-- Stores only a SHA-256 hash of the registered browser/device token.
ALTER TABLE users
    ADD COLUMN registered_device_token CHAR(64) NULL AFTER active_session_token;
