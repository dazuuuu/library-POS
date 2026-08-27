<?php
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

$pdo = Database::pdo();
$F = new Models\FinanceModel($pdo);
$tenant = (new Models\TenantModel($pdo))->find(TenantContext::tenantId());
$currency = $tenant['currency'] ?? 'KES';
$money = fn($v) => $currency . ' ' . number_format((float) $v, 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'expense') {
    $res = $F->recordExpense($_POST);
    $_SESSION['flash'][$res['ok'] ? 'success' : 'error'] = $res['ok'] ? 'Expense recorded.' : ($res['error'] ?? 'Could not record expense.');
    header('Location: ' . public_url('super/finances/'));
    exit;
}

$summary = $F->summary();
$supplierBalances = $F->supplierBalances();
$expenses = $F->expenses();

$page_title = 'Finances';
ob_start();
?>
<div class="row g-3 mb-4">
  <?php foreach ([
      ['Liquid money', $summary['liquid'], 'fa-wallet', '#eff6ff', '#2563eb'],
      ['Inventory asset', $summary['inventory_asset'], 'fa-boxes-stacked', '#f0fdf4', '#16a34a'],
      ['Credit sales owed', $summary['credit_receivable'], 'fa-file-invoice-dollar', '#fffbeb', '#d97706'],
      ['Supplier debt', $summary['supplier_debt'], 'fa-truck-field', '#fef2f2', '#dc2626'],
  ] as $b): ?>
  <div class="col-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
      <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <span class="text-muted small text-uppercase fw-semibold"><?php echo htmlspecialchars($b[0]); ?></span>
          <span class="dash-ic" style="background:<?php echo $b[3]; ?>;color:<?php echo $b[4]; ?>;"><i class="fas <?php echo $b[2]; ?>"></i></span>
        </div>
        <div class="h4 mb-0 fw-bold"><?php echo $money($b[1]); ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3">Balance sheet</h2>
        <div class="d-flex justify-content-between py-2 border-bottom"><span>Assets</span><strong><?php echo $money($summary['assets']); ?></strong></div>
        <div class="d-flex justify-content-between py-2 border-bottom"><span>Liabilities</span><strong><?php echo $money($summary['liabilities']); ?></strong></div>
        <div class="d-flex justify-content-between py-2"><span>Owner equity</span><strong class="<?php echo $summary['equity'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($summary['equity']); ?></strong></div>
      </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3">Record expense</h2>
        <form method="post" class="row g-2">
          <input type="hidden" name="action" value="expense">
          <div class="col-12"><input name="title" class="form-control" placeholder="Expense title" required></div>
          <div class="col-6"><input name="category" class="form-control" placeholder="Category" value="General"></div>
          <div class="col-6"><input type="number" step="0.01" min="0" name="amount" class="form-control" placeholder="Amount" required></div>
          <div class="col-6"><input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
          <div class="col-6">
            <select name="payment_method" class="form-select">
              <option value="cash">Cash</option>
              <option value="mpesa">M-Pesa</option>
              <option value="bank">Bank</option>
            </select>
          </div>
          <div class="col-12"><input name="notes" class="form-control" placeholder="Notes"></div>
          <div class="col-12"><button class="btn btn-primary w-100">Save expense</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3">Supplier credit</h2>
        <?php if (!$supplierBalances): ?>
          <div class="text-muted small">No supplier balances owed.</div>
        <?php else: ?>
          <?php foreach ($supplierBalances as $s): ?>
            <div class="d-flex justify-content-between border-bottom py-2">
              <div><div class="fw-semibold small"><?php echo htmlspecialchars($s['supplier_name']); ?></div><div class="text-muted small">Paid <?php echo $money($s['paid']); ?> of <?php echo $money($s['purchases']); ?></div></div>
              <strong class="text-danger"><?php echo $money($s['owed']); ?></strong>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:14px;">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3">Recent expenses</h2>
        <?php if (!$expenses): ?>
          <div class="text-muted small">No expenses recorded yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr class="text-muted small text-uppercase"><th>Date</th><th>Expense</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
              <tbody>
                <?php foreach ($expenses as $e): ?>
                <tr><td class="small"><?php echo date('j M', strtotime($e['expense_date'])); ?></td><td class="small"><?php echo htmlspecialchars($e['title']); ?></td><td class="small"><?php echo htmlspecialchars(ucfirst($e['payment_method'])); ?></td><td class="text-end fw-semibold"><?php echo $money($e['amount']); ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<style>.dash-ic{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.9rem;}</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
