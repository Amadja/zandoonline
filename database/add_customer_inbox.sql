ALTER TABLE orders
    ADD COLUMN courier_name VARCHAR(120) NULL,
    ADD COLUMN courier_phone VARCHAR(30) NULL,
    MODIFY COLUMN order_status ENUM('pending','processing','ready_for_pickup','out_for_delivery','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending';

CREATE TABLE customer_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    notification_type ENUM('order_received','order_update','payment_update') NOT NULL,
    title VARCHAR(160) NOT NULL,
    message TEXT NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_notifications_inbox (user_id, read_at, created_at)
) ENGINE=InnoDB;