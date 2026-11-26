<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/report_helper.php';
require_once __DIR__ . '/../config/cash_flow_statement.php';
require_once __DIR__ . '/../config/financial_statements.php';

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
        $dateFrom = (string) ($_GET['date_from'] ?? date('Y-m-01'));
        $dateTo = (string) ($_GET['date_to'] ?? date('Y-m-d'));
        try {
            $data = getIncomeStatementData(getDbConnection(), $dateFrom, $dateTo);
            echo json_encode(['success' => true] + $data, JSON_THROW_ON_ERROR);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        }
        break;


    case 'balance-sheet':
        $asOfDate = getReportAsOfDate($_GET);
        $data = getBalanceSheetData(getDbConnection(), $asOfDate);
        $businessNameStmt = getDbConnection()->prepare('SELECT business_name FROM business_settings WHERE id = :id');
        $businessNameStmt->execute([':id' => 1]);
        $businessName = trim((string) $businessNameStmt->fetchColumn());
        $data['business_name'] = $businessName !== '' && $businessName !== 'My Business' ? $businessName : 'WRS Accounting / KoboSocial';
        $data['is_balanced'] = round($data['total_assets'], 2) === round($data['total_liabilities_and_equity'], 2);
        echo json_encode(['success' => true] + $data, JSON_THROW_ON_ERROR);
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

        $businessNameStmt = getDbConnection()->prepare('SELECT business_name FROM business_settings WHERE id = :id');
        $businessNameStmt->execute([':id' => 1]);
        $businessName = trim((string) $businessNameStmt->fetchColumn());

        echo json_encode([
            'success' => true,
            'business_name' => $businessName !== '' && $businessName !== 'My Business'
                ? $businessName
                : 'WRS Accounting / KoboSocial',
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
