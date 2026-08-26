<?php
/* ═══════════════════════════════════════════════════════════════════════════
   backup.php — a nightly copy of the store, kept on disk.

       sudo -u www-data php backup.php              # run it
       sudo -u www-data php backup.php --list       # what is already kept
       sudo -u www-data php backup.php --verify DIR # check one over

   Shopify has no undo. A price applied to fifty variants by mistake, or a
   product deleted, is gone — support cannot roll it back. That is what this
   is for.

   Two things it does that a plain export does not.

   It counts what it wrote and compares against yesterday. A backup that
   quietly captured nothing looks exactly like a good one until the day you
   need it, so a sharp drop is reported as a failure rather than filed away.

   And it keeps the app's own credentials and accounts alongside the data,
   because restoring products into a store you can no longer authenticate
   against is only half a recovery.
   ═══════════════════════════════════════════════════════════════════════════ */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Command line only.\n"); }

require __DIR__ . '/lib.php';

$ROOT   = defined('BACKUP_DIR')  ? BACKUP_DIR  : '/var/backups/everyday-shopify';
$KEEP   = defined('BACKUP_KEEP') ? BACKUP_KEEP : 30;      /* days */
$PAGE   = 100;

$list   = in_array('--list', $argv, true);
$verify = null;
foreach ($argv as $i => $a) if ($a === '--verify' && isset($argv[$i + 1])) $verify = $argv[$i + 1];

function human(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    return round($b / 1048576, 1) . ' MB';
}
function dirsize(string $d): int {
    $n = 0;
    foreach (glob($d . '/*') ?: [] as $f) $n += is_file($f) ? filesize($f) : dirsize($f);
    return $n;
}

/* ── what is already kept ─────────────────────────────────────────────── */
if ($list) {
    $dirs = glob($ROOT . '/20*') ?: [];
    rsort($dirs);
    if (!$dirs) { echo "Nothing backed up yet, in {$ROOT}\n"; exit(0); }
    echo count($dirs) . " backup(s) in {$ROOT}\n\n";
    foreach ($dirs as $d) {
        $man = @json_decode((string)@file_get_contents($d . '/manifest.json'), true);
        printf("  %-22s %-9s %s\n", basename($d), human(dirsize($d)),
            $man ? implode(', ', array_map(fn($k, $v) => "$k $v",
                  array_keys($man['counts'] ?? []), array_values($man['counts'] ?? [])))
                 : 'no manifest — incomplete');
    }
    exit(0);
}

/* ── check one over ───────────────────────────────────────────────────── */
if ($verify !== null) {
    $d = is_dir($verify) ? $verify : $ROOT . '/' . $verify;
    if (!is_dir($d)) exit("No such backup: {$verify}\n");

    $man = @json_decode((string)@file_get_contents($d . '/manifest.json'), true);
    if (!$man) exit("No manifest — that backup did not finish.\n");

    echo "Backup " . basename($d) . " from " . ($man['taken'] ?? '?') . "\n\n";
    $bad = 0;
    foreach ($man['files'] ?? [] as $f => $meta) {
        $path = $d . '/' . $f;
        if (!is_file($path)) { printf("  MISSING  %s\n", $f); $bad++; continue; }
        $size = filesize($path);
        $ok = ($size === ($meta['bytes'] ?? -1));
        printf("  %-8s %-26s %s\n", $ok ? 'ok' : 'CHANGED', $f, human($size));
        if (!$ok) $bad++;
        /* JSON that will not parse is worse than a missing file, because it
           looks present */
        if (str_ends_with($f, '.json') && json_decode((string)file_get_contents($path)) === null) {
            printf("  BROKEN   %s does not parse as JSON\n", $f);
            $bad++;
        }
    }
    echo "\n" . ($bad ? "{$bad} problem(s).\n" : "All present and readable.\n");
    exit($bad ? 1 : 0);
}

/* ── take one ─────────────────────────────────────────────────────────── */
$stamp = date('Y-m-d_His');
$dir   = $ROOT . '/' . $stamp;

if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
    exit("Could not create {$dir} — check the web user can write to " . dirname($ROOT) . "\n");
}

echo "── " . date('Y-m-d H:i') . " ──────────────────────────────\n";
echo "into {$dir}\n\n";

$counts = [];
$files  = [];
$fail   = [];

/* Walk a paged connection to the end. Stores outgrow one page quickly and a
   backup that silently stops at 100 is the kind of thing found too late. */
function collect(string $name, string $field, string $inner, array &$fail): array {
    $all = [];
    $after = null;
    $page = 0;

    do {
        $q = 'query($n: Int!, $after: String) { ' . $field
           . '(first: $n, after: $after) { edges { cursor node { ' . $inner . ' } } '
           . 'pageInfo { hasNextPage endCursor } } }';
        $r = shop_query($q, ['n' => 100, 'after' => $after]);

        if (!$r['ok']) {
            $fail[] = $name . ': ' . $r['message'];
            return $all;
        }
        $conn = $r['data'][$field] ?? [];
        foreach ($conn['edges'] ?? [] as $e) $all[] = $e['node'];

        $more  = !empty($conn['pageInfo']['hasNextPage']);
        $after = $conn['pageInfo']['endCursor'] ?? null;
        $page++;

        echo "  " . str_pad($name, 12) . " " . count($all) . ($more ? '…' : '') . "\r";
        if ($more) usleep(250000);          /* be civil to their rate limit */
    } while ($more && $page < 200);

    echo "  " . str_pad($name, 12) . " " . count($all) . "      \n";
    return $all;
}

function put(string $dir, string $name, $data, array &$files, array &$counts, ?int $n = null): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $path = $dir . '/' . $name;
    if (@file_put_contents($path, $json) === false) return;
    @chmod($path, 0600);
    $files[$name] = ['bytes' => filesize($path)];
    if ($n !== null) $counts[str_replace('.json', '', $name)] = $n;
}

/* ── the store ── */
$products = collect('products', 'products', '
    id title handle status vendor productType tags description totalInventory createdAt updatedAt
    featuredImage { url altText }
    variants(first: 100) { edges { node {
      id title sku barcode price compareAtPrice inventoryQuantity
      inventoryItem { id unitCost { amount currencyCode } }
    } } }', $fail);
put($dir, 'products.json', $products, $files, $counts, count($products));

$customers = collect('customers', 'customers', '
    id firstName lastName displayName email phone note tags
    verifiedEmail state numberOfOrders createdAt updatedAt
    amountSpent { amount currencyCode }
    defaultAddress { name company address1 address2 city province zip country phone }
    addresses { address1 address2 city province zip country }', $fail);
put($dir, 'customers.json', $customers, $files, $counts, count($customers));

$orders = collect('orders', 'orders', '
    id name createdAt processedAt cancelledAt
    displayFulfillmentStatus displayFinancialStatus note tags email
    totalPriceSet { shopMoney { amount currencyCode } }
    totalRefundedSet { shopMoney { amount } }
    customer { id email displayName }
    shippingAddress { name address1 address2 city province zip country phone }
    lineItems(first: 100) { edges { node {
      title quantity sku
      originalTotalSet { shopMoney { amount currencyCode } }
    } } }
    transactions(first: 20) { id kind status gateway processedAt
      amountSet { shopMoney { amount currencyCode } } }', $fail);
put($dir, 'orders.json', $orders, $files, $counts, count($orders));

/* ── the app's own state ── */
$app = [];
foreach (['.users.json' => 'accounts', 'config.php' => 'config', '.token' => 'token'] as $f => $label) {
    $src = __DIR__ . '/' . $f;
    if (!is_file($src)) continue;
    $dst = $dir . '/app-' . ltrim($f, '.');
    if (@copy($src, $dst)) {
        @chmod($dst, 0600);
        $files['app-' . ltrim($f, '.')] = ['bytes' => filesize($dst)];
        $app[] = $label;
    }
}
if ($app) echo "  " . str_pad('app', 12) . implode(', ', $app) . "\n";

/* ── how does this compare with yesterday ─────────────────────────────── */
$prev = null;
$dirs = glob($ROOT . '/20*') ?: [];
sort($dirs);
foreach (array_reverse($dirs) as $d) {
    if ($d === $dir) continue;
    $m = @json_decode((string)@file_get_contents($d . '/manifest.json'), true);
    if ($m) { $prev = $m; break; }
}

/* The best previous figure, not merely the last one. Comparing against the
   most recent backup means that once an empty one lands, every empty one
   after it looks normal — the alarm switches itself off exactly when it is
   needed. */
$best = [];
foreach (array_reverse($dirs) as $d) {
    if ($d === $dir) continue;
    $m = @json_decode((string)@file_get_contents($d . '/manifest.json'), true);
    if (!$m) continue;
    foreach ($m['counts'] ?? [] as $k => $v) {
        if (!isset($best[$k]) || $v > $best[$k]) $best[$k] = $v;
    }
}

$warnings = [];
foreach ($counts as $what => $n) {
    $was = $best[$what] ?? null;

    /* nothing at all, where there used to be something, is always wrong */
    if ($n === 0 && $was !== null && $was > 0) {
        $warnings[] = "{$what}: nothing saved, where {$was} were saved before. "
                    . "Treat this backup as unusable.";
        continue;
    }
    if ($was === null || $was === 0) continue;

    /* a real store loses a few records; it does not lose a third of them */
    if ($n < $was * 0.67) {
        $warnings[] = "{$what}: {$n} today against {$was} at best before — that is a big drop, "
                    . "so this backup may be incomplete.";
    }
}

$manifest = [
    'taken'    => date('c'),
    'store'    => SHOP_DOMAIN,
    'counts'   => $counts,
    'files'    => $files,
    'failed'   => $fail,
    'warnings' => $warnings,
    'previous' => $prev['taken'] ?? null,
    'best_before' => $best,
];
@file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
@chmod($dir . '/manifest.json', 0600);

/* ── throw away the old ones ──────────────────────────────────────────── */
$cut = time() - ($KEEP * 86400);
$gone = 0;
foreach (glob($ROOT . '/20*') ?: [] as $d) {
    if (!is_dir($d) || $d === $dir) continue;
    if (filemtime($d) > $cut) continue;
    foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
    if (@rmdir($d)) $gone++;
}

/* ── say how it went ──────────────────────────────────────────────────── */
echo "\n";
foreach ($counts as $k => $v) printf("  %-12s %d\n", $k, $v);
echo "  " . str_pad('size', 12) . human(dirsize($dir)) . "\n";
if ($gone) echo "  " . str_pad('removed', 12) . $gone . " older than {$KEEP} days\n";

$bad = false;
if ($fail) {
    $bad = true;
    echo "\nFAILED\n";
    foreach ($fail as $f) echo "  · {$f}\n";
    echo "\nThis backup is incomplete. Whatever is missing above was not saved.\n";
}
if ($warnings) {
    $bad = true;
    echo "\nWORTH CHECKING\n";
    foreach ($warnings as $w) echo "  · {$w}\n";
}
if (!$bad) echo "\nDone.\n";

exit($bad ? 1 : 0);
