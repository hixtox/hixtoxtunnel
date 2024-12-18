-- Drop existing tables if they exist
DROP TABLE IF EXISTS tunnel_metrics_archive;
DROP TABLE IF EXISTS tunnel_metrics;
DROP TABLE IF EXISTS tunnel_logs;
DROP TABLE IF EXISTS api_tokens;
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS tunnels;
DROP TABLE IF EXISTS customer_settings;
DROP TABLE IF EXISTS admin_settings;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS admins;

-- Create admins table
CREATE TABLE admins (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    role ENUM('super_admin', 'admin') DEFAULT 'admin',
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_admin_email (email),
    INDEX idx_admin_status (status)
) ENGINE=InnoDB;

-- Create customers table
CREATE TABLE customers (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    company VARCHAR(100),
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    plan ENUM('free', 'basic', 'pro', 'enterprise') DEFAULT 'free',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer_email (email),
    INDEX idx_customer_status (status),
    INDEX idx_customer_plan (plan)
) ENGINE=InnoDB;

-- Create customer settings table
CREATE TABLE customer_settings (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    customer_id INT UNSIGNED NOT NULL,
    max_tunnels INT UNSIGNED DEFAULT 5,
    max_bandwidth BIGINT UNSIGNED DEFAULT 1073741824, -- 1GB in bytes
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_customer_settings (customer_id)
) ENGINE=InnoDB;

-- Create admin settings table
CREATE TABLE admin_settings (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    admin_id INT UNSIGNED NOT NULL,
    setting_key VARCHAR(50) NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    UNIQUE KEY unique_admin_setting (admin_id, setting_key)
) ENGINE=InnoDB;

-- Create tunnels table
CREATE TABLE tunnels (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    customer_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    protocol ENUM('http', 'tcp') NOT NULL,
    local_port INT UNSIGNED NOT NULL,
    remote_port INT UNSIGNED NOT NULL,
    local_host VARCHAR(255) DEFAULT 'localhost',
    status ENUM('active', 'inactive', 'error') DEFAULT 'inactive',
    last_connected TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_tunnel_customer (customer_id),
    INDEX idx_tunnel_status (status),
    INDEX idx_tunnel_ports (local_port, remote_port)
) ENGINE=InnoDB;

-- Create tunnel metrics table
CREATE TABLE tunnel_metrics (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tunnel_id INT UNSIGNED NOT NULL,
    bytes_in BIGINT UNSIGNED DEFAULT 0,
    bytes_out BIGINT UNSIGNED DEFAULT 0,
    requests INT UNSIGNED DEFAULT 0,
    errors INT UNSIGNED DEFAULT 0,
    avg_response_time FLOAT DEFAULT 0,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tunnel_id) REFERENCES tunnels(id) ON DELETE CASCADE,
    INDEX idx_metrics_tunnel (tunnel_id),
    INDEX idx_metrics_timestamp (timestamp)
) ENGINE=InnoDB;

-- Create tunnel metrics archive table
CREATE TABLE tunnel_metrics_archive (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tunnel_id INT UNSIGNED NOT NULL,
    bytes_in BIGINT UNSIGNED DEFAULT 0,
    bytes_out BIGINT UNSIGNED DEFAULT 0,
    requests INT UNSIGNED DEFAULT 0,
    errors INT UNSIGNED DEFAULT 0,
    avg_response_time FLOAT DEFAULT 0,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Create tunnel logs table
CREATE TABLE tunnel_logs (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tunnel_id INT UNSIGNED NOT NULL,
    event_type ENUM('connect', 'disconnect', 'error', 'info') NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tunnel_id) REFERENCES tunnels(id) ON DELETE CASCADE,
    INDEX idx_logs_tunnel (tunnel_id),
    INDEX idx_logs_event (event_type),
    INDEX idx_logs_timestamp (created_at)
) ENGINE=InnoDB;

-- Create API tokens table
CREATE TABLE api_tokens (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    customer_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    token VARCHAR(255) NOT NULL,
    last_used TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_unique_token (token),
    INDEX idx_token_customer (customer_id),
    INDEX idx_token_expiry (expires_at)
) ENGINE=InnoDB;

-- Create user sessions table
CREATE TABLE user_sessions (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    user_type ENUM('admin', 'customer') NOT NULL,
    session_id VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session_user (user_id, user_type),
    INDEX idx_session_activity (last_activity)
) ENGINE=InnoDB;

-- Create password resets table
CREATE TABLE password_resets (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(100) NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_reset_email (email),
    INDEX idx_reset_token (token),
    INDEX idx_reset_expiry (expires_at)
) ENGINE=InnoDB;

-- Create rate limits table
CREATE TABLE rate_limits (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    requests INT UNSIGNED DEFAULT 1,
    window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ratelimit_ip (ip_address),
    INDEX idx_ratelimit_window (window_start)
) ENGINE=InnoDB;

-- Insert default admin user
INSERT INTO admins (username, email, password, role, status)
VALUES ('admin', 'admin@hixtunnel.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 'active');
-- Default password: password

-- Create metrics archival procedure
DELIMITER //
CREATE PROCEDURE archive_old_metrics()
BEGIN
    DECLARE cutoff_date TIMESTAMP;
    SET cutoff_date = DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- Archive metrics older than 30 days
    INSERT INTO tunnel_metrics_archive
    SELECT * FROM tunnel_metrics
    WHERE timestamp < cutoff_date;
    
    -- Delete archived metrics
    DELETE FROM tunnel_metrics
    WHERE timestamp < cutoff_date;
END //
DELIMITER ;

-- Create event to run archival procedure monthly
CREATE EVENT archive_metrics_monthly
ON SCHEDULE EVERY 1 MONTH
DO CALL archive_old_metrics();
