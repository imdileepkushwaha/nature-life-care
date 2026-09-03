-- Utility Management tables for binarymlm_db
USE binarymlm_db;

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

-- Seed samples (ignore if already exist)
INSERT IGNORE INTO countries (id, name, code) VALUES (1, 'India', 'IN');
INSERT IGNORE INTO states (id, country_id, name) VALUES (1, 1, 'Maharashtra'), (2, 1, 'Delhi');
INSERT IGNORE INTO cities (id, state_id, name) VALUES (1, 1, 'Mumbai'), (2, 1, 'Pune'), (3, 2, 'New Delhi');
INSERT IGNORE INTO banks (id, name, short_code) VALUES (1, 'State Bank of India', 'SBI'), (2, 'HDFC Bank', 'HDFC');
INSERT IGNORE INTO deductions (name, deduction_type, value, description) VALUES
('TDS', 'percent', 5.00, 'Tax Deducted at Source'),
('Admin Charge', 'percent', 2.00, 'Admin processing fee');
INSERT IGNORE INTO plans (id, name, description) VALUES (1, 'Binary Plan', 'Standard binary MLM plan');
