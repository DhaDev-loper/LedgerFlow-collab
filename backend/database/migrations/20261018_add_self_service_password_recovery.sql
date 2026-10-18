ALTER TABLE users
    ADD COLUMN reset_token_hash VARCHAR(64) NULL AFTER locked_until,
    ADD COLUMN reset_expires_at DATETIME NULL AFTER reset_token_hash,
    ADD UNIQUE KEY uq_users_reset_token_hash (reset_token_hash);
