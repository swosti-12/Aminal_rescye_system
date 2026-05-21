-- Case lifecycle: archive fields, extended statuses.
-- Run once in phpMyAdmin (animal_rescue). Skip any line that errors "Duplicate column".

USE animal_rescue;

ALTER TABLE rescue_cases
    MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending';

ALTER TABLE rescue_cases ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE rescue_cases ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE rescue_cases ADD COLUMN marked_as_read TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE rescue_cases ADD COLUMN previous_status VARCHAR(32) NULL DEFAULT NULL;

ALTER TABLE rescue_requests ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE rescue_requests ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE rescue_requests ADD COLUMN marked_as_read TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE rescue_cases ADD INDEX idx_rescue_cases_active (is_archived, status);
ALTER TABLE rescue_cases ADD INDEX idx_rescue_cases_archived_at (archived_at);
ALTER TABLE rescue_requests ADD INDEX idx_rescue_requests_archived (is_archived);

-- Archive legacy terminal cases
UPDATE rescue_cases
SET is_archived = 1,
    archived_at = COALESCE(archived_at, resolved_at, created_at),
    marked_as_read = 1,
    previous_status = COALESCE(previous_status, status),
    status = CASE status
        WHEN 'resolved' THEN 'completed'
        ELSE status
    END
WHERE status IN ('resolved', 'rejected');

UPDATE rescue_cases
SET status = 'in_progress'
WHERE status = 'accepted' AND is_archived = 0;

UPDATE rescue_requests rr
INNER JOIN rescue_cases c ON c.id = rr.case_id
SET rr.is_archived = c.is_archived,
    rr.archived_at = c.archived_at,
    rr.marked_as_read = c.marked_as_read
WHERE c.is_archived = 1;
