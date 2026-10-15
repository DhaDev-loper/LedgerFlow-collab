ALTER TABLE accounts
    ADD COLUMN classification ENUM('current', 'non_current') NOT NULL DEFAULT 'current'
    AFTER account_type_id;
