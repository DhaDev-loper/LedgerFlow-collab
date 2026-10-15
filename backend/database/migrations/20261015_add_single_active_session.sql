ALTER TABLE users
    ADD COLUMN active_session_id VARCHAR(128) NULL AFTER suspension_level,
    ADD COLUMN active_session_last_seen DATETIME NULL AFTER active_session_id;
