<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';

if (!in_array($_SESSION['role'], ['admin', 'owner'], true)) {
    http_response_code(403);
    die('<h2>Access Denied</h2><p>This page is restricted to administrators.</p>');
}