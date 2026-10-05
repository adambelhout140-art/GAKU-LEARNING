CREATE TABLE IF NOT EXISTS assessment_question_feedback (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_type ENUM('quiz', 'exam') NOT NULL,
    attempt_id INT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    purpose ENUM('encouragement', 'correction', 'explanation') NOT NULL DEFAULT 'explanation',
    visibility ENUM('student_parent', 'student_only') NOT NULL DEFAULT 'student_parent',
    comment TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assessment_question_feedback (assessment_type, attempt_id, question_id, teacher_id),
    KEY idx_assessment_question_feedback_attempt (assessment_type, attempt_id),
    CONSTRAINT fk_assessment_question_feedback_teacher FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
