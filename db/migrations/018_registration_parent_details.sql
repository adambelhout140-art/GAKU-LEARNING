ALTER TABLE contact_requests
    ADD COLUMN parent_date_of_birth DATE NULL AFTER parent_last_name,
    ADD COLUMN parent_cin VARCHAR(50) NULL AFTER parent_date_of_birth;
