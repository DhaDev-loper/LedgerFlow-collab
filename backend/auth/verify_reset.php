<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respondWithInvalidRecoveryToken(): void
{
    http_response_code(400);
    echo json_encode(['error' => 'This recovery link is invalid, expired, or has already been used.']);
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$tokenInput = $_POST['token'] ?? $_GET['token'] ?? '';
$token = is_string($tokenInput) ? trim($tokenInput) : '';
if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
    respondWithInvalidRecoveryToken();
    exit;
}

if ($method === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    if (!is_string($newPassword) || strlen($newPassword) < 8) {
        http_response_code(422);
        echo json_encode(['error' => 'Password must be at least 8 characters.']);
        exit;
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit_helper.php';

$tokenHash = hash('sha256', $token);
$pdo = null;

try {
    $pdo = getDbConnection();

    if ($method === 'POST') {
        $pdo->beginTransaction();
    }

    $stmt = $pdo->prepare(
        'SELECT id, is_active, suspension_level, locked_until
         FROM users
         WHERE reset_token_hash = :token_hash
           AND reset_expires_at > NOW()
           AND deleted_at IS NULL
           AND password_setup_required = 0
           AND (is_active = 1 OR suspension_level = 4)'
        . ($method === 'POST' ? ' FOR UPDATE' : '')
    );
    $stmt->execute([':token_hash' => $tokenHash]);
    $user = $stmt->fetch();

    if (!$user) {
        if ($method === 'POST') {
            $pdo->rollBack();
        }
        respondWithInvalidRecoveryToken();
        exit;
    }

    if ($method === 'GET') {
        echo json_encode(['success' => true, 'valid' => true]);
        exit;
    }

    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $pdo->prepare(
        'UPDATE users
         SET password_hash = :password_hash,
             failed_attempts = 0,
             locked_until = NULL,
             suspension_level = 0,
             is_active = 1,
             active_session_id = NULL,
             active_session_last_seen = NULL,
             reset_token_hash = NULL,
             reset_expires_at = NULL
         WHERE id = :id
           AND reset_token_hash = :token_hash
           AND reset_expires_at > NOW()'
    );
    $update->execute([
        ':password_hash' => $newPasswordHash,
        ':id' => $user['id'],
        ':token_hash' => $tokenHash,
    ]);

    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        respondWithInvalidRecoveryToken();
        exit;
    }

    logAudit(
        $pdo,
        (int) $user['id'],
        'SELF_SERVICE_ACCOUNT_RECOVERY',
        'user',
        (int) $user['id'],
        json_encode([
            'method' => 'recovery_link',
            'account_unlocked' => (int) $user['is_active'] !== 1
                || (int) $user['suspension_level'] === 4
                || $user['locked_until'] !== null,
        ], JSON_THROW_ON_ERROR)
    );
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Password reset. You can now sign in.']);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Self-service recovery verification failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to complete account recovery. Please try again.']);
}
