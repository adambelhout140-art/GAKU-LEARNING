ALTER TABLE contact_requests
    ADD COLUMN request_kind VARCHAR(30) NOT NULL DEFAULT 'contact' AFTER request_type,
    ADD COLUMN student_password_hash VARCHAR(255) NULL AFTER message,
    ADD COLUMN parent_first_name VARCHAR(100) NULL AFTER student_password_hash,
    ADD COLUMN parent_last_name VARCHAR(100) NULL AFTER parent_first_name,
    ADD COLUMN parent_email VARCHAR(150) NULL AFTER parent_last_name,
    ADD COLUMN parent_phone VARCHAR(30) NULL AFTER parent_email,
    ADD COLUMN parent_password_hash VARCHAR(255) NULL AFTER parent_phone,
    ADD COLUMN created_student_id INT UNSIGNED NULL AFTER parent_password_hash,
    ADD COLUMN created_parent_id INT UNSIGNED NULL AFTER created_student_id,
    ADD KEY idx_contact_request_kind (request_kind);
