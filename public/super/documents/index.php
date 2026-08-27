<?php
require_once __DIR__ . '/../../../app/app.php';
require_once ROOT_PATH . '/app/services/emails/order_invoice_email.php';
require_once ROOT_PATH . '/app/services/emails/order_delivery_note_email.php';
PageGuard::capability(Capabilities::SALES_RECORD);

$pdo = Database::pdo();
$CM = new Models\CustomerModel($pdo);
$P = new Models\ProductModel($pdo);
$O = new Models\OrderModel($pdo);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer = null;
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    if ($customerId > 0) {
        $customer = $CM->find($customerId);
    }
    if (!$customer) {
        $customer = $CM->findOrCreateByContact(
            trim($_POST['customer_name'] ?? ''),
            trim($_POST['customer_email'] ?? ''),
            trim($_POST['customer_phone'] ?? ''),
            trim($_POST['customer_company'] ?? ''),
            trim($_POST['customer_location'] ?? '')
        );
    }

    $cart = json_decode($_POST['cart'] ?? '[]', true);
    $items = [];
    if (is_array($cart)) {
        foreach ($cart as $row) {
            $items[] = ['product_id' => (int) ($row['product_id'] ?? 0), 'quantity' => (float) ($row['quantity'] ?? 0)];
        }
    }

    $name = $customer['name'] ?? trim($_POST['customer_name'] ?? '');
    $email = $customer['email'] ?? trim($_POST['customer_email'] ?? '');
    $phone = $customer['phone'] ?? trim($_POST['customer_phone'] ?? '');
    $company = $customer['company_name'] ?? trim($_POST['customer_company'] ?? '');
    $location = $customer['location'] ?? trim($_POST['customer_location'] ?? '');

    $res = $O->open([
        'table_name' => $name,
        'opened_by' => TenantContext::userId(),
        'items' => $items,
        'channel' => 'tab',
        'sale_type' => in_array($_POST['sale_type'] ?? 'retail', ['retail', 'wholesale'], true) ? $_POST['sale_type'] : 'retail',
        'discount_amount' => $_POST['discount_amount'] ?? 0,
        'customer_id' => $customer['id'] ?? null,
        'customer_email' => $email,
        'customer_phone' => $phone,
        'customer_company' => $company,
        'customer_location' => $location,
        'delivery_person' => trim($_POST['delivery_person'] ?? ''),
        'delivery_fee' => $_POST['delivery_fee'] ?? 0,
    ]);

    if ($res['ok']) {
        $order = $O->find((int) $res['order_id']);
        $orderItems = $O->items((int) $res['order_id']);
        $tenant = (new Models\TenantModel($pdo))->find(TenantContext::tenantId());
        $shop = ['name' => $tenant['name'] ?? 'the shop', 'phone' => $tenant['phone'] ?? '', 'address' => $tenant['address'] ?? ''];
        if (!empty($_POST['send_invoice']) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = build_order_invoice_email($order, $orderItems, $shop);
            if ((new MailService())->send($email, $msg['subject'], $msg['html'], $msg['text'])) { $O->markInvoiceSent((int) $res['order_id']); }
        }
        if (!empty($_POST['send_delivery_note']) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = build_order_delivery_note_email($order, $orderItems, $shop);
            if ((new MailService())->send($email, $msg['subject'], $msg['html'], $msg['text'])) { $O->markDeliveryNoteSent((int) $res['order_id']); }
        }
        if (!empty($_POST['open_delivery_note'])) {
            $O->markDeliveryNoteSent((int) $res['order_id']);
            header('Location: ' . public_url('super/documents/delivery-note.php?id=' . (int) $res['order_id']));
        } else {
            header('Location: ' . public_url('super/orders/view.php?id=' . (int) $res['order_id']));
        }
        exit;
    }
    $error = $res['errors']['_'] ?? ($res['errors']['table_name'] ?? 'Could not create invoice.');
}

$page_title = 'Documents';
$customerSearchUrl = public_url('api/customers/search.php');
$productSearchUrl = public_url('api/inventory/search_sellable.php');
ob_start();
?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<form method="post" id="docForm">
  <input type="hidden" name="cart" id="cartInput">
  <div class="row g-4">
    <div class="col-12 col-lg-4">
      <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
          <h2 class="h6 fw-bold mb-3">Customer</h2>
          <input type="hidden" name="customer_id" id="customerId" value="">
          <label class="form-label small">Search saved customer</label>
          <div class="doc-search mb-3">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" id="customerSearch" class="form-control" placeholder="Type customer, company, email or phone" autocomplete="off">
            <div class="doc-results" id="customerResults"></div>
          </div>
          <div class="row g-2">
            <div class="col-12"><input name="customer_name" id="customerName" class="form-control" placeholder="Customer name" required></div>
            <div class="col-12"><input name="customer_company" id="customerCompany" class="form-control" placeholder="Company"></div>
            <div class="col-12"><input type="email" name="customer_email" id="customerEmail" class="form-control" placeholder="Email"></div>
            <div class="col-12"><input name="customer_phone" id="customerPhone" class="form-control" placeholder="Phone"></div>
            <div class="col-12"><input name="customer_location" id="customerLocation" class="form-control" placeholder="Location"></div>
          </div>
          <hr>
          <h2 class="h6 fw-bold mb-3">Delivery</h2>
          <div class="row g-2">
            <div class="col-12"><input name="delivery_person" class="form-control" placeholder="Delivery person"></div>
            <div class="col-12"><input type="number" step="0.01" min="0" name="delivery_fee" id="deliveryFee" class="form-control" placeholder="Delivery fee"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-8">
      <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h2 class="h6 fw-bold mb-0">Invoice items</h2>
            <div class="btn-group btn-group-sm">
              <input type="radio" class="btn-check" name="sale_type" id="retailMode" value="retail" checked>
              <label class="btn btn-outline-primary" for="retailMode">Retail</label>
              <input type="radio" class="btn-check" name="sale_type" id="wholesaleMode" value="wholesale">
              <label class="btn btn-outline-primary" for="wholesaleMode">Wholesale</label>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-8">
              <div class="doc-search">
                <i class="fas fa-magnifying-glass"></i>
                <input type="text" id="productSearch" class="form-control" placeholder="Type product name, barcode or category" autocomplete="off">
                <div class="doc-results" id="productResults"></div>
              </div>
              <input type="hidden" id="productId">
            </div>
            <div class="col-2"><input type="number" step="0.01" min="0" id="qtyPick" class="form-control" placeholder="Qty"></div>
            <div class="col-2"><button type="button" id="addItem" class="btn btn-primary w-100">Add</button></div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr class="text-muted small text-uppercase"><th>Product</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th><th></th></tr></thead>
              <tbody id="itemRows"><tr><td colspan="5" class="text-muted small text-center py-4">No products selected.</td></tr></tbody>
            </table>
          </div>
          <div class="row g-2 align-items-end">
            <div class="col-6 col-md-4"><label class="form-label small">Discount</label><input type="number" step="0.01" min="0" name="discount_amount" id="discountAmount" class="form-control" value="0"></div>
            <div class="col-6 col-md-8 text-end"><div class="text-muted small">Total</div><div class="h4 fw-bold" id="totalOut">KES 0</div></div>
          </div>
          <hr>
          <div class="d-flex flex-wrap gap-3 mb-3">
            <label class="form-check"><input class="form-check-input" type="checkbox" name="send_invoice" value="1"> <span class="form-check-label">Email invoice</span></label>
            <label class="form-check"><input class="form-check-input" type="checkbox" name="send_delivery_note" value="1"> <span class="form-check-label">Email delivery note</span></label>
            <label class="form-check"><input class="form-check-input" type="checkbox" name="open_delivery_note" value="1"> <span class="form-check-label">Open printable delivery note</span></label>
          </div>
          <button class="btn btn-primary btn-lg w-100">Create invoice / credit sale</button>
        </div>
      </div>
    </div>
  </div>
</form>

<style>
.doc-search{position:relative;}
.doc-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;z-index:2;}
.doc-search input{padding-left:38px;}
.doc-results{display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:50;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 12px 28px rgba(15,23,42,.12);max-height:280px;overflow:auto;}
.doc-results.show{display:block;}
.doc-result{width:100%;border:0;background:#fff;text-align:left;padding:10px 12px;border-bottom:1px solid #f1f5f9;}
.doc-result:hover{background:#f8fafc;}
.doc-result .name{font-weight:700;font-size:.9rem;}
.doc-result .meta{color:#64748b;font-size:.78rem;}
</style>
<script>
(function(){
  var cart = {};
  var selectedProduct = null;
  var CUSTOMER_API = <?php echo json_encode($customerSearchUrl); ?>;
  var PRODUCT_API = <?php echo json_encode($productSearchUrl); ?>;
  function money(n){ return 'KES ' + (Math.round(n * 100) / 100).toLocaleString(); }
  function mode(){ return document.querySelector('input[name=sale_type]:checked').value; }
  function price(item){ return mode() === 'wholesale' ? (parseFloat(item.wholesale_price) || 0) : (parseFloat(item.retail_price) || 0); }
  function esc(s){ return String(s || '').replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
  function render(){
    var body = document.getElementById('itemRows'), rows = '', subtotal = 0;
    Object.keys(cart).forEach(function(id){
      var it = cart[id], line = it.qty * it.price; subtotal += line;
      rows += '<tr><td class="small fw-semibold">' + it.name + '</td><td class="text-end small">' + it.qty + '</td><td class="text-end small">' + money(it.price) + '</td><td class="text-end fw-semibold">' + money(line) + '</td><td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove="' + id + '">Remove</button></td></tr>';
    });
    body.innerHTML = rows || '<tr><td colspan="5" class="text-muted small text-center py-4">No products selected.</td></tr>';
    var discount = parseFloat(document.getElementById('discountAmount').value) || 0;
    var delivery = parseFloat(document.getElementById('deliveryFee').value) || 0;
    document.getElementById('totalOut').textContent = money(Math.max(0, subtotal - discount) + delivery);
    document.getElementById('cartInput').value = JSON.stringify(Object.keys(cart).map(function(id){ return {product_id:id, quantity:cart[id].qty}; }));
  }
  document.getElementById('addItem').addEventListener('click', function(){
    var qty = parseFloat(document.getElementById('qtyPick').value) || 0;
    if (!selectedProduct || qty <= 0) return;
    var id = String(selectedProduct.id);
    cart[id] = {name: selectedProduct.name, qty: (cart[id] ? cart[id].qty : 0) + qty, price: price(selectedProduct)};
    document.getElementById('qtyPick').value = '';
    document.getElementById('productSearch').value = '';
    document.getElementById('productId').value = '';
    selectedProduct = null;
    render();
  });
  document.getElementById('itemRows').addEventListener('click', function(e){ var b = e.target.closest('[data-remove]'); if (b) { delete cart[b.dataset.remove]; render(); } });
  document.querySelectorAll('input[name=sale_type]').forEach(function(r){ r.addEventListener('change', function(){ cart = {}; selectedProduct = null; document.getElementById('productSearch').value = ''; render(); }); });
  ['discountAmount','deliveryFee'].forEach(function(id){ document.getElementById(id).addEventListener('input', render); });

  function wireSearch(inputId, menuId, url, renderItem, pickItem) {
    var input = document.getElementById(inputId), menu = document.getElementById(menuId), timer = null;
    input.addEventListener('input', function(){
      clearTimeout(timer);
      var q = input.value.trim();
      if (!q) { menu.classList.remove('show'); menu.innerHTML = ''; return; }
      timer = setTimeout(function(){
        fetch(url + '?q=' + encodeURIComponent(q))
          .then(function(r){ return r.json(); })
          .then(function(data){
            var items = data.items || [];
            menu.innerHTML = items.length ? items.map(renderItem).join('') : '<div class="doc-result"><div class="meta">No matches found</div></div>';
            menu.classList.add('show');
            menu.querySelectorAll('[data-pick]').forEach(function(btn){
              btn.addEventListener('mousedown', function(e){
                e.preventDefault();
                var item = items[parseInt(btn.dataset.pick, 10)];
                pickItem(item);
                menu.classList.remove('show');
              });
            });
          })
          .catch(function(){});
      }, 160);
    });
    input.addEventListener('blur', function(){ setTimeout(function(){ menu.classList.remove('show'); }, 160); });
  }

  wireSearch('customerSearch', 'customerResults', CUSTOMER_API, function(c, i){
    var meta = [c.company_name, c.email, c.phone, c.location].filter(Boolean).join(' · ');
    return '<button type="button" class="doc-result" data-pick="' + i + '"><div class="name">' + esc(c.name) + '</div><div class="meta">' + esc(meta || 'Saved customer') + '</div></button>';
  }, function(c){
    document.getElementById('customerId').value = c.id || '';
    document.getElementById('customerSearch').value = c.name || '';
    document.getElementById('customerName').value = c.name || '';
    document.getElementById('customerCompany').value = c.company_name || '';
    document.getElementById('customerEmail').value = c.email || '';
    document.getElementById('customerPhone').value = c.phone || '';
    document.getElementById('customerLocation').value = c.location || '';
  });

  wireSearch('productSearch', 'productResults', PRODUCT_API, function(p, i){
    var meta = [p.category_name, 'Stock ' + p.stock, money(mode() === 'wholesale' ? p.wholesale_price : p.retail_price)].filter(Boolean).join(' · ');
    return '<button type="button" class="doc-result" data-pick="' + i + '"><div class="name">' + esc(p.name) + '</div><div class="meta">' + esc(meta) + '</div></button>';
  }, function(p){
    selectedProduct = p;
    document.getElementById('productId').value = p.id || '';
    document.getElementById('productSearch').value = p.name || '';
    document.getElementById('qtyPick').focus();
  });

  ['customerName','customerEmail','customerPhone','customerCompany','customerLocation'].forEach(function(id){
    document.getElementById(id).addEventListener('input', function(){ document.getElementById('customerId').value = ''; });
  });
  document.getElementById('docForm').addEventListener('submit', function(e){ if (!Object.keys(cart).length) { e.preventDefault(); alert('Add at least one product.'); } });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
