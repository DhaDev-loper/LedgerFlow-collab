<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
     // List all journal entries with their basic details
     // we use join in created by so that we can get the name of the user who recored an journal entry of transaction
    case 'list':
        $pdo = getDbConnection();
        $stmt = $pdo->query(
            "SELECT tj.id, tj.description, tj.transaction_date, tj.reference_no, 
                    tj.total_amount, tt.name AS type_name, u.full_name AS created_by_name
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             JOIN users u ON u.id = tj.created_by
             ORDER BY tj.transaction_date DESC, tj.id DESC"
        );
        $journals = $stmt->fetchAll();

        echo json_encode(['success' => true, 'journals' => $journals]);
        break;

    case 'reference-data':
       
        // debugging link:
       //http://localhost/wrs-accounting-collab/backend/routes/journal.php?action=reference-data


        $pdo = getDbConnection();
        // to connect to charts of accounts to the journal enry form
        $accounts = $pdo->query(
            "SELECT a.id, a.name, at.name AS type_name 
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE a.is_active = 1
             ORDER BY at.id, a.name"
        )->fetchAll();

        $types = $pdo->query("SELECT id, name FROM transaction_types ORDER BY id")->fetchAll();

        $categories = $pdo->query(
            "SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name"
        )->fetchAll();

        echo json_encode([
            'success' => true,
            'accounts' => $accounts,
            'types' => $types,
            'categories' => $categories,
        ]);
        break;
         
      // this create case is for is for database transaction
      // but not the transaction it self but it helps so that operations can succedd
     case 'create':
        $transactionTypeId = (int) ($_POST['transaction_type_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $transactionDate = $_POST['transaction_date'] ?? '';
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $fromAccountId = (int) ($_POST['from_account_id'] ?? 0);
        $toAccountId = (int) ($_POST['to_account_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);

        if ($transactionTypeId === 0 || $description === '' || $transactionDate === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Transaction type, description, and date are required.']);
            break;
        }

        if ($fromAccountId === 0 || $toAccountId === 0 || $amount <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'From account, to account, and a positive amount are required.']);
            break;
        }

        if ($fromAccountId === $toAccountId) {
            http_response_code(422);
            echo json_encode(['error' => 'From and To accounts must be different.']);
            break;
        }

        // Money always leaves the FROM account (credit) and arrives at
        // the TO account (debit) — this is true no matter what the
        // transaction TYPE is labeled as. The type is just a label for
        // reporting; the actual accounting mechanics are always the same.
        $lines = [
            ['account_id' => $toAccountId,   'entry_type' => 'debit',  'amount' => $amount],
            ['account_id' => $fromAccountId, 'entry_type' => 'credit', 'amount' => $amount],
        ];

        $pdo = getDbConnection();

        try {
            $pdo->beginTransaction();

            $journalStmt = $pdo->prepare(
                "INSERT INTO transaction_journals 
                    (transaction_type_id, description, transaction_date, reference_no, total_amount, created_by)
                 VALUES (:type_id, :description, :date, :reference, :total, :created_by)"
            );
            $journalStmt->execute([
                ':type_id'    => $transactionTypeId,
                ':description'=> $description,
                ':date'       => $transactionDate,
                ':reference'  => $referenceNo,
                ':total'      => $amount,
                ':created_by' => $_SESSION['user_id'],
            ]);

            $journalId = (int) $pdo->lastInsertId();

            $lineStmt = $pdo->prepare(
                "INSERT INTO transactions (transaction_journal_id, account_id, entry_type, amount)
                 VALUES (:journal_id, :account_id, :entry_type, :amount)"
            );

            foreach ($lines as $line) {
                $lineStmt->execute([
                    ':journal_id'  => $journalId,
                    ':account_id'  => $line['account_id'],
                    ':entry_type'  => $line['entry_type'],
                    ':amount'      => $line['amount'],
                ]);
            }

            if ($categoryId > 0) {
                $categoryStmt = $pdo->prepare(
                    "INSERT INTO journal_category (transaction_journal_id, category_id) VALUES (:journal_id, :category_id)"
                );
                $categoryStmt->execute([':journal_id' => $journalId, ':category_id' => $categoryId]);
            }

            foreach ($lines as $line) {
                $balanceStmt = $pdo->prepare(
                    "SELECT at.normal_balance FROM accounts a
                     JOIN account_types at ON at.id = a.account_type_id
                     WHERE a.id = :id"
                );
                $balanceStmt->execute([':id' => $line['account_id']]);
                $accountInfo = $balanceStmt->fetch();

                $isDebit = $line['entry_type'] === 'debit';
                $matchesNormalSide = ($isDebit && $accountInfo['normal_balance'] === 'debit')
                                  || (!$isDebit && $accountInfo['normal_balance'] === 'credit');

                $delta = $matchesNormalSide ? $line['amount'] : -$line['amount'];

                $updateBalanceStmt = $pdo->prepare(
                    "UPDATE accounts SET current_balance = current_balance + :delta WHERE id = :id"
                );
                $updateBalanceStmt->execute([':delta' => $delta, ':id' => $line['account_id']]);
            }

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Journal entry recorded successfully.', 'journal_id' => $journalId]);

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Journal entry creation failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Something went wrong while saving the entry.']);
        }
        break;

        case 'view':
        $journalId = (int) ($_GET['journal_id'] ?? $_POST['journal_id'] ?? 0);

        if ($journalId === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Journal ID is required.']);
            break;
        }

        $pdo = getDbConnection();

        // The journal header, plus type name, creator name, and category (if any)
        $headerStmt = $pdo->prepare(
            "SELECT tj.id, tj.description, tj.transaction_date, tj.reference_no, 
                    tj.total_amount, tj.created_at, tt.name AS type_name, 
                    u.full_name AS created_by_name, c.name AS category_name
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             JOIN users u ON u.id = tj.created_by
             LEFT JOIN journal_category jc ON jc.transaction_journal_id = tj.id
             LEFT JOIN categories c ON c.id = jc.category_id
             WHERE tj.id = :id"
        );
        $headerStmt->execute([':id' => $journalId]);
        $header = $headerStmt->fetch();

        if (!$header) {
            http_response_code(404);
            echo json_encode(['error' => 'Journal entry not found.']);
            break;
        }

        // The actual debit/credit split lines, with account names attached
        $linesStmt = $pdo->prepare(
            "SELECT t.entry_type, t.amount, a.name AS account_name, at.name AS account_type
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             WHERE t.transaction_journal_id = :id
             ORDER BY t.entry_type DESC"
        );
        $linesStmt->execute([':id' => $journalId]);
        $lines = $linesStmt->fetchAll();

        echo json_encode(['success' => true, 'journal' => $header, 'lines' => $lines]);
        break;


        case 'recent':
        $limit = (int) ($_GET['limit'] ?? 8);

        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            "SELECT tj.id, tj.description, tj.transaction_date, tj.total_amount, 
                    tt.name AS type_name, c.name AS category_name
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             LEFT JOIN journal_category jc ON jc.transaction_journal_id = tj.id
             LEFT JOIN categories c ON c.id = jc.category_id
             ORDER BY tj.transaction_date DESC, tj.id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $recent = $stmt->fetchAll();

        echo json_encode(['success' => true, 'transactions' => $recent]);
        break;

        case 'summary':
        $pdo = getDbConnection();

        // Total Assets — sum of every active Asset account's current balance
        $assetsStmt = $pdo->query(
            "SELECT COALESCE(SUM(a.current_balance), 0) AS total
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE at.name = 'Asset' AND a.is_active = 1"
        );
        $totalAssets = (float) $assetsStmt->fetch()['total'];

        // Cash Inflow this month — sum of journal totals where type = Cash Inflow,
        // restricted to the current calendar month
        $inflowStmt = $pdo->query(
            "SELECT COALESCE(SUM(tj.total_amount), 0) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             WHERE tt.name = 'Cash Inflow'
               AND MONTH(tj.transaction_date) = MONTH(CURDATE())
               AND YEAR(tj.transaction_date) = YEAR(CURDATE())"
        );
        $cashInflow = (float) $inflowStmt->fetch()['total'];

        // Cash Outflow this month — same idea, opposite type
        $outflowStmt = $pdo->query(
            "SELECT COALESCE(SUM(tj.total_amount), 0) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             WHERE tt.name = 'Cash Outflow'
               AND MONTH(tj.transaction_date) = MONTH(CURDATE())
               AND YEAR(tj.transaction_date) = YEAR(CURDATE())"
        );
        $cashOutflow = (float) $outflowStmt->fetch()['total'];

        echo json_encode([
            'success' => true,
            'total_assets' => $totalAssets,
            'cash_inflow' => $cashInflow,
            'cash_outflow' => $cashOutflow,
            'net_cash_flow' => $cashInflow - $cashOutflow,
        ]);
        break;

        case 'cashflow-chart':
        $days = (int) ($_GET['days'] ?? 14);

        $pdo = getDbConnection();

        $stmt = $pdo->prepare(
            "SELECT tj.transaction_date, tt.name AS type_name, SUM(tj.total_amount) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             WHERE tt.name IN ('Cash Inflow', 'Cash Outflow')
               AND tj.transaction_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             GROUP BY tj.transaction_date, tt.name
             ORDER BY tj.transaction_date ASC"
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Build a lookup: { '2026-08-01': { in: 150, out: 0 }, ... }
        $byDate = [];
        foreach ($rows as $row) {
            $date = $row['transaction_date'];
            if (!isset($byDate[$date])) {
                $byDate[$date] = ['in' => 0, 'out' => 0];
            }
            if ($row['type_name'] === 'Cash Inflow') {
                $byDate[$date]['in'] = (float) $row['total'];
            } else {
                $byDate[$date]['out'] = (float) $row['total'];
            }
        }

        // Fill in every day in the range, even ones with zero transactions,
        // so the chart shows a continuous line instead of gaps.
        $labels = [];
        $cashIn = [];
        $cashOut = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = date('M j', strtotime($date));
            $cashIn[] = $byDate[$date]['in'] ?? 0;
            $cashOut[] = $byDate[$date]['out'] ?? 0;
        }

        echo json_encode([
            'success' => true,
            'labels' => $labels,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
        ]);
        break;

        case 'today-sales':
        $pdo = getDbConnection();

        $todayStmt = $pdo->query(
            "SELECT COALESCE(SUM(tj.total_amount), 0) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             WHERE tt.name = 'Cash Inflow' AND tj.transaction_date = CURDATE()"
        );
        $todaySales = (float) $todayStmt->fetch()['total'];

        $yesterdayStmt = $pdo->query(
            "SELECT COALESCE(SUM(tj.total_amount), 0) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             WHERE tt.name = 'Cash Inflow' AND tj.transaction_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
        );
        $yesterdaySales = (float) $yesterdayStmt->fetch()['total'];

        // Avoid dividing by zero if yesterday had no sales at all.
        $percentChange = $yesterdaySales > 0
            ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100, 1)
            : ($todaySales > 0 ? 100 : 0);

        // Last 7 days, for the mini bar chart
        $weekStmt = $pdo->prepare(
            "SELECT tj.transaction_date, COALESCE(SUM(tj.total_amount), 0) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             WHERE tt.name = 'Cash Inflow'
               AND tj.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY tj.transaction_date"
        );
        $weekStmt->execute();
        $weekRows = $weekStmt->fetchAll();

        $byDate = [];
        foreach ($weekRows as $row) {
            $byDate[$row['transaction_date']] = (float) $row['total'];
        }

        $weekLabels = [];
        $weekValues = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $weekLabels[] = date('D', strtotime($date));
            $weekValues[] = $byDate[$date] ?? 0;
        }

        echo json_encode([
            'success' => true,
            'today_sales' => $todaySales,
            'percent_change' => $percentChange,
            'week_labels' => $weekLabels,
            'week_values' => $weekValues,
        ]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}