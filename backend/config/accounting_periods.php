<?php
declare(strict_types=1);

const ACCOUNTING_PERIOD_LOCKS_ENABLED = false;

function accountingPeriodBounds(string $periodType, string $periodValue): array
{
    if ($periodType === 'month') {
        if (!preg_match('/\A(?:1[0-9]{3}|[2-9][0-9]{3})-(0[1-9]|1[0-2])\z/', $periodValue)) {
            throw new InvalidArgumentException('Choose a valid accounting month.');
        }
        $period = DateTimeImmutable::createFromFormat('!Y-m', $periodValue);
        if (!$period || $period->format('Y-m') !== $periodValue) {
            throw new InvalidArgumentException('Choose a valid accounting month.');
        }

        return [
            'period_start' => $period->format('Y-m-01'),
            'period_end' => $period->modify('last day of this month')->format('Y-m-d'),
            'label' => $period->format('F Y'),
        ];
    }

    if ($periodType === 'year') {
        if (!preg_match('/\A(?:1[0-9]{3}|[2-9][0-9]{3})\z/', $periodValue)) {
            throw new InvalidArgumentException('Choose a valid accounting year.');
        }
        $period = DateTimeImmutable::createFromFormat('!Y', $periodValue);
        if (!$period || $period->format('Y') !== $periodValue) {
            throw new InvalidArgumentException('Choose a valid accounting year.');
        }

        return [
            'period_start' => $period->format('Y-01-01'),
            'period_end' => $period->format('Y-12-31'),
            'label' => $period->format('Y'),
        ];
    }

    throw new InvalidArgumentException('Choose a monthly or yearly accounting period.');
}

function accountingDateLock(PDO $pdo, string $date, bool $forUpdate = false): ?array
{
    if (!ACCOUNTING_PERIOD_LOCKS_ENABLED) {
        return null;
    }

    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('A valid accounting date is required.');
    }

    $suffix = $forUpdate ? ' FOR UPDATE' : '';
    $monthStmt = $pdo->prepare(
        "SELECT period_type, period_start, period_end
         FROM accounting_periods
         WHERE period_type = 'month' AND period_start = :period_start
           AND closed_at IS NOT NULL
           AND reopened_at IS NULL" . $suffix
    );
    $monthStmt->execute([':period_start' => $parsedDate->format('Y-m-01')]);
    $monthLock = $monthStmt->fetch(PDO::FETCH_ASSOC);
    if ($monthLock) {
        return $monthLock;
    }

    $yearStmt = $pdo->prepare(
        "SELECT period_type, period_start, period_end
         FROM accounting_periods
         WHERE period_type = 'year' AND period_start = :period_start
           AND closed_at IS NOT NULL
           AND reopened_at IS NULL" . $suffix
    );
    $yearStmt->execute([':period_start' => $parsedDate->format('Y-01-01')]);
    $yearLock = $yearStmt->fetch(PDO::FETCH_ASSOC);

    return $yearLock ?: null;
}

function assertAccountingDateOpen(PDO $pdo, string $date, bool $forUpdate = false): void
{
    if (!ACCOUNTING_PERIOD_LOCKS_ENABLED) {
        return;
    }

    $lock = accountingDateLock($pdo, $date, $forUpdate);
    if ($lock !== null) {
        $periodValue = (string) $lock['period_type'] === 'month'
            ? substr((string) $lock['period_start'], 0, 7)
            : substr((string) $lock['period_start'], 0, 4);
        $bounds = accountingPeriodBounds(
            (string) $lock['period_type'],
            $periodValue
        );
        throw new DomainException(
            'This accounting date is in the closed ' . strtolower($bounds['label'])
            . ' period. Ask an owner to reopen it, or record the correction as a new entry in an open period.'
        );
    }
}

function hasClosedAccountingPeriods(PDO $pdo): bool
{
    if (!ACCOUNTING_PERIOD_LOCKS_ENABLED) {
        return false;
    }

    $stmt = $pdo->query(
        'SELECT EXISTS (
            SELECT 1 FROM accounting_periods
            WHERE closed_at IS NOT NULL AND reopened_at IS NULL
         )'
    );

    return (bool) $stmt->fetchColumn();
}
