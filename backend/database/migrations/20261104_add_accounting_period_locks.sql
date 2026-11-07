ALTER TABLE transaction_journals
    ADD COLUMN correction_of_journal_id BIGINT UNSIGNED NULL AFTER is_reconciled,
    ADD CONSTRAINT fk_journals_correction
        FOREIGN KEY (correction_of_journal_id) REFERENCES transaction_journals(id) ON DELETE RESTRICT,
    ADD INDEX idx_journals_correction (correction_of_journal_id);

CREATE TABLE accounting_periods (
    period_type     ENUM('month','year') NOT NULL,
    period_start    DATE NOT NULL,
    period_end      DATE NOT NULL,
    closed_by       INT UNSIGNED NOT NULL,
    closed_at       DATETIME NOT NULL,
    close_reason    TEXT NOT NULL,
    reopened_by     INT UNSIGNED NULL,
    reopened_at     DATETIME NULL,
    reopen_reason   TEXT NULL,
    PRIMARY KEY (period_type, period_start),
    CONSTRAINT fk_accounting_periods_closed_by
        FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_accounting_periods_reopened_by
        FOREIGN KEY (reopened_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_accounting_periods_status (closed_at, reopened_at, period_start)
) ENGINE=InnoDB;
