<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;

$action = $_GET['action'] ?? '';
$isPreview = isset($_GET['preview']) && $_GET['preview'] === '1';
$pdo = getDbConnection();

// Fetch business name once, default to 'Kobosocial' if missing
$bizStmt = $pdo->query("SELECT business_name FROM business_settings WHERE id = 1");
$businessName = $bizStmt->fetch()['business_name'] ?? 'Kobosocial';

/**
 * Shared CSS for PDF Reports
 */
function getReportCss(): string {
    return "
    <style>
        @page { margin: 30px 35px; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1e293b; font-size: 11px; margin: 0; padding: 0; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; border-bottom: 2px solid #0f172a; padding-bottom: 12px; }
        .brand-title { font-size: 22px; font-weight: bold; color: #0f172a; letter-spacing: -0.5px; text-transform: uppercase; }
        .brand-sub { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; }
        .report-title { font-size: 16px; font-weight: bold; color: #2563eb; text-align: right; text-transform: uppercase; }
        .report-meta { font-size: 10px; color: #475569; text-align: right; margin-top: 4px; }
        
        .section-header { font-size: 12px; font-weight: bold; color: #0f172a; text-transform: uppercase; background: #f1f5f9; padding: 6px 8px; border-left: 4px solid #2563eb; margin-top: 15px; margin-bottom: 8px; }
        
        table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.data-table th { background-color: #f8fafc; color: #475569; font-size: 10px; font-weight: bold; text-transform: uppercase; border-bottom: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        table.data-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; color: #334155; }
        table.data-table tr:nth-child(even) td { background-color: #fafafa; }
        
        .text-right { text-align: right; }
        .subtotal-row td { font-weight: bold; border-top: 1px solid #94a3b8; border-bottom: 1px solid #94a3b8; background-color: #f8fafc; color: #0f172a; }
        .grand-total-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .grand-total-row td { font-size: 13px; font-weight: bold; color: #0f172a; background-color: #eff6ff; border-top: 2px solid #2563eb; border-bottom: 3px double #2563eb; padding: 10px 8px; }
        .footer { position: fixed; bottom: -10px; left: 0px; right: 0px; font-size: 8px; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 5px; }
    </style>";
}

switch ($action) {

    case 'income-statement-pdf':
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');

        // Fetch Revenue Lines
        $revenueStmt = $pdo->prepare(
            "SELECT a.name, SUM(CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE -t.amount END) AS net_amount
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             JOIN transaction_journals tj ON tj.id = t.transaction_journal_id
             WHERE at.name = 'Revenue' AND tj.transaction_date BETWEEN :date_from AND :date_to
             GROUP BY a.id, a.name HAVING net_amount != 0 ORDER BY a.name"
        );
        $revenueStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
        $revenueLines = $revenueStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Expense Lines
        $expenseStmt = $pdo->prepare(
            "SELECT a.name, SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END) AS net_amount
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             JOIN account_types at ON at.id = a.account_type_id
             JOIN transaction_journals tj ON tj.id = t.transaction_journal_id
             WHERE at.name = 'Expense' AND tj.transaction_date BETWEEN :date_from AND :date_to
             GROUP BY a.id, a.name HAVING net_amount != 0 ORDER BY a.name"
        );
        $expenseStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
        $expenseLines = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalRevenue = array_sum(array_column($revenueLines, 'net_amount'));
        $totalExpense = array_sum(array_column($expenseLines, 'net_amount'));
        $netIncome = $totalRevenue - $totalExpense;

        $fmt = fn($n) => '&#8369;' . number_format((float) $n, 2);

        $revenueRows = '';
        foreach ($revenueLines as $line) {
            $name = htmlspecialchars($line['name'], ENT_QUOTES, 'UTF-8');
            $revenueRows .= "<tr><td>{$name}</td><td class='text-right'>" . $fmt($line['net_amount']) . "</td></tr>";
        }
        if (empty($revenueLines)) {
            $revenueRows = "<tr><td colspan='2' style='color: #94a3b8;'>No revenue recorded for this period.</td></tr>";
        }

        $expenseRows = '';
        foreach ($expenseLines as $line) {
            $name = htmlspecialchars($line['name'], ENT_QUOTES, 'UTF-8');
            $expenseRows .= "<tr><td>{$name}</td><td class='text-right'>" . $fmt($line['net_amount']) . "</td></tr>";
        }
        if (empty($expenseLines)) {
            $expenseRows = "<tr><td colspan='2' style='color: #94a3b8;'>No expenses recorded for this period.</td></tr>";
        }

        $safeBusinessName = htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8');

        $html = "
        <html><head>" . getReportCss() . "</head><body>
            <div class='footer'>Generated automatically by {$safeBusinessName} Accounting System on " . date('M d, Y H:i') . "</div>
            <table class='header-table'>
                <tr>
                    <td style='vertical-align: top;'>
                        <div class='brand-title'>{$safeBusinessName}</div>
                        <div class='brand-sub'>Financial Statement</div>
                    </td>
                    <td style='vertical-align: top;'>
                        <div class='report-title'>Income Statement</div>
                        <div class='report-meta'>Period: " . date('M d, Y', strtotime($dateFrom)) . " &mdash; " . date('M d, Y', strtotime($dateTo)) . "</div>
                    </td>
                </tr>
            </table>

            <div class='section-header'>Revenue</div>
            <table class='data-table'>
                <thead><tr><th>Account Name</th><th class='text-right' style='width: 30%;'>Amount</th></tr></thead>
                <tbody>
                    {$revenueRows}
                    <tr class='subtotal-row'><td>Total Revenue</td><td class='text-right'>" . $fmt($totalRevenue) . "</td></tr>
                </tbody>
            </table>

            <div class='section-header'>Operating Expenses</div>
            <table class='data-table'>
                <thead><tr><th>Account Name</th><th class='text-right' style='width: 30%;'>Amount</th></tr></thead>
                <tbody>
                    {$expenseRows}
                    <tr class='subtotal-row'><td>Total Operating Expenses</td><td class='text-right'>" . $fmt($totalExpense) . "</td></tr>
                </tbody>
            </table>

            <table class='grand-total-table'>
                <tr class='grand-total-row'>
                    <td>Net Income / (Loss)</td>
                    <td class='text-right'>" . $fmt($netIncome) . "</td>
                </tr>
            </table>
        </body></html>";

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Attachment => 0 renders inside iframe preview, 1 forces direct file download
        $dompdf->stream('Income_Statement_' . $dateFrom . '_to_' . $dateTo . '.pdf', ['Attachment' => $isPreview ? 0 : 1]);
        exit;

    case 'balance-sheet-pdf':
        $asOf = $_GET['as_of'] ?? date('Y-m-d');
        $fmt = fn($n) => '&#8369;' . number_format((float) $n, 2);

        // Fetch Group Balances as of specified date
        $fetchGroup = function (string $typeName) use ($pdo, $asOf): array {
            $isAssetOrExpense = in_array($typeName, ['Asset', 'Expense'], true);
            $normalFormula = $isAssetOrExpense
                ? "SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END)"
                : "SUM(CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE -t.amount END)";

            $stmt = $pdo->prepare(
                "SELECT a.name, COALESCE({$normalFormula}, 0) AS balance
                 FROM accounts a
                 JOIN account_types at ON at.id = a.account_type_id
                 LEFT JOIN transactions t ON t.account_id = a.id
                 LEFT JOIN transaction_journals tj ON tj.id = t.transaction_journal_id AND tj.transaction_date <= :as_of
                 WHERE at.name = :type AND a.is_active = 1
                 GROUP BY a.id, a.name
                 HAVING balance != 0
                 ORDER BY a.name"
            );
            $stmt->execute([':type' => $typeName, ':as_of' => $asOf]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        $assets = $fetchGroup('Asset');
        $liabilities = $fetchGroup('Liability');
        $equity = $fetchGroup('Equity');

        $totalAssets = array_sum(array_column($assets, 'balance'));
        $totalLiabilities = array_sum(array_column($liabilities, 'balance'));
        $totalEquityRecorded = array_sum(array_column($equity, 'balance'));

        // Retained earnings up to as_of date
        // Retained earnings up to as_of date
        $incomeStmt = $pdo->prepare(
            "SELECT
                (SELECT COALESCE(SUM(CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE -t.amount END), 0)
                 FROM transactions t 
                 JOIN accounts a ON a.id = t.account_id 
                 JOIN account_types at ON at.id = a.account_type_id
                 JOIN transaction_journals tj ON tj.id = t.transaction_journal_id
                 WHERE at.name = 'Revenue' AND tj.transaction_date <= :as_of_rev) AS total_revenue,
                (SELECT COALESCE(SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END), 0)
                 FROM transactions t 
                 JOIN accounts a ON a.id = t.account_id 
                 JOIN account_types at ON at.id = a.account_type_id
                 JOIN transaction_journals tj ON tj.id = t.transaction_journal_id
                 WHERE at.name = 'Expense' AND tj.transaction_date <= :as_of_exp) AS total_expense"
        );
        $incomeStmt->execute([
            ':as_of_rev' => $asOf,
            ':as_of_exp' => $asOf
        ]);
        $income = $incomeStmt->fetch(PDO::FETCH_ASSOC);

        $retainedEarnings = (float) ($income['total_revenue'] ?? 0) - (float) ($income['total_expense'] ?? 0);
        $totalEquity = $totalEquityRecorded + $retainedEarnings;
        $totalLiabilitiesEquity = $totalLiabilities + $totalEquity;

        $buildTableRows = function (array $items) use ($fmt): string {
            if (empty($items)) {
                return "<tr><td colspan='2' style='color: #94a3b8;'>No accounts with active balances.</td></tr>";
            }
            $html = '';
            foreach ($items as $item) {
                $name = htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8');
                $html .= "<tr><td>{$name}</td><td class='text-right'>" . $fmt($item['balance']) . "</td></tr>";
            }
            return $html;
        };

        $safeBusinessName = htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8');

        $html = "
        <html><head>" . getReportCss() . "</head><body>
            <div class='footer'>Generated automatically by {$safeBusinessName} Accounting System on " . date('M d, Y H:i') . "</div>
            <table class='header-table'>
                <tr>
                    <td style='vertical-align: top;'>
                        <div class='brand-title'>{$safeBusinessName}</div>
                        <div class='brand-sub'>Financial Position</div>
                    </td>
                    <td style='vertical-align: top;'>
                        <div class='report-title'>Balance Sheet</div>
                        <div class='report-meta'>As of " . date('F d, Y', strtotime($asOf)) . "</div>
                    </td>
                </tr>
            </table>

            <div class='section-header'>Assets</div>
            <table class='data-table'>
                <thead><tr><th>Account Name</th><th class='text-right' style='width: 30%;'>Balance</th></tr></thead>
                <tbody>
                    " . $buildTableRows($assets) . "
                    <tr class='subtotal-row'><td>Total Assets</td><td class='text-right'>" . $fmt($totalAssets) . "</td></tr>
                </tbody>
            </table>

            <div class='section-header'>Liabilities</div>
            <table class='data-table'>
                <thead><tr><th>Account Name</th><th class='text-right' style='width: 30%;'>Balance</th></tr></thead>
                <tbody>
                    " . $buildTableRows($liabilities) . "
                    <tr class='subtotal-row'><td>Total Liabilities</td><td class='text-right'>" . $fmt($totalLiabilities) . "</td></tr>
                </tbody>
            </table>

            <div class='section-header'>Equity</div>
            <table class='data-table'>
                <thead><tr><th>Account Name</th><th class='text-right' style='width: 30%;'>Balance</th></tr></thead>
                <tbody>
                    " . $buildTableRows($equity) . "
                    <tr><td>Retained Earnings (Unappropriated)</td><td class='text-right'>" . $fmt($retainedEarnings) . "</td></tr>
                    <tr class='subtotal-row'><td>Total Equity</td><td class='text-right'>" . $fmt($totalEquity) . "</td></tr>
                </tbody>
            </table>

            <table class='grand-total-table'>
                <tr class='grand-total-row'>
                    <td>Total Liabilities & Equity</td>
                    <td class='text-right'>" . $fmt($totalLiabilitiesEquity) . "</td>
                </tr>
            </table>
        </body></html>";

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Attachment => 0 renders inside iframe preview, 1 forces direct file download
        $dompdf->stream('Balance_Sheet_' . $asOf . '.pdf', ['Attachment' => $isPreview ? 0 : 1]);
        exit;

    case 'trial-balance-xlsx':
        $asOf = $_GET['as_of'] ?? date('Y-m-d');

        $stmt = $pdo->prepare(
            "SELECT a.name, at.name AS type_name,
                    COALESCE(SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE 0 END), 0) AS total_debit,
                    COALESCE(SUM(CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE 0 END), 0) AS total_credit
             FROM accounts a
             JOIN account_types at ON at.id = a.account_type_id
             LEFT JOIN transactions t ON t.account_id = a.id
             LEFT JOIN transaction_journals tj ON tj.id = t.transaction_journal_id AND tj.transaction_date <= :as_of
             WHERE a.is_active = 1
             GROUP BY a.id, a.name, at.name
             HAVING (total_debit - total_credit) != 0
             ORDER BY at.id, a.name"
        );
        $stmt->execute([':as_of' => $asOf]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Trial Balance');
        $sheet->setShowGridLines(true);

        // Header Banner
        $sheet->setCellValue('A1', mb_strtoupper($businessName));
        $sheet->setCellValue('A2', 'TRIAL BALANCE REPORT');
        $sheet->setCellValue('A3', 'As of ' . date('F d, Y', strtotime($asOf)));

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->setColor(new Color('FF1E293B'));
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11)->setColor(new Color('FF2563EB'));
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(10)->setColor(new Color('FF64748B'));

        // Table Headers
        $headerRow = 5;
        $sheet->setCellValue("A{$headerRow}", 'ACCOUNT NAME');
        $sheet->setCellValue("B{$headerRow}", 'TYPE');
        $sheet->setCellValue("C{$headerRow}", 'DEBIT');
        $sheet->setCellValue("D{$headerRow}", 'CREDIT');

        $sheet->getStyle("A{$headerRow}:D{$headerRow}")->getFont()->setBold(true)->setSize(10)->setColor(new Color('FFFFFFFF'));
        $sheet->getStyle("A{$headerRow}:D{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A2B4C');
        $sheet->getStyle("A{$headerRow}:D{$headerRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("C{$headerRow}:D{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $row = $headerRow + 1;
        $startDataRow = $row;

        if (!empty($rows)) {
            foreach ($rows as $r) {
                $net = (float) $r['total_debit'] - (float) $r['total_credit'];
                $debitBalance = $net > 0 ? $net : 0;
                $creditBalance = $net < 0 ? abs($net) : 0;

                $sheet->setCellValue("A{$row}", $r['name']);
                $sheet->setCellValue("B{$row}", $r['type_name']);
                $sheet->setCellValue("C{$row}", $debitBalance);
                $sheet->setCellValue("D{$row}", $creditBalance);

                // Alternating Row Fill
                if ($row % 2 === 0) {
                    $sheet->getStyle("A{$row}:D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
                }

                $row++;
            }
            $lastDataRow = $row - 1;
        } else {
            $sheet->setCellValue("A{$row}", 'No active accounts found as of this date.');
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setColor(new Color('FF64748B'));
            $lastDataRow = $row;
            $row++;
        }

        // Totals Row
        $sheet->setCellValue("A{$row}", 'TOTAL');
        if (!empty($rows)) {
            $sheet->setCellValue("C{$row}", "=SUM(C{$startDataRow}:C{$lastDataRow})");
            $sheet->setCellValue("D{$row}", "=SUM(D{$startDataRow}:D{$lastDataRow})");
        } else {
            $sheet->setCellValue("C{$row}", 0);
            $sheet->setCellValue("D{$row}", 0);
        }

        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:D{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$row}:D{$row}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
        $sheet->getStyle("C{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Currency Formatting
        $currencyFormat = '"₱"#,##0.00;[Red]("-₱"#,##0.00);"-"';
        $sheet->getStyle("C{$startDataRow}:D{$row}")->getNumberFormat()->setFormatCode($currencyFormat);

        // Column Auto-sizing
        foreach (['A', 'B', 'C', 'D'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Trial_Balance_' . $asOf . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;

        //--- for print receipt functions 
      case 'journal-entry-pdf':
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(400);
        exit('Invalid entry ID.');
    }

    // Fetch journal entry details using exact schema column names
    $stmt = $pdo->prepare(
        "SELECT tj.*, tt.name AS type_name, u.full_name AS recorded_by_name 
         FROM transaction_journals tj
         LEFT JOIN users u ON u.id = tj.created_by
         LEFT JOIN transaction_types tt ON tt.id = tj.transaction_type_id
         WHERE tj.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $journal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$journal) {
        http_response_code(404);
        exit('Journal entry not found.');
    }

    // Fetch split lines / transactions for this journal
    $lineStmt = $pdo->prepare(
        "SELECT t.*, a.name AS account_name, at.name AS account_type
         FROM transactions t
         JOIN accounts a ON a.id = t.account_id
         JOIN account_types at ON at.id = a.account_type_id
         WHERE t.transaction_journal_id = :id
         ORDER BY t.entry_type ASC, a.name ASC"
    );
    $lineStmt->execute([':id' => $id]);
    $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);

    $safeBusinessName = htmlspecialchars($businessName ?? 'WRS', ENT_QUOTES, 'UTF-8');
    $fmt = fn($n) => '&#8369;' . number_format((float)$n, 2);

    $rowsHtml = '';
    $totalDebit = 0;
    $totalCredit = 0;

    foreach ($lines as $line) {
        $debit = $line['entry_type'] === 'debit' ? (float)$line['amount'] : 0;
        $credit = $line['entry_type'] === 'credit' ? (float)$line['amount'] : 0;
        $totalDebit += $debit;
        $totalCredit += $credit;

        $rowsHtml .= "<tr>
            <td>" . htmlspecialchars($line['account_name']) . "<br><small style='color:#64748b;'>" . htmlspecialchars($line['account_type']) . "</small></td>
            <td class='text-right'>" . ($debit > 0 ? $fmt($debit) : '—') . "</td>
            <td class='text-right'>" . ($credit > 0 ? $fmt($credit) : '—') . "</td>
        </tr>";
    }

    $html = "
    <html><head>" . getReportCss() . "</head><body>
        <div class='footer'>Generated by {$safeBusinessName} System on " . date('M d, Y H:i') . "</div>
        <table class='header-table'>
            <tr>
                <td style='vertical-align: top;'>
                    <div class='brand-title'>{$safeBusinessName}</div>
                    <div class='brand-sub'>Transaction Voucher / Receipt</div>
                </td>
                <td style='vertical-align: top;'>
                    <div class='report-title'>Entry #" . str_pad((string)$journal['id'], 5, '0', STR_PAD_LEFT) . "</div>
                    <div class='report-meta'>Date: " . date('F d, Y', strtotime($journal['transaction_date'])) . "</div>
                </td>
            </tr>
        </table>

        <div style='margin: 15px 0; font-size: 13px;'>
            <strong>Description:</strong> " . htmlspecialchars($journal['description'] ?? 'N/A') . "<br>
            <strong>Reference No:</strong> " . htmlspecialchars($journal['reference_no'] ?? '—') . "<br>
            <strong>Type:</strong> " . htmlspecialchars($journal['type_name'] ?? '—') . "<br>
            <strong>Recorded By:</strong> " . htmlspecialchars($journal['recorded_by_name'] ?? 'System') . "
        </div>

        <div class='section-header'>Transaction Breakdown</div>
        <table class='data-table'>
            <thead>
                <tr>
                    <th>Account</th>
                    <th class='text-right' style='width: 25%;'>Debit</th>
                    <th class='text-right' style='width: 25%;'>Credit</th>
                </tr>
            </thead>
            <tbody>
                {$rowsHtml}
                <tr class='subtotal-row'>
                    <td>Total</td>
                    <td class='text-right'>" . $fmt($totalDebit) . "</td>
                    <td class='text-right'>" . $fmt($totalCredit) . "</td>
                </tr>
            </tbody>
        </table>
    </body></html>";

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A5', 'landscape');
    $dompdf->render();

    $dompdf->stream('Receipt_Entry_' . $id . '.pdf', ['Attachment' => 0]);
    
    // Read preview parameter (defaults to false if not set)
    $isPreview = isset($_GET['preview']) && $_GET['preview'] == '1';

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A5', 'landscape');
    $dompdf->render();

    // Stream inline (Attachment = 0) for iframe display, or force download (Attachment = 1)
    $dompdf->stream('Receipt_Entry_' . $id . '.pdf', ['Attachment' => $isPreview ? 0 : 1]);
    exit;
    
        
    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid export action']);
        exit;
}