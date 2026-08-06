<?php
// public/super/inventory/index.php — supplier-grouped inventory overview.
// This is the one place to browse, edit, activate/draft or delete products.
// New stock (new products or restocks) is only added via Record stock.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::INVENTORY_VIEW);

$pdo = Database::pdo();
$P = new Models\ProductModel($pdo);
$canEdit = TenantContext::can(Capabilities::INVENTORY_EDIT);

if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'toggle') {
        $row = $P->find($id);
        if ($row) { $P->setStatus($id, $row['status'] === 'active' ? 'draft' : 'active'); }
        $_SESSION['flash']['success'] = 'Product status updated.';
    } elseif ($action === 'delete') {
        $P->deleteSafe($id);
        $_SESSION['flash']['success'] = 'Product deleted.';
    }
    header('Location: ' . public_url('super/inventory/'));
    exit;
}

$grouped = $P->listGroupedBySupplier();
$editBase = public_url('super/products/');
$stockUrl = public_url('super/stock/new.php');

$totals = ['products' => 0, 'stock_value' => 0.0, 'retail_value' => 0.0];
foreach ($grouped as $items) {
    foreach ($items as $p) {
        $totals['products']++;
        $qty = (float)$p['quantity'];
        $totals['stock_value'] += Models\ProductModel::stockValue((float)$p['buying_price'], $qty);
        $totals['retail_value'] += $qty * (float)($p['retail_price'] ?? $p['selling_price']);
    }
}

$page_title = 'Inventory';
ob_start();
?>
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h1 class="h5 fw-bold mb-1">Inventory by supplier</h1>
    <p class="text-muted small mb-0">See what's in stock, who supplied it, and edit or retire products. New stock is added on the Record stock page.</p>
  </div>
  <?php if ($canEdit): ?>
    <a href="<?php echo $stockUrl; ?>" class="btn btn-primary btn-sm"><i class="fas fa-truck-loading me-1"></i>Record stock</a>
  <?php endif; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold">Products</div>
        <div class="h4 mb-0 fw-bold"><?php echo $totals['products']; ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold">Stock value (cost)</div>
        <div class="h5 mb-0 fw-bold">KES <?php echo number_format($totals['stock_value'], 0); ?></div>
        <div class="text-muted small">Buying price &times; qty</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold">Retail value</div>
        <div class="h5 mb-0 fw-bold text-primary">KES <?php echo number_format($totals['retail_value'], 0); ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold">Suppliers</div>
        <div class="h4 mb-0 fw-bold"><?php echo count($grouped); ?></div>
      </div>
    </div>
  </div>
</div>

<?php if (!$grouped): ?>
  <div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-body p-5 text-center text-muted">
      <i class="fas fa-warehouse fa-2x mb-3 d-block" style="opacity:.25;"></i>
      No products yet. <?php echo $canEdit ? '<a href="' . $stockUrl . '">Record your first stock delivery</a>.' : 'Ask the owner to record stock.'; ?>
    </div>
  </div>
<?php else: ?>
  <?php foreach ($grouped as $supplierName => $items): ?>
  <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;overflow:hidden;">
    <div class="px-4 py-3 d-flex align-items-center justify-content-between" style="background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-bottom:1px solid #e2e8f0;">
      <div>
        <h2 class="h6 fw-bold mb-0"><i class="fas fa-truck-field me-2 text-primary"></i><?php echo htmlspecialchars($supplierName); ?></h2>
        <span class="text-muted small"><?php echo count($items); ?> product<?php echo count($items) !== 1 ? 's' : ''; ?></span>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead>
          <tr class="text-muted small text-uppercase">
            <th style="width:48px;"></th>
            <th>Product</th>
            <th class="text-end">Stock</th>
            <th class="text-end">Buying</th>
            <th class="text-end">Stock value</th>
            <th class="text-end">Wholesale</th>
            <th class="text-end">Retail</th>
            <th>Status</th>
            <?php if ($canEdit): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $p):
              $buy = (float)$p['buying_price'];
              $qty = (float)$p['quantity'];
              $wholesale = (float)($p['wholesale_price'] ?? $p['selling_price']);
              $retail = (float)($p['retail_price'] ?? $p['selling_price']);
              $stockVal = Models\ProductModel::stockValue($buy, $qty);
              $low = $qty <= (int)$p['low_stock_threshold'];
              $sz = Models\ProductModel::sizeLabel($p);
          ?>
          <tr>
            <td>
              <?php if (!empty($p['image_path'])): ?>
                <img src="<?php echo htmlspecialchars($p['image_path']); ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;">
              <?php else: ?>
                <span class="d-inline-flex align-items-center justify-content-center text-muted" style="width:40px;height:40px;border-radius:8px;background:#f1f5f9;"><i class="fas fa-box"></i></span>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold"><?php echo htmlspecialchars($p['name']); ?><?php echo $sz ? ' <span class="text-muted fw-normal">— ' . htmlspecialchars($sz) . '</span>' : ''; ?></div>
              <?php if ($p['category_name'] || $p['subcategory_name']): ?><div class="text-muted small"><?php echo htmlspecialchars($p['category_name'] ?: ''); ?><?php echo $p['subcategory_name'] ? ' · ' . htmlspecialchars($p['subcategory_name']) : ''; ?></div><?php endif; ?>
            </td>
            <td class="text-end <?php echo $low ? 'text-danger fw-semibold' : ''; ?>">
              <?php echo rtrim(rtrim(number_format($qty, 2), '0'), '.'); ?>
              <span class="text-muted small"><?php echo htmlspecialchars($p['unit']); ?></span>
            </td>
            <td class="text-end text-muted">KES <?php echo number_format($buy, 0); ?></td>
            <td class="text-end fw-semibold">KES <?php echo number_format($stockVal, 0); ?></td>
            <td class="text-end">KES <?php echo number_format($wholesale, 0); ?></td>
            <td class="text-end">KES <?php echo number_format($retail, 0); ?></td>
            <td><?php echo $p['status'] === 'active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Draft</span>'; ?></td>
            <?php if ($canEdit): ?>
            <td class="text-end" style="white-space:nowrap;">
              <a class="btn btn-sm btn-outline-primary" href="<?php echo $editBase; ?>?edit=<?php echo (int)$p['id']; ?>">Edit</a>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                <button class="btn btn-sm btn-outline-secondary"><?php echo $p['status'] === 'active' ? 'Draft' : 'Activate'; ?></button>
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this product?');">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                <button class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();
$__layout = TenantContext::role() === 'staff' ? 'staff' : 'tenants';
include __DIR__ . '/../../templates/' . $__layout . '/layout.php';
