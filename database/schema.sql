-- ============================================
-- DROPSHIPPING HEADLESS API DATABASE SCHEMA
-- ============================================

CREATE TABLE IF NOT EXISTS resellers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    api_key VARCHAR(64) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    role ENUM('reseller', 'admin', 'manager') DEFAULT 'reseller',
    tier ENUM('basic', 'silver', 'gold', 'platinum') DEFAULT 'basic',
    status ENUM('active', 'suspended', 'pending') DEFAULT 'pending',
    permissions JSON,
    commission_rate DECIMAL(5,2) DEFAULT 10.00,
    total_sales DECIMAL(10,2) DEFAULT 0.00,
    total_commission DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_email (email),
    INDEX idx_api_key (api_key),
    INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reseller_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL,
    wc_order_id INT NOT NULL,
    customer_name VARCHAR(255),
    customer_phone VARCHAR(20),
    total_amount DECIMAL(10,2) NOT NULL,
    commission DECIMAL(10,2) NOT NULL,
    status VARCHAR(50) DEFAULT 'pending',
    shipping_address JSON,
    tracking_number VARCHAR(100),
    status_note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE,
    INDEX idx_reseller (reseller_id),
    INDEX idx_wc_order (wc_order_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reseller_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(255),
    address TEXT,
    city VARCHAR(100) DEFAULT 'Dhaka',
    order_count INT DEFAULT 0,
    total_spent DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_reseller_phone (reseller_id, phone),
    INDEX idx_phone (phone)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS api_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT,
    endpoint VARCHAR(255),
    method VARCHAR(10),
    ip_address VARCHAR(45),
    user_agent TEXT,
    response_code INT,
    response_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reseller (reseller_id),
    INDEX idx_created (created_at),
    INDEX idx_ip (ip_address)
) ENGINE=InnoDB;

-- Default admin (password: changeme123)
INSERT INTO resellers (email, password_hash, api_key, name, role, tier, status, permissions) VALUES
('admin@ongonwear.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin_demo_key_change_immediately', 'System Admin', 'admin', 'platinum', 'active', '["admin:*"]')
ON DUPLICATE KEY UPDATE id=id;