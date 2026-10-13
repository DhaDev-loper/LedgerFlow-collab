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
      <title>Transaction</title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <link rel="stylesheet" href="../frontend/css/dashboard-style.css">
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>
      <div class="app-shell">
            

           <div class ="main-content">
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
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                              <div>
                                    <h1 class="h4 mb-1">Transactions</h1>
                              
                                    <p class="small mb-0" style="color: var(--dark-text-muted);">Record sale and expenses quickly.</p>
                              <?php if ($canCreate): ?>
                                    <div class="d-flex gap-2">
                                          <button class="quick-action-btn primary" data-bs-toggle="modal" data-bs-target="#quickSaleModal">+ Record Sale</button>
                                          <button class="quick-action-btn" data-bs-toggle="modal" data-bs-target="#quickExpenseModal">Record Expense</button>
                                    </div>
                              <?php endif; ?>
                              </div>
                        
                        </div>
                        
                              <div class="panel">
                              
                                    <div class="table-responsive">
                                          <table class="table table-transactions mb-0">
                                                <thead>
                                                <tr>
                                                      <th style="width:40px;"></th>
                                                      <th>Description</th>
                                                      <th>Category</th>
                                                      <th>Date</th>
                                                      <th class="text-end">Amount</th>
                                                      
                                                </tr>
                                                </thead>
                                                <tbody id="recentTransactionsBody">
                                                <tr>
                                                      <td colspan="5" class="text-center py-4" style="color: var(--dark-text-muted);">Loading transactions...</td>
                                                </tr>
                                                </tbody>
                                          </table>
                                    </div>
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
    <a href="transaction.php" class="bottom-nav-item active">
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

 <!-- Quick Sale Modal -->
            <div class="modal fade" id="quickSaleModal" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog">
                        <div class="modal-content modal-dark">
                              <div class="modal-header">
                              <h5 class="modal-title">Record a Sale</h5>
                              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                              </div>
                              <form id="quickSaleForm">
                              <div class="modal-body">

                                    <div class="mb-3">
                                          <label class="form-label small">Amount</label>
                                          <input type="number" step="0.01" min="0.01" class="form-control form-control-dark" id="saleAmount" required>
                                    </div>

                                    <div class="mb-3">
                                          <label class="form-label small">Received Into</label>
                                          <select class="form-select form-control-dark" id="saleReceivedInto" required></select>
                                          <div class="form-text" style="color: var(--dark-text-muted);">Which account the money landed in (e.g. Cash Drawer)</div>
                                    </div>

                                    <div class="mb-3">
                                          <label class="form-label small">Revenue Type</label>
                                          <select class="form-select form-control-dark" id="saleRevenueAccount" required></select>
                                    </div>

                                    <div class="mb-3">
                                          <label class="form-label small">Description (optional)</label>
                                          <input type="text" class="form-control form-control-dark" id="saleDescription" placeholder="e.g. Walk-in sale">
                                    </div>

                                    <div id="quickSaleError" class="alert alert-danger py-2 px-3 small d-none"></div>
                              </div>
                              <div class="modal-footer">
                                    <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn quick-action-btn primary" id="saveQuickSaleBtn">Record Sale</button>
                              </div>
                              </form>
                        </div>
                  </div>
            </div>

      <!-- Quick Expense Modal -->
      <div class="modal fade" id="quickExpenseModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                  <div class="modal-content modal-dark">
                        <div class="modal-header">
                        <h5 class="modal-title">Record an Expense</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="quickExpenseForm">
                        <div class="modal-body">

                              <div class="mb-3">
                                    <label class="form-label small">Amount</label>
                                    <input type="number" step="0.01" min="0.01" class="form-control form-control-dark" id="expenseAmount" required>
                              </div>

                              <div class="mb-3">
                                    <label class="form-label small">Paid From</label>
                                    <select class="form-select form-control-dark" id="expensePaidFrom" required></select>
                                    <div class="form-text" style="color: var(--dark-text-muted);">Which account the money came out of</div>
                              </div>

                              <div class="mb-3">
                                    <label class="form-label small">Expense Type</label>
                                    <select class="form-select form-control-dark" id="expenseAccount" required></select>
                              </div>

                              <div class="mb-3">
                                    <label class="form-label small">Description (optional)</label>
                                    <input type="text" class="form-control form-control-dark" id="expenseDescription" placeholder="e.g. Electricity bill">
                              </div>

                              <div id="quickExpenseError" class="alert alert-danger py-2 px-3 small d-none"></div>
                        </div>
                        <div class="modal-footer">
                              <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                              <button type="submit" class="btn quick-action-btn primary" id="saveQuickExpenseBtn">Record Expense</button>
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
      

      
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../frontend/js/dashboard.js"></script>
<script>
    window.API_URL    = '../backend/routes/journal.php'; // Ensure correct path to your backend endpoint
    window.CAN_CREATE = <?= json_encode(!empty($_SESSION['user_id'])) ?>;
    window.USER_ROLE  = <?= json_encode(strtolower($_SESSION['role'] ?? 'staff')) ?>;
</script>
<script src="../frontend/js/journal-entries.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>


</body>
</html>