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
      <script src="../frontend/js/theme.js"></script>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <link rel="stylesheet" href="../frontend/css/dashboard-style.css?v=mega-nav-1">
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>
      <div class="app-shell">
            

           <div class ="main-content">
                  <div class="content-area">
            <?php require __DIR__ . '/includes/navbar.php'; ?>

                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                              <div>
                                    <h1 class="h4 mb-1">Transactions</h1>
                              
                                    <p class="small mb-0" style="color: var(--dark-text-muted);">Record sale and expenses quickly.</p>
                              <?php if ($canCreate): ?>
                                    <div class="d-flex gap-2">
                                          <button class="quick-action-btn primary" data-bs-toggle="modal" data-bs-target="#quickSaleModal">+ Record Sale</button>
                                          <button class="quick-action-btn" data-bs-toggle="modal" data-bs-target="#quickExpenseModal">Record Expense</button>
                                          <button class="quick-action-btn" data-bs-toggle="modal" data-bs-target="#vendorBillModal">Record Vendor Bill</button>
                                          <button class="quick-action-btn" type="button" onclick="openPaymentModal('ar')"><i class="bi bi-cash-coin me-1"></i>Settle A/R or A/P</button>
                                    </div>
                              <?php endif; ?>
                              </div>
                        
                        </div>
                        
                              <div class="panel mb-3">
                                    <div class="panel-body compact">
                                          <div class="row g-2 align-items-end">
                                                <div class="col-12 col-md-5">
                                                      <label for="transactionSearch" class="form-label small mb-1">Search</label>
                                                      <input type="search" class="form-control form-control-dark" id="transactionSearch" placeholder="Description, reference, client, category, or type">
                                                </div>
                                                <div class="col-6 col-md-2">
                                                      <label for="transactionTypeFilter" class="form-label small mb-1">Type</label>
                                                      <select class="form-select form-control-dark" id="transactionTypeFilter">
                                                            <option value="">All types</option>
                                                      </select>
                                                </div>
                                                <div class="col-6 col-md-2">
                                                      <label for="transactionDateFrom" class="form-label small mb-1">From</label>
                                                      <input type="date" class="filter-input w-100" id="transactionDateFrom">
                                                </div>
                                                <div class="col-6 col-md-2">
                                                      <label for="transactionDateTo" class="form-label small mb-1">To</label>
                                                      <input type="date" class="filter-input w-100" id="transactionDateTo">
                                                </div>
                                                <div class="col-6 col-md-1">
                                                      <button type="button" class="quick-action-btn w-100" id="clearTransactionFilters" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></button>
                                                </div>
                                          </div>
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
                                    <div class="table-footer d-flex justify-content-between align-items-center flex-wrap gap-2 p-3" style="border-top: 1px solid var(--dark-border);">
                                          <div class="d-flex align-items-center flex-wrap gap-3">
                                                <small id="dashboardRecentPageInfo" style="color: var(--dark-text-muted);"></small>
                                                <label for="dashboardRecentRowsPerPage" class="d-flex align-items-center gap-2 small mb-0" style="color: var(--dark-text-muted);">
                                                      Show
                                                      <select id="dashboardRecentRowsPerPage" class="form-select form-select-sm form-control-dark" style="width: auto;">
                                                            <option value="10" selected>10</option>
                                                            <option value="25">25</option>
                                                            <option value="50">50</option>
                                                            <option value="100">100</option>
                                                      </select>
                                                      entries
                                                </label>
                                          </div>
                                          <nav aria-label="Transactions pages">
                                                <ul id="dashboardRecentPagination" class="pagination pagination-sm mb-0"></ul>
                                          </nav>
                                    </div>
                         </div>

                </div>
         </div>

  </div>



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
                                          <div class="input-group currency-input-group">
                                                <span class="input-group-text" aria-hidden="true">₱</span>
                                                <input type="text" inputmode="decimal" data-currency-input step="0.01" min="0.01" class="form-control form-control-dark" id="saleAmount" required>
                                          </div>
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
                                    <div class="row g-3">
                                          <div class="col-md-6 mb-3">
                                                <label for="saleProjectClient" class="form-label small">Project Client</label>
                                                <select class="form-select form-control-dark project-client-select" id="saleProjectClient"><option value="">Select a customer</option></select>
                                          </div>
                                          <div class="col-md-6 mb-3">
                                                <label for="saleProjectName" class="form-label small">Project (optional)</label>
                                                <input type="text" class="form-control form-control-dark project-name-input" id="saleProjectName" list="activeProjectNames" maxlength="255" placeholder="Type an event or project name">
                                          </div>
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
            <?php if ($canCreate): ?>
            <div class="modal fade" id="vendorBillModal" tabindex="-1" aria-labelledby="vendorBillModalTitle" aria-hidden="true">
                  <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content modal-dark">
                              <div class="modal-header">
                                    <h5 class="modal-title" id="vendorBillModalTitle">Record Vendor Bill</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                              </div>
                              <form id="vendorBillForm">
                                    <div class="modal-body">
                                          <div class="alert alert-info py-2 small">This records an unpaid bill: debit an expense or non-cash asset and credit Accounts Payable. Use Make Payment (A/P) after paying it.</div>
                                          <div id="vendorBillError" class="alert alert-danger py-2 px-3 small d-none"></div>
                                          <div class="row g-3">
                                                <div class="col-md-6">
                                                      <label for="vendorBillVendor" class="form-label small">Vendor</label>
                                                      <select id="vendorBillVendor" class="form-select form-control-dark" required></select>
                                                </div>
                                                <div class="col-md-6">
                                                      <label for="vendorBillAccount" class="form-label small">Expense / Asset Account</label>
                                                      <select id="vendorBillAccount" class="form-select form-control-dark" required></select>
                                                </div>
                                                <div class="col-md-6">
                                                      <label for="vendorBillPayable" class="form-label small">Accounts Payable</label>
                                                      <select id="vendorBillPayable" class="form-select form-control-dark" required></select>
                                                </div>
                                                <div class="col-md-6">
                                                      <label for="vendorBillAmount" class="form-label small">Amount</label>
                                                      <div class="input-group currency-input-group">
                                                            <span class="input-group-text" aria-hidden="true">₱</span>
                                                            <input type="text" inputmode="decimal" data-currency-input id="vendorBillAmount" class="form-control form-control-dark" min="0.01" step="0.01" required>
                                                      </div>
                                                </div>
                                                <div class="col-md-4">
                                                      <label for="vendorBillDate" class="form-label small">Bill Date</label>
                                                      <input type="date" id="vendorBillDate" class="form-control form-control-dark" required>
                                                </div>
                                                <div class="col-md-4">
                                                      <label for="vendorBillDueDate" class="form-label small">Due Date</label>
                                                      <input type="date" id="vendorBillDueDate" class="form-control form-control-dark" required>
                                                </div>
                                                <div class="col-md-4">
                                                      <label for="vendorBillReference" class="form-label small">Bill / Reference #</label>
                                                      <input type="text" id="vendorBillReference" class="form-control form-control-dark" maxlength="50">
                                                </div>
                                                <div class="col-12">
                                                      <label for="vendorBillDescription" class="form-label small">Description</label>
                                                      <input type="text" id="vendorBillDescription" class="form-control form-control-dark" maxlength="255" required>
                                                </div>
                                                <div class="col-md-6">
                                                      <label for="vendorBillProjectClient" class="form-label small">Project Client</label>
                                                      <select id="vendorBillProjectClient" class="form-select form-control-dark project-client-select"><option value="">Select a customer</option></select>
                                                </div>
                                                <div class="col-md-6">
                                                      <label for="vendorBillProjectName" class="form-label small">Project (optional)</label>
                                                      <input type="text" id="vendorBillProjectName" class="form-control form-control-dark project-name-input" list="activeProjectNames" maxlength="255" placeholder="Type an event or project name">
                                                </div>
                                          </div>
                                    </div>
                                    <div class="modal-footer">
                                          <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                                          <button type="submit" class="btn quick-action-btn primary" id="saveVendorBillBtn">Record Bill</button>
                                    </div>
                              </form>
                        </div>
                  </div>
            </div>
            <?php endif; ?>
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
                                    <div class="input-group currency-input-group">
                                          <span class="input-group-text" aria-hidden="true">₱</span>
                                          <input type="text" inputmode="decimal" data-currency-input step="0.01" min="0.01" class="form-control form-control-dark" id="expenseAmount" required>
                                    </div>
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

                              <div class="row g-3">
                                    <div class="col-md-6 mb-3">
                                          <label for="expenseProjectClient" class="form-label small">Project Client</label>
                                          <select class="form-select form-control-dark project-client-select" id="expenseProjectClient"><option value="">Select a customer</option></select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                          <label for="expenseProjectName" class="form-label small">Project (optional)</label>
                                          <input type="text" class="form-control form-control-dark project-name-input" id="expenseProjectName" list="activeProjectNames" maxlength="255" placeholder="Type an event or project name">
                                    </div>
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
                                        <input type="text" inputmode="decimal" data-currency-input step="0.01" class="form-control form-control-dark" id="scannedAmount" required>
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
      

      
<?php if ($canCreate) { require __DIR__ . '/partials/payment-modal.php'; } ?>

<datalist id="activeProjectNames"></datalist>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../frontend/js/currency-inputs.js"></script>
<script src="../frontend/js/dashboard.js?v=transactions-autoload-fix-1"></script>
<?php if ($canCreate): ?>
<script src="../frontend/js/settlement.js"></script>
<?php endif; ?>
<script>
    window.API_URL    = '../backend/routes/journal.php'; // Ensure correct path to your backend endpoint
    window.CAN_CREATE = <?= json_encode(!empty($_SESSION['user_id'])) ?>;
    window.USER_ROLE  = <?= json_encode(strtolower($_SESSION['role'] ?? 'staff')) ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="../frontend/js/session-timeout.js"></script>


</body>
</html>