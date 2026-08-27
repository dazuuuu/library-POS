<?php
// cron/auto_clock_out.php
// Schedule this after closing time if you want unattended auto clock-out.
require_once __DIR__ . '/../app/app.php';

$pdo = Database::pdo();
$TL = new Models\TimeLogModel($pdo);
$tenants = $pdo->query('SELECT id FROM tenants')->fetchAll(PDO::FETCH_COLUMN);
$closed = 0;

foreach ($tenants as $tenantId) {
    $closed += $TL->autoCloseOverdueForTenant((int) $tenantId);
}

echo 'Auto clock-out closed ' . $closed . ' record' . ($closed === 1 ? '' : 's') . '.' . PHP_EOL;
