-- Binary MLM Database Schema
-- Database: binarymlm_db

CREATE DATABASE IF NOT EXISTS binarymlm_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE binarymlm_db;

-- Admin users
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Platform Super Admin (plan/features control — separate from client admin)
CREATE TABLE IF NOT EXISTS super_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Packages / Plans
CREATE TABLE IF NOT EXISTS packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    bv DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Business Volume',
    capping DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Max earning cap',
    daily_roi DECIMAL(8,2) NOT NULL DEFAULT 0,
    validity_days INT NOT NULL DEFAULT 30,
    description TEXT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Members
CREATE TABLE IF NOT EXISTS members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id VARCHAR(20) NOT NULL UNIQUE,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NULL,
    photo VARCHAR(255) NULL,
    sponsor_id INT NULL,
    placement_id INT NULL,
    position ENUM('left','right') NULL,
    package_id INT NULL,
    left_count INT DEFAULT 0,
    right_count INT DEFAULT 0,
    left_bv DECIMAL(12,2) DEFAULT 0,
    right_bv DECIMAL(12,2) DEFAULT 0,
    total_earnings DECIMAL(12,2) DEFAULT 0,
    wallet_balance DECIMAL(12,2) DEFAULT 0,
    status ENUM('active','inactive','blocked') DEFAULT 'active',
    kyc_status ENUM('pending','approved','rejected','not_submitted') DEFAULT 'not_submitted',
    kyc_id_type VARCHAR(50) NULL,
    kyc_id_number VARCHAR(100) NULL,
    kyc_document VARCHAR(255) NULL,
    kyc_note TEXT NULL,
    kyc_submitted_at DATETIME NULL,
    kyc_reviewed_at DATETIME NULL,
    join_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sponsor_id) REFERENCES members(id) ON DELETE SET NULL,
    FOREIGN KEY (placement_id) REFERENCES members(id) ON DELETE SET NULL,
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Commissions
CREATE TABLE IF NOT EXISTS commissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    from_member_id INT NULL,
    type ENUM('binary','referral','matching','level','other') DEFAULT 'binary',
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(255) NULL,
    status ENUM('pending','paid','cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (from_member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Withdrawals / Payouts
CREATE TABLE IF NOT EXISTS withdrawals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    tds_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    other_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(12,2) NULL,
    payment_method VARCHAR(50) NULL,
    account_details TEXT NULL,
    status ENUM('pending','approved','rejected','paid') DEFAULT 'pending',
    admin_note TEXT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Settings
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Activity log
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Default admin (username: admin / password: admin123)
INSERT INTO admins (username, email, password, full_name) VALUES
('admin', 'admin@binarymlm.com', '$2y$10$HV.v4CvAhCF8iW4CkhlaB.NCNXIvGP1agMwXKubzBKDusDlSNLHbi', 'Super Admin');

-- Default packages
INSERT INTO packages (name, amount, bv, daily_roi, validity_days, description) VALUES
('Starter Plan', 1000.00, 1000.00, 1.00, 30, 'Access level 1-5 commissions, binary node placement'),
('Silver Plan', 2500.00, 2500.00, 1.25, 45, 'Access level 1-8 commissions, priority matching'),
('Gold Plan', 5000.00, 5000.00, 1.50, 60, 'Full level access, matching bonus eligible'),
('Platinum Plan', 10000.00, 10000.00, 2.00, 90, 'Premium package with maximum earning potential');

-- Default settings
INSERT INTO settings (setting_key, setting_value) VALUES
('company_name', 'Binary MLM'),
('member_id_prefix', 'MLM'),
('member_id_pad', '5'),
('binary_commission_percent', '10'),
('referral_commission_percent', '5'),
('matching_commission_percent', '0'),
('binary_flush_pairs', '0'),
('binary_pair_bv', '1000'),
('binary_income_enabled', '1'),
('level_income_enabled', '1'),
('level_income_levels', '10'),
('level_1_percent', '5'),
('level_2_percent', '3'),
('level_3_percent', '2'),
('level_4_percent', '1'),
('level_5_percent', '1'),
('level_6_percent', '0.5'),
('level_7_percent', '0.5'),
('level_8_percent', '0.5'),
('level_9_percent', '0.5'),
('level_10_percent', '0.5'),
('min_withdrawal', '500'),
('max_withdrawal', '0'),
('processing_fee_percent', '1'),
('tds_deduction_percent', '5'),
('daily_closing_admin_charge', '0'),
('currency', 'INR'),
('currency_symbol', '₹'),
('contact_person', 'Support Team'),
('contact_phone', '+91 98765 43210'),
('contact_whatsapp', '919876543210'),
('contact_email', 'support@binarymlm.com'),
('contact_alt_phone', ''),
('contact_address', 'Office No. 12, Business Hub'),
('contact_city', 'Mumbai'),
('contact_state', 'Maharashtra'),
('contact_country', 'India'),
('contact_pincode', '400001'),
('contact_hours', 'Mon–Sat, 10:00 AM – 6:00 PM'),
('contact_map_url', ''),
('contact_facebook', ''),
('contact_instagram', ''),
('contact_twitter', ''),
('contact_youtube', ''),
('contact_telegram', ''),
('contact_form_enabled', '1'),
('contact_form_notify_email', 'support@binarymlm.com'),
('plan_mode', 'hybrid'),
('feature_preset', 'hybrid_full'),
('feature_package_enabled', '1'),
('feature_tpin_enabled', '1'),
('feature_utr_activation_enabled', '1'),
('feature_wallet_topup_enabled', '1'),
('feature_product_shop_enabled', '1'),
('feature_binary_income', '1'),
('feature_level_income', '1'),
('feature_referral_income', '1'),
('feature_matching_income', '1'),
('feature_withdrawals_enabled', '1'),
('feature_kyc_enabled', '1'),
('feature_utility_enabled', '1'),
('feature_reports_enabled', '1');

-- Contact form inquiries
CREATE TABLE IF NOT EXISTS contact_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    subject VARCHAR(200) NULL,
    message TEXT NOT NULL,
    status ENUM('new','read','replied','archived') DEFAULT 'new',
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Topup PIN (T-Pin / E-Pin) Type A — package pins for instant activate/upgrade
CREATE TABLE IF NOT EXISTS topup_pins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pin_code VARCHAR(32) NOT NULL,
    package_id INT NOT NULL,
    status ENUM('unused','used','blocked') NOT NULL DEFAULT 'unused',
    generated_by INT NULL,
    assigned_to INT NULL,
    used_by INT NULL,
    used_at DATETIME NULL,
    blocked_at DATETIME NULL,
    blocked_by INT NULL,
    batch_code VARCHAR(40) NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tpin_code (pin_code),
    INDEX idx_tpin_status (status),
    INDEX idx_tpin_assigned (assigned_to),
    INDEX idx_tpin_package (package_id),
    INDEX idx_tpin_batch (batch_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS topup_pin_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pin_id INT NOT NULL,
    from_member_id INT NOT NULL,
    to_member_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tpin_tr_pin (pin_id),
    INDEX idx_tpin_tr_from (from_member_id),
    INDEX idx_tpin_tr_to (to_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample root member (password: member123)
INSERT INTO members (member_id, username, email, password, full_name, phone, package_id, status) VALUES
('MLM00001', 'rootuser', 'root@binarymlm.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Root Member', '9999999999', 4, 'active');
