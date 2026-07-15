<?php
/* ═══════════════════════════════════════════════════════════════
   Communications module — shared config (Voice / SMS / Video)
   Self-contained. Holds Twilio credentials in ONE place, server-side
   only. Never expose these values to the browser / JS.
   Required by: voice.php, sms.php, video.php
   ═══════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../config.php';   // gives db() + DB_* constants

/* ── Twilio account credentials ──────────────────────────────────
   Replace these with your real values from the Twilio Console.
   (These were already live in the old voice.php; kept here so all
   comm endpoints read from a single source.)                       */
const TW_SID   = 'AC906dbbb445fc44f915f813107748e499';
const TW_TOKEN = '315f6470b62e4b45eb42948f678a97a9';
const TW_FROM  = '+15715836064';            // your Twilio phone number (E.164)

/* API Key SID + Secret — created in the Twilio Console under
   Account → API keys & tokens → Create API key (Standard).
   The same pair is used by BOTH the Video product and the browser-based
   Voice SDK (dashboard dial pad). One key pair is enough.
   Console → Account → API keys & tokens.                                 */
const TW_API_KEY    = '';                   // e.g. SKxxxxxxxx
const TW_API_SECRET = '';

/* Voice TwiML App SID — created in the Twilio Console under
   Voice → Manage → TwiML Apps → Create new TwiML App.
   Set the Voice "Request URL" to:
       https://luxetalentsystems.com/api/voice.php?action=voice_app
   That is the webhook Twilio calls when the browser SDK places an outbound
   call; voice.php?action=voice_app returns TwiML that dials the number the
   browser passed in `To`.                                                 */
const TW_VOICE_APP_SID = '';                // e.g. APxxxxxxxx

const TW_API = 'https://api.twilio.com/2010-04-01/Accounts/' . TW_SID;

/* ── Shared helpers ─────────────────────────────────────────────── */

/* Public host for building webhook URLs Twilio will call back. */
function comm_host(): string {
    return $_SERVER['HTTP_HOST'] ?? 'luxetalentsystems.com';
}

/* Normalize a phone number to E.164 (assumes US +1 if no country code). */
function comm_e164(string $raw): string {
    $p = preg_replace('/[^+0-9]/', '', $raw);
    if ($p === '') return '';
    if ($p[0] !== '+') {
        // 10 digits -> +1; 11 digits starting with 1 -> +…
        if (strlen($p) === 11 && $p[0] === '1') $p = '+' . $p;
        else $p = '+1' . $p;
    }
    return $p;
}

/* Look up a registrant email by phone (last 10 digits). */
function comm_email_by_phone(PDO $pdo, string $phone): ?string {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    $last10 = substr($digits, -10);
    if (strlen($last10) < 7) return null;
    $st = $pdo->prepare('SELECT email FROM registration WHERE phone LIKE ? LIMIT 1');
    $st->execute(['%' . $last10 . '%']);
    return $st->fetchColumn() ?: null;
}

/* POST to the Twilio REST API with Basic auth. Returns [httpCode, decodedArray]. */
function tw_post(string $url, array $params): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => TW_SID . ':' . TW_TOKEN,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (json_decode($resp, true) ?: [])];
}

/* GET from the Twilio REST API with Basic auth. Returns [httpCode, decodedArray]. */
function tw_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => TW_SID . ":" . TW_TOKEN,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (json_decode($resp, true) ?: [])];
}
