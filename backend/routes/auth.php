<?php

//auth.php is a backend API endpoint, not a page
declare(strict_types=1);
// this file handles login systems and user authentication
// to ensure that the user is who they say they are and to manage their session
$remember = isset($_POST['remember']) && $_POST['remember'] === '1';

if ($remember) {
    $lifetime = 30 * 24 * 60 * 60; // 30 days, in seconds
    session_set_cookie_params(['lifetime' => $lifetime]);
    ini_set('session.gc_maxlifetime', (string) $lifetime);
}
session_start(); // start session so that php can track who is logged in and who is not

require_once __DIR__ . '/../config/database.php';
//this reads a value called action from the incoming request

$action = $_POST['action'] ?? $_GET['action'] ?? ''; // the double ? if the value is not set it will default and not crash the system

switch ($action) {
   case 'login':
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Email and password are required.']);
        break;
    }

    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        "SELECT id, full_name, email, password_hash, role, is_active, failed_attempts, locked_until 
         FROM users WHERE email = :email"
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    // Check lockout BEFORE checking the password — no point verifying
    // a password on an account that's currently locked out anyway.
    if ($user && $user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
        $minutesLeft = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
        http_response_code(429);
        echo json_encode(['error' => "Too many failed attempts. Please try again in {$minutesLeft} minute(s)."]);
        break;
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {

        // Only increment the counter if the account actually exists —
        // no point tracking attempts against emails that aren't registered.
        if ($user) {
            $newAttempts = $user['failed_attempts'] + 1;
            $maxAttempts = 5;

            if ($newAttempts >= $maxAttempts) {
                $lockUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                $lockStmt = $pdo->prepare(
                    "UPDATE users SET failed_attempts = :attempts, locked_until = :locked WHERE id = :id"
                );
                $lockStmt->execute([
                    ':attempts' => $newAttempts,
                    ':locked'   => $lockUntil,
                    ':id'       => $user['id'],
                ]);
            } else {
                $incrementStmt = $pdo->prepare(
                    "UPDATE users SET failed_attempts = :attempts WHERE id = :id"
                );
                $incrementStmt->execute([
                    ':attempts' => $newAttempts,
                    ':id'       => $user['id'],
                ]);
            }
        }

        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password.']);
        break;
    }

    if ((int)$user['is_active'] !== 1) {
        http_response_code(403);
        echo json_encode(['error' => 'This account has been deactivated.']);
        break;
    }

    // Successful login — reset the failed attempt counter and any lock.
    $resetStmt = $pdo->prepare(
        "UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = :id"
    );
    $resetStmt->execute([':id' => $user['id']]);

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

        case 'forgot-password':
    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Email is required.']);
        break;
    }

    $pdo = getDbConnection();

    $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email = :email AND is_active = 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    // Always respond the same way, whether or not the email exists.
    // This prevents attackers from using this form to discover which emails are registered.
    $genericResponse = ['success' => true, 'message' => 'If that email exists in our system, a code has been sent.'];

    if (!$user) {
        echo json_encode($genericResponse);
        break;
    }

    $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $insertStmt = $pdo->prepare(
        "INSERT INTO password_resets (user_id, otp_code, expires_at) VALUES (:user_id, :otp_code, :expires_at)"
    );
    $insertStmt->execute([
        ':user_id'    => $user['id'],
        ':otp_code'   => $otpCode,
        ':expires_at' => $expiresAt,
    ]);

    require_once __DIR__ . '/../config/mailer.php';

    $emailSent = sendEmail(
        $email,
        $user['full_name'],
        'Your Password Reset Code',
        "<p>Hello {$user['full_name']},</p>
         <p>Your password reset code is:</p>
         <h2 style='letter-spacing: 4px;'>{$otpCode}</h2>
         <p>This code expires in 10 minutes. If you didn't request this, you can safely ignore this email.</p>"
    );

    if (!$emailSent) {
        error_log("Failed to send OTP email to {$email}");
    }

    echo json_encode($genericResponse);
    break;

    case 'verify-otp':
    $email = trim($_POST['email'] ?? '');
    $otpCode = trim($_POST['otp_code'] ?? '');

    if ($email === '' || $otpCode === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Email and code are required.']);
        break;
    }

    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        "SELECT pr.id, pr.expires_at, pr.is_used, u.id AS user_id
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE u.email = :email AND pr.otp_code = :otp_code
         ORDER BY pr.created_at DESC
         LIMIT 1"
    );
    $stmt->execute([':email' => $email, ':otp_code' => $otpCode]);
    $reset = $stmt->fetch();

    if (!$reset) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid code.']);
        break;
    }

    if ((int) $reset['is_used'] === 1) {
        http_response_code(401);
        echo json_encode(['error' => 'This code has already been used.']);
        break;
    }

    if (strtotime($reset['expires_at']) < time()) {
        http_response_code(401);
        echo json_encode(['error' => 'This code has expired. Please request a new one.']);
        break;
    }

    // Code is valid — store a short-lived "verified" flag in the session
    // so the next step (reset-password) knows this user is allowed to proceed.
    $_SESSION['password_reset_user_id'] = $reset['user_id'];
    $_SESSION['password_reset_id'] = $reset['id'];

    echo json_encode(['success' => true]);
    break;

    case 'reset-password':
    if (!isset($_SESSION['password_reset_user_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Please verify your code first.']);
        break;
    }

    $newPassword = $_POST['new_password'] ?? '';

    if (strlen($newPassword) < 8) {
        http_response_code(422);
        echo json_encode(['error' => 'Password must be at least 8 characters.']);
        break;
    }

    $pdo = getDbConnection();
    $userId = $_SESSION['password_reset_user_id'];
    $resetId = $_SESSION['password_reset_id'];
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    try {
        $pdo->beginTransaction();

        $updateUser = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $updateUser->execute([':hash' => $newHash, ':id' => $userId]);

        $markUsed = $pdo->prepare("UPDATE password_resets SET is_used = 1 WHERE id = :id");
        $markUsed->execute([':id' => $resetId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Password reset transaction failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Something went wrong. Please try again.']);
        break;
    }

    unset($_SESSION['password_reset_user_id'], $_SESSION['password_reset_id']);

    echo json_encode(['success' => true]);
    break;

    unset($_SESSION['password_reset_user_id'], $_SESSION['password_reset_id']);

    echo json_encode(['success' => true]);
    break;

    case 'logout':
        $_SESSION = []; // clear all session data
        session_destroy(); // destroy the session
            echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400); //400 status code (means "bad request" — a standard HTTP error code)
        echo json_encode(['error' => 'Invalid action']); 
        break;
}