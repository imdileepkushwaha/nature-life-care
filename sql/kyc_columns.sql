USE binarymlm_db;

ALTER TABLE members
    ADD COLUMN kyc_status ENUM('pending','approved','rejected','not_submitted') DEFAULT 'not_submitted' AFTER status,
    ADD COLUMN kyc_id_type VARCHAR(50) NULL AFTER kyc_status,
    ADD COLUMN kyc_id_number VARCHAR(100) NULL AFTER kyc_id_type,
    ADD COLUMN kyc_document VARCHAR(255) NULL AFTER kyc_id_number,
    ADD COLUMN kyc_note TEXT NULL AFTER kyc_document,
    ADD COLUMN kyc_submitted_at DATETIME NULL AFTER kyc_note,
    ADD COLUMN kyc_reviewed_at DATETIME NULL AFTER kyc_submitted_at;
