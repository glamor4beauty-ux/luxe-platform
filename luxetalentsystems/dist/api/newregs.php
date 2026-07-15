<?php
/* Luxe Talent System — new-registration notifier (self-contained, removable)
   Returns registrations created after a given timestamp, so the admin
   dashboard can pop a notification for each new sign-up.
   Action: list   GET param: since (ISO datetime, optional) */

require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';

try {
    $pdo  = db();
    $since = isset($_GET['since']) && $_GET['since'] !== '' ? $_GET['since'] : null;

    if ($since) {
        $st = $pdo->prepare(
            "SELECT email, stage_name, first_name, last_name, created_at
               FROM registration
              WHERE role = 'performer' AND created_at > ?
              ORDER BY created_at ASC
              LIMIT 100"
        );
        $st->execute([$since]);
    } else {
        /* first load: just report the latest timestamp, no backlog of pop-ups */
        $st = $pdo->query(
            "SELECT email, stage_name, first_name, last_name, created_at
               FROM registration
              WHERE role = 'performer'
              ORDER BY created_at DESC
              LIMIT 1"
        );
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $newest = null;
    foreach ($rows as $r) {
        if ($newest === null || $r['created_at'] > $newest) $newest = $r['created_at'];
    }

    json_response([
        'registrations' => $since ? $rows : [],   // backlog only when 'since' is given
        'latest'        => $newest,               // marker for the dashboard to store
    ]);
} catch (Throwable $e) {
    error_log('[luxe newregs] ' . $e->getMessage());
    json_response(['error' => $e->getMessage()], 500);
}
