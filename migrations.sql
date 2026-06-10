-- Run this against an EXISTING alke_store database to bring it up to date.
-- (New installs should use schema.sql instead.)
-- Each statement is safe to skip if the column/table already exists —
-- the app also auto-migrates these at runtime, so this file is optional.

USE alke_store;

ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL;
ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN verification_token VARCHAR(64) DEFAULT NULL;

ALTER TABLE products ADD COLUMN sizes VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN colors VARCHAR(255) NOT NULL DEFAULT '';

ALTER TABLE orders ADD COLUMN ship_name VARCHAR(150) DEFAULT NULL;
ALTER TABLE orders ADD COLUMN ship_email VARCHAR(150) DEFAULT NULL;
ALTER TABLE orders ADD COLUMN ship_phone VARCHAR(40) DEFAULT NULL;
ALTER TABLE orders ADD COLUMN ship_address VARCHAR(255) DEFAULT NULL;
ALTER TABLE orders ADD COLUMN ship_city VARCHAR(100) DEFAULT NULL;
ALTER TABLE orders ADD COLUMN ship_country VARCHAR(100) DEFAULT NULL;
ALTER TABLE orders ADD COLUMN ship_postal_code VARCHAR(30) DEFAULT NULL;

CREATE INDEX idx_products_category ON products (category_id);
CREATE INDEX idx_products_name ON products (name);
CREATE INDEX idx_orders_user ON orders (user_id);
CREATE INDEX idx_order_items_order ON order_items (order_id);

CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    subject VARCHAR(200) DEFAULT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
