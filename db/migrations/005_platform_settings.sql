CREATE TABLE IF NOT EXISTS platform_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_settings (setting_key, setting_value) VALUES
    ('platform_name', 'GAKU LEARNING'),
    ('support_email', ''),
    ('certificate_prefix', 'FP'),
    ('allow_registration', '1')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
