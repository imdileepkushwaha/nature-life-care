-- Product form wizard columns + gallery
USE binarymlm_db;

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS slug VARCHAR(180) NULL AFTER name,
  ADD COLUMN IF NOT EXISTS sku_mode ENUM('auto','manual') DEFAULT 'auto' AFTER sku,
  ADD COLUMN IF NOT EXISTS discount_percent DECIMAL(8,2) DEFAULT 0 AFTER mrp,
  ADD COLUMN IF NOT EXISTS thumbnail VARCHAR(255) NULL AFTER description,
  ADD COLUMN IF NOT EXISTS meta_title VARCHAR(180) NULL AFTER thumbnail,
  ADD COLUMN IF NOT EXISTS meta_description TEXT NULL AFTER meta_title,
  ADD COLUMN IF NOT EXISTS weight DECIMAL(10,2) DEFAULT 0 AFTER meta_description,
  ADD COLUMN IF NOT EXISTS length DECIMAL(10,2) DEFAULT 0 AFTER weight,
  ADD COLUMN IF NOT EXISTS width DECIMAL(10,2) DEFAULT 0 AFTER length,
  ADD COLUMN IF NOT EXISTS height DECIMAL(10,2) DEFAULT 0 AFTER width;

CREATE TABLE IF NOT EXISTS product_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE products
  ADD COLUMN offer_flash_text VARCHAR(180) NULL AFTER discount_percent,
  ADD COLUMN offer_countdown VARCHAR(20) NULL AFTER offer_flash_text,
  ADD COLUMN offer_bank_text VARCHAR(255) NULL AFTER offer_countdown;
