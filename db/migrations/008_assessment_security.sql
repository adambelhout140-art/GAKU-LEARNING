CREATE TABLE IF NOT EXISTS assessment_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    assessment_type ENUM('quiz', 'exam') NOT NULL,
    assessment_id INT UNSIGNED NOT NULL,
    session_token CHAR(64) NOT NULL,
    draft_answers JSON NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assessment_session (user_id, assessment_type, assessment_id),
    KEY idx_assessment_session_activity (last_seen_at),
    CONSTRAINT fk_assessment_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
