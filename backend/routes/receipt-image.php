<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth_guard.php';

$fileName = (string) ($_GET['file'] ?? '');
if (!preg_match('/\Areceipt_[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp|gif|bmp)\z/i', $fileName)) {
    http_response_code(404);
    exit;
}

$receiptDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads'
    . DIRECTORY_SEPARATOR . 'receipts';
$path = $receiptDirectory . DIRECTORY_SEPARATOR . $fileName;
$realDirectory = realpath($receiptDirectory);
$realPath = realpath($path);
if (
    $realDirectory === false
    || $realPath === false
    || !is_file($realPath)
    || dirname($realPath) !== $realDirectory
) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($realPath);
if (!is_string($mime) || !in_array($mime, [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp',
], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($realPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($realPath);
