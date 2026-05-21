-- Rescuer Directory module: add specialization column
USE animal_rescue;

ALTER TABLE rescuers
    ADD COLUMN IF NOT EXISTS specialization VARCHAR(120) NULL DEFAULT NULL
    AFTER longitude;

-- Seed some sample specializations for existing rescuers (optional, safe to re-run)
UPDATE rescuers SET specialization = 'General Rescue' WHERE specialization IS NULL;
