<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';

$userRole = $_SESSION['role'];
$canCreate = in_array($userRole, ['admin', 'accountant', 'owner'], true);
$userName = $_SESSION['full_name'] ?? $_SESSION['role'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Journal Entries — WRS Accounting</title>
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
            <!-- PAGE HEADER -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h1 class="h4 mb-1">Journal Entries</h1>
                    <p class="small mb-0" style="color: var(--dark-text-muted);">Every recorded transaction, balanced by debit and credit.</p>
                </div>
                <?php if ($userRole === 'owner'): ?>
                    <button class="quick-action-btn position-relative" id="pendingApprovalsBtn" data-bs-toggle="modal" data-bs-target="#pendingApprovalsModal">
                        <i class="bi bi-bell-fill me-1"></i> Pending Approvals
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" id="pendingRequestsBadge">0</span>
                    </button>
                <?php endif; ?>
                <?php if ($canCreate): ?>
                <button class="quick-action-btn primary" data-bs-toggle="modal" data-bs-target="#entryModal" id="newEntryBtn">
                    <i class="bi bi-plus-lg me-1"></i> New Entry
                </button>
                <?php endif; ?>
            </div>

            <!-- FILTERS PANEL -->
            <div class="panel mb-4">
                <div class="panel-body compact">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Search</label>
                            <input type="text" class="filter-input-search w-100" id="filterSearch" placeholder="Search description, ref #, or creator..." title="Search by description, reference number, or creator name">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">From</label>
                            <input type="date" class="filter-input w-100" id="filterDateFrom">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">To</label>
                            <input type="date" class="filter-input w-100" id="filterDateTo">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Type</label>
                            <select class="form-select form-control-dark" id="filterType">
                                <option value="">All Types</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Status</label>
                            <select class="form-select form-control-dark" id="filterStatus">
                                <option value="active">Active</option>
                                <option value="voided">Voided</option>
                                <option value="all">All</option>
                            </select>
                        </div>
                        <div class="col-md-2 text-end">
                            <button type="button" class="btn quick-action-btn w-100" id="clearFiltersBtn">
                                Clear
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MAIN JOURNAL & SUMMARY GRID -->
            <div class="row g-4">
                <!-- JOURNAL TABLE PANEL -->
                <div class="col-lg-8">
                    <div class="panel">
                        <div class="table-responsive journal-table-scroll">
                            <table class="table table-transactions align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 18%;">Date & Ref</th>
                                        <th style="width: 35%;">Description & Created By</th>
                                        <th style="width: 17%;">Type</th>
                                        <th class="text-end" style="width: 18%;">Amount</th>
                                        <?php if ($canCreate): ?><th class="text-end" style="width: 12%;">Actions</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody id="journalTableBody">
                                    <tr>
                                        <td colspan="<?= $canCreate ? '5' : '4' ?>" class="text-center py-4" style="color: var(--dark-text-muted);">
                                            Loading journal entries...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- PAGINATION FOOTER -->
                        <div class="table-footer d-flex justify-content-between align-items-center flex-wrap gap-2 p-3" style="border-top: 1px solid var(--dark-border);">
                            <div class="d-flex align-items-center flex-wrap gap-3">
                                <small id="journalPageInfo" style="color: var(--dark-text-muted);"></small>
                                <label for="journalRowsPerPage" class="d-flex align-items-center gap-2 small mb-0" style="color: var(--dark-text-muted);">
                                    Show
                                    <select id="journalRowsPerPage" class="form-select form-select-sm form-control-dark" style="width: auto;">
                                        <option value="10" selected>10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                    </select>
                                    entries
                                </label>
                            </div>
                            <nav aria-label="Journal entries pagination">
                                <ul id="journalPagination" class="pagination pagination-sm mb-0"></ul>
                            </nav>
                        </div>
                    </div>
                </div>

                <!-- SIDE SUMMARY PANEL -->
                <div class="col-lg-4">
                    <div class="panel receipt-card">
                        <div class="panel-header">
                            <h2 class="h6 mb-0">Summary</h2>
                        </div>
                        <div class="panel-body compact">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="small" style="color: var(--dark-text-muted);">Entries Shown</span>
                                <span class="fw-semibold" id="summaryCount">0</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="small" style="color: var(--dark-text-muted);">Total Inflow</span>
                                <span class="money fw-semibold" style="color: #4ADE80;" id="summaryInflow">₱0.00</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="small" style="color: var(--dark-text-muted);">Total Outflow</span>
                                <span class="money fw-semibold" style="color: #F87171;" id="summaryOutflow">₱0.00</span>
                            </div>
                            <hr style="border-color: var(--dark-border);">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold">Net Total</span>
                                <span class="money fw-bold fs-5" id="summaryNet">₱0.00</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- BOTTOM NAV -->
<nav class="bottom-nav">
    <!-- LEFT SIDE (4 items) -->
    <a href="dashboard.php" class="bottom-nav-item">
        <i class="bi bi-speedometer2"></i>
        <span>Dashboard</span>
    </a>
    <a href="transaction.php" class="bottom-nav-item">
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

    <!-- DEAD-CENTER FLOATING SCANNER BUTTON -->
    <button type="button" class="bottom-nav-scan-btn" id="triggerScanModalBtn" data-bs-toggle="modal" data-bs-target="#receiptScannerModal" title="Scan / Import Receipt">
        <div class="scan-btn-icon">
            <i class="bi bi-qr-code-scan"></i>
        </div>
        <span>Scan</span>
    </button>
    
    <!-- RIGHT SIDE (4 items balanced) -->
    <div class="bottom-nav-expandable active" id="journalExpandable">
        <button type="button" class="bottom-nav-item nav-trigger" id="journalTrigger">
            <i class="bi bi-journal-text"></i>
            <span>Journal</span>
        </button>

        <div class="expandable-content active">
            <a href="journal-entries.php" class="sub-nav-item active">
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
    <a href="reports.php" class="bottom-nav-item">
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

<?php if ($canCreate): ?>
<!-- NEW ENTRY MODAL -->
<div class="modal fade" id="entryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title">New Journal Entry</h5>
                <div class="btn-group me-5" role="group">
                    <button type="button" class="quick-action-btn primary" id="modeManualBtn">Manual</button>
                    <button type="button" class="quick-action-btn" id="modeAutoBtn">Auto</button>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="entryForm">
                <div class="modal-body">

                    <!-- AUTO MODE TEMPLATES -->
                    <div id="autoModeSection" class="mb-3 d-none">
                        <label class="form-label small">Transaction Template</label>
                        <select class="form-select form-control-dark" id="templateSelect">
                            <option value="">-- Select a transaction type --</option>
                        </select>
                        <div class="form-text" style="color: var(--dark-text-muted);">
                            Pick what happened — the correct accounts fill in automatically.
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small">Transaction Type</label>
                            <select class="form-select form-control-dark" id="transactionTypeId" name="transaction_type_id" required>
                                <!-- injected by journal-entries.js -->
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Date</label>
                            <input type="date" class="form-control form-control-dark" id="transactionDate" name="transaction_date" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Customer / Vendor (required for A/R or A/P)</label>
                            <select class="form-select form-control-dark" id="clientIdSelect" name="client_id">
                                <option value="">-- Optional unless entering an invoice or bill --</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Description</label>
                        <input type="text" class="form-control form-control-dark" id="description" name="description" placeholder="e.g. Walk-in sale, Electricity bill" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small">Reference No. (optional)</label>
                            <input type="text" class="form-control form-control-dark" id="referenceNo" name="reference_no">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Category (optional)</label>
                            <select class="form-select form-control-dark" id="categoryId" name="category_id">
                                <option value="">-- None --</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Due Date (required for invoices and bills)</label>
                            <input type="date" class="form-control form-control-dark" id="dueDate" name="due_date">
                        </div>
                    </div>

                    <hr style="border-color: var(--dark-border);">

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label small mb-0 fw-semibold">Debit Lines <span class="text-secondary">(maximum 10)</span></label>
                        <button type="button" class="table-action-btn" id="addDebitLineBtn">+ Add Debit Line</button>
                    </div>
                    <div id="debitLinesContainer"></div>

                    <hr style="border-color: var(--dark-border);">

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label small mb-0 fw-semibold">Credit Lines <span class="text-secondary">(maximum 10)</span></label>
                        <button type="button" class="table-action-btn" id="addCreditLineBtn">+ Add Credit Line</button>
                    </div>
                    <div id="creditLinesContainer"></div>

                    <div class="d-flex justify-content-end gap-4 mt-3 small">
                        <div>Total Debits: <span class="money fw-semibold" id="totalDebitsDisplay">0.00</span></div>
                        <div>Total Credits: <span class="money fw-semibold" id="totalCreditsDisplay">0.00</span></div>
                        <div id="balanceStatusDisplay" class="fw-semibold"></div>
                    </div>
                    <p class="small mt-2 mb-0" style="color: var(--dark-text-muted);">Enter full amounts or use k, m, or b (for example, 1,000,000 or 1m). Use the button beside an amount to switch its display.</p>

                    <div id="entryFormError" class="alert alert-danger py-2 px-3 small mt-3 d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn quick-action-btn primary" id="saveEntryBtn">Save Entry</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- VIEW DETAILS MODAL -->
<div class="modal fade" id="viewEntryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title">Entry Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewEntryBody">
                <p class="text-center py-4" style="color: var(--dark-text-muted);">Loading...</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn quick-action-btn" id="printReceiptBtn">Print Receipt</button>
                <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- PRINT RECEIPT PREVIEW MODAL -->
<div class="modal fade" id="receiptPreviewModal" tabindex="-1" data-bs-backdrop="false" data-bs-scroll="true" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" id="receiptPreviewDialog">
        <div class="modal-content style-dark-modal border border-secondary shadow-lg">
            <div class="modal-header d-flex justify-content-between align-items-center py-2 px-3 border-bottom border-secondary">
                <h6 class="modal-title m-0 fw-semibold">Receipt Preview</h6>
                <div>
                    <button type="button" class="btn btn-sm btn-primary py-1 px-2 me-1" id="downloadReceiptBtn">
                        <i class="bi bi-download"></i> Download
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-0" style="height: 480px;">
                <iframe id="receiptPreviewFrame" style="width: 100%; height: 100%; border: none;"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- EDIT ENTRY MODAL -->
<div class="modal fade" id="editEntryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title">Edit Entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editEntryForm">
                <div class="modal-body">
                    <input type="hidden" id="editJournalId">

                    <div class="mb-3">
                        <label class="form-label small">Description</label>
                        <input type="text" class="form-control form-control-dark" id="editDescription" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Reference No. (optional)</label>
                        <input type="text" class="form-control form-control-dark" id="editReferenceNo">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Category (optional)</label>
                        <select class="form-select form-control-dark" id="editCategoryId">
                            <option value="">-- None --</option>
                        </select>
                    </div>

                    <p class="small mb-0" style="color: var(--dark-text-muted);">
                        Amount and accounts cannot be edited. If the amount was wrong, delete this entry and create a new one instead.
                    </p>

                    <div id="editEntryError" class="alert alert-danger py-2 px-3 small mt-3 d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn quick-action-btn primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Request Deletion Modal (For Admin / Accountant) -->
<div class="modal fade" id="requestDeletionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Request Entry Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="requestDeletionForm">
                <input type="hidden" id="deleteJournalId" name="journal_id">
                <div class="modal-body">
                    <p class="small text-secondary mb-3">
                        This entry cannot be deleted directly. Submitting this request will send it to the <strong>Owner</strong> for approval.
                    </p>
                    <div class="mb-3">
                        <label for="deleteReason" class="form-label small fw-semibold text-light">Reason for Deletion <span class="text-danger">*</span></label>
                        <textarea class="form-control form-control-dark" id="deleteReason" name="reason" rows="3" placeholder="e.g. Duplicate entry, incorrect client billing..." required></textarea>
                    </div>
                    <div id="requestDeletionError" class="alert alert-danger py-2 px-3 small d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="submitDeleteReqBtn">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- RECEIPT SCANNER & EDIT MODAL -->
<div class="modal fade" id="receiptScannerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt me-2 text-primary"></i>Scan & Verify Receipt</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">

                <!-- STEP 1: CAPTURE / UPLOAD SELECTION PANEL -->
                <div id="scanStepSelection" class="row g-4 align-items-center">
                    
                    <!-- LEFT: QR HANDSHAKE FOR PHONE CAMERA -->
                    <div class="col-md-6 border-end border-secondary pe-md-4">
                        <div class="text-center p-3">
                            <h6 class="fw-bold mb-2 text-light"><i class="bi bi-qr-code-scan me-2 text-primary"></i>Option 1: Scan with Phone Camera</h6>
                            <p class="small text-secondary mb-3">Point your phone's native camera app at this QR code to capture a physical receipt on your desk.</p>
                            
                            <div class="d-flex justify-content-center my-3">
                                <div id="qrCodeContainer" class="bg-white p-3 rounded shadow-sm"></div>
                            </div>
                            
                            <div class="d-flex align-items-center justify-content-center text-primary small">
                                <div class="spinner-grow spinner-grow-sm me-2" role="status"></div>
                                <span>Waiting for phone camera upload...</span>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: DIRECT LOCAL FILE DROPZONE -->
                    <div class="col-md-6 ps-md-4">
                        <div class="text-center p-3">
                            <h6 class="fw-bold mb-2 text-light"><i class="bi bi-pc-display me-2 text-primary"></i>Option 2: Direct File Upload</h6>
                            <p class="small text-secondary mb-3">Upload a saved receipt photo or PDF directly from your computer or flash drive.</p>
                            
                            <div id="receiptUploadZone" class="border border-dashed border-secondary rounded p-4 text-center style-dropzone mt-3" style="cursor: pointer; background: rgba(255,255,255,0.02);">
                                <i class="bi bi-cloud-arrow-up display-5 text-primary mb-2 d-block"></i>
                                <span class="fw-semibold small d-block">Click or drag receipt photo here</span>
                                <small class="text-secondary">JPG, PNG, WEBP</small>
                                <input type="file" id="receiptFileInput" accept="image/*" class="d-none">
                            </div>
                        </div>
                    </div>

                </div>

                <!-- STEP 2: EDITABLE VERIFICATION FORM (HIDDEN UNTIL SCAN RECEIVED) -->
                <div id="scanStepVerification" class="d-none">
                    <div class="row g-4">
                        
                        <!-- LEFT PANEL: ZOOMABLE RECEIPT PREVIEW -->
                        <div class="col-md-5 border-end border-secondary pe-md-3">
                            <label class="form-label small fw-semibold text-light mb-2">Scanned Receipt Image</label>
                            <div class="bg-black rounded border border-secondary p-2 text-center overflow-auto" style="max-height: 400px;">
                                <img id="scannedImagePreview" src="" alt="Scanned Receipt" class="img-fluid rounded" style="object-fit: contain;">
                            </div>
                        </div>

                        <!-- RIGHT PANEL: EDITABLE DATA FORM -->
                        <div class="col-md-7 ps-md-3">
                            <form id="scannedReceiptForm">
                                <div class="mb-3">
                                    <label class="form-label">Client / Customer (Optional)</label>
                                    <select id="scannedClient" class="form-select">
                                        <option value="">-- Select Client --</option>
                                    </select>
                                </div>
                                <div class="row g-2 mb-2">
                                    <div class="col-md-8">
                                        <label class="form-label small mb-1">Vendor / Description <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-dark" id="scannedVendor" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small mb-1">Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control form-control-dark" id="scannedDate" required>
                                    </div>
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small mb-1">Reference / Invoice #</label>
                                        <input type="text" class="form-control form-control-dark" id="scannedRef">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small mb-1">Total Amount (₱) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" class="form-control form-control-dark" id="scannedAmount" required>
                                    </div>
                                </div>

                                <hr class="border-secondary my-3">

                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label class="form-label small mb-1">Debit Account (Expense / Asset) <span class="text-danger">*</span></label>
                                        <select class="form-select form-control-dark" id="scannedDebitAccount" required></select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small mb-1">Credit Account (Payment Source) <span class="text-danger">*</span></label>
                                        <select class="form-select form-control-dark" id="scannedCreditAccount" required></select>
                                    </div>
                                </div>
                            </form>
                        </div>

                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary d-none" id="saveScannedEntryBtn">
                    <i class="bi bi-check-lg me-1"></i> Save As Journal Entry
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Pending Approvals Modal (For Owner Review) -->
<?php if ($userRole === 'owner'): ?>
<div class="modal fade" id="pendingApprovalsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shield-check me-2 text-warning"></i>Pending Deletion Requests</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
               <div class="wrs-table-container">
    <table class="wrs-table">
        <thead>
            <tr>
                <th>Requested By</th>
                <th>Reason</th>
                <th>Date</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody id="pendingRequestsTableBody">
            <tr>
                <td colspan="4" style="text-align: center; color: #94a3b8; padding: 20px;">Loading pending requests...</td>
            </tr>
        </tbody>
    </table>
</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    window.API_URL    = '../backend/routes/journal.php'; // Ensure correct path to your backend endpoint
    window.CAN_CREATE = <?= json_encode(!empty($_SESSION['user_id'])) ?>;
    window.USER_ROLE  = <?= json_encode(strtolower($_SESSION['role'] ?? 'staff')) ?>;
</script>
<script src="../frontend/js/journal-entries.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>