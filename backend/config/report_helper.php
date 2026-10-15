<?php
declare(strict_types=1);

function getAccountBalancesAsOf(PDO $pdo, string $asOf): array
{
    $stmt = $pdo->prepare(
        "SELECT a.id, a.name, a.is_active, a.classification, a.opening_balance, a.opening_balance_date,
                at.name AS type_name, at.normal_balance,
                COALESCE(journal_totals.total_debit, 0) AS journal_debit,
                COALESCE(journal_totals.total_credit, 0) AS journal_credit,
                (
                    COALESCE(journal_totals.total_debit, 0)
                    + CASE
                        WHEN at.normal_balance = 'debit'
                         AND (a.opening_balance_date IS NULL OR a.opening_balance_date <= :as_of_debit)
                        THEN a.opening_balance ELSE 0
                      END
                ) AS total_debit,
                (
                    COALESCE(journal_totals.total_credit, 0)
                    + CASE
                        WHEN at.normal_balance = 'credit'
                         AND (a.opening_balance_date IS NULL OR a.opening_balance_date <= :as_of_credit)
                        THEN a.opening_balance ELSE 0
                      END
                ) AS total_credit
         FROM accounts a
         JOIN account_types at ON at.id = a.account_type_id
         LEFT JOIN (
             SELECT t.account_id,
                    SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE 0 END) AS total_debit,
                    SUM(CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE 0 END) AS total_credit
             FROM transactions t
             JOIN transaction_journals tj ON tj.id = t.transaction_journal_id
             WHERE tj.transaction_date <= :as_of_journals
               AND tj.status != 'voided'
             GROUP BY t.account_id
         ) journal_totals ON journal_totals.account_id = a.id
         ORDER BY at.id, a.name"
    );
    $stmt->execute([
        ':as_of_debit' => $asOf,
        ':as_of_credit' => $asOf,
        ':as_of_journals' => $asOf,
    ]);

    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($accounts as &$account) {
        $account['total_debit'] = (float) $account['total_debit'];
        $account['total_credit'] = (float) $account['total_credit'];
        $account['balance'] = $account['normal_balance'] === 'debit'
            ? $account['total_debit'] - $account['total_credit']
            : $account['total_credit'] - $account['total_debit'];
    }
    unset($account);

    return $accounts;
}

function getReportAsOfDate(array $query): string
{
    $date = trim((string) ($query['date_to'] ?? $query['as_of'] ?? ''));
    if ($date === '') {
        $date = date('Y-m-d');
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('A valid report end date is required.');
    }

    return $date;
}
