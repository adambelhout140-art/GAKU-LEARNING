ALTER TABLE users
    ADD COLUMN active_session_token CHAR(64) NULL AFTER last_login_at;
