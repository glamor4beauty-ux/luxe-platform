<?php
/* Jenny config API — /api/jenny-config.php
 * Serves Jenny's settings (greeting, voice, hours, active toggle) as JSON.
 * Stored in the `settings` table under key `jenny_config`.
 * get  = public (Jenny's Node service reads it)
 * save = admin-gated (dashboard writes it)
 */
require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

$email  = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
$action = $_GET['action'] ?? 'get';

$DEFAULTS = [
  'active'   => true,
  'voice'    => 'shimmer',
  'greeting' => "Hi, thanks for calling Luxe Model Collective! This is Jenny. I can answer your questions, connect you with someone, or help you get signed up as a model — how can I help you today?",
  'hours_mode' => '24_7',                // '24_7' | 'scheduled'
  'business_hours' => ['start' => '09:00', 'end' => '21:00', 'timezone' => 'America/New_York'],
  'after_hours_action'  => 'voicemail',  // 'transfer_ivr' | 'voicemail'
  'after_hours_message' => "Thanks for calling Luxe Model Collective. Our team is offline right now, but I can still answer questions or help you get registered.",
  'after_hours_ivr_url' => '',
  'temperature' => 0.8,
  'web_search_enabled' => true,
];

function jennyLoad($DEFAULTS) {
  try {
    $raw = db()->query("SELECT setting_value FROM settings WHERE setting_key='jenny_config'")->fetchColumn();
    if ($raw) {
      $saved = json_decode($raw, true);
      if (is_array($saved)) return array_merge($DEFAULTS, $saved);
    }
  } catch (Throwable $e) {}
  return $DEFAULTS;
}

if ($action === 'get') {
  echo json_encode(['ok' => true, 'config' => jennyLoad($DEFAULTS)]);
  exit;
}

if ($action === 'save') {
  if ($email === '') { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Admin login required']); exit; }
  $in = json_decode(file_get_contents('php://input'), true) ?: [];
  $cur = jennyLoad($DEFAULTS);

  $cfg = [
    'active'   => array_key_exists('active', $in) ? (bool)$in['active'] : $cur['active'],
    'voice'    => !empty($in['voice']) ? preg_replace('/[^a-z]/', '', strtolower($in['voice'])) : $cur['voice'],
    'greeting' => isset($in['greeting']) ? trim($in['greeting']) : $cur['greeting'],
    'hours_mode' => in_array(($in['hours_mode'] ?? ''), ['24_7','scheduled'], true) ? $in['hours_mode'] : $cur['hours_mode'],
    'business_hours' => [
      'start' => $in['business_hours']['start'] ?? $cur['business_hours']['start'],
      'end'   => $in['business_hours']['end']   ?? $cur['business_hours']['end'],
      'timezone' => $in['business_hours']['timezone'] ?? $cur['business_hours']['timezone'],
    ],
    'after_hours_action'  => in_array(($in['after_hours_action'] ?? ''), ['transfer_ivr','voicemail'], true) ? $in['after_hours_action'] : $cur['after_hours_action'],
    'after_hours_message' => isset($in['after_hours_message']) ? trim($in['after_hours_message']) : $cur['after_hours_message'],
    'after_hours_ivr_url' => isset($in['after_hours_ivr_url']) ? trim($in['after_hours_ivr_url']) : $cur['after_hours_ivr_url'],
    'temperature' => isset($in['temperature']) ? max(0, min(1.2, (float)$in['temperature'])) : $cur['temperature'],
    'web_search_enabled' => array_key_exists('web_search_enabled', $in) ? (bool)$in['web_search_enabled'] : $cur['web_search_enabled'],
  ];

  db()->prepare("INSERT INTO settings (setting_key,setting_value,is_secret) VALUES ('jenny_config',?,0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
      ->execute([json_encode($cfg)]);
  echo json_encode(['ok' => true, 'config' => $cfg]);
  exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown action']);
