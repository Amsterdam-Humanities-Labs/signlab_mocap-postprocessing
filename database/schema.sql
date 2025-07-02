-- Database schema for motion capture files
-- This script adds the required fields to the existing mocap_files table

ALTER TABLE mocap_files 
ADD COLUMN is_pp TINYINT(1) DEFAULT 0 COMMENT 'Post-processing status: 0 = not processed, 1 = processed',
ADD COLUMN filename_pp VARCHAR(255) DEFAULT NULL COMMENT 'Filename of the post-processed file',
ADD INDEX idx_is_pp (is_pp),
ADD INDEX idx_datetime (datetime);

-- Create table if it doesn't exist (for testing purposes)
CREATE TABLE IF NOT EXISTS mocap_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    glos VARCHAR(255) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    datetime DATETIME NOT NULL,
    is_pp TINYINT(1) DEFAULT 0,
    filename_pp VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_is_pp (is_pp),
    INDEX idx_datetime (datetime),
    INDEX idx_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;