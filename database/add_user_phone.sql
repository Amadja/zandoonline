ALTER TABLE users
    ADD COLUMN phone VARCHAR(30) NULL AFTER email,
    ADD UNIQUE KEY uq_users_phone (phone);