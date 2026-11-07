CREATE DATABASE IF NOT EXISTS zandoonline
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE zandoonline;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(30) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer','seller','delivery','admin') NOT NULL DEFAULT 'customer',
    status ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
    email_verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_phone (phone),
    INDEX idx_users_role_status (role, status)
) ENGINE=InnoDB;

CREATE TABLE seller_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    business_name VARCHAR(150) NOT NULL,
    business_type VARCHAR(80) NULL,
    phone VARCHAR(30) NULL,
    address TEXT NULL,
    verification_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_seller_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE stores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_user_id BIGINT UNSIGNED NOT NULL,
    store_name VARCHAR(150) NOT NULL,
    slug VARCHAR(180) NULL UNIQUE,
    description TEXT NULL,
    logo_path VARCHAR(255) NULL,
    status ENUM('pending','active','suspended','closed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_store_seller FOREIGN KEY (seller_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_store_seller_status (seller_user_id, status)
) ENGINE=InnoDB;
