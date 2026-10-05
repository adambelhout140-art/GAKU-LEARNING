CREATE TABLE IF NOT EXISTS homepage_videos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    video_url VARCHAR(500) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_homepage_videos_active_position (is_active, position, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO homepage_videos (title, description, video_url, position, is_active)
SELECT 'Présentation de GAKU LEARNING',
       'Découvrez notre plateforme et tout ce qu’elle peut vous offrir.',
       'https://www.youtube.com/watch?v=gl6r8v9zobU',
       0,
       1
WHERE NOT EXISTS (SELECT 1 FROM homepage_videos);
