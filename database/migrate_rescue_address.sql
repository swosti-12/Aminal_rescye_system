-- Cache reverse-geocoded addresses for rescue cases (optional, improves performance).
USE animal_rescue;

ALTER TABLE rescue_cases
    ADD COLUMN address VARCHAR(500) NULL DEFAULT NULL
    AFTER longitude;
