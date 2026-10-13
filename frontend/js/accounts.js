const API_URL = 'routes/accounts.php';
const SCAN_API_URL = 'routes/journal.php';
let accountModal, accountForm, formError, modalTitle;
let selectedAccountType = null;
let rawAccountsList = []; // Caches original loaded dataset for fast filtering

if (CAN_MANAGE) {
    accountModal = new bootstrap.Modal(document.getElementById('accountModal'));
    accountForm = document.getElementById('accountForm');
    formError = document.getElementById('accountFormError');
    modalTitle = document.getElementById('accountModalTitle');
}

// ---------- Load account types into the dropdown ----------
async function loadAccountTypes() {
    if (!CAN_MANAGE) return;

    try {
        const response = await fetch(`${API_URL}?action=account-types`);
        const data = await response.json();

        if (data.success) {
            const select = document.getElementById('accountTypeId');
            select.innerHTML = data.types.map(t => `<option value="${t.id}">${t.name}</option>`).join('');
        }
    } catch (err) {
        console.error('Failed to load account types:', err);
    }
}

// ---------- Load and render accounts ----------
async function loadAccounts() {
    const tbody = document.getElementById('accountsTableBody');
    const summaryContainer = document.getElementById('summaryCards');

    try {
        const response = await fetch(`${API_URL}?action=list`);
        const rawText = await response.text();
        console.log('LIST RAW RESPONSE:', rawText);
        const data = JSON.parse(rawText);

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Failed to load accounts.</td></tr>`;
            return;
        }

        // Save raw dataset reference
        rawAccountsList = data.accounts || [];

        // Render KPIs and Table using active filter
        renderSummaryCards(rawAccountsList, summaryContainer);
        applyAccountFilter();

    } catch (err) {
        console.error('Failed to load accounts:', err);
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Could not reach the server.</td></tr>`;
    }
}

// ---------- Render Summary Cards ----------
function renderSummaryCards(accounts, container) {
    if (!container) return;

    const groups = { Asset: 0, Revenue: 0, Expense: 0, Liability: 0, Equity: 0 };
    
    accounts.forEach(acc => {
        if (acc.type_name in groups && acc.is_active == 1) {
            groups[acc.type_name] += parseFloat(acc.current_balance || 0);
        }
    });

    const isSelected = type => selectedAccountType === type ? 'border border-primary shadow-lg' : '';

    container.innerHTML = `
        <!-- Left: Large Featured Asset Card -->
        <div class="col-12 col-lg-5">
            <div class="kpi-card h-100 p-4 d-flex flex-column justify-content-between ${isSelected('Asset')}" 
                 onclick="handleKpiFilter('Asset')" 
                 style="cursor: pointer;">
                <div>
                    <div class="kpi-label small text-uppercase text-white-50 tracking-wider mb-2">Asset Accounts</div>
                    <div class="kpi-value money display-5 fw-bold text-white mb-2">${formatMoney(groups['Asset'])}</div>
                </div>
                <div class="kpi-footer pt-3 mt-3 border-top border-secondary text-white-50 small d-flex justify-content-between align-items-center">
                    <span>Total active asset balances</span>
                    <i class="bi bi-funnel-fill text-white-50"></i>
                </div>
            </div>
        </div>

        <!-- Right: 2x2 Grid for Remaining 4 Accounts -->
        <div class="col-12 col-lg-7">
            <div class="row g-3">
                <div class="col-6">
                    <div class="kpi-card p-3 h-100 ${isSelected('Revenue')}" 
                         onclick="handleKpiFilter('Revenue')" 
                         style="cursor: pointer;">
                        <div class="kpi-label small text-white-50">Revenue Accounts</div>
                        <div class="kpi-value money fs-4 fw-bold mt-1 text-white">${formatMoney(groups['Revenue'])}</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="kpi-card p-3 h-100 ${isSelected('Expense')}" 
                         onclick="handleKpiFilter('Expense')" 
                         style="cursor: pointer;">
                        <div class="kpi-label small text-white-50">Expense Accounts</div>
                        <div class="kpi-value money fs-4 fw-bold mt-1 text-white">${formatMoney(groups['Expense'])}</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="kpi-card p-3 h-100 ${isSelected('Liability')}" 
                         onclick="handleKpiFilter('Liability')" 
                         style="cursor: pointer;">
                        <div class="kpi-label small text-white-50">Liability Accounts</div>
                        <div class="kpi-value money fs-4 fw-bold mt-1 text-white">${formatMoney(groups['Liability'])}</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="kpi-card p-3 h-100 ${isSelected('Equity')}" 
                         onclick="handleKpiFilter('Equity')" 
                         style="cursor: pointer;">
                        <div class="kpi-label small text-white-50">Equity Accounts</div>
                        <div class="kpi-value money fs-4 fw-bold mt-1 text-white">${formatMoney(groups['Equity'])}</div>
                    </div>
                </div>
            </div>
        </div>
    `;

    renderFilterResetBar();
}

// ---------- Filter Logic ----------
window.handleKpiFilter = function (typeName) {
    if (selectedAccountType === typeName) {
        selectedAccountType = null; // Toggle off if clicked again
    } else {
        selectedAccountType = typeName;
    }

    const summaryContainer = document.getElementById('summaryCards');
    renderSummaryCards(rawAccountsList, summaryContainer);
    applyAccountFilter();
};

window.resetKpiFilter = function () {
    selectedAccountType = null;
    const summaryContainer = document.getElementById('summaryCards');
    renderSummaryCards(rawAccountsList, summaryContainer);
    applyAccountFilter();
};

function applyAccountFilter() {
    const tbody = document.getElementById('accountsTableBody');
    if (!tbody) return;

    let filtered = rawAccountsList;
    if (selectedAccountType) {
        filtered = rawAccountsList.filter(acc => acc.type_name === selectedAccountType);
    }

    renderTable(filtered, tbody);
}

function renderFilterResetBar() {
    let bar = document.getElementById('kpiFilterBar');
    const table = document.getElementById('accountsTable');

    if (!bar && table) {
        bar = document.createElement('div');
        bar.id = 'kpiFilterBar';
        bar.className = 'mb-3';
        table.parentNode.insertBefore(bar, table);
    }

    if (!bar) return;

    if (selectedAccountType) {
        bar.innerHTML = `
            <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill" style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15);">
                <span class="small text-white-50">Filtered by:</span>
                <span class="fw-semibold text-white small">${selectedAccountType} Accounts</span>
                <button type="button" onclick="resetKpiFilter()" class="btn btn-sm btn-link text-white p-0 ms-2 border-0" title="Reset filter">
                    <i class="bi bi-x-circle-fill"></i> Reset
                </button>
            </div>
        `;
    } else {
        bar.innerHTML = '';
    }
}

// ---------- Render Table ----------
function renderTable(accounts, tbody) {
    if (accounts.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4" style="color: var(--dark-text-muted);">No accounts found.</td></tr>`;
        return;
    }

    tbody.innerHTML = accounts.map(acc => `
        <tr>
            <td>${escapeHtml(acc.name)}</td>
            <td><span class="role-badge role-${acc.type_name.toLowerCase()}">${acc.type_name}</span></td>
            <td class="text-end money fw-semibold">${formatMoney(acc.current_balance)}</td>
            <td>
                <span class="badge-${acc.is_active == 1 ? 'in' : 'out'}">
                    ${acc.is_active == 1 ? 'Active' : 'Inactive'}
                </span>
            </td>
            ${CAN_MANAGE ? `
            <td class="text-end">
                <button class="table-action-btn" onclick='openEditModal(${JSON.stringify(acc)})'>Edit</button>
                <button class="table-action-btn" onclick="toggleStatus(${acc.id})">
                    ${acc.is_active == 1 ? 'Deactivate' : 'Activate'}
                </button>
            </td>` : ''}
        </tr>
    `).join('');
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatMoney(amount) {
    return '₱' + parseFloat(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });
}

if (CAN_MANAGE) {
    // ---------- Add Account button ----------
    document.getElementById('addAccountBtn')?.addEventListener('click', () => {
        accountForm.reset();
        document.getElementById('accountId').value = '';
        modalTitle.textContent = 'Add Account';
        document.getElementById('openingBalanceGroup').style.display = 'block';
        formError.classList.add('d-none');
    });

    // ---------- Edit (called inline from rendered rows) ----------
    window.openEditModal = function (account) {
        accountForm.reset();
        formError.classList.add('d-none');

        document.getElementById('accountId').value = account.id;
        document.getElementById('accountName').value = account.name;
        document.getElementById('accountTypeId').value = account.account_type_id;
        document.getElementById('accountNotes').value = account.notes || '';

        modalTitle.textContent = 'Edit Account';
        document.getElementById('openingBalanceGroup').style.display = 'none';

        accountModal.show();
    };

    // ---------- Save (handles both create and edit) ----------
    accountForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        formError.classList.add('d-none');

        const accountId = document.getElementById('accountId').value;
        const isEdit = accountId !== '';

        const formData = new FormData(accountForm);
        formData.append('action', isEdit ? 'edit' : 'create');

        try {
            const response = await fetch(API_URL, { method: 'POST', body: formData });
            const rawText = await response.text();
            console.log('RAW RESPONSE:', rawText);
            const data = JSON.parse(rawText);

            if (data.success) {
                accountModal.hide();
                loadAccounts();
            } else {
                formError.textContent = data.error || 'Something went wrong.';
                formError.classList.remove('d-none');
            }
        } catch (err) {
            console.error('Save account failed:', err);
            formError.textContent = 'Could not reach the server.';
            formError.classList.remove('d-none');
        }
    });

    // ---------- Toggle status (called inline from rendered rows) ----------
    window.toggleStatus = async function (accountId) {
        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                body: new URLSearchParams({ action: 'toggle-status', account_id: accountId }),
            });
            const data = await response.json();

            if (data.success) {
                loadAccounts();
            } else {
                alert(data.error || 'Something went wrong.');
            }
        } catch (err) {
            console.error('Toggle status failed:', err);
            alert('Could not reach the server.');
        }
    };
}

// ---------- Journal entries navigation expandable ----------
document.addEventListener('DOMContentLoaded', () => {
    const journalExpandable = document.getElementById('journalExpandable');
    const journalTrigger = document.getElementById('journalTrigger');

    if (journalTrigger && journalExpandable) {
        journalTrigger.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            journalExpandable.classList.toggle('active');
        });

        document.addEventListener('click', (e) => {
            if (!journalExpandable.contains(e.target)) {
                journalExpandable.classList.remove('active');
            }
        });
    }
});

// ==========================================
// RECEIPT SCANNER & QR POLLING CONTROLLER
// ==========================================

let scanPollInterval = null;
let currentScanToken = null;
let currentReceiptImagePath = '';

// Handle Modal Show -> Generate Token & Start Polling
document.getElementById('receiptScannerModal')?.addEventListener('show.bs.modal', async () => {
    resetScannerModalState();
    triggerCleanupRoutine();
    await initScanSession();
    await ensureAccountsLoadedForScan();
});

// Handle Modal Hide -> Stop Polling
document.getElementById('receiptScannerModal')?.addEventListener('hide.bs.modal', () => {
    stopScanPolling();
});

function resetScannerModalState() {
    stopScanPolling();
    currentScanToken = null;
    currentReceiptImagePath = '';

    document.getElementById('scanStepSelection')?.classList.remove('d-none');
    document.getElementById('scanStepVerification')?.classList.add('d-none');
    document.getElementById('saveScannedEntryBtn')?.classList.add('d-none');

    const qrContainer = document.getElementById('qrCodeContainer');
    if (qrContainer) qrContainer.innerHTML = '';
}

// 1. Initialize Scan Session & Render QR Code
async function initScanSession() {
    const qrContainer = document.getElementById('qrCodeContainer');
    if (qrContainer) qrContainer.innerHTML = '<div class="spinner-border text-primary spinner-border-sm" role="status"></div> Loading QR...';

    try {
        const response = await fetch(`${SCAN_API_URL}?action=init-scan-session`);
        const data = await response.json();

        if (data.success && data.mobile_url) {
            currentScanToken = data.token;

            if (qrContainer) {
                qrContainer.innerHTML = ''; // Clear loading spinner
                
                // Check if QRCode library is loaded
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
            console.error('Scan init error:', data);
        }
    } catch (err) {
        if (qrContainer) qrContainer.innerHTML = '<span class="text-danger small">Server connection error.</span>';
        console.error('Failed to initialize scan session:', err);
    }

    
}


// 2. Poll Backend Every 2 Seconds
function startScanPolling() {
    stopScanPolling(); // Clear any existing interval
    scanPollInterval = setInterval(async () => {
        if (!currentScanToken) return;

        try {
            const response = await fetch(`${SCAN_API_URL}?action=check-scan-session&token=${currentScanToken}`);
            const data = await response.json();

            if (data.success && data.status === 'completed') {
                stopScanPolling();
                openVerificationForm(data.image_path, data.extracted);
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

// 3. Fallback: Local File Drop / Selector
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
        const response = await fetch(SCAN_API_URL, { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            // Re-check session immediately
            const checkRes = await fetch(`${SCAN_API_URL}?action=check-scan-session&token=${currentScanToken}`);
            const checkData = await checkRes.json();
            if (checkData.success && checkData.status === 'completed') {
                stopScanPolling();
                openVerificationForm(checkData.image_path, checkData.extracted);
            }
        } else {
            showToast(data.error || 'Failed to process receipt file.', 'danger');
        }
    } catch (err) {
        showToast('Server error during local file processing.', 'danger');
    }
});

// 4. Populate Form and Switch to Step 2 Verification Panel
function openVerificationForm(imagePath, extracted) {
    currentReceiptImagePath = imagePath;

    document.getElementById('scannedImagePreview').src = '/wrs-accounting-collab/' + imagePath;
    document.getElementById('scannedVendor').value = extracted.vendor || '';
    document.getElementById('scannedDate').value = extracted.date || new Date().toISOString().split('T')[0];
    document.getElementById('scannedRef').value = extracted.reference_no || '';
    document.getElementById('scannedAmount').value = extracted.amount || '0.00';

    populateScanAccounts();

    document.getElementById('scanStepSelection')?.classList.add('d-none');
    document.getElementById('scanStepVerification')?.classList.remove('d-none');
    document.getElementById('saveScannedEntryBtn')?.classList.remove('d-none');
}

// Ensure account reference list is ready for Debit/Credit dropdowns
async function ensureAccountsLoadedForScan() {
    if (!window.allAccountsForLines || window.allAccountsForLines.length === 0) {
        try {
            const res = await fetch(`${SCAN_API_URL}?action=reference-data`);
            const data = await res.json();
            if (data.success) window.allAccountsForLines = data.accounts || [];
        } catch (e) {
            console.error('Failed to load accounts:', e);
        }
    }
}

function populateScanAccounts() {
    const debitSelect = document.getElementById('scannedDebitAccount');
    const creditSelect = document.getElementById('scannedCreditAccount');

    if (window.allAccountsForLines && window.allAccountsForLines.length > 0) {
        const optionsHtml = window.allAccountsForLines.map(a =>
            `<option value="${a.id}">${a.name} (${a.type_name})</option>`
        ).join('');

        if (debitSelect) debitSelect.innerHTML = optionsHtml;
        if (creditSelect) creditSelect.innerHTML = optionsHtml;
    }
}

// 5. Save Final Verified Transaction as Journal Entry
document.getElementById('saveScannedEntryBtn')?.addEventListener('click', async () => {
    const vendor = document.getElementById('scannedVendor').value.trim();
    const date = document.getElementById('scannedDate').value;
    const ref = document.getElementById('scannedRef').value.trim();
    const amount = parseFloat(document.getElementById('scannedAmount').value);
    const debitAcc = document.getElementById('scannedDebitAccount').value;
    const creditAcc = document.getElementById('scannedCreditAccount').value;

    if (!vendor || !date || isNaN(amount) || amount <= 0) {
        showToast('Please complete all required fields.', 'danger');
        return;
    }

    const debitLines = [{ account_id: debitAcc, amount: amount }];
    const creditLines = [{ account_id: creditAcc, amount: amount }];

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('transaction_type_id', 1); // Default to Expense / Outflow
    formData.append('description', vendor);
    formData.append('transaction_date', date);
    formData.append('reference_no', ref);
    formData.append('debit_lines', JSON.stringify(debitLines));
    formData.append('credit_lines', JSON.stringify(creditLines));

    try {
        const response = await fetch(SCAN_API_URL, { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            const modalEl = document.getElementById('receiptScannerModal');
            bootstrap.Modal.getInstance(modalEl)?.hide();

            showToast('Receipt transaction saved successfully!', 'success');
            // Trigger whatever table reload function your app uses
            if (typeof loadJournalEntries === 'function') {
                loadJournalEntries();
            } else if (typeof fetchEntries === 'function') {
                fetchEntries();
            } else if (typeof renderJournalTable === 'function') {
                renderJournalTable();
            } else {
                // Fallback: reload the page smoothly if no function matches
                location.reload();
            }
        } else {
            showToast(data.error || 'Failed to save transaction.', 'danger');
        }
    } catch (err) {
        showToast('Server error while saving transaction.', 'danger');
    }
});

async function triggerCleanupRoutine() {
    try {
        await fetch('../backend/routes/cleanup-scans.php?ajax=1');
    } catch (err) {
        // Fail silently so it never interrupts the user experience
        console.debug('Background cleanup check completed.');
    }
}


// ---------- Logout ----------
document.getElementById('logoutBtn')?.addEventListener('click', async (e) => {
    e.preventDefault();
    await fetch('routes/auth.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'logout' }),
    });
    window.location.href = '../frontend/login.html';
});

// ---------- Initial load ----------
loadAccountTypes();
loadAccounts();