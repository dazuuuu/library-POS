<?php
require_once __DIR__ . '/../../../app/app.php';

header('Content-Type: application/json; charset=utf-8');

if (!TenantContext::check() || !TenantContext::can(Capabilities::SALES_RECORD)) {
    http_response_code(403);
    echo json_encode(['items' => []]);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$rows = (new Models\ProductModel(Database::pdo()))->searchSellable($q, 12);

echo json_encode(['items' => array_map(fn($r) => [
    'id' => (int) $r['id'],
    'name' => $r['name'],
    'category_name' => $r['category_name'] ?? '',
    'stock' => (float) $r['quantity'],
    'retail_price' => (float) $r['retail_price'],
    'wholesale_price' => (float) $r['wholesale_price'],
    'barcode' => $r['barcode'] ?? '',
], $rows)]);
