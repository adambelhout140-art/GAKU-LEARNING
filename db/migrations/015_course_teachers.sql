CREATE TABLE IF NOT EXISTS course_teachers (
    course_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (course_id, teacher_id),
    KEY idx_course_teachers_teacher (teacher_id),
    CONSTRAINT fk_course_teachers_course
        FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_course_teachers_teacher
        FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO course_teachers (course_id, teacher_id)
SELECT id, teacher_id
FROM courses
WHERE teacher_id IS NOT NULL;
