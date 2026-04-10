ALTER TABLE download_logs
    MODIFY download_type ENUM('original', 'processed', 'bulk', 'upload', 'mark_processed') NOT NULL DEFAULT 'original';
