<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit_helper.php';
require_once __DIR__ . '/../config/accounting_periods.php';

if (!ACCOUNTING_PERIOD_LOCKS_ENABLED) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Accounting period locks are unavailable.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$role = (string) ($_SESSION['role'] ?? '');
$userId = (int) ($_SESSION['user_id'] ?? 0);

switch ($action) {
    case 'list':
        $pdo = getDbConnection();
        $stmt = $pdo->query(
            "SELECT ap.period_type, ap.period_start, ap.period_end, ap.close_reason,
                    ap.closed_at, closed_by.full_name AS closed_by_name
             FROM accounting_periods ap
             JOIN users closed_by ON closed_by.id = ap.closed_by
             WHERE ap.closed_at IS NOT NULL
               AND ap.reopened_at IS NULL
             ORDER BY ap.period_start DESC, ap.period_type"
        );
        echo json_encode(['success' => true, 'periods' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'closed-entry-options':
        $pdo = getDbConnection();
        $stmt = $pdo->query(
            "SELECT tj.id, tj.transaction_date, tj.description, tj.reference_no, tj.total_amount
             FROM transaction_journals tj
             WHERE tj.status = 'posted'
               AND EXISTS (
                   SELECT 1
                   FROM accounting_periods ap
                   WHERE ap.closed_at IS NOT NULL
                     AND ap.reopened_at IS NULL
                     AND (
                         (ap.period_type = 'month'
                          AND ap.period_start = DATE_FORMAT(tj.transaction_date, '%Y-%m-01'))
                         OR
                         (ap.period_type = 'year'
                          AND ap.period_start = MAKEDATE(YEAR(tj.transaction_date), 1))
                     )
               )
             ORDER BY tj.transaction_date DESC, tj.id DESC
             LIMIT 500"
        );
        echo json_encode(['success' => true, 'entries' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'close':
        if (!in_array($role, ['admin', 'owner'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only admins and owners can close accounting periods.']);
            break;
        }

        $periodType = (string) ($_POST['period_type'] ?? '');
        $periodValue = trim((string) ($_POST['period_value'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '' || strlen($reason) > 2000) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Enter a close reason (up to 2,000 characters).']);
            break;
        }

        try {
            $bounds = accountingPeriodBounds($periodType, $periodValue);
            $pdo = getDbConnection();
            $pdo->beginTransaction();

            $lockStmt = $pdo->prepare(
                'SELECT closed_at, reopened_at FROM accounting_periods
                 WHERE period_type = :period_type AND period_start = :period_start
                 FOR UPDATE'
            );
            $lockStmt->execute([
                ':period_type' => $periodType,
                ':period_start' => $bounds['period_start'],
            ]);
            $existingPeriod = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (
                $existingPeriod
                && $existingPeriod['closed_at'] !== null
                && $existingPeriod['reopened_at'] === null
            ) {
                throw new DomainException('This accounting period is already closed.');
            }

            $upsert = $pdo->prepare(
                'INSERT INTO accounting_periods
                    (period_type, period_start, period_end, closed_by, closed_at, close_reason,
                     reopened_by, reopened_at, reopen_reason)
                 VALUES (:period_type, :period_start, :period_end, :closed_by, NOW(), :close_reason,
                         NULL, NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    period_end = VALUES(period_end),
                    closed_by = VALUES(closed_by),
                    closed_at = VALUES(closed_at),
                    close_reason = VALUES(close_reason),
                    reopened_by = NULL,
                    reopened_at = NULL,
                    reopen_reason = NULL'
            );
            $upsert->execute([
                ':period_type' => $periodType,
                ':period_start' => $bounds['period_start'],
                ':period_end' => $bounds['period_end'],
                ':closed_by' => $userId,
                ':close_reason' => $reason,
            ]);
            logAudit(
                $pdo,
                $userId,
                'CLOSE_ACCOUNTING_PERIOD',
                'accounting_period',
                null,
                json_encode([
                    'period_type' => $periodType,
                    'period_start' => $bounds['period_start'],
                    'period_end' => $bounds['period_end'],
                    'reason' => $reason,
                ], JSON_THROW_ON_ERROR)
            );
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => $bounds['label'] . ' closed.']);
        } catch (InvalidArgumentException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        } catch (DomainException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Accounting period close failed: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Could not close the accounting period.']);
        }
        break;

    case 'reopen':
        if ($role !== 'owner') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only the owner can reopen accounting periods.']);
            break;
        }

        $periodType = (string) ($_POST['period_type'] ?? '');
        $periodValue = trim((string) ($_POST['period_value'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '' || strlen($reason) > 2000) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Enter a reopen reason (up to 2,000 characters).']);
            break;
        }

        try {
            $bounds = accountingPeriodBounds($periodType, $periodValue);
            $pdo = getDbConnection();
            $pdo->beginTransaction();
            $lockStmt = $pdo->prepare(
                'SELECT closed_at, reopened_at FROM accounting_periods
                 WHERE period_type = :period_type AND period_start = :period_start
                 FOR UPDATE'
            );
            $lockStmt->execute([
                ':period_type' => $periodType,
                ':period_start' => $bounds['period_start'],
            ]);
            $existingPeriod = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (
                !$existingPeriod
                || $existingPeriod['closed_at'] === null
                || $existingPeriod['reopened_at'] !== null
            ) {
                throw new DomainException('This accounting period is not currently closed.');
            }

            $update = $pdo->prepare(
                'UPDATE accounting_periods
                 SET reopened_by = :reopened_by, reopened_at = NOW(), reopen_reason = :reopen_reason
                 WHERE period_type = :period_type AND period_start = :period_start
                   AND closed_at IS NOT NULL AND reopened_at IS NULL'
            );
            $update->execute([
                ':reopened_by' => $userId,
                ':reopen_reason' => $reason,
                ':period_type' => $periodType,
                ':period_start' => $bounds['period_start'],
            ]);
            logAudit(
                $pdo,
                $userId,
                'REOPEN_ACCOUNTING_PERIOD',
                'accounting_period',
                null,
                json_encode([
                    'period_type' => $periodType,
                    'period_start' => $bounds['period_start'],
                    'period_end' => $bounds['period_end'],
                    'reason' => $reason,
                ], JSON_THROW_ON_ERROR)
            );
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => $bounds['label'] . ' reopened.']);
        } catch (InvalidArgumentException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        } catch (DomainException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Accounting period reopen failed: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Could not reopen the accounting period.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown accounting period action.']);
        break;
}
