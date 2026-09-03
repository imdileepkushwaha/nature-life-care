-- Product Management tables
USE binarymlm_db;

CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    image VARCHAR(255) NULL,
    description TEXT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_prod_cat_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_subcategories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE CASCADE,
    UNIQUE KEY uk_prod_subcat (category_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_sizes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    sort_order INT DEFAULT 0,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_prod_size (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_colors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    hex_code VARCHAR(7) NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_prod_color (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subcategory_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subcategory_id INT NOT NULL,
    commission_percent DECIMAL(8,2) DEFAULT 0,
    min_stock_alert INT DEFAULT 5,
    allow_purchase TINYINT(1) DEFAULT 1,
    notes TEXT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_subcat_setting (subcategory_id),
    FOREIGN KEY (subcategory_id) REFERENCES product_subcategories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    sku VARCHAR(60) NULL,
    category_id INT NULL,
    subcategory_id INT NULL,
    size_id INT NULL,
    color_id INT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    bv DECIMAL(12,2) NOT NULL DEFAULT 0,
    mrp DECIMAL(12,2) DEFAULT 0,
    stock_qty INT NOT NULL DEFAULT 0,
    description TEXT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_product_sku (sku),
    FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (subcategory_id) REFERENCES product_subcategories(id) ON DELETE SET NULL,
    FOREIGN KEY (size_id) REFERENCES product_sizes(id) ON DELETE SET NULL,
    FOREIGN KEY (color_id) REFERENCES product_colors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(100) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    address TEXT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vendor_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    invoice_no VARCHAR(60) NULL,
    purchase_date DATE NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    note TEXT NULL,
    status ENUM('pending','completed','cancelled') DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES product_vendors(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_purchase_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    product_id INT NOT NULL,
    batch_number VARCHAR(60) NULL,
    qty INT NOT NULL DEFAULT 1,
    rate DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (purchase_id) REFERENCES stock_purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS commodity_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NULL,
    commodity_name VARCHAR(150) NOT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    effective_date DATE NOT NULL,
    note TEXT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO product_categories (name, description) VALUES
('General', 'Default product category'),
('Wellness', 'Health and wellness products')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO product_sizes (name, sort_order) VALUES
('S', 1), ('M', 2), ('L', 3), ('XL', 4), ('One Size', 5)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO product_colors (name, hex_code) VALUES
('Red', '#dc3545'), ('Blue', '#0d6efd'), ('Green', '#28a745'), ('Black', '#212529'), ('White', '#ffffff')
ON DUPLICATE KEY UPDATE name = VALUES(name);
