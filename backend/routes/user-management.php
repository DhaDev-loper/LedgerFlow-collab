<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../config/database.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

   case 'list':
    $pdo = getDbConnection();
    $stmt = $pdo->query(
        "SELECT id, full_name, email, role, is_active, created_at 
         FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC"
    );
    $users = $stmt->fetchAll();

    echo json_encode(['success' => true, 'users' => $users]);
    break;

        case 'create':
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'staff';

    if ($fullName === '' || $email === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Full name, email, and password are required.']);
        break;
    }

    if (strlen($password) < 8) {
        http_response_code(422);
        echo json_encode(['error' => 'Password must be at least 8 characters.']);
        break;
    }

    $allowedRoles = ['admin', 'accountant', 'staff'];
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid role.']);
        break;
    }

    $pdo = getDbConnection();

    // Check for duplicate email before attempting insert — gives a
    // clearer error than relying on the database's UNIQUE constraint to fail.
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $checkStmt->execute([':email' => $email]);
    if ($checkStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'A user with this email already exists.']);
        break;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $insertStmt = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, role) VALUES (:full_name, :email, :hash, :role)"
    );
    $insertStmt->execute([
        ':full_name' => $fullName,
        ':email'     => $email,
        ':hash'      => $hash,
        ':role'      => $role,
    ]);

    echo json_encode(['success' => true, 'message' => 'User created successfully.']);
    break;
   
    case 'toggle-status':
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($userId === 0) {
        http_response_code(422);
        echo json_encode(['error' => 'User ID is required.']);
        break;
    }

    // Prevent an admin from accidentally deactivating their own account
    // while logged in — that would lock them out with no way back in.
    if ($userId === (int) $_SESSION['user_id']) {
        http_response_code(400);
        echo json_encode(['error' => 'You cannot deactivate your own account.']);
        break;
    }

    $pdo = getDbConnection();

    $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found.']);
        break;
    }

    $newStatus = $user['is_active'] ? 0 : 1;

    $updateStmt = $pdo->prepare("UPDATE users SET is_active = :status WHERE id = :id");
    $updateStmt->execute([':status' => $newStatus, ':id' => $userId]);

    echo json_encode([
        'success' => true,
        'message' => $newStatus ? 'User activated.' : 'User deactivated.',
        'is_active' => $newStatus,
    ]);
    break;

case 'edit':
    $userId = (int) ($_POST['user_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? '';

    if ($userId === 0 || $fullName === '' || $email === '') {
        http_response_code(422);
        echo json_encode(['error' => 'User ID, name, and email are required.']);
        break;
    }

    $allowedRoles = ['admin', 'accountant', 'staff'];
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid role.']);
        break;
    }

    $pdo = getDbConnection();

    // Check the new email isn't already used by a DIFFERENT user
   $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id AND deleted_at IS NULL");
    $checkStmt->execute([':email' => $email, ':id' => $userId]);
    if ($checkStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Another user already has this email.']);
        break;
    }

    $updateStmt = $pdo->prepare(
        "UPDATE users SET full_name = :full_name, email = :email, role = :role WHERE id = :id"
    );
    $updateStmt->execute([
        ':full_name' => $fullName,
        ':email'     => $email,
        ':role'      => $role,
        ':id'        => $userId,
    ]);

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

    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $userId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found.']);
        break;
    }

    $deleteStmt = $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE id = :id");
    $deleteStmt->execute([':id' => $userId]);

    echo json_encode(['success' => true, 'message' => 'User deleted successfully.']);
    break;
}
