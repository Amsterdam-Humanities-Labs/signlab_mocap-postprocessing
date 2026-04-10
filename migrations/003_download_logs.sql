CREATE TABLE IF NOT EXISTS download_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    file_id BIGINT NOT NULL,
    filename VARCHAR(512) NOT NULL,
    download_type ENUM('original', 'processed', 'bulk') NOT NULL DEFAULT 'original',
    downloaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_downloaded_at (downloaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
