<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require_once __DIR__ . '/../../config/database.php';

$ownerEmail = trim((string) env('OWNER_PIN_EMAIL', ''));
$pin = (string) env('OWNER_PIN', '');

if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Set OWNER_PIN_EMAIL to the owner's valid email address.\n");
    exit(1);
}

if (!preg_match('/\A[0-9]{4}\z/', $pin)) {
    fwrite(STDERR, "Set OWNER_PIN to the owner's four-digit PIN.\n");
    exit(1);
}

$pdo = getDbConnection();
$pinHash = password_hash($pin, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET pin_hash = :pin_hash WHERE email = :email AND role = 'owner'");
$stmt->execute([':pin_hash' => $pinHash, ':email' => $ownerEmail]);

echo $stmt->rowCount() > 0
    ? "PIN set successfully for {$ownerEmail}"
    : "No owner account found with that email — check the role was updated first.";