ALTER TABLE orders
    ADD COLUMN payment_phone VARCHAR(30) NULL,
    ADD COLUMN payment_prompt_status ENUM('requested','sent','expired','confirmed') NULL,
    ADD COLUMN payment_prompt_requested_at DATETIME NULL,
    ADD COLUMN payment_prompt_sent_at DATETIME NULL,
    ADD COLUMN payment_prompt_expires_at DATETIME NULL;