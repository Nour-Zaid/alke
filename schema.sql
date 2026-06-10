CREATE DATABASE IF NOT EXISTS alke_store;
USE alke_store;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    verification_token VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    image VARCHAR(255),
    stock INT NOT NULL DEFAULT 0,
    category_id INT,
    sizes VARCHAR(255) NOT NULL DEFAULT '',
    colors VARCHAR(255) NOT NULL DEFAULT '',
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    INDEX idx_products_category (category_id),
    INDEX idx_products_name (name)
);

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    status VARCHAR(50) NOT NULL,
    ship_name VARCHAR(150) DEFAULT NULL,
    ship_email VARCHAR(150) DEFAULT NULL,
    ship_phone VARCHAR(40) DEFAULT NULL,
    ship_address VARCHAR(255) DEFAULT NULL,
    ship_city VARCHAR(100) DEFAULT NULL,
    ship_country VARCHAR(100) DEFAULT NULL,
    ship_postal_code VARCHAR(30) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    INDEX idx_orders_user (user_id)
);

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    INDEX idx_order_items_order (order_id)
);

CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    subject VARCHAR(200) DEFAULT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO categories (name)
SELECT * FROM (SELECT 'Men') AS tmp
WHERE NOT EXISTS (SELECT 1 FROM categories LIMIT 1);
INSERT INTO categories (name)
SELECT * FROM (SELECT 'Women') AS tmp
WHERE (SELECT COUNT(*) FROM categories) = 1;
INSERT INTO categories (name)
SELECT * FROM (SELECT 'Accessories') AS tmp
WHERE (SELECT COUNT(*) FROM categories) = 2;
INSERT INTO categories (name)
SELECT * FROM (SELECT 'Footwear') AS tmp
WHERE (SELECT COUNT(*) FROM categories) = 3;
