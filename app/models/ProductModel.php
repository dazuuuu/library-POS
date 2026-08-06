<?php
// app/models/ProductModel.php
namespace Models;

class ProductModel extends Model
{
    protected string $table = 'products';

    public const UNITS = ['piece', 'g', 'kg', 'tonne', 'ml', 'litre'];
    public const SIZE_UNITS = ['ml', 'l'];

    /**
     * @param array $in name, category_id, subcategory_id, supplier_id, description,
     *                  quantity, unit, size_value, size_unit, buying_price, wholesale_price,
     *                  retail_price, colors[], sizes[], image_path, low_stock_threshold, status
     */
    public function create(array $in): array
    {
        $errors = $this->validate($in);
        if ($errors) {
            return ['ok' => false, 'id' => null, 'errors' => $errors];
        }
        $id = $this->insert($this->columns($in));
        return ['ok' => true, 'id' => $id, 'errors' => []];
    }

    public function edit(int $id, array $in): array
    {
        if (!$this->find($id)) {
            return ['ok' => false, 'errors' => ['_' => 'Product not found.']];
        }
        $errors = $this->validate($in);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        $this->update($id, $this->columns($in));
        return ['ok' => true, 'errors' => []];
    }

    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'draft'], true)) {
            return false;
        }
        return $this->update($id, ['status' => $status]);
    }

    public function deleteSafe(int $id): array
    {
        $this->delete($id);
        return ['ok' => true, 'error' => null];
    }

    /** Per-unit profit and margins. */
    public static function profit(float $buying, float $selling): array
    {
        $unit = $selling - $buying;
        return [
            'unit_profit' => round($unit, 2),
            'margin_pct'  => $selling > 0 ? round($unit / $selling * 100, 1) : null,
            'markup_pct'  => $buying > 0 ? round($unit / $buying * 100, 1) : null,
        ];
    }

    /** Stock value at cost (buying price × quantity). */
    public static function stockValue(float $buying, float $quantity): float
    {
        return round($buying * $quantity, 2);
    }

    /** Products grouped by category for the inventory overview. */
    public function listGroupedByCategory(): array
    {
        $rows = $this->listWithMeta();
        $grouped = [];
        foreach ($rows as $p) {
            $key = $p['category_name'] ?: 'Uncategorized';
            $grouped[$key][] = $p;
        }
        ksort($grouped);
        return $grouped;
    }

    /** Products grouped by supplier for the inventory overview. */
    public function listGroupedBySupplier(): array
    {
        $rows = $this->listWithMeta();
        $grouped = [];
        foreach ($rows as $p) {
            $key = $p['supplier_name'] ?: 'No supplier';
            $grouped[$key][] = $p;
        }
        ksort($grouped);
        return $grouped;
    }

    /** Active products at or below their restock threshold (for alerts). */
    public function lowStock(): array
    {
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare(
            "SELECT * FROM products
              WHERE tenant_id = ? AND status = 'active' AND quantity <= low_stock_threshold
           ORDER BY quantity ASC"
        );
        $stmt->execute([$tid]);
        return $stmt->fetchAll();
    }

    /** Active, in-stock products for the till. */
    public function sellable(): array
    {
        $tid = \TenantContext::tenantId();
        $sql = "SELECT p.id, p.name, p.selling_price, p.wholesale_price, p.retail_price, p.quantity, p.unit,
                       p.image_path, p.size_value, p.size_unit, p.category_id, c.name AS category_name
                  FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.tenant_id = ? AND p.status = 'active' AND p.quantity > 0
              ORDER BY p.name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tid]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['retail_price'] = (float) ($r['retail_price'] ?? $r['selling_price'] ?? 0);
            $r['wholesale_price'] = (float) ($r['wholesale_price'] ?? $r['selling_price'] ?? 0);
        }
        return $rows;
    }

    /** All active products with category names — public catalogue uses retail price. */
    public function catalogueForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.id, p.name,
                    COALESCE(NULLIF(p.retail_price, 0), p.selling_price) AS selling_price,
                    p.image_path, p.description, p.unit, p.size_value, p.size_unit,
                    c.name AS category_name, s.name AS subcategory_name
               FROM products p
          LEFT JOIN categories c  ON c.id = p.category_id
          LEFT JOIN subcategories s ON s.id = p.subcategory_id
              WHERE p.tenant_id = ? AND p.status = 'active'
           ORDER BY p.name ASC"
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    /** Products with category + subcategory + supplier names for listing. */
    public function listWithMeta(): array
    {
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare(
            "SELECT p.*, c.name AS category_name, s.name AS subcategory_name,
                    sup.name AS supplier_name
               FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN subcategories s ON s.id = p.subcategory_id
          LEFT JOIN suppliers sup ON sup.id = p.supplier_id
              WHERE p.tenant_id = ?
           ORDER BY p.name ASC"
        );
        $stmt->execute([$tid]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['retail_price'] = (float) ($r['retail_price'] ?? $r['selling_price'] ?? 0);
            $r['wholesale_price'] = (float) ($r['wholesale_price'] ?? $r['selling_price'] ?? 0);
        }
        return $rows;
    }

    // ---- internals ----

    private function validate(array $in): array
    {
        $errors = [];
        if (trim($in['name'] ?? '') === '') {
            $errors['name'] = 'Product name is required.';
        }
        $catId = (int) ($in['category_id'] ?? 0);
        if ($catId > 0 && !$this->categoryBelongsToTenant($catId)) {
            $errors['category_id'] = 'Choose a valid category.';
        }
        $subId = (int) ($in['subcategory_id'] ?? 0);
        if ($subId > 0) {
            if (!$this->subcategoryBelongsToTenant($subId)) {
                $errors['subcategory_id'] = 'Choose a valid subcategory.';
            } elseif ($catId > 0 && !$this->subcategoryBelongsToCategory($subId, $catId)) {
                $errors['subcategory_id'] = 'That subcategory is not in the chosen category.';
            }
        }
        $unit = $in['unit'] ?? 'piece';
        if (!in_array($unit, self::UNITS, true)) {
            $errors['unit'] = 'Choose a valid unit.';
        }
        $supplierId = (int) ($in['supplier_id'] ?? 0);
        if ($supplierId > 0 && !$this->supplierBelongsToTenant($supplierId)) {
            $errors['supplier_id'] = 'Choose a valid supplier.';
        }
        $sizeValue = $in['size_value'] ?? '';
        if ($sizeValue !== '' && (!is_numeric($sizeValue) || (float) $sizeValue <= 0)) {
            $errors['size_value'] = 'Enter a valid size.';
        }
        $sizeUnit = $in['size_unit'] ?? '';
        if ($sizeValue !== '' && !in_array($sizeUnit, self::SIZE_UNITS, true)) {
            $errors['size_unit'] = 'Choose ML or L.';
        }
        if (!is_numeric($in['buying_price'] ?? null) || (float) $in['buying_price'] < 0) {
            $errors['buying_price'] = 'Enter a valid buying price.';
        }
        $wholesaleIn = $in['wholesale_price'] ?? '';
        if ($wholesaleIn !== '' && (!is_numeric($wholesaleIn) || (float) $wholesaleIn < 0)) {
            $errors['wholesale_price'] = 'Enter a valid wholesale price.';
        }
        if (!is_numeric($in['retail_price'] ?? null) || (float) $in['retail_price'] < 0) {
            $errors['retail_price'] = 'Enter a valid retail price.';
        }
        if (!is_numeric($in['quantity'] ?? null) || (float) $in['quantity'] < 0) {
            $errors['quantity'] = 'Enter a valid quantity.';
        }
        return $errors;
    }

    private function columns(array $in): array
    {
        $subId = (int) ($in['subcategory_id'] ?? 0);
        $catId = (int) ($in['category_id'] ?? 0);
        if ($subId > 0 && $catId <= 0) {
            $catId = $this->subcategoryParent($subId);
        }
        $colors = array_values(array_filter(array_map('trim', (array) ($in['colors'] ?? []))));
        $sizes  = array_values(array_filter(array_map('trim', (array) ($in['sizes'] ?? []))));
        $status = $in['status'] ?? 'active';
        $status = in_array($status, ['active', 'draft'], true) ? $status : 'active';
        $retail = (float) ($in['retail_price'] ?? $in['selling_price'] ?? 0);
        // Wholesale is optional — default it to the selling price rather than 0.
        $wholesale = ($in['wholesale_price'] ?? '') !== '' ? (float) $in['wholesale_price'] : $retail;
        $sizeValue = ($in['size_value'] ?? '') !== '' ? (float) $in['size_value'] : null;
        $sizeUnit  = $sizeValue !== null ? ($in['size_unit'] ?? null) : null;
        return [
            'category_id'         => $catId > 0 ? $catId : null,
            'subcategory_id'      => $subId > 0 ? $subId : null,
            'supplier_id'         => ((int) ($in['supplier_id'] ?? 0)) > 0 ? (int) $in['supplier_id'] : null,
            'name'                => trim($in['name']),
            'description'         => ($in['description'] ?? '') !== '' ? trim($in['description']) : null,
            'quantity'            => (float) ($in['quantity'] ?? 0),
            'unit'                => $in['unit'] ?? 'piece',
            'size_value'          => $sizeValue,
            'size_unit'           => $sizeUnit,
            'buying_price'        => (float) ($in['buying_price'] ?? 0),
            'selling_price'       => $retail,
            'wholesale_price'     => $wholesale,
            'retail_price'        => $retail,
            'colors'              => $colors ? json_encode($colors) : null,
            'sizes'               => $sizes ? json_encode($sizes) : null,
            'image_path'          => ($in['image_path'] ?? '') !== '' ? $in['image_path'] : null,
            'low_stock_threshold' => (int) ($in['low_stock_threshold'] ?? 10),
            'status'              => $status,
        ];
    }

    /** Human-friendly "500ml" / "1L" label, or '' if no size is set. */
    public static function sizeLabel(array $row): string
    {
        if (empty($row['size_value'])) {
            return '';
        }
        $v = rtrim(rtrim(number_format((float) $row['size_value'], 2), '0'), '.');
        return $v . strtoupper((string) ($row['size_unit'] ?? ''));
    }

    private function categoryBelongsToTenant(int $categoryId): bool
    {
        if ($categoryId <= 0) { return false; }
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare('SELECT 1 FROM categories WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$categoryId, $tid]);
        return (bool) $stmt->fetchColumn();
    }

    private function subcategoryBelongsToCategory(int $subId, int $categoryId): bool
    {
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare('SELECT 1 FROM subcategories WHERE id = ? AND category_id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$subId, $categoryId, $tid]);
        return (bool) $stmt->fetchColumn();
    }

    private function subcategoryBelongsToTenant(int $subId): bool
    {
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare('SELECT 1 FROM subcategories WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$subId, $tid]);
        return (bool) $stmt->fetchColumn();
    }

    private function subcategoryParent(int $subId): int
    {
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare('SELECT category_id FROM subcategories WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$subId, $tid]);
        return (int) $stmt->fetchColumn();
    }

    private function supplierBelongsToTenant(int $supplierId): bool
    {
        if ($supplierId <= 0) { return false; }
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare('SELECT 1 FROM suppliers WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$supplierId, $tid]);
        return (bool) $stmt->fetchColumn();
    }
}
