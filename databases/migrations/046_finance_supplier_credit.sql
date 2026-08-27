-- 046_finance_supplier_credit.sql
-- Supplier credit from stock deliveries and shop expense tracking.

ALTER TABLE stock_intakes
    ADD COLUMN total_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER notes,
    ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount,
    ADD COLUMN amount_due DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount_paid,
    ADD COLUMN payment_status ENUM('paid','part_paid','credit') NOT NULL DEFAULT 'paid' AFTER amount_due;

CREATE TABLE IF NOT EXISTS finance_expenses (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
