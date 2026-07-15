-- My Furry Companion Dashboard - Database Schema
-- Import via SSH: mysql -u USER -p DBNAME < schema.sql

CREATE TABLE IF NOT EXISTS fc_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin', 'admin') NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL,
  failed_attempts INT DEFAULT 0,
  locked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fc_products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  handle VARCHAR(255) UNIQUE NOT NULL,
  title VARCHAR(500) NOT NULL,
  description LONGTEXT,
  retail_price DECIMAL(10,2) DEFAULT 0,
  wholesale_price DECIMAL(10,2) DEFAULT 0,
  quantity INT DEFAULT 0,
  promoted ENUM('Listings', 'Off-site') DEFAULT 'Listings',
  status ENUM('Active', 'Inactive', 'Draft', 'Scheduled') DEFAULT 'Active',
  image_src TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_handle (handle),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fc_variants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  sku VARCHAR(100),
  option1_name VARCHAR(100),
  option1_value VARCHAR(200),
  option2_name VARCHAR(100),
  option2_value VARCHAR(200),
  grams INT DEFAULT 0,
  price DECIMAL(10,2) DEFAULT 0,
  compare_at_price DECIMAL(10,2) DEFAULT 0,
  cost DECIMAL(10,2) DEFAULT 0,
  barcode VARCHAR(100),
  image_src TEXT,
  image_position INT DEFAULT 0,
  variant_image TEXT,
  FOREIGN KEY (product_id) REFERENCES fc_products(id) ON DELETE CASCADE,
  INDEX idx_product (product_id),
  INDEX idx_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fc_upload_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  filename VARCHAR(255),
  rows_processed INT DEFAULT 0,
  rows_added INT DEFAULT 0,
  rows_updated INT DEFAULT 0,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES fc_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
