<?php
declare(strict_types=1);
session_start();
// Prevent the browser from caching this page at all. Without this,
// pressing the Back button after logout can show a stale cached
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);

    // Figure out how many folders deep the CURRENT script sits inside 
    // back to the project root — whether this guard was reached from
    $backendDir = dirname(__DIR__); // .../backend
    $scriptDir  = dirname($_SERVER['SCRIPT_FILENAME']); // folder of whichever file included this guard

    $relative = ltrim(str_replace($backendDir, '', $scriptDir), DIRECTORY_SEPARATOR);
    $depth = $relative === '' ? 0 : substr_count($relative, DIRECTORY_SEPARATOR) + 1;

    $upSteps = str_repeat('../', $depth + 1);

    header('Location: ' . $upSteps . 'frontend/login.html');
    exit;
}

