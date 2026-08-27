<?php
// public/super/reports/download.php?date=YYYY-MM-DD — streams the daily report PDF
require_once __DIR__ . '/../../../app/app.php';
PageGuard::auth();

$date = preg_replace('/[^0-9-]/', '', $_GET['date'] ?? '') ?: date('Y-m-d');
$type = $_GET['type'] ?? 'general';
$type = in_array($type, ['general', 'sales', 'inventory', 'products', 'attendance', 'finance'], true) ? $type : 'general';
$data = SalesReport::data(Database::pdo(), TenantContext::tenantId(), $date, $type);
$pdf  = SalesReport::pdf($data);

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $type . '-report-' . $date . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
