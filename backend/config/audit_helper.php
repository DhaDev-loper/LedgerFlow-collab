<?php
declare(strict_types=1);

/**
 * Write one audit event. Include only non-sensitive summaries in $details.
 */
function logAudit(
    PDO $pdo,
    int $userId,
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?string $details = null
): void {
    if ($action === '' || strlen($action) > 100) {
        throw new InvalidArgumentException('Audit action must be between 1 and 100 characters.');
    }
    if ($entityType === '' || strlen($entityType) > 50) {
        throw new InvalidArgumentException('Audit entity type must be between 1 and 50 characters.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO audit (user_id, action, entity_type, entity_id, details)
         VALUES (:user_id, :action, :entity_type, :entity_id, :details)'
    );
    $stmt->execute([
        ':user_id' => $userId > 0 ? $userId : null,
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':details' => $details,
    ]);
}
