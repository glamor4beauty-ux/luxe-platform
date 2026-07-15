<?php
/* Jenny registration intake — /api/jenny-register.php
 * Receives a voice-collected registration from Jenny's service, saves a
 * pending record to the SAME `registration` table the web form uses, and
 * returns a tokenized upload link (for ID photos + signature) that Jenny texts.
 *
 * Security: Jenny's service authenticates with a shared secret header
 * (X-Jenny-Key) so random people can't POST fake registrations.
 */
require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

// Shared-secret auth (set JENNY_SHARED_KEY in settings table)
$provided = $_SERVER['HTTP_X_JENNY_KEY'] ?? '';
$expected = '';
try { $expected = (string) db()->query("SELECT setting_value FROM settings WHERE setting_key='jenny_shared_key'")->fetchColumn(); } catch (Throwable $e) {}
if ($expected === '' || !hash_equals($expected, $provided)) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];

$first = trim($in['first_name'] ?? '');
$last  = trim($in['last_name'] ?? '');
$email = trim($in['email'] ?? '');
$phone = trim($in['phone'] ?? '');
if ($first === '' || $last === '' || $email === '' || $phone === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'missing required fields']);
  exit;
}

$stage = trim($in['stage_name'] ?? '');
$dob   = trim($in['date_of_birth'] ?? '');   // YYYY-MM-DD
$onMobile = !empty($in['on_mobile']);

// Upload token for the ID + signature completion link
$token = bin2hex(random_bytes(16));

try {
  // Insert a PENDING registration (source=phone). Adjust columns to match your schema.
  $sql = "INSERT INTO registration
            (first_name, last_name, email, phone, stage_name, date_of_birth,
             source, status, upload_token, created_at)
          VALUES (?, ?, ?, ?, ?, ?, 'phone', 'pending_docs', ?, NOW())
          ON DUPLICATE KEY UPDATE
             first_name=VALUES(first_name), last_name=VALUES(last_name),
             phone=VALUES(phone), stage_name=VALUES(stage_name),
             date_of_birth=VALUES(date_of_birth), upload_token=VALUES(upload_token),
             status='pending_docs'";
  $st = db()->prepare($sql);
  $st->execute([$first, $last, $email, $phone, $stage, ($dob ?: null), $token]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db_error', 'detail' => $e->getMessage()]);
  exit;
}

$base = 'https://luxetalentsystems.com';
$uploadLink = $base . '/complete-registration.html?e=' . urlencode($email) . '&t=' . $token;

echo json_encode([
  'ok' => true,
  'upload_link' => $uploadLink,
  'on_mobile' => $onMobile,
  'message' => $onMobile
    ? 'Saved. Text the link; stay on the line until they finish uploading ID and signing.'
    : 'Saved. Text the link; caller is on desktop, so admin will follow up.',
]);
