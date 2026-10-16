-- Complete fresh-install schema. Migration changes are already incorporated here.
CREATE DATABASE IF NOT EXISTS wrs_accounting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wrs_accounting;

CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(100)        NOT NULL,
    email           VARCHAR(150)        NOT NULL,
    password_hash   VARCHAR(255)        NOT NULL,
    password_setup_required TINYINT(1)  NOT NULL DEFAULT 0,
    role            ENUM('owner','admin','accountant','staff') NOT NULL DEFAULT 'staff',
    pin_hash        VARCHAR(255)        NULL,
    is_active       TINYINT(1)          NOT NULL DEFAULT 1,
    failed_attempts TINYINT UNSIGNED    NOT NULL DEFAULT 0,
    locked_until    DATETIME            NULL,
    suspension_level TINYINT UNSIGNED   NOT NULL DEFAULT 0,
    active_session_id VARCHAR(128)      NULL,
    active_session_last_seen DATETIME   NULL,
    deleted_at      DATETIME            NULL,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE user_setup_invitations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    token_hash      CHAR(64)        NOT NULL,
    expires_at      DATETIME        NOT NULL,
    used_at         DATETIME        NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_setup_invitations_token (token_hash),
    INDEX idx_user_setup_invitations_user (user_id, used_at),
    CONSTRAINT fk_user_setup_invitations_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id   INT UNSIGNED NULL,
    details     TEXT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_user_created (user_id, created_at),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_action_created (action, created_at)
) ENGINE=InnoDB;

CREATE TABLE password_resets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    otp_code        VARCHAR(6)      NOT NULL,
    expires_at      DATETIME        NOT NULL,
    is_used         TINYINT(1)      NOT NULL DEFAULT 0,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_password_resets_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE account_types (
    id              TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(50)         NOT NULL,
    normal_balance  ENUM('debit','credit') NOT NULL,
    UNIQUE KEY uq_account_types_name (name)
) ENGINE=InnoDB;

INSERT INTO account_types (name, normal_balance) VALUES
    ('Asset',      'debit'),
    ('Liability',  'credit'),
    ('Equity',     'credit'),
    ('Revenue',    'credit'),
    ('Expense',    'debit');

CREATE TABLE accounts (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_type_id         TINYINT UNSIGNED NOT NULL,
    classification          ENUM('current', 'non_current') NOT NULL DEFAULT 'current',
    code                    VARCHAR(20)     NULL,
    name                    VARCHAR(100)    NOT NULL,
    opening_balance         DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
    opening_balance_date    DATE            NULL,
    current_balance         DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
    currency_code           CHAR(3)         NOT NULL DEFAULT 'PHP',
    is_active               TINYINT(1)      NOT NULL DEFAULT 1,
    is_cash_account         TINYINT(1)      NOT NULL DEFAULT 0,
    notes                   TEXT            NULL,
    created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_accounts_type
        FOREIGN KEY (account_type_id) REFERENCES account_types(id) ON DELETE RESTRICT,
    INDEX idx_accounts_type (account_type_id),
    INDEX idx_accounts_active (is_active)
) ENGINE=InnoDB;

INSERT INTO accounts (account_type_id, name, opening_balance, opening_balance_date, current_balance, is_cash_account) VALUES
    (1, 'Cash in bank',        0.00, CURDATE(), 0.00, 1),
    (1, 'Cash on hand',        0.00, CURDATE(), 0.00, 1),
    (1, 'E-Wallet',            0.00, CURDATE(), 0.00, 1),
    (1, 'Accounts Receivable', 0.00, NULL,      0.00, 0),
    (2, 'Accounts Payable',    0.00, NULL,      0.00, 0),
    (2, 'Client Ad Budget Liability', 0.00, NULL, 0.00, 0),
    (4, 'Sales Revenue',       0.00, NULL,      0.00, 0),
    (4, 'Service Revenue',     0.00, NULL,      0.00, 0),
    (5, 'Utilities',           0.00, NULL,      0.00, 0),
    (5, 'Rent',                0.00, NULL,      0.00, 0),
    (5, 'Salaries & Wages',    0.00, NULL,      0.00, 0);

CREATE TABLE transaction_types (
    id      TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(30) NOT NULL,
    UNIQUE KEY uq_transaction_types_name (name)
) ENGINE=InnoDB;

INSERT INTO transaction_types (name) VALUES
    ('Cash Outflow'), ('Cash Inflow'), ('Transfer'), ('Opening Balance');

CREATE TABLE clients (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    company_name    VARCHAR(255) NULL,
    party_type      ENUM('customer','vendor','both') NOT NULL DEFAULT 'customer',
    email           VARCHAR(255) NULL,
    phone           VARCHAR(50)  NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_clients_status (status),
    INDEX idx_clients_name (name)
) ENGINE=InnoDB;

CREATE TABLE transaction_journals (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_type_id TINYINT UNSIGNED NOT NULL,
    description         VARCHAR(255)    NOT NULL,
    transaction_date    DATE            NOT NULL,
    reference_no        VARCHAR(50)     NULL,
    client_id           INT UNSIGNED    NULL,
    due_date            DATE            NULL,
    total_amount        DECIMAL(14,2)   NOT NULL,
    status              ENUM('posted','deletion_pending','voided') NOT NULL DEFAULT 'posted',
    is_reconciled       TINYINT(1)      NOT NULL DEFAULT 0,
    is_edited           TINYINT(1)      NOT NULL DEFAULT 0,
    created_by          INT UNSIGNED    NOT NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    edited_at           TIMESTAMP       NULL DEFAULT NULL,
    CONSTRAINT fk_journals_type
        FOREIGN KEY (transaction_type_id) REFERENCES transaction_types(id) ON DELETE RESTRICT,
    CONSTRAINT fk_journals_user
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_journals_client
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    INDEX idx_journals_date (transaction_date),
    INDEX idx_journals_type (transaction_type_id),
    INDEX idx_journals_client (client_id)
) ENGINE=InnoDB;

CREATE TABLE deletion_requests (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_journal_id  BIGINT UNSIGNED NOT NULL,
    requested_by            INT UNSIGNED NOT NULL,
    reason                  TEXT NOT NULL,
    status                  ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    owner_note              TEXT NULL,
    reviewed_by             INT UNSIGNED NULL,
    reviewed_at             DATETIME NULL,
    journal_snapshot        JSON NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_del_req_journal
        FOREIGN KEY (transaction_journal_id) REFERENCES transaction_journals(id) ON DELETE CASCADE,
    CONSTRAINT fk_del_req_user
        FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_del_req_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_del_req_journal (transaction_journal_id),
    INDEX idx_del_req_status (status)
) ENGINE=InnoDB;

CREATE TABLE transactions (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_journal_id  BIGINT UNSIGNED NOT NULL,
    account_id              INT UNSIGNED    NOT NULL,
    entry_type              ENUM('debit','credit') NOT NULL,
    amount                  DECIMAL(14,2)   NOT NULL,
    description             VARCHAR(255)    NULL,
    created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transactions_journal
        FOREIGN KEY (transaction_journal_id) REFERENCES transaction_journals(id) ON DELETE CASCADE,
    CONSTRAINT fk_transactions_account
        FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
    CONSTRAINT chk_transactions_amount_positive CHECK (amount > 0),
    INDEX idx_transactions_journal (transaction_journal_id),
    INDEX idx_transactions_account (account_id)
) ENGINE=InnoDB;

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

CREATE TABLE categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)    NOT NULL,
    account_type_id TINYINT UNSIGNED NULL,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_name (name),
    CONSTRAINT fk_categories_type
        FOREIGN KEY (account_type_id) REFERENCES account_types(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO categories (name, account_type_id) VALUES
    ('Sales Revenue',        4),
    ('Service Revenue',      4),
    ('Other Income',         4),
    ('Cost of Goods Sold',   5),
    ('Rent',                 5),
    ('Utilities',            5),
    ('Salaries & Wages',     5),
    ('Office Supplies',      5),
    ('Marketing & Advertising', 5),
    ('Transportation & Fuel', 5),
    ('Repairs & Maintenance', 5),
    ('Insurance',            5),
    ('Professional Fees',    5),
    ('Miscellaneous Expense', 5);

CREATE TABLE journal_category (
    transaction_journal_id BIGINT UNSIGNED NOT NULL,
    category_id             INT UNSIGNED    NOT NULL,
    PRIMARY KEY (transaction_journal_id, category_id),
    CONSTRAINT fk_jc_journal
        FOREIGN KEY (transaction_journal_id) REFERENCES transaction_journals(id) ON DELETE CASCADE,
    CONSTRAINT fk_jc_category
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE business_settings (
    id              TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    business_name   VARCHAR(150)    NOT NULL DEFAULT 'My Business',
    address         VARCHAR(255)    NULL,
    contact_number  VARCHAR(30)     NULL,
    logo_path       VARCHAR(255)    NULL,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_single_row CHECK (id = 1)
) ENGINE=InnoDB;

INSERT INTO business_settings (id, business_name) VALUES (1, 'My Business');

CREATE TABLE pending_receipt_scans (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token           VARCHAR(64) NOT NULL,
    status          ENUM('pending','completed') NULL DEFAULT 'pending',
    image_path      VARCHAR(255) NULL,
    vendor          VARCHAR(150) NULL,
    reference_no    VARCHAR(50) NULL,
    amount          DECIMAL(10,2) NULL DEFAULT 0.00,
    created_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    extracted_data  TEXT NULL,
    UNIQUE KEY uq_pending_receipt_scans_token (token)
) ENGINE=InnoDB;