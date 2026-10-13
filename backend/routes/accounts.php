<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

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
    // the list we used SELECT to get all accounts and their types, and return them as JSON
    case 'list':
        $pdo = getDbConnection();
        $stmt = $pdo->query(
            "SELECT a.id, a.name, a.current_balance, a.currency_code, 
                    a.is_active, a.notes, at.name AS type_name, at.id AS account_type_id
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             ORDER BY at.id ASC, a.name ASC"
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

        $name = trim($_POST['name'] ?? '');
        $accountTypeId = (int) ($_POST['account_type_id'] ?? 0);
        $openingBalance = (float) ($_POST['opening_balance'] ?? 0.00);
        $notes = trim($_POST['notes'] ?? '');

        if ($name === '' || $accountTypeId === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Account name and account type are required.']);
            break;
        }

        $pdo = getDbConnection();

        // Exact INSERT statement matching your clean accounts table
        $stmt = $pdo->prepare(
            "INSERT INTO accounts (account_type_id, name, opening_balance, current_balance, notes) 
             VALUES (:type_id, :name, :opening_balance, :current_balance, :notes)"
        );

        $stmt->execute([
            ':type_id'         => $accountTypeId,
            ':name'            => $name,
            ':opening_balance' => $openingBalance,
            ':current_balance' => $openingBalance, // Initial balance starts at opening balance
            ':notes'           => $notes,
        ]);

        echo json_encode([
            'success' => true, 
            'message' => 'Account created successfully.',
            'id'      => $pdo->lastInsertId()
        ]);
        break;

    case 'edit':
        requireFinanceRole();

        $accountId = (int) ($_POST['account_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $accountTypeId = (int) ($_POST['account_type_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($accountId === 0 || $name === '' || $accountTypeId === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Account name and type are required.']);
            break;
        }

        $pdo = getDbConnection();

        $stmt = $pdo->prepare(
            "UPDATE accounts 
             SET name = :name, account_type_id = :type_id, notes = :notes
             WHERE id = :id"
        );
        $stmt->execute([
            ':name'    => $name,
            ':type_id' => $accountTypeId,
            ':notes'   => $notes,
            ':id'      => $accountId,
        ]);

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

        $stmt = $pdo->prepare("SELECT is_active FROM accounts WHERE id = :id");
        $stmt->execute([':id' => $accountId]);
        $account = $stmt->fetch();

        if (!$account) {
            http_response_code(404);
            echo json_encode(['error' => 'Account not found.']);
            break;
        }

        $newStatus = $account['is_active'] ? 0 : 1;

        $updateStmt = $pdo->prepare("UPDATE accounts SET is_active = :status WHERE id = :id");
        $updateStmt->execute([':status' => $newStatus, ':id' => $accountId]);

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