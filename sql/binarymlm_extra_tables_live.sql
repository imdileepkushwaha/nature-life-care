-- Extra tables for LIVE (run after binarymlm_db_live.sql)
-- Select database binarymlm_db in phpMyAdmin first, then Import this file.
-- Safe to re-run: uses CREATE TABLE IF NOT EXISTS / INSERT IGNORE.

-- ========== Utility ==========
CREATE TABLE IF NOT EXISTS countries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(10) NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_country_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    country_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE,
    UNIQUE KEY uk_state_country (country_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (state_id) REFERENCES states(id) ON DELETE CASCADE,
    UNIQUE KEY uk_city_state (state_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS banks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    short_code VARCHAR(20) NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bank_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_id INT NOT NULL,
    account_name VARCHAR(150) NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    ifsc_code VARCHAR(20) NOT NULL,
    branch_name VARCHAR(150) NULL,
    account_type VARCHAR(50) DEFAULT 'Current',
    status ENUM('active','inactive') DEFAULT 'active',
    upi_id VARCHAR(100) NULL,
    qr_code VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deductions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    deduction_type ENUM('percent','fixed') DEFAULT 'percent',
    value DECIMAL(12,2) NOT NULL DEFAULT 0,
    description VARCHAR(255) NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS news (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    published_at DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_plan_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS package_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    package_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    bv DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE,
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
    UNIQUE KEY uk_plan_package (plan_id, package_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO countries (id, name, code) VALUES (1, 'India', 'IN');
INSERT IGNORE INTO states (id, country_id, name) VALUES (1, 1, 'Maharashtra'), (2, 1, 'Delhi');
INSERT IGNORE INTO cities (id, state_id, name) VALUES (1, 1, 'Mumbai'), (2, 1, 'Pune'), (3, 2, 'New Delhi');
INSERT IGNORE INTO banks (id, name, short_code) VALUES (1, 'State Bank of India', 'SBI'), (2, 'HDFC Bank', 'HDFC');
INSERT IGNORE INTO deductions (name, deduction_type, value, description) VALUES
('TDS', 'percent', 5.00, 'Tax Deducted at Source'),
('Admin Charge', 'percent', 2.00, 'Admin processing fee');
INSERT IGNORE INTO plans (id, name, description) VALUES (1, 'Binary Plan', 'Standard binary MLM plan');

-- ========== Products ==========
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
    slug VARCHAR(180) NULL,
    sku VARCHAR(60) NULL,
    sku_mode ENUM('auto','manual') DEFAULT 'auto',
    category_id INT NULL,
    subcategory_id INT NULL,
    size_id INT NULL,
    color_id INT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    bv DECIMAL(12,2) NOT NULL DEFAULT 0,
    mrp DECIMAL(12,2) DEFAULT 0,
    discount_percent DECIMAL(8,2) DEFAULT 0,
    offer_flash_text VARCHAR(180) NULL,
    offer_countdown VARCHAR(20) NULL,
    offer_bank_text VARCHAR(255) NULL,
    stock_qty INT NOT NULL DEFAULT 0,
    description TEXT NULL,
    thumbnail VARCHAR(255) NULL,
    meta_title VARCHAR(180) NULL,
    meta_description TEXT NULL,
    weight DECIMAL(10,2) DEFAULT 0,
    length DECIMAL(10,2) DEFAULT 0,
    width DECIMAL(10,2) DEFAULT 0,
    height DECIMAL(10,2) DEFAULT 0,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_product_sku (sku),
    FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (subcategory_id) REFERENCES product_subcategories(id) ON DELETE SET NULL,
    FOREIGN KEY (size_id) REFERENCES product_sizes(id) ON DELETE SET NULL,
    FOREIGN KEY (color_id) REFERENCES product_colors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
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

-- ========== KYC / Password / Closing / Activation ==========
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pr_member (member_id),
    KEY idx_pr_token (token_hash),
    KEY idx_pr_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS member_kyc_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    doc_type ENUM('pan','bank','aadhar','upi') NOT NULL,
    status ENUM('not_submitted','pending','approved','rejected') NOT NULL DEFAULT 'not_submitted',
    pan_number VARCHAR(20) NULL,
    pan_name VARCHAR(100) NULL,
    account_holder VARCHAR(100) NULL,
    account_number VARCHAR(50) NULL,
    ifsc_code VARCHAR(20) NULL,
    bank_name VARCHAR(100) NULL,
    branch_name VARCHAR(100) NULL,
    aadhar_number VARCHAR(20) NULL,
    address_line TEXT NULL,
    country VARCHAR(100) NULL,
    state VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    area VARCHAR(100) NULL,
    pincode VARCHAR(20) NULL,
    upi_id VARCHAR(100) NULL,
    upi_name VARCHAR(50) NULL,
    document_file VARCHAR(255) NULL,
    document_back VARCHAR(255) NULL,
    admin_note TEXT NULL,
    submitted_at DATETIME NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_member_doc (member_id, doc_type),
    KEY idx_kyc_status (status),
    KEY idx_kyc_type (doc_type),
    CONSTRAINT fk_kyc_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS member_kyc_upi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    upi_name VARCHAR(50) NOT NULL,
    upi_id VARCHAR(100) NOT NULL,
    document_file VARCHAR(255) NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note TEXT NULL,
    submitted_at DATETIME NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_member_upi_id (member_id, upi_id),
    KEY idx_kyc_upi_member (member_id),
    KEY idx_kyc_upi_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activation_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    package_id INT NOT NULL,
    from_package_id INT NULL,
    request_type VARCHAR(20) NOT NULL DEFAULT 'activation',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(50) NOT NULL DEFAULT 'Bank Transfer',
    utr_reference VARCHAR(100) NOT NULL,
    payment_slip VARCHAR(255) NULL,
    note TEXT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note TEXT NULL,
    processed_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    INDEX idx_act_req_member (member_id),
    INDEX idx_act_req_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bv_credits (
    member_id INT NOT NULL PRIMARY KEY,
    package_id INT NULL,
    bv DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bv_credits_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS closing_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NULL,
    members_processed INT NOT NULL DEFAULT 0,
    members_paid INT NOT NULL DEFAULT 0,
    pairs_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    matched_bv_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    binary_gross_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    binary_net_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    matching_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    admin_charge_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    pair_bv DECIMAL(12,2) NOT NULL DEFAULT 1,
    binary_percent DECIMAL(8,2) NOT NULL DEFAULT 0,
    matching_percent DECIMAL(8,2) NOT NULL DEFAULT 0,
    flush_pairs INT NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS closing_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    closing_id INT NOT NULL,
    member_id INT NOT NULL,
    left_bv_before DECIMAL(12,2) NOT NULL DEFAULT 0,
    right_bv_before DECIMAL(12,2) NOT NULL DEFAULT 0,
    pairs DECIMAL(12,2) NOT NULL DEFAULT 0,
    matched_bv DECIMAL(12,2) NOT NULL DEFAULT 0,
    binary_gross DECIMAL(12,2) NOT NULL DEFAULT 0,
    admin_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
    binary_net DECIMAL(12,2) NOT NULL DEFAULT 0,
    matching_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    matching_to INT NULL,
    left_bv_after DECIMAL(12,2) NOT NULL DEFAULT 0,
    right_bv_after DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_closing_items_run (closing_id),
    INDEX idx_closing_items_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS package_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    product_id INT NOT NULL,
    qty INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pkg_product (package_id, product_id),
    INDEX idx_pp_package (package_id),
    INDEX idx_pp_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Immutable withdrawal payout audit (approve / reject / paid)
CREATE TABLE IF NOT EXISTS withdrawal_payout_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    withdrawal_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NULL,
    event_type VARCHAR(20) NOT NULL,
    gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tds_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    other_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(80) NULL,
    account_details TEXT NULL,
    payout_ref VARCHAR(120) NULL,
    admin_note TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wpl_withdrawal (withdrawal_id),
    KEY idx_wpl_member (member_id),
    KEY idx_wpl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
