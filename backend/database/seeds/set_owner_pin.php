<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

$ownerEmail = 'admin@gmail.com'; // change this to match
$pin = '1234'; // change this to your real 4-digit PIN

$pinHash = password_hash($pin, PASSWORD_DEFAULT);

$pdo = getDbConnection();
$stmt = $pdo->prepare("UPDATE users SET pin_hash = :pin_hash WHERE email = :email AND role = 'owner'");
$stmt->execute([':pin_hash' => $pinHash, ':email' => $ownerEmail]);

echo $stmt->rowCount() > 0
    ? "PIN set successfully for {$ownerEmail}"
    : "No owner account found with that email — check the role was updated first.";