<?php
// public/staff/orders/view.php?id=N — tab detail: add rounds, void.
// Settling payment happens on the Payments page (staff/payments/), keyed by
// invoice number — kept separate so servers and reception each have one job.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::SALES_RECORD);

$pdo = Database::pdo();
$O = new Models\OrderModel($pdo);

$id = (int) ($_GET['id'] ?? 0);
$order = $id > 0 ? $O->find($id) : null;
if (!$order) {
    http_response_code(404);
    echo 'Tab not found.';
    exit;
}

$P = new Models\ProductModel($pdo);
$products = $order['status'] === 'open' ? $P->sellable() : [];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_items') {
        $cart = json_decode($_POST['cart'] ?? '[]', true);
        $items = [];
        if (is_array($cart)) {
            foreach ($cart as $c) {
                $items[] = ['product_id' => (int) ($c['product_id'] ?? 0), 'quantity' => (float) ($c['quantity'] ?? 0)];
            }
        }
        $res = $O->addItems($id, $items, TenantContext::userId());
        if ($res['ok']) {
            $_SESSION['flash']['success'] = 'Added to the tab.';
            header('Location: ' . public_url('staff/orders/view.php?id=' . $id));
            exit;
        }
        $error = $res['errors']['_'] ?? 'Could not add those drinks.';

    } elseif ($action === 'void') {
        $res = $O->void($id, TenantContext::userId());
        $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Tab voided; stock restored.' : ($res['error'] ?? 'Could not void this tab.');
        header('Location: ' . public_url('staff/orders/'));
        exit;
    }

    $order = $O->find($id); // re-fetch in case totals changed
}

$items = $O->items($id);
$page_title = 'Tab — ' . $order['table_name'];
$statusBadge = [
    'open' => '<span class="badge bg-warning text-dark">Unpaid</span>',
    'paid' => '<span class="badge bg-success">Paid</span>',
    'void' => '<span class="badge bg-secondary">Voided</span>',
][$order['status']] ?? '';
ob_start();
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h1 class="h5 mb-1 fw-bold"><?php echo htmlspecialchars($order['table_name']); ?> <?php echo $statusBadge; ?></h1>
    <div class="small text-muted">Invoice <?php echo htmlspecialchars($order['receipt_number']); ?> · opened <?php echo date('j M Y, g:i a', strtotime($order['created_at'])); ?></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo public_url('staff/orders/'); ?>"><i class="fas fa-arrow-left me-1"></i>All tabs</a>
    <a class="btn btn-sm btn-outline-primary" href="<?php echo public_url('staff/orders/receipt.php?id=' . $id); ?>"><i class="fas fa-receipt me-1"></i>Receipt</a>
  </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<?php if ($order['status'] === 'open'): ?>
  <div class="alert alert-info py-2 small">
    <i class="fas fa-circle-info me-1"></i>To collect payment, give the customer invoice <strong><?php echo htmlspecialchars($order['receipt_number']); ?></strong> —
    whoever is on Payments looks it up by that number and marks it paid.
  </div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
      <div class="card-body p-4">
        <h2 class="h6 mb-3">Items so far</h2>
        <?php if (!$items): ?>
          <div class="text-muted small">No items yet.</div>
        <?php else: ?>
          <?php foreach ($items as $it): ?>
            <div class="d-flex justify-content-between border-bottom py-2">
              <div>
                <div class="fw-semibold" style="font-size:.9rem;"><?php echo htmlspecialchars($it['product_name']); ?></div>
                <small class="text-muted">KES <?php echo number_format((float) $it['unit_price'], 0); ?> × <?php echo rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.'); ?></small>
              </div>
              <div class="fw-bold">KES <?php echo number_format((float) $it['line_total'], 0); ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <div class="d-flex justify-content-between pt-3">
          <span class="fw-semibold">Total</span>
          <span class="fw-bold fs-5">KES <?php echo number_format((float) $order['total'], 0); ?></span>
        </div>
      </div>
    </div>

    <?php if ($order['status'] === 'open'): ?>
    <form method="post" class="d-inline" onsubmit="return confirm('Void this tab? Stock will be restored.');">
      <input type="hidden" name="action" value="void">
      <button class="btn btn-outline-danger btn-sm"><i class="fas fa-ban me-1"></i>Void tab</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="col-12 col-lg-6">
    <?php if ($order['status'] === 'open'): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
      <div class="card-body p-4">
        <h2 class="h6 mb-3">Add another round</h2>
        <?php if (!$products): ?>
          <div class="text-muted small">No products in stock.</div>
        <?php else: ?>
        <form method="post" id="addForm">
          <input type="hidden" name="action" value="add_items">
          <input type="hidden" name="cart" id="cartInput" value="">
          <div class="position-relative mb-2">
            <input type="text" id="search" class="form-control" placeholder="Search drinks…" autocomplete="off">
          </div>
          <div id="productList" style="max-height:280px;overflow-y:auto;">
            <?php foreach ($products as $p):
                $price = (float) ($p['retail_price'] ?: $p['selling_price']);
                $sz = Models\ProductModel::sizeLabel($p);
            ?>
              <button type="button" class="prod btn w-100 text-start border rounded mb-2 p-2 d-flex justify-content-between align-items-center"
                      data-id="<?php echo (int) $p['id']; ?>"
                      data-name="<?php echo htmlspecialchars($p['name'] . ($sz ? " ({$sz})" : ''), ENT_QUOTES); ?>"
                      data-price="<?php echo $price; ?>" data-stock="<?php echo (float) $p['quantity']; ?>">
                <span><?php echo htmlspecialchars($p['name']); ?><?php echo $sz ? ' <small class="text-muted">(' . htmlspecialchars($sz) . ')</small>' : ''; ?></span>
                <span class="fw-bold text-primary">KES <?php echo number_format($price, 0); ?></span>
              </button>
            <?php endforeach; ?>
          </div>
          <div id="cartRows" class="mt-3"></div>
          <div class="d-flex justify-content-between fw-semibold mt-2"><span>Round total</span><span>KES <span id="roundTotal">0</span></span></div>
          <button type="submit" class="btn btn-primary w-100 mt-3" id="addBtn" disabled>Add to tab</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($order['status'] === 'open' && $products): ?>
<script>
var PRODUCTS = {};
document.querySelectorAll('.prod').forEach(function (b) {
    PRODUCTS[b.dataset.id] = { name: b.dataset.name, price: parseFloat(b.dataset.price), stock: parseFloat(b.dataset.stock) };
});
var cart = {};
function money(n) { return n.toLocaleString('en-KE', {maximumFractionDigits:0}); }
function setQty(id, val) {
    var p = PRODUCTS[id]; val = Math.round(val);
    if (val <= 0) { delete cart[id]; render(); return; }
    if (val > p.stock) { val = p.stock; }
    cart[id] = val; render();
}
function render() {
    var wrap = document.getElementById('cartRows'), ids = Object.keys(cart), total = 0;
    wrap.innerHTML = '';
    ids.forEach(function (id) {
        var p = PRODUCTS[id], qty = cart[id]; total += p.price * qty;
        var row = document.createElement('div');
        row.className = 'd-flex justify-content-between align-items-center border-bottom py-1';
        row.innerHTML = '<span style="font-size:.85rem;">' + p.name + ' × ' + qty + '</span>'
          + '<span><button type="button" class="btn btn-sm btn-outline-secondary" data-dec="' + id + '">−</button> '
          + '<button type="button" class="btn btn-sm btn-outline-secondary" data-inc="' + id + '">+</button></span>';
        wrap.appendChild(row);
    });
    document.getElementById('roundTotal').textContent = money(total);
    document.getElementById('addBtn').disabled = ids.length === 0;
    document.getElementById('cartInput').value = JSON.stringify(ids.map(function (id) { return { product_id: parseInt(id, 10), quantity: cart[id] }; }));
}
document.querySelectorAll('.prod').forEach(function (b) { b.addEventListener('click', function () { setQty(b.dataset.id, (cart[b.dataset.id] || 0) + 1); }); });
document.getElementById('cartRows').addEventListener('click', function (e) {
    var t = e.target.closest('button'); if (!t) return;
    if (t.dataset.inc) setQty(t.dataset.inc, (cart[t.dataset.inc] || 0) + 1);
    else if (t.dataset.dec) setQty(t.dataset.dec, (cart[t.dataset.dec] || 0) - 1);
});
var search = document.getElementById('search');
search.addEventListener('input', function () {
    var q = search.value.toLowerCase().trim();
    document.querySelectorAll('.prod').forEach(function (b) {
        b.style.display = (q === '' || b.dataset.name.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
    });
});
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/staff/layout.php';
