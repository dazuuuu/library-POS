<?php
// app/models/StockIntakeModel.php
// The write path for "record stock": a supplier brings a batch of products
// (new ones, or a restock of ones already in the catalogue) in one delivery.
namespace Models;

class StockIntakeModel extends Model
{
    protected string $table = 'stock_intakes';

    /**
     * @param array $header supplier_id, staff_id, notes
     * @param array $items  list of either:
     *   new product:  ['mode'=>'new', 'name', 'size_value', 'size_unit', 'quantity',
     *                   'buying_price', 'selling_price', 'wholesale_price', 'image_path']
     *   restock:      ['mode'=>'restock', 'product_id', 'quantity', 'buying_price']
     * @return array ['ok'=>bool, 'intake_id'=>?int, 'errors'=>array]
     */
    public function create(array $header, array $items): array
    {
        $tid = \TenantContext::tenantId();
        if ($tid === null) {
            return ['ok' => false, 'intake_id' => null, 'errors' => ['_' => 'No shop in context.']];
        }

        $supplierId = (int) ($header['supplier_id'] ?? 0);
        if ($supplierId <= 0 || !$this->supplierBelongsToTenant($supplierId)) {
            return ['ok' => false, 'intake_id' => null, 'errors' => ['supplier_id' => 'Choose a valid supplier.']];
        }
        $staffId = (int) ($header['staff_id'] ?? 0);
        if ($staffId <= 0) {
            return ['ok' => false, 'intake_id' => null, 'errors' => ['_' => 'No staff in context.']];
        }

        $items = array_values(array_filter($items, fn($i) => in_array($i['mode'] ?? '', ['new', 'restock'], true)));
        if (!$items) {
            return ['ok' => false, 'intake_id' => null, 'errors' => ['_' => 'Add at least one product to this delivery.']];
        }

        $db = $this->db;
        $productModel = new ProductModel($db);

        try {
            $db->beginTransaction();

            $insIntake = $db->prepare(
                'INSERT INTO stock_intakes (tenant_id, supplier_id, staff_id, notes) VALUES (?,?,?,?)'
            );
            $insIntake->execute([
                $tid, $supplierId, $staffId,
                trim((string) ($header['notes'] ?? '')) !== '' ? trim($header['notes']) : null,
            ]);
            $intakeId = (int) $db->lastInsertId();

            $insItem = $db->prepare(
                'INSERT INTO stock_intake_items (tenant_id, stock_intake_id, product_id, product_name, quantity, buying_price)
                 VALUES (?,?,?,?,?,?)'
            );
            $bump = $db->prepare(
                'UPDATE products SET quantity = quantity + ?, buying_price = ? WHERE id = ? AND tenant_id = ?'
            );

            foreach ($items as $i) {
                $qty = (float) ($i['quantity'] ?? 0);
                if ($qty <= 0) {
                    $db->rollBack();
                    return ['ok' => false, 'intake_id' => null, 'errors' => ['_' => 'Every product needs a quantity greater than zero.']];
                }
                $buying = (float) ($i['buying_price'] ?? 0);

                if (($i['mode'] ?? '') === 'restock') {
                    $productId = (int) ($i['product_id'] ?? 0);
                    $prod = $productModel->find($productId);
                    if (!$prod) {
                        $db->rollBack();
                        return ['ok' => false, 'intake_id' => null, 'errors' => ['_' => 'One of the selected products was not found.']];
                    }
                    $bump->execute([$qty, $buying, $productId, $tid]);
                    $productName = $prod['name'];
                } else {
                    $name = trim((string) ($i['name'] ?? ''));
                    if ($name === '') {
                        $db->rollBack();
                        return ['ok' => false, 'intake_id' => null, 'errors' => ['_' => 'Every new product needs a name.']];
                    }
                    $res = $productModel->create([
                        'name'            => $name,
                        'category_id'     => (int) ($i['category_id'] ?? 0),
                        'supplier_id'     => $supplierId,
                        'unit'            => 'piece',
                        'size_value'      => $i['size_value'] ?? '',
                        'size_unit'       => $i['size_unit'] ?? '',
                        'quantity'        => $qty,
                        'buying_price'    => $buying,
                        'retail_price'    => $i['selling_price'] ?? 0,
                        'wholesale_price' => $i['wholesale_price'] ?? '',
                        'image_path'      => $i['image_path'] ?? '',
                        'status'          => 'active',
                    ]);
                    if (!$res['ok']) {
                        $db->rollBack();
                        return ['ok' => false, 'intake_id' => null, 'errors' => $res['errors'] ?: ['_' => "Could not save \"{$name}\"."]];
                    }
                    $productId = (int) $res['id'];
                    $productName = $name;
                }

                $insItem->execute([$tid, $intakeId, $productId, $productName, $qty, $buying]);
            }

            $db->commit();
            return ['ok' => true, 'intake_id' => $intakeId, 'errors' => []];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            return ['ok' => false, 'intake_id' => null, 'errors' => ['_' => 'Could not record this delivery. Please try again.']];
        }
    }

    /** Recent deliveries with supplier names, for the activity list. */
    public function recentWithMeta(int $limit = 30): array
    {
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare(
            "SELECT si.*, sup.name AS supplier_name, u.username AS staff_name,
                    (SELECT COUNT(*) FROM stock_intake_items sii WHERE sii.stock_intake_id = si.id) AS item_count
               FROM stock_intakes si
          LEFT JOIN suppliers sup ON sup.id = si.supplier_id
          LEFT JOIN users u ON u.id = si.staff_id
              WHERE si.tenant_id = ?
           ORDER BY si.created_at DESC, si.id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute([$tid]);
        return $stmt->fetchAll();
    }

    public function itemsFor(int $intakeId): array
    {
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare('SELECT * FROM stock_intake_items WHERE stock_intake_id = ? AND tenant_id = ? ORDER BY id ASC');
        $stmt->execute([$intakeId, $tid]);
        return $stmt->fetchAll();
    }

    private function supplierBelongsToTenant(int $id): bool
    {
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare('SELECT 1 FROM suppliers WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$id, $tid]);
        return (bool) $stmt->fetchColumn();
    }
}
