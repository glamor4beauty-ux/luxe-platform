<?php
require __DIR__."/../cors.php";
require __DIR__.'/../config.php';

// ── Stripchat Studio API credentials — server-side only ──
define('SC_API_KEY', '9a0221fd39188e45d4f32d3e1e58d88d');
define('SC_STUDIO', 'LuxeModelCollective');
define('SC_API_BASE', 'https://stripchat.com/api/stats/v2/studios/username/' . SC_STUDIO . '/models/username/');

// Token to USD conversion (Stripchat: 1 token ≈ $0.05 for studios)
define('SC_TOKEN_RATE', 0.05);

// Convert Stripchat ISO 8601 (2026-06-22T00:14:21Z) to MySQL DATETIME (2026-06-22 00:14:21).
function sc_mysql_date($v){
    if(empty($v)) return null;
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ── Fetch live earnings from Stripchat API ──
        case 'fetch':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $modelUsername = trim($data['model_username'] ?? '');
            $periodType = $data['period_type'] ?? 'currentPayment';
            $periodStart = $data['period_start'] ?? null;
            $periodEnd = $data['period_end'] ?? null;

            if (!$modelUsername) json_response(['error'=>'model_username required'], 400);
            if (!in_array($periodType, ['currentPayment','lastPayment','currentSession'])) {
                $periodType = 'currentPayment';
            }

            // Build Stripchat API URL
            $url = SC_API_BASE . urlencode($modelUsername) . '?periodType=' . $periodType;
            if ($periodStart) $url .= '&periodStart=' . urlencode($periodStart);
            if ($periodEnd) $url .= '&periodEnd=' . urlencode($periodEnd);

            // Call Stripchat API
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['API-Key: ' . SC_API_KEY],
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) throw new RuntimeException("Stripchat API error: $error");

            $scData = json_decode($response, true);

            if ($httpCode === 403) json_response(['error'=>'Wrong API key'], 403);
            if ($httpCode === 404) json_response(['error'=>'Studio or model not found: ' . $modelUsername], 404);
            if ($httpCode === 400) json_response(['error'=>$scData['error'] ?? 'Bad request'], 400);
            if ($httpCode !== 200) json_response(['error'=>'Stripchat API returned HTTP ' . $httpCode], 500);

            // Find performer email by stage name
            $performerEmail = null;
            $ps = db()->prepare("SELECT email FROM registration WHERE stage_name = ? OR LOWER(stage_name) = LOWER(?)");
            $ps->execute([$modelUsername, $modelUsername]);
            $pr = $ps->fetch();
            if ($pr) $performerEmail = $pr['email'];

            // Store in database
            $ins = db()->prepare('INSERT INTO stripchat_earnings (
                model_username, performer_email, period_type, period_start, period_end,
                tips, public_chat_tips, private_chat_tips, offline_tips, unlock_chat,
                album_sales, video_sales, private_shows, spy_on_privates, exclusive_privates,
                contest_win, exclusive_private_no_video, user_tips_vr, private_chat_tips_vr,
                anonymous_tips, anonymous_tips_vr, fan_club_soldiers, fan_club_lords, fan_club_princes,
                group_shows, ticket_show, public_tips_vr, spy_on_privates_vr, public_show_recordings,
                photo_sales_pm, mass_messages, models_referral_program, users_referral_program,
                refunds, other_income, total_earnings
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE period_start=VALUES(period_start), period_end=VALUES(period_end), tips=VALUES(tips), public_chat_tips=VALUES(public_chat_tips), private_chat_tips=VALUES(private_chat_tips), offline_tips=VALUES(offline_tips), unlock_chat=VALUES(unlock_chat), album_sales=VALUES(album_sales), video_sales=VALUES(video_sales), private_shows=VALUES(private_shows), spy_on_privates=VALUES(spy_on_privates), exclusive_privates=VALUES(exclusive_privates), contest_win=VALUES(contest_win), exclusive_private_no_video=VALUES(exclusive_private_no_video), user_tips_vr=VALUES(user_tips_vr), private_chat_tips_vr=VALUES(private_chat_tips_vr), anonymous_tips=VALUES(anonymous_tips), anonymous_tips_vr=VALUES(anonymous_tips_vr), fan_club_soldiers=VALUES(fan_club_soldiers), fan_club_lords=VALUES(fan_club_lords), fan_club_princes=VALUES(fan_club_princes), group_shows=VALUES(group_shows), ticket_show=VALUES(ticket_show), public_tips_vr=VALUES(public_tips_vr), spy_on_privates_vr=VALUES(spy_on_privates_vr), public_show_recordings=VALUES(public_show_recordings), photo_sales_pm=VALUES(photo_sales_pm), mass_messages=VALUES(mass_messages), models_referral_program=VALUES(models_referral_program), users_referral_program=VALUES(users_referral_program), refunds=VALUES(refunds), other_income=VALUES(other_income), total_earnings=VALUES(total_earnings), fetched_at=NOW()');

            $ins->execute([
                $modelUsername, $performerEmail, $periodType,
                sc_mysql_date($scData['periodStart'] ?? $periodStart),
                sc_mysql_date($scData['periodEnd'] ?? $periodEnd),
                $scData['tip'] ?? 0,
                $scData['publicChatTip'] ?? 0,
                $scData['privateChatTip'] ?? 0,
                $scData['offlineTip'] ?? 0,
                $scData['unlockChat'] ?? 0,
                $scData['albumSale'] ?? 0,
                $scData['videoSale'] ?? 0,
                $scData['privateShow'] ?? 0,
                $scData['spyOnPrivate'] ?? 0,
                $scData['exclusivePrivate'] ?? 0,
                $scData['contestWin'] ?? 0,
                $scData['exclusivePrivateNoVideo'] ?? 0,
                $scData['userTipVR'] ?? 0,
                $scData['privateChatTipVR'] ?? 0,
                $scData['anonymousTip'] ?? 0,
                $scData['anonymousTipVR'] ?? 0,
                $scData['fanClubSoldier'] ?? 0,
                $scData['fanClubLord'] ?? 0,
                $scData['fanClubPrince'] ?? 0,
                $scData['groupShow'] ?? 0,
                $scData['ticketShow'] ?? 0,
                $scData['publicTipVR'] ?? 0,
                $scData['spyOnPrivateVR'] ?? 0,
                $scData['publicShowRecording'] ?? 0,
                $scData['photoSalePM'] ?? 0,
                $scData['massMessage'] ?? 0,
                $scData['modelReferralProgram'] ?? 0,
                $scData['userReferralProgram'] ?? 0,
                $scData['refund'] ?? 0,
                $scData['otherIncome'] ?? 0,
                $scData['totalEarnings'] ?? 0,
            ]);

            // Add USD conversion to response
            $scData['totalEarningsUSD'] = round(($scData['totalEarnings'] ?? 0) * SC_TOKEN_RATE, 2);
            $scData['storedId'] = (int)db()->lastInsertId();
            $scData['model_username'] = $modelUsername;
            $scData['performer_email'] = $performerEmail;

            json_response($scData);
            break;

        // ── Fetch all models at once ──
        case 'fetch_all':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $periodType = $data['period_type'] ?? 'currentPayment';

            // Get all performers with stage names
            $performers = db()->query("SELECT email, stage_name FROM registration WHERE role='performer' AND stage_name IS NOT NULL AND stage_name != '' ORDER BY stage_name")->fetchAll();

            $results = [];
            $errors = [];

            foreach ($performers as $p) {
                $url = SC_API_BASE . urlencode($p['stage_name']) . '?periodType=' . $periodType;
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['API-Key: ' . SC_API_KEY],
                    CURLOPT_TIMEOUT => 10,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $scData = json_decode($response, true);
                    $scData['model_username'] = $p['stage_name'];
                    $scData['performer_email'] = $p['email'];
                    $scData['totalEarningsUSD'] = round(($scData['totalEarnings'] ?? 0) * SC_TOKEN_RATE, 2);
                    $results[] = $scData;

                    // Store
$ins = db()->prepare('INSERT INTO stripchat_earnings (
                        model_username, performer_email, period_type, period_start, period_end,
                        tips, public_chat_tips, private_chat_tips, offline_tips, unlock_chat,
                        album_sales, video_sales, private_shows, spy_on_privates, exclusive_privates,
                        contest_win, exclusive_private_no_video, user_tips_vr, private_chat_tips_vr,
                        anonymous_tips, anonymous_tips_vr, fan_club_soldiers, fan_club_lords, fan_club_princes,
                        group_shows, ticket_show, public_tips_vr, spy_on_privates_vr, public_show_recordings,
                        photo_sales_pm, mass_messages, models_referral_program, users_referral_program,
                        refunds, other_income, total_earnings
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE period_start=VALUES(period_start), period_end=VALUES(period_end), tips=VALUES(tips), public_chat_tips=VALUES(public_chat_tips), private_chat_tips=VALUES(private_chat_tips), offline_tips=VALUES(offline_tips), unlock_chat=VALUES(unlock_chat), album_sales=VALUES(album_sales), video_sales=VALUES(video_sales), private_shows=VALUES(private_shows), spy_on_privates=VALUES(spy_on_privates), exclusive_privates=VALUES(exclusive_privates), contest_win=VALUES(contest_win), exclusive_private_no_video=VALUES(exclusive_private_no_video), user_tips_vr=VALUES(user_tips_vr), private_chat_tips_vr=VALUES(private_chat_tips_vr), anonymous_tips=VALUES(anonymous_tips), anonymous_tips_vr=VALUES(anonymous_tips_vr), fan_club_soldiers=VALUES(fan_club_soldiers), fan_club_lords=VALUES(fan_club_lords), fan_club_princes=VALUES(fan_club_princes), group_shows=VALUES(group_shows), ticket_show=VALUES(ticket_show), public_tips_vr=VALUES(public_tips_vr), spy_on_privates_vr=VALUES(spy_on_privates_vr), public_show_recordings=VALUES(public_show_recordings), photo_sales_pm=VALUES(photo_sales_pm), mass_messages=VALUES(mass_messages), models_referral_program=VALUES(models_referral_program), users_referral_program=VALUES(users_referral_program), refunds=VALUES(refunds), other_income=VALUES(other_income), total_earnings=VALUES(total_earnings), fetched_at=NOW()');
                    $ins->execute([
                        $p['stage_name'], $p['email'], $periodType,
                        sc_mysql_date($scData['periodStart'] ?? null),
                        sc_mysql_date($scData['periodEnd'] ?? null),
                        $scData['tip'] ?? 0,
                        $scData['publicChatTip'] ?? 0,
                        $scData['privateChatTip'] ?? 0,
                        $scData['offlineTip'] ?? 0,
                        $scData['unlockChat'] ?? 0,
                        $scData['albumSale'] ?? 0,
                        $scData['videoSale'] ?? 0,
                        $scData['privateShow'] ?? 0,
                        $scData['spyOnPrivate'] ?? 0,
                        $scData['exclusivePrivate'] ?? 0,
                        $scData['contestWin'] ?? 0,
                        $scData['exclusivePrivateNoVideo'] ?? 0,
                        $scData['userTipVR'] ?? 0,
                        $scData['privateChatTipVR'] ?? 0,
                        $scData['anonymousTip'] ?? 0,
                        $scData['anonymousTipVR'] ?? 0,
                        $scData['fanClubSoldier'] ?? 0,
                        $scData['fanClubLord'] ?? 0,
                        $scData['fanClubPrince'] ?? 0,
                        $scData['groupShow'] ?? 0,
                        $scData['ticketShow'] ?? 0,
                        $scData['publicTipVR'] ?? 0,
                        $scData['spyOnPrivateVR'] ?? 0,
                        $scData['publicShowRecording'] ?? 0,
                        $scData['photoSalePM'] ?? 0,
                        $scData['massMessage'] ?? 0,
                        $scData['modelReferralProgram'] ?? 0,
                        $scData['userReferralProgram'] ?? 0,
                        $scData['refund'] ?? 0,
                        $scData['otherIncome'] ?? 0,
                        $scData['totalEarnings'] ?? 0,
                    ]);
                } else {
                    $errors[] = ['model'=>$p['stage_name'], 'status'=>$httpCode];
                }
            }

            json_response(['results'=>$results, 'errors'=>$errors, 'total_models'=>count($performers)]);
            break;


        // ── Single performer earnings by STAGE NAME (email can change) ──
        // GET ?action=my_earnings&stage=YukiLei[&period=currentPayment|lastPayment|currentSession]
        case 'my_earnings':
            $stage  = trim($_GET['stage'] ?? $_GET['stage_name'] ?? '');
            $email  = trim($_GET['email'] ?? '');
            $period = $_GET['period'] ?? '';

            // Resolve the model_username: prefer stage name; fall back to email lookup.
            if ($stage === '' && $email !== '') {
                $q = db()->prepare("SELECT stage_name FROM registration WHERE email = ? LIMIT 1");
                $q->execute([$email]);
                $stage = (string)($q->fetchColumn() ?: '');
            }
            if ($stage === '') { json_response(['ok'=>false,'error'=>'stage name or email required'], 400); }

            // Pull rows for this model (optionally filtered to a period type), newest first.
            if (in_array($period, ['currentPayment','lastPayment','currentSession'], true)) {
                $s = db()->prepare("SELECT * FROM stripchat_earnings WHERE model_username = ? AND period_type = ? ORDER BY fetched_at DESC LIMIT 24");
                $s->execute([$stage, $period]);
            } else {
                $s = db()->prepare("SELECT * FROM stripchat_earnings WHERE model_username = ? ORDER BY fetched_at DESC LIMIT 24");
                $s->execute([$stage]);
            }
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                json_response([
                    'ok'=>true,'stage_name'=>$stage,'has_data'=>false,
                    'periods'=>[], 'latest'=>null,
                    'token_rate'=>SC_TOKEN_RATE
                ]);
            }

            // Category groups (token columns) for a friendly breakdown.
            $groups = [
                'Tips'      => ['tips','public_chat_tips','private_chat_tips','offline_tips','anonymous_tips','user_tips_vr','private_chat_tips_vr','anonymous_tips_vr','public_tips_vr'],
                'Shows'     => ['private_shows','exclusive_privates','exclusive_private_no_video','group_shows','ticket_show','spy_on_privates','spy_on_privates_vr'],
                'Fan Club'  => ['fan_club_soldiers','fan_club_lords','fan_club_princes'],
                'Sales'     => ['album_sales','video_sales','photo_sales_pm','public_show_recordings','unlock_chat','mass_messages'],
                'Referrals' => ['models_referral_program','users_referral_program'],
                'Other'     => ['contest_win','other_income'],
            ];

            $fmtRows = [];
            foreach ($rows as $r) {
                $breakdown = [];
                $sum = 0;
                foreach ($groups as $label => $cols) {
                    $g = 0;
                    foreach ($cols as $c) { $g += (float)($r[$c] ?? 0); }
                    if ($g != 0) $breakdown[$label] = $g;
                    $sum += $g;
                }
                $refunds = (float)($r['refunds'] ?? 0);
                $total   = (float)($r['total_earnings'] ?? $sum);

                $fmtRows[] = [
                    'period_type'  => $r['period_type'],
                    'period_start' => $r['period_start'],
                    'period_end'   => $r['period_end'],
                    'fetched_at'   => $r['fetched_at'],
                    'tokens'       => $total,
                    'usd'          => round($total * SC_TOKEN_RATE, 2),
                    'refunds'      => $refunds,
                    'breakdown'    => $breakdown,   // label => token count (non-zero only)
                ];
            }

            $latest = $fmtRows[0];
            json_response([
                'ok'         => true,
                'stage_name' => $stage,
                'has_data'   => true,
                'token_rate' => SC_TOKEN_RATE,
                'latest'     => $latest,
                'periods'    => $fmtRows,
            ]);
            break;

        // ── List stored earnings history ──
        case 'history':
            $model = $_GET['model'] ?? '';
            $limit = min((int)($_GET['limit'] ?? 50), 200);
            if ($model) {
                $s = db()->prepare('SELECT * FROM stripchat_earnings WHERE model_username=? ORDER BY fetched_at DESC LIMIT ?');
                $s->bindValue(1, $model);
                $s->bindValue(2, $limit, PDO::PARAM_INT);
                $s->execute();
            } else {
                $s = db()->prepare('SELECT * FROM stripchat_earnings ORDER BY fetched_at DESC LIMIT ?');
                $s->bindValue(1, $limit, PDO::PARAM_INT);
                $s->execute();
            }
            json_response($s->fetchAll());
            break;

        // ── Get latest earnings per model (summary view) ──
        case 'summary':
            $rows = db()->query("
                SELECT se.*, r.first_name, r.last_name, r.stage_name
                FROM stripchat_earnings se
                INNER JOIN (
                    SELECT model_username, MAX(fetched_at) as latest
                    FROM stripchat_earnings
                    GROUP BY model_username
                ) latest ON se.model_username = latest.model_username AND se.fetched_at = latest.latest
                LEFT JOIN registration r ON se.performer_email = r.email
                ORDER BY se.total_earnings DESC
            ")->fetchAll();
            // Add USD
            foreach ($rows as &$r) {
                $r['totalEarningsUSD'] = round($r['total_earnings'] * SC_TOKEN_RATE, 2);
            }
            json_response($rows);
            break;

        // ── List models (for dropdown) ──
        case 'models':
            json_response(db()->query("SELECT email, stage_name, first_name, last_name FROM registration WHERE role='performer' AND stage_name IS NOT NULL AND stage_name != '' ORDER BY stage_name")->fetchAll());
            break;

        case 'monthly_totals':
            $rows = db()->query("SELECT DATE_FORMAT(period_start,'%Y-%m') as month, SUM(total_earnings) as total_earnings, SUM(tips+public_chat_tips+private_chat_tips+offline_tips) as tips, SUM(private_shows+exclusive_privates+group_shows) as private_shows, SUM(fan_club_soldiers+fan_club_lords+fan_club_princes) as fan_clubs, SUM(other_income+album_sales+video_sales) as other FROM stripchat_earnings GROUP BY DATE_FORMAT(period_start,'%Y-%m') ORDER BY month DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
            json_response(['months'=>$rows]);
            break;
        default:
            json_response(['error'=>'Unknown action. Use: fetch, fetch_all, history, summary, models'], 400);
    }
} catch (Throwable $e) {
    error_log('[luxe stripchat] ' . $e->getMessage());
    json_response(['error'=>$e->getMessage()], 500);
}
