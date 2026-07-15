<?php
require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';

/* ═══════════════════════════════════════════════════════════════
   Clip4Sale — multi-sale model (v2)
   Sales table fields: clip_pk, payment_date, sold_amount, commission, payout_amount, notes
   Status (live/new/etc) lives on clips only.
   ═══════════════════════════════════════════════════════════════ */

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $pdo = db();
    switch ($action) {
        case 'stats':       handleStats($pdo); break;
        case 'clips_list':  handleClipsList($pdo); break;
        case 'clip_save':   handleClipSave($pdo); break;
        case 'clip_status': handleClipStatus($pdo); break;
        case 'clip_delete': handleClipDelete($pdo); break;
        case 'sales_list':  handleSalesList($pdo); break;
        case 'sale_save':   handleSaleSave($pdo); break;
        case 'sale_delete': handleSaleDelete($pdo); break;
        case 'totals':      handleTotals($pdo); break;
        default: json_response(['success'=>false,'error'=>'Invalid action'], 400);
    }
} catch (Throwable $e) {
    error_log('[clip4sale] ' . $e->getMessage());
    json_response(['success'=>false,'error'=>$e->getMessage()], 400);
}

function handleStats(PDO $pdo): void {
    $live  = (int)$pdo->query("SELECT COUNT(*) FROM clip4sale_clips WHERE status='live'")->fetchColumn();
    $total = (int)$pdo->query("SELECT COUNT(*) FROM clip4sale_clips")->fetchColumn();
    $new   = (int)$pdo->query("SELECT COUNT(*) FROM clip4sale_clips WHERE status='new'")->fetchColumn();
    json_response(['live'=>$live, 'total_uploads'=>$total, 'new_uploads'=>$new]);
}

function handleClipsList(PDO $pdo): void {
    $performer = trim($_GET['performer'] ?? '');
    $where = ''; $args = [];
    if ($performer !== '') { $where = 'WHERE performer = ?'; $args[] = $performer; }
    $sql = "SELECT id, performer, performer_email, published_date, clip_name, clip_id, tracking_id, clip_price, status, created_at, updated_at,
                   (SELECT COUNT(*) FROM clip4sale_sales s WHERE s.clip_pk = c.id) AS sale_count
            FROM clip4sale_clips c $where ORDER BY created_at DESC";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    json_response(['clips' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

function handleClipSave(PDO $pdo): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id   = (int)($in['id'] ?? 0);
    $data = [
        'performer'       => trim($in['performer'] ?? ''),
        'performer_email' => trim($in['performer_email'] ?? ''),
        'published_date'  => trim($in['published_date'] ?? '') ?: null,
        'clip_name'       => trim($in['clip_name'] ?? ''),
        'clip_id'         => trim($in['clip_id'] ?? ''),
        'tracking_id'     => trim($in['tracking_id'] ?? ''),
        'clip_price'      => (float)($in['clip_price'] ?? 0),
        'status'          => strtolower(trim($in['status'] ?? 'new')),
    ];
    if (!in_array($data['status'], ['new','live','sold','paid','inactive'], true)) $data['status'] = 'new';

    if ($id) {
        $st = $pdo->prepare("UPDATE clip4sale_clips SET performer=?, performer_email=?, published_date=?, clip_name=?, clip_id=?, tracking_id=?, clip_price=?, status=? WHERE id=?");
        $st->execute([$data['performer'], $data['performer_email'], $data['published_date'], $data['clip_name'], $data['clip_id'], $data['tracking_id'], $data['clip_price'], $data['status'], $id]);
    } else {
        $st = $pdo->prepare("INSERT INTO clip4sale_clips (performer, performer_email, published_date, clip_name, clip_id, tracking_id, clip_price, status) VALUES (?,?,?,?,?,?,?,?)");
        $st->execute([$data['performer'], $data['performer_email'], $data['published_date'], $data['clip_name'], $data['clip_id'], $data['tracking_id'], $data['clip_price'], $data['status']]);
        $id = (int)$pdo->lastInsertId();
    }
    json_response(['success'=>true, 'id'=>$id]);
}

function handleClipStatus(PDO $pdo): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($in['id'] ?? 0);
    $status = strtolower(trim($in['status'] ?? ''));
    if (!$id) throw new RuntimeException('Missing id');
    if (!in_array($status, ['new','live','sold','paid','inactive'], true)) throw new RuntimeException('Invalid status');
    $pdo->prepare("UPDATE clip4sale_clips SET status=? WHERE id=?")->execute([$status, $id]);
    json_response(['success'=>true]);
}

function handleClipDelete(PDO $pdo): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($in['id'] ?? 0);
    if (!$id) throw new RuntimeException('Missing id');
    $pdo->prepare("DELETE FROM clip4sale_clips WHERE id=?")->execute([$id]);
    json_response(['success'=>true]);
}

/* ── Sales list: optionally filter by performer (joins clips) or by search ── */
function handleSalesList(PDO $pdo): void {
    $performer = trim($_GET['performer'] ?? '');
    $search    = trim($_GET['search'] ?? '');
    $where = []; $args = [];
    if ($performer !== '') { $where[] = 'c.performer = ?'; $args[] = $performer; }
    if ($search !== '') {
        $where[] = '(c.clip_id LIKE ? OR c.clip_name LIKE ? OR s.notes LIKE ?)';
        $like = '%' . $search . '%';
        $args[] = $like; $args[] = $like; $args[] = $like;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT s.id, s.clip_pk, s.payment_date, s.sold_amount, s.commission, s.payout_amount, s.notes,
                   c.clip_id, c.clip_name, c.performer
            FROM clip4sale_sales s
            JOIN clip4sale_clips c ON c.id = s.clip_pk
            $whereSql
            ORDER BY s.payment_date DESC, s.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    json_response(['sales' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

function handleSaleSave(PDO $pdo): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($in['id'] ?? 0);
    $clipPk = (int)($in['clip_pk'] ?? 0);
    if (!$clipPk) throw new RuntimeException('Missing clip_pk');
    $data = [
        'payment_date'  => trim($in['payment_date'] ?? '') ?: null,
        'sold_amount'   => (float)($in['sold_amount'] ?? 0),
        'commission'    => (float)($in['commission'] ?? 0),
        'payout_amount' => (float)($in['payout_amount'] ?? 0),
        'notes'         => trim($in['notes'] ?? ''),
    ];
    if ($id) {
        $st = $pdo->prepare("UPDATE clip4sale_sales SET clip_pk=?, payment_date=?, sold_amount=?, commission=?, payout_amount=?, notes=? WHERE id=?");
        $st->execute([$clipPk, $data['payment_date'], $data['sold_amount'], $data['commission'], $data['payout_amount'], $data['notes'], $id]);
    } else {
        $st = $pdo->prepare("INSERT INTO clip4sale_sales (clip_pk, payment_date, sold_amount, commission, payout_amount, notes) VALUES (?,?,?,?,?,?)");
        $st->execute([$clipPk, $data['payment_date'], $data['sold_amount'], $data['commission'], $data['payout_amount'], $data['notes']]);
        $id = (int)$pdo->lastInsertId();
    }
    json_response(['success'=>true, 'id'=>$id]);
}

function handleSaleDelete(PDO $pdo): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($in['id'] ?? 0);
    if (!$id) throw new RuntimeException('Missing id');
    $pdo->prepare("DELETE FROM clip4sale_sales WHERE id=?")->execute([$id]);
    json_response(['success'=>true]);
}

function handleTotals(PDO $pdo): void {
    $performer = trim($_GET['performer'] ?? '');
    $where = []; $args = [];
    if ($performer !== '') { $where[] = 'c.performer = ?'; $args[] = $performer; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT
              COALESCE(SUM(s.sold_amount),0)    AS total_sold,
              COALESCE(SUM(s.commission),0)     AS total_commission,
              COALESCE(SUM(s.payout_amount),0)  AS total_payout,
              COUNT(s.id) AS sale_count
            FROM clip4sale_sales s
            JOIN clip4sale_clips c ON c.id = s.clip_pk
            $whereSql";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    json_response($st->fetch(PDO::FETCH_ASSOC) ?: []);
}
