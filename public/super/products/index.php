<?php
// public/super/products/index.php — edit a single product.
// This page no longer creates products: new stock is only added on
// super/stock/new.php. Browsing/toggling/deleting products lives on
// super/inventory/ (grouped by supplier); its Edit buttons land here.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::INVENTORY_EDIT);

$pdo = Database::pdo();
$C = new Models\CategoryModel($pdo);
$S = new Models\SubcategoryModel($pdo);
$P = new Models\ProductModel($pdo);
$AL = new Models\AuditLogModel($pdo);
$SUP = new Models\SupplierModel($pdo);

$base = public_url('super/products/');
$inventoryUrl = public_url('super/inventory/');

$categories = $C->all([], 'name ASC');
$allSubs    = $S->all([], 'name ASC');
$suppliers  = $SUP->all([], 'name ASC');

// id => name maps so the activity log shows names, not raw ids.
$catName = []; foreach ($categories as $c) { $catName[(int)$c['id']] = $c['name']; }
$subName = []; foreach ($allSubs as $s) { $subName[(int)$s['id']] = $s['name']; }

$editId  = (int) ($_GET['edit'] ?? $_POST['id'] ?? 0);
$editRow = $editId > 0 ? $P->find($editId) : null;

// This page only edits an existing product — nothing to do without one.
if (!$editRow) {
    header('Location: ' . $inventoryUrl);
    exit;
}

$errors = [];
$old = [];

/** Turn a product-shaped row into label-friendly values for the audit diff. */
function audit_product_view(array $r, array $catName, array $subName): array
{
    $cid = (int) ($r['category_id'] ?? 0);
    $sid = (int) ($r['subcategory_id'] ?? 0);
    return [
        'name'                => $r['name'] ?? null,
        'category_id'         => $cid > 0 ? ($catName[$cid] ?? ('#' . $cid)) : null,
        'subcategory_id'      => $sid > 0 ? ($subName[$sid] ?? ('#' . $sid)) : null,
        'description'         => ($r['description'] ?? '') !== '' ? $r['description'] : null,
        'quantity'            => $r['quantity'] ?? null,
        'unit'                => $r['unit'] ?? null,
        'buying_price'        => $r['buying_price'] ?? null,
        'wholesale_price'     => $r['wholesale_price'] ?? null,
        'retail_price'        => $r['retail_price'] ?? null,
        'low_stock_threshold' => $r['low_stock_threshold'] ?? null,
        'status'              => $r['status'] ?? null,
        'image_path'          => ($r['image_path'] ?? '') !== '' ? 'Photo' : null,
    ];
}

/** Validate + store an uploaded product image. Returns ['ok','path'|'error','skip']. */
function product_handle_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || ($file['name'] ?? '') === '') {
        return ['ok' => true, 'path' => null, 'skip' => true];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Image upload failed. Try a smaller file.'];
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Image must be under 3 MB.'];
    }
    $info = @getimagesize($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = $info['mime'] ?? '';
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Use a JPG, PNG, WEBP or GIF image.'];
    }
    $dir = ROOT_PATH . '/public/assets/uploads/products';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $name = 'prod_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Could not save the image. Check folder permissions.'];
    }
    return ['ok' => true, 'path' => public_url('assets/uploads/products/' . $name)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = [
        'name'                => trim($_POST['name'] ?? ''),
        'category_id'         => (int) ($_POST['category_id'] ?? 0),
        'subcategory_id'      => (int) ($_POST['subcategory_id'] ?? 0),
        'supplier_id'         => (int) ($_POST['supplier_id'] ?? 0),
        'description'         => trim($_POST['description'] ?? ''),
        'quantity'            => $_POST['quantity'] ?? '',
        'unit'                => $_POST['unit'] ?? 'piece',
        'size_value'          => $_POST['size_value'] ?? '',
        'size_unit'           => $_POST['size_unit'] ?? '',
        'buying_price'        => $_POST['buying_price'] ?? '',
        'wholesale_price'     => $_POST['wholesale_price'] ?? '',
        'retail_price'        => $_POST['retail_price'] ?? '',
        'low_stock_threshold' => (int) ($_POST['low_stock_threshold'] ?? 10),
        'colors'              => array_filter(array_map('trim', explode(',', $_POST['colors'] ?? ''))),
        'sizes'               => array_filter(array_map('trim', explode(',', $_POST['sizes'] ?? ''))),
        'status'              => ($_POST['action'] ?? '') === 'draft' ? 'draft' : 'active',
    ];
    $old = $in;

    // Image: keep existing unless a new one is uploaded.
    $img = product_handle_image($_FILES['image'] ?? []);
    if (!$img['ok']) {
        $errors['image'] = $img['error'];
    } else {
        $in['image_path'] = empty($img['skip']) ? $img['path'] : ($editRow['image_path'] ?? null);
    }

    if (!$errors) {
        $beforeRow = $editRow;
        $res = $P->edit($editId, $in);
        if ($res['ok']) {
            try {
                $before = audit_product_view($beforeRow, $catName, $subName);
                $after  = audit_product_view($in, $catName, $subName);
                $changes = Models\AuditLogModel::diff($before, $after, Models\AuditLogModel::PRODUCT_FIELDS);
                if ($changes) {
                    $AL->record('product', $editId, $in['name'], 'updated', $changes);
                }
            } catch (\Throwable $e) { /* logging must never block the save */ }

            $_SESSION['flash']['success'] = 'Product updated.';
            header('Location: ' . $inventoryUrl); exit;
        }
        $errors = $res['errors'];
    }
    $old['image_path'] = $editRow['image_path'] ?? null;
}

// Prefill values (edit row, or repopulated $old after a failed submit)
$val = function (string $k, $default = '') use ($editRow, $old) {
    if (!empty($old)) { return $old[$k] ?? $default; }
    return $editRow[$k] ?? $default;
};
$csv = function (?string $json): string {
    $a = $json ? json_decode($json, true) : [];
    return is_array($a) ? implode(', ', $a) : '';
};
$colorsVal = !empty($old) ? implode(', ', (array) ($old['colors'] ?? [])) : $csv($editRow['colors'] ?? null);
$sizesVal  = !empty($old) ? implode(', ', (array) ($old['sizes'] ?? []))  : $csv($editRow['sizes'] ?? null);
$curImage  = $editRow['image_path'] ?? ($old['image_path'] ?? null);

$activity = $AL->recent('product', 20);
$page_title = 'Edit product';

// subcategories grouped by category for the dependent dropdown
$subsByCat = [];
foreach ($allSubs as $s) { $subsByCat[(int) $s['category_id']][] = ['id' => (int) $s['id'], 'name' => $s['name']]; }

ob_start();
$unitLabels = ['piece' => 'Piece(s)', 'g' => 'Grams (g)', 'kg' => 'Kilograms (kg)', 'tonne' => 'Tonnes', 'ml' => 'Millilitres (ml)', 'litre' => 'Litres'];

$actionBadge = function (string $a): string {
    $map = [
        'created'   => ['Created', 'bg-success'],
        'updated'   => ['Updated', 'bg-primary'],
        'activated' => ['Activated', 'bg-success'],
        'drafted'   => ['Set to draft', 'bg-secondary'],
        'deleted'   => ['Deleted', 'bg-danger'],
    ];
    [$label, $cls] = $map[$a] ?? [ucfirst($a), 'bg-secondary'];
    return '<span class="badge ' . $cls . '">' . $label . '</span>';
};
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h1 class="h5 mb-0 fw-bold">Edit product</h1>
  <a class="btn btn-sm btn-outline-secondary" href="<?php echo $inventoryUrl; ?>"><i class="fas fa-arrow-left me-1"></i>Back to inventory</a>
</div>

<div class="row g-4">
  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <p class="text-muted small mb-3">Stock value = buying price &times; quantity. To add new stock or a new product, use <a href="<?php echo public_url('super/stock/new.php'); ?>">Record stock</a>.</p>

        <?php if (!empty($errors['_'])): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($errors['_']); ?></div><?php endif; ?>
        <?php if (!empty($errors['image'])): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($errors['image']); ?></div><?php endif; ?>

        <form method="post" enctype="multipart/form-data" novalidate>
          <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">

          <div class="row g-2">
            <div class="col-7 mb-3">
              <label class="form-label">Category <span class="text-muted">(optional)</span></label>
              <select name="category_id" id="catSel" class="form-select">
                <option value="">Uncategorized</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>" <?php echo ((string)$val('category_id') === (string)$c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($errors['category_id'])): ?><small class="text-danger"><?php echo htmlspecialchars($errors['category_id']); ?></small><?php endif; ?>
            </div>
            <div class="col-5 mb-3">
              <label class="form-label">Subcategory <span class="text-muted">(optional)</span></label>
              <select name="subcategory_id" id="subSel" class="form-select"><option value="">—</option></select>
              <?php if (!empty($errors['subcategory_id'])): ?><small class="text-danger"><?php echo htmlspecialchars($errors['subcategory_id']); ?></small><?php endif; ?>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Product name</label>
            <input name="name" class="form-control" value="<?php echo htmlspecialchars($val('name')); ?>" placeholder="e.g. Tusker Lager" required>
            <?php if (!empty($errors['name'])): ?><small class="text-danger"><?php echo htmlspecialchars($errors['name']); ?></small><?php endif; ?>
          </div>

          <div class="mb-3">
            <label class="form-label">Supplier <span class="text-muted">(optional)</span></label>
            <select name="supplier_id" class="form-select">
              <option value="">—</option>
              <?php foreach ($suppliers as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>" <?php echo ((string)$val('supplier_id') === (string)$s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Description <span class="text-muted">(optional)</span></label>
            <textarea name="description" class="form-control" rows="2" placeholder="Short note about the product"><?php echo htmlspecialchars($val('description')); ?></textarea>
          </div>

          <div class="row g-2">
            <div class="col-4 mb-3">
              <label class="form-label">Quantity in stock</label>
              <input name="quantity" id="qtyP" type="number" step="0.01" min="0" class="form-control" value="<?php echo htmlspecialchars($val('quantity')); ?>" placeholder="0">
              <?php if (!empty($errors['quantity'])): ?><small class="text-danger"><?php echo htmlspecialchars($errors['quantity']); ?></small><?php endif; ?>
            </div>
            <div class="col-4 mb-3">
              <label class="form-label">Bottle size <span class="text-muted">(optional)</span></label>
              <div class="input-group">
                <input name="size_value" type="number" step="0.01" min="0" class="form-control" value="<?php echo htmlspecialchars($val('size_value')); ?>" placeholder="500">
                <select name="size_unit" class="form-select" style="max-width:80px;">
                  <?php foreach (['ml' => 'ML', 'l' => 'L'] as $u => $lbl): ?>
                    <option value="<?php echo $u; ?>" <?php echo ((string)$val('size_unit', 'ml') === $u) ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php if (!empty($errors['size_value'])): ?><small class="text-danger"><?php echo htmlspecialchars($errors['size_value']); ?></small><?php endif; ?>
            </div>
            <div class="col-4 mb-3">
              <label class="form-label">Unit</label>
              <select name="unit" class="form-select">
                <?php foreach ($unitLabels as $u => $lbl): ?>
                  <option value="<?php echo $u; ?>" <?php echo ((string)$val('unit', 'piece') === $u) ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="row g-2">
            <div class="col-4 mb-3">
              <label class="form-label">Buying price (KES)</label>
              <input name="buying_price" id="buyP" type="number" step="0.01" min="0" class="form-control" value="<?php echo htmlspecialchars($val('buying_price')); ?>" placeholder="0">
              <?php if (!empty($errors['buying_price'])): ?><small class="text-danger"><?php echo htmlspecialchars($errors['buying_price']); ?></small><?php endif; ?>
            </div>
            <div class="col-4 mb-3">
              <label class="form-label">Wholesale (KES)</label>
              <input name="wholesale_price" id="wholeP" type="number" step="0.01" min="0" class="form-control" value="<?php echo htmlspecialchars($val('wholesale_price', $val('selling_price'))); ?>" placeholder="0">
              <?php if (!empty($errors['wholesale_price'])): ?><small class="text-danger"><?php echo htmlspecialchars($errors['wholesale_price']); ?></small><?php endif; ?>
            </div>
            <div class="col-4 mb-3">
              <label class="form-label">Retail (KES)</label>
              <input name="retail_price" id="retailP" type="number" step="0.01" min="0" class="form-control" value="<?php echo htmlspecialchars($val('retail_price', $val('selling_price'))); ?>" placeholder="0">
              <?php if (!empty($errors['retail_price'])): ?><small class="text-danger"><?php echo htmlspecialchars($errors['retail_price']); ?></small><?php endif; ?>
            </div>
          </div>

          <div id="stockValueBox" class="alert alert-light border py-2 small mb-2" style="display:none;"></div>
          <div id="profitBox" class="alert alert-secondary py-2 small mb-3" style="display:none;"></div>

          <div class="row g-2">
            <div class="col-6 mb-3">
              <label class="form-label">Colours <span class="text-muted">(optional)</span></label>
              <input name="colors" class="form-control" value="<?php echo htmlspecialchars($colorsVal); ?>" placeholder="Blue, Red">
              <small class="text-muted">Comma-separated</small>
            </div>
            <div class="col-6 mb-3">
              <label class="form-label">Sizes <span class="text-muted">(optional)</span></label>
              <input name="sizes" class="form-control" value="<?php echo htmlspecialchars($sizesVal); ?>" placeholder="S, M, L">
              <small class="text-muted">Comma-separated</small>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Restock alert when stock reaches</label>
            <input name="low_stock_threshold" type="number" min="0" class="form-control" value="<?php echo htmlspecialchars($val('low_stock_threshold', 10)); ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Product image <span class="text-muted">(optional)</span></label>
            <?php if ($curImage): ?>
              <div class="mb-2"><img src="<?php echo htmlspecialchars($curImage); ?>" alt="" style="height:54px;border-radius:8px;border:1px solid #e2e8f0;"></div>
            <?php endif; ?>
            <input name="image" type="file" accept="image/*" class="form-control">
            <small class="text-muted">JPG, PNG, WEBP or GIF, under 3 MB.<?php echo $curImage ? ' Leave empty to keep the current image.' : ''; ?></small>
          </div>

          <button class="btn btn-primary" name="action" value="save">Save product</button>
          <button class="btn btn-outline-secondary" name="action" value="draft">Save as draft</button>
          <a class="btn btn-link" href="<?php echo $inventoryUrl; ?>">Cancel</a>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-4">
        <h2 class="h6 mb-3"><i class="fas fa-clock-rotate-left me-2 text-primary"></i>Recent activity</h2>
        <?php if (!$activity): ?>
          <div class="text-muted small">No changes recorded yet.</div>
        <?php else: ?>
          <div class="timeline">
            <?php foreach ($activity as $a):
                $when = date('j M Y, g:i a', strtotime($a['created_at']));
                $who  = $a['username'] ?: 'Unknown';
                $ch   = $a['changes'] ? json_decode($a['changes'], true) : [];
                if (!is_array($ch)) { $ch = []; }
            ?>
            <div class="d-flex gap-3 py-2" style="border-top:1px solid #f1f5f9;">
              <div class="text-muted small text-nowrap" style="min-width:110px;"><?php echo htmlspecialchars($when); ?></div>
              <div class="flex-grow-1">
                <div class="mb-1">
                  <?php echo $actionBadge($a['action']); ?>
                  <span class="fw-semibold ms-1"><?php echo htmlspecialchars($a['entity_label'] ?: ('#' . (int)$a['entity_id'])); ?></span>
                  <span class="text-muted small">by <?php echo htmlspecialchars($who); ?></span>
                </div>
                <?php if ($ch): ?>
                  <div class="small">
                    <?php foreach ($ch as $c): ?>
                      <span class="d-inline-block me-2 mb-1" style="background:#f8fafc;border:1px solid #eef2f7;border-radius:6px;padding:2px 8px;">
                        <span class="text-muted"><?php echo htmlspecialchars($c['label'] ?? $c['field'] ?? ''); ?>:</span>
                        <span><?php echo htmlspecialchars((string)($c['from'] ?? '—')); ?></span>
                        <i class="fas fa-arrow-right-long text-muted mx-1" style="font-size:.7rem;"></i>
                        <span class="fw-semibold"><?php echo htmlspecialchars((string)($c['to'] ?? '—')); ?></span>
                      </span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
  // Dependent subcategory dropdown
  var SUBS = <?php echo json_encode($subsByCat); ?>;
  var SELECTED_SUB = <?php echo json_encode((string)$val('subcategory_id')); ?>;
  var catSel = document.getElementById('catSel'), subSel = document.getElementById('subSel');
  function fillSubs() {
    var list = SUBS[catSel.value] || [];
    subSel.innerHTML = '<option value="">—</option>';
    list.forEach(function (s) {
      var o = document.createElement('option');
      o.value = s.id; o.textContent = s.name;
      if (String(s.id) === String(SELECTED_SUB)) o.selected = true;
      subSel.appendChild(o);
    });
  }
  if (catSel) { catSel.addEventListener('change', function () { SELECTED_SUB = ''; fillSubs(); }); fillSubs(); }

  // Live stock value + profit readout
  var buyP = document.getElementById('buyP'), wholeP = document.getElementById('wholeP'),
      retailP = document.getElementById('retailP'), qtyP = document.getElementById('qtyP'),
      box = document.getElementById('profitBox'), stockBox = document.getElementById('stockValueBox');
  function calcProfit() {
    var b = parseFloat(buyP.value), w = parseFloat(wholeP.value), r = parseFloat(retailP.value), q = parseFloat(qtyP.value) || 0;
    if (isNaN(b)) { box.style.display = 'none'; stockBox.style.display = 'none'; return; }
    if (!isNaN(q) && q >= 0) {
      stockBox.style.display = 'block';
      stockBox.innerHTML = 'Stock value at cost: <strong>KES ' + (b * q).toFixed(0) + '</strong> (buying &times; quantity)';
    } else { stockBox.style.display = 'none'; }
    if (isNaN(w) && isNaN(r)) { box.style.display = 'none'; return; }
    var wProfit = isNaN(w) ? null : w - b;
    var rProfit = isNaN(r) ? null : r - b;
    box.style.display = 'block';
    var html = '';
    if (wProfit !== null) {
      var wMargin = w > 0 ? (wProfit / w * 100) : 0;
      html += '<div><strong>Wholesale</strong> profit/unit: KES ' + wProfit.toFixed(0) + ' &middot; margin ' + wMargin.toFixed(1) + '%';
      if (q > 0) html += ' &middot; total profit if all sold: KES ' + (wProfit * q).toFixed(0);
      html += '</div>';
    }
    if (rProfit !== null) {
      var rMargin = r > 0 ? (rProfit / r * 100) : 0;
      html += '<div class="mt-1"><strong>Retail</strong> profit/unit: KES ' + rProfit.toFixed(0) + ' &middot; margin ' + rMargin.toFixed(1) + '%';
      if (q > 0) html += ' &middot; total profit if all sold: KES ' + (rProfit * q).toFixed(0);
      html += '</div>';
    }
    box.className = 'alert py-2 small mb-3 ' + ((wProfit !== null && wProfit < 0) || (rProfit !== null && rProfit < 0) ? 'alert-danger' : 'alert-success');
    box.innerHTML = html;
  }
  [buyP, wholeP, retailP, qtyP].forEach(function(el){ if(el) el.addEventListener('input', calcProfit); });
  calcProfit();
</script>
<?php
$content = ob_get_clean();
$__layout = TenantContext::role() === 'staff' ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
