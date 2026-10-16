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
<title>Reports — WRS Accounting</title>
<script src="../frontend/js/theme.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../frontend/css/dashboard-style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>

<div class="app-shell">


    <div class="main-content">
        <div class="content-area">
            <!-- Global Top Navigation Bar -->
            <div class="d-flex justify-content-between align-items-center mb-4 pb-2">
                <!-- Top-Left: Logo -->
                <div class="d-flex align-items-center">
                    <img src="../frontend/assets/Logo-Kobo-Light.png" alt="KoboSocial Logo" style="height: 42px; width: auto; object-fit: contain;">
                </div>

                <!-- Top-Right: User Profile -->
                <div class="d-flex align-items-center gap-2 px-3 py-1.5 rounded-pill" style="background: rgba(255, 255, 255, 0.04); border: 1px solid var(--dark-border, rgba(255, 255, 255, 0.08));">
                    <i class="bi bi-person-circle fs-5 text-gradient-neon"></i>
                    <span class="fw-semibold small text-light"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
           <div class="mb-4">
             <h1 class="h4 mb-1">Reports</h1>
             <p class="small mb-0" style="color: var(--dark-text-muted);">Financial reports from journal activity, including cash inflows and outflows.</p>
            </div>

          <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
              <div class="btn-group gap-2" role="group">
                    <button type="button" class="quick-action-btn primary" id="tabIncomeStatement">Income Statement</button>
                    <button type="button" class="quick-action-btn" id="tabBalanceSheet">Balance Sheet</button>
                    <button type="button" class="quick-action-btn" id="tabTrialBalance">Trial Balance</button>
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
             <button class="quick-action-btn" id="generateReportBtn">Generate</button>
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

<div id="reportContent">
    <p class="text-center py-5" style="color: var(--dark-text-muted);">Reports load automatically when selected.</p>
</div>

        </div>
    </div>
</div>
          <nav class="bottom-nav">
    <!-- LEFT SIDE (4 items) -->
    <a href="dashboard.php" class="bottom-nav-item">
        <i class="bi bi-speedometer2"></i>
        <span>Dashboard</span>
    </a>
    <a href="transaction.php" class="bottom-nav-item ">
        <i class="bi bi-arrow-left-right"></i>
        <span>Transact</span>
    </a>
    <a href="accounts.php" class="bottom-nav-item">
        <i class="bi bi-wallet2"></i>
        <span>Accounts</span>
    </a>
    <a href="clients.php" class="bottom-nav-item">
        <i class="bi bi-people-fill"></i>
        <span>Clients</span>
    </a>


    
    
    <!-- RIGHT SIDE (4 items balanced) -->
    <div class="bottom-nav-expandable " id="journalExpandable">
        <button type="button" class="bottom-nav-item nav-trigger" id="journalTrigger">
            <i class="bi bi-journal-text"></i>
            <span>Journal</span>
        </button>

        <div class="expandable-content active">
            <a href="journal-entries.php" class="sub-nav-item">
                <i class="bi bi-journal-check"></i>
                <span>Entries</span>
            </a>
            <a href="general-ledger.php" class="sub-nav-item">
                <i class="bi bi-book"></i>
                <span>Ledger</span>
            </a>
            <a href="cashflow.php" class="sub-nav-item">
                <i class="bi bi-cash-stack"></i>
                <span>Cash Flow</span>
            </a>
        </div>
    </div>
     <?php if (in_array($_SESSION['role'], ['admin', 'owner'], true)): ?>
            <a href="user-management.php" class="bottom-nav-item ">
                <i class="bi bi-people"></i>
                <span>Users</span>
            </a>
     <?php endif; ?>
    <a href="reports.php" class="bottom-nav-item active" aria-current="page">
        <i class="bi bi-bar-chart"></i>
        <span>Reports</span>
    </a>

    <a href="settings.php" class="bottom-nav-item">
        <i class="bi bi-gear"></i>
        <span>Settings</span>
    </a>

    <div class="bottom-nav-divider"></div>
    <a href="#" class="bottom-nav-item" id="logoutBtn">
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>
</nav>


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
<script src="../frontend/js/reports.js"></script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>