ALTER TABLE users
  ADD COLUMN vehicle_type ENUM('bodaboda','car') NULL AFTER phone,
  ADD COLUMN vehicle_plate VARCHAR(30) NULL AFTER vehicle_type,
  ADD COLUMN profile_photo VARCHAR(255) NULL,
  ADD COLUMN government_id_photo VARCHAR(255) NULL,
  ADD COLUMN drivers_license_photo VARCHAR(255) NULL;
