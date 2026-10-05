CREATE TABLE IF NOT EXISTS exam_attempt_feedback (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_attempt_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    purpose ENUM('encouragement', 'correction', 'explanation') NOT NULL DEFAULT 'explanation',
    visibility ENUM('student_parent', 'student_only') NOT NULL DEFAULT 'student_parent',
    comment TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exam_attempt_feedback_teacher (exam_attempt_id, teacher_id),
    KEY idx_exam_attempt_feedback_attempt (exam_attempt_id),
    CONSTRAINT fk_exam_feedback_attempt FOREIGN KEY (exam_attempt_id) REFERENCES exam_attempts (id) ON DELETE CASCADE,
    CONSTRAINT fk_exam_feedback_teacher FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
