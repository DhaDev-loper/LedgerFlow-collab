ALTER TABLE accounts
    ADD COLUMN is_cash_account TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

UPDATE accounts a
JOIN account_types at ON at.id = a.account_type_id
SET a.is_cash_account = 1
WHERE at.name = 'Asset'
  AND (
      LOWER(a.name) LIKE '%cash%'
      OR LOWER(a.name) LIKE '%bank%'
      OR LOWER(a.name) LIKE '%wallet%'
  );


