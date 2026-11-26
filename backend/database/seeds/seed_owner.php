<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this seed from the command line.\n");
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/setup_invitation.php';

$fullName = trim((string) env('OWNER_SEED_NAME', ''));
$email = trim((string) env('OWNER_SEED_EMAIL', ''));
$pin = (string) env('OWNER_SEED_PIN', '');
$appUrl = rtrim((string) env('APP_URL', ''), '/');

if ($fullName === '' || strlen($fullName) > 100) {
    fwrite(STDERR, "Set OWNER_SEED_NAME to the owner's name (up to 100 characters).\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    fwrite(STDERR, "Set OWNER_SEED_EMAIL to the owner's valid email address.\n");
    exit(1);
}
if (!preg_match('/\A[0-9]{4}\z/', $pin)) {
    fwrite(STDERR, "Set OWNER_SEED_PIN to the owner's four-digit PIN.\n");
    exit(1);
}
$urlParts = parse_url($appUrl);
if (
    $appUrl === ''
    || !is_array($urlParts)
    || !in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
    || empty($urlParts['host'])
) {
    fwrite(STDERR, "Set APP_URL to the application's absolute HTTP(S) URL before creating the owner.\n");
    exit(1);
}

$pdo = getDbConnection();
$existing = $pdo->prepare('SELECT role FROM users WHERE email = :email LIMIT 1');
$existing->execute([':email' => $email]);
$existingRole = $existing->fetchColumn();
if ($existingRole !== false) {
    if ($existingRole !== 'owner') {
        fwrite(STDERR, "That email already belongs to a non-owner account. No changes were made.\n");
        exit(1);
    }
    echo "The owner account already exists. No changes were made.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, password_setup_required, pin_hash, role)
         VALUES (:full_name, :email, :password_hash, 1, :pin_hash, 'owner')"
    );
    $insert->execute([
        ':full_name' => $fullName,
        ':email' => $email,
        ':password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        ':pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $pdo->lastInsertId();
    $token = createSetupInvitation($pdo, $userId);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

if (sendSetupInvitation($email, $fullName, $token)) {
    echo "Owner account created. A one-time password setup link was sent to {$email}.\n";
    exit(0);
}

$setupUrl = $appUrl . '/frontend/reset-password.html?invite=' . rawurlencode($token);
fwrite(STDERR, "Owner account was created, but the setup email could not be sent.\n");
fwrite(STDERR, "Use this one-time link within 24 hours to set the owner password:\n{$setupUrl}\n");
exit(1);
