ALTER TABLE users
    MODIFY role ENUM('student', 'parent', 'company_manager', 'teacher', 'admin')
    NOT NULL DEFAULT 'student';

CREATE TABLE IF NOT EXISTS parent_student_links (
    parent_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (parent_id, student_id),
    KEY idx_parent_student_student (student_id),
    CONSTRAINT fk_parent_student_parent
        FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_parent_student_student
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
