<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit_helper.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'get-business':
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT * FROM business_settings WHERE id = 1");
        $settings = $stmt->fetch();

        echo json_encode(['success' => true, 'settings' => $settings]);
        break;

    case 'update-business':
        if (!in_array($_SESSION['role'], ['admin', 'owner'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Only admins can update business settings.']);
            break;
        }

        $businessName = trim($_POST['business_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');

        if ($businessName === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Business name is required.']);
            break;
        }

        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            "UPDATE business_settings SET business_name = :name, address = :address, contact_number = :contact WHERE id = 1"
        );
        $stmt->execute([
            ':name' => $businessName,
            ':address' => $address,
            ':contact' => $contactNumber,
        ]);
        logAudit(
            $pdo,
            (int) $_SESSION['user_id'],
            'UPDATE_BUSINESS_SETTINGS',
            'business_settings',
            1,
            json_encode(['fields' => ['business_name', 'address', 'contact_number']], JSON_THROW_ON_ERROR)
        );

        echo json_encode(['success' => true, 'message' => 'Business settings updated.']);
        break;

    case 'change-password':
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';

        if (!is_string($currentPassword) || !is_string($newPassword) || strlen($newPassword) < 8) {
            http_response_code(422);
            echo json_encode(['error' => 'New password must be at least 8 characters.']);
            break;
        }

        $pdo = getDbConnection();
        $pdo->beginTransaction();
        try {
            $userId = (int) $_SESSION['user_id'];
            $stmt = $pdo->prepare('SELECT password_hash, active_session_id FROM users WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                $pdo->rollBack();
                http_response_code(401);
                echo json_encode(['error' => 'Current password is incorrect.']);
                break;
            }

            $previousSessionId = session_id();
            if (!session_regenerate_id(true)) {
                throw new RuntimeException('Unable to rotate the session after password change.');
            }
            $updateStmt = $pdo->prepare(
                'UPDATE users
                 SET password_hash = :hash, reset_token_hash = NULL, reset_expires_at = NULL,
                     active_session_id = :new_session_id, active_session_last_seen = NOW()
                 WHERE id = :id AND active_session_id = :previous_session_id'
            );
            $updateStmt->execute([
                ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':new_session_id' => session_id(),
                ':id' => $userId,
                ':previous_session_id' => $previousSessionId,
            ]);
            if ($updateStmt->rowCount() !== 1) {
                throw new RuntimeException('The active session changed during the password update.');
            }
            logAudit($pdo, $userId, 'CHANGE_PASSWORD', 'user', $userId, '{"method":"current_password"}');
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Password change failed: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Password could not be changed. Please try again.']);
            break;
        }

        echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);
        break;

    case 'change-pin':
        if ($_SESSION['role'] !== 'owner') {
            http_response_code(403);
            echo json_encode(['error' => 'Only the Owner account has a PIN.']);
            break;
        }

        $currentPin = $_POST['current_pin'] ?? '';
        $newPin = $_POST['new_pin'] ?? '';

        if (strlen($newPin) !== 4 || !ctype_digit($newPin)) {
            http_response_code(422);
            echo json_encode(['error' => 'New PIN must be exactly 4 digits.']);
            break;
        }

        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT pin_hash FROM users WHERE id = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!password_verify($currentPin, $user['pin_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Current PIN is incorrect.']);
            break;
        }

        $newHash = password_hash($newPin, PASSWORD_DEFAULT);
        $updateStmt = $pdo->prepare("UPDATE users SET pin_hash = :hash, pin_failed_attempts = 0, pin_locked_until = NULL WHERE id = :id");
        $updateStmt->execute([':hash' => $newHash, ':id' => $_SESSION['user_id']]);
        logAudit($pdo, (int) $_SESSION['user_id'], 'CHANGE_OWNER_PIN', 'user', (int) $_SESSION['user_id'], '{"method":"current_pin"}');

        echo json_encode(['success' => true, 'message' => 'PIN changed successfully.']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
