<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this seed from the command line.\n");
}

require_once __DIR__ . '/../../config/database.php';

$fullName = 'KoboSocial';
$email = 'KoboSocial@gmail.com';
$password = getenv('OWNER_SEED_PASSWORD');
$pin = getenv('OWNER_SEED_PIN');

if (!is_string($password) || strlen($password) < 8) {
    fwrite(STDERR, "Set OWNER_SEED_PASSWORD to a password of at least 8 characters before running this seed.\n");
    exit(1);
}

if (!is_string($pin) || !preg_match('/\A[0-9]{4}\z/', $pin)) {
    fwrite(STDERR, "Set OWNER_SEED_PIN to the owner's four-digit PIN before running this seed.\n");
    exit(1);
}

$pdo = getDbConnection();
$stmt = $pdo->prepare('SELECT id, role FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$existingUser = $stmt->fetch();

if ($existingUser) {
    if ($existingUser['role'] !== 'owner') {
        fwrite(STDERR, "The email already belongs to a non-owner account. No changes were made.\n");
        exit(1);
    }

    echo "The owner account already exists. No changes were made.\n";
    exit(0);
}

$insert = $pdo->prepare(
    'INSERT INTO users (full_name, email, password_hash, pin_hash, role)
     VALUES (:full_name, :email, :password_hash, :pin_hash, \'owner\')'
);
$insert->execute([
    ':full_name' => $fullName,
    ':email' => $email,
    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ':pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
]);

echo "Official owner account created for {$email}.\n";
