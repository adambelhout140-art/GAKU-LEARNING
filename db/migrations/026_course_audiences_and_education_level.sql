ALTER TABLE `courses`
  ADD COLUMN `education_level` ENUM(
    'primary',
    'middle_school',
    'high_school',
    'higher_education',
    'vocational_training',
    'other'
  ) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `level`;

CREATE TABLE IF NOT EXISTS `course_audiences` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_course_audiences_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `course_target_audiences` (
  `course_id` INT UNSIGNED NOT NULL,
  `audience_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`course_id`, `audience_id`),
  KEY `idx_course_target_audiences_audience` (`audience_id`),
  CONSTRAINT `fk_course_target_audiences_course`
    FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_course_target_audiences_audience`
    FOREIGN KEY (`audience_id`) REFERENCES `course_audiences` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `course_audiences` (`name`) VALUES
  ('Élève'),
  ('Étudiant'),
  ('Professionnel'),
  ('Tout public');
