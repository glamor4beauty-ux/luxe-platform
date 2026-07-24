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
        /* v3 — performer-scoped dashboard */
        case 'performers_list': v3PerformersList($pdo); break;
        case 'cm_list':     v3CmList($pdo); break;
        case 'cm_save':     v3CmSave($pdo); break;
        case 'cm_delete':   v3CmDelete($pdo); break;
        case 'ts_list':     v3TsList($pdo); break;
        case 'ts_save':     v3TsSave($pdo); break;
        case 'ts_delete':   v3TsDelete($pdo); break;
        case 'docs_list':   v3DocsList($pdo); break;
        case 'docs_save':   v3DocsSave($pdo); break;
        case 'docs_delete': v3DocsDelete($pdo); break;
        case 'docs_default':v3DocsDefault($pdo); break;
        case 'revenue':     v3Revenue($pdo); break;
        case 'cm_import':   v3CmImport($pdo); break;
        case 'ts_import':   v3TsImport($pdo); break;
        case 'latest_month':v3LatestMonth($pdo); break;
        case 'c4s_list':    v4C4sList($pdo); break;
        case 'c4s_add':     v4C4sAdd($pdo); break;
        case 'c4s_delete':  v4C4sDelete($pdo); break;
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

/* ═══════════════ v3 — performer-scoped dashboard (step 2) ═══════════════ */

function v3In(): array { return json_decode(file_get_contents('php://input'), true) ?: $_POST; }

function v3PerformersList(PDO $pdo): void {
    $st = $pdo->query("SELECT performer_id, stage_name, first_name, last_name, email
                       FROM registration WHERE role='performer' AND performer_id IS NOT NULL
                       ORDER BY stage_name, first_name");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $label = trim($r['stage_name'] ?? '') ?: trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''));
        $out[] = ['performer_id'=>(int)$r['performer_id'], 'label'=>$label ?: $r['email']];
    }
    json_response(['performers'=>$out]);
}

/* ── Content Management ── */
function v3CmList(PDO $pdo): void {
    $perf = (int)($_GET['perf'] ?? 0);
    $where = ''; $args = [];
    if ($perf) { $where = 'WHERE id_performer = ?'; $args[] = $perf; }
    $st = $pdo->prepare("SELECT id, file_name, category, type, visibility, performers, price, published_date, id_performer
                         FROM content_management $where ORDER BY published_date DESC, id DESC");
    $st->execute($args);
    json_response(['rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}
function v3CmSave(PDO $pdo): void {
    $in = v3In();
    $id = (int)($in['id'] ?? 0);
    $perf = isset($in['performer']) ? v4Resolve($pdo, $in['performer']) : ((int)($in['id_performer'] ?? 0) ?: null);
    $performers = trim($in['performers'] ?? '');
    if ($perf && $performers === '') {
        $q = $pdo->prepare("SELECT COALESCE(NULLIF(stage_name,''), CONCAT(first_name,' ',last_name)) FROM registration WHERE performer_id=?");
        $q->execute([$perf]); $performers = (string)$q->fetchColumn();
    }
    $v = [trim($in['file_name'] ?? ''), trim($in['category'] ?? ''), trim($in['type'] ?? ''),
          trim($in['visibility'] ?? 'Public'), $performers, (float)($in['price'] ?? 0),
          (trim($in['published_date'] ?? '') ?: null), $perf];
    if ($id) {
        $st = $pdo->prepare("UPDATE content_management SET file_name=?, category=?, type=?, visibility=?, performers=?, price=?, published_date=?, id_performer=? WHERE id=?");
        $st->execute(array_merge($v, [$id]));
    } else {
        $st = $pdo->prepare("INSERT INTO content_management (file_name, category, type, visibility, performers, price, published_date, id_performer) VALUES (?,?,?,?,?,?,?,?)");
        $st->execute($v);
        $id = (int)$pdo->lastInsertId();
    }
    json_response(['success'=>true, 'id'=>$id]);
}
function v3CmDelete(PDO $pdo): void {
    $id = (int)(v3In()['id'] ?? 0);
    if (!$id) throw new RuntimeException('Missing id');
    $pdo->prepare("DELETE FROM content_management WHERE id=?")->execute([$id]);
    json_response(['success'=>true]);
}

/* ── Track Sales ── */
function v3TsList(PDO $pdo): void {
    $perf  = (int)($_GET['perf'] ?? 0);
    $month = trim($_GET['month'] ?? '');
    $start = trim($_GET['start'] ?? '');
    $end   = trim($_GET['end'] ?? '');
    $where = []; $args = [];
    if ($perf) { $where[] = 'id_perf = ?'; $args[] = $perf; }
    $isDate = function($d){ return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };
    if ($start !== '' && $isDate($start)) { $where[] = 'sale_date >= ?'; $args[] = $start; }
    if ($end   !== '' && $isDate($end))   { $where[] = 'sale_date <= ?'; $args[] = $end; }
    if ($start === '' && $end === '' && $month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) { $where[] = "DATE_FORMAT(sale_date,'%Y-%m') = ?"; $args[] = $month; }
    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';
    $st = $pdo->prepare("SELECT id, sale_date, income, etp, amd, page_views, clips_sold, promo_sales, id_perf
                         FROM track_sales $whereSql ORDER BY sale_date DESC, id DESC");
    $st->execute($args);
    json_response(['rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}
function v3TsSave(PDO $pdo): void {
    $in = v3In();
    $id = (int)($in['id'] ?? 0);
    $date = trim($in['sale_date'] ?? '');
    if ($date === '') throw new RuntimeException('Missing date');
    $tsPerf = isset($in['performer']) ? v4Resolve($pdo, $in['performer']) : ((int)($in['id_perf'] ?? 0) ?: null);
    $v = [$date, (float)($in['income'] ?? 0), (float)($in['etp'] ?? 0), (float)($in['amd'] ?? 0),
          (int)($in['page_views'] ?? 0), (int)($in['clips_sold'] ?? 0), (float)($in['promo_sales'] ?? 0),
          $tsPerf];
    if ($id) {
        $st = $pdo->prepare("UPDATE track_sales SET sale_date=?, income=?, etp=?, amd=?, page_views=?, clips_sold=?, promo_sales=?, id_perf=? WHERE id=?");
        $st->execute(array_merge($v, [$id]));
    } else {
        $st = $pdo->prepare("INSERT INTO track_sales (sale_date, income, etp, amd, page_views, clips_sold, promo_sales, id_perf) VALUES (?,?,?,?,?,?,?,?)");
        $st->execute($v);
        $id = (int)$pdo->lastInsertId();
    }
    json_response(['success'=>true, 'id'=>$id]);
}
function v3TsDelete(PDO $pdo): void {
    $id = (int)(v3In()['id'] ?? 0);
    if (!$id) throw new RuntimeException('Missing id');
    $pdo->prepare("DELETE FROM track_sales WHERE id=?")->execute([$id]);
    json_response(['success'=>true]);
}

/* ── Performer Documents ── */
function v3DocsList(PDO $pdo): void {
    $perf = (int)($_GET['perf'] ?? 0);
    $q    = trim($_GET['q'] ?? '');
    $where = []; $args = [];
    if ($perf) { $where[] = 'performer_id = ?'; $args[] = $perf; }
    if ($q !== '') {
        $where[] = '(stage_name LIKE ? OR file_name LIKE ? OR doc_type LIKE ? OR status LIKE ? OR CAST(performer_id AS CHAR) LIKE ?)';
        $like = '%'.$q.'%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';
    $st = $pdo->prepare("SELECT id, performer_id, stage_name, file_name, doc_type, status, is_default
                         FROM perf_docs $whereSql ORDER BY performer_id, id");
    $st->execute($args);
    json_response(['rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}
function v3DocsSave(PDO $pdo): void {
    $in = v3In();
    $id   = (int)($in['id'] ?? 0);
    $perf = isset($in['performer']) ? v4Resolve($pdo, $in['performer']) : (int)($in['performer_id'] ?? 0);
    if (!$perf) throw new RuntimeException('Missing performer');
    $stage = trim($in['stage_name'] ?? '');
    if ($stage === '') {
        $q = $pdo->prepare("SELECT stage_name FROM clip4sale WHERE performer_id=? LIMIT 1");
        $q->execute([$perf]); $stage = (string)$q->fetchColumn();
    }
    $v = [$perf, $stage, trim($in['file_name'] ?? ''), trim($in['doc_type'] ?? ''),
          (trim($in['status'] ?? '') ?: 'Active'), ((int)($in['is_default'] ?? 0) ? 1 : 0)];
    if ($id) {
        $st = $pdo->prepare("UPDATE perf_docs SET performer_id=?, stage_name=?, file_name=?, doc_type=?, status=?, is_default=? WHERE id=?");
        $st->execute(array_merge($v, [$id]));
    } else {
        $st = $pdo->prepare("INSERT INTO perf_docs (performer_id, stage_name, file_name, doc_type, status, is_default) VALUES (?,?,?,?,?,?)");
        $st->execute($v);
        $id = (int)$pdo->lastInsertId();
    }
    json_response(['success'=>true, 'id'=>$id]);
}
function v3DocsDelete(PDO $pdo): void {
    $id = (int)(v3In()['id'] ?? 0);
    if (!$id) throw new RuntimeException('Missing id');
    $pdo->prepare("DELETE FROM perf_docs WHERE id=?")->execute([$id]);
    json_response(['success'=>true]);
}
function v3DocsDefault(PDO $pdo): void {
    $in = v3In();
    $id = (int)($in['id'] ?? 0);
    if (!$id) throw new RuntimeException('Missing id');
    $val = ((int)($in['is_default'] ?? 0)) ? 1 : 0;
    $pdo->prepare("UPDATE perf_docs SET is_default=? WHERE id=?")->execute([$val, $id]);
    json_response(['success'=>true]);
}

/* ── Revenue (from track_sales; optional month YYYY-MM + performer) ── */
function v3Revenue(PDO $pdo): void {
    $rawPerf = trim($_GET['perf'] ?? '');
    $perf = ctype_digit($rawPerf) ? (int)$rawPerf : ($rawPerf !== '' ? (function() use ($pdo, $rawPerf) { try { return v4Resolve($pdo, $rawPerf); } catch (Exception $e) { return -1; } })() : 0);
    $month = trim($_GET['month'] ?? '');
    $start = trim($_GET['start'] ?? '');
    $end   = trim($_GET['end'] ?? '');
    $where = []; $args = [];
    if ($perf) { $where[] = 'id_perf = ?'; $args[] = $perf; }
    $isDate = function($d){ return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };
    if ($start !== '' && $isDate($start)) { $where[] = 'sale_date >= ?'; $args[] = $start; }
    if ($end   !== '' && $isDate($end))   { $where[] = 'sale_date <= ?'; $args[] = $end; }
    if ($start === '' && $end === '' && $month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) { $where[] = "DATE_FORMAT(sale_date,'%Y-%m') = ?"; $args[] = $month; }
    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';
    $st = $pdo->prepare("SELECT COALESCE(SUM(income),0) AS income, COALESCE(SUM(etp),0) AS etp,
                                COALESCE(SUM(amd),0) AS amd, COALESCE(SUM(promo_sales),0) AS promo_sales,
                                COALESCE(SUM(clips_sold),0) AS clips_sold, COALESCE(SUM(page_views),0) AS page_views
                         FROM track_sales $whereSql");
    $st->execute($args);
    json_response($st->fetch(PDO::FETCH_ASSOC) ?: []);
}

/* ── CSV imports (body: {csv:"...", perf:optional performer_id}) ── */
function v3CsvRows(string $csv): array {
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    if (count($lines) < 2) throw new RuntimeException('CSV needs a header row + at least 1 data row');
    $head = array_map(function($h){ return strtolower(trim(str_replace(' ', '_', trim($h, " \t\"'")))); }, str_getcsv(array_shift($lines)));
    $rows = [];
    foreach ($lines as $ln) {
        if (trim($ln) === '') continue;
        $vals = str_getcsv($ln);
        $r = [];
        foreach ($head as $i => $k) $r[$k] = trim($vals[$i] ?? '');
        $rows[] = $r;
    }
    return $rows;
}
function v3Col(array $r, array $names): string {
    foreach ($names as $n) if (isset($r[$n]) && $r[$n] !== '') return $r[$n];
    return '';
}
function v3CmImport(PDO $pdo): void {
    $in = v3In();
    $perfDefault = v4Resolve($pdo, $in['perf'] ?? '');
    $rows = v3CsvRows((string)($in['csv'] ?? ''));
    $st = $pdo->prepare("INSERT INTO content_management (file_name, category, type, visibility, performers, price, published_date, id_performer) VALUES (?,?,?,?,?,?,?,?)");
    $n = 0;
    foreach ($rows as $r) {
        $file = v3Col($r, ['file_name','filename','file']);
        if ($file === '') continue;
        $perf = (int)v3Col($r, ['performer_id','id_performer','perf_id']) ?: $perfDefault;
        $date = v3Col($r, ['published_date','publish_date','date']);
        $st->execute([$file, v3Col($r,['category','cat']), v3Col($r,['type']),
                      (v3Col($r,['visibility','vis']) ?: 'Public'), v3Col($r,['performers','performer','stage_name']),
                      (float)str_replace(['$',','], '', v3Col($r,['price'])), ($date ?: null), $perf]);
        $n++;
    }
    json_response(['success'=>true, 'imported'=>$n]);
}
function v3TsImport(PDO $pdo): void {
    $in = v3In();
    $perfDefault = v4Resolve($pdo, $in['perf'] ?? '');
    $rows = v3CsvRows((string)($in['csv'] ?? ''));
    $st = $pdo->prepare("INSERT INTO track_sales (sale_date, income, etp, amd, page_views, clips_sold, promo_sales, id_perf) VALUES (?,?,?,?,?,?,?,?)");
    $n = 0;
    foreach ($rows as $r) {
        $date = v3Col($r, ['sale_date','date']);
        if ($date === '') continue;
        $perf = (int)v3Col($r, ['performer_id','id_perf','perf_id']) ?: $perfDefault;
        $money = function($k) use ($r) { return (float)str_replace(['$',','], '', v3Col($r, $k)); };
        $st->execute([$date, $money(['income']), $money(['etp']), $money(['amd']),
                      (int)str_replace(',', '', v3Col($r,['page_views','views'])),
                      (int)v3Col($r,['clips_sold','clip_sold','sold']),
                      $money(['promo_sales','promo']), $perf]);
        $n++;
    }
    json_response(['success'=>true, 'imported'=>$n]);
}

/* ── Latest month with revenue for a performer (or overall) ── */
function v3LatestMonth(PDO $pdo): void {
    $perf = (int)($_GET['perf'] ?? 0);
    if ($perf) {
        $st = $pdo->prepare("SELECT DATE_FORMAT(MAX(sale_date),'%Y-%m') FROM track_sales WHERE id_perf=?");
        $st->execute([$perf]);
    } else {
        $st = $pdo->query("SELECT DATE_FORMAT(MAX(sale_date),'%Y-%m') FROM track_sales");
    }
    json_response(['month' => ($st->fetchColumn() ?: null)]);
}

/* ═══════════ v4 — clip4sale enrollment table + resolution ═══════════ */

/* Resolve a typed value (performer ID or stage name) against the clip4sale table.
   Returns performer_id int, null (empty input), or throws (not found). */
function v4Resolve(PDO $pdo, $val) {
    $val = trim((string)$val);
    if ($val === '') return null;
    if (ctype_digit($val)) {
        $st = $pdo->prepare("SELECT performer_id FROM clip4sale WHERE performer_id=? LIMIT 1");
        $st->execute([(int)$val]);
    } else {
        $st = $pdo->prepare("SELECT performer_id FROM clip4sale WHERE stage_name LIKE ? ORDER BY stage_name LIMIT 1");
        $st->execute(['%'.$val.'%']);
    }
    $id = $st->fetchColumn();
    if ($id === false) throw new RuntimeException('Performer "'.$val.'" not found in Clip4Sale table');
    return (int)$id;
}
function v4C4sList(PDO $pdo): void {
    $st = $pdo->query("SELECT id, store_id, performer_id, stage_name FROM clip4sale ORDER BY stage_name, performer_id");
    json_response(['rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}
function v4C4sAdd(PDO $pdo): void {
    $in = v3In();
    $store = trim($in['store_id'] ?? '');
    $perf  = (int)($in['performer_id'] ?? 0);
    if ($store === '' || !$perf) throw new RuntimeException('Store ID and Performer ID required');
    $q = $pdo->prepare("SELECT COALESCE(NULLIF(stage_name,''), CONCAT(first_name,' ',last_name)) FROM registration WHERE performer_id=?");
    $q->execute([$perf]);
    $stage = $q->fetchColumn();
    if ($stage === false) throw new RuntimeException('ID '.$perf.' not found — enter it on the performer\'s admin record first');
    $st = $pdo->prepare("INSERT IGNORE INTO clip4sale (store_id, performer_id, stage_name) VALUES (?,?,?)");
    $st->execute([$store, $perf, $stage]);
    json_response(['success'=>true, 'stage_name'=>$stage]);
}
function v4C4sDelete(PDO $pdo): void {
    $id = (int)(v3In()['id'] ?? 0);
    if (!$id) throw new RuntimeException('Missing id');
    $pdo->prepare("DELETE FROM clip4sale WHERE id=?")->execute([$id]);
    json_response(['success'=>true]);
}
