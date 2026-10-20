ALTER TABLE `courses`
  MODIFY COLUMN `education_level` ENUM(
    'preschool',
    'primary',
    'middle_school',
    'high_school',
    'post_secondary_non_tertiary',
    'higher_education',
    'vocational_training',
    'other'
  ) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `level`;
