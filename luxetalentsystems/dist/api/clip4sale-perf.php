<?php
/**
 * Luxe Talent — Clip4Sale performer summary endpoint
 *
 * GET /api/clip4sale-perf.php?email=<performer-email>
 *
 * Returns aggregate metrics + per-clip rows for the performer matching
 * the given email (clip4sale_clips.performer_email). All data lives in
 * the existing admin tables clip4sale_clips and clip4sale_sales — no
 * new tables, no scraping.
 *
 * Response:
 * {
 *   "performer_email": "...",
 *   "totals": {
 *     "clips_total":   N,
 *     "clips_live":    N,
 *     "clips_sold":    N,
 *     "clips_paid":    N,
 *     "clips_new":     N,
 *     "clips_draft":   N,
 *     "lifetime_revenue": 0.00,
 *     "lifetime_payout":  0.00,
 *     "lifetime_commission": 0.00,
 *     "last30_revenue":   0.00,
 *     "last30_payout":    0.00,
 *     "last_sale_date":   "YYYY-MM-DD"|null,
 *     "top_clip": { "clip_name":..., "revenue":..., "sales_count":N }|null
 *   },
 *   "clips": [
 *     { "id":..., "clip_name":..., "clip_id":..., "status":..., "clip_price":...,
 *       "published_date":..., "sales_count":N, "clip_revenue":..., "clip_payout":... },
 *     ...
 *   ]
 * }
 */
require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'GET only'], 405);
}

$email = trim((string)($_GET['email'] ?? ''));
if ($email === '') {
    json_response(['error' => 'email parameter required'], 400);
}

try {
    // ── Aggregate totals in one query ──
    $sqlTotals = "
        SELECT
            COUNT(*)                                       AS clips_total,
            SUM(c.status='live')                           AS clips_live,
            SUM(c.status='sold')                           AS clips_sold,
            SUM(c.status='paid')                           AS clips_paid,
            SUM(c.status='new')                            AS clips_new,
            SUM(c.status='draft')                          AS clips_draft,
            SUM(c.status='inactive')                       AS clips_inactive,
            SUM(c.status='denied')                         AS clips_denied
        FROM clip4sale_clips c
        WHERE c.performer_email = ?
    ";
    $st = db()->prepare($sqlTotals);
    $st->execute([$email]);
    $tot = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    // Cast counts to int
    foreach (['clips_total','clips_live','clips_sold','clips_paid','clips_new',
              'clips_draft','clips_inactive','clips_denied'] as $k) {
        $tot[$k] = (int)($tot[$k] ?? 0);
    }

    // ── Lifetime sales totals ──
    $sqlSalesTot = "
        SELECT
            COALESCE(SUM(s.sold_amount),   0) AS lifetime_revenue,
            COALESCE(SUM(s.payout_amount), 0) AS lifetime_payout,
            COALESCE(SUM(s.commission),    0) AS lifetime_commission,
            MAX(s.payment_date)               AS last_sale_date
        FROM clip4sale_sales s
        INNER JOIN clip4sale_clips c ON c.id = s.clip_pk
        WHERE c.performer_email = ?
    ";
    $st = db()->prepare($sqlSalesTot);
    $st->execute([$email]);
    $sTot = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Last 30 days ──
    $sqlSales30 = "
        SELECT
            COALESCE(SUM(s.sold_amount),   0) AS last30_revenue,
            COALESCE(SUM(s.payout_amount), 0) AS last30_payout
        FROM clip4sale_sales s
        INNER JOIN clip4sale_clips c ON c.id = s.clip_pk
        WHERE c.performer_email = ?
          AND s.payment_date >= (CURRENT_DATE - INTERVAL 30 DAY)
    ";
    $st = db()->prepare($sqlSales30);
    $st->execute([$email]);
    $s30 = $st->fetch(PDO::FETCH_ASSOC) ?: ['last30_revenue'=>0, 'last30_payout'=>0];

    // ── Top selling clip ──
    $sqlTop = "
        SELECT c.clip_name,
               COUNT(s.id)                       AS sales_count,
               COALESCE(SUM(s.sold_amount), 0)   AS revenue
        FROM clip4sale_clips c
        LEFT JOIN clip4sale_sales s ON s.clip_pk = c.id
        WHERE c.performer_email = ?
        GROUP BY c.id, c.clip_name
        HAVING revenue > 0
        ORDER BY revenue DESC
        LIMIT 1
    ";
    $st = db()->prepare($sqlTop);
    $st->execute([$email]);
    $top = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($top) {
        $top['sales_count'] = (int)$top['sales_count'];
        $top['revenue']     = (float)$top['revenue'];
    }

    // ── Per-clip rows ──
    $sqlClips = "
        SELECT
            c.id, c.clip_name, c.clip_id, c.status, c.clip_price, c.published_date,
            COUNT(s.id)                       AS sales_count,
            COALESCE(SUM(s.sold_amount), 0)   AS clip_revenue,
            COALESCE(SUM(s.payout_amount), 0) AS clip_payout
        FROM clip4sale_clips c
        LEFT JOIN clip4sale_sales s ON s.clip_pk = c.id
        WHERE c.performer_email = ?
        GROUP BY c.id
        ORDER BY clip_revenue DESC, c.published_date DESC, c.id DESC
    ";
    $st = db()->prepare($sqlClips);
    $st->execute([$email]);
    $clips = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($clips as &$row) {
        $row['id']          = (int)$row['id'];
        $row['clip_price']  = (float)$row['clip_price'];
        $row['sales_count'] = (int)$row['sales_count'];
        $row['clip_revenue']= (float)$row['clip_revenue'];
        $row['clip_payout'] = (float)$row['clip_payout'];
    }
    unset($row);

    json_response([
        'performer_email' => $email,
        'totals' => [
            'clips_total'        => $tot['clips_total'],
            'clips_live'         => $tot['clips_live'],
            'clips_sold'         => $tot['clips_sold'],
            'clips_paid'         => $tot['clips_paid'],
            'clips_new'          => $tot['clips_new'],
            'clips_draft'        => $tot['clips_draft'],
            'clips_inactive'     => $tot['clips_inactive'],
            'clips_denied'       => $tot['clips_denied'],
            'lifetime_revenue'   => (float)$sTot['lifetime_revenue'],
            'lifetime_payout'    => (float)$sTot['lifetime_payout'],
            'lifetime_commission'=> (float)$sTot['lifetime_commission'],
            'last30_revenue'     => (float)$s30['last30_revenue'],
            'last30_payout'      => (float)$s30['last30_payout'],
            'last_sale_date'     => $sTot['last_sale_date'] ?? null,
            'top_clip'           => $top,
        ],
        'clips' => $clips,
    ]);

} catch (Throwable $e) {
    error_log('[clip4sale-perf] ' . $e->getMessage());
    json_response(['error' => $e->getMessage()], 500);
}
