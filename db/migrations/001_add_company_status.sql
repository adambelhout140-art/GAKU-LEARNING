-- ! Add a soft-active status to companies.
-- * Run this once against the formation_platform database before using the status controls.
ALTER TABLE companies
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER email;