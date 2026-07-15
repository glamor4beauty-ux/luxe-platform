<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Studio Payouts (admin) — /api/studio-payouts-admin.php
   Self-contained admin endpoint for the Studio Payouts table. Reads/writes the
   existing `studio_payouts` table plus a commission_pct column. Computes
   Payout = Amount Earned − (Amount Earned × commission_pct / 100).

   Kept separate from the existing studio-payouts.php (which also serves the
   performer-side view) so nothing there is disturbed.

     GET  ?action=list[&q=&sort=&dir=]   → { ok, rows:[…], totals:{…} }
     POST ?action=save  {row}            → { ok, id }
     POST ?action=delete {id}            → { ok }
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
session_start();                       // after config so session save_path is set
header('Content-Type: application/json; charset=utf-8');

$email = $_SESSION['user_email'] ?? $_SESSION['admin_email'] ?? '';
$role  = strtolower((string)($_SESSION['user_role'] ?? ''));
$isAdmin = in_array($role, ['admin', 'superadmin', 'administrator'], true);
if ($email === '') { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!$isAdmin)     { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admin only']);    exit; }

/* Ensure the commission_pct column exists (idempotent; CREATE/ALTER granted). */
try {
    $has = db()->query("SHOW COLUMNS FROM studio_payouts LIKE 'commission_pct'")->fetch();
    if (!$has) db()->exec("ALTER TABLE studio_payouts ADD COLUMN commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
} catch (Throwable $e) { error_log('[studio-admin] alter: ' . $e->getMessage()); }

function payout_of(float $amount, float $pct): float {
    return round($amount - ($amount * $pct / 100), 2);
}

$action = $_GET['action'] ?? 'list';

try {
    if ($action === 'list') {
        $q    = trim((string)($_GET['q'] ?? ''));
        $sort = (string)($_GET['sort'] ?? 'payout_date');
        $dir  = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $sortable = ['performer_name', 'work_performed', 'period_start', 'period_end', 'amount', 'commission_pct', 'payout_date'];
        if (!in_array($sort, $sortable, true)) $sort = 'payout_date';

        $where = ''; $args = [];
        if ($q !== '') {
            $where = "WHERE performer_name LIKE ? OR performer_email LIKE ? OR work_performed LIKE ?";
            $like = '%' . $q . '%'; $args = [$like, $like, $like];
        }
        $st = db()->prepare(
            "SELECT id, performer_email, performer_name, work_performed,
                    period_start, period_end, amount, commission_pct, amount_paid, payout_date, notes
             FROM studio_payouts $where ORDER BY $sort $dir, id DESC"
        );
        $st->execute($args);
        $rows = $st->fetchAll();

        $totEarned = 0.0; $totComm = 0.0; $totPayout = 0.0;
        foreach ($rows as &$r) {
            $amt = (float)$r['amount']; $pct = (float)$r['commission_pct'];
            $comm = round($amt * $pct / 100, 2);
            $pay  = round($amt - $comm, 2);
            $r['commission_amt'] = $comm;
            $r['payout'] = $pay;
            $totEarned += $amt; $totComm += $comm; $totPayout += $pay;
        }
        unset($r);

        json_response([
            'ok' => true,
            'rows' => $rows,
            'totals' => [
                'earned'     => round($totEarned, 2),
                'commission' => round($totComm, 2),
                'payout'     => round($totPayout, 2),
                'count'      => count($rows),
            ],
        ]);
    }

    if ($action === 'save') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $id          = (int)($in['id'] ?? 0);
        $pemail      = trim((string)($in['performer_email'] ?? ''));
        $pname       = trim((string)($in['performer_name'] ?? ''));
        $desc        = trim((string)($in['work_performed'] ?? ''));
        $pstart      = trim((string)($in['period_start'] ?? '')) ?: null;
        $pend        = trim((string)($in['period_end'] ?? '')) ?: null;
        $amount      = (float)($in['amount'] ?? 0);
        $pct         = (float)($in['commission_pct'] ?? 0);
        $paid        = trim((string)($in['payout_date'] ?? '')) ?: null;   // Date Paid
        $notes       = trim((string)($in['notes'] ?? ''));

        if ($pname === '' && $pemail === '') json_response(['ok' => false, 'error' => 'Performer required'], 400);
        if ($pct < 0)   $pct = 0;
        if ($pct > 100) $pct = 100;

        // Store the computed payout into amount_paid so existing reports see a value;
        // also keep commission_pct so it round-trips in the editor.
        $computedPayout = payout_of($amount, $pct);
        $payDate = $paid ?: date('Y-m-d');

        if ($id > 0) {
            db()->prepare(
                "UPDATE studio_payouts SET performer_email=?, performer_name=?, work_performed=?,
                    period_start=?, period_end=?, amount=?, commission_pct=?, amount_paid=?, payout_date=?, notes=?
                 WHERE id=?"
            )->execute([$pemail, $pname, $desc, $pstart, $pend, $amount, $pct, $computedPayout, $payDate, $notes, $id]);
        } else {
            db()->prepare(
                "INSERT INTO studio_payouts
                    (performer_email, performer_name, work_performed, period_start, period_end,
                     amount, commission_pct, amount_paid, payout_date, method, method_detail, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?, 'other', '', ?, ?)"
            )->execute([$pemail, $pname, $desc, $pstart, $pend, $amount, $pct, $computedPayout, $payDate, $notes, $email]);
            $id = (int)db()->lastInsertId();
        }
        json_response(['ok' => true, 'id' => $id]);
    }

    if ($action === 'delete') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'Missing id'], 400);
        db()->prepare("DELETE FROM studio_payouts WHERE id = ?")->execute([$id]);
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'Unknown action'], 400);

} catch (Throwable $e) {
    error_log('[studio-admin] ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Server error'], 500);
}
