<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit_helper.php';
require_once __DIR__ . '/../config/accounting_helper.php';
require_once __DIR__ . '/../config/accounting_periods.php';
require_once __DIR__ . '/../services/OCRService.php';

function getReceiptImageUrl(string $imagePath): string
{
    $projectBasePath = dirname(dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))));
    $projectBasePath = rtrim(str_replace('\\', '/', $projectBasePath), '/');
    $fileName = basename($imagePath);
    if (
        !str_starts_with($imagePath, 'uploads/receipts/')
        || !preg_match('/\\Areceipt_[A-Za-z0-9_-]+\\.(?:jpg|jpeg|png|webp|gif|bmp)\\z/i', $fileName)
    ) {
        return '';
    }

    return $projectBasePath . '/backend/routes/receipt-image.php?file=' . rawurlencode($fileName);
}

function saveClientLogoUpload(): ?string
{
    $upload = $_FILES['logo'] ?? null;
    if (!$upload || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) ($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new DomainException('The logo upload failed. Choose an image no larger than 2 MB.');
    }
    if ((int) ($upload['size'] ?? 0) <= 0 || (int) $upload['size'] > 2 * 1024 * 1024) {
        throw new DomainException('Client logos must be 2 MB or smaller.');
    }
    if (!is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        throw new DomainException('Choose a valid logo image to upload.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $image = @getimagesize($upload['tmp_name']);
    $imageMimes = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];
    if (!isset($extensions[$mime]) || !$image || ($imageMimes[$image[2]] ?? null) !== $mime) {
        throw new DomainException('Choose a valid JPG, PNG, or WebP image.');
    }

    $projectRoot = dirname(__DIR__, 2);
    $uploadDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'clients';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('The client logo folder could not be created.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($upload['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $fileName)) {
        throw new RuntimeException('The client logo could not be saved.');
    }

    return 'uploads/clients/' . $fileName;
}

function deleteClientLogoFile(?string $logoPath): void
{
    if (!$logoPath || !preg_match('~^uploads/clients/[a-f0-9]{32}\.(?:jpg|png|webp)$~i', $logoPath)) {
        return;
    }
    $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $logoPath);
    if (is_file($path)) {
        unlink($path);
    }
}

// Check if this is an incoming mobile upload or scan request
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$token  = $_POST['token'] ?? $_GET['token'] ?? '';

// Only scanner polling and upload use the bearer token instead of a login.
// A token parameter must never bypass authentication for other journal actions.
$publicScannerActions = ['check-scan-session', 'push-mobile-receipt'];
if (!in_array($action, $publicScannerActions, true)) {
    require_once __DIR__ . '/../config/auth_guard.php';
} elseif (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/i', $token)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'This scanner link is invalid. Reopen the scanner and try again.']);
    exit;
}
if (in_array($action, $publicScannerActions, true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
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
            "SELECT a.id, a.code, a.name, a.is_cash_account, a.classification,
                    at.name AS type_name, at.normal_balance
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE a.is_active = 1
             ORDER BY at.id, a.name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $types = $pdo->query("SELECT id, name FROM transaction_types ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

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
        $projectsStmt = $pdo->query(
            "SELECT id, project_name, client_id FROM projects
             WHERE status = 'active' ORDER BY project_name"
        );

        echo json_encode([
            'success'    => true,
            'accounts'   => $accounts,
            'types'      => $types,
            'cash_accounts' => $cashAccountsStmt->fetchAll(PDO::FETCH_ASSOC),
            'receivable_accounts' => $receivableAccountsStmt->fetchAll(PDO::FETCH_ASSOC),
            'payable_accounts' => $payableAccountsStmt->fetchAll(PDO::FETCH_ASSOC),
            'clients' => $clientsStmt->fetchAll(PDO::FETCH_ASSOC),
            'projects' => $projectsStmt->fetchAll(PDO::FETCH_ASSOC),
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

    case 'due-notifications':
        $pdo = getDbConnection();
        $today = new DateTimeImmutable('today');
        $upcomingThrough = $today->modify('+7 days')->format('Y-m-d');
        $notificationItems = [];
        $accountSets = [
            ['type' => 'ar', 'name' => 'Accounts Receivable', 'increase_side' => 'debit', 'account_type' => 'Asset'],
            ['type' => 'ap', 'name' => 'Accounts Payable', 'increase_side' => 'credit', 'account_type' => 'Liability'],
        ];
        $parties = $pdo->query('SELECT id, name, company_name FROM clients')
            ->fetchAll(PDO::FETCH_ASSOC);
        $partiesById = [];
        foreach ($parties as $party) {
            $partiesById[(int) $party['id']] = $party;
        }

        $accountsStmt = $pdo->prepare(
            'SELECT a.id FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE LOWER(a.name) = LOWER(:name) AND at.name = :type'
        );
        foreach ($accountSets as $accountSet) {
            $accountsStmt->execute([
                ':name' => $accountSet['name'],
                ':type' => $accountSet['account_type'],
            ]);
            foreach ($accountsStmt->fetchAll(PDO::FETCH_COLUMN) as $accountId) {
                foreach (getPartyOpenItems(
                    $pdo,
                    (int) $accountId,
                    $accountSet['increase_side']
                ) as $item) {
                    $dueDate = $item['due_date'];
                    $partyId = (int) $item['client_id'];
                    if ($dueDate === null || $dueDate > $upcomingThrough
                        || !isset($partiesById[$partyId])) {
                        continue;
                    }

                    $party = $partiesById[$partyId];
                    $notificationItems[] = [
                        'type' => $accountSet['type'],
                        'journal_id' => (int) $item['journal_id'],
                        'client_id' => $partyId,
                        'party_name' => (string) $party['name'],
                        'company_name' => $party['company_name'],
                        'description' => $item['description'],
                        'reference_no' => $item['reference_no'],
                        'due_date' => $dueDate,
                        'remaining' => round((float) $item['remaining'], 2),
                        'is_overdue' => $dueDate < $today->format('Y-m-d'),
                    ];
                }
            }
        }

        usort($notificationItems, static function (array $left, array $right): int {
            return [
                $left['is_overdue'] ? 0 : 1,
                $left['due_date'],
                $left['type'] === 'ar' ? 0 : 1,
                $left['party_name'],
            ] <=> [
                $right['is_overdue'] ? 0 : 1,
                $right['due_date'],
                $right['type'] === 'ar' ? 0 : 1,
                $right['party_name'],
            ];
        });

        echo json_encode([
            'success' => true,
            'upcoming_days' => 7,
            'items' => $notificationItems,
        ]);
        break;

    // 3. CREATE NEW JOURNAL ENTRY
     case 'create':
        if (ob_get_length()) ob_clean();

        if (!in_array($userRole, ['admin', 'accountant', 'owner'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Your role is not allowed to create journal entries.']);
            exit;
        }

        try {
            $referenceLockName = null;
            $transactionTypeId = (int) ($_POST['transaction_type_id'] ?? 0);
            $description       = trim($_POST['description'] ?? '');
            $transactionDate   = $_POST['transaction_date'] ?? '';
            $referenceNo       = trim($_POST['reference_no'] ?? '');
            $dueDate           = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $documentType      = trim($_POST['document_type'] ?? '');
            $projectName       = trim($_POST['project_name'] ?? '');

            $clientId = !empty($_POST['client_id']) && (int)$_POST['client_id'] > 0 ? (int)$_POST['client_id'] : null;
            $projectClientId = !empty($_POST['project_client_id']) && (int) $_POST['project_client_id'] > 0
                ? (int) $_POST['project_client_id']
                : $clientId;

            if ($transactionTypeId <= 0 || $transactionDate === '') {
                throw new DomainException('Transaction type and date are required.');
            }

            $pdo = getDbConnection();
            $transactionTypeStmt = $pdo->prepare('SELECT name FROM transaction_types WHERE id = :id');
            $transactionTypeStmt->execute([':id' => $transactionTypeId]);
            $transactionTypeName = $transactionTypeStmt->fetchColumn();
            if ($transactionTypeName === false) {
                throw new DomainException('Select a valid transaction type.');
            }
            $isOpeningBalance = $transactionTypeName === 'Opening Balance';

            $openingBalanceAccountId = 0;
            $openingBalanceEquityId = 0;
            $openingBalanceAmount = 0.0;
            $openingBalanceDirection = 'normal';
            if ($isOpeningBalance) {
                $openingBalanceAccountId = (int) ($_POST['opening_balance_account_id'] ?? 0);
                $openingAmountInput = trim((string) ($_POST['opening_balance_amount'] ?? ''));
                $openingBalanceDirection = strtolower(trim((string) ($_POST['opening_balance_direction'] ?? 'normal')));

                if ($openingBalanceAccountId <= 0) {
                    throw new DomainException('Choose an account for the opening balance.');
                }
                if (!preg_match('/\A(?:\d+(?:\.\d{1,2})?|\.\d{1,2})\z/', $openingAmountInput)) {
                    throw new DomainException('Enter a positive opening balance with no more than two decimal places.');
                }
                $openingBalanceAmount = (float) $openingAmountInput;
                if (!is_finite($openingBalanceAmount) || $openingBalanceAmount <= 0
                    || $openingBalanceAmount > 999999999999.99
                    || round($openingBalanceAmount, 2) !== $openingBalanceAmount) {
                    throw new DomainException('Enter a positive opening balance with no more than two decimal places.');
                }
                if (!in_array($openingBalanceDirection, ['normal', 'debit', 'credit'], true)) {
                    throw new DomainException('Choose a valid opening balance direction.');
                }

                $openingAccountStmt = $pdo->prepare(
                    "SELECT a.id, a.name, a.is_active, at.name AS type_name, at.normal_balance
                     FROM accounts a
                     JOIN account_types at ON at.id = a.account_type_id
                     WHERE a.id = :id"
                );
                $openingAccountStmt->execute([':id' => $openingBalanceAccountId]);
                $openingAccount = $openingAccountStmt->fetch(PDO::FETCH_ASSOC);
                if (!$openingAccount || (int) $openingAccount['is_active'] !== 1) {
                    throw new DomainException('Choose an existing active Chart of Accounts account.');
                }
                if (!in_array($openingAccount['type_name'], ['Asset', 'Liability', 'Equity'], true)) {
                    throw new DomainException('Opening balances can only be recorded for Asset, Liability, or Equity accounts. Revenue and Expense accounts are period activity accounts.');
                }

                $openingEquityStmt = $pdo->query(
                    "SELECT a.id, a.name, a.is_active, at.name AS type_name
                     FROM accounts a
                     JOIN account_types at ON at.id = a.account_type_id
                     WHERE LOWER(TRIM(a.name)) = 'opening balance equity'
                     ORDER BY a.id"
                );
                $openingEquityAccounts = $openingEquityStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$openingEquityAccounts) {
                    $equityTypeId = $pdo->query("SELECT id FROM account_types WHERE name = 'Equity'")->fetchColumn();
                    if ($equityTypeId !== false) {
                        $createEquityStmt = $pdo->prepare(
                            "INSERT INTO accounts (account_type_id, name, opening_balance, current_balance, is_cash_account)
                             VALUES (:type_id, 'Opening Balance Equity', 0.00, 0.00, 0)"
                        );
                        $createEquityStmt->execute([':type_id' => (int) $equityTypeId]);
                    }
                    $openingEquityStmt = $pdo->query(
                        "SELECT a.id, a.name, a.is_active, at.name AS type_name
                         FROM accounts a
                         JOIN account_types at ON at.id = a.account_type_id
                         WHERE LOWER(TRIM(a.name)) = 'opening balance equity'
                         ORDER BY a.id"
                    );
                    $openingEquityAccounts = $openingEquityStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                if (count($openingEquityAccounts) !== 1
                    || (int) $openingEquityAccounts[0]['is_active'] !== 1
                    || $openingEquityAccounts[0]['type_name'] !== 'Equity') {
                    throw new DomainException(
                        'Opening Balance Equity must be exactly one active Equity account in the Chart of Accounts.'
                    );
                }
                $openingBalanceEquityId = (int) $openingEquityAccounts[0]['id'];
                if ($openingBalanceAccountId === $openingBalanceEquityId
                    || strcasecmp(trim((string) $openingAccount['name']), 'Opening Balance Equity') === 0) {
                    throw new DomainException('Opening Balance Equity cannot be the account receiving its own opening balance.');
                }

                $lineDirection = $openingBalanceDirection === 'normal'
                    ? (string) $openingAccount['normal_balance']
                    : $openingBalanceDirection;
                if (!in_array($lineDirection, ['debit', 'credit'], true)) {
                    throw new DomainException('The selected account has no valid normal balance direction.');
                }

                $debitLine = ['account_id' => $openingBalanceAccountId, 'amount' => $openingBalanceAmount];
                $creditLine = ['account_id' => $openingBalanceEquityId, 'amount' => $openingBalanceAmount];
                $debitLines = $lineDirection === 'debit' ? [$debitLine] : [$creditLine];
                $creditLines = $lineDirection === 'debit' ? [$creditLine] : [$debitLine];

                if ($description === '') {
                    $description = 'Opening balance - ' . $openingAccount['name'];
                }
                if (strlen($description) > 255) {
                    throw new DomainException('Opening balance memo must be 255 characters or fewer.');
                }

                // Opening balances have no project, customer/vendor, due date, category, or bill document.
                $projectName = '';
                $clientId = null;
                $projectClientId = null;
                $dueDate = null;
                $documentType = '';
            } else {
                $debitLines = json_decode($_POST['debit_lines'] ?? '[]', true);
                $creditLines = json_decode($_POST['credit_lines'] ?? '[]', true);
                if ($description === '') {
                    throw new DomainException('A description is required.');
                }
            }

            if (strlen($projectName) > 255) {
                throw new DomainException('Project names must be 255 characters or fewer.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $transactionDate);
            if (!$date || $date->format('Y-m-d') !== $transactionDate) {
                throw new DomainException('Enter a valid transaction date.');
            }
            if ($dueDate !== null) {
                $parsedDueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
                if (!$parsedDueDate || $parsedDueDate->format('Y-m-d') !== $dueDate) {
                    throw new DomainException('Enter a valid due date.');
                }
            }

            if (!is_array($debitLines) || !is_array($creditLines)
                || (!$isOpeningBalance && (empty($debitLines) || empty($creditLines)))) {
                throw new DomainException('At least one debit line and one credit line are required.');
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

            if ($transactionTypeName === 'Cash Inflow'
                && (round($cashDebitTotal, 2) !== round($totalDebits, 2) || $cashCreditTotal > 0)) {
                throw new DomainException('Cash inflows must debit only active cash, bank, or wallet accounts.');
            }
            if ($transactionTypeName === 'Cash Outflow'
                && (round($cashCreditTotal, 2) !== round($totalCredits, 2) || $cashDebitTotal > 0)) {
                throw new DomainException('Cash outflows must credit only active cash, bank, or wallet accounts.');
            }

            $hasReceivableEntries = !$isOpeningBalance && ($arIncreaseByAccount || $arDecreaseByAccount);
            $hasPayableEntries = !$isOpeningBalance && ($apIncreaseByAccount || $apDecreaseByAccount);
            if (($hasReceivableEntries || $hasPayableEntries) && $clientId === null) {
                throw new DomainException('Select the customer or vendor for every A/R or A/P entry.');
            }
            if (!$isOpeningBalance && ($arIncreaseByAccount || $apIncreaseByAccount) && $dueDate === null) {
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
            assertAccountingDateOpen($pdo, $transactionDate, true);

            if ($isOpeningBalance) {
                $openingLockStmt = $pdo->prepare(
                    "SELECT a.id, a.name, a.is_active, at.name AS type_name, at.normal_balance
                     FROM accounts a
                     JOIN account_types at ON at.id = a.account_type_id
                     WHERE a.id IN (?, ?)
                     FOR UPDATE"
                );
                $openingLockStmt->execute([$openingBalanceAccountId, $openingBalanceEquityId]);
                $lockedOpeningAccounts = [];
                foreach ($openingLockStmt->fetchAll(PDO::FETCH_ASSOC) as $lockedAccount) {
                    $lockedOpeningAccounts[(int) $lockedAccount['id']] = $lockedAccount;
                }
                $lockedAccount = $lockedOpeningAccounts[$openingBalanceAccountId] ?? null;
                $lockedEquity = $lockedOpeningAccounts[$openingBalanceEquityId] ?? null;
                if (!$lockedAccount || !$lockedEquity
                    || (int) $lockedAccount['is_active'] !== 1
                    || !in_array($lockedAccount['type_name'], ['Asset', 'Liability', 'Equity'], true)
                    || (int) $lockedEquity['is_active'] !== 1
                    || $lockedEquity['type_name'] !== 'Equity'
                    || strcasecmp(trim((string) $lockedEquity['name']), 'Opening Balance Equity') !== 0) {
                    throw new DomainException('The selected opening balance accounts changed or became inactive. Refresh the page and try again.');
                }
                if ($lockedAccount['normal_balance'] !== $accountTypesMap[$openingBalanceAccountId]['normal_balance']) {
                    throw new DomainException('The selected account changed while saving. Refresh the page and try again.');
                }
            }

            $projectId = null;
            if ($projectName !== '') {
                if ($projectClientId === null) {
                    throw new DomainException('Select a customer before assigning a project.');
                }

                $projectClientStmt = $pdo->prepare(
                    "SELECT id, party_type, status FROM clients WHERE id = :id FOR UPDATE"
                );
                $projectClientStmt->execute([':id' => $projectClientId]);
                $projectClient = $projectClientStmt->fetch(PDO::FETCH_ASSOC);
                if (!$projectClient
                    || !in_array($projectClient['party_type'], ['customer', 'both'], true)
                    || $projectClient['status'] !== 'active') {
                    throw new DomainException('Select an active customer for the project.');
                }

                $projectUpsert = $pdo->prepare(
                    "INSERT INTO projects (project_name, client_id)
                     VALUES (:project_name, :client_id)
                     ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
                );
                $projectUpsert->execute([
                    ':project_name' => $projectName,
                    ':client_id' => $projectClientId,
                ]);
                $projectId = (int) $pdo->lastInsertId();

                $projectStmt = $pdo->prepare(
                    'SELECT client_id, status FROM projects WHERE id = :id FOR UPDATE'
                );
                $projectStmt->execute([':id' => $projectId]);
                $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
                if (!$project || (int) $project['client_id'] !== $projectClientId) {
                    throw new DomainException('That project name is already assigned to another client.');
                }
                if ($project['status'] !== 'active') {
                    throw new DomainException('That project is archived and cannot receive new transactions.');
                }
            }

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
            $referenceContext = $isOpeningBalance
                ? 'opening_balance'
                : ($documentType === 'vendor_bill'
                    ? 'vendor_bill'
                    : (($arDecreaseByAccount || $apDecreaseByAccount) ? 'payment' : $transactionTypeName));
            $referenceNo = generateEntryReference($pdo, $referenceContext, $transactionDate, $referenceLockName);

            $journalStmt = $pdo->prepare(
                "INSERT INTO transaction_journals 
                    (transaction_type_id, description, transaction_date, reference_no, due_date, total_amount, created_by, client_id, project_id, status)
                VALUES (:type_id, :description, :date, :reference, :due_date, :total, :created_by, :client_id, :project_id, 'posted')"
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
                ':project_id' => $projectId,
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
                    'project_id' => $projectId,
                    'reference_no' => $referenceNo,
                    'document_type' => $documentType !== '' ? $documentType : null,
                    'settlement_allocations' => $allocationPlans,
                    'opening_balance_account_id' => $isOpeningBalance ? $openingBalanceAccountId : null,
                    'opening_balance_equity_account_id' => $isOpeningBalance ? $openingBalanceEquityId : null,
                    'opening_balance_direction' => $isOpeningBalance ? $lineDirection : null,
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
            releaseEntryReferenceLock($pdo, $referenceLockName);
            echo json_encode([
                'success' => true,
                'message' => 'Journal entry recorded successfully.',
                'journal_id' => $journalId,
                'reference_no' => $referenceNo,
            ]);
            exit;

        } catch (DomainException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (isset($pdo)) {
                releaseEntryReferenceLock($pdo, $referenceLockName ?? null);
            }
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (isset($pdo)) {
                releaseEntryReferenceLock($pdo, $referenceLockName ?? null);
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

        if ($journalId === 0 || $description === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Journal ID and description are required.']);
            break;
        }

        $pdo = getDbConnection();
        $referenceLockName = null;

        try {
            $pdo->beginTransaction();
            $checkStmt = $pdo->prepare(
                'SELECT tj.id, tj.status, tj.transaction_date, tj.reference_no, tt.name AS transaction_type_name
                 FROM transaction_journals tj
                 JOIN transaction_types tt ON tt.id = tj.transaction_type_id
                 WHERE tj.id = :id FOR UPDATE'
            );
            $checkStmt->execute([':id' => $journalId]);
            $journal = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$journal) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Journal entry not found.']);
                break;
            }
            if ($journal['status'] === 'deletion_pending') {
                $pdo->rollBack();
                http_response_code(422);
                echo json_encode(['error' => 'Cannot edit an entry while a deletion request is pending review.']);
                break;
            }
            assertAccountingDateOpen($pdo, (string) $journal['transaction_date'], true);
            if ($referenceNo === '') {
                $referenceNo = trim((string) ($journal['reference_no'] ?? ''));
                if ($referenceNo === '') {
                    $referenceContext = $journal['transaction_type_name'];
                    if ($referenceContext === 'Opening Balance') {
                        $referenceContext = 'opening_balance';
                    }
                    $referenceNo = generateEntryReference(
                        $pdo,
                        (string) $referenceContext,
                        (string) $journal['transaction_date'],
                        $referenceLockName
                    );
                }
            }

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

            logAudit(
                $pdo,
                $userId,
                'EDIT_JOURNAL_ENTRY',
                'journal',
                $journalId,
                json_encode([
                    'description' => $description,
                    'reference_no' => $referenceNo,
                ], JSON_THROW_ON_ERROR)
            );

            $pdo->commit();
            releaseEntryReferenceLock($pdo, $referenceLockName);

            echo json_encode(['success' => true, 'message' => 'Journal entry updated successfully.', 'reference_no' => $referenceNo]);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            releaseEntryReferenceLock($pdo, $referenceLockName);
            if ($e instanceof DomainException) {
                http_response_code(422);
                echo json_encode(['error' => $e->getMessage()]);
                break;
            }
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
                jc.category_id AS category_id,
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
            assertAccountingDateOpen($pdo, (string) $journal['transaction_date'], true);

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
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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

            $stmt = $pdo->prepare(
                "SELECT dr.*, tj.transaction_date
                 FROM deletion_requests dr
                 JOIN transaction_journals tj ON tj.id = dr.transaction_journal_id
                 WHERE dr.id = :id AND dr.status = 'pending'
                 FOR UPDATE"
            );
            $stmt->execute([':id' => $requestId]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                $pdo->rollBack();
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Request not found or already processed.']);
                exit;
            }

            $journalId = (int)$req['transaction_journal_id'];
            assertAccountingDateOpen($pdo, (string) $req['transaction_date'], true);
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

            $stmt = $pdo->prepare(
                "SELECT dr.transaction_journal_id, tj.transaction_date
                 FROM deletion_requests dr
                 JOIN transaction_journals tj ON tj.id = dr.transaction_journal_id
                 WHERE dr.id = :id AND dr.status = 'pending'
                 FOR UPDATE"
            );
            $stmt->execute([':id' => $requestId]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                $pdo->rollBack();
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Request not found or already processed.']);
                exit;
            }

            assertAccountingDateOpen($pdo, (string) $req['transaction_date'], true);
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
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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

            $stmt = $pdo->prepare(
                'SELECT id, status, transaction_date FROM transaction_journals WHERE id = :id FOR UPDATE'
            );
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

            assertAccountingDateOpen($pdo, (string) $journal['transaction_date'], true);
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
        $limit = min(max((int) ($_GET['limit'] ?? 8), 1), 100);
        $offset = max((int) ($_GET['offset'] ?? 0), 0);
        $search = trim((string) ($_GET['search'] ?? ''));
        $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
        $dateTo = trim((string) ($_GET['date_to'] ?? ''));
        $typeId = (int) ($_GET['transaction_type_id'] ?? 0);
        $pdo = getDbConnection();

        $joins = " FROM transaction_journals tj
             JOIN transaction_types tt ON tt.id = tj.transaction_type_id
             LEFT JOIN (
                 SELECT jc.transaction_journal_id,
                        GROUP_CONCAT(DISTINCT cat.name ORDER BY cat.name SEPARATOR ', ') AS category_name
                 FROM journal_category jc
                 JOIN categories cat ON cat.id = jc.category_id
                 GROUP BY jc.transaction_journal_id
             ) c ON c.transaction_journal_id = tj.id
             LEFT JOIN clients client ON client.id = tj.client_id";
        $where = ["tj.status != 'voided'"];
        $params = [];

        if ($search !== '') {
            $where[] = '(tj.description LIKE :search_description
                OR tj.reference_no LIKE :search_reference
                OR tt.name LIKE :search_type
                OR client.name LIKE :search_client
                OR client.company_name LIKE :search_company
                OR c.category_name LIKE :search_category)';
            $searchTerm = '%' . $search . '%';
            $params[':search_description'] = $searchTerm;
            $params[':search_reference'] = $searchTerm;
            $params[':search_type'] = $searchTerm;
            $params[':search_client'] = $searchTerm;
            $params[':search_company'] = $searchTerm;
            $params[':search_category'] = $searchTerm;
        }
        if ($dateFrom !== '') {
            $where[] = 'tj.transaction_date >= :date_from';
            $params[':date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 'tj.transaction_date <= :date_to';
            $params[':date_to'] = $dateTo;
        }
        if ($typeId > 0) {
            $where[] = 'tj.transaction_type_id = :type_id';
            $params[':type_id'] = $typeId;
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $totalStmt = $pdo->prepare('SELECT COUNT(DISTINCT tj.id)' . $joins . $whereSql);
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT tj.id, tj.description, tj.transaction_date, tj.reference_no, tj.total_amount,
                    tt.name AS type_name, c.category_name,
                    client.name AS client_name
            " . $joins . $whereSql . "
             ORDER BY tj.transaction_date DESC, tj.id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'transactions' => $recent, 'total' => $total]);
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
            $labels[]  = date('m/d/Y', strtotime($date));
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
        $statusFilter = trim($_GET['status'] ?? 'active');

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
                WHERE 1 = 1";

        $params = [];

        if ($statusFilter === 'active') {
            $sql .= " AND tj.status != 'voided'";
        } elseif ($statusFilter === 'voided') {
            $sql .= " AND tj.status = 'voided'";
        }

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

    case 'receivables-payables-items':
        $settlementType = $_GET['type'] ?? '';
        if (!in_array($settlementType, ['ar', 'ap'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Select receivables or payables to view.']);
            break;
        }

        $pdo = getDbConnection();
        $accountName = $settlementType === 'ar' ? 'Accounts Receivable' : 'Accounts Payable';
        $accountType = $settlementType === 'ar' ? 'Asset' : 'Liability';
        $increaseEntryType = $settlementType === 'ar' ? 'debit' : 'credit';
        $accountsStmt = $pdo->prepare(
            'SELECT a.id FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             WHERE LOWER(a.name) = LOWER(:name) AND at.name = :type'
        );
        $accountsStmt->execute([':name' => $accountName, ':type' => $accountType]);

        $items = [];
        foreach ($accountsStmt->fetchAll(PDO::FETCH_COLUMN) as $accountId) {
            $items = array_merge(
                $items,
                getPartyOpenItems($pdo, (int) $accountId, $increaseEntryType)
            );
        }

        $partyIds = array_values(array_unique(array_filter(
            array_map(static fn(array $item): int => (int) ($item['client_id'] ?? 0), $items)
        )));
        $partiesById = [];
        if ($partyIds) {
            $placeholders = implode(',', array_fill(0, count($partyIds), '?'));
            $partiesStmt = $pdo->prepare(
                "SELECT id, name, company_name FROM clients WHERE id IN ({$placeholders})"
            );
            $partiesStmt->execute($partyIds);
            foreach ($partiesStmt->fetchAll(PDO::FETCH_ASSOC) as $party) {
                $partiesById[(int) $party['id']] = $party;
            }
        }

        foreach ($items as &$item) {
            $party = $partiesById[(int) ($item['client_id'] ?? 0)] ?? null;
            $item['party_name'] = $party['name'] ?? 'Unassigned party';
            $item['company_name'] = $party['company_name'] ?? null;
        }
        unset($item);

        $today = date('Y-m-d');
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

        echo json_encode([
            'success' => true,
            'type' => $settlementType,
            'total' => round(array_sum(array_column($items, 'remaining')), 2),
            'items' => $items,
            'as_of' => $today,
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

        if (($_GET['summary_only'] ?? '') === '1') {
            echo json_encode([
                'success' => true,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'total_inflow' => $totalInflow,
                'total_outflow' => $totalOutflow,
                'net_cash_flow' => $totalInflow - $totalOutflow,
            ]);
            break;
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
            $labels[]  = $date->format('m/d/Y');
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
        $stmt = $pdo->query("SELECT id, name, company_name, party_type, status FROM clients WHERE status = 'active' ORDER BY name ASC");
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'clients' => $clients]);
        break;

    //20. SCANNER RECEIPT DATA SESSIONS
     case 'init-scan-session':
    $token = bin2hex(random_bytes(32));
    $pdo = getDbConnection();
    $appUrl = rtrim((string) env('APP_URL', ''), '/');
    $appUrlParts = parse_url($appUrl);
    if (
        $appUrl === ''
        || !is_array($appUrlParts)
        || !in_array($appUrlParts['scheme'] ?? '', ['http', 'https'], true)
        || empty($appUrlParts['host'])
    ) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'Receipt scanning is not configured. Set APP_URL to the application URL.']);
        break;
    }

    $mobileUrl = $appUrl . '/backend/mobile-scan.php?token=' . rawurlencode($token);

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
    $token = $_POST['token'] ?? $_GET['token'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM pending_receipt_scans WHERE token = :token AND status = 'completed' AND created_at >= NOW() - INTERVAL 1 HOUR");
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
            'image_url' => getReceiptImageUrl((string) $row['image_path']),
            'extracted' => [
                'vendor' => $extracted['vendor'] ?? 'Scanned Vendor',
                'date' => $extracted['date'] ?? date('Y-m-d'),
                'reference_no' => $extracted['reference_no'] ?? '',
                'amount' => $extracted['amount'] ?? '0.00'
            ]
        ]);
    } else {
        echo json_encode(['success' => true, 'status' => 'pending']);
    }
    break;

           case 'push-mobile-receipt':
    $token = $_POST['token'] ?? '';
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        echo json_encode([
            'success' => false,
            'error' => 'The photo is larger than the server upload limit. Use a lower-resolution photo or reduce its size.',
        ]);
        exit;
    }

    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/i', $token)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'This scanner link is invalid. Reopen the scanner from your computer and scan its new QR code.']);
        exit;
    }

    if (!isset($_FILES['receipt_image'])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'No photo was received. Please take or choose a photo and try again.']);
        exit;
    }

    $uploadError = (int) ($_FILES['receipt_image']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'The photo is larger than the server upload limit. Use a lower-resolution photo or reduce its size.',
            UPLOAD_ERR_FORM_SIZE => 'The photo is larger than the allowed upload size.',
            UPLOAD_ERR_PARTIAL => 'The photo upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No photo was received. Please take or choose a photo and try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server is not configured to receive uploads. Contact the administrator.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded photo. Contact the administrator.',
            UPLOAD_ERR_EXTENSION => 'A server extension stopped the photo upload. Contact the administrator.',
        ];
        http_response_code(in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 422);
        echo json_encode([
            'success' => false,
            'error' => $uploadErrors[$uploadError] ?? 'The photo upload failed. Please try again.',
        ]);
        exit;
    }

    $pdo = getDbConnection();
    $scanStmt = $pdo->prepare("SELECT id FROM pending_receipt_scans WHERE token = :token AND status = 'pending' AND created_at >= NOW() - INTERVAL 1 HOUR LIMIT 1");
    $scanStmt->execute([':token' => $token]);
    if (!$scanStmt->fetchColumn()) {
        http_response_code(410);
        echo json_encode([
            'success' => false,
            'error' => 'This scanner link has expired or was already used. Reopen the scanner from your computer and scan its new QR code.',
        ]);
        exit;
    }

    $uploadDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'receipts';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Could not prepare receipt storage.']);
            exit;
        }
    }

    $fileSize = (int) ($_FILES['receipt_image']['size'] ?? 0);
    if ($fileSize <= 0 || $fileSize > 10 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'Receipt photos must be 10 MB or smaller.']);
        exit;
    }

    $temporaryPath = (string) ($_FILES['receipt_image']['tmp_name'] ?? '');
    $info = is_uploaded_file($temporaryPath) ? @getimagesize($temporaryPath) : false;
    $allowedImageTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
    ];
    if (
        $info === false
        || !isset($allowedImageTypes[$info['mime'] ?? ''])
        || (int) ($info[0] ?? 0) <= 0
        || (int) ($info[1] ?? 0) <= 0
        || (int) $info[0] * (int) $info[1] > 40000000
    ) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'The uploaded file is not a readable image. Please take the receipt photo again.']);
        exit;
    }

    // Pick an extension from the decoded image type, never from the client filename.
    $fileName = 'receipt_' . bin2hex(random_bytes(16)) . '.' . $allowedImageTypes[$info['mime']];
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
    $imagePath = 'uploads/receipts/' . $fileName;

    if (move_uploaded_file($temporaryPath, $targetPath)) {
        // Preprocess image for blurry/faded receipts using GD library

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
            'reference_no' => '',
            'amount' => '0.00'
        ];

        $absoluteTargetPath = realpath($targetPath);
        $ocrResult = $absoluteTargetPath === false
            ? ['success' => false, 'parsed_text' => '', 'error' => 'The uploaded receipt file could not be read.']
            : extractTextFromOCRSpace($absoluteTargetPath);

        if (!$ocrResult['success']) {
            if (!unlink($targetPath)) {
                error_log('Failed to remove receipt upload after OCR failure: ' . $targetPath);
            }
            http_response_code(502);
            echo json_encode([
                'success' => false,
                'error' => $ocrResult['error'],
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        $rawText = $ocrResult['parsed_text'];
        if ($rawText !== '') {
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

        $stmt = $pdo->prepare("UPDATE pending_receipt_scans SET status = 'completed', image_path = :path, extracted_data = :extracted WHERE token = :token");
        $stmt->execute([
            ':path' => $imagePath,
            ':extracted' => json_encode($extracted),
            ':token' => $token
        ]);

        if ($stmt->rowCount() !== 1) {
            if (!unlink($targetPath)) {
                error_log('Failed to remove receipt upload for expired mobile scan: ' . $targetPath);
            }
            http_response_code(410);
            echo json_encode([
                'success' => false,
                'error' => 'This scanner link has expired. Reopen the scanner from your computer and scan its new QR code.',
            ]);
            exit;
        }

        echo json_encode(['success' => true]);
    } else {
        error_log(sprintf('Failed to move mobile receipt upload (PHP upload error %d).', $uploadError));
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'The server could not save the photo. Please try again or contact the administrator.']);
    }
    break;
    
    case 'cleanup-scans':
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
        exit;
    }
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
        $stmt = $pdo->prepare(
            'SELECT id, name, company_name, party_type, email, phone, logo, status, created_at
             FROM clients ORDER BY created_at DESC'
        );
        $stmt->execute();
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
            "SELECT id, name, company_name, party_type, email, phone, logo, status
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

        try {
            $logoPath = saveClientLogoUpload();
        } catch (DomainException $exception) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
            break;
        } catch (Throwable $exception) {
            error_log('Client logo upload failed: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'The client logo could not be saved.']);
            break;
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO clients (name, company_name, party_type, email, phone, logo, status, created_at)
                 VALUES (:name, :company, :party_type, :email, :phone, :logo, :status, NOW())"
            );
            $stmt->execute([
                ':name' => $name,
                ':company' => $company !== '' ? $company : null,
                ':party_type' => $partyType,
                ':email' => $email !== '' ? $email : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':logo' => $logoPath,
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
                    'has_logo' => $logoPath !== null,
                ], JSON_THROW_ON_ERROR)
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            deleteClientLogoFile($logoPath);
            error_log('Client creation failed: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to create client. Please try again.']);
            break;
        }

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
        $checkStmt = $pdo->prepare('SELECT id, logo FROM clients WHERE id = :id');
        $checkStmt->execute([':id' => $clientId]);
        $existingClient = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingClient) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Client not found.']);
            break;
        }

        $logoPath = null;
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
            try {
                $logoPath = saveClientLogoUpload();
            } catch (DomainException $exception) {
                $pdo->rollBack();
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
                break;
            }
            $updateStmt = $pdo->prepare(
                'UPDATE clients
                 SET name = :name, company_name = :company, party_type = :party_type, email = :email,
                     phone = :phone, logo = COALESCE(:logo, logo), status = :status
                 WHERE id = :id'
            );
            $updateStmt->execute([
                ':name' => $name,
                ':company' => $company !== '' ? $company : null,
                ':party_type' => $partyType,
                ':email' => $email !== '' ? $email : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':logo' => $logoPath,
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
                    'has_logo' => $logoPath !== null || $existingClient['logo'] !== null,
                ], JSON_THROW_ON_ERROR)
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            deleteClientLogoFile($logoPath);
            error_log('Client update failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update client. Please try again.']);
            break;
        }

        if ($logoPath !== null) {
            deleteClientLogoFile($existingClient['logo'] ?? null);
        }

        echo json_encode(['success' => true, 'message' => 'Client updated successfully.']);
        break;
    
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
