<?php
declare(strict_types=1);
//For admin pages so that other acc cant access the user management page
require_once __DIR__ . '/auth_guard.php';

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die('<h2>Access Denied</h2><p>This page is restricted to administrators.</p>');
}