const API_URL = '../backend/routes/reports.php';
const EXPORT_URL = '../backend/routes/export.php'; // Adjust path if your export script is located elsewhere

let currentReport = 'income-statement';

// Set up default dates: 1st of current month to today
const today = new Date().toISOString().split('T')[0];
const firstOfMonth = new Date();
firstOfMonth.setDate(1);
const dateFromStr = firstOfMonth.toISOString().split('T')[0];

function setActiveTab(report) {
    currentReport = report;

    document.getElementById('tabIncomeStatement')?.classList.toggle('primary', report === 'income-statement');
    document.getElementById('tabBalanceSheet')?.classList.toggle('primary', report === 'balance-sheet');
    document.getElementById('tabTrialBalance')?.classList.toggle('primary', report === 'trial-balance');

    // Trial Balance uses the same "as of" single-date picker as Balance Sheet
    document.getElementById('dateRangeControls')?.classList.toggle('d-none', report !== 'income-statement');
    document.getElementById('asOfControl')?.classList.toggle('d-none', report === 'income-statement');

    const container = document.getElementById('reportContent');
    if (container) {
        container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Click Generate to load this report.</p>`;
    }
}

function formatMoney(amount) {
    const num = parseFloat(amount) || 0;
    const sign = num < 0 ? '-' : '';
    return sign + '₱' + Math.abs(num).toLocaleString(undefined, { minimumFractionDigits: 2 });
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ---------- Report Loaders ----------

async function loadIncomeStatement() {
    const container = document.getElementById('reportContent');
    if (!container) return;
    container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading...</p>`;

    const dateFrom = document.getElementById('dateFrom')?.value || '';
    const dateTo = document.getElementById('dateTo')?.value || '';

    try {
        const response = await fetch(`${API_URL}?action=income-statement&date_from=${dateFrom}&date_to=${dateTo}`);
        const data = await response.json();

        if (!data.success) {
            container.innerHTML = `<p class="text-center py-5 text-danger">Failed to load report.</p>`;
            return;
        }

        const revenueRows = data.revenue_lines?.map(line => `
            <div class="d-flex justify-content-between py-2" style="border-bottom: 1px solid var(--dark-border);">
                <span class="small">${escapeHtml(line.name)}</span>
                <span class="money">${formatMoney(line.net_amount)}</span>
            </div>
        `).join('') || `<p class="small py-2" style="color: var(--dark-text-muted);">No revenue recorded in this period.</p>`;

        const expenseRows = data.expense_lines?.map(line => `
            <div class="d-flex justify-content-between py-2" style="border-bottom: 1px solid var(--dark-border);">
                <span class="small">${escapeHtml(line.name)}</span>
                <span class="money">${formatMoney(line.net_amount)}</span>
            </div>
        `).join('') || `<p class="small py-2" style="color: var(--dark-text-muted);">No expenses recorded in this period.</p>`;

        const isProfitable = data.net_income >= 0;

        container.innerHTML = `
            <div class="panel receipt-card mb-4">
                <div class="panel-header">
                    <h2 class="h6 mb-0">Revenue</h2>
                </div>
                <div class="panel-body compact">
                    ${revenueRows}
                    <div class="d-flex justify-content-between pt-3 fw-semibold">
                        <span>Total Revenue</span>
                        <span class="money">${formatMoney(data.total_revenue)}</span>
                    </div>
                </div>
            </div>

            <div class="panel receipt-card mb-4">
                <div class="panel-header">
                    <h2 class="h6 mb-0">Expenses</h2>
                </div>
                <div class="panel-body compact">
                    ${expenseRows}
                    <div class="d-flex justify-content-between pt-3 fw-semibold">
                        <span>Total Expenses</span>
                        <span class="money">${formatMoney(data.total_expense)}</span>
                    </div>
                </div>
            </div>

            <div class="panel receipt-card">
                <div class="panel-body compact">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="h6 mb-0">Net Income</span>
                        <span class="money fw-bold fs-5" style="color: ${isProfitable ? '#4ADE80' : '#F87171'};">
                            ${formatMoney(data.net_income)}
                        </span>
                    </div>
                </div>
            </div>
        `;
    } catch (err) {
        console.error('Failed to load income statement:', err);
        container.innerHTML = `<p class="text-center py-5 text-danger">Could not reach the server.</p>`;
    }
}

async function loadBalanceSheet() {
    const container = document.getElementById('reportContent');
    if (!container) return;
    container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading...</p>`;

    const asOf = document.getElementById('asOfDate')?.value || '';

    try {
        const response = await fetch(`${API_URL}?action=balance-sheet&as_of=${asOf}`);
        const data = await response.json();

        if (!data.success) {
            container.innerHTML = `<p class="text-center py-5 text-danger">Failed to load report.</p>`;
            return;
        }

        const renderRows = (items) => (items || []).map(item => `
            <div class="d-flex justify-content-between py-2" style="border-bottom: 1px solid var(--dark-border);">
                <span class="small">${escapeHtml(item.name)}</span>
                <span class="money">${formatMoney(item.current_balance)}</span>
            </div>
        `).join('') || `<p class="small py-2" style="color: var(--dark-text-muted);">None recorded.</p>`;

        container.innerHTML = `
            <div class="panel receipt-card mb-4">
                <div class="panel-header">
                    <h2 class="h6 mb-0">Assets</h2>
                </div>
                <div class="panel-body compact">
                    ${renderRows(data.assets)}
                    <div class="d-flex justify-content-between pt-3 fw-semibold">
                        <span>Total Assets</span>
                        <span class="money">${formatMoney(data.total_assets)}</span>
                    </div>
                </div>
            </div>

            <div class="panel receipt-card mb-4">
                <div class="panel-header">
                    <h2 class="h6 mb-0">Liabilities</h2>
                </div>
                <div class="panel-body compact">
                    ${renderRows(data.liabilities)}
                    <div class="d-flex justify-content-between pt-3 fw-semibold">
                        <span>Total Liabilities</span>
                        <span class="money">${formatMoney(data.total_liabilities)}</span>
                    </div>
                </div>
            </div>

            <div class="panel receipt-card mb-4">
                <div class="panel-header">
                    <h2 class="h6 mb-0">Equity</h2>
                </div>
                <div class="panel-body compact">
                    ${renderRows(data.equity)}
                    <div class="d-flex justify-content-between py-2" style="border-bottom: 1px solid var(--dark-border);">
                        <span class="small">Retained Earnings</span>
                        <span class="money">${formatMoney(data.retained_earnings)}</span>
                    </div>
                    <div class="d-flex justify-content-between pt-3 fw-semibold">
                        <span>Total Equity</span>
                        <span class="money">${formatMoney(data.total_equity)}</span>
                    </div>
                </div>
            </div>

            <div class="panel receipt-card">
                <div class="panel-body compact">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small">Total Liabilities + Equity</span>
                        <span class="money">${formatMoney(data.total_liabilities_and_equity)}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="h6 mb-0">Balanced?</span>
                        <span class="fw-bold" style="color: ${data.is_balanced ? '#4ADE80' : '#F87171'};">
                            ${data.is_balanced ? '✓ Yes' : '✗ No — check your records'}
                        </span>
                    </div>
                </div>
            </div>
        `;
    } catch (err) {
        console.error('Failed to load balance sheet:', err);
        container.innerHTML = `<p class="text-center py-5 text-danger">Could not reach the server.</p>`;
    }
}

async function loadTrialBalance() {
    const container = document.getElementById('reportContent');
    if (!container) return;
    container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading...</p>`;

    const asOf = document.getElementById('asOfDate')?.value || '';

    try {
        const response = await fetch(`${API_URL}?action=trial-balance&as_of=${asOf}`);
        const data = await response.json();

        if (!data.success) {
            container.innerHTML = `<p class="text-center py-5 text-danger">Failed to load report.</p>`;
            return;
        }

        const rows = (data.lines || []).map(line => `
            <tr>
                <td>${escapeHtml(line.name)}</td>
                <td><span class="small" style="color: var(--dark-text-muted);">${escapeHtml(line.type_name)}</span></td>
                <td class="text-end money">${line.debit_balance > 0 ? formatMoney(line.debit_balance) : '—'}</td>
                <td class="text-end money">${line.credit_balance > 0 ? formatMoney(line.credit_balance) : '—'}</td>
            </tr>
        `).join('');

        container.innerHTML = `
            <div class="panel receipt-card">
                <div class="table-responsive">
                    <table class="table table-transactions mb-0">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Type</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows}
                        </tbody>
                        <tfoot>
                            <tr style="border-top: 2px solid var(--dark-border);">
                                <td colspan="2" class="fw-bold">TOTAL</td>
                                <td class="text-end money fw-bold">${formatMoney(data.total_debit)}</td>
                                <td class="text-end money fw-bold">${formatMoney(data.total_credit)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="panel receipt-card mt-4">
                <div class="panel-body compact">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="h6 mb-0">Books Balanced?</span>
                        <span class="fw-bold" style="color: ${data.is_balanced ? '#4ADE80' : '#F87171'};">
                            ${data.is_balanced ? '✓ Yes — Total Debits = Total Credits' : '✗ No — discrepancy found, review your entries'}
                        </span>
                    </div>
                </div>
            </div>
        `;
    } catch (err) {
        console.error('Failed to load trial balance:', err);
        container.innerHTML = `<p class="text-center py-5 text-danger">Could not reach the server.</p>`;
    }
}

// ---------- DOM Content Loaded Initializations ----------

document.addEventListener('DOMContentLoaded', () => {
    // Populate default date inputs
    const dateFromEl = document.getElementById('dateFrom');
    const dateToEl = document.getElementById('dateTo');
    const asOfDateEl = document.getElementById('asOfDate');

    if (dateFromEl) dateFromEl.value = dateFromStr;
    if (dateToEl) dateToEl.value = today;
    if (asOfDateEl) asOfDateEl.value = today;

    // Tab Event Listeners
    document.getElementById('tabIncomeStatement')?.addEventListener('click', () => setActiveTab('income-statement'));
    document.getElementById('tabBalanceSheet')?.addEventListener('click', () => setActiveTab('balance-sheet'));
    document.getElementById('tabTrialBalance')?.addEventListener('click', () => setActiveTab('trial-balance'));

    // Generate Button Listener
    document.getElementById('generateReportBtn')?.addEventListener('click', () => {
        if (currentReport === 'income-statement') {
            loadIncomeStatement();
        } else if (currentReport === 'balance-sheet') {
            loadBalanceSheet();
        } else {
            loadTrialBalance();
        }
    });

    // Journal entries expandable navigation
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

    // Export & PDF Preview Modal Handler
    const exportBtn = document.getElementById('exportBtn');
    const modal = document.getElementById('pdfPreviewModal');
    const closeModalBtn = document.getElementById('closePdfModal');
    const iframe = document.getElementById('pdfPreviewFrame');
    const downloadBtn = document.getElementById('pdfDownloadBtn');
    const modalTitle = document.getElementById('pdfModalTitle');

    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            const activeTab = currentReport;

            const dateFrom = document.getElementById('dateFrom')?.value || '';
            const dateTo = document.getElementById('dateTo')?.value || '';
            const asOf = document.getElementById('asOfDate')?.value || dateTo || today;

            let action = '';
            let isPdf = true;
            let titleText = 'Document Preview';
            const params = new URLSearchParams();

            switch (activeTab) {
                case 'income-statement':
                    action = 'income-statement-pdf';
                    titleText = 'Income Statement Preview';
                    if (dateFrom) params.append('date_from', dateFrom);
                    if (dateTo) params.append('date_to', dateTo);
                    break;

                case 'balance-sheet':
                    action = 'balance-sheet-pdf';
                    titleText = 'Balance Sheet Preview';
                    if (asOf) params.append('as_of', asOf);
                    break;

                case 'trial-balance':
                    action = 'trial-balance-xlsx';
                    isPdf = false; // Excel files do not support iframe preview
                    if (asOf) params.append('as_of', asOf);
                    break;

                default:
                    alert('Unknown report type.');
                    return;
            }

            params.append('action', action);
            const baseUrl = `${EXPORT_URL}?` + params.toString();

            if (isPdf) {
                const previewUrl = baseUrl + '&preview=1&t=' + new Date().getTime();
                if (modalTitle) modalTitle.textContent = titleText;
                if (iframe) iframe.src = previewUrl;
                if (downloadBtn) downloadBtn.href = baseUrl;
                if (modal) modal.style.display = 'flex';
            } else {
                window.location.href = baseUrl;
            }
        });
    }

    // Close Modal Event Handlers
    const closeModal = () => {
        if (modal) modal.style.display = 'none';
        if (iframe) iframe.src = '';
    };

    closeModalBtn?.addEventListener('click', closeModal);

    modal?.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal && modal.style.display === 'flex') {
            closeModal();
        }
    });

    // Logout button listener
    document.getElementById('logoutBtn')?.addEventListener('click', async (e) => {
        e.preventDefault();
        try {
            await fetch('routes/auth.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'logout' }),
            });
        } catch (err) {
            console.error('Logout failed:', err);
        }
        window.location.href = '../frontend/login.html';
    });

    // Initial load when opening the page
    loadIncomeStatement();
});