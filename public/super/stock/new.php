<?php
// public/super/stock/new.php — record a delivery: a supplier brings a batch
// of products (new ones, or a restock of ones already in the catalogue).
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::STOCK_ENTER);

$pdo = Database::pdo();
$SUP = new Models\SupplierModel($pdo);
$P   = new Models\ProductModel($pdo);
$C   = new Models\CategoryModel($pdo);
$SI  = new Models\StockIntakeModel($pdo);

$base = public_url('super/stock/new.php');

/** Validate + store a row's uploaded image. Same rules as the products page. */
function stock_row_image_file(int $i): array
{
    if (!isset($_FILES['items']['name'][$i]['image']) || $_FILES['items']['name'][$i]['image'] === '') {
        return ['error' => UPLOAD_ERR_NO_FILE];
    }
    return [
        'name'     => $_FILES['items']['name'][$i]['image'],
        'type'     => $_FILES['items']['type'][$i]['image'],
        'tmp_name' => $_FILES['items']['tmp_name'][$i]['image'],
        'error'    => $_FILES['items']['error'][$i]['image'],
        'size'     => $_FILES['items']['size'][$i]['image'],
    ];
}

function stock_handle_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
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

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplierId = 0;
    $newSupplierName = trim($_POST['new_supplier_name'] ?? '');
    if (($_POST['supplier_id'] ?? '') === 'new') {
        if ($newSupplierName === '') {
            $error = 'Enter the new supplier\'s name.';
        } else {
            $res = $SUP->create($newSupplierName);
            if (!$res['ok']) { $error = $res['error']; }
            else { $supplierId = (int) $res['id']; }
        }
    } else {
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    }

    $rows = $_POST['items'] ?? [];

    if (!$error) {
        $items = [];
        foreach ($rows as $i => $row) {
            $qty = (float) ($row['quantity'] ?? 0);
            if ($qty <= 0) { continue; } // skip rows the user didn't fill in
            $productChoice = $row['product_choice'] ?? 'new';

            if ($productChoice !== 'new') {
                $items[] = [
                    'mode'         => 'restock',
                    'product_id'   => (int) $productChoice,
                    'quantity'     => $qty,
                    'buying_price' => (float) ($row['buying_price'] ?? 0),
                ];
                continue;
            }

            $name = trim($row['name'] ?? '');
            if ($name === '') { continue; }

            $img = stock_handle_image(stock_row_image_file((int) $i));
            if (!$img['ok']) { $error = $name . ': ' . $img['error']; break; }

            $items[] = [
                'mode'            => 'new',
                'name'            => $name,
                'category_id'     => (int) ($row['category_id'] ?? 0),
                'size_value'      => $row['size_value'] ?? '',
                'size_unit'       => $row['size_unit'] ?? '',
                'quantity'        => $qty,
                'buying_price'    => (float) ($row['buying_price'] ?? 0),
                'selling_price'   => $row['selling_price'] ?? 0,
                'wholesale_price' => $row['wholesale_price'] ?? '',
                'image_path'      => $img['path'] ?? '',
            ];
        }

        if (!$error && !$items) {
            $error = 'Add at least one product with a quantity.';
        }

        if (!$error) {
            $res = $SI->create([
                'supplier_id' => $supplierId,
                'staff_id'    => TenantContext::userId(),
                'notes'       => $_POST['notes'] ?? '',
            ], $items);
            if ($res['ok']) {
                $_SESSION['flash']['success'] = 'Stock recorded — ' . count($items) . ' product' . (count($items) === 1 ? '' : 's') . '.';
                header('Location: ' . public_url('super/inventory/'));
                exit;
            }
            $error = $res['errors']['_'] ?? (reset($res['errors']) ?: 'Could not record this delivery.');
        }
    }
}

$suppliers  = $SUP->all([], 'name ASC');
$categories = $C->all([], 'name ASC');
$products  = array_values(array_filter($P->listWithMeta(), fn($p) => $p['status'] === 'active'));
$page_title = 'Record stock';
ob_start();
$sizeUnits = ['ml' => 'ML', 'l' => 'L'];
?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" id="stockForm" novalidate>
  <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
    <div class="card-body p-4">
      <h2 class="h5 mb-3">This delivery</h2>
      <div class="row g-3">
        <div class="col-12 col-md-6">
          <label class="form-label">Supplier</label>
          <select name="supplier_id" id="supplierSel" class="form-select" required>
            <option value="">Choose a supplier…</option>
            <option value="new">+ New supplier</option>
            <?php foreach ($suppliers as $s): ?>
              <option value="<?php echo (int) $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="new_supplier_name" id="newSupplierName" class="form-control mt-2" placeholder="New supplier name" style="display:none;">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
          <input name="notes" class="form-control" placeholder="e.g. invoice #, delivery notes">
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
    <div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Products received</h2>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addRowBtn"><i class="fas fa-plus me-1"></i>Add another product</button>
      </div>
      <div id="rows"></div>
    </div>
  </div>

  <button class="btn btn-primary btn-lg"><i class="fas fa-truck-loading me-1"></i>Record stock</button>
</form>

<template id="rowTpl">
  <div class="stock-row border rounded p-3 mb-3" style="border-color:#e2e8f0!important;">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <span class="fw-semibold small text-muted">Product __N__</span>
      <button type="button" class="btn btn-sm btn-link text-danger p-0 removeRow">Remove</button>
    </div>
    <div class="row g-2">
      <div class="col-12">
        <label class="form-label small mb-1">Product</label>
        <select name="items[__I__][product_choice]" class="form-select form-select-sm productChoice">
          <option value="new">+ New product</option>
          <?php foreach ($products as $p): ?>
            <option value="<?php echo (int) $p['id']; ?>" data-buying="<?php echo (float) $p['buying_price']; ?>">
              <?php echo htmlspecialchars($p['name']); ?><?php echo $p['size_value'] ? ' (' . htmlspecialchars(Models\ProductModel::sizeLabel($p)) . ')' : ''; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="newProductFields">
        <div class="col-12 col-sm-6 mt-2">
          <label class="form-label small mb-1">New product name</label>
          <input type="text" name="items[__I__][name]" class="form-control form-control-sm" placeholder="e.g. Tusker Lager">
        </div>
        <div class="col-12 col-sm-6 mt-2">
          <label class="form-label small mb-1">Category <span class="text-muted">(optional)</span></label>
          <select name="items[__I__][category_id]" class="form-select form-select-sm">
            <option value="">Uncategorized</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-sm-2 mt-2">
          <label class="form-label small mb-1">Size</label>
          <input type="number" step="0.01" min="0" name="items[__I__][size_value]" class="form-control form-control-sm" placeholder="500">
        </div>
        <div class="col-6 col-sm-2 mt-2">
          <label class="form-label small mb-1">Unit</label>
          <select name="items[__I__][size_unit]" class="form-select form-select-sm">
            <?php foreach ($sizeUnits as $u => $lbl): ?><option value="<?php echo $u; ?>"><?php echo $lbl; ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="col-6 col-sm-3 mt-2">
        <label class="form-label small mb-1">Quantity</label>
        <input type="number" step="0.01" min="0" name="items[__I__][quantity]" class="form-control form-control-sm" placeholder="0">
      </div>
      <div class="col-6 col-sm-3 mt-2">
        <label class="form-label small mb-1">Buying price (each)</label>
        <input type="number" step="0.01" min="0" name="items[__I__][buying_price]" class="form-control form-control-sm buyingPrice" placeholder="0">
      </div>
      <div class="newProductFields">
        <div class="col-6 col-sm-3 mt-2">
          <label class="form-label small mb-1">Selling price</label>
          <input type="number" step="0.01" min="0" name="items[__I__][selling_price]" class="form-control form-control-sm" placeholder="0">
        </div>
        <div class="col-6 col-sm-3 mt-2">
          <label class="form-label small mb-1">Wholesale <span class="text-muted">(optional)</span></label>
          <input type="number" step="0.01" min="0" name="items[__I__][wholesale_price]" class="form-control form-control-sm" placeholder="0">
        </div>
        <div class="col-12 mt-2">
          <label class="form-label small mb-1">Photo <span class="text-muted">(optional)</span></label>
          <input type="file" name="items[__I__][image]" accept="image/*" class="form-control form-control-sm">
        </div>
      </div>
    </div>
  </div>
</template>

<style>
  .stock-row .newProductFields { display: contents; }
  .stock-row.is-restock .newProductFields { display: none; }
</style>
<script>
(function () {
  var tplHtml = document.getElementById('rowTpl').innerHTML;
  var rowsWrap = document.getElementById('rows');
  var idx = 0;

  function addRow() {
    var html = tplHtml.replace(/__I__/g, idx).replace(/__N__/g, idx + 1);
    var wrap = document.createElement('div');
    wrap.innerHTML = html;
    var row = wrap.firstElementChild;
    rowsWrap.appendChild(row);
    wireRow(row);
    idx++;
  }

  function wireRow(row) {
    var sel = row.querySelector('.productChoice');
    var buying = row.querySelector('.buyingPrice');
    row.querySelector('.removeRow').addEventListener('click', function () { row.remove(); });
    sel.addEventListener('change', function () {
      var isRestock = sel.value !== 'new';
      row.classList.toggle('is-restock', isRestock);
      if (isRestock) {
        var opt = sel.options[sel.selectedIndex];
        buying.value = opt.dataset.buying || '';
      }
    });
  }

  document.getElementById('addRowBtn').addEventListener('click', addRow);
  addRow(); // start with one row

  var supplierSel = document.getElementById('supplierSel');
  var newSupplierName = document.getElementById('newSupplierName');
  function syncSupplier() { newSupplierName.style.display = supplierSel.value === 'new' ? 'block' : 'none'; }
  supplierSel.addEventListener('change', syncSupplier);
  syncSupplier();

  document.getElementById('stockForm').addEventListener('submit', function (e) {
    if (!rowsWrap.children.length) { e.preventDefault(); alert('Add at least one product.'); }
  });
})();
</script>

<?php
$content = ob_get_clean();
$__layout = TenantContext::role() === 'staff' ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
