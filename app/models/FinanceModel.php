<?php
namespace Models;

class FinanceModel extends Model
{
    public function __construct(?\PDO $db = null)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    public function recordExpense(array $in): array
    {
        $tid = \TenantContext::tenantId();
        $amount = round((float) ($in['amount'] ?? 0), 2);
        $title = trim((string) ($in['title'] ?? ''));
        if ($tid === null) { return ['ok' => false, 'error' => 'No shop in context.']; }
        if ($title === '') { return ['ok' => false, 'error' => 'Enter an expense title.']; }
        if ($amount <= 0) { return ['ok' => false, 'error' => 'Enter an amount greater than zero.']; }

        $stmt = $this->db->prepare(
            'INSERT INTO finance_expenses (tenant_id, title, category, amount, payment_method, expense_date, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $tid,
            $title,
            trim((string) ($in['category'] ?? 'General')) ?: 'General',
            $amount,
            in_array($in['payment_method'] ?? 'cash', ['cash', 'mpesa', 'bank'], true) ? $in['payment_method'] : 'cash',
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['expense_date'] ?? '') ? $in['expense_date'] : date('Y-m-d'),
            trim((string) ($in['notes'] ?? '')) ?: null,
            \TenantContext::userId(),
        ]);
        return ['ok' => true, 'error' => null];
    }

    public function expenses(int $limit = 80): array
    {
        return $this->expensesForTenant((int) \TenantContext::tenantId(), $limit);
    }

    public function expensesForTenant(int $tenantId, int $limit = 80): array
    {
        $stmt = $this->db->prepare('SELECT * FROM finance_expenses WHERE tenant_id = ? ORDER BY expense_date DESC, id DESC LIMIT ' . (int) $limit);
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    public function supplierBalances(): array
    {
        return $this->supplierBalancesForTenant((int) \TenantContext::tenantId());
    }

    public function supplierBalancesForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT si.supplier_id, COALESCE(s.name, 'Unknown supplier') AS supplier_name,
                    SUM(si.total_amount) AS purchases,
                    SUM(si.amount_paid) AS paid,
                    SUM(si.amount_due) AS owed
               FROM stock_intakes si
          LEFT JOIN suppliers s ON s.id = si.supplier_id
              WHERE si.tenant_id = ? AND si.amount_due > 0
           GROUP BY si.supplier_id, s.name
           ORDER BY owed DESC"
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    public function summary(): array
    {
        return $this->summaryForTenant((int) \TenantContext::tenantId());
    }

    public function summaryForTenant(int $tid): array
    {
        $sales = (float) $this->scalar("SELECT COALESCE(SUM(total),0) FROM sales WHERE tenant_id = ? AND status = 'completed'", [$tid])
            + (float) $this->scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE tenant_id = ? AND status = 'paid'", [$tid]);
        $cash = (float) $this->scalar("SELECT COALESCE(SUM(cash_amount),0) FROM sales WHERE tenant_id = ? AND status = 'completed'", [$tid])
            + (float) $this->scalar("SELECT COALESCE(SUM(cash_amount),0) FROM orders WHERE tenant_id = ? AND status = 'paid'", [$tid]);
        $mpesa = (float) $this->scalar("SELECT COALESCE(SUM(mpesa_amount),0) FROM sales WHERE tenant_id = ? AND status = 'completed'", [$tid])
            + (float) $this->scalar("SELECT COALESCE(SUM(mpesa_amount),0) FROM orders WHERE tenant_id = ? AND status = 'paid'", [$tid]);
        $creditReceivable = (float) $this->scalar("SELECT COALESCE(SUM(total),0) FROM orders WHERE tenant_id = ? AND status = 'open'", [$tid]);
        $inventoryAsset = (float) $this->scalar("SELECT COALESCE(SUM(quantity * buying_price),0) FROM products WHERE tenant_id = ? AND status <> 'archived'", [$tid]);
        $expenses = (float) $this->scalar("SELECT COALESCE(SUM(amount),0) FROM finance_expenses WHERE tenant_id = ?", [$tid]);
        $supplierDebt = (float) $this->scalar("SELECT COALESCE(SUM(amount_due),0) FROM stock_intakes WHERE tenant_id = ?", [$tid]);
        $liquid = max(0, $cash + $mpesa - $expenses);
        return [
            'sales' => round($sales, 2),
            'liquid' => round($liquid, 2),
            'cash' => round($cash, 2),
            'mpesa' => round($mpesa, 2),
            'inventory_asset' => round($inventoryAsset, 2),
            'credit_receivable' => round($creditReceivable, 2),
            'expenses' => round($expenses, 2),
            'supplier_debt' => round($supplierDebt, 2),
            'assets' => round($liquid + $inventoryAsset + $creditReceivable, 2),
            'liabilities' => round($supplierDebt, 2),
            'equity' => round(($liquid + $inventoryAsset + $creditReceivable) - $supplierDebt, 2),
        ];
    }

    private function scalar(string $sql, array $args)
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchColumn();
    }

    private function ensureSchema(): void
    {
        $this->ensureColumn('stock_intakes', 'total_amount', "ALTER TABLE stock_intakes ADD COLUMN total_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER notes");
        $this->ensureColumn('stock_intakes', 'amount_paid', "ALTER TABLE stock_intakes ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount");
        $this->ensureColumn('stock_intakes', 'amount_due', "ALTER TABLE stock_intakes ADD COLUMN amount_due DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount_paid");
        $this->ensureColumn('stock_intakes', 'payment_status', "ALTER TABLE stock_intakes ADD COLUMN payment_status ENUM('paid','part_paid','credit') NOT NULL DEFAULT 'paid' AFTER amount_due");
        $this->db->exec("CREATE TABLE IF NOT EXISTS finance_expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            title VARCHAR(160) NOT NULL,
            category VARCHAR(80) NOT NULL DEFAULT 'General',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_method ENUM('cash','mpesa','bank') NOT NULL DEFAULT 'cash',
            expense_date DATE NOT NULL,
            notes VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_finance_expenses_tenant (tenant_id, expense_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function ensureColumn(string $table, string $column, string $sql): void
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
            $stmt->execute([$table, $column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $this->db->exec($sql);
            }
        } catch (\PDOException $ignored) {
        }
    }
}
