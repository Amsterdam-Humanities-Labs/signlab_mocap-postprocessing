CREATE TABLE IF NOT EXISTS capture_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    capture_date DATE NOT NULL,
    assigned_by VARCHAR(255) NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_date (capture_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
