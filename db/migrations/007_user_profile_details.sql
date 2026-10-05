ALTER TABLE users
    ADD COLUMN date_of_birth DATE NULL AFTER last_name,
    ADD COLUMN cin VARCHAR(50) NULL AFTER date_of_birth,
    ADD COLUMN education VARCHAR(255) NULL AFTER cin,
    ADD COLUMN job_title VARCHAR(150) NULL AFTER education;
