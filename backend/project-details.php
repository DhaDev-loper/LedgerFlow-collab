<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';

$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT) ?: 0;
$canManageProjects = in_array($_SESSION['role'] ?? '', ['admin', 'accountant', 'owner'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Profit &amp; Loss — WRS Accounting</title>
    <script src="../frontend/js/theme.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../frontend/css/dashboard-style.css?v=mega-nav-1">
    <link rel="stylesheet" href="../frontend/css/report-statements.css">
    <link rel="stylesheet" href="../frontend/css/project-details.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>
<div class="app-shell">
    <main class="main-content">
        <div class="content-area">
            <?php require __DIR__ . '/includes/navbar.php'; ?>

            <div class="project-report-toolbar">
                <a class="quick-action-btn" href="reports.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Reports</a>
                <div class="d-flex gap-2">
                    <?php if ($canManageProjects): ?>
                    <button class="quick-action-btn" id="projectStatusButton" type="button" hidden></button>
                    <?php endif; ?>
                    <button class="quick-action-btn primary" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print / Save PDF</button>
                </div>
            </div>

            <article class="income-statement-paper project-statement" id="projectStatement" aria-live="polite">
                <table class="statement-header-table"><tbody><tr>
                    <td>
                        <div class="statement-brand-title" id="businessName">WRS Accounting / KoboSocial</div>
                        <div class="statement-brand-sub">Project Financial Statement</div>
                    </td>
                    <td>
                        <div class="statement-report-title">Project Income Statement</div>
                        <div class="statement-report-meta">Date Generated: <time id="generatedAt">—</time></div>
                    </td>
                </tr></tbody></table>

                <div class="project-report-meta">
                    <div class="project-meta-main"><span>Project</span><strong id="projectTitle">Loading project...</strong></div>
                    <div><span>Client</span><strong id="clientName">—</strong></div>
                    <div><span>Status</span><strong><span id="projectStatus" class="project-status">—</span></strong></div>
                    <div><span>Date Created</span><strong><time id="projectCreatedAt">—</time></strong></div>
                </div>

                <div class="statement-section-header">Revenue</div>
                <table class="statement-data-table">
                    <thead><tr><th>Income Account</th><th class="text-right" style="width: 30%;">Amount</th></tr></thead>
                    <tbody id="revenueLines"><tr><td colspan="2" class="statement-empty">Loading...</td></tr></tbody>
                    <tbody><tr class="statement-subtotal-row"><td>Total Revenue</td><td class="text-right" id="totalRevenue">₱0.00</td></tr></tbody>
                </table>

                <div class="statement-section-header">Direct Costs &amp; Operating Expenses</div>
                <table class="statement-data-table">
                    <thead><tr><th>Expense Account</th><th class="text-right" style="width: 30%;">Amount</th></tr></thead>
                    <tbody id="expenseLines"><tr><td colspan="2" class="statement-empty">Loading...</td></tr></tbody>
                    <tbody><tr class="statement-subtotal-row"><td>Total Expenses</td><td class="text-right" id="totalExpense">₱0.00</td></tr></tbody>
                </table>

                <table class="statement-grand-total-table"><tbody><tr class="statement-grand-total-row">
                    <td>Net Project Profit / (Loss)</td><td class="text-right project-net-amount" id="netIncome">₱0.00</td>
                </tr></tbody></table>
                <div class="project-margin-summary">
                    <span>Net Profit Margin</span>
                    <strong id="netMargin">0.00%</strong>
                </div>
                <div class="statement-footer">Amounts are based on posted journal entries assigned to this project.</div>
            </article>
            <div id="projectReportError" class="alert alert-danger mt-3" role="alert" hidden></div>
        </div>
    </main>
</div>
<script>
    window.PROJECT_ID = <?= json_encode($projectId) ?>;
    window.CAN_MANAGE_PROJECTS = <?= json_encode($canManageProjects) ?>;
</script>
<script src="../frontend/js/project-details.js"></script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>
