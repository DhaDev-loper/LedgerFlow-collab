/* =====================================================================
   Dashboard logic
   ===================================================================== */

const dashboardThemeStyles = getComputedStyle(document.documentElement);
const gridColor = dashboardThemeStyles.getPropertyValue('--dark-border').trim();
const tickColor = dashboardThemeStyles.getPropertyValue('--dark-text-muted').trim();
const chartSurface = dashboardThemeStyles.getPropertyValue('--dark-surface').trim();
const chartText = dashboardThemeStyles.getPropertyValue('--dark-text').trim();


async function loadCashflowChart() {
    const ctx = document.getElementById('cashflowChart');
    if (!ctx) return;

    // Destroy any existing chart on this canvas before creating a new one,
    // otherwise switching periods repeatedly stacks multiple charts on top
    // of each other.
    const existingChart = Chart.getChart(ctx);
    if (existingChart) existingChart.destroy();

    try {
        const days = document.getElementById('periodFilter')?.value || 14;
        const response = await fetch(`routes/journal.php?action=cashflow-chart&days=${days}`);
        const data = await response.json();

        if (!data.success) return;

        const gradientIn = ctx.getContext('2d').createLinearGradient(0, 0, 0, 260);
        gradientIn.addColorStop(0, 'rgba(46, 144, 250, 0.35)');
        gradientIn.addColorStop(1, 'rgba(46, 144, 250, 0.02)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Cash In',
                        data: data.cash_in,
                        borderColor: '#2E90FA',
                        backgroundColor: gradientIn,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                    },
                    {
                        label: 'Cash Out',
                        data: data.cash_out,
                        borderColor: '#F87171',
                        backgroundColor: 'transparent',
                        fill: false,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                        borderDash: [4, 4],
                    },
                ],
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { boxWidth: 12, usePointStyle: true, color: tickColor, font: { family: 'Inter', size: 12 } },
                    },
                    tooltip: {
                        backgroundColor: chartSurface,
                        borderColor: gridColor,
                        borderWidth: 1,
                        titleColor: chartText,
                        bodyColor: chartText,
                        callbacks: {
                            label: (item) => `${item.dataset.label}: ₱${item.formattedValue}`,
                        },
                    },
                },
                scales: {
                    y: {
                        ticks: { color: tickColor, callback: (val) => '₱' + val.toLocaleString(), font: { family: 'IBM Plex Mono', size: 11 } },
                        grid: { color: gridColor },
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: tickColor, font: { family: 'Inter', size: 11 } },
                    },
                },
            },
        });

    } catch (err) {
        console.error('Failed to load cashflow chart:', err);
    }
}

// =====================================================================
// Shared helpers — defined ONCE, used everywhere in this file
// =====================================================================

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, match => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[match]));
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatMoney(amount) {
    return '₱' + Math.abs(parseFloat(amount) || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });
}

// =====================================================================
// Recent Transactions table
// =====================================================================

async function loadRecentTransactions() {
    const tbody = document.getElementById('recentTransactionsBody');
    if (!tbody) return;

    try {
        const response = await fetch('routes/journal.php?action=recent&limit=8');
        const data = await response.json();

        if (!data.success || data.transactions.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center py-3" style="color: var(--dark-text-muted);">No transactions yet.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.transactions.map(tx => {
            const isInflow = tx.type_name === 'Cash Inflow';
            const isOutflow = tx.type_name === 'Cash Outflow';
            const icon = isInflow ? '↓' : isOutflow ? '↑' : '↔';
            const iconClass = isInflow ? 'in' : isOutflow ? 'out' : '';
            const amountClass = isInflow ? 'text-success' : isOutflow ? 'text-danger' : '';
            const sign = isInflow ? '+' : isOutflow ? '-' : '';

            return `
                <tr>
                    <td><span class="tx-icon ${iconClass}">${icon}</span></td>
                    <td>${escapeHtml(tx.description)}</td>
                    <td><span class="small" style="color: var(--dark-text-muted);">${escapeHtml(tx.category_name || tx.type_name)}</span></td>
                    <td><span class="small" style="color: var(--dark-text-muted);">${formatDate(tx.transaction_date)}</span></td>
                    <td class="text-end money fw-semibold ${amountClass}">
                        ${sign}${formatMoney(tx.total_amount)}
                    </td>
                </tr>
            `;
        }).join('');

    } catch (err) {
        console.error('Failed to load recent transactions:', err);
        tbody.innerHTML = `<tr><td colspan="5" class="text-center py-3 text-danger">Could not load transactions.</td></tr>`;
    }
}

// =====================================================================
// Dashboard summary (KPI cards)
// =====================================================================

async function loadDashboardSummary() {
    try {
        const response = await fetch('routes/journal.php?action=summary');
        const data = await response.json();

        if (!data.success) return;

        document.getElementById('kpiTotalAssets').textContent = formatMoney(data.total_assets);
        document.getElementById('kpiCashInflow').textContent = '+' + formatMoney(data.cash_inflow);
        document.getElementById('kpiCashOutflow').textContent = '-' + formatMoney(data.cash_outflow);

        const netCashFlowEl = document.getElementById('kpiNetCashFlow');
        const isPositive = data.net_cash_flow >= 0;
        netCashFlowEl.textContent = (isPositive ? '+' : '-') + formatMoney(Math.abs(data.net_cash_flow));
        netCashFlowEl.classList.add(isPositive ? 'positive' : 'negative');

    } catch (err) {
        console.error('Failed to load dashboard summary:', err);
    }
}

// =====================================================================
// Accounts widget (right column)
// =====================================================================

async function loadDashboardAccounts() {
    const container = document.getElementById('dashboardAccountsList');
    if (!container) return;

    try {
        const response = await fetch('routes/accounts.php?action=list');
        const data = await response.json();

        if (!data.success) return;

        const assetAccounts = data.accounts.filter(a => a.type_name === 'Asset' && a.is_active == 1);

        if (assetAccounts.length === 0) {
            container.innerHTML = `<p class="text-center py-3" style="color: var(--dark-text-muted);">No asset accounts yet.</p>`;
            return;
        }

        container.innerHTML = assetAccounts.map(acc => `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <div class="fw-semibold small">${escapeHtml(acc.name)}</div>
                    <div class="small" style="color: var(--dark-text-muted);">Asset</div>
                </div>
                <div class="money fw-semibold">${formatMoney(acc.current_balance)}</div>
            </div>
        `).join('');

        const totalAssets = assetAccounts.reduce((sum, acc) => sum + parseFloat(acc.current_balance), 0);
        document.getElementById('dashboardTotalAssets').textContent = formatMoney(totalAssets);

    } catch (err) {
        console.error('Failed to load dashboard accounts:', err);
        container.innerHTML = `<p class="text-center py-3 text-danger">Could not load accounts.</p>`;
    }
}

// =====================================================================
// Quick Sale / Quick Expense
// =====================================================================

let quickTransactionAccounts = [];
let quickTransactionReferenceData = null;

async function loadQuickTransactionAccounts() {
    try {
        const response = await fetch('routes/journal.php?action=reference-data');
        const data = await response.json();

        if (!data.success) return;

        quickTransactionAccounts = data.accounts;
        quickTransactionReferenceData = data;

        const assetAccounts = data.cash_accounts || [];
        const revenueAccounts = data.accounts.filter(a => a.type_name === 'Revenue');
        const expenseAccounts = data.accounts.filter(a => a.type_name === 'Expense');

        const optionsFor = (list) => list.map(a => `<option value="${a.id}">${a.name}</option>`).join('');

        document.getElementById('saleReceivedInto').innerHTML = optionsFor(assetAccounts);
        document.getElementById('saleRevenueAccount').innerHTML = optionsFor(revenueAccounts);

        document.getElementById('expensePaidFrom').innerHTML = optionsFor(assetAccounts);
        document.getElementById('expenseAccount').innerHTML = optionsFor(expenseAccounts);

        window.CASH_INFLOW_TYPE_ID = data.types.find(t => t.name === 'Cash Inflow')?.id;
        window.CASH_OUTFLOW_TYPE_ID = data.types.find(t => t.name === 'Cash Outflow')?.id;
        window.TRANSFER_TYPE_ID = data.types.find(t => t.name === 'Transfer')?.id;

        const cashAccountIds = new Set(assetAccounts.map(account => Number(account.id)));
        const billAccounts = data.accounts.filter(account =>
            account.type_name === 'Expense'
            || (account.type_name === 'Asset'
                && !cashAccountIds.has(Number(account.id))
                && account.name.toLowerCase() !== 'accounts receivable')
        );
        const vendors = (data.clients || []).filter(client =>
            client.status === 'active'
            && (client.party_type === 'vendor' || client.party_type === 'both')
        );
        const billAccountSelect = document.getElementById('vendorBillAccount');
        if (billAccountSelect) {
            billAccountSelect.replaceChildren(new Option('-- Select expense or asset --', ''));
            billAccounts.forEach(account => billAccountSelect.add(new Option(account.name, account.id)));
        }
        const vendorSelect = document.getElementById('vendorBillVendor');
        if (vendorSelect) {
            vendorSelect.replaceChildren(new Option('-- Select vendor --', ''));
            vendors.forEach(vendor => {
                const label = vendor.company_name ? `${vendor.name} (${vendor.company_name})` : vendor.name;
                vendorSelect.add(new Option(label, vendor.id));
            });
        }
        const payableSelect = document.getElementById('vendorBillPayable');
        if (payableSelect) {
            payableSelect.replaceChildren(new Option('-- Select Accounts Payable --', ''));
            (data.payable_accounts || []).forEach(account => payableSelect.add(new Option(account.name, account.id)));
        }

    } catch (err) {
        console.error('Failed to load quick transaction accounts:', err);
    }
}

document.getElementById('vendorBillModal')?.addEventListener('show.bs.modal', () => {
    const today = new Date();
    const localDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    document.getElementById('vendorBillDate').value = localDate;
    document.getElementById('vendorBillDueDate').value = localDate;
});

document.getElementById('vendorBillForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const errorBox = document.getElementById('vendorBillError');
    const saveButton = document.getElementById('saveVendorBillBtn');
    errorBox.classList.add('d-none');

    if (!quickTransactionReferenceData || !window.TRANSFER_TYPE_ID) {
        errorBox.textContent = 'The Transfer transaction type is not configured.';
        errorBox.classList.remove('d-none');
        return;
    }

    const vendorId = Number(document.getElementById('vendorBillVendor').value);
    const debitAccountId = Number(document.getElementById('vendorBillAccount').value);
    const payableAccountId = Number(document.getElementById('vendorBillPayable').value);
    const amount = Number(document.getElementById('vendorBillAmount').value);
    if (!vendorId || !debitAccountId || !payableAccountId || !Number.isFinite(amount) || amount <= 0) {
        errorBox.textContent = 'Select a vendor and accounts, and enter an amount greater than zero.';
        errorBox.classList.remove('d-none');
        return;
    }

    saveButton.disabled = true;
    saveButton.textContent = 'Saving...';
    try {
        const response = await fetch('routes/journal.php', {
            method: 'POST',
            body: new URLSearchParams({
                action: 'create',
                document_type: 'vendor_bill',
                transaction_type_id: String(window.TRANSFER_TYPE_ID),
                transaction_date: document.getElementById('vendorBillDate').value,
                due_date: document.getElementById('vendorBillDueDate').value,
                client_id: String(vendorId),
                description: document.getElementById('vendorBillDescription').value.trim(),
                reference_no: document.getElementById('vendorBillReference').value.trim(),
                debit_lines: JSON.stringify([{ account_id: debitAccountId, amount }]),
                credit_lines: JSON.stringify([{ account_id: payableAccountId, amount }]),
            }),
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Could not record vendor bill.');
        }

        bootstrap.Modal.getInstance(document.getElementById('vendorBillModal')).hide();
        document.getElementById('vendorBillForm').reset();
        loadRecentTransactions();
        loadDashboardSummary();
        loadDashboardAccounts();
        loadReceivablesPayables();
    } catch (error) {
        errorBox.textContent = error.message || 'Could not reach the server.';
        errorBox.classList.remove('d-none');
    } finally {
        saveButton.disabled = false;
        saveButton.textContent = 'Record Bill';
    }
});

document.getElementById('quickSaleForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const errorBox = document.getElementById('quickSaleError');
    errorBox.classList.add('d-none');

    const amount = document.getElementById('saleAmount').value;
    const toAccount = document.getElementById('saleReceivedInto').value;
    const fromAccount = document.getElementById('saleRevenueAccount').value;
    const description = document.getElementById('saleDescription').value || 'Quick sale';

    try {
        const response = await fetch('routes/journal.php', {
            method: 'POST',
            body: new URLSearchParams({
                action: 'create',
                transaction_type_id: window.CASH_INFLOW_TYPE_ID,
                description: description,
                transaction_date: new Date().toISOString().split('T')[0],
                debit_lines: JSON.stringify([{ account_id: toAccount, amount: amount }]),
                credit_lines: JSON.stringify([{ account_id: fromAccount, amount: amount }]),
            }),
        });
        const data = await response.json();

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('quickSaleModal')).hide();
            document.getElementById('quickSaleForm').reset();
            loadRecentTransactions();
            loadDashboardSummary();
            loadDashboardAccounts();
            loadCashflowChart();
        } else {
            errorBox.textContent = data.error || 'Something went wrong.';
            errorBox.classList.remove('d-none');
        }
    } catch (err) {
        console.error('Quick sale failed:', err);
        errorBox.textContent = 'Could not reach the server.';
        errorBox.classList.remove('d-none');
    }
});

document.getElementById('quickExpenseForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const errorBox = document.getElementById('quickExpenseError');
    errorBox.classList.add('d-none');

    const amount = document.getElementById('expenseAmount').value;
    const fromAccount = document.getElementById('expensePaidFrom').value;
    const toAccount = document.getElementById('expenseAccount').value;
    const description = document.getElementById('expenseDescription').value || 'Quick expense';

    try {
        const response = await fetch('routes/journal.php', {
            method: 'POST',
            body: new URLSearchParams({
                action: 'create',
                transaction_type_id: window.CASH_OUTFLOW_TYPE_ID,
                description: description,
                transaction_date: new Date().toISOString().split('T')[0],
                debit_lines: JSON.stringify([{ account_id: toAccount, amount: amount }]),
                credit_lines: JSON.stringify([{ account_id: fromAccount, amount: amount }]),
            }),
        });
        const data = await response.json();

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('quickExpenseModal')).hide();
            document.getElementById('quickExpenseForm').reset();
            loadRecentTransactions();
            loadDashboardSummary();
            loadDashboardAccounts();
            loadCashflowChart();
        } else {
            errorBox.textContent = data.error || 'Something went wrong.';
            errorBox.classList.remove('d-none');
        }
    } catch (err) {
        console.error('Quick expense failed:', err);
        errorBox.textContent = 'Could not reach the server.';
        errorBox.classList.remove('d-none');
    }
});

//------ A/R A/P for dashboard and journal entry page
async function loadReceivablesPayables() {
    try {
        const response = await fetch('routes/journal.php?action=receivables-payables');
        const data = await response.json();

        if (!data.success) return;

        fillAgingCard('ar', data.receivables);
        fillAgingCard('ap', data.payables);

    } catch (err) {
        console.error('Failed to load receivables/payables:', err);
    }
}

function fillAgingCard(prefix, info) {
    document.getElementById(`${prefix}Total`).textContent = formatMoney(info.total);
    document.getElementById(`${prefix}CurrentAmount`).textContent = formatMoney(info.current);
    document.getElementById(`${prefix}OverdueAmount`).textContent = formatMoney(info.overdue);

    const sum = info.current + info.overdue;
    const currentPercent = sum > 0 ? (info.current / sum) * 100 : 100;
    const overduePercent = sum > 0 ? (info.overdue / sum) * 100 : 0;

    document.getElementById(`${prefix}CurrentBar`).style.width = currentPercent + '%';
    document.getElementById(`${prefix}OverdueBar`).style.width = overduePercent + '%';
}


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




document.getElementById('periodFilter')?.addEventListener('change', () => {
    loadCashflowChart();
});


//--login responsive ui

// Smooth Login Transition Handler
document.addEventListener('DOMContentLoaded', () => {
    const overlay = document.getElementById('loginTransitionOverlay');
    if (!overlay) return;

    const loaderStep = document.getElementById('transitionStepLoader');
    const welcomeStep = document.getElementById('transitionStepWelcome');

    // 1. Show spinner for 700ms
    setTimeout(() => {
        if (loaderStep && welcomeStep) {
            loaderStep.classList.add('d-none');
            welcomeStep.classList.remove('d-none');
        }

        // 2. Display "Logged in as" message for 1.3 seconds, then fade out
        setTimeout(() => {
            overlay.style.opacity = '0';
            overlay.style.visibility = 'hidden';

            // Remove from DOM after fade animation completes
            setTimeout(() => overlay.remove(), 500);
        }, 1300);

    }, 700);
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
        const response = await fetch(`${API_URL}?action=init-scan-session`);
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
            const response = await fetch(`${API_URL}?action=check-scan-session&token=${currentScanToken}`);
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
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            // Re-check session immediately
            const checkRes = await fetch(`${API_URL}?action=check-scan-session&token=${currentScanToken}`);
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
            const res = await fetch(`${API_URL}?action=reference-data`);
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
        const response = await fetch(API_URL, { method: 'POST', body: formData });
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
        await fetch('backend/routes/cleanup-scans.php?ajax=1');
    } catch (err) {
        // Fail silently so it never interrupts the user experience
        console.debug('Background cleanup check completed.');
    }
}

function loadClientDropdown() {
    fetch('routes/journal.php?action=get_clients')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const clientSelect = document.getElementById('clientIdSelect');
                if (!clientSelect) return;
                clientSelect.innerHTML = '<option value="">-- Optional: Select Client --</option>';
                
                data.clients.forEach(client => {
                    const label = client.company_name 
                        ? `${client.name} (${client.company_name})` 
                        : client.name;
                    
                    const option = document.createElement('option');
                    option.value = client.id;
                    option.textContent = label;
                    clientSelect.appendChild(option);
                });
            }
        })
        .catch(err => console.error('Error loading clients:', err));
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
loadQuickTransactionAccounts();
loadRecentTransactions();
loadDashboardSummary();
loadCashflowChart();
loadDashboardAccounts();
loadReceivablesPayables() // for A/R and A/P in the dashboard and journal entry page