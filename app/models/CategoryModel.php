<?php
// app/models/CategoryModel.php
namespace Models;

class CategoryModel extends Model
{
    protected string $table = 'categories';

    public function create(string $name, ?string $imagePath = null): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'id' => null, 'error' => 'Category name is required.'];
        }
        if (strlen($name) > 120) {
            return ['ok' => false, 'id' => null, 'error' => 'Category name is too long.'];
        }
        if ($this->nameTaken($name)) {
            return ['ok' => false, 'id' => null, 'error' => 'You already have a category with that name.'];
        }
        try {
            $id = $this->insert(['name' => $name, 'image_path' => $imagePath, 'status' => 'active']);
            return ['ok' => true, 'id' => $id, 'error' => null];
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'id' => null, 'error' => 'You already have a category with that name.'];
            }
            throw $e;
        }
    }

    /** Rename and, optionally, replace the image (pass null to leave it as-is). */
    public function rename(int $id, string $name, ?string $imagePath = null): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Category name is required.'];
        }
        if ($this->nameTaken($name, $id)) {
            return ['ok' => false, 'error' => 'You already have a category with that name.'];
        }
        $data = ['name' => $name];
        if ($imagePath !== null) { $data['image_path'] = $imagePath; }
        $this->update($id, $data);
        return ['ok' => true, 'error' => null];
    }

    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'draft'], true)) {
            return false;
        }
        return $this->update($id, ['status' => $status]);
    }

    /** Delete only when empty — never orphan subcategories or products. */
    public function deleteSafe(int $id): array
    {
        if ($this->childCount('subcategories', 'category_id', $id) > 0) {
            return ['ok' => false, 'error' => 'Remove or move its subcategories first.'];
        }
        if ($this->childCount('products', 'category_id', $id) > 0) {
            return ['ok' => false, 'error' => 'This category still has products. Move or delete them first.'];
        }
        $this->delete($id);
        return ['ok' => true, 'error' => null];
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

    /** Categories with their subcategory + product counts. */
    public function listWithCounts(): array
    {
        $cats = $this->all([], 'name ASC');
        if (!$cats) {
            return [];
        }
        $ids = array_column($cats, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $sub = $this->db->prepare("SELECT category_id, COUNT(*) c FROM subcategories WHERE category_id IN ($in) GROUP BY category_id");
        $sub->execute($ids);
        $subCounts = [];
        foreach ($sub->fetchAll() as $r) { $subCounts[(int) $r['category_id']] = (int) $r['c']; }
        $prd = $this->db->prepare("SELECT category_id, COUNT(*) c FROM products WHERE category_id IN ($in) GROUP BY category_id");
        $prd->execute($ids);
        $prdCounts = [];
        foreach ($prd->fetchAll() as $r) { $prdCounts[(int) $r['category_id']] = (int) $r['c']; }
        foreach ($cats as &$c) {
            $c['subcategory_count'] = $subCounts[(int) $c['id']] ?? 0;
            $c['product_count']     = $prdCounts[(int) $c['id']] ?? 0;
        }
        return $cats;
    }

    private function childCount(string $table, string $col, int $id): int
    {
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} = ? AND tenant_id = ?");
        $stmt->execute([$id, $tid]);
        return (int) $stmt->fetchColumn();
    }
}