<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';

$userRole =$_SESSION['role'];
$canCreate = in_array($userRole, ['admin', 'accountant', 'owner'], true);
$userName = $_SESSION['full_name'] ?? $_SESSION['role'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Clients Directory — WRS Accounting</title>
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
                  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                      <div>
                          <h1 class="h4 mb-1">Clients Directory</h1>
                          <p class="small mb-0" style="color: var(--dark-text-muted);">Manage customer and vendor profiles and business accounts. Select a row to view client details and transaction history.</p>
                      </div>
                      <?php if ($canCreate): ?>
                          <div class="d-flex gap-2">
                              <button class="quick-action-btn primary" id="addClientBtn" data-bs-toggle="modal" data-bs-target="#clientModal">+ Add New Client</button>
                          </div>
                      <?php endif; ?>
                  </div>
                  
                  <div class="panel">
                      <div class="table-responsive">
                          <table class="table table-transactions mb-0">
                              <thead>
                                  <tr>
                                      <th>Name</th>
                                      <th>Company</th>
                                      <th>Party Type</th>
                                      <th>Email</th>
                                      <th>Phone</th>
                                      <th>Status</th>
                                      <th class="text-end">Actions</th>
                                  </tr>
                              </thead>
                              <tbody id="clientsTableBody">
                                  <tr>
                                      <td colspan="7" class="text-center py-4" style="color: var(--dark-text-muted);">Loading clients...</td>
                                  </tr>
                              </tbody>
                          </table>
                      </div>
                  </div>
              </div>
          </div>
      </div>

      <!-- Bottom Navigation Bar (Balanced & Centered Scan Button) -->
      <nav class="bottom-nav">
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
          <a href="clients.php" class="bottom-nav-item active">
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
          
          <div class="bottom-nav-expandable" id="journalExpandable">
              <button type="button" class="bottom-nav-item nav-trigger" id="journalTrigger">
                  <i class="bi bi-journal-text"></i>
                  <span>Journal</span>
              </button>

              <div class="expandable-content">
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
          <a href="user-management.php" class="bottom-nav-item">
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

      <!-- Client Profile and Transaction History -->
      <div class="modal fade" id="clientDetailModal" tabindex="-1" aria-labelledby="clientDetailName" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
              <div class="modal-content modal-dark">
                  <div class="modal-header align-items-start">
                      <div>
                          <h5 class="modal-title" id="clientDetailName">Client Details</h5>
                          <p class="small mb-0 client-detail-muted" id="clientDetailCompany"></p>
                      </div>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 p-3 mb-4 rounded border" style="border-color: var(--dark-border) !important; background: rgba(255,255,255,.025);">
                          <div>
                              <div class="small client-detail-muted mb-1" id="clientDetailBalanceLabel">Total Unsettled Balance (A/R)</div>
                              <div class="h4 mb-0 text-light" id="clientDetailBalance">₱0.00</div>
                          </div>
                          <?php if ($canCreate): ?>
                          <div class="d-flex gap-2">
                              <button type="button" class="btn quick-action-btn primary" id="clientReceivePaymentBtn" onclick="openPaymentModal('ar', window.currentClientDetailId)">
                                  <i class="bi bi-plus-lg me-1"></i> Receive Payment (A/R)
                              </button>
                              <button type="button" class="btn quick-action-btn" id="clientMakePaymentBtn" onclick="openPaymentModal('ap', window.currentClientDetailId)">
                                  <i class="bi bi-cash-coin me-1"></i> Make Payment (A/P)
                              </button>
                          </div>
                          <?php endif; ?>
                      </div>
                      <div class="row g-3 mb-4">
                          <div class="col-md-6"><div class="small client-detail-muted">Email</div><div id="clientDetailEmail"></div></div>
                          <div class="col-md-6"><div class="small client-detail-muted">Phone</div><div id="clientDetailPhone"></div></div>
                          <div class="col-md-6"><div class="small client-detail-muted">Party Type</div><div class="text-capitalize" id="clientDetailPartyType"></div></div>
                          <div class="col-md-6"><div class="small client-detail-muted">Status</div><div id="clientDetailStatus"></div></div>
                      </div>
                      <div class="d-flex justify-content-between align-items-center mb-2">
                          <h6 class="mb-0">Transaction History</h6>
                          <span class="small client-detail-muted">Journal activity</span>
                      </div>
                      <div class="client-history-panel">
                          <div class="table-responsive">
                          <table class="table client-history-table mb-0">
                              <thead><tr><th>Date</th><th>Description</th><th>Journal Lines</th><th class="text-end">Amount</th></tr></thead>
                              <tbody id="clientHistoryBody">
                                  <tr><td colspan="4" class="text-center py-3 client-detail-muted">Select a client to view transactions.</td></tr>
                              </tbody>
                          </table>
                          </div>
                      </div>
                  </div>
              </div>
          </div>
      </div>

      <!-- Add Client Modal -->
      <div class="modal fade" id="clientModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog">
              <div class="modal-content modal-dark">
                  <div class="modal-header">
                      <h5 class="modal-title" id="clientModalTitle">Add New Client</h5>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <form id="clientForm">
                      <div class="modal-body">
                          <input type="hidden" id="clientId">
                          <div class="mb-3">
                              <label for="clientName" class="form-label small">Full Name *</label>
                              <input type="text" class="form-control form-control-dark" id="clientName" required>
                          </div>
                          <div class="mb-3">
                              <label for="clientCompany" class="form-label small">Company Name</label>
                              <input type="text" class="form-control form-control-dark" id="clientCompany">
                          </div>
                          <div class="mb-3">
                              <label for="clientPartyType" class="form-label small">Party Type</label>
                              <select class="form-select form-control-dark" id="clientPartyType" required>
                                  <option value="customer">Customer</option>
                                  <option value="vendor">Vendor</option>
                                  <option value="both">Customer and vendor</option>
                              </select>
                          </div>
                          <div class="mb-3">
                              <label for="clientEmail" class="form-label small">Email Address</label>
                              <input type="email" class="form-control form-control-dark" id="clientEmail">
                          </div>
                          <div class="mb-3">
                              <label for="clientPhone" class="form-label small">Phone Number</label>
                              <input type="text" class="form-control form-control-dark" id="clientPhone">
                          </div>
                          <div class="mb-3">
                              <label for="clientStatus" class="form-label small">Status</label>
                              <select class="form-select form-control-dark" id="clientStatus">
                                  <option value="active">Active</option>
                                  <option value="inactive">Inactive</option>
                              </select>
                          </div>
                          <div id="clientError" class="alert alert-danger py-2 px-3 small d-none"></div>
                      </div>
                      <div class="modal-footer">
                          <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                          <button type="submit" class="btn quick-action-btn primary" id="saveClientBtn">Save Client</button>
                      </div>
                  </form>
              </div>
          </div>
      </div>

      <?php if ($canCreate) { require __DIR__ . '/partials/payment-modal.php'; } ?>

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

                      <!-- STEP 2: EDITABLE VERIFICATION FORM -->
                      <div id="scanStepVerification" class="d-none">
                          <div class="row g-4">
                              <div class="col-md-5 border-end border-secondary pe-md-3">
                                  <label class="form-label small fw-semibold text-light mb-2">Scanned Receipt Image</label>
                                  <div class="bg-black rounded border border-secondary p-2 text-center overflow-auto" style="max-height: 400px;">
                                      <img id="scannedImagePreview" src="" alt="Scanned Receipt" class="img-fluid rounded" style="object-fit: contain;">
                                  </div>
                              </div>
                              <div class="col-md-7 ps-md-3">
                                  <form id="scannedReceiptForm">
                                      <div class="mb-3">
                                          <label class="form-label">Client / Customer (Optional)</label>
                                          <select id="scannedClient" class="form-select form-control-dark">
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
                                              <label class="form-label small mb-1">Debit Account <span class="text-danger">*</span></label>
                                              <select class="form-select form-control-dark" id="scannedDebitAccount" required></select>
                                          </div>
                                          <div class="col-md-6">
                                              <label class="form-label small mb-1">Credit Account <span class="text-danger">*</span></label>
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

      <!-- Scripts -->
      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
      <script src="../frontend/js/settlement.js"></script>
      <script src="../frontend/js/clients.js"></script>
      <script>
         const API_URL = 'routes/journal.php';
          async function loadClients() {
              try {
                  const res = await fetch(`${API_URL}?action=list-clients`);
                  const data = await res.json();
                  const tbody = document.getElementById('clientsTableBody');
                  
                  if (data.success && data.clients.length > 0) {
                      tbody.innerHTML = data.clients.map(c => `
                          <tr class="client-row" data-client-id="${Number(c.id)}" tabindex="0" role="button" aria-label="View details and transaction history for ${escapeHtml(c.name)}" title="Select to view client details">
                              <td class="client-row-name">
                                  <span class="fw-semibold">${escapeHtml(c.name)}</span>
                                  <span class="client-row-hint">View details <i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
                              </td>
                              <td>${escapeHtml(c.company_name || '-')}</td>
                              <td class="text-capitalize">${escapeHtml(c.party_type || 'customer')}</td>
                              <td>${escapeHtml(c.email || '-')}</td>
                              <td>${escapeHtml(c.phone || '-')}</td>
                              <td>
                                  <span class="badge ${c.status === 'active' ? 'bg-success bg-opacity-25 text-success' : 'bg-secondary bg-opacity-25 text-secondary'} px-2 py-1 text-uppercase" style="font-size: 0.75rem;">
                                      ${escapeHtml(c.status)}
                                  </span>
                              </td>
                              <td class="text-end">
                                  ${<?= $canCreate ? 'true' : 'false' ?> ? `
                                      <button type="button" class="btn btn-sm btn-dark border border-secondary text-light px-2 py-1 edit-client-btn" data-client-id="${Number(c.id)}" title="Edit client" aria-label="Edit ${escapeHtml(c.name)}"><i class="bi bi-pencil"></i></button>
                                      <button type="button" class="btn btn-sm ${c.status === 'active' ? 'btn-outline-warning' : 'btn-outline-success'} px-2 py-1 toggle-client-status-btn" data-client-id="${Number(c.id)}" data-status="${c.status === 'active' ? 'inactive' : 'active'}" title="${c.status === 'active' ? 'Deactivate' : 'Activate'} client" aria-label="${c.status === 'active' ? 'Deactivate' : 'Activate'} ${escapeHtml(c.name)}"><i class="bi ${c.status === 'active' ? 'bi-person-dash' : 'bi-person-check'}"></i></button>
                                  ` : '<span class="client-detail-muted">—</span>'}
                              </td>
                          </tr>
                      `).join('');
                  } else {
                      tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4" style="color: var(--dark-text-muted);">No clients recorded yet.</td></tr>`;
                  }
              } catch (e) {
                  console.error('Error loading clients:', e);
              }
          }

          document.getElementById('clientsTableBody').addEventListener('click', (event) => {
              const statusButton = event.target.closest('.toggle-client-status-btn');
              if (statusButton) {
                  event.stopPropagation();
                  toggleClientStatus(
                      Number(statusButton.dataset.clientId),
                      statusButton.dataset.status,
                      statusButton
                  );
                  return;
              }
              const editButton = event.target.closest('.edit-client-btn');
              if (editButton) {
                  event.stopPropagation();
                  openClientEdit(Number(editButton.dataset.clientId));
                  return;
              }
              const row = event.target.closest('.client-row');
              if (row) viewClient(Number(row.dataset.clientId));
          });

          async function toggleClientStatus(clientId, status, button) {
              const action = status === 'inactive' ? 'deactivate' : 'activate';
              if (!window.confirm(`Are you sure you want to ${action} this client? Their financial history will be preserved.`)) {
                  return;
              }

              button.disabled = true;
              const formData = new FormData();
              formData.append('action', 'toggle-client-status');
              formData.append('client_id', String(clientId));
              formData.append('status', status);
              try {
                  const response = await fetch(API_URL, { method: 'POST', body: formData });
                  const data = await response.json();
                  if (!response.ok || !data.success) {
                      throw new Error(data.error || `Failed to ${action} client.`);
                  }
                  await loadClients();
              } catch (error) {
                  window.alert(error.message || `Failed to ${action} client.`);
                  button.disabled = false;
              }
          }

          document.getElementById('clientsTableBody').addEventListener('keydown', (event) => {
              if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('.client-row')) {
                  event.preventDefault();
                  viewClient(Number(event.target.dataset.clientId));
              }
          });

          window.openClientEdit = async function (clientId) {
              const errorDiv = document.getElementById('clientError');
              errorDiv.classList.add('d-none');
              try {
                  const res = await fetch(`${API_URL}?action=client-detail&client_id=${encodeURIComponent(clientId)}`);
                  const data = await res.json();
                  if (!res.ok || !data.success) throw new Error(data.error || 'Failed to load client.');
                  const client = data.client;
                  document.getElementById('clientId').value = client.id;
                  document.getElementById('clientName').value = client.name || '';
                  document.getElementById('clientCompany').value = client.company_name || '';
                  document.getElementById('clientPartyType').value = client.party_type || 'customer';
                  document.getElementById('clientEmail').value = client.email || '';
                  document.getElementById('clientPhone').value = client.phone || '';
                  document.getElementById('clientStatus').value = client.status || 'active';
                  document.getElementById('clientModalTitle').textContent = 'Edit Client';
                  document.getElementById('saveClientBtn').textContent = 'Save Changes';
                  bootstrap.Modal.getOrCreateInstance(document.getElementById('clientModal')).show();
              } catch (error) {
                  window.alert(error.message || 'Failed to load client for editing.');
              }
          };

          document.getElementById('addClientBtn')?.addEventListener('click', () => {
              document.getElementById('clientForm').reset();
              document.getElementById('clientId').value = '';
              document.getElementById('clientPartyType').value = 'customer';
              document.getElementById('clientStatus').value = 'active';
              document.getElementById('clientModalTitle').textContent = 'Add New Client';
              document.getElementById('saveClientBtn').textContent = 'Save Client';
              document.getElementById('clientError').classList.add('d-none');
          });

          document.getElementById('clientForm').addEventListener('submit', async (e) => {
              e.preventDefault();
              const errorDiv = document.getElementById('clientError');
              errorDiv.classList.add('d-none');

              const formData = new FormData();
              const clientId = document.getElementById('clientId').value;
              formData.append('action', clientId ? 'update-client' : 'create-client');
              if (clientId) formData.append('client_id', clientId);
              formData.append('name', document.getElementById('clientName').value);
              formData.append('company_name', document.getElementById('clientCompany').value);
              formData.append('party_type', document.getElementById('clientPartyType').value);
              formData.append('email', document.getElementById('clientEmail').value);
              formData.append('phone', document.getElementById('clientPhone').value);
              formData.append('status', document.getElementById('clientStatus').value);

              try {
                  const res = await fetch(API_URL, { method: 'POST', body: formData });
                  const data = await res.json();
                  if (data.success) {
                      bootstrap.Modal.getInstance(document.getElementById('clientModal')).hide();
                      document.getElementById('clientForm').reset();
                      document.getElementById('clientId').value = '';
                      document.getElementById('clientModalTitle').textContent = 'Add New Client';
                      document.getElementById('saveClientBtn').textContent = 'Save Client';
                      loadClients();
                  } else {
                      errorDiv.textContent = data.error || 'Failed to save client.';
                      errorDiv.classList.remove('d-none');
                  }
              } catch (err) {
                  errorDiv.textContent = 'Server connection error.';
                  errorDiv.classList.remove('d-none');
              }
          });

          function escapeHtml(str) {
              return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
          }

          // QR Scanner Controller
          let scanPollInterval = null;
          let currentScanToken = null;

          document.getElementById('receiptScannerModal')?.addEventListener('show.bs.modal', async () => {
              resetScannerModalState();
              await loadClientsIntoScanDropdown();
              await initScanSession();
              await ensureAccountsLoadedForScan();
          });

          document.getElementById('receiptScannerModal')?.addEventListener('hide.bs.modal', () => {
              stopScanPolling();
          });

          function resetScannerModalState() {
              stopScanPolling();
              currentScanToken = null;
              document.getElementById('scanStepSelection')?.classList.remove('d-none');
              document.getElementById('scanStepVerification')?.classList.add('d-none');
              document.getElementById('saveScannedEntryBtn')?.classList.add('d-none');
              const qrContainer = document.getElementById('qrCodeContainer');
              if (qrContainer) qrContainer.innerHTML = '';
          }

          async function initScanSession() {
              const qrContainer = document.getElementById('qrCodeContainer');
              if (qrContainer) qrContainer.innerHTML = '<div class="spinner-border text-primary spinner-border-sm" role="status"></div> Loading QR...';

              try {
                  const response = await fetch(`${API_URL}?action=init-scan-session`);
                  const data = await response.json();

                  if (data.success && data.mobile_url) {
                      currentScanToken = data.token;
                      if (qrContainer) {
                          qrContainer.innerHTML = '';
                          if (typeof QRCode !== 'undefined') {
                              new QRCode(qrContainer, {
                                  text: data.mobile_url,
                                  width: 160,
                                  height: 160,
                                  colorDark: '#0f172a',
                                  colorLight: '#ffffff',
                                  correctLevel: QRCode.CorrectLevel.M
                              });
                          } else {
                              qrContainer.innerHTML = `<span class="text-danger small">QR library missing. <a href="${data.mobile_url}" target="_blank">Open Link</a></span>`;
                          }
                      }
                      startScanPolling();
                  } else {
                      if (qrContainer) qrContainer.innerHTML = '<span class="text-danger small">Failed to create scan session.</span>';
                  }
              } catch (err) {
                  if (qrContainer) qrContainer.innerHTML = '<span class="text-danger small">Server connection error.</span>';
              }
          }

          function startScanPolling() {
              stopScanPolling();
              scanPollInterval = setInterval(async () => {
                  if (!currentScanToken) return;
                  try {
                      const response = await fetch(`${API_URL}?action=check-scan-session&token=${currentScanToken}`);
                      const data = await response.json();
                      if (data.success && data.status === 'completed') {
                          stopScanPolling();
                          openVerificationForm(data.image_path, data.extracted, data.image_url);
                      }
                  } catch (err) {
                      console.error('Polling error:', err);
                  }
              }, 2000);
          }

          function stopScanPolling() {
              if (scanPollInterval) {
                  clearInterval(scanPollInterval);
                  scanPollInterval = null;
              }
          }

          const uploadZone = document.getElementById('receiptUploadZone');
          const fileInput = document.getElementById('receiptFileInput');

          uploadZone?.addEventListener('click', () => fileInput?.click());

          fileInput?.addEventListener('change', async (e) => {
              const file = e.target.files[0];
              if (!file) return;

              const formData = new FormData();
              formData.append('action', 'push-mobile-receipt');
              formData.append('token', currentScanToken);
              formData.append('receipt_image', file);

              try {
                  const response = await fetch(API_URL, { method: 'POST', body: formData });
                  const data = await response.json();

                  if (data.success) {
                      const checkRes = await fetch(`${API_URL}?action=check-scan-session&token=${currentScanToken}`);
                      const checkData = await checkRes.json();
                      if (checkData.success && checkData.status === 'completed') {
                          stopScanPolling();
                          openVerificationForm(checkData.image_path, checkData.extracted, checkData.image_url);
                      }
                  } else {
                      alert(data.error || 'Failed to process receipt file.');
                  }
              } catch (err) {
                  console.error('Local file processing error:', err);
                  alert('Could not reach the receipt scanning service. Please try again.');
              }
          });

          function openVerificationForm(imagePath, extracted, imageUrl = imagePath) {
              document.getElementById('scannedImagePreview').src = imageUrl;
              document.getElementById('scannedVendor').value = extracted.vendor || '';
              document.getElementById('scannedDate').value = extracted.date || new Date().toISOString().split('T')[0];
              document.getElementById('scannedRef').value = extracted.reference_no || '';
              document.getElementById('scannedAmount').value = extracted.amount || '0.00';

              document.getElementById('scanStepSelection')?.classList.add('d-none');
              document.getElementById('scanStepVerification')?.classList.remove('d-none');
              document.getElementById('saveScannedEntryBtn')?.classList.remove('d-none');
          }

          async function ensureAccountsLoadedForScan() {
              if (!window.allAccountsForLines || window.allAccountsForLines.length === 0) {
                  try {
                      const res = await fetch(`${API_URL}?action=reference-data`);
                      const data = await res.json();
                      if (data.success) {
                          window.allAccountsForLines = data.accounts || [];
                          const debitSelect = document.getElementById('scannedDebitAccount');
                          const creditSelect = document.getElementById('scannedCreditAccount');
                          const optionsHtml = window.allAccountsForLines.map(a => `<option value="${a.id}">${a.name} (${a.type_name})</option>`).join('');
                          if (debitSelect) debitSelect.innerHTML = optionsHtml;
                          if (creditSelect) creditSelect.innerHTML = optionsHtml;
                      }
                  } catch (e) {
                      console.error('Failed to load accounts:', e);
                  }
              }
          }

          async function loadClientsIntoScanDropdown() {
              const selectEl = document.getElementById('scannedClient');
              if (!selectEl) return;

              selectEl.replaceChildren(new Option('Loading active clients...', ''));
              try {
                  const res = await fetch('routes/clients.php?action=list&status=active');
                  const data = await res.json();
                  if (!res.ok || !data.success || !Array.isArray(data.clients)) {
                      throw new Error(data.error || 'Could not load active clients.');
                  }

                  selectEl.replaceChildren(new Option('-- Select Client (Optional) --', ''));
                  data.clients.forEach(client => {
                      const label = client.company_name
                          ? `${client.name} (${client.company_name})`
                          : client.name;
                      selectEl.add(new Option(label, client.id));
                  });
              } catch (err) {
                  console.error('Failed to load clients into scan modal', err);
                  selectEl.replaceChildren(new Option('Unable to load clients', ''));
                  selectEl.options[0].disabled = true;
              }
          }

          document.getElementById('saveScannedEntryBtn')?.addEventListener('click', async () => {
              const vendor = document.getElementById('scannedVendor').value.trim();
              const date = document.getElementById('scannedDate').value;
              const ref = document.getElementById('scannedRef').value.trim();
              const amount = parseFloat(document.getElementById('scannedAmount').value);
              const debitAcc = document.getElementById('scannedDebitAccount').value;
              const creditAcc = document.getElementById('scannedCreditAccount').value;
              const clientId = document.getElementById('scannedClient').value;

              if (!vendor || !date || isNaN(amount) || amount <= 0) {
                  alert('Please complete all required fields.');
                  return;
              }

              const formData = new FormData();
              formData.append('action', 'create');
              formData.append('transaction_type_id', 1);
              formData.append('description', vendor);
              formData.append('transaction_date', date);
              formData.append('reference_no', ref);
              formData.append('client_id', clientId);
              formData.append('debit_lines', JSON.stringify([{ account_id: debitAcc, amount: amount }]));
              formData.append('credit_lines', JSON.stringify([{ account_id: creditAcc, amount: amount }]));

              try {
                  const response = await fetch(API_URL, { method: 'POST', body: formData });
                  const data = await response.json();
                  if (data.success) {
                      bootstrap.Modal.getInstance(document.getElementById('receiptScannerModal'))?.hide();
                      alert('Receipt transaction saved successfully!');
                  } else {
                      alert(data.error || 'Failed to save transaction.');
                  }
              } catch (err) {
                  alert('Server error while saving transaction.');
              }
          });

          //-- journal entries navigation expandble
                  document.addEventListener('DOMContentLoaded', () => {
                  const journalExpandable = document.getElementById('journalExpandable');
                  const journalTrigger = document.getElementById('journalTrigger');

                  journalTrigger.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        // Toggle active state to trigger expand animation
                        journalExpandable.classList.toggle('active');
                  });

                  // Optional: Close expansion when clicking outside the nav
                  document.addEventListener('click', (e) => {
                        if (!journalExpandable.contains(e.target)) {
                              journalExpandable.classList.remove('active');
                        }
                  });
                  });

          loadClients();
          const requestedClientId = Number(new URLSearchParams(window.location.search).get('client_id'));
          if (Number.isInteger(requestedClientId) && requestedClientId > 0) {
              window.viewClient(requestedClientId);
          }
      </script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>