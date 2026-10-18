<?php

//auth.php is a backend API endpoint, not a page
declare(strict_types=1);
// this file handles login systems and user authentication
// to ensure that the user is who they say they are and to manage their session
header('Content-Type: application/json; charset=utf-8');

set_exception_handler(static function (Throwable $exception): void {
    error_log('Authentication request failed: ' . $exception->getMessage());

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && $GLOBALS['pdo']->inTransaction()) {
        $GLOBALS['pdo']->rollBack();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(['error' => 'The login service encountered an error. Please check the server logs.']);
});

$remember = isset($_POST['remember']) && $_POST['remember'] === '1';

if ($remember) {
    $lifetime = 30 * 24 * 60 * 60; // 30 days, in seconds
    session_set_cookie_params(['lifetime' => $lifetime]);
    ini_set('session.gc_maxlifetime', (string) $lifetime);
}
session_start(); // start session so that php can track who is logged in and who is not

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit_helper.php';
//this reads a value called action from the incoming request

$action = $_POST['action'] ?? $_GET['action'] ?? ''; // the double ? if the value is not set it will default and not crash the system


//LOGIN SYSTEM
switch ($action) {
    // this will validate the email and password from the loginForm  
    // LOGIN SYSTEM
    case 'login':
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($email === '' || $password === '') {
                http_response_code(422);
                echo json_encode(['error' => 'Email and password are required.']);
                break;
            }

            $pdo = getDbConnection();

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT id, full_name, email, password_hash, password_setup_required, role, is_active,
                        failed_attempts, locked_until, suspension_level,
                        active_session_id, active_session_last_seen,
                        (active_session_last_seen >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)) AS active_session_is_recent,
                        deleted_at
                 FROM users WHERE email = :email FOR UPDATE"
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user) {
                $pdo->commit();
                http_response_code(401);
                echo json_encode(['error' => 'Invalid email or password.']);
                break;
            }

            if ((int) $user['is_active'] !== 1 || $user['deleted_at'] !== null) {
                $pdo->commit();
                if (!password_verify($password, $user['password_hash'])) {
                    http_response_code(401);
                    echo json_encode(['error' => 'Invalid email or password.']);
                    break;
                }

                http_response_code(403);
                echo json_encode(['error' => 'This account has been deactivated.']);
                break;
            }

            $isOwner = $user['role'] === 'owner';
            $lockedUntil = $user['locked_until'] === null ? false : strtotime($user['locked_until']);
            if (!$isOwner && $lockedUntil !== false && $lockedUntil > time()) {
                $secondsLeft = $lockedUntil - time();
                $minutesLeft = (int) ceil($secondsLeft / 60);
                $pdo->commit();
                http_response_code(429);
                echo json_encode([
                    'error' => "Your account is suspended. Try again in {$minutesLeft} minute(s).",
                    'suspended' => true,
                    'suspension_level' => (int) $user['suspension_level'],
                    'remaining_seconds' => $secondsLeft,
                ]);
                break;
            }

            if (!$isOwner && $lockedUntil !== false) {
                $pdo->prepare(
                    "UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = :id"
                )->execute([':id' => $user['id']]);
                $user['failed_attempts'] = 0;
                $user['locked_until'] = null;
            }

            if ((int) $user['password_setup_required'] === 1) {
                $pdo->commit();
                http_response_code(403);
                echo json_encode([
                    'error' => 'Set up your password using the one-time invitation link before signing in.',
                    'setup_required' => true,
                ]);
                break;
            }

            if (!password_verify($password, $user['password_hash'])) {
                if (!$isOwner) {
                    $failedAttempts = (int) $user['failed_attempts'] + 1;
                    if ($failedAttempts >= 5) {
                        $nextLevel = (int) $user['suspension_level'] + 1;
                        $suspensionMinutes = [1 => 5, 2 => 15, 3 => 60];

                        if (isset($suspensionMinutes[$nextLevel])) {
                            $lockUntil = date('Y-m-d H:i:s', time() + ($suspensionMinutes[$nextLevel] * 60));
                            $lockStmt = $pdo->prepare(
                                "UPDATE users
                                 SET failed_attempts = 0, locked_until = :locked_until,
                                     suspension_level = :suspension_level
                                 WHERE id = :id"
                            );
                            $lockStmt->execute([
                                ':locked_until' => $lockUntil,
                                ':suspension_level' => $nextLevel,
                                ':id' => $user['id'],
                            ]);
                            $pdo->commit();
                            http_response_code(429);
                            echo json_encode([
                                'error' => "Your account is suspended for {$suspensionMinutes[$nextLevel]} minute(s) after too many failed attempts.",
                                'suspended' => true,
                                'suspension_level' => $nextLevel,
                                'remaining_seconds' => $suspensionMinutes[$nextLevel] * 60,
                            ]);
                            break;
                        }

                        $deactivateStmt = $pdo->prepare(
                            "UPDATE users
                             SET is_active = 0, failed_attempts = 0, locked_until = NULL,
                                 suspension_level = 4,
                                 active_session_id = NULL, active_session_last_seen = NULL
                             WHERE id = :id"
                        );
                        $deactivateStmt->execute([':id' => $user['id']]);
                        $pdo->commit();
                        http_response_code(403);
                        echo json_encode([
                            'error' => 'Your account has been locked out after repeated suspensions. Contact the owner to reactivate it.',
                        ]);
                        break;
                    }

                    $incrementStmt = $pdo->prepare(
                        "UPDATE users SET failed_attempts = :attempts WHERE id = :id"
                    );
                    $incrementStmt->execute([
                        ':attempts' => $failedAttempts,
                        ':id' => $user['id'],
                    ]);
                }

                $pdo->commit();
                http_response_code(401);
                echo json_encode(['error' => 'Invalid email or password.']);
                break;
            }

            $currentSessionId = session_id();
            $hasRecentOtherSession = !empty($user['active_session_id'])
                && $user['active_session_id'] !== $currentSessionId
                && (bool) $user['active_session_is_recent'];

            if ($hasRecentOtherSession) {
                $pdo->commit();
                http_response_code(409);
                echo json_encode([
                    'error' => 'This account is currently signed in on another device. Only one active session is allowed. Sign out from that device or try again after its session expires.',
                    'active_session' => true,
                ]);
                break;
            }

            session_regenerate_id(true);
            $activeSessionId = session_id();
            $reserveSessionStmt = $pdo->prepare(
                "UPDATE users
                 SET failed_attempts = 0, locked_until = NULL,
                     active_session_id = :session_id, active_session_last_seen = NOW()
                 WHERE id = :id"
            );
            $reserveSessionStmt->execute([
                ':session_id' => $activeSessionId,
                ':id' => $user['id'],
            ]);

            if ($user['role'] !== 'owner') {
                logAudit(
                    $pdo,
                    (int) $user['id'],
                    'LOGIN_SUCCESS',
                    'user',
                    (int) $user['id'],
                    json_encode(['role' => $user['role']], JSON_THROW_ON_ERROR)
                );
            }
            $pdo->commit();

            // OWNER PIN VERIFICATION
            if ($user['role'] === 'owner') {
                $_SESSION['pending_owner_id'] = $user['id'];

                echo json_encode([
                    'success' => true,
                    'pin_required' => true,
                ]);
                break;
            }

            // Standard User Login (Admin / Accountant)
            $_SESSION['user_id']        = $user['id'];
            $_SESSION['full_name']      = $user['full_name'];
            $_SESSION['role']           = $user['role'];
            $_SESSION['just_logged_in'] = true; // <-- SET FOR STANDARD USERS

            echo json_encode([
                'success' => true,
                'user' => [
                    'full_name' => $user['full_name'],
                    'role'      => $user['role'],
                ],
            ]);
            break;

    // OWNER PIN VERIFICATION
    case 'verify-pin':
            if (!isset($_SESSION['pending_owner_id'])) {
                http_response_code(403);
                echo json_encode(['error' => 'No pending verification. Please log in again.']);
                break;
            }

            $pin = $_POST['pin'] ?? '';

            if ($pin === '' || strlen($pin) !== 4) {
                http_response_code(422);
                echo json_encode(['error' => 'Please enter your 4-digit PIN.']);
                break;
            }

            $pdo = getDbConnection();
            if (!empty($_SESSION['pending_owner_takeover'])) {
                unset($_SESSION['pending_owner_id'], $_SESSION['pending_owner_takeover'], $_SESSION['pending_owner_previous_session_id']);
                http_response_code(409);
                echo json_encode([
                    'error' => 'Signing in on another device is not allowed while this account is active. Sign out from the active device first.',
                    'active_session' => true,
                ]);
                break;
            }
            $stmt = $pdo->prepare(
                'SELECT id, full_name, role, pin_hash, is_active, active_session_id, deleted_at
                 FROM users WHERE id = :id'
            );
            $stmt->execute([':id' => $_SESSION['pending_owner_id']]);
            $user = $stmt->fetch();

            if (
                !$user
                || (int) $user['is_active'] !== 1
                || $user['deleted_at'] !== null
                || $user['active_session_id'] !== session_id()
            ) {
                unset($_SESSION['pending_owner_id']);
                unset($_SESSION['pending_owner_takeover'], $_SESSION['pending_owner_previous_session_id']);
                http_response_code(403);
                echo json_encode(['error' => 'The owner session changed before verification. Please sign in again.']);
                break;
            }

            if (!password_verify($pin, $user['pin_hash'])) {
                $pdo->prepare(
                    "UPDATE users SET active_session_last_seen = NOW()
                     WHERE id = :id AND active_session_id = :session_id"
                )->execute([':id' => $user['id'], ':session_id' => session_id()]);
                http_response_code(401);
                echo json_encode(['error' => 'Incorrect PIN.']);
                break;
            }

            $pdo->prepare(
                "UPDATE users SET active_session_last_seen = NOW()
                 WHERE id = :id AND active_session_id = :session_id"
            )->execute([':id' => $user['id'], ':session_id' => session_id()]);

            $pdo->prepare(
                "UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = :id"
            )->execute([':id' => $user['id']]);
            logAudit(
                $pdo,
                (int) $user['id'],
                'LOGIN_SUCCESS',
                'user',
                (int) $user['id'],
                json_encode(['role' => $user['role'], 'method' => 'password_and_pin'], JSON_THROW_ON_ERROR)
            );
            // Clear pending state and create session
            unset($_SESSION['pending_owner_id']);
            unset($_SESSION['pending_owner_takeover'], $_SESSION['pending_owner_previous_session_id']);

            $_SESSION['user_id']        = $user['id'];
            $_SESSION['full_name']      = $user['full_name'];
            $_SESSION['role']           = $user['role'];
            $_SESSION['just_logged_in'] = true; // <-- ADDED FOR OWNER PIN SUCCESS

            echo json_encode([
                'success' => true,
                'user' => [
                    'full_name' => $user['full_name'],
                    'role'      => $user['role'],
                ],
            ]);
            break;

    case 'validate-setup-invitation':
            $token = trim($_POST['token'] ?? '');
            if (!preg_match('/\A[a-f0-9]{64}\z/i', $token)) {
                http_response_code(401);
                echo json_encode(['error' => 'This setup link is invalid or has expired. Ask an administrator to resend it.']);
                break;
            }

            $pdo = getDbConnection();
            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM user_setup_invitations i
                 JOIN users u ON u.id = i.user_id
                 WHERE i.token_hash = :token_hash
                   AND i.used_at IS NULL
                   AND i.expires_at > NOW()
                   AND u.password_setup_required = 1
                   AND u.is_active = 1
                   AND u.deleted_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute([':token_hash' => hash('sha256', $token)]);

            if (!$stmt->fetchColumn()) {
                http_response_code(401);
                echo json_encode(['error' => 'This setup link is invalid or has expired. Ask an administrator to resend it.']);
                break;
            }

            echo json_encode(['success' => true]);
            break;

    case 'accept-setup-invitation':
            $token = trim($_POST['token'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';
            if (!preg_match('/\A[a-f0-9]{64}\z/i', $token)) {
                http_response_code(401);
                echo json_encode(['error' => 'This setup link is invalid or has expired. Ask an administrator to resend it.']);
                break;
            }

            if (strlen($newPassword) < 8) {
                http_response_code(422);
                echo json_encode(['error' => 'Password must be at least 8 characters.']);
                break;
            }

            $pdo = getDbConnection();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT i.id AS invitation_id, u.id AS user_id
                 FROM user_setup_invitations i
                 JOIN users u ON u.id = i.user_id
                 WHERE i.token_hash = :token_hash
                   AND i.used_at IS NULL
                   AND i.expires_at > NOW()
                   AND u.password_setup_required = 1
                   AND u.is_active = 1
                   AND u.deleted_at IS NULL
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([':token_hash' => hash('sha256', $token)]);
            $invitation = $stmt->fetch();

            if (!$invitation) {
                $pdo->commit();
                http_response_code(401);
                echo json_encode(['error' => 'This setup link is invalid or has expired. Ask an administrator to resend it.']);
                break;
            }

            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash, password_setup_required = 0,
                     failed_attempts = 0, locked_until = NULL
                 WHERE id = :id'
            )->execute([
                ':password_hash' => $passwordHash,
                ':id' => $invitation['user_id'],
            ]);
            $pdo->prepare(
                'UPDATE user_setup_invitations SET used_at = NOW()
                 WHERE user_id = :user_id AND used_at IS NULL'
            )->execute([':user_id' => $invitation['user_id']]);
            logAudit(
                $pdo,
                (int) $invitation['user_id'],
                'COMPLETE_PASSWORD_SETUP',
                'user',
                (int) $invitation['user_id'],
                json_encode(['method' => 'invitation'], JSON_THROW_ON_ERROR)
            );
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Password set. You can now sign in.']);
            break;

    // OWNER PIN VERIFICATION
    case 'verify-pin':
    if (!isset($_SESSION['pending_owner_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'No pending verification. Please log in again.']);
        break;
    }

    $pin = $_POST['pin'] ?? '';

    if ($pin === '' || strlen($pin) !== 4) {
        http_response_code(422);
        echo json_encode(['error' => 'Please enter your 4-digit PIN.']);
        break;
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, full_name, role, pin_hash, is_active, deleted_at FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['pending_owner_id']]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1 || $user['deleted_at'] !== null) {
        unset($_SESSION['pending_owner_id']);
        http_response_code(403);
        echo json_encode(['error' => 'This account has been deactivated.']);
        break;
    }

    if (!password_verify($pin, $user['pin_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Incorrect PIN.']);
        break;
    }

    // PIN correct — NOW create the real, full session.
    unset($_SESSION['pending_owner_id']);
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role']      = $user['role'];

    echo json_encode([
        'success' => true,
        'user' => [
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
        ],
    ]);
    break;
    

    case 'logout':
        $logoutUserId = (int) ($_SESSION['user_id'] ?? $_SESSION['pending_owner_id'] ?? 0);
        if ($logoutUserId > 0) {
            $pdo = getDbConnection();
            $pdo->prepare(
                "UPDATE users
                 SET active_session_id = NULL, active_session_last_seen = NULL
                 WHERE id = :id AND active_session_id = :session_id"
            )->execute([':id' => $logoutUserId, ':session_id' => session_id()]);
        }

        $_SESSION = []; // clear all session data
        session_destroy(); // destroy the session
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
        }
            echo json_encode(['success' => true]);
        break;

    case 'forgot-password':
        require __DIR__ . '/../auth/request_reset.php';
        exit;

    default:
        http_response_code(400); //400 status code (means "bad request" — a standard HTTP error code)
        echo json_encode(['error' => 'Invalid action']); 
        break;
}