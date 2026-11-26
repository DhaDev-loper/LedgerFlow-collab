<?php
declare(strict_types=1);

function startApplicationSession(?int $cookieLifetime = null): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // TLS may terminate at the web server or load balancer. Configure PHP's
    // HTTPS variable there; do not trust arbitrary forwarded headers here.
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params([
        'lifetime' => $cookieLifetime ?? (int) ini_get('session.cookie_lifetime'),
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function requireSameOriginForWriteRequests(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $configuredUrl = parse_url((string) env('APP_URL', ''));
    $sourceUrl = parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if (!$sourceUrl && !empty($_SERVER['HTTP_REFERER'])) {
        $sourceUrl = parse_url((string) $_SERVER['HTTP_REFERER']);
    }

    $configuredScheme = strtolower((string) ($configuredUrl['scheme'] ?? ''));
    $sourceScheme = strtolower((string) ($sourceUrl['scheme'] ?? ''));
    $configuredHost = strtolower((string) ($configuredUrl['host'] ?? ''));
    $sourceHost = strtolower((string) ($sourceUrl['host'] ?? ''));
    $configuredPort = (int) ($configuredUrl['port'] ?? ($configuredScheme === 'https' ? 443 : 80));
    $sourcePort = (int) ($sourceUrl['port'] ?? ($sourceScheme === 'https' ? 443 : 80));

    if (
        !in_array($configuredScheme, ['http', 'https'], true)
        || $configuredHost === ''
        || $sourceHost === ''
        || $sourceScheme !== $configuredScheme
        || $sourceHost !== $configuredHost
        || $sourcePort !== $configuredPort
        || strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin')) === 'cross-site'
    ) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'This request did not come from the configured application origin.']);
        exit;
    }
}
