<?php
require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
$email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
if ($email === '') { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }

$out = ['ok'=>true];
$q = function($sql) { try { return db()->query($sql)->fetchColumn(); } catch(Throwable $e){ return 0; } };

// PERFORMERS
$out['performers_total'] = (int)$q("SELECT COUNT(*) FROM registration");
$out['performers_active'] = (int)$q("SELECT COUNT(*) FROM registration WHERE status='active'");
$out['performers_pending'] = (int)$q("SELECT COUNT(*) FROM registration WHERE status='pending'");
$out['performers_models'] = (int)$q("SELECT COUNT(*) FROM registration WHERE applying_for LIKE '%model%' OR role LIKE '%model%'");

// STRIPCHAT unpaid (latest per model, current period, minus 20% fee)
try {
  $gross = (float)db()->query("
    SELECT COALESCE(SUM(total_earnings),0)*0.05 FROM stripchat_earnings se
    INNER JOIN (SELECT model_username, MAX(fetched_at) mx FROM stripchat_earnings WHERE period_type='currentPayment' GROUP BY model_username) t
    ON se.model_username=t.model_username AND se.fetched_at=t.mx
  ")->fetchColumn();
  $out['stripchat_unpaid'] = round($gross * 0.76, 2);
  $out['studio_fee'] = round($gross * 0.24, 2);
} catch(Throwable $e){ $out['stripchat_unpaid'] = 0; $out['studio_fee'] = 0; }

// CLIP4SALE
$out['c4s_live'] = (int)$q("SELECT COUNT(*) FROM clip4sale_clips WHERE status='live' OR status='active' OR status='published'");
$out['c4s_uploads'] = (int)$q("SELECT COUNT(*) FROM clip4sale_clips");
$out['c4s_sold'] = round((float)$q("SELECT COALESCE(SUM(sold_amount),0) FROM clip4sale_sales"), 2);
$out['c4s_payout'] = round((float)$q("SELECT COALESCE(SUM(payout_amount),0) FROM clip4sale_sales"), 2);

// STUDIO PAYOUTS total
try {
  $out['studio_payout'] = round((float)db()->query("SELECT COALESCE(SUM(amount - (amount*commission_pct/100)),0) FROM studio_payouts")->fetchColumn(), 2);
} catch(Throwable $e){ $out['studio_payout'] = 0; }

// VIDEO UPLOADS (try common table names)
$vu = 0;
foreach (['video_uploads','videos','vidup_files','uploads'] as $t) {
  try { $vu = (int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); if($vu) break; } catch(Throwable $e){}
}
$out['video_uploads'] = $vu;

// UNREAD EMAILS (try common shapes)
$ue = 0;
foreach (['SELECT COUNT(*) FROM emails WHERE is_read=0','SELECT COUNT(*) FROM email_inbox WHERE seen=0','SELECT COUNT(*) FROM messages WHERE read_at IS NULL'] as $sql) {
  try { $ue = (int)db()->query($sql)->fetchColumn(); if($ue) break; } catch(Throwable $e){}
}
$out['unread_emails'] = $ue;

echo json_encode($out);
