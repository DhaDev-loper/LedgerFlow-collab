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

    $stmt = $pdo->prepare(
        "SELECT tj.id AS journal_id, tj.description, tj.reference_no, tj.transaction_date,
                t.amount, t.entry_type, a.id AS account_id, a.name AS account_name,
                a.is_cash_account, a.classification, at.name AS account_type
         FROM transaction_journals tj
         JOIN transactions t ON t.transaction_journal_id = tj.id
         JOIN accounts a ON a.id = t.account_id
         JOIN account_types at ON at.id = a.account_type_id
         WHERE tj.status != 'voided'
           AND tj.transaction_date BETWEEN :date_from AND :date_to
         ORDER BY tj.transaction_date, tj.id, a.name"
    );
    $stmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);

    $journals = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $journalId = (int) $row['journal_id'];
        $journals[$journalId]['description'] = (string) $row['description'];
        $journals[$journalId]['reference_no'] = $row['reference_no'];
        $journals[$journalId]['transaction_date'] = (string) $row['transaction_date'];

        $amount = (float) $row['amount'];
        $row['amount'] = $amount;
        if ((int) $row['is_cash_account'] === 1 && $row['account_type'] === 'Asset') {
            $journals[$journalId]['cash_change'] =
                ($journals[$journalId]['cash_change'] ?? 0.0)
                + ($row['entry_type'] === 'debit' ? $amount : -$amount);
        } else {
            $journals[$journalId]['counterpart_lines'][] = $row;
        }
    }

    $activities = [
        'operating' => [],
        'investing' => [],
        'financing' => [],
    ];

    foreach ($journals as $journal) {
        $cashChange = (float) ($journal['cash_change'] ?? 0.0);
        $counterpartLines = $journal['counterpart_lines'] ?? [];
        if (abs($cashChange) < 0.005 || !$counterpartLines) {
            continue;
        }

        $weightedLines = [];
        $totalWeight = 0.0;
        foreach ($counterpartLines as $line) {
            $accountType = $line['account_type'];
            $accountName = strtolower((string) $line['account_name']);
            if ($accountType === 'Asset') {
                $activity = $line['classification'] === 'non_current' ? 'investing' : 'operating';
            } elseif ($accountType === 'Equity') {
                $activity = 'financing';
            } elseif ($accountType === 'Liability') {
                $isFinancingLiability = $line['classification'] === 'non_current'
                    || preg_match('/\b(loan|borrow|note payable|credit line|lease payable)\b/', $accountName) === 1;
                $activity = $isFinancingLiability ? 'financing' : 'operating';
            } else {
                $activity = 'operating';
            }

            $weight = (float) $line['amount'];
            if ($weight <= 0) {
                continue;
            }

            $weightedLines[] = [
                'activity' => $activity,
                'weight' => $weight,
                'line' => $line,
            ];
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0) {
            continue;
        }

        $cashChangeCents = (int) round($cashChange * 100, 0, PHP_ROUND_HALF_UP);
        $remainingCashCents = $cashChangeCents;
        $lineCount = count($weightedLines);
        foreach ($weightedLines as $index => $weightedLine) {
            $line = $weightedLine['line'];
            $allocatedCents = $index === $lineCount - 1
                ? $remainingCashCents
                : (int) round(
                    $cashChangeCents * $weightedLine['weight'] / $totalWeight,
                    0,
                    PHP_ROUND_HALF_UP
                );
            $remainingCashCents -= $allocatedCents;
            $amount = $allocatedCents / 100;
            $label = (string) $line['account_name'];
            $description = trim($journal['description']);
            if ($description !== '' && strcasecmp($description, $label) !== 0) {
                $label .= ' — ' . $description;
            }

            if (!empty($journal['reference_no'])) {
                $label .= ' (Ref ' . (string) $journal['reference_no'] . ')';
            }

            $key = $label;
            if (!isset($activities[$weightedLine['activity']][$key])) {
                $activities[$weightedLine['activity']][$key] = [
                    'label' => $label,
                    'amount' => 0.0,
                ];
            }
            $activities[$weightedLine['activity']][$key]['amount'] += $amount;
        }
    }

    $sections = [];
    $sectionNames = [
        'operating' => 'Operating Activities',
        'investing' => 'Investing Activities',
        'financing' => 'Financing Activities',
    ];
    $totalCashFlow = 0.0;

    foreach ($sectionNames as $key => $title) {
        $items = array_values(array_filter(
            $activities[$key],
            static fn(array $item): bool => abs($item['amount']) >= 0.005
        ));
        usort($items, static fn(array $left, array $right): int => strcasecmp($left['label'], $right['label']));
        $sectionTotal = round(array_sum(array_column($items, 'amount')), 2);
        $totalCashFlow += $sectionTotal;
        $sections[$key] = [
            'title' => $title,
            'items' => $items,
            'total' => round($sectionTotal, 2),
        ];
    }

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'sections' => $sections,
        'net_change' => round($totalCashFlow, 2),
    ];
}

function formatCashFlowStatementDate(string $date): string
{
    return (new DateTimeImmutable($date))->format('F j, Y');
}

function formatCashFlowStatementAmount(float|int|string $amount): string
{
    $amount = (float) $amount;
    $formatted = '₱' . number_format(abs($amount), 2, '.', ',');
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
    <h1>Statement of Cash Flows</h1>
    <p class="cash-flow-period">For the Period DATE_FROM to DATE_TO</p>
HTML;
    $html = str_replace(['DATE_FROM', 'DATE_TO'], [$dateFrom, $dateTo], $html);
    $totalLabels = [
        'operating' => 'Net cash provided by (used in) operating activities',
        'investing' => 'Net cash provided by (used in) investing activities',
        'financing' => 'Net cash provided by (used in) financing activities',
    ];

    foreach ($data['sections'] as $key => $section) {
        $html .= '<section class="cash-flow-section"><h2>'
            . htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8')
            . '</h2><table class="cash-flow-lines"><tbody>';

        if (!$section['items']) {
            $activityName = strtolower(str_replace(' Activities', '', $section['title']));
            $html .= '<tr class="cash-flow-placeholder"><td class="cash-flow-label">No '
                . htmlspecialchars($activityName, ENT_QUOTES, 'UTF-8')
                . ' transactions in this period</td><td class="cash-flow-amount">'
                . formatCashFlowStatementAmount(0)
                . '</td></tr>';
        } else {
            foreach ($section['items'] as $item) {
                $html .= '<tr><td class="cash-flow-label">'
                    . htmlspecialchars($item['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</td><td class="cash-flow-amount">'
                    . formatCashFlowStatementAmount($item['amount'])
                    . '</td></tr>';
            }
        }

        $html .= '<tr class="cash-flow-total"><td>'
            . htmlspecialchars($totalLabels[$key], ENT_QUOTES, 'UTF-8')
            . '</td><td class="cash-flow-amount"><span>'
            . formatCashFlowStatementAmount($section['total'])
            . '</span></td></tr></tbody></table></section>';
    }

    $html .= '<div class="cash-flow-net"><table class="cash-flow-lines"><tbody><tr><td>Net Increase (Decrease) in Cash</td><td class="cash-flow-amount"><span>'
        . formatCashFlowStatementAmount($data['net_change'])
        . '</span></td></tr></tbody></table></div></article>';

    return $html;
}
