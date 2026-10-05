ALTER TABLE companies
    ADD COLUMN industry VARCHAR(150) NULL AFTER company_name,
    ADD COLUMN website VARCHAR(255) NULL AFTER email,
    ADD COLUMN responsible_name VARCHAR(150) NULL AFTER website,
    ADD COLUMN responsible_email VARCHAR(150) NULL AFTER responsible_name,
    ADD COLUMN responsible_phone VARCHAR(30) NULL AFTER responsible_email,
    ADD COLUMN director_name VARCHAR(150) NULL AFTER responsible_phone,
    ADD COLUMN director_email VARCHAR(150) NULL AFTER director_name,
    ADD COLUMN director_phone VARCHAR(30) NULL AFTER director_email;
