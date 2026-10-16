<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit_helper.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/**
 * Access Control Rules:
 * - Users can always edit their OWN profile details (name, email, password).
 * - Owners can manage everyone (Admin, Accountant, Staff).
 * - Admins can manage Accountants and Staff, but NOT other Admins or Owners.
 * - Non-Owners CANNOT modify or act upon Owner accounts.
 */
function canManageTarget(array $currentUser, array $targetUser): bool
{
    $currentId = (int)($currentUser['id'] ?? 0);
    $targetId  = (int)($targetUser['id'] ?? 0);
    $currentRole = $currentUser['role'] ?? '';
    $targetRole  = $targetUser['role'] ?? '';

    // Allow user to edit their own account profile details
    if ($currentId > 0 && $currentId === $targetId) {
        return true;
    }

    // Owner account cannot be managed/modified by anyone else
    if ($targetRole === 'owner') {
        return false;
    }

    // Owner can manage all non-owner accounts
    if ($currentRole === 'owner') {
        return true;
    }

    // Admins can manage Accountant and Staff, but NOT other Admins
    if ($currentRole === 'admin' && $targetRole !== 'admin') {
        return true;
    }

    return false;
}

function createSetupInvitation(PDO $pdo, int $userId): string
{
    $pdo->prepare(
        'UPDATE user_setup_invitations
         SET used_at = NOW()
         WHERE user_id = :user_id AND used_at IS NULL'
    )->execute([':user_id' => $userId]);

    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        'INSERT INTO user_setup_invitations (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':token_hash' => hash('sha256', $token),
    ]);

    return $token;
}

function sendSetupInvitation(string $email, string $fullName, string $token): bool
{
    require_once __DIR__ . '/../config/mailer.php';

    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    $urlParts = parse_url($appUrl);
    if (
        $appUrl === ''
        || !is_array($urlParts)
        || !in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
        || empty($urlParts['host'])
    ) {
        error_log('User setup invitation could not be sent: APP_URL must be an absolute HTTP(S) URL.');
        return false;
    }

    $setupUrl = $appUrl . '/frontend/reset-password.html?invite=' . rawurlencode($token);
    $safeName = htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($setupUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return sendEmail(
        $email,
        $fullName,
        'Set Up Your LedgerFlow Account',
        "<p>Hello {$safeName},</p>
         <p>An account has been created for you in LedgerFlow. Use the button below to choose your password.</p>
         <p><a href=\"{$safeUrl}\" style=\"display:inline-block;padding:12px 20px;background:#b0004b;color:#fff;text-decoration:none;border-radius:6px;\">Set up your password</a></p>
         <p>This one-time link expires in 24 hours. If you weren't expecting this invitation, you can ignore this email.</p>"
    );
}

switch ($action) {

    case 'list':
        $pdo = getDbConnection();
        $stmt = $pdo->query(
            "SELECT id, full_name, email, role, is_active, password_setup_required, failed_attempts,
                    locked_until, suspension_level, created_at
             FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC"
        );
        $users = $stmt->fetchAll();

        echo json_encode(['success' => true, 'users' => $users]);
        break;

    case 'create':
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'staff';

        if ($fullName === '' || $email === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Full name and email are required.']);
            break;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['error' => 'Enter a valid email address.']);
            break;
        }

        $allowedRoles = ['admin', 'accountant', 'staff'];
        if (!in_array($role, $allowedRoles, true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid role.']);
            break;
        }

        $pdo = getDbConnection();

        // 1. Check if email belongs to an existing account (active or soft-deleted)
        $checkStmt = $pdo->prepare("SELECT id, deleted_at, suspension_level FROM users WHERE email = :email");
        $checkStmt->execute([':email' => $email]);
        $existingUser = $checkStmt->fetch();

        if ($existingUser) {
            // Case 1: Account is active -> Return conflict error
            if ($existingUser['deleted_at'] === null) {
                http_response_code(409);
                echo json_encode(['error' => 'A active user with this email already exists.']);
                break;
            }

            if ((int) $existingUser['suspension_level'] >= 4 && ($_SESSION['role'] ?? '') !== 'owner') {
                http_response_code(403);
                echo json_encode(['error' => 'Only the owner can reactivate an account locked out after repeated suspensions.']);
                break;
            }

            // Case 2: Account was soft-deleted -> Restore and require a new password setup.
            $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            $restoreStmt = $pdo->prepare(
                "UPDATE users 
                 SET full_name = :full_name, 
                     password_hash = :hash, 
                     password_setup_required = 1,
                     role = :role, 
                     is_active = 1, 
                     failed_attempts = 0,
                     locked_until = NULL,
                     suspension_level = 0,
                     active_session_id = NULL,
                     active_session_last_seen = NULL,
                     deleted_at = NULL 
                 WHERE id = :id"
            );
            $restoreStmt->execute([
                ':full_name' => $fullName,
                ':hash'      => $hash,
                ':role'      => $role,
                ':id'        => $existingUser['id'],
            ]);
            logAudit(
                $pdo,
                (int) ($_SESSION['user_id'] ?? 0),
                'RESTORE_USER',
                'user',
                (int) $existingUser['id'],
                json_encode(['email' => $email, 'role' => $role], JSON_THROW_ON_ERROR)
            );
            $setupToken = createSetupInvitation($pdo, (int) $existingUser['id']);
            $pdo->commit();

            $emailSent = sendSetupInvitation($email, $fullName, $setupToken);
            echo json_encode([
                'success' => true,
                'message' => 'Former employee account restored and reactivated successfully.',
                'warning' => $emailSent ? null : 'The account was restored, but the setup email could not be sent. Use Resend setup invite in the user list after checking email configuration.',
            ]);
            break;
        }

        // Case 3: Completely new email -> create the account and require password setup.
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $pdo->beginTransaction();
        $insertStmt = $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, password_setup_required, role)
             VALUES (:full_name, :email, :hash, 1, :role)"
        );
        $insertStmt->execute([
            ':full_name' => $fullName,
            ':email'     => $email,
            ':hash'      => $hash,
            ':role'      => $role,
        ]);
        $newUserId = (int) $pdo->lastInsertId();
        logAudit(
            $pdo,
            (int) ($_SESSION['user_id'] ?? 0),
            'CREATE_USER',
            'user',
            $newUserId,
            json_encode(['email' => $email, 'role' => $role], JSON_THROW_ON_ERROR)
        );
        $setupToken = createSetupInvitation($pdo, $newUserId);
        $pdo->commit();

        $emailSent = sendSetupInvitation($email, $fullName, $setupToken);
        echo json_encode([
            'success' => true,
            'message' => 'User created successfully.',
            'warning' => $emailSent ? null : 'The user was created, but the setup email could not be sent. Use Resend setup invite in the user list after checking email configuration.',
        ]);
        break;

    case 'resend-setup-invitation':
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId < 1) {
            http_response_code(422);
            echo json_encode(['error' => 'User ID is required.']);
            break;
        }

        $pdo = getDbConnection();
        $targetStmt = $pdo->prepare(
            'SELECT id, full_name, email, role, is_active, password_setup_required
             FROM users WHERE id = :id AND deleted_at IS NULL'
        );
        $targetStmt->execute([':id' => $userId]);
        $targetUser = $targetStmt->fetch();
        if (!$targetUser) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found.']);
            break;
        }

        if (!canManageTarget(
            ['id' => $_SESSION['user_id'] ?? 0, 'role' => $_SESSION['role'] ?? ''],
            $targetUser
        )) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to manage this account.']);
            break;
        }

        if ((int) $targetUser['password_setup_required'] !== 1) {
            http_response_code(409);
            echo json_encode(['error' => 'This user has already set up a password.']);
            break;
        }

        if ((int) $targetUser['is_active'] !== 1) {
            http_response_code(409);
            echo json_encode(['error' => 'Activate this account before resending its setup link.']);
            break;
        }

        $pdo->beginTransaction();
        $setupToken = createSetupInvitation($pdo, $userId);
        $pdo->commit();

        if (!sendSetupInvitation($targetUser['email'], $targetUser['full_name'], $setupToken)) {
            http_response_code(502);
            echo json_encode(['error' => 'The setup email could not be sent. Check the email configuration and try again.']);
            break;
        }

        echo json_encode(['success' => true, 'message' => 'A new setup link was sent. Any older setup link is now invalid.']);
        break;

    case 'toggle-status':
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'User ID is required.']);
            break;
        }

        if ($userId === (int) $_SESSION['user_id']) {
            http_response_code(400);
            echo json_encode(['error' => 'You cannot deactivate your own account.']);
            break;
        }

        $pdo = getDbConnection();

        $stmt = $pdo->prepare("SELECT id, role, is_active, suspension_level FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $targetUser = $stmt->fetch();

        if (!$targetUser) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found.']);
            break;
        }

        $currentUserInfo = [
            'id'   => $_SESSION['user_id'] ?? 0,
            'role' => $_SESSION['role'] ?? ''
        ];

        if (!canManageTarget($currentUserInfo, $targetUser)) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to manage this account.']);
            break;
        }

        $newStatus = $targetUser['is_active'] ? 0 : 1;

        if ((int) $targetUser['suspension_level'] >= 4 && $newStatus === 1 && ($_SESSION['role'] ?? '') !== 'owner') {
            http_response_code(403);
            echo json_encode(['error' => 'Only the owner can reactivate an account locked out after repeated suspensions.']);
            break;
        }

        $pdo->beginTransaction();
        if ($newStatus === 1 && (int) $targetUser['suspension_level'] >= 4) {
            $updateStmt = $pdo->prepare(
                "UPDATE users
                 SET is_active = 1, failed_attempts = 0, locked_until = NULL,
                     suspension_level = 0, active_session_id = NULL,
                     active_session_last_seen = NULL
                 WHERE id = :id"
            );
            $updateStmt->execute([':id' => $userId]);
        } else {
            $updateStmt = $pdo->prepare(
                "UPDATE users
                 SET is_active = :status,
                     active_session_id = CASE WHEN :inactive_status = 0 THEN NULL ELSE active_session_id END,
                     active_session_last_seen = CASE WHEN :inactive_status = 0 THEN NULL ELSE active_session_last_seen END
                 WHERE id = :id"
            );
            $updateStmt->execute([
                ':status' => $newStatus,
                ':inactive_status' => $newStatus,
                ':id' => $userId,
            ]);
        }
        logAudit(
            $pdo,
            (int) ($_SESSION['user_id'] ?? 0),
            'TOGGLE_USER_STATUS',
            'user',
            $userId,
            json_encode(['is_active' => (bool) $newStatus], JSON_THROW_ON_ERROR)
        );
        $pdo->commit();

        echo json_encode([
            'success'   => true,
            'message'   => $newStatus ? 'User activated.' : 'User deactivated.',
            'is_active' => $newStatus,
        ]);
        break;

    case 'edit':
        $userId   = (int) ($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $inputRole = strtolower(trim($_POST['role'] ?? ''));
        $password  = $_POST['password'] ?? '';

        if ($userId === 0 || $fullName === '' || $email === '') {
            http_response_code(422);
            echo json_encode(['error' => 'User ID, name, and email are required.']);
            break;
        }

        $pdo = getDbConnection();

        // 1. Fetch existing user record to verify target and current role
        $targetStmt = $pdo->prepare("SELECT id, role FROM users WHERE id = :id AND deleted_at IS NULL");
        $targetStmt->execute([':id' => $userId]);
        $targetUser = $targetStmt->fetch();

        if (!$targetUser) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found.']);
            break;
        }

        // 2. Validate permission
        $currentUserInfo = [
            'id'   => $_SESSION['user_id'] ?? 0,
            'role' => $_SESSION['role'] ?? ''
        ];

        if (!canManageTarget($currentUserInfo, $targetUser)) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to manage this account.']);
            break;
        }

        // 3. Prevent demoting/changing Owner role or self-role escalation
        if ($targetUser['role'] === 'owner') {
            $finalRole = 'owner';
        } else if ($userId === (int) ($_SESSION['user_id'] ?? 0)) {
            // Keep current role if editing own profile to prevent self-role escalation
            $finalRole = $targetUser['role'];
        } else {
            $allowedRoles = ['admin', 'accountant', 'staff'];
            if (!in_array($inputRole, $allowedRoles, true)) {
                http_response_code(422);
                echo json_encode(['error' => 'Invalid role selected.']);
                break;
            }
            $finalRole = $inputRole;
        }

        // 4. Ensure email is not occupied by another active account
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id AND deleted_at IS NULL");
        $checkStmt->execute([':email' => $email, ':id' => $userId]);
        if ($checkStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Another user already has this email.']);
            break;
        }

        // 5. Update password if requested, or preserve existing password
        $pdo->beginTransaction();
        if ($password !== '') {
            if (strlen($password) < 8) {
                http_response_code(422);
                echo json_encode(['error' => 'Password must be at least 8 characters.']);
                break;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = $pdo->prepare(
                "UPDATE users SET full_name = :full_name, email = :email, role = :role, password_hash = :hash WHERE id = :id"
            );
            $updateStmt->execute([
                ':full_name' => $fullName,
                ':email'     => $email,
                ':role'      => $finalRole,
                ':hash'      => $hash,
                ':id'        => $userId,
            ]);
        } else {
            $updateStmt = $pdo->prepare(
                "UPDATE users SET full_name = :full_name, email = :email, role = :role WHERE id = :id"
            );
            $updateStmt->execute([
                ':full_name' => $fullName,
                ':email'     => $email,
                ':role'      => $finalRole,
                ':id'        => $userId,
            ]);
        }

        // 6. Update session name if editing self
        if ($userId === (int) ($_SESSION['user_id'] ?? 0)) {
            $_SESSION['full_name'] = $fullName;
        }

        logAudit(
            $pdo,
            (int) ($_SESSION['user_id'] ?? 0),
            'EDIT_USER',
            'user',
            $userId,
            json_encode(['full_name' => $fullName, 'email' => $email, 'role' => $finalRole], JSON_THROW_ON_ERROR)
        );
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
        break;

    case 'delete':
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'User ID is required.']);
            break;
        }

        if ($userId === (int) $_SESSION['user_id']) {
            http_response_code(400);
            echo json_encode(['error' => 'You cannot delete your own account.']);
            break;
        }

        $pdo = getDbConnection();

        $targetStmt = $pdo->prepare("SELECT id, role FROM users WHERE id = :id AND deleted_at IS NULL");
        $targetStmt->execute([':id' => $userId]);
        $targetUser = $targetStmt->fetch();

        if (!$targetUser) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found.']);
            break;
        }

        $currentUserInfo = [
            'id'   => $_SESSION['user_id'] ?? 0,
            'role' => $_SESSION['role'] ?? ''
        ];

        if (!canManageTarget($currentUserInfo, $targetUser)) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to manage this account.']);
            break;
        }

        $pdo->beginTransaction();
        $deleteStmt = $pdo->prepare(
            "UPDATE users
             SET deleted_at = NOW(), active_session_id = NULL, active_session_last_seen = NULL
             WHERE id = :id"
        );
        $deleteStmt->execute([':id' => $userId]);
        logAudit(
            $pdo,
            (int) ($_SESSION['user_id'] ?? 0),
            'DELETE_USER',
            'user',
            $userId,
            json_encode(['role' => $targetUser['role']], JSON_THROW_ON_ERROR)
        );
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'User deleted successfully.']);
        break;
    

    case 'list-archived':
        $pdo = getDbConnection();
        $stmt = $pdo->query(
            "SELECT id, full_name, email, role, is_active, suspension_level,
                    created_at, deleted_at
             FROM users WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC"
        );
        $archivedUsers = $stmt->fetchAll();

        echo json_encode(['success' => true, 'users' => $archivedUsers]);
        break;

    case 'restore':
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'User ID is required.']);
            break;
        }

        $pdo = getDbConnection();

        // 1. Fetch soft-deleted target user
        $targetStmt = $pdo->prepare(
            "SELECT id, full_name, email, role, suspension_level
             FROM users WHERE id = :id AND deleted_at IS NOT NULL"
        );
        $targetStmt->execute([':id' => $userId]);
        $targetUser = $targetStmt->fetch();

        if (!$targetUser) {
            http_response_code(404);
            echo json_encode(['error' => 'Archived user not found.']);
            break;
        }

        if ((int) $targetUser['suspension_level'] >= 4 && ($_SESSION['role'] ?? '') !== 'owner') {
            http_response_code(403);
            echo json_encode(['error' => 'Only the owner can restore an account locked out after repeated suspensions.']);
            break;
        }

        // 2. Prevent duplicate email conflicts with active users
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND deleted_at IS NULL");
        $checkStmt->execute([':email' => $targetUser['email']]);
        if ($checkStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Cannot restore account because an active user already exists with this email address.']);
            break;
        }

        // 3. Restore user
        $pdo->beginTransaction();
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $restoreStmt = $pdo->prepare(
            "UPDATE users
             SET deleted_at = NULL, is_active = 1, password_hash = :hash,
                 password_setup_required = 1, failed_attempts = 0,
                 locked_until = NULL, suspension_level = 0,
                 active_session_id = NULL, active_session_last_seen = NULL
             WHERE id = :id"
        );
        $restoreStmt->execute([':hash' => $hash, ':id' => $userId]);
        logAudit(
            $pdo,
            (int) ($_SESSION['user_id'] ?? 0),
            'RESTORE_USER',
            'user',
            $userId,
            json_encode(['email' => $targetUser['email'], 'role' => $targetUser['role']], JSON_THROW_ON_ERROR)
        );
        $setupToken = createSetupInvitation($pdo, $userId);
        $pdo->commit();

        $emailSent = sendSetupInvitation($targetUser['email'], $targetUser['full_name'], $setupToken);
        echo json_encode([
            'success' => true,
            'message' => 'User account restored successfully.',
            'warning' => $emailSent ? null : 'The account was restored, but the setup email could not be sent. Use Resend setup invite in the user list after checking email configuration.',
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action.']);
        break;
}