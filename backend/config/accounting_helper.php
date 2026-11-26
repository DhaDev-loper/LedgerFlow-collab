<?php
declare(strict_types=1);

function isCashBankAccount(array $account): bool
{
    return strcasecmp((string) ($account['type_name'] ?? ''), 'Asset') === 0
        && (int) ($account['is_cash_account'] ?? 0) === 1;
}

function generateEntryReference(PDO $pdo, string $typeOrContext, string $transactionDate, ?string &$lockName = null): string
{
    $context = strtolower(trim($typeOrContext));
    // All new transaction vouchers use invoice-style numbering. Existing references
    // remain untouched; opening-balance setup records retain their distinct prefix.
    $prefix = in_array($context, ['opening balance', 'opening_balance'], true) ? 'OB' : 'INV';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $transactionDate);
    if (!$date || $date->format('Y-m-d') !== $transactionDate) {
        throw new InvalidArgumentException('A valid transaction date is required to generate a reference number.');
    }

    $year = $date->format('Y');
    $lockName = 'wrs_entry_ref_' . strtolower($prefix) . '_' . $year;
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lockStmt->execute([':lock_name' => $lockName]);
    if ((int) $lockStmt->fetchColumn() !== 1) {
        $lockName = null;
        throw new RuntimeException('Could not reserve a unique reference number. Please try again.');
    }

    $sequenceStmt = $pdo->prepare(
        "SELECT reference_no
         FROM transaction_journals
         WHERE reference_no REGEXP :reference_pattern
         FOR UPDATE"
    );
    $sequenceStmt->execute([':reference_pattern' => '^' . $prefix . '-' . $year . '-[0-9]+$']);
    $maxSequence = 0;
    $referencePattern = '/^' . preg_quote($prefix, '/') . '-' . preg_quote($year, '/') . '-([0-9]+)$/i';
    foreach ($sequenceStmt->fetchAll(PDO::FETCH_COLUMN) as $existingReference) {
        if (preg_match($referencePattern, (string) $existingReference, $matches)) {
            $maxSequence = max($maxSequence, (int) $matches[1]);
        }
    }
    $nextSequence = $maxSequence + 1;
    return sprintf('%s-%s-%05d', $prefix, $year, $nextSequence);
}

function releaseEntryReferenceLock(PDO $pdo, ?string $lockName): void
{
    if ($lockName === null) {
        return;
    }
    $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $releaseStmt->execute([':lock_name' => $lockName]);
}

function getPartyOpenItems(
    PDO $pdo,
    int $accountId,
    string $increaseEntryType,
    ?int $clientId = null,
    bool $includeSettled = false
): array {
    if (!in_array($increaseEntryType, ['debit', 'credit'], true)) {
        throw new InvalidArgumentException('The normal increase side must be debit or credit.');
    }

    $decreaseEntryType = $increaseEntryType === 'debit' ? 'credit' : 'debit';
    $sql = "SELECT tj.id AS journal_id, tj.client_id, tj.description,
                   tj.reference_no, tj.transaction_date, tj.due_date,
                   t.entry_type, SUM(t.amount) AS amount
            FROM transaction_journals tj
            JOIN transactions t ON t.transaction_journal_id = tj.id
            WHERE t.account_id = :account_id
              AND tj.status != 'voided'";
    $params = [':account_id' => $accountId];

    if ($clientId !== null) {
        $sql .= ' AND tj.client_id = :client_id';
        $params[':client_id'] = $clientId;
    }

    $sql .= " GROUP BY tj.id, tj.client_id, tj.description, tj.reference_no,
                       tj.transaction_date, tj.due_date, t.entry_type
              ORDER BY tj.transaction_date ASC, tj.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$events) {
        return [];
    }

    $allocationSql = "SELECT sa.settlement_journal_id, sa.document_journal_id,
                             sa.amount, sj.client_id
                      FROM settlement_allocations sa
                      JOIN transaction_journals sj
                        ON sj.id = sa.settlement_journal_id AND sj.status != 'voided'
                      JOIN transaction_journals dj
                        ON dj.id = sa.document_journal_id AND dj.status != 'voided'
                       AND dj.client_id = sj.client_id
                      WHERE sa.account_id = :account_id";
    $allocationParams = [':account_id' => $accountId];
    if ($clientId !== null) {
        $allocationSql .= ' AND sj.client_id = :client_id';
        $allocationParams[':client_id'] = $clientId;
    }
    $allocationStmt = $pdo->prepare($allocationSql);
    $allocationStmt->execute($allocationParams);

    $allocationsBySettlement = [];
    foreach ($allocationStmt->fetchAll(PDO::FETCH_ASSOC) as $allocation) {
        $settlementId = (int) $allocation['settlement_journal_id'];
        $allocationsBySettlement[$settlementId][] = [
            'document_journal_id' => (int) $allocation['document_journal_id'],
            'amount' => (float) $allocation['amount'],
        ];
    }

    $itemsByClient = [];
    foreach ($events as $event) {
        $partyId = (int) $event['client_id'];
        $journalId = (int) $event['journal_id'];
        $amount = (float) $event['amount'];
        $entryType = strtolower((string) $event['entry_type']);

        if ($entryType === $increaseEntryType) {
            $itemsByClient[$partyId][$journalId] = [
                'journal_id' => $journalId,
                'client_id' => $partyId,
                'description' => (string) $event['description'],
                'reference_no' => $event['reference_no'],
                'transaction_date' => (string) $event['transaction_date'],
                'due_date' => $event['due_date'],
                'amount' => $amount,
                'applied' => 0.0,
                'remaining' => $amount,
            ];
            continue;
        }

        if ($entryType !== $decreaseEntryType) {
            continue;
        }

        $allocations = $allocationsBySettlement[$journalId] ?? [];
        if ($allocations) {
            foreach ($allocations as $allocation) {
                $documentId = $allocation['document_journal_id'];
                if (!isset($itemsByClient[$partyId][$documentId])) {
                    continue;
                }
                $applied = min(
                    $itemsByClient[$partyId][$documentId]['remaining'],
                    $allocation['amount']
                );
                $itemsByClient[$partyId][$documentId]['applied'] += $applied;
                $itemsByClient[$partyId][$documentId]['remaining'] -= $applied;
            }
            continue;
        }

        $candidateIds = array_keys($itemsByClient[$partyId] ?? []);
        usort($candidateIds, static function (int $leftId, int $rightId) use (&$itemsByClient, $partyId): int {
            $left = $itemsByClient[$partyId][$leftId];
            $right = $itemsByClient[$partyId][$rightId];
            return [
                $left['due_date'] ?? '9999-12-31',
                $left['transaction_date'],
                $left['journal_id'],
            ] <=> [
                $right['due_date'] ?? '9999-12-31',
                $right['transaction_date'],
                $right['journal_id'],
            ];
        });

        $unapplied = $amount;
        foreach ($candidateIds as $documentId) {
            if ($unapplied <= 0) {
                break;
            }
            $remaining = $itemsByClient[$partyId][$documentId]['remaining'];
            if ($remaining <= 0) {
                continue;
            }
            $applied = min($remaining, $unapplied);
            $itemsByClient[$partyId][$documentId]['applied'] += $applied;
            $itemsByClient[$partyId][$documentId]['remaining'] -= $applied;
            $unapplied -= $applied;
        }
    }

    $items = [];
    foreach ($itemsByClient as $partyItems) {
        foreach ($partyItems as $item) {
            if ($includeSettled || $item['remaining'] > 0.005) {
                $items[] = $item;
            }
        }
    }

    usort($items, static function (array $left, array $right): int {
        return [
            $left['due_date'] ?? '9999-12-31',
            $left['transaction_date'],
            $left['journal_id'],
        ] <=> [
            $right['due_date'] ?? '9999-12-31',
            $right['transaction_date'],
            $right['journal_id'],
        ];
    });

    return $items;
}

function buildSettlementAllocationPlan(array $openItems, float $amount): array
{
    $plan = [];
    $unallocated = round($amount, 2);

    foreach ($openItems as $item) {
        if ($unallocated <= 0.005) {
            break;
        }
        $remaining = (float) $item['remaining'];
        if ($remaining <= 0.005) {
            continue;
        }
        $applied = min($remaining, $unallocated);
        $plan[] = [
            'document_journal_id' => (int) $item['journal_id'],
            'amount' => round($applied, 2),
        ];
        $unallocated = round($unallocated - $applied, 2);
    }

    if ($unallocated > 0.005) {
        throw new DomainException("The payment exceeds the party's outstanding balance.");
    }

    return $plan;
}

function ensurePartyDocumentCanBeVoided(PDO $pdo, int $journalId): void
{
    $stmt = $pdo->prepare(
        "SELECT tj.client_id, t.account_id, t.entry_type, at.name AS type_name, a.name AS account_name
         FROM transaction_journals tj
         JOIN transactions t ON t.transaction_journal_id = tj.id
         JOIN accounts a ON a.id = t.account_id
         JOIN account_types at ON at.id = a.account_type_id
         WHERE tj.id = :journal_id AND tj.client_id IS NOT NULL
           AND ((LOWER(a.name) = 'accounts receivable' AND at.name = 'Asset' AND t.entry_type = 'debit')
             OR (LOWER(a.name) = 'accounts payable' AND at.name = 'Liability' AND t.entry_type = 'credit'))"
    );
    $stmt->execute([':journal_id' => $journalId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $document) {
        $increaseEntryType = strtolower($document['account_name']) === 'accounts receivable'
            ? 'debit'
            : 'credit';
        $items = getPartyOpenItems(
            $pdo,
            (int) $document['account_id'],
            $increaseEntryType,
            (int) $document['client_id'],
            true
        );
        foreach ($items as $item) {
            if ((int) $item['journal_id'] === $journalId && $item['applied'] > 0.005) {
                throw new DomainException(
                    'This invoice or bill has settlement activity and cannot be voided until the related payments are resolved.'
                );
            }
        }
    }
}
