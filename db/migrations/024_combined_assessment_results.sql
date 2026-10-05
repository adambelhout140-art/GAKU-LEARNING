ALTER TABLE exam_attempts
    ADD COLUMN quiz_average_percentage DECIMAL(5,2) NULL AFTER percentage,
    ADD COLUMN quiz_count INT UNSIGNED NULL AFTER quiz_average_percentage,
    ADD COLUMN combined_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER quiz_count,
    ADD COLUMN overall_passed TINYINT(1) NOT NULL DEFAULT 0 AFTER combined_percentage;

UPDATE exam_attempts
SET combined_percentage = percentage,
    overall_passed = passed;
