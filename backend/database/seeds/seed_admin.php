<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this seed from the command line.\n");
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/setup_invitation.php';

$fullName = trim((string) env('ADMIN_SEED_NAME', ''));
$email = trim((string) env('ADMIN_SEED_EMAIL', ''));

if ($fullName === '' || strlen($fullName) > 100) {
    fwrite(STDERR, "Set ADMIN_SEED_NAME to the administrator's name (up to 100 characters).\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    fwrite(STDERR, "Set ADMIN_SEED_EMAIL to the administrator's valid email address (up to 150 characters).\n");
    exit(1);
}

$appUrl = rtrim((string) env('APP_URL', ''), '/');
$urlParts = parse_url($appUrl);
if (
    $appUrl === ''
    || !is_array($urlParts)
    || !in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
    || empty($urlParts['host'])
) {
    fwrite(STDERR, "Set APP_URL to the absolute HTTP(S) URL of the application before creating the account.\n");
    exit(1);
}

$pdo = getDbConnection();
$passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, password_setup_required, role)
         VALUES (:full_name, :email, :password_hash, 1, 'admin')"
    );
    $insert->execute([
        ':full_name' => $fullName,
        ':email' => $email,
        ':password_hash' => $passwordHash,
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
    echo "Administrator account created. A one-time password setup link was sent to {$email}.\n";
    exit(0);
}

$setupUrl = $appUrl . '/frontend/reset-password.html?invite=' . rawurlencode($token);
fwrite(STDERR, "Administrator account was created, but the setup email could not be sent.\n");
fwrite(STDERR, "Use this one-time link within 24 hours to set the administrator password:\n{$setupUrl}\n");
exit(1);
