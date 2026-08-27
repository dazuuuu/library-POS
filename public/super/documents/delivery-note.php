<?php
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::SALES_RECORD);

$pdo = Database::pdo();
$O = new Models\OrderModel($pdo);
$id = (int) ($_GET['id'] ?? 0);
$order = $id > 0 ? $O->find($id) : null;
if (!$order) {
    http_response_code(404);
    echo 'Delivery note not found.';
    exit;
}
$items = $O->items($id);
$tenant = (new Models\TenantModel($pdo))->find(TenantContext::tenantId());
$shop = $tenant['name'] ?? 'My Shop';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Delivery Note <?php echo htmlspecialchars($order['receipt_number']); ?></title>
<style>
  body{margin:0;background:#f1f5f9;font-family:Arial,sans-serif;color:#111827;}
  .note{width:80mm;max-width:100%;margin:18px auto;background:#fff;padding:12px;box-shadow:0 1px 8px rgba(0,0,0,.12);}
  .center{text-align:center}.line{border-top:1px dashed #9ca3af;margin:8px 0}.small{font-size:11px}.row{display:flex;justify-content:space-between;gap:8px}.bold{font-weight:700}
  table{width:100%;border-collapse:collapse;font-size:12px}td,th{padding:3px 0;text-align:left}td:last-child,th:last-child{text-align:right}
  .actions{width:80mm;max-width:100%;margin:0 auto 18px;display:flex;gap:8px}.actions a,.actions button{flex:1;padding:9px;border:0;border-radius:6px;background:#16a34a;color:#fff;text-decoration:none;text-align:center}
  @media print{body{background:#fff}.note{box-shadow:none;margin:0;width:72mm}.actions{display:none}}
</style>
</head>
<body>
<div class="note">
  <div class="center">
    <div class="bold"><?php echo htmlspecialchars($shop); ?></div>
    <?php if (!empty($tenant['phone'])): ?><div class="small"><?php echo htmlspecialchars($tenant['phone']); ?></div><?php endif; ?>
    <div class="small">DELIVERY NOTE</div>
    <div class="small"><?php echo htmlspecialchars($order['receipt_number']); ?> · <?php echo date('j M Y, g:i a', strtotime($order['created_at'])); ?></div>
  </div>
  <div class="line"></div>
  <div class="small">Customer: <span class="bold"><?php echo htmlspecialchars($order['table_name']); ?></span></div>
  <?php if (!empty($order['customer_company'])): ?><div class="small">Company: <?php echo htmlspecialchars($order['customer_company']); ?></div><?php endif; ?>
  <?php if (!empty($order['customer_location'])): ?><div class="small">Location: <?php echo htmlspecialchars($order['customer_location']); ?></div><?php endif; ?>
  <?php if (!empty($order['customer_phone'])): ?><div class="small">Phone: <?php echo htmlspecialchars($order['customer_phone']); ?></div><?php endif; ?>
  <?php if (!empty($order['delivery_person'])): ?><div class="small">Delivery: <?php echo htmlspecialchars($order['delivery_person']); ?></div><?php endif; ?>
  <div class="line"></div>
  <table>
    <thead><tr><th>Item</th><th>Qty</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr><td><?php echo htmlspecialchars($it['product_name']); ?></td><td><?php echo rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.'); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ((float) ($order['delivery_fee'] ?? 0) > 0): ?>
  <div class="line"></div>
  <div class="row small"><span>Delivery fee</span><span class="bold">KES <?php echo number_format((float) $order['delivery_fee'], 2); ?></span></div>
  <?php endif; ?>
  <div class="line"></div>
  <div class="small">Customer sign: __________________</div>
  <div class="small" style="margin-top:8px;">Delivered by: ________________</div>
</div>
<div class="actions">
  <button onclick="window.print()">Print</button>
  <a href="<?php echo public_url('super/orders/view.php?id=' . $id); ?>">Invoice</a>
</div>
</body>
</html>
