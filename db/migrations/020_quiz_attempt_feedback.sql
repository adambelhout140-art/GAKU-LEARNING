CREATE TABLE IF NOT EXISTS quiz_attempt_feedback (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    quiz_attempt_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    purpose ENUM('encouragement', 'correction', 'explanation') NOT NULL DEFAULT 'explanation',
    visibility ENUM('student_parent', 'student_only') NOT NULL DEFAULT 'student_parent',
    comment TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_quiz_attempt_feedback_teacher (quiz_attempt_id, teacher_id),
    KEY idx_quiz_attempt_feedback_attempt (quiz_attempt_id),
    CONSTRAINT fk_feedback_attempt FOREIGN KEY (quiz_attempt_id) REFERENCES quiz_attempts (id) ON DELETE CASCADE,
    CONSTRAINT fk_feedback_teacher FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
