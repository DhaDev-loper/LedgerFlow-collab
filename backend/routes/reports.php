<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'income-statement':
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // default: 1st of this month
        $dateTo   = $_GET['date_to'] ?? date('Y-m-d');     // default: today

        $pdo = getDbConnection();

        // Revenue: a CREDIT increases revenue, a DEBIT decreases it
        // (matches Revenue's normal_balance = 'credit' from account_types)
        $revenueStmt = $pdo->prepare(
            "SELECT a.id, a.name,
                    SUM(CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE -t.amount END) AS net_amount
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             JOIN transaction_journals tj ON tj.id = t.transaction_journal_id
             WHERE at.name = 'Revenue'
               AND tj.transaction_date BETWEEN :date_from AND :date_to
             GROUP BY a.id, a.name
             HAVING net_amount != 0
             ORDER BY a.name"
        );
        $revenueStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
        $revenueLines = $revenueStmt->fetchAll();

        // Expense: a DEBIT increases expense, a CREDIT decreases it
        $expenseStmt = $pdo->prepare(
            "SELECT a.id, a.name,
                    SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END) AS net_amount
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             JOIN transaction_journals tj ON tj.id = t.transaction_journal_id
             WHERE at.name = 'Expense'
               AND tj.transaction_date BETWEEN :date_from AND :date_to
             GROUP BY a.id, a.name
             HAVING net_amount != 0
             ORDER BY a.name"
        );
        $expenseStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
        $expenseLines = $expenseStmt->fetchAll();

        $totalRevenue = array_sum(array_column($revenueLines, 'net_amount'));
        $totalExpense = array_sum(array_column($expenseLines, 'net_amount'));

        echo json_encode([
            'success' => true,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'revenue_lines' => $revenueLines,
            'expense_lines' => $expenseLines,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_income' => $totalRevenue - $totalExpense,
        ]);
        break;


        case 'balance-sheet':
        $asOfDate = $_GET['as_of'] ?? date('Y-m-d');

        $pdo = getDbConnection();

        // Assets — using stored current_balance since it's already
        // maintained correctly by every journal entry's balance update.
        $assetsStmt = $pdo->query(
            "SELECT a.id, a.name, a.current_balance
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE at.name = 'Asset' AND a.is_active = 1
             ORDER BY a.name"
        );
        $assets = $assetsStmt->fetchAll();

        $liabilitiesStmt = $pdo->query(
            "SELECT a.id, a.name, a.current_balance
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE at.name = 'Liability' AND a.is_active = 1
             ORDER BY a.name"
        );
        $liabilities = $liabilitiesStmt->fetchAll();

        $equityStmt = $pdo->query(
            "SELECT a.id, a.name, a.current_balance
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE at.name = 'Equity' AND a.is_active = 1
             ORDER BY a.name"
        );
        $equity = $equityStmt->fetchAll();

        $totalAssets = array_sum(array_column($assets, 'current_balance'));
        $totalLiabilities = array_sum(array_column($liabilities, 'current_balance'));
        $totalEquityRecorded = array_sum(array_column($equity, 'current_balance'));

        // Retained Earnings: since we don't have a formal "closing the books"
        // process yet, we treat all accumulated net income (Revenue minus
        // Expense, all-time) as part of Equity — this is what makes the
        // Balance Sheet actually balance: Assets = Liabilities + Equity.
        $netIncomeStmt = $pdo->query(
            "SELECT
                (SELECT COALESCE(SUM(CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE -t.amount END), 0)
                 FROM transactions t JOIN accounts a ON a.id = t.account_id JOIN account_types at ON at.id = a.account_type_id
                 WHERE at.name = 'Revenue') AS total_revenue,
                (SELECT COALESCE(SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END), 0)
                 FROM transactions t JOIN accounts a ON a.id = t.account_id JOIN account_types at ON at.id = a.account_type_id
                 WHERE at.name = 'Expense') AS total_expense"
        );
        $income = $netIncomeStmt->fetch();
        $retainedEarnings = (float) $income['total_revenue'] - (float) $income['total_expense'];

        $totalEquity = $totalEquityRecorded + $retainedEarnings;

        echo json_encode([
            'success' => true,
            'as_of' => $asOfDate,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'retained_earnings' => $retainedEarnings,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'total_liabilities_and_equity' => $totalLiabilities + $totalEquity,
            'is_balanced' => round($totalAssets, 2) === round($totalLiabilities + $totalEquity, 2),
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}