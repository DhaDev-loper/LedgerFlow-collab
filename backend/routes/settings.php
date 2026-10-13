<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

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

        echo json_encode(['success' => true, 'message' => 'Business settings updated.']);
        break;

    case 'change-password':
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';

        if (strlen($newPassword) < 8) {
            http_response_code(422);
            echo json_encode(['error' => 'New password must be at least 8 characters.']);
            break;
        }

        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!password_verify($currentPassword, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Current password is incorrect.']);
            break;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $updateStmt->execute([':hash' => $newHash, ':id' => $_SESSION['user_id']]);

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
        $updateStmt = $pdo->prepare("UPDATE users SET pin_hash = :hash WHERE id = :id");
        $updateStmt->execute([':hash' => $newHash, ':id' => $_SESSION['user_id']]);

        echo json_encode(['success' => true, 'message' => 'PIN changed successfully.']);
        break;

    case 'list-categories':
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT id, name, is_active FROM categories ORDER BY name");
        echo json_encode(['success' => true, 'categories' => $stmt->fetchAll()]);
        break;

    case 'create-category':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Category name is required.']);
            break;
        }

        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (:name)");
        $stmt->execute([':name' => $name]);

        echo json_encode(['success' => true, 'message' => 'Category created.']);
        break;

    case 'toggle-category':
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $pdo = getDbConnection();

        $stmt = $pdo->prepare("SELECT is_active FROM categories WHERE id = :id");
        $stmt->execute([':id' => $categoryId]);
        $category = $stmt->fetch();

        if (!$category) {
            http_response_code(404);
            echo json_encode(['error' => 'Category not found.']);
            break;
        }

        $newStatus = $category['is_active'] ? 0 : 1;
        $updateStmt = $pdo->prepare("UPDATE categories SET is_active = :status WHERE id = :id");
        $updateStmt->execute([':status' => $newStatus, ':id' => $categoryId]);

        echo json_encode(['success' => true, 'is_active' => $newStatus]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}