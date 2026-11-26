<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit_helper.php';

function getProjectIncomeStatement(int $projectId): ?array
{
    $pdo = getDbConnection();
    $projectStmt = $pdo->prepare(
        "SELECT p.id, p.project_name, p.created_at, p.status,
                c.name AS client_name, c.company_name,
                CASE
                    WHEN bs.business_name IS NULL OR TRIM(bs.business_name) = '' OR bs.business_name = 'My Business'
                    THEN 'WRS Accounting / KoboSocial'
                    ELSE bs.business_name
                END AS business_name
         FROM projects p
         JOIN clients c ON c.id = p.client_id
         LEFT JOIN business_settings bs ON bs.id = 1
         WHERE p.id = :project_id"
    );
    $projectStmt->execute([':project_id' => $projectId]);
    $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
    if (!$project) {
        return null;
    }

    $revenueStmt = $pdo->prepare(
        "SELECT a.id AS account_id, a.name AS account_name,
                SUM(CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE -t.amount END) AS amount
         FROM transactions t
         JOIN accounts a ON a.id = t.account_id
         JOIN account_types at ON at.id = a.account_type_id
         JOIN transaction_journals tj ON tj.id = t.transaction_journal_id
         WHERE tj.project_id = :project_id
           AND tj.status = 'posted'
           AND at.name = 'Revenue'
         GROUP BY a.id, a.name
         HAVING amount <> 0
         ORDER BY a.name"
    );
    $revenueStmt->execute([':project_id' => $projectId]);
    $revenueLines = $revenueStmt->fetchAll(PDO::FETCH_ASSOC);

    $expenseStmt = $pdo->prepare(
        "SELECT a.id AS account_id, a.name AS account_name,
                SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END) AS amount
         FROM transactions t
         JOIN accounts a ON a.id = t.account_id
         JOIN account_types at ON at.id = a.account_type_id
         JOIN transaction_journals tj ON tj.id = t.transaction_journal_id
         WHERE tj.project_id = :project_id
           AND tj.status = 'posted'
           AND at.name = 'Expense'
         GROUP BY a.id, a.name
         HAVING amount <> 0
         ORDER BY a.name"
    );
    $expenseStmt->execute([':project_id' => $projectId]);
    $expenseLines = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($revenueLines as &$line) {
        $line['amount'] = (float) $line['amount'];
    }
    unset($line);
    foreach ($expenseLines as &$line) {
        $line['amount'] = (float) $line['amount'];
    }
    unset($line);

    $totalRevenue = array_sum(array_column($revenueLines, 'amount'));
    $totalExpense = array_sum(array_column($expenseLines, 'amount'));
    $netIncome = $totalRevenue - $totalExpense;

    return [
        'project' => $project,
        'generated_at' => date(DATE_ATOM),
        'revenue_lines' => $revenueLines,
        'expense_lines' => $expenseLines,
        'total_revenue' => $totalRevenue,
        'total_expense' => $totalExpense,
        'net_income' => $netIncome,
        'net_margin' => $totalRevenue > 0 ? ($netIncome / $totalRevenue) * 100 : 0.0,
    ];
}

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'statement');

try {
    $pdo = getDbConnection();

    if ($action === 'client-projects') {
        $clientId = (int) ($_GET['client_id'] ?? 0);
        if ($clientId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'A valid client ID is required.']);
            exit;
        }

        $projectsStmt = $pdo->prepare(
            "SELECT p.id, p.project_name, p.status,
                    COALESCE(SUM(CASE WHEN at.name = 'Revenue' THEN
                        CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE -t.amount END
                        ELSE 0 END), 0) AS total_revenue,
                    COALESCE(SUM(CASE WHEN at.name = 'Expense' THEN
                        CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END
                        ELSE 0 END), 0) AS total_expense
             FROM projects p
             LEFT JOIN transaction_journals tj ON tj.project_id = p.id AND tj.status = 'posted'
             LEFT JOIN transactions t ON t.transaction_journal_id = tj.id
             LEFT JOIN accounts a ON a.id = t.account_id
             LEFT JOIN account_types at ON at.id = a.account_type_id
             WHERE p.client_id = :client_id
             GROUP BY p.id, p.project_name, p.status
             ORDER BY p.created_at DESC, p.project_name"
        );
        $projectsStmt->execute([':client_id' => $clientId]);
        $projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($projects as &$project) {
            $project['total_revenue'] = (float) $project['total_revenue'];
            $project['total_expense'] = (float) $project['total_expense'];
            $project['net_profit'] = $project['total_revenue'] - $project['total_expense'];
        }
        unset($project);

        echo json_encode(['success' => true, 'projects' => $projects], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'projects') {
        $status = (string) ($_GET['status'] ?? 'active');
        $where = $status === 'all' ? '' : 'WHERE p.status = :status';
        if (!in_array($status, ['active', 'archived', 'all'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid project status.']);
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT p.id, p.project_name, p.client_id, p.status, c.name AS client_name
             FROM projects p JOIN clients c ON c.id = p.client_id
             {$where}
             ORDER BY p.project_name"
        );
        $stmt->execute($status === 'all' ? [] : [':status' => $status]);
        echo json_encode(['success' => true, 'projects' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'set-status') {
        if (!in_array($_SESSION['role'] ?? '', ['admin', 'accountant', 'owner'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You do not have permission to change project status.']);
            exit;
        }
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        if ($projectId <= 0 || !in_array($status, ['active', 'archived'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'A valid project and status are required.']);
            exit;
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE projects SET status = :status WHERE id = :project_id');
        $stmt->execute([':status' => $status, ':project_id' => $projectId]);
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT id FROM projects WHERE id = :project_id');
            $exists->execute([':project_id' => $projectId]);
            if (!$exists->fetchColumn()) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Project not found.']);
                exit;
            }
        }
        logAudit(
            $pdo,
            (int) ($_SESSION['user_id'] ?? 0),
            'SET_PROJECT_STATUS',
            'project',
            $projectId,
            json_encode(['status' => $status], JSON_THROW_ON_ERROR)
        );
        $pdo->commit();
        echo json_encode(['success' => true, 'status' => $status]);
        exit;
    }

    if ($action !== 'statement') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid project report action.']);
        exit;
    }

    $projectId = (int) ($_GET['project_id'] ?? 0);
    if ($projectId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'A valid project ID is required.']);
        exit;
    }

    $statement = getProjectIncomeStatement($projectId);
    if ($statement === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Project not found.']);
        exit;
    }

    echo json_encode(['success' => true] + $statement, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Project income statement failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load the project income statement.']);
}
