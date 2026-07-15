<?php
// ════════════════════════════════════════════════════════════════════
// /api/studio-payouts.php v2 — adds date range filter + totals endpoint
//
// Actions:
//   GET  list          — list payouts (admin: all; performer: only own)
//                        filters: performer, start_date, end_date, period
//   GET  totals        — gross/fee/unpaid totals for the filtered set
//   GET  performers    — distinct list of performers with payout history
//   POST save          — admin only: create or update a payout
//   POST delete        — admin only: delete a payout
//   POST last_seen     — performer pings on dashboard load
// ════════════════════════════════════════════════════════════════════

require __DIR__.'/../cors.php';
session_start();
require __DIR__.'/../config.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$email  = $_SESSION['user_email'] ?? '';
$role   = $_SESSION['user_role']  ?? '';
$isAdmin = in_array(strtolower($role), ['admin','superadmin']);

if (!$isAdmin && !in_array($action, ['list','totals','last_seen'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Admin only']);
    exit;
}
if (!$email) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Not signed in']);
    exit;
}

// ── Build WHERE clause from filters (shared by list + totals) ──
function buildWhere($isAdmin, $sessionEmail) {
    $where = [];
    $args  = [];

    $perfFilter = trim($_GET['performer'] ?? '');
    // Performers see only their own data
    if (!$isAdmin) $perfFilter = $sessionEmail;

    if ($perfFilter !== '') {
        $where[] = 'performer_email = ?';
        $args[]  = $perfFilter;
    }

    $start = trim($_GET['start_date'] ?? '');
    $end   = trim($_GET['end_date']   ?? '');
    $period = trim($_GET['period']    ?? '');

    // Period presets override start/end if not provided
    if (!$start && !$end && $period) {
        $today = date('Y-m-d');
        switch ($period) {
            case 'current':
            case 'current_payment':
                $start = date('Y-m-01');
                $end   = $today;
                break;
            case 'last_30':
                $start = date('Y-m-d', strtotime('-30 days'));
                $end   = $today;
                break;
            case 'last_90':
                $start = date('Y-m-d', strtotime('-90 days'));
                $end   = $today;
                break;
            case 'ytd':
                $start = date('Y-01-01');
                $end   = $today;
                break;
        }
    }

    if ($start) { $where[] = 'payout_date >= ?'; $args[] = $start; }
    if ($end)   { $where[] = 'payout_date <= ?'; $args[] = $end; }

    $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';
    return [$whereSql, $args];
}

try {
    $pdo = db();

    switch ($action) {

        // ── List payouts ──
        case 'list': {
            [$whereSql, $args] = buildWhere($isAdmin, $email);
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
            $sql = "SELECT id, performer_email, performer_name, work_performed,
                           payout_date, amount, amount_paid, method, method_detail,
                           period_start, period_end, notes, created_by, created_at, updated_at
                    FROM studio_payouts
                    $whereSql
                    ORDER BY payout_date DESC, id DESC
                    LIMIT $limit";
            $st = $pdo->prepare($sql);
            $st->execute($args);
            echo json_encode(['ok'=>true, 'payouts'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        // ── Totals (gross / fee / unpaid) ──
        case 'totals': {
            [$whereSql, $args] = buildWhere($isAdmin, $email);
            $sql = "SELECT
                       COALESCE(SUM(amount), 0)       AS gross_payout,
                       COALESCE(SUM(amount_paid), 0)  AS total_paid,
                       COUNT(*)                       AS pay_count,
                       MAX(payout_date)               AS last_payout_date
                    FROM studio_payouts
                    $whereSql";
            $st = $pdo->prepare($sql);
            $st->execute($args);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            $gross = (float)($row['gross_payout'] ?? 0);
            $paid  = (float)($row['total_paid']   ?? 0);
            $feePct = (float)($_GET['fee_percent'] ?? 20);
            $fee   = round($gross * ($feePct / 100), 2);
            $unpaid = round($gross - $paid - $fee, 2);
            if ($unpaid < 0) $unpaid = 0;

            echo json_encode([
                'ok'              => true,
                'gross_payout'    => $gross,
                'studio_fee'      => $fee,
                'fee_percent'     => $feePct,
                'total_paid'      => $paid,
                'total_unpaid'    => $unpaid,
                'pay_count'       => (int)($row['pay_count'] ?? 0),
                'last_payout_date'=> $row['last_payout_date'] ?? null,
            ]);
            break;
        }

        // ── Distinct performers (for dropdown) ──
        case 'performers': {
            if (!$isAdmin) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admin only']); exit; }
            $st = $pdo->query("
                SELECT performer_email, MAX(performer_name) AS name, COUNT(*) AS n
                FROM studio_payouts
                GROUP BY performer_email
                ORDER BY name
            ");
            echo json_encode(['ok'=>true, 'performers'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        // ── Save ──
        case 'save': {
            if (!$isAdmin) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admin only']); exit; }

            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $id            = (int)($in['id'] ?? 0);
            $perfEmail     = strtolower(trim($in['performer_email'] ?? ''));
            $perfName      = trim($in['performer_name'] ?? '');
            $workPerformed = trim($in['work_performed'] ?? '');
            $payoutDate    = trim($in['payout_date'] ?? '');
            $amount        = (float)($in['amount'] ?? 0);
            $amountPaid    = (float)($in['amount_paid'] ?? 0);
            $method        = strtolower(trim($in['method'] ?? 'cash'));
            $methodDetail  = trim($in['method_detail'] ?? '');
            $periodStart   = trim($in['period_start'] ?? '') ?: null;
            $periodEnd     = trim($in['period_end']   ?? '') ?: null;
            $notes         = trim($in['notes'] ?? '');

            if (!$perfEmail || !$payoutDate || $amount < 0) {
                http_response_code(400);
                echo json_encode(['ok'=>false,'error'=>'Email, date, and non-negative amount required']);
                exit;
            }
            $methods = ['cash','cashapp','zelle','venmo','paypal','check','bank','other'];
            if (!in_array($method, $methods)) $method = 'other';

            if (!$perfName) {
                $st = $pdo->prepare("SELECT COALESCE(stage_name, CONCAT_WS(' ', first_name, last_name), email) FROM registration WHERE email=? LIMIT 1");
                $st->execute([$perfEmail]);
                $perfName = $st->fetchColumn() ?: $perfEmail;
            }

            if ($id > 0) {
                $st = $pdo->prepare("UPDATE studio_payouts
                                     SET performer_email=?, performer_name=?, work_performed=?,
                                         payout_date=?, amount=?, amount_paid=?,
                                         method=?, method_detail=?, period_start=?, period_end=?, notes=?
                                     WHERE id=?");
                $st->execute([$perfEmail, $perfName, $workPerformed, $payoutDate, $amount, $amountPaid,
                              $method, $methodDetail, $periodStart, $periodEnd, $notes, $id]);
                echo json_encode(['ok'=>true, 'id'=>$id, 'updated'=>true]);
            } else {
                $st = $pdo->prepare("INSERT INTO studio_payouts
                                     (performer_email, performer_name, work_performed,
                                      payout_date, amount, amount_paid,
                                      method, method_detail, period_start, period_end, notes, created_by)
                                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$perfEmail, $perfName, $workPerformed, $payoutDate, $amount, $amountPaid,
                              $method, $methodDetail, $periodStart, $periodEnd, $notes, $email]);
                echo json_encode(['ok'=>true, 'id'=>$pdo->lastInsertId(), 'created'=>true]);
            }
            break;
        }

        // ── Delete ──
        case 'delete': {
            if (!$isAdmin) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admin only']); exit; }
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
            $st = $pdo->prepare("DELETE FROM studio_payouts WHERE id=?");
            $st->execute([$id]);
            echo json_encode(['ok'=>true, 'deleted'=>$st->rowCount()]);
            break;
        }

        case 'last_seen': {
            // No-op for now (kept for backward compat)
            echo json_encode(['ok'=>true]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok'=>false, 'error'=>'Unknown action: '.$action]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
