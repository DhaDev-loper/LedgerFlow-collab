<?php
declare(strict_types=1);

function createSetupInvitation(PDO $pdo, int $userId): string
{
    $pdo->prepare(
        'UPDATE user_setup_invitations
         SET used_at = NOW()
         WHERE user_id = :user_id AND used_at IS NULL'
    )->execute([':user_id' => $userId]);

    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        'INSERT INTO user_setup_invitations (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':token_hash' => hash('sha256', $token),
    ]);

    return $token;
}

function sendSetupInvitation(string $email, string $fullName, string $token): bool
{
    require_once __DIR__ . '/mailer.php';

    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    $urlParts = parse_url($appUrl);
    if (
        $appUrl === ''
        || !is_array($urlParts)
        || !in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
        || empty($urlParts['host'])
    ) {
        error_log('User setup invitation could not be sent: APP_URL must be an absolute HTTP(S) URL.');
        return false;
    }

    $setupUrl = $appUrl . '/frontend/reset-password.html?invite=' . rawurlencode($token);
    $safeName = htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($setupUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return sendEmail(
        $email,
        $fullName,
        'Set Up Your LedgerFlow Account',
        "<p>Hello {$safeName},</p>
         <p>An account has been created for you in LedgerFlow. Use the button below to choose your password.</p>
         <p><a href=\"{$safeUrl}\" style=\"display:inline-block;padding:12px 20px;background:#b0004b;color:#fff;text-decoration:none;border-radius:6px;\">Set up your password</a></p>
         <p>This one-time link expires in 24 hours. If you weren't expecting this invitation, you can ignore this email.</p>"
    );
}
