ALTER TABLE clients
    ADD COLUMN party_type ENUM('customer', 'vendor', 'both') NOT NULL DEFAULT 'customer'
    AFTER company_name;

UPDATE clients c
SET c.party_type = 'both'
WHERE EXISTS (
    SELECT 1
    FROM transaction_journals tj
    JOIN transactions t ON t.transaction_journal_id = tj.id
    JOIN accounts a ON a.id = t.account_id
    JOIN account_types at ON at.id = a.account_type_id
    WHERE tj.client_id = c.id
      AND tj.status != 'voided'
      AND LOWER(a.name) = 'accounts payable'
      AND at.name = 'Liability'
);

CREATE TABLE settlement_allocations (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    settlement_journal_id   BIGINT UNSIGNED NOT NULL,
    document_journal_id     BIGINT UNSIGNED NOT NULL,
    account_id              INT UNSIGNED NOT NULL,
    amount                  DECIMAL(14,2) NOT NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_settlement_allocations_settlement
        FOREIGN KEY (settlement_journal_id) REFERENCES transaction_journals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_allocations_document
        FOREIGN KEY (document_journal_id) REFERENCES transaction_journals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_allocations_account
        FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
    CONSTRAINT chk_settlement_allocations_amount CHECK (amount > 0),
    UNIQUE KEY uq_settlement_allocation (settlement_journal_id, document_journal_id, account_id),
    INDEX idx_settlement_allocation_document (document_journal_id, account_id)
) ENGINE=InnoDB;
