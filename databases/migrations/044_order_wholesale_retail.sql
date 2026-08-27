-- 044_order_wholesale_retail.sql
-- Retail/wholesale mode for the current order-based selling flow.
-- Fresh installs already include these in databases/full_schema.sql; this
-- migration documents the same change for existing databases.

ALTER TABLE orders
    ADD COLUMN sale_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER channel;

ALTER TABLE order_items
    ADD COLUMN price_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER unit_price;
