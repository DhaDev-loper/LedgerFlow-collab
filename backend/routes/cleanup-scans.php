<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();

    // Find pending or completed scans older than 24 hours that were never saved as entries
    $stmt = $pdo->prepare("
        SELECT image_path FROM pending_receipt_scans 
        WHERE status IN ('pending', 'completed') 
        AND created_at < NOW() - INTERVAL 1 DAY
    ");
    $stmt->execute();
    $expiredScans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deletedFilesCount = 0;
    foreach ($expiredScans as $scan) {
        if (!empty($scan['image_path'])) {
            // Adjust relative path depending on where cleanup script is placed
            $fullPath = __DIR__ . '/../../' . $scan['image_path'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
                $deletedFilesCount++;
            }
        }
    }

    // Delete the expired rows from the database table
    $deleteStmt = $pdo->prepare("
        DELETE FROM pending_receipt_scans 
        WHERE status IN ('pending', 'completed') 
        AND created_at < NOW() - INTERVAL 1 DAY
    ");
    $deleteStmt->execute();
    $deletedRows = $deleteStmt->rowCount();

    // Optional: If called via AJAX/JSON request, return status
    if (isset($_GET['ajax'])) {
        echo json_encode(['success' => true, 'deleted_files' => $deletedFilesCount, 'deleted_rows' => $deletedRows]);
        exit;
    }

} catch (Exception $e) {
    if (isset($_GET['ajax'])) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}