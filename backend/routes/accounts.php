<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit_helper.php';

// A small reusable check for actions that modify data — not everyone
// who can VIEW accounts should be able to CHANGE them. so only admin and accountant

// this is for security to prevent other roles from performing actions they shouldn't be able to do.
function requireFinanceRole(): void
{
    if (!in_array($_SESSION['role'], ['admin', 'accountant', 'owner'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to perform this action.']);
        exit;
    }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    // List all accounts including their code
    case 'list':
        $pdo = getDbConnection();
        $stmt = $pdo->query(
            "SELECT a.id, a.code, a.name, a.current_balance, a.currency_code,
                    a.is_active, a.is_cash_account, a.classification, a.notes,
                    at.name AS type_name, at.id AS account_type_id
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             ORDER BY at.id ASC, a.code ASC, a.name ASC"
        );
        $accounts = $stmt->fetchAll();

        echo json_encode(['success' => true, 'accounts' => $accounts]);
        break;

    case 'account-types':
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT id, name FROM account_types ORDER BY id ASC");
        echo json_encode(['success' => true, 'types' => $stmt->fetchAll()]);
        break;

    case 'add':
    case 'create':
        requireFinanceRole();

        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $accountTypeId = (int) ($_POST['account_type_id'] ?? 0);
        $openingBalance = (float) ($_POST['opening_balance'] ?? 0.00);
        $notes = trim($_POST['notes'] ?? '');
        $isCashAccount = !empty($_POST['is_cash_account']) ? 1 : 0;
        $classification = $_POST['classification'] ?? 'current';

        if ($name === '' || $accountTypeId === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Account name and account type are required.']);
            break;
        }

        $pdo = getDbConnection();
        $accountTypeStmt = $pdo->prepare("SELECT name FROM account_types WHERE id = :id");
        $accountTypeStmt->execute([':id' => $accountTypeId]);
        $accountTypeName = $accountTypeStmt->fetchColumn();
        if ($accountTypeName === false || ($isCashAccount === 1 && $accountTypeName !== 'Asset')) {
            http_response_code(422);
            echo json_encode(['error' => 'Cash/bank accounts must use the Asset account type.']);
            break;
        }
        if (!in_array($classification, ['current', 'non_current'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Choose a valid account classification.']);
            break;
        }
        if (!in_array($accountTypeName, ['Asset', 'Liability'], true)) {
            $classification = 'current';
        }
        $pdo->beginTransaction();

        // Insert including the account code
        $stmt = $pdo->prepare(
            "INSERT INTO accounts
                (code, account_type_id, classification, name, opening_balance, current_balance, is_cash_account, notes)
             VALUES (:code, :type_id, :classification, :name, :opening_balance, :current_balance, :is_cash_account, :notes)"
        );

        $stmt->execute([
            ':code'            => $code !== '' ? $code : null,
            ':type_id'         => $accountTypeId,
            ':classification'  => $classification,
            ':name'            => $name,
            ':opening_balance' => $openingBalance,
            ':current_balance' => $openingBalance, // Initial balance starts at opening balance
            ':is_cash_account' => $isCashAccount,
            ':notes'           => $notes,
        ]);
        $accountId = (int) $pdo->lastInsertId();
        logAudit(
            $pdo,
            (int) ($_SESSION['user_id'] ?? 0),
            'CREATE_ACCOUNT',
            'account',
            $accountId,
            json_encode([
                'code' => $code,
                'name' => $name,
                'account_type_id' => $accountTypeId,
                'classification' => $classification,
                'is_cash_account' => (bool) $isCashAccount,
            ], JSON_THROW_ON_ERROR)
        );
        $pdo->commit();

        echo json_encode([
            'success' => true, 
            'message' => 'Account created successfully.',
            'id'      => $accountId
        ]);
        break;

    case 'edit':
        requireFinanceRole();

        $accountId = (int) ($_POST['account_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $accountTypeId = (int) ($_POST['account_type_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $isCashAccount = !empty($_POST['is_cash_account']) ? 1 : 0;
        $classification = $_POST['classification'] ?? 'current';

        if ($accountId === 0 || $name === '' || $accountTypeId === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Account name and type are required.']);
            break;
        }

        $pdo = getDbConnection();
        $accountTypeStmt = $pdo->prepare("SELECT name FROM account_types WHERE id = :id");
        $accountTypeStmt->execute([':id' => $accountTypeId]);
        $accountTypeName = $accountTypeStmt->fetchColumn();
        if ($accountTypeName === false || ($isCashAccount === 1 && $accountTypeName !== 'Asset')) {
            http_response_code(422);
            echo json_encode(['error' => 'Cash/bank accounts must use the Asset account type.']);
            break;
        }
        if (!in_array($classification, ['current', 'non_current'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Choose a valid account classification.']);
            break;
        }
        if (!in_array($accountTypeName, ['Asset', 'Liability'], true)) {
            $classification = 'current';
        }
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "UPDATE accounts 
             SET code = :code, name = :name, account_type_id = :type_id,
                 classification = :classification,
                 is_cash_account = :is_cash_account, notes = :notes
             WHERE id = :id"
        );
        $stmt->execute([
            ':code'    => $code !== '' ? $code : null,
            ':name'    => $name,
            ':type_id' => $accountTypeId,
            ':classification' => $classification,
            ':is_cash_account' => $isCashAccount,
            ':notes'   => $notes,
            ':id'      => $accountId,
        ]);
        logAudit(
            $pdo,
            (int) ($_SESSION['user_id'] ?? 0),
            'EDIT_ACCOUNT',
            'account',
            $accountId,
            json_encode([
                'code' => $code,
                'name' => $name,
                'account_type_id' => $accountTypeId,
                'classification' => $classification,
                'is_cash_account' => (bool) $isCashAccount,
            ], JSON_THROW_ON_ERROR)
        );
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Account updated successfully.']);
        break;

    case 'toggle-status':
        requireFinanceRole();

        $accountId = (int) ($_POST['account_id'] ?? 0);

        if ($accountId === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Account ID is required.']);
            break;
        }

        $pdo = getDbConnection();

        $stmt = $pdo->prepare("SELECT name, is_active FROM accounts WHERE id = :id");
        $stmt->execute([':id' => $accountId]);
        $account = $stmt->fetch();

        if (!$account) {
            http_response_code(404);
            echo json_encode(['error' => 'Account not found.']);
            break;
        }

        $newStatus = $account['is_active'] ? 0 : 1;

        $pdo->beginTransaction();
        $updateStmt = $pdo->prepare("UPDATE accounts SET is_active = :status WHERE id = :id");
        $updateStmt->execute([':status' => $newStatus, ':id' => $accountId]);
        logAudit(
            $pdo,
            (int) ($_SESSION['user_id'] ?? 0),
            'TOGGLE_ACCOUNT_STATUS',
            'account',
            $accountId,
            json_encode(['name' => $account['name'], 'is_active' => (bool) $newStatus], JSON_THROW_ON_ERROR)
        );
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => $newStatus ? 'Account activated.' : 'Account deactivated.',
            'is_active' => $newStatus,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}