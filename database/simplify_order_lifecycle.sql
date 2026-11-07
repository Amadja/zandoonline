UPDATE orders SET order_status = 'out_for_delivery' WHERE order_status = 'shipped';

ALTER TABLE orders
    MODIFY COLUMN order_status ENUM('pending','processing','ready_for_pickup','assigned','accepted','picked_up','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'pending';