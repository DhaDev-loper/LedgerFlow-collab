ALTER TABLE users
    ADD COLUMN pin_failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER pin_hash,
    ADD COLUMN pin_locked_until DATETIME NULL AFTER pin_failed_attempts;
