<?php
/* Registration Settings API — platforms + opt-in config, stored in `settings` table as JSON (key: reg_config) */
require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

$email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
$action = $_GET['action'] ?? '';

// Defaults if nothing saved yet
$DEFAULTS = [
  'platforms' => [
    'stripchat'  => true,
    'bongacams'  => true,
    'myclub'     => true,
    'livejasmin' => true,
    'creator'    => true,
  ],
  'max_platforms' => 1,
  'sms_optin_required' => true,
];

function loadConfig($DEFAULTS){
  try {
    $raw = db()->query("SELECT setting_value FROM settings WHERE setting_key='reg_config'")->fetchColumn();
    if ($raw) {
      $saved = json_decode($raw, true);
      if (is_array($saved)) {
        // merge platforms individually so new platforms get defaults
        if (isset($saved['platforms']) && is_array($saved['platforms'])) {
          $saved['platforms'] = array_merge($DEFAULTS['platforms'], $saved['platforms']);
        }
        return array_merge($DEFAULTS, $saved);
      }
    }
  } catch (Throwable $e) {}
  return $DEFAULTS;
}

if ($action === 'get') {
  echo json_encode(['ok'=>true, 'config'=>loadConfig($DEFAULTS)]);
  exit;
}

if ($action === 'save') {
  if ($email === '') { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Admin login required']); exit; }
  $in = json_decode(file_get_contents('php://input'), true) ?: [];

  $platforms = [];
  foreach ($DEFAULTS['platforms'] as $k => $def) {
    $platforms[$k] = !empty($in['platforms'][$k]);
  }
  $cfg = [
    'platforms' => $platforms,
    'max_platforms' => max(1, min(10, (int)($in['max_platforms'] ?? 1))),
    'sms_optin_required' => !empty($in['sms_optin_required']),
  ];
  db()->prepare("INSERT INTO settings (setting_key,setting_value,is_secret) VALUES ('reg_config',?,0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
      ->execute([json_encode($cfg)]);
  echo json_encode(['ok'=>true, 'config'=>$cfg]);
  exit;
}

echo json_encode(['ok'=>false, 'error'=>'unknown action']);
