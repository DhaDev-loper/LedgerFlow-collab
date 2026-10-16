<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/report_helper.php';
require_once __DIR__ . '/../config/cash_flow_statement.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'cash-flow-statement':
        $dateFrom = (string) ($_GET['date_from'] ?? date('Y-m-01'));
        $dateTo = (string) ($_GET['date_to'] ?? date('Y-m-d'));

        try {
            $data = getCashFlowStatementData(getDbConnection(), $dateFrom, $dateTo);
            $data['html'] = renderCashFlowStatementHtml($data);
            echo json_encode(['success' => true] + $data, JSON_THROW_ON_ERROR);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        }
        break;

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
               AND tj.status != 'voided'
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
               AND tj.status != 'voided'
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
        $asOfDate = getReportAsOfDate($_GET);
        $accounts = array_values(array_filter(
            getAccountBalancesAsOf(getDbConnection(), $asOfDate),
            static fn(array $account): bool => (int) $account['is_active'] === 1
        ));

        $byType = static function (array $accounts, string $type): array {
            return array_values(array_map(
                static fn(array $account): array => [
                    'id' => $account['id'],
                    'name' => $account['name'],
                    'classification' => $account['classification'],
                    'current_balance' => $account['balance'],
                ],
                array_filter($accounts, static fn(array $account): bool => $account['type_name'] === $type)
            ));
        };

        $assets = $byType($accounts, 'Asset');
        $liabilities = $byType($accounts, 'Liability');
        $byClassification = static fn(array $items, string $classification): array => array_values(array_filter(
            $items,
            static fn(array $item): bool => $item['classification'] === $classification
        ));
        $currentAssets = $byClassification($assets, 'current');
        $nonCurrentAssets = $byClassification($assets, 'non_current');
        $currentLiabilities = $byClassification($liabilities, 'current');
        $nonCurrentLiabilities = $byClassification($liabilities, 'non_current');
        $equity = $byType($accounts, 'Equity');
        $revenue = $byType($accounts, 'Revenue');
        $expenses = $byType($accounts, 'Expense');
        $totalAssets = array_sum(array_column($assets, 'current_balance'));
        $totalLiabilities = array_sum(array_column($liabilities, 'current_balance'));
        $totalEquityRecorded = array_sum(array_column($equity, 'current_balance'));
        $retainedEarnings = array_sum(array_column($revenue, 'current_balance'))
            - array_sum(array_column($expenses, 'current_balance'));
        $totalEquity = $totalEquityRecorded + $retainedEarnings;

        echo json_encode([
            'success' => true,
            'as_of' => $asOfDate,
            'assets' => $assets,
            'current_assets' => $currentAssets,
            'non_current_assets' => $nonCurrentAssets,
            'liabilities' => $liabilities,
            'current_liabilities' => $currentLiabilities,
            'non_current_liabilities' => $nonCurrentLiabilities,
            'equity' => $equity,
            'retained_earnings' => $retainedEarnings,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'total_liabilities_and_equity' => $totalLiabilities + $totalEquity,
            'is_balanced' => round($totalAssets, 2) === round($totalLiabilities + $totalEquity, 2),
        ]);
        break;

    case 'trial-balance':
        $asOf = getReportAsOfDate($_GET);
        $accounts = array_values(array_filter(
            getAccountBalancesAsOf(getDbConnection(), $asOf),
            static fn(array $account): bool => (int) $account['is_active'] === 1
        ));

        $lines = [];
        $totalDebitColumn = 0;
        $totalCreditColumn = 0;

        foreach ($accounts as $row) {
            $net = (float) $row['total_debit'] - (float) $row['total_credit'];
            if (abs($net) < 0.005) {
                continue;
            }

            $debitBalance = $net > 0 ? $net : 0;
            $creditBalance = $net < 0 ? abs($net) : 0;

            $lines[] = [
                'account_id' => $row['id'],
                'name' => $row['name'],
                'type_name' => $row['type_name'],
                'debit_balance' => $debitBalance,
                'credit_balance' => $creditBalance,
            ];

            $totalDebitColumn += $debitBalance;
            $totalCreditColumn += $creditBalance;
        }

        echo json_encode([
            'success' => true,
            'as_of' => $asOf,
            'lines' => $lines,
            'total_debit' => $totalDebitColumn,
            'total_credit' => $totalCreditColumn,
            'is_balanced' => round($totalDebitColumn, 2) === round($totalCreditColumn, 2),
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}