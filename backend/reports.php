<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';
$userName = $_SESSION['full_name'] ?? $_SESSION['role'] ?? 'User';
$userRole = $_SESSION['role'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — KoboSocial</title>
<script src="../frontend/js/theme.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../frontend/css/dashboard-style.css?v=mega-nav-1">
<link rel="stylesheet" href="../frontend/css/report-statements.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>

<div class="app-shell">


    <div class="main-content">
        <div class="content-area">
            <?php require __DIR__ . '/includes/navbar.php'; ?>

           <div class="mb-4">
             <h1 class="h4 mb-1">Reports</h1>
             <p class="small mb-0" style="color: var(--dark-text-muted);">Financial reports from journal activity, including cash inflows and outflows.</p>
            </div>

          <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
              <div class="btn-group gap-2" role="group">
                    <button type="button" class="quick-action-btn primary" id="tabIncomeStatement">Income Statement</button>
                    <button type="button" class="quick-action-btn" id="tabBalanceSheet">Balance Sheet</button>
                    <button type="button" class="quick-action-btn" id="tabTrialBalance">Trial Balance</button>
                    <button type="button" class="quick-action-btn" id="tabCashFlowAnalysis">Cash Flow Analysis</button>
                    <button type="button" class="quick-action-btn" id="tabCashFlowStatement">Cash Flow Statement</button>
                </div>

            <div class="d-flex flex-wrap align-items-end gap-2">
                   <div id="incomeStatementDateControls" class="d-flex gap-2 align-items-end">
                         <div>
                               <label for="dateFrom" class="form-label small mb-1">From</label>
                               <input type="date" class="filter-input" id="dateFrom">
                           </div>
                          <div>
                                <label for="dateTo" class="form-label small mb-1">To</label>
                               <input type="date" class="filter-input" id="dateTo">
                          </div>
                    </div>
                    <div id="balanceSheetDateControl" class="d-none">
                        <label for="balanceSheetAsOfDate" class="form-label small mb-1">Balance Sheet as of</label>
                        <input type="date" class="filter-input" id="balanceSheetAsOfDate">
                    </div>
                    <div id="trialBalanceDateControl" class="d-none">
                        <label for="trialBalanceAsOfDate" class="form-label small mb-1">Trial Balance as of</label>
                        <input type="date" class="filter-input" id="trialBalanceAsOfDate">
                    </div>
                    <div id="cashFlowAnalysisDateControls" class="d-none d-flex gap-2 align-items-end">
                        <div>
                            <label for="cashFlowAnalysisDateFrom" class="form-label small mb-1">From</label>
                            <input type="date" class="filter-input" id="cashFlowAnalysisDateFrom">
                        </div>
                        <div>
                            <label for="cashFlowAnalysisDateTo" class="form-label small mb-1">To</label>
                            <input type="date" class="filter-input" id="cashFlowAnalysisDateTo">
                        </div>
                    </div>
                    <div id="cashFlowStatementDateControls" class="d-none d-flex gap-2 align-items-end">
                        <div>
                            <label for="cashFlowDateFrom" class="form-label small mb-1">From</label>
                            <input type="date" class="filter-input" id="cashFlowDateFrom">
                        </div>
                        <div>
                            <label for="cashFlowDateTo" class="form-label small mb-1">To</label>
                            <input type="date" class="filter-input" id="cashFlowDateTo">
                        </div>
                    </div>
             <button type="button" class="quick-action-btn d-none" id="printIncomeStatementBtn">Print</button>
             <button type="button" class="quick-action-btn d-none" id="printBalanceSheetBtn">Print</button>
             <button type="button" class="quick-action-btn d-none" id="printTrialBalanceBtn">Print</button>
             <button type="button" class="quick-action-btn d-none" id="printCashFlowBtn">Print</button>
             <button type="button" class="quick-action-btn d-none" id="exportCashFlowExcelBtn">Export Excel</button>
             <button type="button" id="exportBtn" class="quick-action-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    <span id="exportButtonLabel">Export</span>
                </button>
            </div>
      </div>

<div class="panel mb-3">
    <div class="panel-body compact d-flex flex-wrap align-items-end justify-content-between gap-3">
        <div>
            <label for="projectReportSelect" class="form-label small mb-1">Project Profit &amp; Loss</label>
            <select class="form-select form-control-dark" id="projectReportSelect" style="min-width: min(320px, 80vw);">
                <option value="">Loading active projects...</option>
            </select>
        </div>
        <button type="button" class="quick-action-btn primary" id="openProjectReportBtn" disabled>
            <i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> Open Project Report
        </button>
    </div>
</div>

<div id="reportContent">
    <p class="text-center py-5" style="color: var(--dark-text-muted);">Reports update automatically when you change the selected dates.</p>
</div>

        </div>
    </div>
</div>

<!-- modal -->
 <!-- PDF Preview Modal -->
<div id="pdfPreviewModal" class="pdf-modal-overlay" style="display: none;">
    <div class="pdf-modal-container">
        <div class="pdf-modal-header">
            <div class="pdf-modal-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                </svg>
                <span id="pdfModalTitle">Document Preview</span>
            </div>
            <div class="pdf-modal-actions">
                <a id="pdfDownloadBtn" href="#" class="btn-modal-download" download>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    Download File
                </a>
                <button type="button" id="closePdfModal" class="btn-modal-close">&times;</button>
            </div>
        </div>
        <div class="pdf-modal-body">
            <div id="pdfLoadingSpinner" class="pdf-spinner">Loading preview...</div>
            <iframe id="pdfPreviewFrame" src="" frameborder="0"></iframe>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="../frontend/js/reports.js"></script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>