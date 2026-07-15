<?php
/**
 * Luxe Talent — AI Knowledge Base endpoint (v2)
 *
 * Endpoints:
 *   POST ?action=ask     -> { question, history } -> { reply, sources, docsSearched }
 *   GET  ?action=suggest -> { suggestions: [...] }
 *
 * Pipeline:
 *   1. Hybrid search of knowledgebase table: FULLTEXT + per-keyword LIKE
 *   2. Pull top 8 docs, large excerpts (each up to 8KB of content_text)
 *   3. Send to Claude Opus 4.8 with a strict context-grounded prompt
 *   4. Return only the model's text reply + the list of sources used
 *
 * NOTE: This file is a complete rewrite. Drop it in place over the old one.
 */

require __DIR__."/../cors.php";
require __DIR__.'/../config.php';

// ── Anthropic API config ──
define('CLAUDE_API_KEY', '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');
define('CLAUDE_MODEL',   'claude-opus-4-8');
define('CLAUDE_API_URL', 'https://api.anthropic.com/v1/messages');
define('CLAUDE_VERSION', '2023-06-01');
define('CLAUDE_MAX_TOKENS', 1200);

// Search tuning
define('KB_TOP_N',         8);     // documents pulled into the context
define('KB_EXCERPT_BYTES', 8000);  // chars from each doc's content_text
define('KB_TOTAL_CAP',     60000); // total chars passed to model
define('KB_SOURCES_RETURN', 5);    // sources returned to the UI

// ─────────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'ask';

try {
    switch ($action) {
        case 'ask':
            handleAsk();
            break;
        case 'suggest':
            handleSuggest();
            break;
        default:
            json_response(['error' => 'Unknown action. Use: ask, suggest'], 400);
    }
} catch (Throwable $e) {
    error_log('[luxe ai-kb] ' . $e->getMessage());
    json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
}

// ═════════════════════════════════════════════════════════════════════
// Handlers
// ═════════════════════════════════════════════════════════════════════

function handleAsk(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['error' => 'POST only'], 405);
    }
    $data     = json_decode(file_get_contents('php://input'), true) ?: [];
    $question = trim((string)($data['question'] ?? ''));
    $history  = is_array($data['history'] ?? null) ? $data['history'] : [];

    if ($question === '') {
        json_response(['error' => 'Question required'], 400);
    }

    $ctx = deepSearchKB($question);

    $systemPrompt = buildSystemPrompt($ctx['context_text']);
    $messages     = buildMessages($history, $question);

    $reply = callClaude($systemPrompt, $messages);

    json_response([
        'reply'        => $reply,
        'sources'      => $ctx['sources'],
        'docsSearched' => $ctx['count'],
        'model'        => CLAUDE_MODEL,
    ]);
}

function handleSuggest(): void {
    $cats = db()->query("SELECT DISTINCT category FROM knowledgebase
                         WHERE category IS NOT NULL AND category <> ''
                         ORDER BY category")
                ->fetchAll(PDO::FETCH_COLUMN);

    $titles = db()->query("SELECT DISTINCT title FROM knowledgebase
                           WHERE title IS NOT NULL AND title <> ''
                           ORDER BY RAND() LIMIT 5")
                  ->fetchAll(PDO::FETCH_COLUMN);

    $suggestions = [];
    foreach ($titles as $t) {
        $suggestions[] = 'Tell me about ' . $t;
    }
    if (in_array('Bongacams', $cats, true))  $suggestions[] = 'How do I earn money on Bongacams?';
    if (in_array('Stripchat', $cats, true))  $suggestions[] = 'What is the new model promotion on Stripchat?';
    $suggestions[] = 'What documents do I need to register?';
    $suggestions[] = 'How do payouts work?';

    $suggestions = array_values(array_unique($suggestions));
    $suggestions = array_slice($suggestions, 0, 6);

    json_response(['suggestions' => $suggestions]);
}

// ═════════════════════════════════════════════════════════════════════
// Deep search
// ═════════════════════════════════════════════════════════════════════

/**
 * Hybrid search: FULLTEXT for relevance ranking, LIKE for terms FULLTEXT
 * skips (short/common words). Returns the top docs concatenated as
 * Markdown-flavored context plus a flat sources list.
 */
function deepSearchKB(string $query): array {
    $query = trim($query);
    if ($query === '') {
        return ['context_text' => '', 'sources' => [], 'count' => 0];
    }

    $docs = [];

    // 1. FULLTEXT (natural language) — best for multi-word queries
    try {
        $sql = "SELECT id, title, category, subcategory, file_type,
                       SUBSTRING(content_text, 1, " . (int)KB_EXCERPT_BYTES . ") AS excerpt,
                       CHAR_LENGTH(content_text) AS total_len,
                       MATCH(title, content_text, category, subcategory)
                           AGAINST(? IN NATURAL LANGUAGE MODE) AS relevance
                FROM knowledgebase
                WHERE MATCH(title, content_text, category, subcategory)
                      AGAINST(? IN NATURAL LANGUAGE MODE)
                ORDER BY relevance DESC
                LIMIT " . (int)KB_TOP_N;
        $st = db()->prepare($sql);
        $st->execute([$query, $query]);
        $docs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $docs = []; // table may lack a FULLTEXT index
    }

    // 2. LIKE fallback when FULLTEXT returned nothing — broken into keywords
    if (empty($docs)) {
        $keywords = extractKeywords($query);
        if (!empty($keywords)) {
            $clauses = [];
            $params  = [];
            foreach ($keywords as $kw) {
                $clauses[] = '(title LIKE ? OR content_text LIKE ? OR category LIKE ? OR subcategory LIKE ?)';
                $kw_like   = '%' . $kw . '%';
                $params[]  = $kw_like;
                $params[]  = $kw_like;
                $params[]  = $kw_like;
                $params[]  = $kw_like;
            }
            $sql = "SELECT id, title, category, subcategory, file_type,
                           SUBSTRING(content_text, 1, " . (int)KB_EXCERPT_BYTES . ") AS excerpt,
                           CHAR_LENGTH(content_text) AS total_len
                    FROM knowledgebase
                    WHERE " . implode(' OR ', $clauses) . "
                    ORDER BY CHAR_LENGTH(content_text) DESC
                    LIMIT " . (int)KB_TOP_N;
            $st = db()->prepare($sql);
            $st->execute($params);
            $docs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    // 3. Last resort — newest entries so we always return something
    if (empty($docs)) {
        $sql = "SELECT id, title, category, subcategory, file_type,
                       SUBSTRING(content_text, 1, " . (int)KB_EXCERPT_BYTES . ") AS excerpt,
                       CHAR_LENGTH(content_text) AS total_len
                FROM knowledgebase
                ORDER BY created_at DESC
                LIMIT 3";
        $docs = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Build the context block and sources list ──
    $contextText = '';
    $sources     = [];
    $usedChars   = 0;

    foreach ($docs as $i => $d) {
        $titleLine = '## ' . ($d['title'] ?: ('Document #' . $d['id']));
        if (!empty($d['category'])) {
            $titleLine .= ' (' . $d['category']
                       . (!empty($d['subcategory']) ? ' / ' . $d['subcategory'] : '')
                       . ')';
        }

        $excerpt = (string)($d['excerpt'] ?? '');
        if ($excerpt === '') $excerpt = '(No text content extracted)';

        $block = $titleLine . "\n" . $excerpt . "\n\n";

        if ($usedChars + strlen($block) > KB_TOTAL_CAP) {
            // Trim the last block to fit
            $remaining = KB_TOTAL_CAP - $usedChars;
            if ($remaining > 400) {
                $block = substr($block, 0, $remaining) . "\n...(truncated)\n\n";
                $contextText .= $block;
                $usedChars   += strlen($block);
            }
            break;
        }
        $contextText .= $block;
        $usedChars   += strlen($block);
    }

    foreach (array_slice($docs, 0, KB_SOURCES_RETURN) as $d) {
        $sources[] = [
            'id'         => (int)$d['id'],
            'title'      => (string)$d['title'],
            'category'   => (string)($d['category'] ?? ''),
            'file_type'  => (string)($d['file_type'] ?? ''),
            'total_len'  => (int)($d['total_len'] ?? 0),
        ];
    }

    return [
        'context_text' => $contextText,
        'sources'      => $sources,
        'count'        => count($docs),
    ];
}

function extractKeywords(string $query): array {
    // Strip punctuation, lowercase, split, drop stopwords + words < 3 chars.
    $stop = ['the','and','for','you','your','that','this','with','what','how','why','when',
            'where','who','are','can','will','from','have','has','was','were','tell','me',
            'about','our','any','all','some','their','they','its','it'];
    $q = strtolower(preg_replace('/[^a-z0-9\s]+/i', ' ', $query));
    $parts = preg_split('/\s+/', $q);
    $kws = [];
    foreach ($parts as $w) {
        if (strlen($w) >= 3 && !in_array($w, $stop, true)) $kws[$w] = true;
    }
    return array_keys($kws);
}

// ═════════════════════════════════════════════════════════════════════
// Prompt + message construction
// ═════════════════════════════════════════════════════════════════════

function buildSystemPrompt(string $contextText): string {
    $base =
        "You are the AI assistant for Luxe Model Collective, a webcam talent agency. "
      . "Answer the user's question using ONLY the knowledge-base content shown below. "
      . "If the answer is not in the knowledge base, say so honestly — do not invent facts. "
      . "Be concrete and professional. Cite the source document by its title when you use it. "
      . "Format answers as plain readable text (short paragraphs, bullet lists when helpful). "
      . "Keep answers under 350 words unless the user explicitly asks for more detail.\n\n"
      . "── KNOWLEDGE BASE ──\n";

    if (trim($contextText) === '') {
        return $base . "(No matching documents were found for this question.)\n";
    }
    return $base . $contextText;
}

function buildMessages(array $history, string $question): array {
    $messages = [];
    foreach ($history as $h) {
        if (!isset($h['role'], $h['content'])) continue;
        if (!in_array($h['role'], ['user','assistant'], true)) continue;
        $messages[] = ['role' => $h['role'], 'content' => (string)$h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $question];
    return $messages;
}

// ═════════════════════════════════════════════════════════════════════
// Anthropic call
// ═════════════════════════════════════════════════════════════════════

function callClaude(string $systemPrompt, array $messages): string {
    $payload = [
        'model'      => CLAUDE_MODEL,
        'max_tokens' => CLAUDE_MAX_TOKENS,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ];

    $ch = curl_init(CLAUDE_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: ' . CLAUDE_VERSION,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("Claude API curl error: $err");
    }
    if ($code < 200 || $code >= 300) {
        $excerpt = substr((string)$raw, 0, 400);
        throw new RuntimeException("Claude API HTTP $code: $excerpt");
    }

    $body = json_decode((string)$raw, true);
    if (!is_array($body)) {
        throw new RuntimeException('Claude API returned non-JSON');
    }

    // The Messages API returns: { content: [ { type: "text", text: "..." }, ... ], ... }
    // Walk all text blocks so partial responses still surface.
    $text = '';
    foreach (($body['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
            $text .= ($text === '' ? '' : "\n") . $block['text'];
        }
    }
    $text = trim($text);

    if ($text === '') {
        // Surface any error the model returned without ever leaking "model: ..." as the answer.
        $reason = $body['error']['message']
            ?? $body['stop_reason']
            ?? 'No content returned';
        throw new RuntimeException('Empty answer from model: ' . $reason);
    }

    return $text;
}
