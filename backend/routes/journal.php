<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit_helper.php';
require_once __DIR__ . '/../config/accounting_helper.php';

// Check if this is an incoming mobile upload or scan request
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$token  = $_POST['token'] ?? $_GET['token'] ?? '';

// Only run desktop auth guard if it's not a mobile upload request
if ($action !== 'push-mobile-receipt' && empty($token)) {
    require_once __DIR__ . '/../config/auth_guard.php';
}

$userRole = $_SESSION['role'] ?? 'staff';
$userId   = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
switch ($action) {

    // 1. LIST JOURNAL ENTRIES
    case 'list':
        $search      = trim($_GET['search'] ?? '');
        $dateFrom    = $_GET['date_from'] ?? '';
        $dateTo      = $_GET['date_to'] ?? '';
        $typeId      = (int) ($_GET['transaction_type_id'] ?? 0);
        $accountName = trim($_GET['account_name'] ?? '');
        $statusFilter= trim($_GET['status'] ?? 'active');

        $sql = "SELECT DISTINCT tj.id, tj.description, tj.transaction_date, tj.reference_no, 
                       tj.total_amount, tj.due_date, tj.status, tj.is_edited, tj.edited_at, tt.name AS type_name,
                       u.full_name AS created_by_name, c.name AS client_name
                FROM transaction_journals tj
                JOIN transaction_types tt ON tt.id = tj.transaction_type_id
                JOIN users u ON u.id = tj.created_by
                LEFT JOIN clients c ON c.id = tj.client_id";

        if ($accountName !== '') {
            $sql .= " JOIN transactions t ON t.transaction_journal_id = tj.id
                      JOIN accounts a ON a.id = t.account_id";
        }

        $sql .= " WHERE 1=1";

        $params = [];

        if ($search !== '') {
            $sql .= " AND (tj.description LIKE :search1 OR tj.reference_no LIKE :search2 OR u.full_name LIKE :search3)";
            $params[':search1'] = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
            $params[':search3'] = '%' . $search . '%';
        }

        if ($dateFrom !== '') {
            $sql .= " AND tj.transaction_date >= :date_from";
            $params[':date_from'] = $dateFrom;
        }

        if ($dateTo !== '') {
            $sql .= " AND tj.transaction_date <= :date_to";
            $params[':date_to'] = $dateTo;
        }

        if ($typeId > 0) {
            $sql .= " AND tj.transaction_type_id = :type_id";
            $params[':type_id'] = $typeId;
        }

        if ($accountName !== '') {
            $sql .= " AND a.name = :account_name";
            $params[':account_name'] = $accountName;
        }

        if ($statusFilter === 'active') {
            $sql .= " AND tj.status != 'voided'";
        } elseif ($statusFilter === 'voided') {
            $sql .= " AND tj.status = 'voided'";
        }
        // If $statusFilter === 'all', no status clause is added

        $sql .= " ORDER BY tj.transaction_date DESC, tj.id DESC";

        $pdo = getDbConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $journals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'journals' => $journals]);
        break;

    // 2. REFERENCE DATA FOR FORM DROPDOWNS
    case 'reference-data':
        $pdo = getDbConnection();
        $accounts = $pdo->query(
            "SELECT a.id, a.name, a.is_cash_account, at.name AS type_name
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE a.is_active = 1
             ORDER BY at.id, a.name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $types = $pdo->query("SELECT id, name FROM transaction_types ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        $categories = $pdo->query(
            "SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $cashAccountsStmt = $pdo->query(
            "SELECT a.id, a.name, at.name AS type_name
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE a.is_active = 1
               AND at.name = 'Asset'
               AND a.is_cash_account = 1
             ORDER BY a.name"
        );
        $receivableAccountsStmt = $pdo->query(
            "SELECT a.id, a.name
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE a.is_active = 1 AND at.name = 'Asset'
               AND LOWER(a.name) = 'accounts receivable'
             ORDER BY a.name"
        );
        $payableAccountsStmt = $pdo->query(
            "SELECT a.id, a.name
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE a.is_active = 1 AND at.name = 'Liability'
               AND LOWER(a.name) = 'accounts payable'
             ORDER BY a.name"
        );
        $clientsStmt = $pdo->query(
            "SELECT id, name, company_name, party_type, status FROM clients
             ORDER BY name"
        );

        echo json_encode([
            'success'    => true,
            'accounts'   => $accounts,
            'types'      => $types,
            'categories' => $categories,
            'cash_accounts' => $cashAccountsStmt->fetchAll(PDO::FETCH_ASSOC),
            'receivable_accounts' => $receivableAccountsStmt->fetchAll(PDO::FETCH_ASSOC),
            'payable_accounts' => $payableAccountsStmt->fetchAll(PDO::FETCH_ASSOC),
            'clients' => $clientsStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
        break;

    case 'party-open-items':
        $clientId = (int) ($_GET['client_id'] ?? 0);
        $settlementType = $_GET['type'] ?? '';
        if ($clientId <= 0 || !in_array($settlementType, ['ar', 'ap'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'A valid party and settlement type are required.']);
            break;
        }

        $pdo = getDbConnection();
        $accountName = $settlementType === 'ar' ? 'Accounts Receivable' : 'Accounts Payable';
        $increaseEntryType = $settlementType === 'ar' ? 'debit' : 'credit';
        $partyTypeStmt = $pdo->prepare('SELECT party_type FROM clients WHERE id = :id');
        $partyTypeStmt->execute([':id' => $clientId]);
        $partyType = $partyTypeStmt->fetchColumn();
        $requiredPartyType = $settlementType === 'ar' ? 'customer' : 'vendor';
        if ($partyType === false || !in_array($partyType, [$requiredPartyType, 'both'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'This party is not configured for the selected settlement type.']);
            break;
        }

        $accountsStmt = $pdo->prepare(
            "SELECT a.id FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE LOWER(a.name) = LOWER(:name)
               AND at.name = :type"
        );
        $accountsStmt->execute([
            ':name' => $accountName,
            ':type' => $settlementType === 'ar' ? 'Asset' : 'Liability',
        ]);
        $items = [];
        foreach ($accountsStmt->fetchAll(PDO::FETCH_COLUMN) as $accountId) {
            $items = array_merge(
                $items,
                getPartyOpenItems($pdo, (int) $accountId, $increaseEntryType, $clientId)
            );
        }
        $openBalance = array_sum(array_column($items, 'remaining'));
        echo json_encode([
            'success' => true,
            'open_balance' => round($openBalance, 2),
            'items' => $items,
            'allocation_method' => 'oldest due date first',
        ]);
        break;

    // 3. CREATE NEW JOURNAL ENTRY
     case 'create':
        if (ob_get_length()) ob_clean();

        try {
            $transactionTypeId = (int) ($_POST['transaction_type_id'] ?? 0);
            $description       = trim($_POST['description'] ?? '');
            $transactionDate   = $_POST['transaction_date'] ?? '';
            $referenceNo       = trim($_POST['reference_no'] ?? '');
            $dueDate           = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $categoryId        = (int) ($_POST['category_id'] ?? 0);
            $documentType      = trim($_POST['document_type'] ?? '');

            $clientId = !empty($_POST['client_id']) && (int)$_POST['client_id'] > 0 ? (int)$_POST['client_id'] : null;

            $debitLines  = json_decode($_POST['debit_lines'] ?? '[]', true);
            $creditLines = json_decode($_POST['credit_lines'] ?? '[]', true);

            if ($transactionTypeId === 0 || $description === '' || $transactionDate === '') {
                echo json_encode(['success' => false, 'error' => 'Transaction type, description, and date are required.']);
                exit;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $transactionDate);
            if (!$date || $date->format('Y-m-d') !== $transactionDate) {
                echo json_encode(['success' => false, 'error' => 'Enter a valid transaction date.']);
                exit;
            }
            if ($dueDate !== null) {
                $parsedDueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
                if (!$parsedDueDate || $parsedDueDate->format('Y-m-d') !== $dueDate) {
                    echo json_encode(['success' => false, 'error' => 'Enter a valid due date.']);
                    exit;
                }
            }

            if (!is_array($debitLines) || !is_array($creditLines) || empty($debitLines) || empty($creditLines)) {
                echo json_encode(['success' => false, 'error' => 'At least one debit line and one credit line are required.']);
                exit;
            }
            if (count($debitLines) > 10 || count($creditLines) > 10) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'error' => 'A journal entry can have at most 10 debit lines and 10 credit lines.',
                ]);
                exit;
            }

            $totalDebits  = 0;
            $totalCredits = 0;
            $debitAccountIds = [];
            $creditAccountIds = [];

            foreach ($debitLines as $line) {
                if (!is_array($line)) {
                    throw new DomainException('Debit lines must be valid account and amount pairs.');
                }
                $amount = is_numeric($line['amount'] ?? null) ? (float) $line['amount'] : 0;
                $accId = (int) ($line['account_id'] ?? 0);
                if ($accId === 0 || !is_finite($amount) || $amount <= 0
                    || $amount > 999999999999.99 || round($amount, 2) !== $amount) {
                    throw new DomainException('Every debit line needs a valid account and amount with no more than two decimal places.');
                }
                $totalDebits += $amount;
                $debitAccountIds[] = $accId;
            }

            foreach ($creditLines as $line) {
                if (!is_array($line)) {
                    throw new DomainException('Credit lines must be valid account and amount pairs.');
                }
                $amount = is_numeric($line['amount'] ?? null) ? (float) $line['amount'] : 0;
                $accId = (int) ($line['account_id'] ?? 0);
                if ($accId === 0 || !is_finite($amount) || $amount <= 0
                    || $amount > 999999999999.99 || round($amount, 2) !== $amount) {
                    throw new DomainException('Every credit line needs a valid account and amount with no more than two decimal places.');
                }
                $totalCredits += $amount;
                $creditAccountIds[] = $accId;
            }

            // 1. PREVENT CASH-TO-CASH OR IDENTICAL ACCOUNT SELF-REFERENCING
            $commonAccounts = array_intersect($debitAccountIds, $creditAccountIds);
            if (!empty($commonAccounts)) {
                echo json_encode(['success' => false, 'error' => 'Invalid entry: You cannot debit and credit the exact same account in a single transaction line.']);
                exit;
            }

            if (round($totalDebits, 2) !== round($totalCredits, 2)) {
                echo json_encode(['success' => false, 'error' => "Entry is not balanced. Total Debits: {$totalDebits}, Total Credits: {$totalCredits}"]);
                exit;
            }

            $pdo = getDbConnection();

            // 2. FETCH ACTIVE ACCOUNTS AND THEIR ACCOUNTING CLASSIFICATION
            $allAccountIds = array_unique(array_merge($debitAccountIds, $creditAccountIds));
            $accountTypesMap = [];
            if (!empty($allAccountIds)) {
                $placeholders = implode(',', array_fill(0, count($allAccountIds), '?'));
                $stmt = $pdo->prepare(
                    "SELECT a.id, a.name, a.is_active, a.is_cash_account,
                            at.name AS type_name, at.normal_balance
                     FROM accounts a
                     JOIN account_types at ON at.id = a.account_type_id
                     WHERE a.id IN ($placeholders)"
                );
                $stmt->execute(array_values($allAccountIds));
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $accountTypesMap[(int) $row['id']] = $row;
                }
            }
            if (count($accountTypesMap) !== count($allAccountIds)) {
                throw new DomainException('One or more selected accounts do not exist.');
            }
            foreach ($accountTypesMap as $account) {
                if ((int) $account['is_active'] !== 1) {
                    throw new DomainException('Inactive accounts cannot be used in new journal entries.');
                }
            }

            // 3. PREVENT ILLOGICAL ACCOUNT TYPE PAIRINGS (e.g., Expense vs. Expense)
            $debitTypes = [];
            $creditTypes = [];
            $cashDebitTotal = 0.0;
            $cashCreditTotal = 0.0;
            $arIncreaseByAccount = [];
            $arDecreaseByAccount = [];
            $apIncreaseByAccount = [];
            $apDecreaseByAccount = [];

            foreach ($debitLines as $line) {
                $accId = (int) $line['account_id'];
                $account = $accountTypesMap[$accId];
                $debitTypes[] = $account['type_name'];
                if (isCashBankAccount($account)) {
                    $cashDebitTotal += (float) $line['amount'];
                }
                if (strcasecmp($account['name'], 'Accounts Receivable') === 0 && $account['type_name'] === 'Asset') {
                    $arIncreaseByAccount[$accId] = ($arIncreaseByAccount[$accId] ?? 0) + (float) $line['amount'];
                }
                if (strcasecmp($account['name'], 'Accounts Payable') === 0 && $account['type_name'] === 'Liability') {
                    $apDecreaseByAccount[$accId] = ($apDecreaseByAccount[$accId] ?? 0) + (float) $line['amount'];
                }
            }

            foreach ($creditLines as $line) {
                $accId = (int) $line['account_id'];
                $account = $accountTypesMap[$accId];
                $creditTypes[] = $account['type_name'];
                if (isCashBankAccount($account)) {
                    $cashCreditTotal += (float) $line['amount'];
                }
                if (strcasecmp($account['name'], 'Accounts Receivable') === 0 && $account['type_name'] === 'Asset') {
                    $arDecreaseByAccount[$accId] = ($arDecreaseByAccount[$accId] ?? 0) + (float) $line['amount'];
                }
                if (strcasecmp($account['name'], 'Accounts Payable') === 0 && $account['type_name'] === 'Liability') {
                    $apIncreaseByAccount[$accId] = ($apIncreaseByAccount[$accId] ?? 0) + (float) $line['amount'];
                }
            }

            if (in_array('Expense', $debitTypes) && in_array('Expense', $creditTypes)) {
                throw new DomainException('Invalid transaction pairing: You cannot debit and credit two Expense accounts directly against each other.');
            }

            $transactionTypeStmt = $pdo->prepare('SELECT name FROM transaction_types WHERE id = :id');
            $transactionTypeStmt->execute([':id' => $transactionTypeId]);
            $transactionTypeName = $transactionTypeStmt->fetchColumn();
            if ($transactionTypeName === false) {
                throw new DomainException('Select a valid transaction type.');
            }
            if ($transactionTypeName === 'Cash Inflow'
                && (round($cashDebitTotal, 2) !== round($totalDebits, 2) || $cashCreditTotal > 0)) {
                throw new DomainException('Cash inflows must debit only active cash, bank, or wallet accounts.');
            }
            if ($transactionTypeName === 'Cash Outflow'
                && (round($cashCreditTotal, 2) !== round($totalCredits, 2) || $cashDebitTotal > 0)) {
                throw new DomainException('Cash outflows must credit only active cash, bank, or wallet accounts.');
            }

            $hasReceivableEntries = $arIncreaseByAccount || $arDecreaseByAccount;
            $hasPayableEntries = $apIncreaseByAccount || $apDecreaseByAccount;
            if (($hasReceivableEntries || $hasPayableEntries) && $clientId === null) {
                throw new DomainException('Select the customer or vendor for every A/R or A/P entry.');
            }
            if (($arIncreaseByAccount || $apIncreaseByAccount) && $dueDate === null) {
                throw new DomainException('A due date is required when recording an invoice or vendor bill.');
            }

            if ($documentType !== '' && $documentType !== 'vendor_bill') {
                throw new DomainException('Invalid document type.');
            }
            if ($documentType === 'vendor_bill') {
                $debitAccountsAreBillable = true;
                foreach ($debitAccountIds as $accountId) {
                    $account = $accountTypesMap[$accountId];
                    if (!in_array($account['type_name'], ['Asset', 'Expense'], true)
                        || isCashBankAccount($account)
                        || (strcasecmp($account['name'], 'Accounts Receivable') === 0
                            && $account['type_name'] === 'Asset')) {
                        $debitAccountsAreBillable = false;
                        break;
                    }
                }
                $apCreditTotal = array_sum($apIncreaseByAccount);
                if ($transactionTypeName !== 'Transfer'
                    || !$hasPayableEntries
                    || $apDecreaseByAccount
                    || round($apCreditTotal, 2) !== round($totalCredits, 2)
                    || !$debitAccountsAreBillable) {
                    throw new DomainException('A vendor bill must debit non-cash expense/asset accounts and credit Accounts Payable.');
                }
            }

            $pdo->beginTransaction();
            $allocationPlans = [];
            if ($hasReceivableEntries || $hasPayableEntries) {
                $partyStmt = $pdo->prepare(
                    'SELECT id, party_type, status FROM clients WHERE id = :id FOR UPDATE'
                );
                $partyStmt->execute([':id' => $clientId]);
                $party = $partyStmt->fetch(PDO::FETCH_ASSOC);
                if (!$party) {
                    throw new DomainException('Select a valid customer or vendor.');
                }
                if (($hasReceivableEntries && !in_array($party['party_type'], ['customer', 'both'], true))
                    || ($hasPayableEntries && !in_array($party['party_type'], ['vendor', 'both'], true))) {
                    throw new DomainException('The selected party type does not match this A/R or A/P entry.');
                }
                if ($party['status'] !== 'active' && ($arIncreaseByAccount || $apIncreaseByAccount)) {
                    throw new DomainException('Inactive parties may settle existing balances but cannot receive new invoices or bills.');
                }

                foreach ($arDecreaseByAccount as $accountId => $amount) {
                    $items = getPartyOpenItems($pdo, (int) $accountId, 'debit', $clientId);
                    $items = array_values(array_filter(
                        $items,
                        static fn (array $item): bool => $item['transaction_date'] <= $transactionDate
                    ));
                    $allocationPlans[$accountId] = buildSettlementAllocationPlan($items, $amount);
                }
                foreach ($apDecreaseByAccount as $accountId => $amount) {
                    $items = getPartyOpenItems($pdo, (int) $accountId, 'credit', $clientId);
                    $items = array_values(array_filter(
                        $items,
                        static fn (array $item): bool => $item['transaction_date'] <= $transactionDate
                    ));
                    $allocationPlans[$accountId] = buildSettlementAllocationPlan($items, $amount);
                }
            } elseif ($clientId !== null) {
                $partyStmt = $pdo->prepare('SELECT id FROM clients WHERE id = :id AND status = \'active\'');
                $partyStmt->execute([':id' => $clientId]);
                if (!$partyStmt->fetchColumn()) {
                    throw new DomainException('Select an active client.');
                }
            }

            $createdBy = $userId > 0 ? $userId : 1;

            $journalStmt = $pdo->prepare(
                "INSERT INTO transaction_journals 
                    (transaction_type_id, description, transaction_date, reference_no, due_date, total_amount, created_by, client_id, status)
                VALUES (:type_id, :description, :date, :reference, :due_date, :total, :created_by, :client_id, 'posted')"
            );
            
            $journalStmt->execute([
                ':type_id'    => $transactionTypeId,
                ':description'=> $description,
                ':date'       => $transactionDate,
                ':reference'  => $referenceNo,
                ':due_date'   => $dueDate,
                ':total'      => $totalDebits,
                ':created_by' => $createdBy,
                ':client_id'  => $clientId,
            ]);

            $journalId = (int) $pdo->lastInsertId();

            if ($allocationPlans) {
                $allocationStmt = $pdo->prepare(
                    'INSERT INTO settlement_allocations
                        (settlement_journal_id, document_journal_id, account_id, amount)
                     VALUES (:settlement_id, :document_id, :account_id, :amount)'
                );
                foreach ($allocationPlans as $accountId => $plan) {
                    foreach ($plan as $allocation) {
                        $allocationStmt->execute([
                            ':settlement_id' => $journalId,
                            ':document_id' => $allocation['document_journal_id'],
                            ':account_id' => (int) $accountId,
                            ':amount' => $allocation['amount'],
                        ]);
                    }
                }
            }

            $lineStmt = $pdo->prepare(
                "INSERT INTO transactions (transaction_journal_id, account_id, entry_type, amount)
                 VALUES (:journal_id, :account_id, :entry_type, :amount)"
            );

            $allLines = [];
            foreach ($debitLines as $line) {
                $lineStmt->execute([
                    ':journal_id' => $journalId,
                    ':account_id' => (int) $line['account_id'],
                    ':entry_type' => 'debit',
                    ':amount'     => (float) $line['amount'],
                ]);
                $allLines[] = ['account_id' => (int) $line['account_id'], 'entry_type' => 'debit', 'amount' => (float) $line['amount']];
            }

            foreach ($creditLines as $line) {
                $lineStmt->execute([
                    ':journal_id' => $journalId,
                    ':account_id' => (int) $line['account_id'],
                    ':entry_type' => 'credit',
                    ':amount'     => (float) $line['amount'],
                ]);
                $allLines[] = ['account_id' => (int) $line['account_id'], 'entry_type' => 'credit', 'amount' => (float) $line['amount']];
            }

            if ($categoryId > 0) {
                $categoryStmt = $pdo->prepare(
                    "INSERT INTO journal_category (transaction_journal_id, category_id) VALUES (:journal_id, :category_id)"
                );
                $categoryStmt->execute([':journal_id' => $journalId, ':category_id' => $categoryId]);
            }

            logAudit(
                $pdo,
                $userId,
                'CREATE_JOURNAL_ENTRY',
                'journal',
                $journalId,
                json_encode([
                    'transaction_type_id' => $transactionTypeId,
                    'total_amount' => $totalDebits,
                    'client_id' => $clientId,
                    'reference_no' => $referenceNo,
                    'document_type' => $documentType !== '' ? $documentType : null,
                    'settlement_allocations' => $allocationPlans,
                ], JSON_THROW_ON_ERROR)
            );

            foreach ($allLines as $line) {
                $balanceStmt = $pdo->prepare(
                    "SELECT at.normal_balance FROM accounts a
                     JOIN account_types at ON at.id = a.account_type_id
                     WHERE a.id = :id"
                );
                $balanceStmt->execute([':id' => $line['account_id']]);
                $accountInfo = $balanceStmt->fetch(PDO::FETCH_ASSOC);

                if ($accountInfo) {
                    $isDebit = $line['entry_type'] === 'debit';
                    $matchesNormalSide = ($isDebit && $accountInfo['normal_balance'] === 'debit')
                                      || (!$isDebit && $accountInfo['normal_balance'] === 'credit');

                    $delta = $matchesNormalSide ? $line['amount'] : -$line['amount'];

                    $updateBalanceStmt = $pdo->prepare(
                        "UPDATE accounts SET current_balance = current_balance + :delta WHERE id = :id"
                    );
                    $updateBalanceStmt->execute([':delta' => $delta, ':id' => $line['account_id']]);
                }
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Journal entry recorded successfully.', 'journal_id' => $journalId]);
            exit;

        } catch (DomainException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Journal creation failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Journal entry could not be recorded. Please check the database setup and try again.']);
            exit;
        }
        break;

    // 4. EDIT ENTRY HEADER DETAILS
    case 'edit':
        $journalId   = (int) ($_POST['journal_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $categoryId  = (int) ($_POST['category_id'] ?? 0);

        if ($journalId === 0 || $description === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Journal ID and description are required.']);
            break;
        }

        $pdo = getDbConnection();

        $checkStmt = $pdo->prepare("SELECT id, status FROM transaction_journals WHERE id = :id");
        $checkStmt->execute([':id' => $journalId]);
        $journal = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$journal) {
            http_response_code(404);
            echo json_encode(['error' => 'Journal entry not found.']);
            break;
        }

        if ($journal['status'] === 'deletion_pending') {
            http_response_code(422);
            echo json_encode(['error' => 'Cannot edit an entry while a deletion request is pending review.']);
            break;
        }

        try {
            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare(
                "UPDATE transaction_journals
                 SET description = :description, reference_no = :reference,
                     is_edited = 1, edited_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $updateStmt->execute([
                ':description' => $description,
                ':reference'   => $referenceNo,
                ':id'          => $journalId,
            ]);

            $deleteCategoryStmt = $pdo->prepare("DELETE FROM journal_category WHERE transaction_journal_id = :id");
            $deleteCategoryStmt->execute([':id' => $journalId]);

            if ($categoryId > 0) {
                $insertCategoryStmt = $pdo->prepare(
                    "INSERT INTO journal_category (transaction_journal_id, category_id) VALUES (:id, :category_id)"
                );
                $insertCategoryStmt->execute([':id' => $journalId, ':category_id' => $categoryId]);
            }

            logAudit(
                $pdo,
                $userId,
                'EDIT_JOURNAL_ENTRY',
                'journal',
                $journalId,
                json_encode([
                    'description' => $description,
                    'reference_no' => $referenceNo,
                    'category_id' => $categoryId ?: null,
                ], JSON_THROW_ON_ERROR)
            );

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Journal entry updated successfully.']);

        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Something went wrong while updating the entry: ' . $e->getMessage()]);
        }
        break;

    // 5. VIEW ENTRY DETAILS
    case 'view':
        $journalId = (int) ($_GET['journal_id'] ?? $_POST['journal_id'] ?? 0);

        if ($journalId === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Journal ID is required.']);
            break;
        }

        $pdo = getDbConnection();

        $headerStmt = $pdo->prepare(
            "SELECT 
                tj.id, 
                tj.description, 
                tj.transaction_date, 
                tj.reference_no, 
                tj.total_amount, 
                tj.created_at, 
                tj.status,
                tj.is_edited,
                tj.edited_at,
                tt.name AS type_name, 
                u.full_name AS created_by_name, 
                c.name AS client_name,
                cat.name AS category_name
            FROM transaction_journals tj
            JOIN transaction_types tt ON tt.id = tj.transaction_type_id
            JOIN users u ON u.id = tj.created_by
            LEFT JOIN clients c ON c.id = tj.client_id
            LEFT JOIN journal_category jc ON jc.transaction_journal_id = tj.id
            LEFT JOIN categories cat ON cat.id = jc.category_id
            WHERE tj.id = :id"
        );
        $headerStmt->execute([':id' => $journalId]);
        $header = $headerStmt->fetch(PDO::FETCH_ASSOC);

        if (!$header) {
            http_response_code(404);
            echo json_encode(['error' => 'Journal entry not found.']);
            break;
        }

        $linesStmt = $pdo->prepare(
            "SELECT t.entry_type, t.amount, a.name AS account_name, at.name AS account_type
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             WHERE t.transaction_journal_id = :id
             ORDER BY t.entry_type DESC"
        );
        $linesStmt->execute([':id' => $journalId]);
        $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'journal' => $header, 'lines' => $lines]);
        break;

    // 6. REQUEST DELETION (ADMIN & ACCOUNTANT)
    case 'request-deletion':
        if (!in_array($userRole, ['admin', 'accountant', 'owner'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Permission denied.']);
            exit;
        }

        $journalId = (int)($_POST['journal_id'] ?? 0);
        $reason    = trim($_POST['reason'] ?? '');

        if ($journalId <= 0 || $reason === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Journal ID and reason are required.']);
            exit;
        }

        $pdo = getDbConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM transaction_journals WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $journalId]);
            $journal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$journal || $journal['status'] !== 'posted') {
                $pdo->rollBack();
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Entry is invalid or already has a pending deletion request.']);
                exit;
            }

            $linesStmt = $pdo->prepare("SELECT * FROM transactions WHERE transaction_journal_id = :id");
            $linesStmt->execute([':id' => $journalId]);
            $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

            $snapshot = json_encode(['journal' => $journal, 'lines' => $lines]);

            $reqStmt = $pdo->prepare(
                "INSERT INTO deletion_requests (transaction_journal_id, requested_by, reason, journal_snapshot) 
                 VALUES (:jid, :uid, :reason, :snap)"
            );
            $reqStmt->execute([
                ':jid'    => $journalId,
                ':uid'    => $userId,
                ':reason' => $reason,
                ':snap'   => $snapshot
            ]);

            $updateStmt = $pdo->prepare("UPDATE transaction_journals SET status = 'deletion_pending' WHERE id = :id");
            $updateStmt->execute([':id' => $journalId]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Deletion request submitted for Owner review.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to submit deletion request: ' . $e->getMessage()]);
        }
        break;

    // 7. LIST PENDING DELETION REQUESTS (OWNER ONLY)
    case 'list-pending-deletions':
        if ($userRole !== 'owner') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
            exit;
        }

        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            "SELECT dr.id, dr.transaction_journal_id, dr.reason, dr.created_at, u.full_name AS requested_by_name
             FROM deletion_requests dr
             JOIN users u ON u.id = dr.requested_by
             WHERE dr.status = 'pending'
             ORDER BY dr.created_at DESC"
        );
        $stmt->execute();
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'requests' => $requests]);
        break;

    // 8. APPROVE DELETION & VOID (OWNER ONLY)
    case 'approve-deletion':
        if ($userRole !== 'owner') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only the Owner can approve deletion requests.']);
            exit;
        }

        $requestId = (int)($_POST['request_id'] ?? 0);

        if ($requestId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid request ID.']);
            exit;
        }

        $pdo = getDbConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM deletion_requests WHERE id = :id AND status = 'pending' FOR UPDATE");
            $stmt->execute([':id' => $requestId]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                $pdo->rollBack();
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Request not found or already processed.']);
                exit;
            }

            $journalId = (int)$req['transaction_journal_id'];
            ensurePartyDocumentCanBeVoided($pdo, $journalId);

            // Reverse balances
            $linesStmt = $pdo->prepare("SELECT account_id, amount, entry_type FROM transactions WHERE transaction_journal_id = :id");
            $linesStmt->execute([':id' => $journalId]);
            $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($lines as $line) {
                $balanceStmt = $pdo->prepare(
                    "SELECT at.normal_balance FROM accounts a
                     JOIN account_types at ON at.id = a.account_type_id
                     WHERE a.id = :id"
                );
                $balanceStmt->execute([':id' => $line['account_id']]);
                $accountInfo = $balanceStmt->fetch(PDO::FETCH_ASSOC);

                if ($accountInfo) {
                    $isDebit = strtolower((string)$line['entry_type']) === 'debit';
                    $matchesNormalSide = ($isDebit && $accountInfo['normal_balance'] === 'debit')
                                      || (!$isDebit && $accountInfo['normal_balance'] === 'credit');

                    $delta = $matchesNormalSide ? -(float)$line['amount'] : (float)$line['amount'];

                    $balStmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + :delta WHERE id = :aid");
                    $balStmt->execute([':delta' => $delta, ':aid' => $line['account_id']]);
                }
            }

            $voidStmt = $pdo->prepare("UPDATE transaction_journals SET status = 'voided' WHERE id = :id");
            $voidStmt->execute([':id' => $journalId]);

            $appStmt = $pdo->prepare(
                "UPDATE deletion_requests 
                 SET status = 'approved', reviewed_by = :uid, reviewed_at = NOW() 
                 WHERE id = :id"
            );
            $appStmt->execute([':uid' => $userId, ':id' => $requestId]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Entry voided and account balances adjusted.']);
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Journal deletion approval failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Approval failed. Please try again.']);
        }
        break;

    // 9. REJECT DELETION REQUEST (OWNER ONLY)
    case 'reject-deletion':
        if ($userRole !== 'owner') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only the Owner can reject deletion requests.']);
            exit;
        }

        $requestId = (int)($_POST['request_id'] ?? 0);
        $note      = trim($_POST['owner_note'] ?? '');

        if ($requestId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid request ID.']);
            exit;
        }

        $pdo = getDbConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT transaction_journal_id FROM deletion_requests WHERE id = :id AND status = 'pending' FOR UPDATE");
            $stmt->execute([':id' => $requestId]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                $pdo->rollBack();
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Request not found or already processed.']);
                exit;
            }

            $restStmt = $pdo->prepare("UPDATE transaction_journals SET status = 'posted' WHERE id = :id");
            $restStmt->execute([':id' => $req['transaction_journal_id']]);

            $rejStmt = $pdo->prepare(
                "UPDATE deletion_requests 
                 SET status = 'rejected', owner_note = :note, reviewed_by = :uid, reviewed_at = NOW() 
                 WHERE id = :id"
            );
            $rejStmt->execute([':note' => $note, ':uid' => $userId, ':id' => $requestId]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Deletion request rejected. Entry restored to posted status.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Rejection failed: ' . $e->getMessage()]);
        }
        break;

    // 10. DIRECT VOID (OWNER ONLY)
    case 'void':
        if ($userRole !== 'owner') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only the Owner role is authorized to void entries directly.']);
            exit;
        }

        $journalId = (int)($_POST['journal_id'] ?? 0);

        if ($journalId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid or missing journal ID.']);
            exit;
        }

        $pdo = getDbConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id, status FROM transaction_journals WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $journalId]);
            $journal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$journal) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Journal entry not found in database.']);
                exit;
            }

            if ($journal['status'] === 'voided') {
                $pdo->rollBack();
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'This entry is already voided.']);
                exit;
            }

            ensurePartyDocumentCanBeVoided($pdo, $journalId);

            $linesStmt = $pdo->prepare("SELECT account_id, amount, entry_type FROM transactions WHERE transaction_journal_id = :id");
            $linesStmt->execute([':id' => $journalId]);
            $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($lines as $line) {
                $balanceStmt = $pdo->prepare(
                    "SELECT at.normal_balance FROM accounts a
                     JOIN account_types at ON at.id = a.account_type_id
                     WHERE a.id = :id"
                );
                $balanceStmt->execute([':id' => $line['account_id']]);
                $accountInfo = $balanceStmt->fetch(PDO::FETCH_ASSOC);

                if ($accountInfo) {
                    $isDebit = strtolower((string)$line['entry_type']) === 'debit';
                    $matchesNormalSide = ($isDebit && $accountInfo['normal_balance'] === 'debit')
                                      || (!$isDebit && $accountInfo['normal_balance'] === 'credit');

                    $delta = $matchesNormalSide ? -(float)$line['amount'] : (float)$line['amount'];

                    $balStmt = $pdo->prepare("UPDATE accounts SET current_balance = current_balance + :delta WHERE id = :aid");
                    $balStmt->execute([':delta' => $delta, ':aid' => $line['account_id']]);
                }
            }

            $voidStmt = $pdo->prepare("UPDATE transaction_journals SET status = 'voided' WHERE id = :id");
            $voidStmt->execute([':id' => $journalId]);

            $reqStmt = $pdo->prepare(
                "UPDATE deletion_requests 
                 SET status = 'approved', reviewed_by = :uid, reviewed_at = NOW() 
                 WHERE transaction_journal_id = :jid AND status = 'pending'"
            );
            $reqStmt->execute([':uid' => $userId, ':jid' => $journalId]);

            logAudit(
                $pdo,
                $userId,
                'VOID_JOURNAL_ENTRY',
                'journal',
                $journalId
            );

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Entry successfully voided and balances adjusted.']);
            exit;

        } catch (DomainException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Journal void failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database failure while voiding the entry. Please try again.']);
            exit;
        }
        break;

    // 11. RECENT TRANSACTIONS
    case 'recent':
        $limit = (int) ($_GET['limit'] ?? 8);
        $pdo = getDbConnection();

        $stmt = $pdo->prepare(
            "SELECT tj.id, tj.description, tj.transaction_date, tj.total_amount, 
                    tt.name AS type_name, c.name AS category_name
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             LEFT JOIN journal_category jc ON jc.transaction_journal_id = tj.id
             LEFT JOIN categories c ON c.id = jc.category_id
             WHERE tj.status != 'voided'
             ORDER BY tj.transaction_date DESC, tj.id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'transactions' => $recent]);
        break;

    // 12. SUMMARY STATISTICS
    case 'summary':
        $pdo = getDbConnection();

        $assetsStmt = $pdo->query(
            "SELECT COALESCE(SUM(a.current_balance), 0) AS total
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE at.name = 'Asset' AND a.is_active = 1"
        );
        $totalAssets = (float) $assetsStmt->fetch(PDO::FETCH_ASSOC)['total'];

        $inflowStmt = $pdo->query(
            "SELECT COALESCE(SUM(t.amount), 0) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             JOIN transactions t ON t.transaction_journal_id = tj.id
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             WHERE tt.name = 'Cash Inflow'
               AND tj.status != 'voided'
               AND t.entry_type = 'debit'
               AND at.name = 'Asset'
               AND a.is_cash_account = 1
               AND MONTH(tj.transaction_date) = MONTH(CURDATE())
               AND YEAR(tj.transaction_date) = YEAR(CURDATE())"
        );
        $cashInflow = (float) $inflowStmt->fetch(PDO::FETCH_ASSOC)['total'];

        $outflowStmt = $pdo->query(
            "SELECT COALESCE(SUM(t.amount), 0) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             JOIN transactions t ON t.transaction_journal_id = tj.id
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             WHERE tt.name = 'Cash Outflow'
               AND tj.status != 'voided'
               AND t.entry_type = 'credit'
               AND at.name = 'Asset'
               AND a.is_cash_account = 1
               AND MONTH(tj.transaction_date) = MONTH(CURDATE())
               AND YEAR(tj.transaction_date) = YEAR(CURDATE())"
        );

        $cashOutflow = (float) $outflowStmt->fetch(PDO::FETCH_ASSOC)['total'];

        $arStmt = $pdo->query(
            "SELECT COALESCE(SUM(current_balance), 0) AS total FROM accounts WHERE name = 'Accounts Receivable'"
        );
        $totalReceivable = (float) $arStmt->fetch(PDO::FETCH_ASSOC)['total'];

        $apStmt = $pdo->query(
            "SELECT COALESCE(SUM(current_balance), 0) AS total FROM accounts WHERE name = 'Accounts Payable'"
        );
        $totalPayable = (float) $apStmt->fetch(PDO::FETCH_ASSOC)['total'];

        echo json_encode([
            'success'          => true,
            'total_assets'     => $totalAssets,
            'cash_inflow'      => $cashInflow,
            'cash_outflow'     => $cashOutflow,
            'net_cash_flow'    => $cashInflow - $cashOutflow,
            'total_receivable' => $totalReceivable,
            'total_payable'    => $totalPayable,
        ]);
        break;

    // 13. CASHFLOW CHART
    case 'cashflow-chart':
        $days = (int) ($_GET['days'] ?? 14);
        $pdo  = getDbConnection();

        $stmt = $pdo->prepare(
            "SELECT tj.transaction_date, tt.name AS type_name, SUM(t.amount) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             JOIN transactions t ON t.transaction_journal_id = tj.id
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             WHERE tt.name IN ('Cash Inflow', 'Cash Outflow')
               AND tj.status != 'voided'
               AND at.name = 'Asset'
               AND a.is_cash_account = 1
               AND ((tt.name = 'Cash Inflow' AND t.entry_type = 'debit')
                 OR (tt.name = 'Cash Outflow' AND t.entry_type = 'credit'))
               AND tj.transaction_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             GROUP BY tj.transaction_date, tt.name
             ORDER BY tj.transaction_date ASC"
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byDate = [];
        foreach ($rows as $row) {
            $date = $row['transaction_date'];
            if (!isset($byDate[$date])) $byDate[$date] = ['in' => 0, 'out' => 0];
            if ($row['type_name'] === 'Cash Inflow') {
                $byDate[$date]['in'] = (float) $row['total'];
            } else {
                $byDate[$date]['out'] = (float) $row['total'];
            }
        }

        $labels  = [];
        $cashIn  = [];
        $cashOut = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $labels[]  = date('M j', strtotime($date));
            $cashIn[]  = $byDate[$date]['in'] ?? 0;
            $cashOut[] = $byDate[$date]['out'] ?? 0;
        }

        echo json_encode([
            'success'  => true,
            'labels'   => $labels,
            'cash_in'  => $cashIn,
            'cash_out' => $cashOut,
        ]);
        break;

    // 14. TODAY SALES STATS
    case 'today-sales':
        $pdo = getDbConnection();

        $todayStmt = $pdo->query(
            "SELECT COALESCE(SUM(t.amount), 0) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             JOIN transactions t ON t.transaction_journal_id = tj.id
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             WHERE tt.name = 'Cash Inflow' AND tj.transaction_date = CURDATE()"
             . " AND tj.status != 'voided' AND t.entry_type = 'debit' AND at.name = 'Asset'
               AND a.is_cash_account = 1"
        );
        $todaySales = (float) $todayStmt->fetch(PDO::FETCH_ASSOC)['total'];

        $yesterdayStmt = $pdo->query(
            "SELECT COALESCE(SUM(t.amount), 0) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             JOIN transactions t ON t.transaction_journal_id = tj.id
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             WHERE tt.name = 'Cash Inflow' AND tj.status != 'voided'
               AND t.entry_type = 'debit' AND at.name = 'Asset'
               AND a.is_cash_account = 1
               AND tj.transaction_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
        );
        $yesterdaySales = (float) $yesterdayStmt->fetch(PDO::FETCH_ASSOC)['total'];

        $percentChange = $yesterdaySales > 0
            ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100, 1)
            : ($todaySales > 0 ? 100 : 0);

        $weekStmt = $pdo->prepare(
            "SELECT tj.transaction_date, COALESCE(SUM(t.amount), 0) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             JOIN transactions t ON t.transaction_journal_id = tj.id
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             WHERE tt.name = 'Cash Inflow' AND tj.status != 'voided'
               AND t.entry_type = 'debit' AND at.name = 'Asset'
               AND a.is_cash_account = 1
               AND tj.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY tj.transaction_date"
        );
        $weekStmt->execute();
        $weekRows = $weekStmt->fetchAll(PDO::FETCH_ASSOC);

        $byDate = [];
        foreach ($weekRows as $row) {
            $byDate[$row['transaction_date']] = (float) $row['total'];
        }

        $weekLabels = [];
        $weekValues = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $weekLabels[] = date('D', strtotime($date));
            $weekValues[] = $byDate[$date] ?? 0;
        }

        echo json_encode([
            'success'        => true,
            'today_sales'    => $todaySales,
            'percent_change' => $percentChange,
            'week_labels'    => $weekLabels,
            'week_values'    => $weekValues,
        ]);
        break;

    // 15. TOTALS PANEL DATA
    case 'totals':
        $search   = trim($_GET['search'] ?? '');
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo   = $_GET['date_to'] ?? '';
        $typeId   = (int) ($_GET['transaction_type_id'] ?? 0);

        $sql = "SELECT tt.name AS type_name, COUNT(*) AS entry_count,
                       SUM(CASE
                           WHEN tt.name = 'Cash Inflow' THEN (
                               SELECT COALESCE(SUM(t.amount), 0)
                               FROM transactions t
                               JOIN accounts a ON a.id = t.account_id
                               JOIN account_types at ON at.id = a.account_type_id
                               WHERE t.transaction_journal_id = tj.id AND t.entry_type = 'debit'
                                 AND at.name = 'Asset'
                                 AND a.is_cash_account = 1
                           )
                           WHEN tt.name = 'Cash Outflow' THEN (
                               SELECT COALESCE(SUM(t.amount), 0)
                               FROM transactions t
                               JOIN accounts a ON a.id = t.account_id
                               JOIN account_types at ON at.id = a.account_type_id
                               WHERE t.transaction_journal_id = tj.id AND t.entry_type = 'credit'
                                 AND at.name = 'Asset'
                                 AND a.is_cash_account = 1
                           )
                           ELSE tj.total_amount
                       END) AS total
                FROM transaction_journals tj
                JOIN transaction_types tt ON tt.id = tj.transaction_type_id
                JOIN users u ON u.id = tj.created_by
                WHERE tj.status != 'voided'";

        $params = [];

        if ($search !== '') {
            $sql .= " AND (tj.description LIKE :search1 OR tj.reference_no LIKE :search2 OR u.full_name LIKE :search3)";
            $params[':search1'] = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
            $params[':search3'] = '%' . $search . '%';
        }
        if ($dateFrom !== '') {
            $sql .= " AND tj.transaction_date >= :date_from";
            $params[':date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $sql .= " AND tj.transaction_date <= :date_to";
            $params[':date_to'] = $dateTo;
        }
        if ($typeId > 0) {
            $sql .= " AND tj.transaction_type_id = :type_id";
            $params[':type_id'] = $typeId;
        }

        $sql .= " GROUP BY tt.name";

        $pdo = getDbConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalInflow  = 0;
        $totalOutflow = 0;
        $entryCount   = 0;

        foreach ($rows as $row) {
            $entryCount += (int) $row['entry_count'];
            if ($row['type_name'] === 'Cash Inflow') {
                $totalInflow = (float) $row['total'];
            } elseif ($row['type_name'] === 'Cash Outflow') {
                $totalOutflow = (float) $row['total'];
            }
        }

        echo json_encode([
            'success'       => true,
            'entry_count'   => $entryCount,
            'total_inflow'  => $totalInflow,
            'total_outflow' => $totalOutflow,
            'net_total'     => $totalInflow - $totalOutflow,
        ]);
        break;

    // 16. RECEIVABLES & PAYABLES SUMMARY
    case 'receivables-payables':
        $pdo = getDbConnection();

        $buildSummary = function (
            string $accountName,
            string $accountType,
            string $increaseEntryType
        ) use ($pdo) {
            $accountsStmt = $pdo->prepare(
                "SELECT a.id FROM accounts a
                 JOIN account_types at ON at.id = a.account_type_id
                 WHERE a.name = :name AND at.name = :type"
            );
            $accountsStmt->execute([':name' => $accountName, ':type' => $accountType]);
            $accountIds = array_map('intval', $accountsStmt->fetchAll(PDO::FETCH_COLUMN));
            $currentAmount = 0.0;
            $overdueAmount = 0.0;
            $today = date('Y-m-d');
            foreach ($accountIds as $accountId) {
                $items = getPartyOpenItems($pdo, $accountId, $increaseEntryType);
                foreach ($items as $item) {
                    if ($item['due_date'] === null || $item['due_date'] >= $today) {
                        $currentAmount += (float) $item['remaining'];
                    } else {
                        $overdueAmount += (float) $item['remaining'];
                    }
                }
            }
            $total = $currentAmount + $overdueAmount;

            return [
                'total' => round($total, 2),
                'current' => round($currentAmount, 2),
                'overdue' => round($overdueAmount, 2),
            ];
        };

        $receivables = $buildSummary('Accounts Receivable', 'Asset', 'debit');
        $payables    = $buildSummary('Accounts Payable', 'Liability', 'credit');

        echo json_encode([
            'success'     => true,
            'receivables' => $receivables,
            'payables'    => $payables,
        ]);
        break;

    // 17. GENERAL LEDGER DATA
    case 'ledger':
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $dateFrom  = $_GET['date_from'] ?? '';
        $dateTo    = $_GET['date_to'] ?? date('Y-m-d');

        if ($accountId === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Account is required.']);
            break;
        }

        $pdo = getDbConnection();

        $accountStmt = $pdo->prepare(
            "SELECT a.id, a.name, at.name AS type_name, at.normal_balance
             FROM accounts a JOIN account_types at ON at.id = a.account_type_id
             WHERE a.id = :id"
        );
        $accountStmt->execute([':id' => $accountId]);
        $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            http_response_code(404);
            echo json_encode(['error' => 'Account not found.']);
            break;
        }

        $openingBalance = 0;
        if ($dateFrom !== '') {
            $openingStmt = $pdo->prepare(
                "SELECT 
                    COALESCE(SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE 0 END), 0) AS total_debit,
                    COALESCE(SUM(CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE 0 END), 0) AS total_credit
                 FROM transactions t
                 JOIN transaction_journals tj ON tj.id = t.transaction_journal_id
                 WHERE t.account_id = :id AND tj.status != 'voided'
                   AND tj.transaction_date < :date_from"
            );
            $openingStmt->execute([':id' => $accountId, ':date_from' => $dateFrom]);
            $row = $openingStmt->fetch(PDO::FETCH_ASSOC);
            $debit  = (float) $row['total_debit'];
            $credit = (float) $row['total_credit'];
            $openingBalance = $account['normal_balance'] === 'debit' ? ($debit - $credit) : ($credit - $debit);
        }

        $sql = "SELECT tj.transaction_date, tj.description, tj.reference_no, t.entry_type, t.amount
                FROM transactions t
                JOIN transaction_journals tj ON tj.id = t.transaction_journal_id
                WHERE t.account_id = :id AND tj.status != 'voided'";
        $params = [':id' => $accountId];

        if ($dateFrom !== '') {
            $sql .= " AND tj.transaction_date >= :date_from";
            $params[':date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $sql .= " AND tj.transaction_date <= :date_to";
            $params[':date_to'] = $dateTo;
        }
        $sql .= " ORDER BY tj.transaction_date ASC, tj.id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $runningBalance = $openingBalance;
        $lines = [];

        foreach ($rows as $row) {
            $debit  = $row['entry_type'] === 'debit' ? (float) $row['amount'] : 0;
            $credit = $row['entry_type'] === 'credit' ? (float) $row['amount'] : 0;

            $delta = $account['normal_balance'] === 'debit' ? ($debit - $credit) : ($credit - $debit);
            $runningBalance += $delta;

            $lines[] = [
                'date'            => $row['transaction_date'],
                'description'     => $row['description'],
                'reference_no'    => $row['reference_no'],
                'debit'           => $debit,
                'credit'          => $credit,
                'running_balance' => $runningBalance,
            ];
        }

        echo json_encode([
            'success'         => true,
            'account_name'    => $account['name'],
            'account_type'    => $account['type_name'],
            'opening_balance' => $openingBalance,
            'closing_balance' => $runningBalance,
            'lines'           => $lines,
        ]);
        break;

    // 18. CASHFLOW REPORT DATA
    case 'cashflow-report':
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo   = $_GET['date_to'] ?? date('Y-m-d');
        if (!is_string($dateFrom) || !is_string($dateTo)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) !== 1) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Enter a valid cash flow date range.']);
            break;
        }
        $parsedDateFrom = DateTimeImmutable::createFromFormat('!Y-m-d', $dateFrom);
        $parsedDateTo = DateTimeImmutable::createFromFormat('!Y-m-d', $dateTo);
        if (!$parsedDateFrom || !$parsedDateTo
            || $parsedDateFrom->format('Y-m-d') !== $dateFrom
            || $parsedDateTo->format('Y-m-d') !== $dateTo
            || $parsedDateFrom > $parsedDateTo) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Enter a valid cash flow date range.']);
            break;
        }

        $pdo = getDbConnection();

        $totalsStmt = $pdo->prepare(
            "SELECT tt.name AS type_name, COALESCE(SUM(t.amount), 0) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             JOIN transactions t ON t.transaction_journal_id = tj.id
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             WHERE tt.name IN ('Cash Inflow', 'Cash Outflow')
               AND tj.status != 'voided' AND at.name = 'Asset'
               AND a.is_cash_account = 1
               AND ((tt.name = 'Cash Inflow' AND t.entry_type = 'debit')
                 OR (tt.name = 'Cash Outflow' AND t.entry_type = 'credit'))
               AND tj.transaction_date BETWEEN :date_from AND :date_to
             GROUP BY tt.name"
        );
        $totalsStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
        $totalsRows = $totalsStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalInflow  = 0;
        $totalOutflow = 0;
        foreach ($totalsRows as $row) {
            if ($row['type_name'] === 'Cash Inflow') $totalInflow = (float) $row['total'];
            else $totalOutflow = (float) $row['total'];
        }

        $dailyStmt = $pdo->prepare(
            "SELECT tj.transaction_date, tt.name AS type_name, SUM(t.amount) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             JOIN transactions t ON t.transaction_journal_id = tj.id
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             WHERE tt.name IN ('Cash Inflow', 'Cash Outflow')
               AND tj.status != 'voided' AND at.name = 'Asset'
               AND a.is_cash_account = 1
               AND ((tt.name = 'Cash Inflow' AND t.entry_type = 'debit')
                 OR (tt.name = 'Cash Outflow' AND t.entry_type = 'credit'))
               AND tj.transaction_date BETWEEN :date_from AND :date_to
             GROUP BY tj.transaction_date, tt.name
             ORDER BY tj.transaction_date ASC"
        );
        $dailyStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
        $dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

        $byDate = [];
        foreach ($dailyRows as $row) {
            $date = $row['transaction_date'];
            if (!isset($byDate[$date])) $byDate[$date] = ['in' => 0, 'out' => 0];
            if ($row['type_name'] === 'Cash Inflow') $byDate[$date]['in'] = (float) $row['total'];
            else $byDate[$date]['out'] = (float) $row['total'];
        }

        $labels  = [];
        $cashIn  = [];
        $cashOut = [];
        $period  = new DatePeriod($parsedDateFrom, new DateInterval('P1D'), $parsedDateTo->modify('+1 day'));
        foreach ($period as $date) {
            $key       = $date->format('Y-m-d');
            $labels[]  = $date->format('M j');
            $cashIn[]  = $byDate[$key]['in'] ?? 0;
            $cashOut[] = $byDate[$key]['out'] ?? 0;
        }

        $breakdownStmt = $pdo->prepare(
            "SELECT tt.name AS type_name, COALESCE(c.name, 'Uncategorized') AS category_name, 
                    SUM(t.amount) AS total
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             JOIN transactions t ON t.transaction_journal_id = tj.id
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             LEFT JOIN journal_category jc ON jc.transaction_journal_id = tj.id
             LEFT JOIN categories c ON c.id = jc.category_id
             WHERE tt.name IN ('Cash Inflow', 'Cash Outflow')
               AND tj.status != 'voided' AND at.name = 'Asset'
               AND a.is_cash_account = 1
               AND ((tt.name = 'Cash Inflow' AND t.entry_type = 'debit')
                 OR (tt.name = 'Cash Outflow' AND t.entry_type = 'credit'))
               AND tj.transaction_date BETWEEN :date_from AND :date_to
             GROUP BY tt.name, category_name
             ORDER BY total DESC"
        );
        $breakdownStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
        $breakdownRows = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);

        $inflowBreakdown  = [];
        $outflowBreakdown = [];
        foreach ($breakdownRows as $row) {
            $entry = ['category' => $row['category_name'], 'total' => (float) $row['total']];
            if ($row['type_name'] === 'Cash Inflow') $inflowBreakdown[] = $entry;
            else $outflowBreakdown[] = $entry;
        }

        echo json_encode([
            'success'          => true,
            'date_from'        => $dateFrom,
            'date_to'          => $dateTo,
            'total_inflow'     => $totalInflow,
            'total_outflow'    => $totalOutflow,
            'net_cash_flow'    => $totalInflow - $totalOutflow,
            'labels'           => $labels,
            'cash_in'          => $cashIn,
            'cash_out'         => $cashOut,
            'inflow_breakdown' => $inflowBreakdown,
            'outflow_breakdown'=> $outflowBreakdown,
        ]);
        break;

    // 19. FETCH ACTIVE CLIENTS LIST
    case 'get_clients':
        $pdo  = getDbConnection();
        $stmt = $pdo->query("SELECT id, name, company_name, party_type FROM clients WHERE status = 'active' ORDER BY name ASC");
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'clients' => $clients]);
        break;

    //20. SCANNER RECEIPT DATA SESSIONS
     case 'init-scan-session':
    $token = bin2hex(random_bytes(8));
    $pdo = getDbConnection();
    $host = $_SERVER['HTTP_HOST'];
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";

    $scriptName = $_SERVER['SCRIPT_NAME']; // e.g. /wrs-accounting-collab/backend/routes/journal.php
    $pathParts = explode('/', trim($scriptName, '/'));
    $projectFolder = !empty($pathParts[0]) ? $pathParts[0] : '';
    
    // Includes the correct plural 'routes' folder path
    $basePath = $projectFolder ? "/{$projectFolder}/backend/routes" : '/backend/routes';

    $mobileUrl = "{$protocol}://{$host}{$basePath}/../mobile-scan.php?token={$token}&ngrok-skip-browser-warning=true";

    $stmt = $pdo->prepare("INSERT INTO pending_receipt_scans (token, status) VALUES (:token, 'pending')");
    $stmt->execute([':token' => $token]);

    echo json_encode([
        'success' => true, 
        'token' => $token,
        'mobile_url' => $mobileUrl
    ]);
    break;

            case 'check-scan-session':
    $pdo = getDbConnection();
    $token = $_GET['token'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM pending_receipt_scans WHERE token = :token AND status = 'completed'");
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // Clean up completed token record
        $deleteStmt = $pdo->prepare("DELETE FROM pending_receipt_scans WHERE token = :token");
        $deleteStmt->execute([':token' => $token]);

        // Decode the stored JSON extracted_data column
        $extracted = json_decode($row['extracted_data'] ?? '', true) ?: [];

        echo json_encode([
            'success' => true,
            'status' => 'completed',
            'image_path' => $row['image_path'],
            'extracted' => [
                'vendor' => $extracted['vendor'] ?? 'Scanned Vendor',
                'date' => $extracted['date'] ?? date('Y-m-d'),
                'reference_no' => $extracted['reference_no'] ?? ('REC-' . rand(1000, 9999)),
                'amount' => $extracted['amount'] ?? '0.00'
            ]
        ]);
    } else {
        echo json_encode(['success' => true, 'status' => 'pending']);
    }
    break;

           case 'push-mobile-receipt':
    $pdo = getDbConnection();
    $token = $_POST['token'] ?? '';
    if (empty($token) || !isset($_FILES['receipt_image'])) {
        echo json_encode(['success' => false, 'error' => 'Missing token or image.']);
        exit;
    }

    $uploadDir = '../../uploads/receipts/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileExt = pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION);
    $fileName = 'receipt_' . uniqid() . '.' . ($fileExt ?: 'jpg');
    $targetPath = $uploadDir . $fileName;

    if (move_uploaded_file($_FILES['receipt_image']['tmp_name'], $targetPath)) {
        $imagePath = 'uploads/receipts/' . $fileName;

        // Preprocess image for blurry/faded receipts using GD library
        $info = @getimagesize($targetPath);
        if ($info) {
            $img = null;
            if ($info['mime'] == 'image/jpeg') {
                $img = @imagecreatefromjpeg($targetPath);
            } elseif ($info['mime'] == 'image/png') {
                $img = @imagecreatefrompng($targetPath);
            }

            if ($img) {
                imagefilter($img, IMG_FILTER_GRAYSCALE);
                imagefilter($img, IMG_FILTER_CONTRAST, -30); // Higher contrast for faded ink
                if ($info['mime'] == 'image/jpeg') {
                    imagejpeg($img, $targetPath, 100);
                } else {
                    imagepng($img, $targetPath, 9);
                }
                imagedestroy($img);
            }
        }

        $extracted = [
            'vendor' => 'QUICK MART',
            'date' => date('Y-m-d'),
            'reference_no' => 'REC-' . rand(1000, 9999),
            'amount' => '0.00'
        ];

        try {
            $tesseractPath = '"C:\\Program Files\\Tesseract-OCR\\tesseract.exe"';
            // Added --oem 1 --psm 6 to better handle messy receipt fonts and blocks of text
            $command = "$tesseractPath " . escapeshellarg($targetPath) . " stdout --dpi 300 --oem 1 --psm 6 2>&1";
            $rawText = @shell_exec($command);

            error_log("TESSERACT RAW TEXT: " . $rawText);

            if ($rawText) {
                if (preg_match('/(?:^|\n)\s*total[^0-9]*([0-9]+\.[0-9]{2})/i', $rawText, $matches)) {
                    $extracted['amount'] = $matches[1];
                } elseif (preg_match('/total\D+([0-9]+\.[0-9]{2})/i', $rawText, $matches)) {
                    $extracted['amount'] = $matches[1];
                } else {
                    preg_match_all('/\b\d+\.\d{2}\b/', $rawText, $allPrices);
                    if (!empty($allPrices[0])) {
                        $extracted['amount'] = max(array_map('floatval', $allPrices[0]));
                    }
                }

                if (preg_match('/(\d{2}\/\d{2}\/\d{4})/', $rawText, $dateMatches)) {
                    $extracted['date'] = date('Y-m-d', strtotime($dateMatches[1]));
                }

                $lines = array_filter(explode("\n", $rawText));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strlen($line) > 3 && !str_contains($line, '---')) {
                        $extracted['vendor'] = substr($line, 0, 50);
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore
        }

        $stmt = $pdo->prepare("UPDATE pending_receipt_scans SET status = 'completed', image_path = :path, extracted_data = :extracted WHERE token = :token");
        $stmt->execute([
            ':path' => $imagePath,
            ':extracted' => json_encode($extracted),
            ':token' => $token
        ]);

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file.']);
    }
    break;
    
    case 'cleanup-scans':
    $pdo = getDbConnection();
    // Delete pending scans older than 1 hour or clean up temporary files
    $stmt = $pdo->prepare("DELETE FROM pending_receipt_scans WHERE created_at < (NOW() - INTERVAL 1 HOUR)");
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    break;

    // clients module
        // Fetch all clients for the management table
    case 'list-clients':
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT * FROM clients ORDER BY created_at DESC");
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'clients' => $clients]);
        break;

    case 'client-detail':
        $clientId = (int) ($_GET['client_id'] ?? 0);
        if ($clientId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'A valid client ID is required.']);
            break;
        }

        $pdo = getDbConnection();
        $clientStmt = $pdo->prepare(
            "SELECT id, name, company_name, party_type, email, phone, status
             FROM clients WHERE id = :id"
        );
        $clientStmt->execute([':id' => $clientId]);
        $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Client not found.']);
            break;
        }

        $receivableAccountsStmt = $pdo->query(
            "SELECT a.id FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE LOWER(a.name) = 'accounts receivable' AND at.name = 'Asset'"
        );
        $clientOpenItems = [];
        foreach ($receivableAccountsStmt->fetchAll(PDO::FETCH_COLUMN) as $accountId) {
            $clientOpenItems = array_merge(
                $clientOpenItems,
                getPartyOpenItems($pdo, (int) $accountId, 'debit', $clientId)
            );
        }
        $client['unsettled_balance'] = round(
            array_sum(array_column($clientOpenItems, 'remaining')),
            2
        );
        $payableAccountsStmt = $pdo->query(
            "SELECT a.id FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE LOWER(a.name) = 'accounts payable' AND at.name = 'Liability'"
        );
        $clientOpenPayables = [];
        foreach ($payableAccountsStmt->fetchAll(PDO::FETCH_COLUMN) as $accountId) {
            $clientOpenPayables = array_merge(
                $clientOpenPayables,
                getPartyOpenItems($pdo, (int) $accountId, 'credit', $clientId)
            );
        }
        $client['unsettled_payable_balance'] = round(
            array_sum(array_column($clientOpenPayables, 'remaining')),
            2
        );

        $historyStmt = $pdo->prepare(
            "SELECT tj.id, tj.description, tj.transaction_date, tj.reference_no,
                    tj.total_amount, tj.status, tt.name AS transaction_type,
                    (
                        SELECT GROUP_CONCAT(
                            CONCAT(a.name, ' (', UPPER(t.entry_type), ' ', FORMAT(t.amount, 2), ')')
                            ORDER BY t.entry_type, a.name SEPARATOR ' · '
                        )
                        FROM transactions t
                        JOIN accounts a ON a.id = t.account_id
                        WHERE t.transaction_journal_id = tj.id
                    ) AS entries
             FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             WHERE tj.client_id = :client_id
             ORDER BY tj.transaction_date DESC, tj.id DESC"
        );
        $historyStmt->execute([':client_id' => $clientId]);

        echo json_encode([
            'success' => true,
            'client' => $client,
            'history' => $historyStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
        break;

    case 'toggle-client-status':
        if (!in_array($userRole, ['admin', 'accountant', 'owner'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You do not have permission to manage clients.']);
            break;
        }

        $clientId = (int) ($_POST['client_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        if ($clientId <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'A valid client ID and status are required.']);
            break;
        }

        $pdo = getDbConnection();
        try {
            $pdo->beginTransaction();
            $clientStmt = $pdo->prepare('SELECT id, status FROM clients WHERE id = :id FOR UPDATE');
            $clientStmt->execute([':id' => $clientId]);
            $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
            if (!$client) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Client not found.']);
                break;
            }

            if ($client['status'] !== $status) {
                $updateStmt = $pdo->prepare('UPDATE clients SET status = :status WHERE id = :id');
                $updateStmt->execute([
                    ':status' => $status,
                    ':id' => $clientId,
                ]);
                logAudit(
                    $pdo,
                    $userId,
                    'TOGGLE_CLIENT_STATUS',
                    'client',
                    $clientId,
                    json_encode([
                        'old_status' => $client['status'],
                        'new_status' => $status,
                    ], JSON_THROW_ON_ERROR)
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Client status update failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update client status. Please try again.']);
            break;
        }

        echo json_encode([
            'success' => true,
            'status' => $status,
            'message' => $status === 'active' ? 'Client activated successfully.' : 'Client deactivated successfully.',
        ]);
        break;

    // Create a new client profile
    case 'create-client':
        if (!in_array($userRole, ['admin', 'accountant', 'owner'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You do not have permission to manage clients.']);
            break;
        }

        $pdo = getDbConnection();
        $name = trim($_POST['name'] ?? '');
        $company = trim($_POST['company_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $status = trim($_POST['status'] ?? 'active');
        $partyType = trim($_POST['party_type'] ?? 'customer');

        if ($name === '' || !in_array($status, ['active', 'inactive'], true)
            || !in_array($partyType, ['customer', 'vendor', 'both'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Client name, party type, and status are required.']);
            exit;
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Enter a valid client email address.']);
            break;
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO clients (name, company_name, party_type, email, phone, status, created_at)
             VALUES (:name, :company, :party_type, :email, :phone, :status, NOW())"
        );
        $stmt->execute([
            ':name' => $name,
            ':company' => $company,
            ':party_type' => $partyType,
            ':email' => $email,
            ':phone' => $phone,
            ':status' => $status,
        ]);

        $clientId = (int) $pdo->lastInsertId();
        logAudit(
            $pdo,
            $userId,
            'CREATE_CLIENT',
            'client',
            $clientId,
            json_encode([
                'name' => $name,
                'company_name' => $company,
                'party_type' => $partyType,
            ], JSON_THROW_ON_ERROR)
        );
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Client created successfully.']);
        break;

    case 'update-client':
        if (!in_array($userRole, ['admin', 'accountant', 'owner'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You do not have permission to manage clients.']);
            break;
        }

        $clientId = (int) ($_POST['client_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $company = trim($_POST['company_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $partyType = trim($_POST['party_type'] ?? '');

        if ($clientId <= 0 || $name === '' || !in_array($status, ['active', 'inactive'], true)
            || !in_array($partyType, ['customer', 'vendor', 'both'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'A valid client ID, name, party type, and status are required.']);
            break;
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Enter a valid client email address.']);
            break;
        }

        $pdo = getDbConnection();
        $checkStmt = $pdo->prepare('SELECT id FROM clients WHERE id = :id');
        $checkStmt->execute([':id' => $clientId]);
        if (!$checkStmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Client not found.']);
            break;
        }

        try {
            $pdo->beginTransaction();
            $activityStmt = $pdo->prepare(
                "SELECT
                    SUM(CASE WHEN LOWER(a.name) = 'accounts receivable'
                                  AND at.name = 'Asset' THEN 1 ELSE 0 END) AS has_ar,
                    SUM(CASE WHEN LOWER(a.name) = 'accounts payable'
                                  AND at.name = 'Liability' THEN 1 ELSE 0 END) AS has_ap
                 FROM transaction_journals tj
                 JOIN transactions t ON t.transaction_journal_id = tj.id
                 JOIN accounts a ON a.id = t.account_id
                 JOIN account_types at ON at.id = a.account_type_id
                 WHERE tj.client_id = :client_id AND tj.status != 'voided'"
            );
            $activityStmt->execute([':client_id' => $clientId]);
            $activity = $activityStmt->fetch(PDO::FETCH_ASSOC);
            if (((int) ($activity['has_ar'] ?? 0) > 0 && !in_array($partyType, ['customer', 'both'], true))
                || ((int) ($activity['has_ap'] ?? 0) > 0 && !in_array($partyType, ['vendor', 'both'], true))) {
                $pdo->rollBack();
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'error' => 'The party type cannot exclude customers or vendors with existing A/R or A/P activity.',
                ]);
                break;
            }
            $updateStmt = $pdo->prepare(
                'UPDATE clients
                 SET name = :name, company_name = :company, party_type = :party_type, email = :email,
                     phone = :phone, status = :status
                 WHERE id = :id'
            );
            $updateStmt->execute([
                ':name' => $name,
                ':company' => $company !== '' ? $company : null,
                ':party_type' => $partyType,
                ':email' => $email !== '' ? $email : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':status' => $status,
                ':id' => $clientId,
            ]);
            logAudit(
                $pdo,
                $userId,
                'EDIT_CLIENT',
                'client',
                $clientId,
                json_encode([
                    'name' => $name,
                    'company_name' => $company,
                    'party_type' => $partyType,
                    'email' => $email,
                    'phone' => $phone,
                    'status' => $status,
                ], JSON_THROW_ON_ERROR)
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Client update failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update client. Please try again.']);
            break;
        }

        echo json_encode(['success' => true, 'message' => 'Client updated successfully.']);
        break;
    
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}