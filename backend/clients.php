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
      <title>Clients Directory — KoboSocial</title>
      <script src="../frontend/js/theme.js"></script>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <link rel="stylesheet" href="../frontend/css/dashboard-style.css?v=mega-nav-1">
    <link rel="stylesheet" href="../frontend/css/clients.css">
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>

      <div class="app-shell">
          <div class="main-content">
              <div class="content-area">
            <?php require __DIR__ . '/includes/navbar.php'; ?>

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
                  
                  <div class="client-directory" id="clientsTableBody" aria-live="polite">
                      <p class="client-directory-message">Loading clients...</p>
                  </div>
              </div>
          </div>
      </div>

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
                      <ul class="nav nav-tabs client-detail-tabs bg-dark border-secondary" id="clientDetailTabs" role="tablist">
                          <li class="nav-item" role="presentation">
                              <button class="nav-link active bg-dark text-light border-secondary" id="clientOverviewTab" data-bs-toggle="tab" data-bs-target="#clientOverviewPane" type="button" role="tab" aria-controls="clientOverviewPane" aria-selected="true">Overview &amp; Financials</button>
                          </li>
                          <li class="nav-item" role="presentation">
                              <button class="nav-link bg-dark text-light border-secondary" id="clientProjectsTab" data-bs-toggle="tab" data-bs-target="#clientProjectsPane" type="button" role="tab" aria-controls="clientProjectsPane" aria-selected="false">Client Projects &amp; P&amp;L</button>
                          </li>
                      </ul>
                      <div class="tab-content client-detail-tab-content" id="clientDetailTabContent">
                          <section class="tab-pane fade show active" id="clientOverviewPane" role="tabpanel" aria-labelledby="clientOverviewTab" tabindex="0">
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
                          </section>
                          <section class="tab-pane fade" id="clientProjectsPane" role="tabpanel" aria-labelledby="clientProjectsTab" tabindex="0">
                              <div class="client-projects-heading">
                                  <div>
                                      <h6 class="mb-1">Projects &amp; Job Costing</h6>
                                      <p class="small client-detail-muted mb-0">Posted revenue, costs, and net profit by project.</p>
                                  </div>
                              </div>
                              <div class="table-responsive client-projects-table-wrap">
                                  <table class="table client-projects-table mb-0">
                                      <thead>
                                          <tr>
                                              <th scope="col">Project Name</th>
                                              <th scope="col">Status</th>
                                              <th scope="col" class="text-end">Total Revenue</th>
                                              <th scope="col" class="text-end">Total Expenses</th>
                                              <th scope="col" class="text-end">Net Profit</th>
                                              <th scope="col" class="text-end">Action</th>
                                          </tr>
                                      </thead>
                                      <tbody id="clientProjectsBody">
                                          <tr><td colspan="6" class="text-center py-4 client-detail-muted">Select a client to view projects.</td></tr>
                                      </tbody>
                                  </table>
                              </div>
                          </section>
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
                  <form id="clientForm" enctype="multipart/form-data">
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
                              <label for="clientLogo" class="form-label small">Client Logo</label>
                              <input type="file" class="form-control form-control-dark" id="clientLogo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                              <div class="form-text" style="color: var(--dark-text-muted);">JPG, PNG, or WebP. Maximum 2 MB.</div>
                          </div>
                          <div class="client-logo-preview-box mb-3" id="clientLogoPreviewBox">
                              <img id="clientLogoPreview" alt="Client logo preview" hidden>
                              <div class="client-logo-placeholder" id="clientLogoPlaceholder">Logo preview</div>
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

      <div class="modal fade" id="clientStatusConfirmModal" tabindex="-1" aria-labelledby="clientStatusConfirmTitle" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content modal-dark">
                  <div class="modal-header">
                      <h5 class="modal-title" id="clientStatusConfirmTitle">Confirm client status change</h5>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body" id="clientStatusConfirmMessage"></div>
                  <div class="modal-footer">
                      <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                      <button type="button" class="btn quick-action-btn primary" id="confirmClientStatusBtn">Confirm</button>
                  </div>
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
                                              <input type="text" inputmode="decimal" data-currency-input step="0.01" class="form-control form-control-dark" id="scannedAmount" required>
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
    <script src="../frontend/js/currency-inputs.js"></script>
      <script src="../frontend/js/clients.js"></script>
      <script>
         const API_URL = 'routes/journal.php';
         const CAN_MANAGE_CLIENTS = <?= $canCreate ? 'true' : 'false' ?>;
          async function loadClients() {
              try {
                  const res = await fetch(`${API_URL}?action=list-clients`);
                  const data = await res.json();
                  const clientList = document.getElementById('clientsTableBody');
                  
                  if (data.success && data.clients.length > 0) {
                      clientList.innerHTML = data.clients.map(client => {
                          const name = escapeHtml(client.name || 'Unnamed client');
                          const logoUrl = getClientLogoUrl(client.logo);
                          const initials = getClientInitials(client.name);
                          const nextStatus = client.status === 'active' ? 'inactive' : 'active';
                          return `
                              <article class="client-card" data-client-id="${Number(client.id)}" tabindex="0" role="button" aria-label="View details and transaction history for ${name}">
                                  <div class="client-card-logo" style="--client-logo-hue: ${(Number(client.id) * 67) % 360};">
                                      ${logoUrl
                                          ? `<img src="${logoUrl}" alt="${name} logo" loading="lazy">`
                                          : `<span aria-hidden="true">${escapeHtml(initials)}</span>`}
                                  </div>
                                  <div class="client-card-info">
                                      <h2 class="client-card-name">${name}</h2>
                                      <div class="client-card-details">
                                          <span><strong>Company</strong>${escapeHtml(client.company_name || '—')}</span>
                                          <span><strong>Party type</strong>${escapeHtml((client.party_type || 'customer').replaceAll('_', ' '))}</span>
                                          <span><strong>Email</strong>${escapeHtml(client.email || '—')}</span>
                                          <span><strong>Phone</strong>${escapeHtml(client.phone || '—')}</span>
                                      </div>
                                      <span class="client-card-hint">View details and transaction history</span>
                                  </div>
                                  <div class="client-card-actions">
                                      <span class="client-status-badge ${client.status === 'active' ? 'is-active' : 'is-inactive'}">${escapeHtml(client.status || 'inactive')}</span>
                                      ${CAN_MANAGE_CLIENTS ? `
                                          <button type="button" class="quick-action-btn client-edit-btn" data-client-id="${Number(client.id)}" aria-label="Edit ${name}" title="Edit client"><i class="bi bi-pencil" aria-hidden="true"></i><span>Edit</span></button>
                                          <button type="button" class="quick-action-btn ${nextStatus === 'inactive' ? 'client-deactivate-btn' : 'client-reactivate-btn'} toggle-client-status-btn" data-client-id="${Number(client.id)}" data-status="${nextStatus}" data-client-name="${name}" aria-label="${nextStatus === 'inactive' ? 'Deactivate' : 'Reactivate'} ${name}">${nextStatus === 'inactive' ? 'Deactivate' : 'Reactivate'}</button>
                                      ` : ''}
                                  </div>
                              </article>
                          `;
                      }).join('');
                  } else {
                      clientList.innerHTML = '<p class="client-directory-message">No clients recorded yet.</p>';
                  }
              } catch (e) {
                  console.error('Error loading clients:', e);
                  document.getElementById('clientsTableBody').innerHTML = '<p class="client-directory-message text-danger">Could not load clients.</p>';
              }
          }

          document.getElementById('clientsTableBody').addEventListener('click', (event) => {
              const statusButton = event.target.closest('.toggle-client-status-btn');
              if (statusButton) {
                  event.stopPropagation();
                  showClientStatusConfirmation(
                      Number(statusButton.dataset.clientId),
                      statusButton.dataset.status,
                      statusButton.dataset.clientName,
                      statusButton
                  );
                  return;
              }
              const editButton = event.target.closest('.client-edit-btn');
              if (editButton) {
                  event.stopPropagation();
                  openClientEdit(Number(editButton.dataset.clientId));
                  return;
              }
              const row = event.target.closest('.client-card');
              if (row) viewClient(Number(row.dataset.clientId));
          });

          const clientStatusModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('clientStatusConfirmModal'));
          let pendingClientStatusChange = null;

          function showClientStatusConfirmation(clientId, status, clientName, button) {
              const action = status === 'inactive' ? 'deactivate' : 'reactivate';
              pendingClientStatusChange = { clientId, status, button };
              document.getElementById('clientStatusConfirmTitle').textContent = `${action === 'deactivate' ? 'Deactivate' : 'Reactivate'} client`;
              document.getElementById('clientStatusConfirmMessage').textContent = action === 'deactivate'
                  ? `Deactivate ${clientName}? Their profile and financial history will be preserved.`
                  : `Reactivate ${clientName}? They will become available in active client lists again.`;
              document.getElementById('confirmClientStatusBtn').textContent = action === 'deactivate' ? 'Deactivate' : 'Reactivate';
              clientStatusModal.show();
          }

          document.getElementById('confirmClientStatusBtn').addEventListener('click', async () => {
              if (!pendingClientStatusChange) return;
              const change = pendingClientStatusChange;
              pendingClientStatusChange = null;
              clientStatusModal.hide();
              await toggleClientStatus(change.clientId, change.status, change.button);
          });

          async function toggleClientStatus(clientId, status, button) {
              const action = status === 'inactive' ? 'deactivate' : 'reactivate';

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

          let existingClientLogoUrl = '';
          const clientLogoInput = document.getElementById('clientLogo');
          const clientLogoPreview = document.getElementById('clientLogoPreview');
          const clientLogoPlaceholder = document.getElementById('clientLogoPlaceholder');

          function getClientLogoUrl(logoPath) {
              const path = String(logoPath || '').replace(/\\/g, '/');
              if (!/^uploads\/clients\/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)$/i.test(path)) return '';
              return `../${path.split('/').map(encodeURIComponent).join('/')}`;
          }

          function getClientInitials(name) {
              const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
              if (parts.length === 1) return Array.from(parts[0]).slice(0, 2).join('').toLocaleUpperCase();
              return parts.slice(0, 2).map(part => Array.from(part)[0] || '').join('').toLocaleUpperCase() || '?';
          }

          function setClientLogoPreview(url, name) {
              clientLogoPreview.hidden = !url;
              clientLogoPlaceholder.hidden = Boolean(url);
              clientLogoPlaceholder.textContent = getClientInitials(name);
              if (url) clientLogoPreview.src = url;
              else clientLogoPreview.removeAttribute('src');
          }

          clientLogoInput?.addEventListener('change', () => {
              const file = clientLogoInput.files?.[0];
              if (!file) {
                  setClientLogoPreview(existingClientLogoUrl, document.getElementById('clientName').value);
                  return;
              }
              const acceptedTypes = ['image/jpeg', 'image/png', 'image/webp'];
              const acceptedExtensions = /\.(?:jpe?g|png|webp)$/i;
              if ((!acceptedTypes.includes(file.type) && !acceptedExtensions.test(file.name)) || file.size > 2 * 1024 * 1024) {
                  clientLogoInput.value = '';
                  const errorDiv = document.getElementById('clientError');
                  errorDiv.textContent = file.size > 2 * 1024 * 1024
                      ? 'Client logos must be 2 MB or smaller.'
                      : 'Choose a JPG, PNG, or WebP image.';
                  errorDiv.classList.remove('d-none');
                  return;
              }
              const previewUrl = URL.createObjectURL(file);
              clientLogoPreview.onload = () => URL.revokeObjectURL(previewUrl);
              setClientLogoPreview(previewUrl, document.getElementById('clientName').value);
          });

          document.getElementById('clientName').addEventListener('input', () => {
              if (clientLogoPlaceholder && clientLogoPreview.hidden) {
                  clientLogoPlaceholder.textContent = getClientInitials(document.getElementById('clientName').value);
              }
          });

          document.getElementById('clientsTableBody').addEventListener('keydown', (event) => {
              if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('.client-card')) {
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
                  clientLogoInput.value = '';
                  existingClientLogoUrl = getClientLogoUrl(client.logo);
                  setClientLogoPreview(existingClientLogoUrl, client.name);
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
              clientLogoInput.value = '';
              existingClientLogoUrl = '';
              setClientLogoPreview('', document.getElementById('clientName').value);
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
              if (clientLogoInput.files?.[0]) formData.append('logo', clientLogoInput.files[0]);

              try {
                  const res = await fetch(API_URL, { method: 'POST', body: formData });
                  const data = await res.json();
                  if (data.success) {
                      bootstrap.Modal.getInstance(document.getElementById('clientModal')).hide();
                      document.getElementById('clientForm').reset();
                      document.getElementById('clientId').value = '';
                      existingClientLogoUrl = '';
                      setClientLogoPreview('', document.getElementById('clientName').value);
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
              window.formatCurrencyInput?.(document.getElementById('scannedAmount'));

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
              const amount = window.parseCurrencyValue(document.getElementById('scannedAmount').value);
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

          loadClients();
          const requestedClientId = Number(new URLSearchParams(window.location.search).get('client_id'));
          if (Number.isInteger(requestedClientId) && requestedClientId > 0) {
              window.viewClient(requestedClientId);
          }
      </script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>