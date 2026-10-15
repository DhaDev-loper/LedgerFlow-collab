<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';
// Prevent the browser from caching this page at all. Without this,
// pressing the Back button after logout can show a stale cached
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);

    // Figure out how many folders deep the CURRENT script sits inside 
    // back to the project root — whether this guard was reached from
    $backendDir = dirname(__DIR__); // .../backend
    $scriptDir  = dirname($_SERVER['SCRIPT_FILENAME']); // folder of whichever file included this guard

    $relative = ltrim(str_replace($backendDir, '', $scriptDir), DIRECTORY_SEPARATOR);
    $depth = $relative === '' ? 0 : substr_count($relative, DIRECTORY_SEPARATOR) + 1;

    $upSteps = str_repeat('../', $depth + 1);

    header('Location: ' . $upSteps . 'frontend/login.html');
    exit;
} else {
    $stmt = getDbConnection()->prepare(
        'SELECT full_name, role, is_active, locked_until, active_session_id,
                active_session_last_seen,
                (active_session_last_seen >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)) AS active_session_is_recent,
                deleted_at
         FROM users WHERE id = :id'
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    $pdo = getDbConnection();
    $sessionId = session_id();
    $hasRecentOtherSession = $user
        && !empty($user['active_session_id'])
        && $user['active_session_id'] !== $sessionId
        && (bool) $user['active_session_is_recent'];

    if ($user && empty($user['active_session_id'])) {
        $claimStmt = $pdo->prepare(
            'UPDATE users
             SET active_session_id = :session_id, active_session_last_seen = NOW()
             WHERE id = :id AND active_session_id IS NULL'
        );
        $claimStmt->execute([':session_id' => $sessionId, ':id' => $_SESSION['user_id']]);
        if ($claimStmt->rowCount() === 1) {
            $user['active_session_id'] = $sessionId;
        } else {
            $refreshStmt = $pdo->prepare(
                'SELECT active_session_id, active_session_last_seen,
                        (active_session_last_seen >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)) AS active_session_is_recent
                 FROM users WHERE id = :id'
            );
            $refreshStmt->execute([':id' => $_SESSION['user_id']]);
            $sessionState = $refreshStmt->fetch();
            $user['active_session_id'] = $sessionState['active_session_id'] ?? null;
            $user['active_session_last_seen'] = $sessionState['active_session_last_seen'] ?? null;
            $hasRecentOtherSession = !empty($user['active_session_id'])
                && $user['active_session_id'] !== $sessionId
                && (bool) $user['active_session_is_recent'];
        }
    }

    $isSuspended = $user
        && $user['role'] !== 'owner'
        && $user['locked_until'] !== null
        && strtotime($user['locked_until']) > time();

    if (
        !$user
        || (int) $user['is_active'] !== 1
        || $user['deleted_at'] !== null
        || $isSuspended
        || $hasRecentOtherSession
    ) {
        $_SESSION = [];
        session_destroy();
        http_response_code(401);

        $backendDir = dirname(__DIR__);
        $scriptDir = dirname($_SERVER['SCRIPT_FILENAME']);
        $relative = ltrim(str_replace($backendDir, '', $scriptDir), DIRECTORY_SEPARATOR);
        $depth = $relative === '' ? 0 : substr_count($relative, DIRECTORY_SEPARATOR) + 1;
        $upSteps = str_repeat('../', $depth + 1);

        header('Location: ' . $upSteps . 'frontend/login.html');
        exit;
    }

    if ($user['active_session_id'] !== $sessionId) {
        $pdo->prepare(
            'UPDATE users
             SET active_session_id = :session_id, active_session_last_seen = NOW()
             WHERE id = :id AND active_session_id = :previous_session_id
               AND (active_session_last_seen IS NULL
                    OR active_session_last_seen < DATE_SUB(NOW(), INTERVAL 30 MINUTE))'
        )->execute([
            ':session_id' => $sessionId,
            ':id' => $_SESSION['user_id'],
            ':previous_session_id' => $user['active_session_id'],
        ]);
        $refreshStmt = $pdo->prepare(
            'SELECT active_session_id FROM users WHERE id = :id'
        );
        $refreshStmt->execute([':id' => $_SESSION['user_id']]);
        if ($refreshStmt->fetchColumn() !== $sessionId) {
            $_SESSION = [];
            session_destroy();
            http_response_code(401);
            $backendDir = dirname(__DIR__);
            $scriptDir = dirname($_SERVER['SCRIPT_FILENAME']);
            $relative = ltrim(str_replace($backendDir, '', $scriptDir), DIRECTORY_SEPARATOR);
            $depth = $relative === '' ? 0 : substr_count($relative, DIRECTORY_SEPARATOR) + 1;
            $upSteps = str_repeat('../', $depth + 1);
            header('Location: ' . $upSteps . 'frontend/login.html');
            exit;
        }
    } else {
        $heartbeatStmt = $pdo->prepare(
            'UPDATE users SET active_session_last_seen = NOW() WHERE id = :id AND active_session_id = :session_id'
        );
        $heartbeatStmt->execute([':id' => $_SESSION['user_id'], ':session_id' => $sessionId]);
        if ($heartbeatStmt->rowCount() !== 1) {
            $sessionCheckStmt = $pdo->prepare('SELECT active_session_id FROM users WHERE id = :id');
            $sessionCheckStmt->execute([':id' => $_SESSION['user_id']]);
            if ($sessionCheckStmt->fetchColumn() !== $sessionId) {
                $_SESSION = [];
                session_destroy();
                http_response_code(401);
                $backendDir = dirname(__DIR__);
                $scriptDir = dirname($_SERVER['SCRIPT_FILENAME']);
                $relative = ltrim(str_replace($backendDir, '', $scriptDir), DIRECTORY_SEPARATOR);
                $depth = $relative === '' ? 0 : substr_count($relative, DIRECTORY_SEPARATOR) + 1;
                $upSteps = str_repeat('../', $depth + 1);
                header('Location: ' . $upSteps . 'frontend/login.html');
                exit;
            }
        }
    }

    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
}
