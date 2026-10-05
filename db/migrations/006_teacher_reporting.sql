-- Teacher reporting: cumulative active time spent on each lesson.
ALTER TABLE lesson_progress
    ADD COLUMN total_time_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_position;
