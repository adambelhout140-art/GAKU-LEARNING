CREATE TABLE IF NOT EXISTS `lesson_material_views` (
  `user_id` INT UNSIGNED NOT NULL,
  `material_id` INT UNSIGNED NOT NULL,
  `page_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `opened_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_viewed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`, `material_id`),
  KEY `idx_lesson_material_views_material` (`material_id`),
  CONSTRAINT `fk_lesson_material_views_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lesson_material_views_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lesson_material_page_views` (
  `user_id` INT UNSIGNED NOT NULL,
  `material_id` INT UNSIGNED NOT NULL,
  `page_number` SMALLINT UNSIGNED NOT NULL,
  `viewed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `material_id`, `page_number`),
  KEY `idx_lesson_material_page_views_material` (`material_id`),
  CONSTRAINT `fk_lesson_material_page_views_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lesson_material_page_views_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
