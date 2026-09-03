USE binarymlm_db;

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

INSERT INTO settings (setting_key, setting_value) VALUES
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
('contact_form_notify_email', 'support@binarymlm.com')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
