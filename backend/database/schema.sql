CREATE DATABASE IF NOT EXISTS wrs_accounting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wrs_accounting;

CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(100)        NOT NULL,
    email           VARCHAR(150)        NOT NULL,
    password_hash   VARCHAR(255)        NOT NULL,
    role            ENUM('owner','admin','accountant','staff') NOT NULL DEFAULT 'staff',
    pin_hash        VARCHAR(255)        NULL,
    is_active       TINYINT(1)          NOT NULL DEFAULT 1,
    failed_attempts TINYINT UNSIGNED    NOT NULL DEFAULT 0,
    locked_until    DATETIME            NULL,
    deleted_at      DATETIME            NULL,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
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
    name                    VARCHAR(100)    NOT NULL,
    opening_balance         DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
    opening_balance_date    DATE            NULL,
    current_balance         DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
    currency_code           CHAR(3)         NOT NULL DEFAULT 'PHP',
    is_active               TINYINT(1)      NOT NULL DEFAULT 1,
    notes                   TEXT            NULL,
    created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_accounts_type
        FOREIGN KEY (account_type_id) REFERENCES account_types(id) ON DELETE RESTRICT,
    INDEX idx_accounts_type (account_type_id),
    INDEX idx_accounts_active (is_active)
) ENGINE=InnoDB;

INSERT INTO accounts (account_type_id, name, opening_balance, opening_balance_date, current_balance) VALUES
    (1, 'Cash in bank',        0.00, CURDATE(), 0.00),
    (1, 'Cash on hand',        0.00, CURDATE(), 0.00),
    (1, 'E-Wallet',            0.00, CURDATE(), 0.00),
    (4, 'Sales Revenue',       0.00, NULL,      0.00),
    (4, 'Service Revenue',     0.00, NULL,      0.00),
    (5, 'Utilities',         0.00, NULL,      0.00),
    (5, 'Rent',                0.00, NULL,      0.00),
    (5, 'Salaries & Wages',    0.00, NULL,      0.00);

CREATE TABLE transaction_types (
    id      TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(30) NOT NULL,
    UNIQUE KEY uq_transaction_types_name (name)
) ENGINE=InnoDB;

INSERT INTO transaction_types (name) VALUES
    ('Cash Outflow'), ('Cash Inflow'), ('Transfer'), ('Opening Balance');

CREATE TABLE transaction_journals (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_type_id TINYINT UNSIGNED NOT NULL,
    description         VARCHAR(255)    NOT NULL,
    transaction_date    DATE            NOT NULL,
    reference_no        VARCHAR(50)     NULL,
    total_amount        DECIMAL(14,2)   NOT NULL,
    is_reconciled       TINYINT(1)      NOT NULL DEFAULT 0,
    created_by          INT UNSIGNED    NOT NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_journals_type
        FOREIGN KEY (transaction_type_id) REFERENCES transaction_types(id) ON DELETE RESTRICT,
    CONSTRAINT fk_journals_user
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_journals_date (transaction_date),
    INDEX idx_journals_type (transaction_type_id)
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