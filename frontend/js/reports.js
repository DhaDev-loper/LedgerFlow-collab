const API_URL = 'routes/reports.php';
let currentReport = 'income-statement';

function setActiveTab(report) {
    currentReport = report;

    document.getElementById('tabIncomeStatement').classList.toggle('primary', report === 'income-statement');
    document.getElementById('tabBalanceSheet').classList.toggle('primary', report === 'balance-sheet');

    document.getElementById('dateRangeControls').classList.toggle('d-none', report === 'balance-sheet');
    document.getElementById('asOfControl').classList.toggle('d-none', report === 'income-statement');

    document.getElementById('reportContent').innerHTML =
        `<p class="text-center py-5" style="color: var(--dark-text-muted);">Click Generate to load this report.</p>`;
}

document.getElementById('tabIncomeStatement').addEventListener('click', () => setActiveTab('income-statement'));
document.getElementById('tabBalanceSheet').addEventListener('click', () => setActiveTab('balance-sheet'));

// Default dates: this month's 1st through today, and "as of" = today
const today = new Date().toISOString().split('T')[0];
const firstOfMonth = new Date();
firstOfMonth.setDate(1);
document.getElementById('dateFrom').value = firstOfMonth.toISOString().split('T')[0];
document.getElementById('dateTo').value = today;
document.getElementById('asOfDate').value = today;

document.getElementById('generateReportBtn').addEventListener('click', () => {
    if (currentReport === 'income-statement') {
        loadIncomeStatement();
    } else {
        loadBalanceSheet();
    }
});

function formatMoney(amount) {
    const num = parseFloat(amount) || 0;
    const sign = num < 0 ? '-' : '';
    return sign + '₱' + Math.abs(num).toLocaleString(undefined, { minimumFractionDigits: 2 });
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

async function loadIncomeStatement() {
    const container = document.getElementById('reportContent');
    container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading...</p>`;

    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;

    try {
        const response = await fetch(`${API_URL}?action=income-statement&date_from=${dateFrom}&date_to=${dateTo}`);
        const data = await response.json();

        if (!data.success) {
            container.innerHTML = `<p class="text-center py-5 text-danger">Failed to load report.</p>`;
            return;
        }

        const revenueRows = data.revenue_lines.map(line => `
            <div class="d-flex justify-content-between py-2" style="border-bottom: 1px solid var(--dark-border);">
                <span class="small">${escapeHtml(line.name)}</span>
                <span class="money">${formatMoney(line.net_amount)}</span>
            </div>
        `).join('') || `<p class="small py-2" style="color: var(--dark-text-muted);">No revenue recorded in this period.</p>`;

        const expenseRows = data.expense_lines.map(line => `
            <div class="d-flex justify-content-between py-2" style="border-bottom: 1px solid var(--dark-border);">
                <span class="small">${escapeHtml(line.name)}</span>
                <span class="money">${formatMoney(line.net_amount)}</span>
            </div>
        `).join('') || `<p class="small py-2" style="color: var(--dark-text-muted);">No expenses recorded in this period.</p>`;

        const isProfitable = data.net_income >= 0;

        container.innerHTML = `
            <div class="panel mb-4">
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

            <div class="panel mb-4">
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

            <div class="panel">
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
    container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading...</p>`;

    const asOf = document.getElementById('asOfDate').value;

    try {
        const response = await fetch(`${API_URL}?action=balance-sheet&as_of=${asOf}`);
        const data = await response.json();

        if (!data.success) {
            container.innerHTML = `<p class="text-center py-5 text-danger">Failed to load report.</p>`;
            return;
        }

        const renderRows = (items) => items.map(item => `
            <div class="d-flex justify-content-between py-2" style="border-bottom: 1px solid var(--dark-border);">
                <span class="small">${escapeHtml(item.name)}</span>
                <span class="money">${formatMoney(item.current_balance)}</span>
            </div>
        `).join('') || `<p class="small py-2" style="color: var(--dark-text-muted);">None recorded.</p>`;

        container.innerHTML = `
            <div class="panel mb-4">
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

            <div class="panel mb-4">
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

            <div class="panel mb-4">
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

            <div class="panel">
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

// ---------- Load Income Statement by default on page open ----------
loadIncomeStatement();

document.getElementById('logoutBtn')?.addEventListener('click', async (e) => {
    e.preventDefault();
    await fetch('routes/auth.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'logout' }),
    });
    window.location.href = '../frontend/login.html';
});