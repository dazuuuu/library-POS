<?php
// app/models/SupplierModel.php
namespace Models;

class SupplierModel extends Model
{
    protected string $table = 'suppliers';

    public function create(string $name, ?string $phone = null, ?string $notes = null): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'id' => null, 'error' => 'Supplier name is required.'];
        }
        if (strlen($name) > 160) {
            return ['ok' => false, 'id' => null, 'error' => 'Supplier name is too long.'];
        }
        if ($this->nameTaken($name)) {
            return ['ok' => false, 'id' => null, 'error' => 'You already have a supplier with that name.'];
        }
        try {
            $id = $this->insert([
                'name'  => $name,
                'phone' => ($phone !== null && trim($phone) !== '') ? trim($phone) : null,
                'notes' => ($notes !== null && trim($notes) !== '') ? trim($notes) : null,
            ]);
            return ['ok' => true, 'id' => $id, 'error' => null];
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'id' => null, 'error' => 'You already have a supplier with that name.'];
            }
            throw $e;
        }
    }

    public function nameTaken(string $name, ?int $exceptId = null): bool
    {
        foreach ($this->all(['name' => trim($name)]) as $row) {
            if ($exceptId === null || (int) $row['id'] !== $exceptId) {
                return true;
            }
        }
        return false;
    }

    /** Suppliers with a count of products currently attributed to them. */
    public function listWithCounts(): array
    {
        $suppliers = $this->all([], 'name ASC');
        if (!$suppliers) {
            return [];
        }
        $tid = \TenantContext::tenantId();
        $ids = array_column($suppliers, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT supplier_id, COUNT(*) c FROM products WHERE tenant_id = ? AND supplier_id IN ($in) GROUP BY supplier_id"
        );
        $stmt->execute(array_merge([$tid], $ids));
        $counts = [];
        foreach ($stmt->fetchAll() as $r) { $counts[(int) $r['supplier_id']] = (int) $r['c']; }
        foreach ($suppliers as &$s) { $s['product_count'] = $counts[(int) $s['id']] ?? 0; }
        return $suppliers;
    }
}
