-- Optional: cache human-readable address on rescue cases (reverse geocoding).
-- Safe to run on existing XAMPP databases. Coordinates remain the source of truth.
USE animal_rescue;

ALTER TABLE rescue_cases
    ADD COLUMN IF NOT EXISTS address VARCHAR(512) NULL DEFAULT NULL
    AFTER longitude;
