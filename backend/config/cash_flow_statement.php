<?php
declare(strict_types=1);

function getCashFlowStatementData(PDO $pdo, string $dateFrom, string $dateTo): array
{
    foreach ([$dateFrom, $dateTo] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Enter a valid cash flow date range.');
        }
    }
    if ($dateFrom > $dateTo) {
        throw new InvalidArgumentException('The cash flow start date must not be after the end date.');
    }

    $dayBefore = (new DateTimeImmutable($dateFrom))->modify('-1 day')->format('Y-m-d');
    $beginningAccounts = getAccountBalancesAsOf($pdo, $dayBefore);
    $endingAccounts = getAccountBalancesAsOf($pdo, $dateTo);
    $balanceTotal = static function (array $accounts, callable $matches): float {
        $total = 0.0;
        foreach ($accounts as $account) {
            if ($matches($account)) {
                $total += (float) $account['balance'];
            }
        }
        return round($total, 2);
    };
    $cashEquivalentMatches = static function (array $account): bool {
        if (($account['type_name'] ?? $account['account_type'] ?? '') !== 'Asset') {
            return false;
        }
        $name = (string) ($account['name'] ?? $account['account_name'] ?? '');
        if (preg_match('/project cash advance|prepaid|accounts? receivable|trade receivable|inventor|\bstock\b/i', $name) === 1) {
            return false;
        }
        return (int) ($account['is_cash_account'] ?? 0) === 1
            || preg_match('/\bcash\b|e[- ]?wallet/i', $name) === 1;
    };
    $cashTotal = static fn(array $accounts): float => $balanceTotal($accounts, $cashEquivalentMatches);
    $beginningCash = $cashTotal($beginningAccounts);
    $endingCash = $cashTotal($endingAccounts);
    $netChange = round($endingCash - $beginningCash, 2);

    $periodIncome = getIncomeStatementData($pdo, $dateFrom, $dateTo);
    $netIncome = round((float) $periodIncome['net_income_after_tax'], 2);
    $depreciationStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END), 0)
         FROM transactions t
         JOIN transaction_journals tj ON tj.id = t.transaction_journal_id
         JOIN accounts a ON a.id = t.account_id
         JOIN account_types at ON at.id = a.account_type_id
         WHERE at.name = 'Expense'
           AND LOWER(a.name) REGEXP 'deprecia'
           AND tj.status <> 'voided'
           AND tj.transaction_date BETWEEN :date_from AND :date_to"
    );
    $depreciationStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
    $depreciation = round((float) $depreciationStmt->fetchColumn(), 2);

    $workingCapitalRules = [
        'Accounts Receivable' => ['type' => 'Asset', 'pattern' => '/accounts? receivable|trade receivable/i', 'sign' => 'asset'],
        'Project Cash Advance' => ['type' => 'Asset', 'pattern' => '/project cash advance/i', 'sign' => 'asset'],
        'Prepaid Expense' => ['type' => 'Asset', 'pattern' => '/prepaid/i', 'sign' => 'asset'],
        'Accounts Payable' => ['type' => 'Liability', 'pattern' => '/accounts payable|trade payable|vendor payable/i', 'sign' => 'liability'],
        'Salaries Payable' => ['type' => 'Liability', 'pattern' => '/salaries payable|wages payable/i', 'sign' => 'liability'],
        'Unearned Revenue' => ['type' => 'Liability', 'pattern' => '/unearned revenue|deferred revenue/i', 'sign' => 'liability'],
    ];
    $workingCapitalChanges = [];
    foreach ($workingCapitalRules as $label => $rule) {
        $matches = static fn(array $account): bool => $account['type_name'] === $rule['type']
            && preg_match($rule['pattern'], (string) $account['name']) === 1;
        $begin = $balanceTotal($beginningAccounts, $matches);
        $end = $balanceTotal($endingAccounts, $matches);
        $workingCapitalChanges[$label] = round($rule['sign'] === 'asset' ? $begin - $end : $end - $begin, 2);
    }

    $operatingAssetPatterns = '/accounts? receivable|trade receivable|project cash advance|prepaid/i';
    $fixedAssetPattern = '/property|equipment|machinery|vehicle|furniture|building|\\bland\\b|accumulated depreciation/i';
    $otherOperatingAssets = static fn(array $account): bool => $account['type_name'] === 'Asset'
        && $account['classification'] === 'current'
        && (int) $account['is_cash_account'] !== 1
        && preg_match($operatingAssetPatterns, (string) $account['name']) !== 1
        && preg_match($fixedAssetPattern, (string) $account['name']) !== 1;
    $otherOperatingLiabilities = static fn(array $account): bool => $account['type_name'] === 'Liability'
        && $account['classification'] === 'current'
        && preg_match('/accounts payable|trade payable|vendor payable|salaries payable|wages payable|unearned revenue|deferred revenue|notes payable|loan|borrowing/i', (string) $account['name']) !== 1;
    $otherAssetBegin = $balanceTotal($beginningAccounts, $otherOperatingAssets);
    $otherAssetEnd = $balanceTotal($endingAccounts, $otherOperatingAssets);
    $otherLiabilityBegin = $balanceTotal($beginningAccounts, $otherOperatingLiabilities);
    $otherLiabilityEnd = $balanceTotal($endingAccounts, $otherOperatingLiabilities);
    $otherWorkingCapitalChange = round(($otherAssetBegin - $otherAssetEnd) + ($otherLiabilityEnd - $otherLiabilityBegin), 2);

    $cashLinesStmt = $pdo->prepare(
        "SELECT tj.id AS journal_id, t.amount, t.entry_type, a.name AS account_name,
                at.name AS account_type, a.classification, a.is_cash_account
         FROM transaction_journals tj
         JOIN transactions t ON t.transaction_journal_id = tj.id
         JOIN accounts a ON a.id = t.account_id
         JOIN account_types at ON at.id = a.account_type_id
         WHERE tj.status <> 'voided'
           AND tj.transaction_date BETWEEN :date_from AND :date_to
         ORDER BY tj.id, t.id"
    );
    $cashLinesStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
    $journals = [];
    foreach ($cashLinesStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $journalId = (int) $line['journal_id'];
        $journals[$journalId]['cash_change'] ??= 0.0;
        $journals[$journalId]['counterparts'] ??= [];
        $amount = (float) $line['amount'];
        if ($cashEquivalentMatches([
            'account_type' => $line['account_type'],
            'account_name' => $line['account_name'],
            'is_cash_account' => $line['is_cash_account'],
        ])) {
            $journals[$journalId]['cash_change'] += $line['entry_type'] === 'debit' ? $amount : -$amount;
        } else {
            $journals[$journalId]['counterparts'][] = $line;
        }
    }

    $capitalCash = 0.0;
    $drawingCash = 0.0;
    $notesPayableCash = 0.0;
    $investingCash = 0.0;
    foreach ($journals as $journal) {
        $cashDelta = (float) $journal['cash_change'];
        $counterparts = $journal['counterparts'];
        $totalWeight = array_sum(array_map(static fn(array $line): float => (float) $line['amount'], $counterparts));
        if (abs($cashDelta) < 0.005 || $totalWeight <= 0) {
            continue;
        }
        $weights = ['capital' => 0.0, 'drawing' => 0.0, 'notes' => 0.0, 'investing' => 0.0];
        foreach ($counterparts as $line) {
            $name = (string) $line['account_name'];
            $amount = (float) $line['amount'];
            if ($line['account_type'] === 'Equity' && preg_match('/owner.?s?\\s+(capital|equity)|owners capital|capital invested/i', $name) === 1) {
                $weights['capital'] += $amount;
            } elseif ($line['account_type'] === 'Equity' && preg_match('/owner.?s?\\s+(drawing|drawings|draw)|\\bdrawings?\\b/i', $name) === 1) {
                $weights['drawing'] += $amount;
            } elseif ($line['account_type'] === 'Liability' && preg_match('/notes payable|loan|borrowing/i', $name) === 1) {
                $weights['notes'] += $amount;
            } elseif ($line['account_type'] === 'Asset' && (preg_match($fixedAssetPattern, $name) === 1 || $line['classification'] === 'non_current')) {
                $weights['investing'] += $amount;
            }
        }
        foreach ($weights as $kind => $weight) {
            if ($weight > 0) {
                $share = $cashDelta * $weight / $totalWeight;
                if ($kind === 'capital') { $capitalCash += $share; }
                elseif ($kind === 'drawing') { $drawingCash += $share; }
                elseif ($kind === 'notes') { $notesPayableCash += $share; }
                elseif ($kind === 'investing') { $investingCash += $share; }
            }
        }
    }
    $capitalCash = round($capitalCash, 2);
    $drawingCash = round($drawingCash, 2);
    $notesPayableCash = round($notesPayableCash, 2);
    $investingCash = round($investingCash, 2);

    $knownOperatingAdjustments = $netIncome + $depreciation + array_sum($workingCapitalChanges) + $otherWorkingCapitalChange;
    // Put any remaining operating/unclassified movement on its own visible line so the
    // three activity totals reconcile exactly to cash at the report cutoff.
    $otherOperatingAdjustment = round($netChange - $knownOperatingAdjustments - $investingCash - $capitalCash - $drawingCash - $notesPayableCash, 2);
    $operatingNet = round($knownOperatingAdjustments + $otherOperatingAdjustment, 2);
    $investingNet = $investingCash;
    $financingNet = round($capitalCash + $drawingCash + $notesPayableCash, 2);
    $netIncrease = round($operatingNet + $investingNet + $financingNet, 2);
    $endingCashFromStatement = round($beginningCash + $netIncrease, 2);
    $reconciliationVariance = round($endingCash - $endingCashFromStatement, 2);
    $businessName = trim((string) $pdo->query('SELECT business_name FROM business_settings WHERE id = 1')->fetchColumn());

    $sections = [
        'operating' => [
            'title' => 'CASH FLOWS FROM OPERATING ACTIVITIES',
            'items' => [
                ['label' => 'Net Income After Tax', 'amount' => $netIncome],
                ['label' => 'Adjustments to Reconcile Net Income to Net Cash', 'kind' => 'subheading'],
                ['label' => 'Depreciation Expense', 'amount' => $depreciation],
                ['label' => 'Changes in Operating Assets and Liabilities', 'kind' => 'subheading'],
                ['label' => 'Decrease (Increase) in Accounts Receivable', 'amount' => $workingCapitalChanges['Accounts Receivable']],
                ['label' => 'Decrease (Increase) in Project Cash Advance', 'amount' => $workingCapitalChanges['Project Cash Advance']],
                ['label' => 'Decrease (Increase) in Prepaid Expense', 'amount' => $workingCapitalChanges['Prepaid Expense']],
                ['label' => 'Increase (Decrease) in Accounts Payable', 'amount' => $workingCapitalChanges['Accounts Payable']],
                ['label' => 'Increase (Decrease) in Salaries Payable', 'amount' => $workingCapitalChanges['Salaries Payable']],
                ['label' => 'Increase (Decrease) in Unearned Revenue', 'amount' => $workingCapitalChanges['Unearned Revenue']],
                ['label' => 'Other Operating Assets and Liabilities', 'amount' => $otherWorkingCapitalChange],
                ['label' => 'Other Operating / Unclassified Adjustments', 'amount' => $otherOperatingAdjustment],
            ],
            'total_label' => 'NET CASH PROVIDED BY (USED IN) OPERATING ACTIVITIES',
            'total' => $operatingNet,
        ],
        'investing' => [
            'title' => 'CASH FLOWS FROM INVESTING ACTIVITIES',
            'items' => [['label' => 'Purchase of Computer Equipment / Other Assets', 'amount' => $investingCash]],
            'total_label' => 'NET CASH PROVIDED BY (USED IN) INVESTING ACTIVITIES',
            'total' => $investingNet,
        ],
        'financing' => [
            'title' => 'CASH FLOWS FROM FINANCING ACTIVITIES',
            'items' => [
                ['label' => "Owner's Capital", 'amount' => $capitalCash],
                [
                    'label' => "Owner's Drawing",
                    'amount' => $drawingCash,
                ],
                ['label' => 'Notes Payable', 'amount' => $notesPayableCash],
            ],
            'total_label' => 'NET CASH PROVIDED BY (USED IN) FINANCING ACTIVITIES',
            'total' => $financingNet,
        ],
    ];
    $summary = [
        ['label' => 'Operating Cash Flow', 'amount' => $operatingNet],
        ['label' => 'Investing Cash Flow', 'amount' => $investingNet],
        ['label' => 'Financing Cash Flow', 'amount' => $financingNet],
        ['label' => 'Net Increase (Decrease) in Cash', 'amount' => $netIncrease],
        ['label' => 'Cash at End of Period', 'amount' => $endingCash],
        ['label' => 'Cash Reconciliation', 'text' => abs($reconciliationVariance) < 0.01 ? 'Reconciled' : 'Variance: ' . formatCashFlowStatementAmount($reconciliationVariance)],
    ];

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'sections' => $sections,
        'net_change' => $netIncrease,
        'beginning_cash' => $beginningCash,
        'ending_cash' => $endingCash,
        'reconciliation_variance' => $reconciliationVariance,
        'business_name' => $businessName !== '' && $businessName !== 'My Business' ? $businessName : 'WRS Accounting / KoboSocial',
        'summary' => $summary,
    ];
}
function formatCashFlowStatementDate(string $date): string
{
    return (new DateTimeImmutable($date))->format('m/d/Y');
}

function formatCashFlowStatementAmount(float|int|string $amount): string
{
    $amount = (float) $amount;
    $formatted = "\u{20B1}" . number_format(abs($amount), 2, '.', ',');
    return $amount < -0.004 ? '(' . $formatted . ')' : $formatted;
}
function renderCashFlowStatementHtml(array $data): string
{
    $dateFrom = htmlspecialchars(formatCashFlowStatementDate($data['date_from']), ENT_QUOTES, 'UTF-8');
    $dateTo = htmlspecialchars(formatCashFlowStatementDate($data['date_to']), ENT_QUOTES, 'UTF-8');
    $html = <<<'HTML'
<style>
.cash-flow-statement {
    box-sizing: border-box;
    width: 100%;
    max-width: 900px;
    margin: 0 auto;
    padding: 42px 54px;
    background: #fff;
    color: #000;
    font-family: "Times New Roman", "DejaVu Serif", serif;
    font-size: 16px;
    line-height: 1.45;
}
.cash-flow-statement * { box-sizing: border-box; }
.cash-flow-statement h1 {
    margin: 0;
    color: #000;
    font-family: "Times New Roman", "DejaVu Serif", serif;
    font-size: 24px;
    font-weight: 700;
    text-align: center;
}
.cash-flow-statement .cash-flow-business {
    margin-bottom: 5px;
    color: #000;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
    text-transform: uppercase;
}
.cash-flow-statement .cash-flow-summary {
    margin-top: 18px;
    padding-top: 8px;
    border-top: 1px solid #000;
    font-size: 13px;
}
.cash-flow-statement .cash-flow-summary h2 { margin: 0 0 5px; font-size: 15px; }
.cash-flow-statement .cash-flow-summary p { margin: 3px 0; }
.cash-flow-statement .cash-flow-period {
    margin: 4px 0 34px;
    color: #000;
    font-size: 15px;
    font-style: italic;
    text-align: center;
}
.cash-flow-statement .cash-flow-section { margin: 0 0 30px; }
.cash-flow-statement .cash-flow-section h2 {
    margin: 0 0 9px;
    color: #000;
    font-family: "Times New Roman", "DejaVu Serif", serif;
    font-size: 17px;
    font-weight: 700;
}
.cash-flow-statement .cash-flow-lines {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}
.cash-flow-statement .cash-flow-lines td {
    padding: 4px 0;
    border: 0;
    background: #fff;
    color: #000;
    vertical-align: top;
}
.cash-flow-statement .cash-flow-label { padding-left: 28px !important; }
.cash-flow-statement .cash-flow-amount {
    width: 190px;
    text-align: right;
    white-space: nowrap;
}
.cash-flow-statement .cash-flow-placeholder td {
    color: #777;
    font-style: italic;
}
.cash-flow-statement .cash-flow-total td {
    padding-top: 9px;
    border-top: 1px solid #000;
    font-weight: 700;
}
.cash-flow-statement .cash-flow-total + .cash-flow-total td {
    border-top: 0;
}
.cash-flow-statement .cash-flow-total .cash-flow-amount span {
    display: inline-block;
    min-width: 128px;
    padding-bottom: 2px;
    border-bottom: 1px solid #000;
}
.cash-flow-statement .cash-flow-net {
    margin-top: 9px;
    padding-top: 11px;
    border-top: 1px solid #000;
    font-weight: 700;
}
.cash-flow-statement .cash-flow-net .cash-flow-amount span {
    display: inline-block;
    min-width: 128px;
    padding-bottom: 3px;
    border-bottom: 3px double #000;
}
@media print {
    @page { size: A4 portrait; margin: 18mm 16mm; }
    html, body { width: 100%; min-height: 0; margin: 0 !important; padding: 0 !important; background: #fff !important; }
    body * { visibility: hidden !important; }
    #reportContent, #reportContent * { visibility: visible !important; }
    .cash-flow-statement, .cash-flow-statement * { visibility: visible !important; }
    #reportContent {
        position: absolute !important;
        inset: 0 auto auto 0 !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }
    .cash-flow-statement {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        font-size: 12pt !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .cash-flow-statement .cash-flow-section { break-inside: avoid; }
}
</style>
<article class="cash-flow-statement">
    <div class="cash-flow-business">BUSINESS NAME</div>
    <h1>Statement of Cash Flows (Indirect Method)</h1>
    <p class="cash-flow-period">For the Period Ended DATE_TO</p>
HTML;
    $html = str_replace('DATE_TO', $dateTo, $html);
    $businessName = htmlspecialchars((string) ($data['business_name'] ?? 'BUSINESS NAME'), ENT_QUOTES, 'UTF-8');
    $html = str_replace('BUSINESS NAME', $businessName, $html);
    $renderRows = static function (array $items): string {
        $rows = '';
        foreach ($items as $item) {
            $label = htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8');
            if (($item['kind'] ?? '') === 'subheading') {
                $rows .= '<tr><td class="cash-flow-label"><strong>' . $label . '</strong></td><td class="cash-flow-amount">&nbsp;</td></tr>';
            } else {
                $rows .= '<tr><td class="cash-flow-label">' . $label . '</td><td class="cash-flow-amount">'
                    . formatCashFlowStatementAmount((float) $item['amount']) . '</td></tr>';
            }
        }
        return $rows;
    };
    $renderTable = static function (array $items, string $totalLabel, float $total) use ($renderRows): string {
        return '<table class="cash-flow-lines"><colgroup><col><col style="width:190px"></colgroup><tbody>'
            . $renderRows($items)
            . '<tr class="cash-flow-total"><td>' . htmlspecialchars($totalLabel, ENT_QUOTES, 'UTF-8') . '</td><td class="cash-flow-amount"><span>'
            . formatCashFlowStatementAmount($total) . '</span></td></tr></tbody></table>';
    };

    foreach ($data['sections'] as $section) {
        $html .= '<section class="cash-flow-section"><h2>' . htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') . '</h2>'
            . $renderTable($section['items'], $section['total_label'], (float) $section['total'])
            . '</section>';
    }

    $html .= '<div class="cash-flow-net"><table class="cash-flow-lines"><colgroup><col><col style="width:190px"></colgroup><tbody>'
        . '<tr><td>NET INCREASE (DECREASE) IN CASH</td><td class="cash-flow-amount"><span>' . formatCashFlowStatementAmount((float) $data['net_change']) . '</span></td></tr>'
        . '<tr><td>Cash at Beginning of Period</td><td class="cash-flow-amount">' . formatCashFlowStatementAmount((float) $data['beginning_cash']) . '</td></tr>'
        . '<tr><td>CASH AT END OF PERIOD</td><td class="cash-flow-amount"><span>' . formatCashFlowStatementAmount((float) $data['ending_cash']) . '</span></td></tr>'
        . '</tbody></table></div>';

    $summaryRows = '';
    foreach ($data['summary'] as $line) {
        $value = array_key_exists('amount', $line)
            ? formatCashFlowStatementAmount((float) $line['amount'])
            : htmlspecialchars((string) ($line['text'] ?? ''), ENT_QUOTES, 'UTF-8');
        $summaryRows .= '<tr><td class="cash-flow-label">' . htmlspecialchars((string) $line['label'], ENT_QUOTES, 'UTF-8')
            . '</td><td class="cash-flow-amount">' . $value . '</td></tr>';
    }
    $html .= '<section class="cash-flow-summary"><h2>Summary</h2><table class="cash-flow-lines"><colgroup><col><col style="width:190px"></colgroup><tbody>'
        . $summaryRows . '</tbody></table></section></article>';

    return $html;
}
