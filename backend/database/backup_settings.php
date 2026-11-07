<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

require_once __DIR__ . '/../config/env.php';

echo json_encode([
    'bucket' => env('BACKUP_S3_BUCKET', ''),
    'prefix' => env('BACKUP_S3_PREFIX', 'ledgerflow'),
    'endpoint' => env('BACKUP_S3_ENDPOINT_URL', ''),
    'server_side_encryption' => env('BACKUP_S3_SSE', 'AES256'),
    'profile' => env('BACKUP_AWS_PROFILE', ''),
], JSON_THROW_ON_ERROR);
