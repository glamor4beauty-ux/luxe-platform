<?php
/**
 * JSON API endpoints for the dashboard.
 *
 * Actions:
 *   ?action=detail&id=N        -> GET product + variants
 *   ?action=update_field       -> POST {id, field, value} (single dropdown change)
 *   ?action=update_product     -> POST full edit form
 *   ?action=delete_product     -> POST {id} (super_admin only)
 */
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'detail':
            $id = (int) ($_GET['id'] ?? 0);
            $stmt = db()->prepare('SELECT * FROM fc_products WHERE id = ?');
            $stmt->execute([$id]);
            $product = $stmt->fetch();
            if (!$product) {
                throw new RuntimeException('Product not found');
            }
            $stmt = db()->prepare('SELECT * FROM fc_variants WHERE product_id = ? ORDER BY id ASC');
            $stmt->execute([$id]);
            $product['variants'] = $stmt->fetchAll();
            echo json_encode(['ok' => true, 'product' => $product]);
            break;

        case 'update_field':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST required');
            require_csrf();
            $id    = (int) ($_POST['id'] ?? 0);
            $field = $_POST['field'] ?? '';
            $value = $_POST['value'] ?? '';

            $allowed = [
                'promoted' => ['Listings', 'Off-site'],
                'status'   => ['Active', 'Inactive', 'Draft', 'Scheduled'],
            ];
            if (!isset($allowed[$field])) throw new RuntimeException('Invalid field');
            if (!in_array($value, $allowed[$field], true)) throw new RuntimeException('Invalid value');

            $stmt = db()->prepare("UPDATE fc_products SET {$field} = ? WHERE id = ?");
            $stmt->execute([$value, $id]);
            echo json_encode(['ok' => true]);
            break;

        case 'update_product':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST required');
            require_csrf();
            $id   = (int) ($_POST['id'] ?? 0);
            $data = [
                'title'           => trim($_POST['title'] ?? ''),
                'retail_price'    => (float) ($_POST['retail_price']    ?? 0),
                'wholesale_price' => (float) ($_POST['wholesale_price'] ?? 0),
                'quantity'        => (int)   ($_POST['quantity']        ?? 0),
                'status'          => $_POST['status']   ?? 'Active',
                'promoted'        => $_POST['promoted'] ?? 'Listings',
                'description'     => $_POST['description'] ?? '',
            ];

            if ($data['title'] === '') throw new RuntimeException('Title is required');
            if (!in_array($data['status'],   ['Active','Inactive','Draft','Scheduled'], true)) {
                throw new RuntimeException('Invalid status');
            }
            if (!in_array($data['promoted'], ['Listings','Off-site'], true)) {
                throw new RuntimeException('Invalid promoted value');
            }

            $stmt = db()->prepare("UPDATE fc_products SET title=?, retail_price=?, wholesale_price=?,
                                  quantity=?, status=?, promoted=?, description=? WHERE id=?");
            $stmt->execute([
                $data['title'], $data['retail_price'], $data['wholesale_price'],
                $data['quantity'], $data['status'], $data['promoted'],
                $data['description'], $id,
            ]);
            echo json_encode(['ok' => true]);
            break;

        case 'delete_product':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST required');
            require_csrf();
            if ($user['role'] !== 'super_admin') {
                http_response_code(403);
                throw new RuntimeException('Only Super Admin can delete products');
            }
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = db()->prepare('DELETE FROM fc_products WHERE id = ?');
            $stmt->execute([$id]);
            echo json_encode(['ok' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
