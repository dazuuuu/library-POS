<?php
require_once __DIR__ . '/../../../app/app.php';
PageGuard::capability(Capabilities::CUSTOMERS_MANAGE);

$pdo = Database::pdo();
$C = new Models\CustomerModel($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = $C->save($_POST);
    $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Customer saved.' : ($res['error'] ?? 'Could not save customer.');
    header('Location: ' . public_url('super/customers/'));
    exit;
}

$customers = $C->listForTenant();
$page_title = 'Customers';
ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3">Add customer</h2>
        <form method="post" class="row g-2">
          <div class="col-12"><label class="form-label small">Name</label><input name="name" class="form-control" required></div>
          <div class="col-12"><label class="form-label small">Company</label><input name="company_name" class="form-control"></div>
          <div class="col-12"><label class="form-label small">Email</label><input type="email" name="email" class="form-control"></div>
          <div class="col-12"><label class="form-label small">Phone</label><input name="phone" class="form-control"></div>
          <div class="col-12"><label class="form-label small">Location</label><input name="location" class="form-control"></div>
          <div class="col-12"><label class="form-label small">Notes</label><input name="notes" class="form-control"></div>
          <div class="col-12"><button class="btn btn-primary w-100">Save customer</button></div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h6 fw-bold mb-0">Loyal customers</h2>
          <a class="btn btn-sm btn-outline-primary" href="<?php echo public_url('super/documents/'); ?>">Create invoice</a>
        </div>
        <?php if (!$customers): ?>
          <div class="text-muted small">No customers saved yet.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr class="text-muted small text-uppercase"><th>Name</th><th>Company</th><th>Email</th><th>Phone</th><th>Location</th></tr></thead>
            <tbody>
              <?php foreach ($customers as $c): ?>
              <tr>
                <td class="fw-semibold small"><?php echo htmlspecialchars($c['name']); ?></td>
                <td class="small"><?php echo htmlspecialchars($c['company_name'] ?? ''); ?></td>
                <td class="small"><?php echo htmlspecialchars($c['email'] ?? ''); ?></td>
                <td class="small"><?php echo htmlspecialchars($c['phone'] ?? ''); ?></td>
                <td class="small"><?php echo htmlspecialchars($c['location'] ?? ''); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
