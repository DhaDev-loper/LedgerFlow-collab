INSERT INTO accounts
    (account_type_id, classification, name, opening_balance, opening_balance_date, current_balance, is_cash_account, notes)
SELECT
    account_types.id,
    'current',
    'Client Ad Budget Liability',
    0.00,
    NULL,
    0.00,
    0,
    'Tracks client funds held for ad platform spending.'
FROM account_types
WHERE account_types.name = 'Liability'
  AND NOT EXISTS (
      SELECT 1
      FROM accounts
      WHERE accounts.name = 'Client Ad Budget Liability'
  );
