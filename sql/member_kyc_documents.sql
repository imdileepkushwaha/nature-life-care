-- Per-document KYC (PAN / Bank / Aadhaar / UPI)
CREATE TABLE IF NOT EXISTS member_kyc_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    doc_type ENUM('pan','bank','aadhar','upi') NOT NULL,
    status ENUM('not_submitted','pending','approved','rejected') NOT NULL DEFAULT 'not_submitted',
    -- PAN
    pan_number VARCHAR(20) NULL,
    pan_name VARCHAR(100) NULL,
    -- Bank
    account_holder VARCHAR(100) NULL,
    account_number VARCHAR(50) NULL,
    ifsc_code VARCHAR(20) NULL,
    bank_name VARCHAR(100) NULL,
    branch_name VARCHAR(100) NULL,
    -- Aadhaar / Address
    aadhar_number VARCHAR(20) NULL,
    address_line TEXT NULL,
    -- UPI
    upi_id VARCHAR(100) NULL,
    upi_name VARCHAR(50) NULL,
    -- File + review
    document_file VARCHAR(255) NULL,
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

-- Extra Aadhaar / UPI columns (safe if table already exists)
ALTER TABLE member_kyc_documents
    MODIFY COLUMN doc_type ENUM('pan','bank','aadhar','upi') NOT NULL,
    ADD COLUMN IF NOT EXISTS document_back VARCHAR(255) NULL AFTER document_file,
    ADD COLUMN IF NOT EXISTS country VARCHAR(100) NULL AFTER address_line,
    ADD COLUMN IF NOT EXISTS state VARCHAR(100) NULL AFTER country,
    ADD COLUMN IF NOT EXISTS city VARCHAR(100) NULL AFTER state,
    ADD COLUMN IF NOT EXISTS area VARCHAR(100) NULL AFTER city,
    ADD COLUMN IF NOT EXISTS pincode VARCHAR(20) NULL AFTER area,
    ADD COLUMN IF NOT EXISTS upi_id VARCHAR(100) NULL AFTER pincode,
    ADD COLUMN IF NOT EXISTS upi_name VARCHAR(50) NULL AFTER upi_id;

-- Multiple UPI IDs per member
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
