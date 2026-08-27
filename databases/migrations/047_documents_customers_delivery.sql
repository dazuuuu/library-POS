-- Active: 1785849373366@@127.0.0.1@3306@denmar_db
-- 047_documents_customers_delivery.sql
-- Manual document generation, loyal customers, and delivery-note metadata.

CREATE TABLE IF NOT EXISTS customers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders
    ADD COLUMN customer_id INT NULL AFTER table_name,
    ADD COLUMN customer_company VARCHAR(160) NULL AFTER customer_phone,
    ADD COLUMN customer_location VARCHAR(160) NULL AFTER customer_company,
    ADD COLUMN delivery_person VARCHAR(120) NULL AFTER delivery_note_sent_at,
    ADD COLUMN delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER delivery_person;
