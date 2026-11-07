ALTER TABLE orders
    ADD COLUMN delivery_code_hash VARCHAR(255) NULL,
    ADD COLUMN delivery_code_expires_at DATETIME NULL,
    ADD COLUMN delivery_code_requested_at DATETIME NULL,
    ADD COLUMN delivery_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN delivered_at DATETIME NULL;