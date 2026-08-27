<?php
require_once __DIR__ . '/../../../app/app.php';

header('Content-Type: application/json; charset=utf-8');

if (!TenantContext::check() || !TenantContext::can(Capabilities::CUSTOMERS_MANAGE)) {
    http_response_code(403);
    echo json_encode(['items' => []]);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$rows = (new Models\CustomerModel(Database::pdo()))->search($q, 10);

echo json_encode(['items' => array_map(fn($r) => [
    'id' => (int) $r['id'],
    'name' => $r['name'],
    'company_name' => $r['company_name'] ?? '',
    'email' => $r['email'] ?? '',
    'phone' => $r['phone'] ?? '',
    'location' => $r['location'] ?? '',
], $rows)]);
