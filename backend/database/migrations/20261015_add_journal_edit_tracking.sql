ALTER TABLE transaction_journals
    ADD COLUMN is_edited TINYINT(1) NOT NULL DEFAULT 0 AFTER is_reconciled,
    ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at;
