<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line.\n");
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php create_backup.php <output-zip> <mysqldump-path>\n");
    exit(2);
}

if (!class_exists(ZipArchive::class) || !defined('ZipArchive::EM_AES_256')) {
    fwrite(STDERR, "PHP ZipArchive with AES-256 encryption support is required.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

$archivePath = $argv[1];
$dumpTool = $argv[2];
$archivePassword = getenv('BACKUP_ARCHIVE_PASSWORD');
$databaseName = (string) env('DB_NAME', 'wrs_accounting');
$receiptDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads'
    . DIRECTORY_SEPARATOR . 'receipts';

if (!is_string($archivePassword) || strlen($archivePassword) < 16) {
    fwrite(STDERR, "Set BACKUP_ARCHIVE_PASSWORD to a unique password of at least 16 characters.\n");
    exit(1);
}

if (!is_file($dumpTool)) {
    fwrite(STDERR, "mysqldump was not found at the supplied path: {$dumpTool}\n");
    exit(1);
}

if (!is_dir($receiptDirectory)) {
    fwrite(STDERR, "Receipt directory does not exist: {$receiptDirectory}\n");
    exit(1);
}

if (file_exists($archivePath)) {
    fwrite(STDERR, "Refusing to overwrite an existing backup archive: {$archivePath}\n");
    exit(1);
}

$workDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'ledgerflow-backup-' . bin2hex(random_bytes(8));
if (!mkdir($workDirectory, 0700)) {
    throw new RuntimeException("Unable to create backup work directory: {$workDirectory}");
}

$dumpPath = $workDirectory . DIRECTORY_SEPARATOR . 'database.sql';
$dumpErrorPath = $workDirectory . DIRECTORY_SEPARATOR . 'mysqldump-error.log';
$pdo = null;
$zip = null;

try {
    $host = (string) env('DB_HOST', '127.0.0.1');
    $port = (string) env('DB_PORT', '3306');
    $username = (string) env('DB_USER', 'root');
    $password = (string) env('DB_PASS', '');

    $command = [
        $dumpTool,
        '--protocol=tcp',
        '--host=' . $host,
        '--port=' . $port,
        '--user=' . $username,
        '--default-character-set=utf8mb4',
        '--single-transaction',
        '--routines',
        '--triggers',
        '--events',
        '--hex-blob',
        '--no-tablespaces',
        $databaseName,
    ];
    $processEnvironment = getenv();
    $processEnvironment['MYSQL_PWD'] = $password;
    $process = proc_open(
        $command,
        [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', $dumpPath, 'wb'],
            2 => ['file', $dumpErrorPath, 'wb'],
        ],
        $pipes,
        null,
        $processEnvironment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start mysqldump.');
    }

    $dumpExitCode = proc_close($process);
    if ($dumpExitCode !== 0 || !is_file($dumpPath) || filesize($dumpPath) === 0) {
        $dumpError = is_file($dumpErrorPath) ? trim((string) file_get_contents($dumpErrorPath)) : '';
        throw new RuntimeException(
            'Database export failed.'
            . ($dumpError !== '' ? ' mysqldump: ' . $dumpError : '')
        );
    }

    $pdo = getDbConnection();
    $tableRows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    $tables = array_map(static fn(array $row): string => (string) $row[0], $tableRows);
    sort($tables, SORT_STRING);
    if ($tables === []) {
        throw new RuntimeException('The configured database has no tables; refusing to create an empty backup.');
    }

    $receiptFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($receiptDirectory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }

        $relativePath = substr($file->getPathname(), strlen($receiptDirectory) + 1);
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        if (!preg_match('/\A[A-Za-z0-9._-]+\z/', $relativePath)) {
            throw new RuntimeException("Receipt path contains unsupported characters: {$relativePath}");
        }
        $hash = hash_file('sha256', $file->getPathname());
        if (!is_string($hash)) {
            throw new RuntimeException("Unable to hash receipt file: {$relativePath}");
        }
        $receiptFiles[$relativePath] = $hash;
    }
    ksort($receiptFiles, SORT_STRING);

    $manifest = [
        'format_version' => 1,
        'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'database' => $databaseName,
        'tables' => $tables,
        'receipts_sha256' => $receiptFiles,
    ];
    $manifestPath = $workDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
    if (file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    ) === false) {
        throw new RuntimeException('Unable to write the backup manifest.');
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL);
    if ($openResult !== true) {
        throw new RuntimeException("Unable to create backup archive (ZipArchive error {$openResult}).");
    }

    $entries = [
        'database.sql' => $dumpPath,
        'manifest.json' => $manifestPath,
    ];
    foreach ($receiptFiles as $relativePath => $_hash) {
        $entries['uploads/receipts/' . $relativePath] = $receiptDirectory
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    foreach ($entries as $entryName => $filePath) {
        if (!$zip->addFile($filePath, $entryName)) {
            throw new RuntimeException("Unable to add {$entryName} to the backup archive.");
        }
        if (!$zip->setEncryptionName($entryName, ZipArchive::EM_AES_256, $archivePassword)) {
            throw new RuntimeException("Unable to encrypt {$entryName} with AES-256.");
        }
    }

    if (!$zip->close()) {
        $zip = null;
        throw new RuntimeException('Unable to finalize the encrypted backup archive.');
    }
    $zip = null;

    fwrite(
        STDOUT,
        sprintf(
            "Created encrypted backup: %s (%d database tables, %d receipt files)\n",
            $archivePath,
            count($tables),
            count($receiptFiles)
        )
    );
} catch (Throwable $exception) {
    if ($zip instanceof ZipArchive) {
        $zip->close();
    }
    if (is_file($archivePath)) {
        unlink($archivePath);
    }
    throw $exception;
} finally {
    $pdo = null;
    foreach (glob($workDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $workFile) {
        if (is_file($workFile)) {
            unlink($workFile);
        }
    }
    rmdir($workDirectory);
}
