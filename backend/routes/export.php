<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/report_helper.php';
require_once __DIR__ . '/../config/cash_flow_statement.php';
require_once __DIR__ . '/../config/financial_statements.php';
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

// Fetch the fixed organization's document details once.
$bizStmt = $pdo->query("SELECT business_name, address, contact_number FROM business_settings WHERE id = 1");
$businessSettings = $bizStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$businessName = $businessSettings['business_name'] ?? 'Kobosocial';
$businessAddress = trim((string) ($businessSettings['address'] ?? ''));
$businessContact = trim((string) ($businessSettings['contact_number'] ?? ''));

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

    case 'cash-flow-statement-pdf':
        $dateFrom = (string) ($_GET['date_from'] ?? '');
        $dateTo = (string) ($_GET['date_to'] ?? '');

        try {
            $data = getCashFlowStatementData($pdo, $dateFrom, $dateTo);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            exit(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Serif');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(
            '<!DOCTYPE html><html lang="en-US"><head><meta charset="UTF-8"><title>Statement of Cash Flows (Indirect Method)</title></head><body>'
            . renderCashFlowStatementHtml($data)
            . '</body></html>',
            'UTF-8'
        );
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream(
            'Statement_of_Cash_Flows_' . $dateFrom . '_to_' . $dateTo . '.pdf',
            ['Attachment' => $isPreview ? 0 : 1]
        );
        exit;

    case 'cash-flow-statement-xlsx':
        $dateFrom = (string) ($_GET['date_from'] ?? '');
        $dateTo = (string) ($_GET['date_to'] ?? '');
        try {
            $data = getCashFlowStatementData($pdo, $dateFrom, $dateTo);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
            exit;
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cash Flows');
        $sheet->setShowGridLines(false);
        foreach ([1, 2, 3] as $headerRow) { $sheet->mergeCells("A{$headerRow}:B{$headerRow}"); }
        $sheet->setCellValue('A1', mb_strtoupper($data['business_name']));
        $sheet->setCellValue('A2', 'Statement of Cash Flows (Indirect Method)');
        $sheet->setCellValue('A3', 'For the Period Ended ' . formatCashFlowStatementDate($dateTo));
        $sheet->getStyle('A1:B3')->getFont()->setName('Times New Roman');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A2:B2')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A3:B3')->getFont()->setItalic(true)->setSize(11);
        $sheet->getStyle('A1:B3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getColumnDimension('A')->setWidth(92);
        $sheet->getColumnDimension('B')->setWidth(24);
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageMargins()->setTop(0.7)->setRight(0.6)->setBottom(0.7)->setLeft(0.6);
        $currencyFormat = "\u{20B1}#,##0.00;(\u{20B1}#,##0.00);\u{20B1}0.00";
        $row = 5;
        $write = static function (string $label, float $amount, bool $bold = false, bool $doubleRule = false) use ($sheet, &$row, $currencyFormat): void {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $amount);
            $sheet->getStyle("A{$row}:B{$row}")->getFont()->setName('Times New Roman')->setSize(11)->setBold($bold);
            $sheet->getStyle("A{$row}")->getAlignment()->setIndent(1);
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode($currencyFormat);
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            if ($bold) { $sheet->getStyle("A{$row}:B{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN); }
            if ($doubleRule) { $sheet->getStyle("B{$row}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE); }
            $row++;
        };
        $heading = static function (string $label) use ($sheet, &$row): void {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}:B{$row}")->getFont()->setName('Times New Roman')->setBold(true)->setSize(12);
            $sheet->getRowDimension($row)->setRowHeight(21);
            $row++;
        };

        foreach ($data['sections'] as $section) {
            $heading($section['title']);
            foreach ($section['items'] as $item) {
                if (($item['kind'] ?? '') === 'subheading') {
                    $sheet->setCellValue("A{$row}", $item['label']);
                    $sheet->getStyle("A{$row}:B{$row}")->getFont()->setName('Times New Roman')->setBold(true)->setItalic(true);
                    $row++;
                } else {
                    $write($item['label'], (float) $item['amount']);
                }
            }
            $write($section['total_label'], (float) $section['total'], true);
            $row++;
        }
        $write('NET INCREASE (DECREASE) IN CASH', (float) $data['net_change'], true);
        $write('Cash at Beginning of Period', (float) $data['beginning_cash']);
        $write('CASH AT END OF PERIOD', (float) $data['ending_cash'], true, true);
        $row++;
        $heading('Summary');
        foreach ($data['summary'] as $line) {
            $sheet->setCellValue("A{$row}", $line['label']);
            if (array_key_exists('amount', $line)) {
                $sheet->setCellValue("B{$row}", (float) $line['amount']);
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode($currencyFormat);
            } else {
                $sheet->setCellValue("B{$row}", (string) ($line['text'] ?? ''));
            }
            $sheet->getStyle("A{$row}:B{$row}")->getFont()->setName('Times New Roman')->setSize(10);
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Statement_of_Cash_Flows_' . $dateFrom . '_to_' . $dateTo . '.xlsx"');
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit;
    case 'income-statement-pdf':
        $dateFrom = (string) ($_GET['date_from'] ?? date('Y-m-01'));
        $dateTo = (string) ($_GET['date_to'] ?? date('Y-m-d'));
        try {
            $data = getIncomeStatementData($pdo, $dateFrom, $dateTo);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            exit(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        $safeName = htmlspecialchars($data['business_name'], ENT_QUOTES, 'UTF-8');
        $fmt = static fn(float $amount): string => '&#8369;' . number_format($amount, 2);
        $table = static function (array $lines, ?array $total = null, bool $grandTotal = false) use ($fmt): string {
            $rows = '';
            foreach ($lines as $line) {
                $rows .= '<tr><td>' . htmlspecialchars((string) $line['name'], ENT_QUOTES, 'UTF-8')
                    . '</td><td class="text-right">' . $fmt((float) $line['amount']) . '</td></tr>';
            }
            if ($total !== null) {
                $class = $grandTotal ? 'subtotal-row grand-total-row' : 'subtotal-row';
                $rows .= '<tr class="' . $class . '"><td>' . htmlspecialchars((string) $total['name'], ENT_QUOTES, 'UTF-8')
                    . '</td><td class="text-right">' . $fmt((float) $total['amount']) . '</td></tr>';
            }
            return '<table class="data-table"><colgroup><col style="width:70%"><col style="width:30%"></colgroup><thead><tr><th>Account Name</th><th class="text-right">Amount (&#8369;)</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        };
        $section = static fn(string $title, array $lines, string $totalLabel, float $total): string =>
            '<div class="section-header">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>'
            . $table($lines, ['name' => $totalLabel, 'amount' => $total]);
        $single = static fn(string $title, string $label, float $amount, bool $grand = false): string =>
            '<div class="section-header">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>'
            . $table([], ['name' => $label, 'amount' => $amount], $grand);
        $summaryLines = $data['summary'];
        $html = '<html><head>' . getReportCss() . '</head><body><div class="footer">Generated automatically by ' . $safeName . ' Accounting System on ' . date('m/d/Y h:i A') . '</div>'
            . '<table class="header-table"><tr><td><div class="brand-title">' . $safeName . '</div><div class="brand-sub">Financial Statement</div></td>'
            . '<td><div class="report-title">Income Statement</div><div class="report-meta">For the Period Ended ' . date('m/d/Y', strtotime($dateTo)) . '</div></td></tr></table>'
            . $section('Revenue', $data['revenue_lines'], 'Total Revenue', $data['total_revenue'])
            . $section('Cost of Services', $data['cost_of_services'], 'Total Cost of Services', $data['total_cost_of_services'])
            . $single('Gross Profit', 'Gross Profit', $data['gross_profit'], true)
            . $section('Operating Expenses', $data['operating_expenses'], 'Total Operating Expenses', $data['total_operating_expenses'])
            . $single('Net Income (Loss) Before Tax', 'Net Income (Loss) Before Tax', $data['net_income_before_tax'])
            . $single('Income Tax Expense', 'Income Tax Expense', $data['income_tax_expense'])
            . $single('Net Income (Loss) After Tax', 'Net Income (Loss) After Tax', $data['net_income_after_tax'], true)
            . '<div class="section-header">Summary</div>' . $table($summaryLines) . '</body></html>';
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream('Income_Statement_' . $dateFrom . '_to_' . $dateTo . '.pdf', ['Attachment' => $isPreview ? 0 : 1]);
        exit;

    case 'balance-sheet-pdf':
        $asOf = getReportAsOfDate($_GET);
        $data = getBalanceSheetData($pdo, $asOf);
        $safeName = htmlspecialchars($data['business_name'], ENT_QUOTES, 'UTF-8');
        $fmt = static fn(float $amount): string => '&#8369;' . number_format($amount, 2);
        $table = static function (array $items, ?array $totalLine = null, bool $grandTotal = false) use ($fmt): string {
            $rows = '';
            foreach ($items as $label => $amount) {
                $rows .= '<tr><td>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</td><td class="text-right">' . $fmt((float) $amount) . '</td></tr>';
            }
            if ($totalLine !== null) {
                $rowClass = $grandTotal ? 'grand-total-row' : 'subtotal-row';
                $rows .= '<tr class="' . $rowClass . '"><td>' . htmlspecialchars((string) $totalLine['name'], ENT_QUOTES, 'UTF-8')
                    . '</td><td class="text-right">' . $fmt((float) $totalLine['amount']) . '</td></tr>';
            }
            $class = $grandTotal ? 'grand-total-table' : 'data-table';
            $header = $grandTotal ? '' : '<thead><tr><th>Account Name</th><th class="text-right">Balance (&#8369;)</th></tr></thead>';
            return '<table class="' . $class . '"><colgroup><col style="width:70%"><col style="width:30%"></colgroup>' . $header . '<tbody>' . $rows . '</tbody></table>';
        };
        $section = static fn(string $title, array $items, string $totalLabel, float $total): string =>
            '<div class="section-header">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>'
            . $table($items, ['name' => $totalLabel, 'amount' => $total]);
        $grandTotal = static fn(string $label, float $amount): string =>
            $table([], ['name' => $label, 'amount' => $amount], true);
        $summaryHtml = '';
        foreach ($data['summary'] as $line) {
            $value = array_key_exists('amount', $line)
                ? $fmt((float) $line['amount'])
                : htmlspecialchars((string) ($line['value'] ?? ''), ENT_QUOTES, 'UTF-8');
            $statusClass = $line['name'] === 'Balance Status' && !$data['is_balanced'] ? ' class="subtotal-row"' : '';
            $summaryHtml .= '<tr' . $statusClass . '><td>' . htmlspecialchars((string) $line['name'], ENT_QUOTES, 'UTF-8') . '</td><td class="text-right">' . $value . '</td></tr>';
        }
        $html = '<html><head>' . getReportCss() . '</head><body><div class="footer">Generated automatically by ' . $safeName . ' Accounting System on ' . date('m/d/Y h:i A') . '</div>'
            . '<table class="header-table"><tr><td><div class="brand-title">' . $safeName . '</div><div class="brand-sub">Financial Position</div></td>'
            . '<td><div class="report-title">Balance Sheet</div><div class="report-meta">As of ' . date('m/d/Y', strtotime($asOf)) . '</div></td></tr></table>'
            . '<div class="section-header">Assets</div>'
            . $section('Current Assets', $data['current_assets'], 'Total Current Assets', $data['total_current_assets'])
            . $section('Non-Current Assets', $data['non_current_assets'], 'Total Non-Current Assets', $data['total_non_current_assets'])
            . $grandTotal('TOTAL ASSETS', $data['total_assets'])
            . '<div class="section-header">Liabilities</div>'
            . $section('Current Liabilities', $data['current_liabilities'], 'Total Current Liabilities', $data['total_current_liabilities'])
            . $section('Non-Current Liabilities', $data['non_current_liabilities'], 'Total Non-Current Liabilities', $data['total_non_current_liabilities'])
            . $grandTotal('TOTAL LIABILITIES', $data['total_liabilities'])
            . $section("Owner's Equity", $data['equity'], "TOTAL OWNER'S EQUITY", $data['total_equity'])
            . $grandTotal("TOTAL LIABILITIES & OWNER'S EQUITY", $data['total_liabilities_and_equity'])
            . '<div class="section-header">Summary</div><table class="data-table"><colgroup><col style="width:70%"><col style="width:30%"></colgroup><tbody>' . $summaryHtml . '</tbody></table></body></html>';
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream('Balance_Sheet_' . $asOf . '.pdf', ['Attachment' => $isPreview ? 0 : 1]);
        exit;

        $asOf = getReportAsOfDate($_GET);
        $fmt = fn($n) => '&#8369;' . number_format((float) $n, 2);

        $balancesAsOf = array_values(array_filter(
            getAccountBalancesAsOf($pdo, $asOf),
            static fn(array $account): bool => (int) $account['is_active'] === 1
        ));
        $fetchGroup = function (string $typeName) use ($balancesAsOf): array {
            return array_values(array_map(
                static fn(array $account): array => ['name' => $account['name'], 'balance' => $account['balance']],
                array_filter(
                    $balancesAsOf,
                    static fn(array $account): bool => $account['type_name'] === $typeName
                        && abs((float) $account['balance']) >= 0.005
                )
            ));
        };

        $assets = $fetchGroup('Asset');
        $liabilities = $fetchGroup('Liability');
        $equity = $fetchGroup('Equity');

        $totalAssets = array_sum(array_column($assets, 'balance'));
        $totalLiabilities = array_sum(array_column($liabilities, 'balance'));
        $totalEquityRecorded = array_sum(array_column($equity, 'balance'));

        // Retained earnings up to as_of date
        // Retained earnings up to as_of date
        $retainedEarnings = array_sum(array_column($fetchGroup('Revenue'), 'balance'))
            - array_sum(array_column($fetchGroup('Expense'), 'balance'));
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
            <div class='footer'>Generated automatically by {$safeBusinessName} Accounting System on " . date('m/d/Y h:i A') . "</div>
            <table class='header-table'>
                <tr>
                    <td style='vertical-align: top;'>
                        <div class='brand-title'>{$safeBusinessName}</div>
                        <div class='brand-sub'>Financial Position</div>
                    </td>
                    <td style='vertical-align: top;'>
                        <div class='report-title'>Balance Sheet</div>
                        <div class='report-meta'>As of " . date('m/d/Y', strtotime($asOf)) . "</div>
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
        $asOf = getReportAsOfDate($_GET);
        $rows = array_values(array_filter(
            getAccountBalancesAsOf($pdo, $asOf),
            static fn(array $account): bool => (int) $account['is_active'] === 1
                && abs((float) $account['total_debit'] - (float) $account['total_credit']) >= 0.005
        ));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Trial Balance');
        $sheet->setShowGridLines(true);

        // Header Banner
        $sheet->setCellValue('A1', mb_strtoupper($businessName));
        $sheet->setCellValue('A2', 'TRIAL BALANCE REPORT');
        $sheet->setCellValue('A3', 'As of ' . date('m/d/Y', strtotime($asOf)));

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
    $receiptContactDetails = array_filter([
        $businessAddress,
        $businessContact,
    ], static fn(string $detail): bool => $detail !== '');
    $receiptContactHtml = $receiptContactDetails
        ? "<div class='brand-sub'>" . htmlspecialchars(implode(' · ', $receiptContactDetails), ENT_QUOTES, 'UTF-8') . "</div>"
        : '';
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
        <div class='footer'>Generated by {$safeBusinessName} System on " . date('m/d/Y h:i A') . "</div>
        <table class='header-table'>
            <tr>
                <td style='vertical-align: top;'>
                    <div class='brand-title'>{$safeBusinessName}</div>
                    <div class='brand-sub'>Invoice</div>
                    {$receiptContactHtml}
                </td>
                <td style='vertical-align: top;'>
                    <div class='report-title'>Invoice</div>
                    <div class='report-meta'>Date: " . date('m/d/Y', strtotime($journal['transaction_date'])) . "</div>
                </td>
            </tr>
        </table>

        <div style='margin: 15px 0; font-size: 13px;'>
            <strong>Description:</strong> " . htmlspecialchars($journal['description'] ?? 'N/A') . "<br>
            <strong>Invoice No:</strong> " . htmlspecialchars($journal['reference_no'] ?? '—', ENT_QUOTES, 'UTF-8') . "<br>
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

    $isPreview = isset($_GET['preview']) && $_GET['preview'] == '1';
    $invoiceNumber = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($journal['reference_no'] ?? ('INV-' . $id)));
    $dompdf->stream('Invoice_' . $invoiceNumber . '.pdf', ['Attachment' => $isPreview ? 0 : 1]);
    exit;
    
        
    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid export action']);
        exit;
}
