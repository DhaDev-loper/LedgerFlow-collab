<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit_helper.php';
require_once __DIR__ . '/../config/accounting_periods.php';

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

/** Return a validation message when an account name or code is already in use. */
function findDuplicateAccount(PDO $pdo, string $name, string $code, ?int $excludeId = null): ?string
{
    $nameSql = 'SELECT id FROM accounts WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))';
    $codeSql = "SELECT id FROM accounts WHERE code IS NOT NULL AND TRIM(code) <> '' AND LOWER(TRIM(code)) = LOWER(TRIM(:code))";
    $params = [':name' => $name];
    if ($excludeId !== null) {
        $nameSql .= ' AND id <> :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }
    $nameSql .= ' LIMIT 1';
    $stmt = $pdo->prepare($nameSql);
    $stmt->execute($params);
    if ($stmt->fetchColumn() !== false) {
        return 'An account with this name already exists. Use a different account name.';
    }

    if ($code !== '') {
        $params = [':code' => $code];
        if ($excludeId !== null) {
            $codeSql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }
        $codeSql .= ' LIMIT 1';
        $stmt = $pdo->prepare($codeSql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) {
            return 'An account with this code already exists. Use a different account code.';
        }
    }

    return null;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    // List all accounts including their code
    case 'list':
        $pdo = getDbConnection();
        $stmt = $pdo->query(
            "SELECT a.id, a.code, a.name, a.current_balance, a.currency_code,
                    a.is_active, a.is_cash_account, a.classification, a.notes,
                    at.name AS type_name, at.id AS account_type_id,
                    (EXISTS (SELECT 1 FROM transactions tx WHERE tx.account_id = a.id)
                     OR EXISTS (SELECT 1 FROM settlement_allocations sa WHERE sa.account_id = a.id)) AS has_transactions
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
        $duplicateError = findDuplicateAccount($pdo, $name, $code);
        if ($duplicateError !== null) {
            http_response_code(409);
            echo json_encode(['error' => $duplicateError]);
            break;
        }
        if ($openingBalance != 0.0 && hasClosedAccountingPeriods($pdo)) {
            http_response_code(422);
            echo json_encode([
                'error' => 'An opening balance would change finalized reports. Create the account with a zero balance and record the balance as a new entry in an open period.',
            ]);
            break;
        }
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

        if ($accountId === 0 || $name === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Account name and type are required.']);
            break;
        }

        $pdo = getDbConnection();
        $pdo->beginTransaction();
        $existingStmt = $pdo->prepare(
            "SELECT a.account_type_id, a.classification, a.is_cash_account,
                    (EXISTS (SELECT 1 FROM transactions tx WHERE tx.account_id = a.id)
                     OR EXISTS (SELECT 1 FROM settlement_allocations sa WHERE sa.account_id = a.id)) AS has_transactions
             FROM accounts a WHERE a.id = :id FOR UPDATE"
        );
        $existingStmt->execute([':id' => $accountId]);
        $existingAccount = $existingStmt->fetch();
        if (!$existingAccount) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Account not found.']);
            break;
        }
        $duplicateError = findDuplicateAccount($pdo, $name, $code, $accountId);
        if ($duplicateError !== null) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => $duplicateError]);
            break;
        }
        if ((bool) $existingAccount['has_transactions']) {
            $accountTypeId = (int) $existingAccount['account_type_id'];
            $classification = (string) $existingAccount['classification'];
            $isCashAccount = (int) $existingAccount['is_cash_account'];
        }
        if ($accountTypeId === 0) {
            $pdo->rollBack();
            http_response_code(422);
            echo json_encode(['error' => 'Account name and type are required.']);
            break;
        }
        $accountTypeStmt = $pdo->prepare("SELECT name FROM account_types WHERE id = :id");
        $accountTypeStmt->execute([':id' => $accountTypeId]);
        $accountTypeName = $accountTypeStmt->fetchColumn();
        if ($accountTypeName === false || ($isCashAccount === 1 && $accountTypeName !== 'Asset')) {
            $pdo->rollBack();
            http_response_code(422);
            echo json_encode(['error' => 'Cash/bank accounts must use the Asset account type.']);
            break;
        }
        if (!in_array($classification, ['current', 'non_current'], true)) {
            $pdo->rollBack();
            http_response_code(422);
            echo json_encode(['error' => 'Choose a valid account classification.']);
            break;
        }
        if (!in_array($accountTypeName, ['Asset', 'Liability'], true)) {
            $classification = 'current';
        }
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
