USE zandoonline;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_user_id BIGINT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(210) NOT NULL UNIQUE,
    description TEXT NULL,
    category VARCHAR(100) NOT NULL,
    price INT UNSIGNED NOT NULL,
    stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    image_url VARCHAR(500) NOT NULL,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_seller FOREIGN KEY (seller_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_products_status_category (status, category)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(32) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_email VARCHAR(190) NOT NULL,
    customer_phone VARCHAR(30) NOT NULL,
    delivery_address VARCHAR(255) NOT NULL,
    delivery_district VARCHAR(100) NOT NULL,
    delivery_notes VARCHAR(500) NULL,
    delivery_latitude DECIMAL(10,7) NULL,
    delivery_longitude DECIMAL(10,7) NULL,
    google_maps_url VARCHAR(1000) NULL,
    courier_name VARCHAR(120) NULL,
    courier_phone VARCHAR(30) NULL,
    delivery_user_id BIGINT UNSIGNED NULL,
    delivery_assigned_at DATETIME NULL,
    delivery_code_hash VARCHAR(255) NULL,
    delivery_code_expires_at DATETIME NULL,
    delivery_code_requested_at DATETIME NULL,
    delivery_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    delivered_at DATETIME NULL,
    payment_method ENUM('cash_on_delivery','mobile_money') NOT NULL,
    payment_provider ENUM('mtn','airtel') NULL,
    payment_phone VARCHAR(30) NULL,
    payment_status ENUM('unpaid','pending','paid','failed') NOT NULL DEFAULT 'unpaid',
    payment_prompt_status ENUM('requested','sent','expired','confirmed') NULL,
    payment_prompt_requested_at DATETIME NULL,
    payment_prompt_sent_at DATETIME NULL,
    payment_prompt_expires_at DATETIME NULL,
    order_status ENUM('pending','processing','ready_for_pickup','assigned','accepted','picked_up','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'pending',
    subtotal INT UNSIGNED NOT NULL,
    delivery_fee INT UNSIGNED NOT NULL,
    total INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_delivery_user FOREIGN KEY (delivery_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_orders_delivery (delivery_user_id, order_status),
    INDEX idx_orders_email_created (customer_email, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_notifications (
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

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    product_name VARCHAR(190) NOT NULL,
    unit_price INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    INDEX idx_order_items_product (product_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO products (name, slug, description, category, price, stock_quantity, image_url) VALUES
('Wireless over-ear headphones', 'wireless-over-ear-headphones', 'Comfortable wireless headphones for everyday listening.', 'Electronics', 189000, 20, 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=700&q=80'),
('Everyday running sneakers', 'everyday-running-sneakers', 'Lightweight sneakers for daily walks and runs.', 'Fashion', 165000, 18, 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=700&q=80'),
('Minimal analog wristwatch', 'minimal-analog-wristwatch', 'A clean everyday watch with a simple dial.', 'Fashion', 142000, 12, 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=700&q=80'),
('Mirrorless digital camera', 'mirrorless-digital-camera', 'A compact camera for travel and everyday photography.', 'Electronics', 890000, 6, 'https://images.unsplash.com/photo-1516035069371-29a1b244cc32?auto=format&fit=crop&w=700&q=80'),
('Canvas everyday backpack', 'canvas-everyday-backpack', 'A roomy canvas backpack for work and weekends.', 'Fashion', 98000, 25, 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=700&q=80'),
('Portable Bluetooth speaker', 'portable-bluetooth-speaker', 'A compact wireless speaker for home or travel.', 'Home & Living', 119000, 16, 'https://images.unsplash.com/photo-1608043152269-423dbba4e7e1?auto=format&fit=crop&w=700&q=80'),
('Daily hydration face serum', 'daily-hydration-face-serum', 'A lightweight serum for a simple daily skincare routine.', 'Beauty', 67000, 30, 'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?auto=format&fit=crop&w=700&q=80'),
('Soft glow bedside lamp', 'soft-glow-bedside-lamp', 'A warm bedside lamp for a calm evening atmosphere.', 'Home & Living', 154000, 10, 'https://images.unsplash.com/photo-1507473885765-e6ed057f782c?auto=format&fit=crop&w=700&q=80');