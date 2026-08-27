<?php
// public/super/inventory/index.php — the stock ledger overview: browse,
// edit, activate/draft or delete products, grouped by category or supplier.
// New stock is only added via Record stock.
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
    } elseif ($action === 'archive') {
        $P->setStatus($id, 'archived');
        $_SESSION['flash']['success'] = 'Product archived — hidden from normal browsing, still sellable from the Archive tab at the till.';
    } elseif ($action === 'unarchive') {
        $P->setStatus($id, 'active');
        $_SESSION['flash']['success'] = 'Product unarchived — back in normal browsing.';
    } elseif ($action === 'delete') {
        $P->deleteSafe($id);
        $_SESSION['flash']['success'] = 'Product deleted.';
    }
    header('Location: ' . public_url('super/inventory/') . '?group=' . urlencode($_GET['group'] ?? 'category'));
    exit;
}

$groupBy = in_array($_GET['group'] ?? '', ['category', 'supplier', 'archived'], true) ? $_GET['group'] : 'category';
$groupLabels = ['category' => 'category', 'supplier' => 'supplier', 'archived' => 'archived items'];
$grouped = match ($groupBy) {
    'supplier'   => $P->listGroupedBySupplier(),
    'archived'   => ($archived = $P->listArchived()) ? ['Archived' => $archived] : [],
    default      => $P->listGroupedByCategory(),
};
$editBase = public_url('super/products/');
$stockUrl = public_url('super/stock/new.php');
$groupUrl = fn(string $g) => public_url('super/inventory/') . '?group=' . $g;

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
    <h1 class="h5 fw-bold mb-1">Inventory by <?php echo $groupLabels[$groupBy]; ?></h1>
    <p class="text-muted small mb-0">See what's on the shelf, balances, cost, wholesale, and retail prices. New stock is added on the Record stock page.</p>
  </div>
  <?php if ($canEdit): ?>
    <a href="<?php echo $stockUrl; ?>" class="btn btn-primary btn-sm"><i class="fas fa-truck-loading me-1"></i>Record stock</a>
  <?php endif; ?>
</div>

<div class="btn-group btn-group-sm mb-4" role="group">
  <?php foreach (['category' => 'By category', 'supplier' => 'By supplier', 'archived' => 'Archived'] as $g => $lbl): ?>
    <a href="<?php echo $groupUrl($g); ?>" class="btn btn-outline-secondary <?php echo $groupBy === $g ? 'active' : ''; ?>"><?php echo $lbl; ?></a>
  <?php endforeach; ?>
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
        <div class="text-muted small">Unit price &times; balance</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold">Selling value</div>
        <div class="h5 mb-0 fw-bold text-primary">KES <?php echo number_format($totals['retail_value'], 0); ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
      <div class="card-body p-3">
        <div class="text-muted small text-uppercase fw-semibold">Groups</div>
        <div class="h4 mb-0 fw-bold"><?php echo count($grouped); ?></div>
      </div>
    </div>
  </div>
</div>

<?php if (!$grouped): ?>
  <div class="card border-0 shadow-sm" style="border-radius:14px;">
    <div class="card-body p-5 text-center text-muted">
      <i class="fas fa-box fa-2x mb-3 d-block" style="opacity:.25;"></i>
      <?php if ($groupBy === 'archived'): ?>
        No archived items. Old products you archive from a row show up here.
      <?php else: ?>
        No products yet. <?php echo $canEdit ? '<a href="' . $stockUrl . '">Record your first stock delivery</a>.' : 'Ask the owner to record stock.'; ?>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <?php foreach ($grouped as $groupName => $items): ?>
  <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;overflow:hidden;">
    <div class="px-4 py-3 d-flex align-items-center justify-content-between" style="background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-bottom:1px solid #e2e8f0;">
      <?php $groupIcon = ['category' => 'tags', 'supplier' => 'truck-field', 'archived' => 'box-archive'][$groupBy] ?? 'tags'; ?>
      <div>
        <h2 class="h6 fw-bold mb-0"><i class="fas fa-<?php echo $groupIcon; ?> me-2 text-primary"></i><?php echo htmlspecialchars($groupName); ?></h2>
        <span class="text-muted small"><?php echo count($items); ?> item<?php echo count($items) !== 1 ? 's' : ''; ?></span>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead>
          <tr class="text-muted small text-uppercase">
            <th style="width:48px;"></th>
            <th>Item</th>
            <th>Category</th>
            <th>Supplier</th>
            <th class="text-end">Balance</th>
            <th class="text-end">Cost</th>
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
              $subline = array_filter([$p['barcode'] ?? null]);
              $eff = Models\ProductModel::effectivePrice($p);
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
              <div class="fw-semibold"><?php echo htmlspecialchars($p['name']); ?></div>
              <?php if ($subline): ?><div class="text-muted small"><?php echo htmlspecialchars(implode(' · ', $subline)); ?></div><?php endif; ?>
            </td>
            <td class="text-muted small"><?php echo htmlspecialchars($p['category_name'] ?: '—'); ?></td>
            <td class="text-muted small"><?php echo htmlspecialchars($p['supplier_name'] ?: '—'); ?></td>
            <td class="text-end <?php echo $low ? 'text-danger fw-semibold' : ''; ?>">
              <?php echo rtrim(rtrim(number_format($qty, 2), '0'), '.'); ?>
            </td>
            <td class="text-end text-muted">KES <?php echo number_format($buy, 0); ?></td>
            <td class="text-end fw-semibold">KES <?php echo number_format($stockVal, 0); ?></td>
            <td class="text-end">KES <?php echo number_format($wholesale, 0); ?></td>
            <td class="text-end">
              <?php if ($eff['on_offer']): ?>
                <span class="text-decoration-line-through text-muted small">KES <?php echo number_format($retail, 0); ?></span>
                <span class="fw-semibold" style="color:#b45309;">KES <?php echo number_format($eff['price'], 0); ?></span>
              <?php else: ?>
                KES <?php echo number_format($retail, 0); ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($p['status'] === 'active'): ?><span class="badge bg-success">Active</span>
              <?php elseif ($p['status'] === 'archived'): ?><span class="badge bg-dark">Archived</span>
              <?php else: ?><span class="badge bg-secondary">Draft</span>
              <?php endif; ?>
              <?php if ($eff['on_offer']): ?>
                <span class="badge bg-warning text-dark ms-1"><i class="fas fa-tag me-1"></i>Offer</span>
              <?php endif; ?>
            </td>
            <?php if ($canEdit): ?>
            <td class="text-end" style="white-space:nowrap;">
              <a class="btn btn-sm btn-outline-primary" href="<?php echo $editBase; ?>?edit=<?php echo (int)$p['id']; ?>">Edit</a>
              <?php if ($p['status'] === 'archived'): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="unarchive"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                  <button class="btn btn-sm btn-outline-success">Unarchive</button>
                </form>
              <?php else: ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                  <button class="btn btn-sm btn-outline-secondary"><?php echo $p['status'] === 'active' ? 'Draft' : 'Activate'; ?></button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm('Archive this product? It will drop out of normal browsing but staff can still sell it from the Archive tab.');">
                  <input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                  <button class="btn btn-sm btn-outline-dark">Archive</button>
                </form>
              <?php endif; ?>
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
