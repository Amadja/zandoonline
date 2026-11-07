ALTER TABLE users
    MODIFY COLUMN role ENUM('customer','seller','delivery','admin') NOT NULL DEFAULT 'customer';

ALTER TABLE orders
    ADD COLUMN delivery_user_id BIGINT UNSIGNED NULL,
    ADD COLUMN delivery_assigned_at DATETIME NULL,
    ADD CONSTRAINT fk_order_delivery_user FOREIGN KEY (delivery_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD INDEX idx_orders_delivery (delivery_user_id, order_status),
    MODIFY COLUMN order_status ENUM('pending','processing','ready_for_pickup','assigned','accepted','picked_up','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'pending';