<?php 
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

$fullName = 'Admin User';
$email = 'admin@gmail.com';
$PlainPassword = 'admin123';
$role = 'admin';

$hashedPassword = password_hash($PlainPassword, PASSWORD_DEFAULT);

$pdo = getDbConnection();

$sql = "INSERT INTO users (full_name, email, password_hash, role) 
        VALUES (:full_name, :email, :password_hash, :role)";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    ':full_name'      => $fullName,
    ':email'          => $email,
    ':password_hash'  => $hashedPassword,
    ':role'           => $role,
]);

echo "Admin user created successfully! Email: {$email}";