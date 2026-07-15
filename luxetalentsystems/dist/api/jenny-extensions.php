<?php
/* Jenny extensions lookup — /api/jenny-extensions.php
 * Returns the name -> extension/number map so Jenny can transfer callers
 * by name OR extension. Reads from the same IVR config the phone tree uses.
 *
 * get  = returns the list Jenny matches against (shared-secret auth).
 * The actual call transfer is done by Jenny via Twilio TwiML (<Dial>), using
 * the number/endpoint returned here.
 */
require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$provided = $_SERVER['HTTP_X_JENNY_KEY'] ?? '';
$expected = '';
try { $expected = (string) db()->query("SELECT setting_value FROM settings WHERE setting_key='jenny_shared_key'")->fetchColumn(); } catch (Throwable $e) {}
if ($expected === '' || !hash_equals($expected, $provided)) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

$extensions = [];

// Pull from IVR config (settings key 'ivr_config') if present.
try {
  $raw = db()->query("SELECT setting_value FROM settings WHERE setting_key='ivr_config'")->fetchColumn();
  if ($raw) {
    $ivr = json_decode($raw, true);
    // ivr_config extension shape may vary; adapt as needed. Expecting a list with
    // name, ext, and a dial target (client/sip/number/ringall).
    if (!empty($ivr['extensions']) && is_array($ivr['extensions'])) {
      foreach ($ivr['extensions'] as $x) {
        $extensions[] = [
          'name'   => $x['name'] ?? '',
          'ext'    => (string)($x['ext'] ?? $x['extension'] ?? ''),
          'type'   => $x['type'] ?? 'number',       // client | sip | number | ringall
          'target' => $x['target'] ?? $x['number'] ?? $x['client'] ?? '',
        ];
      }
    }
  }
} catch (Throwable $e) {}

// Fallback: also allow a dedicated jenny_extensions settings key to override/add.
try {
  $raw2 = db()->query("SELECT setting_value FROM settings WHERE setting_key='jenny_extensions'")->fetchColumn();
  if ($raw2) {
    $extra = json_decode($raw2, true);
    if (is_array($extra)) {
      foreach ($extra as $x) {
        $extensions[] = [
          'name'   => $x['name'] ?? '',
          'ext'    => (string)($x['ext'] ?? ''),
          'type'   => $x['type'] ?? 'number',
          'target' => $x['target'] ?? '',
        ];
      }
    }
  }
} catch (Throwable $e) {}

echo json_encode(['ok' => true, 'extensions' => $extensions]);
