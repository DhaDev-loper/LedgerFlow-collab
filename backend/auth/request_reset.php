<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respondWithGenericRecoveryMessage(): void
{
    echo json_encode([
        'success' => true,
        'message' => 'If the email exists, a recovery link has been sent.',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';

$emailInput = $_POST['email'] ?? '';
$email = is_string($emailInput) ? trim($emailInput) : '';
$user = null;

try {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respondWithGenericRecoveryMessage();
        exit;
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT id, full_name
         FROM users
         WHERE email = :email
           AND deleted_at IS NULL
           AND password_setup_required = 0
           AND (is_active = 1 OR suspension_level = 4)
         LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $update = $pdo->prepare(
            'UPDATE users
             SET reset_token_hash = :token_hash,
                 reset_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
             WHERE id = :id'
        );
        $update->execute([
            ':token_hash' => $tokenHash,
            ':id' => $user['id'],
        ]);
    }
} catch (Throwable $exception) {
    error_log('Self-service recovery request failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to process the recovery request. Please try again.']);
    exit;
}

if ($user) {
    try {
        $appUrl = rtrim((string) env('APP_URL', ''), '/');
        $urlParts = parse_url($appUrl);
        $validAppUrl = $appUrl !== ''
            && is_array($urlParts)
            && in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
            && !empty($urlParts['host']);

        if ($validAppUrl) {
            require_once __DIR__ . '/../config/mailer.php';

            $resetUrl = $appUrl . '/frontend/reset-password.html?token=' . rawurlencode($token);
            $safeName = htmlspecialchars($user['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $emailSent = sendEmail(
                $email,
                $user['full_name'],
                'Recover Your LedgerFlow Account',
                "<p>Hello {$safeName},</p>
                 <p>Use the link below to reset your password and unlock your account if it was suspended after repeated failed sign-in attempts.</p>
                 <p><a href=\"{$safeUrl}\">Reset your password</a></p>
                 <p>This one-time link expires in 15 minutes. If you didn't request this, you can safely ignore this email.</p>"
            );

            if (!$emailSent) {
                error_log('Self-service recovery email could not be sent.');
            }
        } else {
            error_log('Self-service recovery email could not be sent: APP_URL must be an absolute HTTP(S) URL.');
        }
    } catch (Throwable $exception) {
        error_log('Self-service recovery email could not be sent: ' . $exception->getMessage());
    }
}

respondWithGenericRecoveryMessage();
