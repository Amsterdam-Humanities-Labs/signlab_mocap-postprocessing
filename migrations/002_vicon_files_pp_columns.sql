ALTER TABLE vicon_files
    ADD COLUMN is_pp TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN filename_pp VARCHAR(512) DEFAULT NULL,
    ADD COLUMN datetime_pp DATETIME DEFAULT NULL,
    ADD COLUMN review_status ENUM('pending', 'approved', 'rejected', 'needs_review') NOT NULL DEFAULT 'pending';
