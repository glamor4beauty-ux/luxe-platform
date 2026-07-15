<?php
require_once __DIR__ . '/includes/auth.php';

$user = require_login();
$page_title = 'Dashboard';

// Filter & search
$search = trim($_GET['q'] ?? '');
$filter_status = $_GET['status'] ?? '';
$filter_promoted = $_GET['promoted'] ?? '';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(title LIKE ? OR handle LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filter_status && in_array($filter_status, ['Active','Inactive','Draft','Scheduled'])) {
    $where[] = 'status = ?';
    $params[] = $filter_status;
}
if ($filter_promoted && in_array($filter_promoted, ['Listings','Off-site'])) {
    $where[] = 'promoted = ?';
    $params[] = $filter_promoted;
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = db()->prepare("
  SELECT p.*, COUNT(v.id) AS variant_count
  FROM fc_products p
  LEFT JOIN fc_variants v ON v.product_id = p.id
  $where_sql
  GROUP BY p.id
  ORDER BY p.title ASC
");
$stmt->execute($params);
$products = $stmt->fetchAll();

$total = (int) db()->query('SELECT COUNT(*) FROM fc_products')->fetchColumn();
$active = (int) db()->query("SELECT COUNT(*) FROM fc_products WHERE status='Active'")->fetchColumn();
// Total potential profit on active inventory: SUM((retail - wholesale) * quantity)
$potential_profit = (float) db()->query("
  SELECT COALESCE(SUM((retail_price - wholesale_price) * quantity), 0)
  FROM fc_products WHERE status='Active'
")->fetchColumn();

require __DIR__ . '/includes/header.php';
?>
<div class="dash-summary">
  <div class="stat">
    <div class="stat-value"><?= number_format($total) ?></div>
    <div class="stat-label">Total Products</div>
  </div>
  <div class="stat">
    <div class="stat-value stat-green"><?= number_format($active) ?></div>
    <div class="stat-label">Active</div>
  </div>
  <div class="stat">
    <div class="stat-value"><?= count($products) ?></div>
    <div class="stat-label">Showing</div>
  </div>
  <div class="stat">
    <div class="stat-value stat-accent">$<?= number_format($potential_profit, 2) ?></div>
    <div class="stat-label">Potential Profit (Active)</div>
  </div>
</div>

<form class="filter-bar" method="get">
  <input type="search" name="q" placeholder="Search by title or handle&hellip;" value="<?= esc($search) ?>">
  <select name="status">
    <option value="">All Statuses</option>
    <?php foreach (['Active','Inactive','Draft','Scheduled'] as $s): ?>
      <option value="<?= $s ?>" <?= $filter_status === $s ? 'selected' : '' ?>><?= $s ?></option>
    <?php endforeach; ?>
  </select>
  <select name="promoted">
    <option value="">All Promotion</option>
    <option value="Listings"  <?= $filter_promoted === 'Listings'  ? 'selected' : '' ?>>Listings</option>
    <option value="Off-site"  <?= $filter_promoted === 'Off-site'  ? 'selected' : '' ?>>Off-site</option>
  </select>
  <button type="submit" class="btn btn-primary">Apply</button>
  <a href="dashboard.php" class="btn btn-ghost">Clear</a>
</form>

<div class="table-wrap">
<table class="data-table">
  <thead>
    <tr>
      <th class="col-title">Product Name</th>
      <th class="col-num">Retail</th>
      <th class="col-num">Wholesale</th>
      <th class="col-num">Profit</th>
      <th class="col-num">Qty</th>
      <th class="col-mid">Promoted</th>
      <th class="col-mid">Status</th>
      <th class="col-actions">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$products): ?>
    <tr>
      <td colspan="8" class="empty">
        No products found.
        <?php if ($user['role'] === 'super_admin'): ?>
          <a href="upload.php">Upload a Shopify CSV</a> to get started.
        <?php endif; ?>
      </td>
    </tr>
  <?php else: foreach ($products as $p): ?>
    <tr data-product-id="<?= (int) $p['id'] ?>">
      <td class="col-title">
        <a href="#" class="product-link" onclick="openDetail(<?= (int) $p['id'] ?>);return false;">
          <?= esc($p['title']) ?>
        </a>
        <?php if ($p['variant_count']): ?>
          <span class="variant-pill"><?= $p['variant_count'] ?> variant<?= $p['variant_count'] == 1 ? '' : 's' ?></span>
        <?php endif; ?>
        <div class="handle-sub"><?= esc($p['handle']) ?></div>
      </td>
      <td class="col-num">$<?= number_format((float) $p['retail_price'], 2) ?></td>
      <td class="col-num">$<?= number_format((float) $p['wholesale_price'], 2) ?></td>
      <?php
        $profit = (float) $p['retail_price'] - (float) $p['wholesale_price'];
        $profit_class = $profit > 0 ? 'profit-pos' : ($profit < 0 ? 'profit-neg' : 'profit-zero');
      ?>
      <td class="col-num <?= $profit_class ?>">$<?= number_format($profit, 2) ?></td>
      <td class="col-num"><?= (int) $p['quantity'] ?></td>
      <td class="col-mid">
        <select class="inline-select" data-field="promoted" data-id="<?= (int) $p['id'] ?>">
          <option value="Listings"  <?= $p['promoted'] === 'Listings'  ? 'selected' : '' ?>>Listings</option>
          <option value="Off-site"  <?= $p['promoted'] === 'Off-site'  ? 'selected' : '' ?>>Off-site</option>
        </select>
      </td>
      <td class="col-mid">
        <select class="inline-select status-<?= esc($p['status']) ?>" data-field="status" data-id="<?= (int) $p['id'] ?>">
          <?php foreach (['Active','Inactive','Draft','Scheduled'] as $s): ?>
            <option value="<?= $s ?>" <?= $p['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td class="col-actions">
        <button class="btn btn-small btn-primary" onclick="openEdit(<?= (int) $p['id'] ?>)">Edit</button>
        <?php if ($user['role'] === 'super_admin'): ?>
          <button class="btn btn-small btn-danger" onclick="confirmDelete(<?= (int) $p['id'] ?>, '<?= esc(addslashes($p['title'])) ?>')">Delete</button>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<!-- Detail Modal -->
<div class="modal" id="detailModal" style="display:none">
  <div class="modal-content">
    <div class="modal-header">
      <h2 id="detailTitle"></h2>
      <button class="modal-close" onclick="closeModal('detailModal')">&times;</button>
    </div>
    <div class="modal-body" id="detailBody">Loading&hellip;</div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal" id="editModal" style="display:none">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Edit Product</h2>
      <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
    </div>
    <div class="modal-body" id="editBody">Loading&hellip;</div>
  </div>
</div>

<!-- Delete confirmation -->
<div class="modal" id="deleteModal" style="display:none">
  <div class="modal-content modal-small">
    <div class="modal-header">
      <h2>Delete product?</h2>
      <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
    </div>
    <div class="modal-body">
      <p>This will permanently delete <strong id="deleteName"></strong> and all its variants from the database. This cannot be undone.</p>
      <p>The product on eBay is <em>not</em> affected &mdash; this only removes it from your local inventory dashboard.</p>
      <div class="modal-actions">
        <button class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
        <button class="btn btn-danger" id="deleteConfirmBtn">Delete forever</button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const USER_ROLE = <?= json_encode($user['role']) ?>;

function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}

async function openDetail(id) {
  const m = document.getElementById('detailModal');
  m.style.display = 'flex';
  document.getElementById('detailBody').innerHTML = 'Loading&hellip;';
  document.getElementById('detailTitle').textContent = '';
  try {
    const r = await fetch('api.php?action=detail&id=' + id);
    const data = await r.json();
    if (!data.ok) throw new Error(data.error || 'Failed to load');
    renderDetail(data.product);
  } catch (e) {
    document.getElementById('detailBody').textContent = 'Error: ' + e.message;
  }
}

function renderDetail(p) {
  document.getElementById('detailTitle').textContent = p.title;
  let html = '';
  html += '<div class="detail-grid">';
  html += '<div><div class="detail-label">Handle</div><div class="detail-value">' + escapeHtml(p.handle) + '</div></div>';
  html += '<div><div class="detail-label">Retail Price</div><div class="detail-value">$' + Number(p.retail_price).toFixed(2) + '</div></div>';
  html += '<div><div class="detail-label">Wholesale Price</div><div class="detail-value">$' + Number(p.wholesale_price).toFixed(2) + '</div></div>';
  var prof = Number(p.retail_price) - Number(p.wholesale_price);
  var profCls = prof > 0 ? 'profit-pos' : (prof < 0 ? 'profit-neg' : 'profit-zero');
  html += '<div><div class="detail-label">Profit</div><div class="detail-value ' + profCls + '">$' + prof.toFixed(2) + '</div></div>';
  html += '<div><div class="detail-label">Quantity</div><div class="detail-value">' + p.quantity + '</div></div>';
  html += '<div><div class="detail-label">Status</div><div class="detail-value">' + escapeHtml(p.status) + '</div></div>';
  html += '<div><div class="detail-label">Promoted</div><div class="detail-value">' + escapeHtml(p.promoted) + '</div></div>';
  html += '</div>';

  if (p.description) {
    html += '<h3 class="detail-section">Description</h3>';
    html += '<div class="detail-description">' + p.description + '</div>';
  }

  if (p.variants && p.variants.length) {
    html += '<h3 class="detail-section">Variants (' + p.variants.length + ')</h3>';
    html += '<table class="variant-table"><thead><tr>';
    html += '<th>SKU</th><th>Option 1</th><th>Option 2</th><th>Cost</th><th>Price</th><th>Compare At</th>';
    html += '</tr></thead><tbody>';
    p.variants.forEach(function(v) {
      html += '<tr>';
      html += '<td><code>' + escapeHtml(v.sku || '') + '</code></td>';
      html += '<td>' + escapeHtml((v.option1_name||'') + ': ' + (v.option1_value||'')) + '</td>';
      html += '<td>' + escapeHtml((v.option2_name||'') + (v.option2_value ? ': ' + v.option2_value : '')) + '</td>';
      html += '<td>$' + Number(v.cost || 0).toFixed(2) + '</td>';
      html += '<td>$' + Number(v.price || 0).toFixed(2) + '</td>';
      html += '<td>$' + Number(v.compare_at_price || 0).toFixed(2) + '</td>';
      html += '</tr>';
    });
    html += '</tbody></table>';
  }

  document.getElementById('detailBody').innerHTML = html;
}

async function openEdit(id) {
  const m = document.getElementById('editModal');
  m.style.display = 'flex';
  document.getElementById('editBody').innerHTML = 'Loading&hellip;';
  try {
    const r = await fetch('api.php?action=detail&id=' + id);
    const data = await r.json();
    if (!data.ok) throw new Error(data.error || 'Failed to load');
    renderEdit(data.product);
  } catch (e) {
    document.getElementById('editBody').textContent = 'Error: ' + e.message;
  }
}

function renderEdit(p) {
  let html = '';
  html += '<form id="editForm" onsubmit="return saveEdit(event, ' + p.id + ')">';
  html += '<label>Title<input type="text" name="title" value="' + escapeAttr(p.title) + '" required maxlength="500"></label>';
  html += '<div class="form-row">';
  html += '<label>Retail Price<input type="number" name="retail_price" step="0.01" min="0" value="' + Number(p.retail_price).toFixed(2) + '"></label>';
  html += '<label>Wholesale Price<input type="number" name="wholesale_price" step="0.01" min="0" value="' + Number(p.wholesale_price).toFixed(2) + '"></label>';
  html += '<label>Quantity<input type="number" name="quantity" min="0" value="' + p.quantity + '"></label>';
  html += '</div>';
  html += '<div class="form-row">';
  html += '<label>Status<select name="status">';
  ['Active','Inactive','Draft','Scheduled'].forEach(function(s) {
    html += '<option value="' + s + '"' + (p.status === s ? ' selected' : '') + '>' + s + '</option>';
  });
  html += '</select></label>';
  html += '<label>Promoted<select name="promoted">';
  ['Listings','Off-site'].forEach(function(s) {
    html += '<option value="' + s + '"' + (p.promoted === s ? ' selected' : '') + '>' + s + '</option>';
  });
  html += '</select></label>';
  html += '</div>';
  html += '<label>Description (HTML allowed)<textarea name="description" rows="8">' + escapeHtml(p.description || '') + '</textarea></label>';
  html += '<div class="modal-actions">';
  html += '<button type="button" class="btn btn-ghost" onclick="closeModal(\'editModal\')">Cancel</button>';
  html += '<button type="submit" class="btn btn-primary">Save changes</button>';
  html += '</div>';
  html += '<div id="editError" class="alert alert-error" style="display:none;margin-top:1em"></div>';
  html += '</form>';
  document.getElementById('editBody').innerHTML = html;
}

async function saveEdit(e, id) {
  e.preventDefault();
  const form = e.target;
  const fd = new FormData(form);
  fd.append('csrf_token', CSRF);
  fd.append('id', id);
  try {
    const r = await fetch('api.php?action=update_product', { method: 'POST', body: fd });
    const data = await r.json();
    if (!data.ok) throw new Error(data.error || 'Save failed');
    location.reload();
  } catch (err) {
    const eb = document.getElementById('editError');
    eb.textContent = err.message;
    eb.style.display = 'block';
  }
  return false;
}

function confirmDelete(id, name) {
  document.getElementById('deleteName').textContent = name;
  const btn = document.getElementById('deleteConfirmBtn');
  btn.onclick = async function() {
    try {
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('id', id);
      const r = await fetch('api.php?action=delete_product', { method: 'POST', body: fd });
      const data = await r.json();
      if (!data.ok) throw new Error(data.error || 'Delete failed');
      location.reload();
    } catch (e) {
      alert('Delete failed: ' + e.message);
    }
  };
  document.getElementById('deleteModal').style.display = 'flex';
}

// Inline dropdown changes (Promoted, Status)
document.querySelectorAll('.inline-select').forEach(function(sel) {
  sel.addEventListener('change', async function() {
    const id = this.dataset.id;
    const field = this.dataset.field;
    const value = this.value;
    try {
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('id', id);
      fd.append('field', field);
      fd.append('value', value);
      const r = await fetch('api.php?action=update_field', { method: 'POST', body: fd });
      const data = await r.json();
      if (!data.ok) throw new Error(data.error || 'Update failed');
      // Update status color class
      if (field === 'status') {
        this.className = 'inline-select status-' + value;
      }
    } catch (e) {
      alert('Update failed: ' + e.message);
      location.reload();
    }
  });
});

function escapeHtml(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function escapeAttr(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

// Click outside modal to close
document.querySelectorAll('.modal').forEach(function(m) {
  m.addEventListener('click', function(e) {
    if (e.target === m) m.style.display = 'none';
  });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
