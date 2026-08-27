<?php
namespace Models;

class CustomerModel extends Model
{
    public function __construct(?\PDO $db = null)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    public function listForTenant(int $limit = 200): array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE tenant_id = ? ORDER BY name ASC LIMIT ' . (int) $limit);
        $stmt->execute([\TenantContext::tenantId()]);
        return $stmt->fetchAll();
    }

    public function search(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') { return []; }
        $stmt = $this->db->prepare(
            'SELECT * FROM customers
              WHERE tenant_id = ?
                AND (name LIKE ? OR company_name LIKE ? OR email LIKE ? OR phone LIKE ?)
           ORDER BY (name LIKE ?) DESC, name ASC
              LIMIT ' . (int) $limit
        );
        $like = '%' . $q . '%';
        $prefix = $q . '%';
        $stmt->execute([\TenantContext::tenantId(), $like, $like, $like, $like, $prefix]);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$id, \TenantContext::tenantId()]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function save(array $in): array
    {
        $tid = \TenantContext::tenantId();
        $name = trim((string) ($in['name'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        if ($name === '') { return ['ok' => false, 'error' => 'Enter the customer name.']; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { return ['ok' => false, 'error' => 'Enter a valid email address.']; }

        $id = (int) ($in['id'] ?? 0);
        $args = [
            $name,
            trim((string) ($in['company_name'] ?? '')) ?: null,
            $email !== '' ? $email : null,
            trim((string) ($in['phone'] ?? '')) ?: null,
            trim((string) ($in['location'] ?? '')) ?: null,
            trim((string) ($in['notes'] ?? '')) ?: null,
        ];
        if ($id > 0 && $this->find($id)) {
            $stmt = $this->db->prepare('UPDATE customers SET name = ?, company_name = ?, email = ?, phone = ?, location = ?, notes = ? WHERE id = ? AND tenant_id = ?');
            $stmt->execute([...$args, $id, $tid]);
            return ['ok' => true, 'id' => $id, 'error' => null];
        }
        $stmt = $this->db->prepare('INSERT INTO customers (tenant_id, name, company_name, email, phone, location, notes) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$tid, ...$args]);
        return ['ok' => true, 'id' => (int) $this->db->lastInsertId(), 'error' => null];
    }

    public function findOrCreateByContact(string $name, string $email = '', string $phone = '', string $company = '', string $location = ''): ?array
    {
        $name = trim($name);
        if ($name === '') { return null; }
        $tid = \TenantContext::tenantId();
        if ($email !== '') {
            $stmt = $this->db->prepare('SELECT * FROM customers WHERE tenant_id = ? AND email = ? LIMIT 1');
            $stmt->execute([$tid, $email]);
            $row = $stmt->fetch();
            if ($row) { return $row; }
        }
        $res = $this->save(['name' => $name, 'email' => $email, 'phone' => $phone, 'company_name' => $company, 'location' => $location]);
        return $res['ok'] ? $this->find((int) $res['id']) : null;
    }

    private function ensureSchema(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            name VARCHAR(160) NOT NULL,
            company_name VARCHAR(160) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(40) NULL,
            location VARCHAR(160) NULL,
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_customers_tenant_name (tenant_id, name),
            KEY idx_customers_tenant_email (tenant_id, email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
