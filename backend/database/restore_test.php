<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php restore_test.php <backup-zip> <mysql-path>\n");
    exit(2);
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "PHP ZipArchive is required.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

$archivePath = $argv[1];
$mysqlTool = $argv[2];
$archivePassword = getenv('BACKUP_ARCHIVE_PASSWORD');

if (!is_string($archivePassword) || strlen($archivePassword) < 16) {
    fwrite(STDERR, "Set BACKUP_ARCHIVE_PASSWORD to the password used to encrypt the backup.\n");
    exit(1);
}

if (!is_file($archivePath)) {
    fwrite(STDERR, "Backup archive does not exist: {$archivePath}\n");
    exit(1);
}

if (!is_file($mysqlTool)) {
    fwrite(STDERR, "mysql was not found at the supplied path: {$mysqlTool}\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($archivePath) !== true) {
    throw new RuntimeException('Unable to open the backup archive.');
}
$zipOpen = true;

$pdo = null;
$restoreDatabase = 'ledgerflow_restore_test_' . bin2hex(random_bytes(6));
$restoreDatabaseCreated = false;
$workDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'ledgerflow-restore-' . bin2hex(random_bytes(8));

try {
    $zip->setPassword($archivePassword);
    $manifestJson = $zip->getFromName('manifest.json');
    if (!is_string($manifestJson)) {
        throw new RuntimeException('Backup manifest is missing or could not be decrypted.');
    }

    $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
    if (
        !is_array($manifest)
        || ($manifest['format_version'] ?? null) !== 1
        || !is_array($manifest['tables'] ?? null)
        || !is_array($manifest['receipts_sha256'] ?? null)
    ) {
        throw new RuntimeException('Backup manifest has an unsupported or invalid format.');
    }

    $expectedEntryNames = ['database.sql', 'manifest.json'];
    foreach ($manifest['receipts_sha256'] as $relativePath => $expectedHash) {
        if (
            !is_string($relativePath)
            || !preg_match('/\A[A-Za-z0-9._-]+\z/', $relativePath)
            || in_array($relativePath, ['.', '..'], true)
            || !is_string($expectedHash)
            || !preg_match('/\A[a-f0-9]{64}\z/', $expectedHash)
        ) {
            throw new RuntimeException('Backup receipt manifest contains an invalid entry.');
        }
        $expectedEntryNames[] = 'uploads/receipts/' . $relativePath;
    }

    foreach ($manifest['tables'] as $tableName) {
        if (!is_string($tableName) || $tableName === '') {
            throw new RuntimeException('Backup table manifest contains an invalid entry.');
        }
    }

    $actualEntryNames = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entryName = $zip->getNameIndex($index);
        if (
            !is_string($entryName)
            || str_contains($entryName, "\0")
            || str_contains($entryName, '\\')
            || str_starts_with($entryName, '/')
            || preg_match('~(?:^|/)\.\.(?:/|$)~', $entryName)
            || (
                $entryName !== 'database.sql'
                && $entryName !== 'manifest.json'
                && !preg_match('~\Auploads/receipts/[A-Za-z0-9._-]+\z~', $entryName)
            )
        ) {
            throw new RuntimeException('Backup archive contains an unexpected or unsafe path.');
        }
        $actualEntryNames[] = $entryName;
    }
    sort($expectedEntryNames, SORT_STRING);
    sort($actualEntryNames, SORT_STRING);
    if ($actualEntryNames !== $expectedEntryNames) {
        throw new RuntimeException('Backup archive contents do not match its manifest.');
    }

    if (!mkdir($workDirectory, 0700)) {
        throw new RuntimeException("Unable to create restore work directory: {$workDirectory}");
    }
    $receiptOutputDirectory = $workDirectory . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'receipts';
    if ($manifest['receipts_sha256'] !== [] && !mkdir($receiptOutputDirectory, 0700, true)) {
        throw new RuntimeException('Unable to create the temporary receipt restore directory.');
    }

    foreach ($expectedEntryNames as $entryName) {
        if ($entryName === 'database.sql' || $entryName === 'manifest.json') {
            $outputPath = $workDirectory . DIRECTORY_SEPARATOR . $entryName;
        } else {
            $relativePath = substr($entryName, strlen('uploads/receipts/'));
            $outputPath = $receiptOutputDirectory . DIRECTORY_SEPARATOR . $relativePath;
        }

        $input = $zip->getStream($entryName);
        if (!is_resource($input)) {
            throw new RuntimeException("Unable to decrypt backup entry: {$entryName}");
        }
        $output = fopen($outputPath, 'xb');
        if (!is_resource($output)) {
            fclose($input);
            throw new RuntimeException("Unable to create temporary restore file: {$entryName}");
        }

        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException("Unable to extract backup entry: {$entryName}");
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    $zip->close();
    $zipOpen = false;

    $sqlPath = $workDirectory . DIRECTORY_SEPARATOR . 'database.sql';
    if (!is_file($sqlPath) || filesize($sqlPath) === 0) {
        throw new RuntimeException('Backup database export is missing or empty.');
    }

    $host = (string) env('DB_HOST', '127.0.0.1');
    $port = (string) env('DB_PORT', '3306');
    $databaseName = (string) env('DB_NAME', 'wrs_accounting');
    $restoreUser = trim((string) env('BACKUP_RESTORE_DB_USER', ''));
    $restorePassword = (string) env('BACKUP_RESTORE_DB_PASS', '');
    if ($restoreUser === '') {
        $restoreUser = (string) env('DB_USER', 'root');
        $restorePassword = (string) env('DB_PASS', '');
    }
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
        $restoreUser,
        $restorePassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec(
        'CREATE DATABASE `' . $restoreDatabase
        . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $restoreDatabaseCreated = true;

    $processEnvironment = getenv();
    $processEnvironment['MYSQL_PWD'] = $restorePassword;
    $restoreErrorPath = $workDirectory . DIRECTORY_SEPARATOR . 'mysql-restore-error.log';
    $process = proc_open(
        [
            $mysqlTool,
            '--protocol=tcp',
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . $restoreUser,
            '--default-character-set=utf8mb4',
            '--database=' . $restoreDatabase,
        ],
        [
            0 => ['file', $sqlPath, 'r'],
            1 => ['file', 'NUL', 'w'],
            2 => ['file', $restoreErrorPath, 'wb'],
        ],
        null,
        $processEnvironment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start mysql for the restore test.');
    }

    $restoreExitCode = proc_close($process);
    if ($restoreExitCode !== 0) {
        $restoreError = is_file($restoreErrorPath)
            ? trim((string) file_get_contents($restoreErrorPath))
            : '';
        throw new RuntimeException(
            'Restoring the backup into the temporary database failed.'
            . ($restoreError !== '' ? ' mysql: ' . $restoreError : '')
        );
    }

    $restoredTables = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ' . $pdo->quote($restoreDatabase)
    )->fetchAll(PDO::FETCH_COLUMN);
    $expectedTables = $manifest['tables'];
    sort($expectedTables, SORT_STRING);
    sort($restoredTables, SORT_STRING);
    if ($restoredTables !== $expectedTables) {
        throw new RuntimeException('The restored database tables do not match the backup manifest.');
    }

    $receiptsDirectory = $workDirectory . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'receipts';
    foreach ($manifest['receipts_sha256'] as $relativePath => $expectedHash) {
        if (
            !is_string($relativePath)
            || !preg_match('/\A[A-Za-z0-9._-]+\z/', $relativePath)
        ) {
            throw new RuntimeException('Backup receipt manifest contains an invalid entry.');
        }

        $receiptPath = $receiptsDirectory . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $actualHash = is_file($receiptPath) ? hash_file('sha256', $receiptPath) : false;
        if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException("Receipt restore validation failed for {$relativePath}.");
        }
    }

    fwrite(
        STDOUT,
        sprintf(
            "Restore validation passed: %d tables and %d receipt files verified in a temporary database.\n",
            count($restoredTables),
            count($manifest['receipts_sha256'])
        )
    );
} finally {
    if ($zipOpen) {
        $zip->close();
    }
    try {
        if ($restoreDatabaseCreated && $pdo instanceof PDO) {
            $pdo->exec('DROP DATABASE IF EXISTS `' . $restoreDatabase . '`');
        }
    } finally {
        $pdo = null;
        if (is_dir($workDirectory)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($workDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }
            rmdir($workDirectory);
        }
    }
}
