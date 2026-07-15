<?php
require_once __DIR__ . '/includes/auth.php';

$user = require_super_admin();
$page_title = 'Upload CSV';

$result = null;
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $error = 'No file uploaded or upload failed.';
    } else {
        $tmp = $_FILES['csv']['tmp_name'];
        $name = $_FILES['csv']['name'];

        $fh = fopen($tmp, 'r');
        if (!$fh) {
            $error = 'Could not read uploaded file.';
        } else {
            // Skip UTF-8 BOM
            $bom = fread($fh, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($fh);

            $headers = fgetcsv($fh);
            if (!$headers) {
                $error = 'Empty or invalid CSV.';
            } else {
                // Normalize header lookup
                $idx = [];
                foreach ($headers as $i => $h) {
                    $idx[trim($h)] = $i;
                }

                function col($row, $idx, $key) {
                    return isset($idx[$key]) ? trim($row[$idx[$key]] ?? '') : '';
                }

                $rows_processed = 0;
                $products_added = 0;
                $products_updated = 0;
                $variants_added = 0;

                // Group rows by Handle
                $groups = [];
                while (($row = fgetcsv($fh)) !== false) {
                    $handle = col($row, $idx, 'Handle');
                    if ($handle === '') continue;
                    $groups[$handle][] = $row;
                    $rows_processed++;
                }
                fclose($fh);

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    foreach ($groups as $handle => $group) {
                        // The "parent" row is the first one that has Title populated
                        $parent = null;
                        foreach ($group as $r) {
                            if (col($r, $idx, 'Title') !== '') { $parent = $r; break; }
                        }
                        if (!$parent) $parent = $group[0];

                        $title  = col($parent, $idx, 'Title');
                        $cost   = (float) col($parent, $idx, 'Cost per item');
                        $price  = (float) col($parent, $idx, 'Variant Price');
                        $compare= (float) col($parent, $idx, 'Variant Compare At Price');
                        $status = col($parent, $idx, 'Status') ?: 'Active';
                        $status_map = ['active'=>'Active','draft'=>'Draft','archived'=>'Inactive'];
                        $norm_status = $status_map[strtolower($status)] ?? 'Active';
                        $image  = col($parent, $idx, 'Image Src');

                        // Wholesale = Cost per item from Shopify; Retail = Variant Price
                        $retail    = $price > 0 ? $price : $compare;
                        $wholesale = $cost;

                        // Check if product exists (merge mode — preserve manual Status/Promoted)
                        $stmt = $pdo->prepare('SELECT id, status, promoted FROM fc_products WHERE handle = ?');
                        $stmt->execute([$handle]);
                        $existing = $stmt->fetch();

                        if ($existing) {
                            // Update, preserving status/promoted that user may have changed manually
                            $stmt = $pdo->prepare("UPDATE fc_products SET
                                title=?, retail_price=?, wholesale_price=?, image_src=?
                                WHERE id=?");
                            $stmt->execute([$title, $retail, $wholesale, $image, $existing['id']]);
                            $product_id = (int) $existing['id'];
                            $products_updated++;
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO fc_products
                                (handle, title, retail_price, wholesale_price, image_src, status, promoted)
                                VALUES (?, ?, ?, ?, ?, ?, 'Listings')");
                            $stmt->execute([$handle, $title, $retail, $wholesale, $image, $norm_status]);
                            $product_id = (int) $pdo->lastInsertId();
                            $products_added++;
                        }

                        // Replace variants (simpler than diff'ing — Shopify exports are authoritative)
                        $pdo->prepare('DELETE FROM fc_variants WHERE product_id = ?')->execute([$product_id]);
                        $vstmt = $pdo->prepare("INSERT INTO fc_variants
                            (product_id, sku, option1_name, option1_value, option2_name, option2_value,
                             grams, price, compare_at_price, cost, barcode, image_src, image_position, variant_image)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        foreach ($group as $r) {
                            $sku = col($r, $idx, 'Variant SKU');
                            $opt1n = col($r, $idx, 'Option1 Name');
                            $opt1v = col($r, $idx, 'Option1 Value');
                            $opt2n = col($r, $idx, 'Option2 Name');
                            $opt2v = col($r, $idx, 'Option2 Value');
                            // Skip rows that look like image-only sub-rows (no SKU and no option values)
                            if ($sku === '' && $opt1v === '' && $opt2v === '') continue;
                            $vstmt->execute([
                                $product_id,
                                $sku,
                                $opt1n, $opt1v,
                                $opt2n, $opt2v,
                                (int)   col($r, $idx, 'Variant Grams'),
                                (float) col($r, $idx, 'Variant Price'),
                                (float) col($r, $idx, 'Variant Compare At Price'),
                                (float) col($r, $idx, 'Cost per item'),
                                col($r, $idx, 'Variant Barcode'),
                                col($r, $idx, 'Image Src'),
                                (int)   col($r, $idx, 'Image Position'),
                                col($r, $idx, 'Variant Image'),
                            ]);
                            $variants_added++;
                        }

                        // Update quantity to count of variants if not explicitly set elsewhere
                        $pdo->prepare('UPDATE fc_products SET quantity = (SELECT COUNT(*) FROM fc_variants WHERE product_id = ?) WHERE id = ?')
                            ->execute([$product_id, $product_id]);
                    }

                    // Record upload history
                    $stmt = $pdo->prepare('INSERT INTO fc_upload_history (user_id, filename, rows_processed, rows_added, rows_updated) VALUES (?,?,?,?,?)');
                    $stmt->execute([$user['id'], $name, $rows_processed, $products_added, $products_updated]);

                    $pdo->commit();

                    $result = [
                        'rows_processed' => $rows_processed,
                        'products_added' => $products_added,
                        'products_updated' => $products_updated,
                        'variants_added' => $variants_added,
                    ];
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $error = 'Import failed: ' . $e->getMessage();
                }
            }
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Upload Shopify CSV</h1>
<p class="page-sub">Merges by Handle. Existing products keep their manual Status and Promoted settings; new products are added with Status = Active. No products are deleted by upload.</p>

<?php if ($error): ?>
  <div class="alert alert-error"><?= esc($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
  <div class="alert alert-success">
    <strong>Upload complete.</strong><br>
    Rows processed: <?= (int) $result['rows_processed'] ?><br>
    Products added: <?= (int) $result['products_added'] ?><br>
    Products updated: <?= (int) $result['products_updated'] ?><br>
    Variants imported: <?= (int) $result['variants_added'] ?>
  </div>
  <p><a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a></p>
<?php else: ?>
  <form method="post" enctype="multipart/form-data" class="upload-form">
    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
    <label class="file-drop">
      <input type="file" name="csv" accept=".csv,text/csv" required>
      <span>Choose CSV file&hellip;</span>
    </label>
    <div class="form-actions">
      <a href="dashboard.php" class="btn btn-ghost">Cancel</a>
      <button type="submit" class="btn btn-primary">Upload &amp; Merge</button>
    </div>
  </form>

  <div class="info-card">
    <h3>Expected columns</h3>
    <p>The CSV should be a Shopify products export with these columns (order doesn't matter, but spelling does):</p>
    <code>Handle, Title, Option1 Name, Option1 Value, Option1 Linked To, Option2 Name, Option2 Value, Variant SKU, Variant Grams, Variant Price, Variant Compare At Price, Variant Barcode, Image Src, Image Position, Variant Image, Cost per item, Status</code>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
