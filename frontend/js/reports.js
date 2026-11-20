const API_URL = '../backend/routes/reports.php';
const EXPORT_URL = '../backend/routes/export.php'; // Adjust path if your export script is located elsewhere
const JOURNAL_API_URL = 'routes/journal.php';
const PROJECT_PNL_URL = 'reports/project_pnl.php';

let currentReport = 'income-statement';
let currentCashFlowData = null;
let currentCashFlowAnalysisChart = null;
let cashFlowAnalysisRequestId = 0;
let reportReloadTimeout;
let activeReportRequestId = 0;

// Set up default dates: 1st of current month to today
const today = new Date().toISOString().split('T')[0];
const firstOfMonth = new Date();
firstOfMonth.setDate(1);
const dateFromStr = firstOfMonth.toISOString().split('T')[0];

function setActiveTab(report) {
    window.clearTimeout(reportReloadTimeout);
    currentReport = report;

    document.getElementById('tabIncomeStatement')?.classList.toggle('primary', report === 'income-statement');
    document.getElementById('tabBalanceSheet')?.classList.toggle('primary', report === 'balance-sheet');
    document.getElementById('tabTrialBalance')?.classList.toggle('primary', report === 'trial-balance');
    document.getElementById('tabCashFlowAnalysis')?.classList.toggle('primary', report === 'cash-flow-analysis');
    document.getElementById('tabCashFlowStatement')?.classList.toggle('primary', report === 'cash-flow-statement');
    document.getElementById('incomeStatementDateControls')?.classList.toggle('d-none', report !== 'income-statement');
    document.getElementById('balanceSheetDateControl')?.classList.toggle('d-none', report !== 'balance-sheet');
    document.getElementById('trialBalanceDateControl')?.classList.toggle('d-none', report !== 'trial-balance');
    document.getElementById('cashFlowAnalysisDateControls')?.classList.toggle('d-none', report !== 'cash-flow-analysis');
    document.getElementById('cashFlowStatementDateControls')?.classList.toggle('d-none', report !== 'cash-flow-statement');
    document.getElementById('exportBtn')?.classList.toggle('d-none', report === 'cash-flow-analysis');
    document.getElementById('printIncomeStatementBtn')?.classList.toggle('d-none', report !== 'income-statement');
    document.getElementById('printBalanceSheetBtn')?.classList.toggle('d-none', report !== 'balance-sheet');
    document.getElementById('printTrialBalanceBtn')?.classList.toggle('d-none', report !== 'trial-balance');
    const exportButtonLabel = document.getElementById('exportButtonLabel');
    if (exportButtonLabel) exportButtonLabel.textContent = 'Export';
    document.getElementById('printCashFlowBtn')?.classList.toggle('d-none', report !== 'cash-flow-statement');
    document.getElementById('exportCashFlowExcelBtn')?.classList.toggle('d-none', report !== 'cash-flow-statement');
    currentCashFlowAnalysisChart?.destroy();
    currentCashFlowAnalysisChart = null;
    currentCashFlowData = null;
    updateCashFlowActionAvailability();

    const container = document.getElementById('reportContent');
    if (container) {
        container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading report...</p>`;
    }
    loadActiveReport();
}

function loadActiveReport() {
    const requestId = ++activeReportRequestId;
    if (currentReport === 'income-statement') {
        loadIncomeStatement(requestId);
    } else if (currentReport === 'balance-sheet') {
        loadBalanceSheet(requestId);
    } else if (currentReport === 'trial-balance') {
        loadTrialBalance(requestId);
    } else if (currentReport === 'cash-flow-analysis') {
        loadCashFlowAnalysis(requestId);
    } else if (currentReport === 'cash-flow-statement') {
        loadCashFlowStatement(requestId);
    }
}

function formatMoney(amount) {
    const num = parseFloat(amount) || 0;
    const sign = num < 0 ? '-' : '';
    return sign + '₱' + Math.abs(num).toLocaleString(undefined, { minimumFractionDigits: 2 });
}

function formatStatementDate(value) {
    return window.formatDateMDY(value, '');
}

function formatLongStatementDate(value) {
    return window.formatDateMDY(value, '');
}

function renderStatementFooter(businessName) {
    const generatedAt = window.formatDateTimeMDY(new Date());
    return `<div class="statement-footer">Generated automatically by ${escapeHtml(businessName)} Accounting System on ${escapeHtml(generatedAt)}</div>`;
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ---------- Report Loaders ----------

async function loadIncomeStatement(requestId) {
    const container = document.getElementById('reportContent');
    if (!container) return;
    container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading...</p>`;

    const dateFrom = document.getElementById('dateFrom')?.value || '';
    const dateTo = document.getElementById('dateTo')?.value || '';

    try {
        const response = await fetch(`${API_URL}?action=income-statement&date_from=${dateFrom}&date_to=${dateTo}`);
        const data = await response.json();
        if (requestId !== activeReportRequestId) return;

        if (!data.success) {
            container.innerHTML = `<p class="text-center py-5 text-danger">Failed to load report.</p>`;
            return;
        }

        const renderStatementRows = (lines, emptyMessage) => lines?.length
            ? lines.map(line => `
                <tr>
                    <td>${escapeHtml(line.name)}</td>
                    <td class="text-right">${formatMoney(line.net_amount)}</td>
                </tr>
            `).join('')
            : `<tr><td colspan="2" class="statement-empty">${emptyMessage}</td></tr>`;
        const generatedAt = window.formatDateTimeMDY(new Date());

        container.innerHTML = `
            <article class="income-statement-paper">
                <table class="statement-header-table">
                    <tbody><tr>
                        <td>
                            <div class="statement-brand-title">${escapeHtml(data.business_name || 'WRS Accounting / KoboSocial')}</div>
                            <div class="statement-brand-sub">Financial Statement</div>
                        </td>
                        <td>
                            <div class="statement-report-title">Income Statement</div>
                            <div class="statement-report-meta">Period: ${escapeHtml(formatStatementDate(data.date_from))} &mdash; ${escapeHtml(formatStatementDate(data.date_to))}</div>
                        </td>
                    </tr></tbody>
                </table>

                <div class="statement-section-header">Revenue</div>
                <table class="statement-data-table">
                    <thead><tr><th>Account Name</th><th class="text-right" style="width: 30%;">Amount</th></tr></thead>
                    <tbody>
                        ${renderStatementRows(data.revenue_lines, 'No revenue recorded for this period.')}
                        <tr class="statement-subtotal-row"><td>Total Revenue</td><td class="text-right">${formatMoney(data.total_revenue)}</td></tr>
                    </tbody>
                </table>

                <div class="statement-section-header">Operating Expenses</div>
                <table class="statement-data-table">
                    <thead><tr><th>Account Name</th><th class="text-right" style="width: 30%;">Amount</th></tr></thead>
                    <tbody>
                        ${renderStatementRows(data.expense_lines, 'No expenses recorded for this period.')}
                        <tr class="statement-subtotal-row"><td>Total Operating Expenses</td><td class="text-right">${formatMoney(data.total_expense)}</td></tr>
                    </tbody>
                </table>

                <table class="statement-grand-total-table">
                    <tbody><tr class="statement-grand-total-row">
                        <td>Net Income / (Loss)</td>
                        <td class="text-right">${formatMoney(data.net_income)}</td>
                    </tr></tbody>
                </table>
                <div class="statement-footer">Generated automatically by ${escapeHtml(data.business_name || 'WRS Accounting / KoboSocial')} Accounting System on ${escapeHtml(generatedAt)}</div>
            </article>
        `;
    } catch (err) {
        if (requestId !== activeReportRequestId) return;
        console.error('Failed to load income statement:', err);
        container.innerHTML = `<p class="text-center py-5 text-danger">Could not reach the server.</p>`;
    }
}

async function loadBalanceSheet(requestId) {
    const container = document.getElementById('reportContent');
    if (!container) return;
    container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading...</p>`;

    const params = new URLSearchParams({
        action: 'balance-sheet',
        date_to: document.getElementById('balanceSheetAsOfDate')?.value || today,
    });

    try {
        const response = await fetch(`${API_URL}?${params.toString()}`);
        const data = await response.json();
        if (requestId !== activeReportRequestId) return;

        if (!data.success) {
            container.innerHTML = `<p class="text-center py-5 text-danger">Failed to load report.</p>`;
            return;
        }

        const businessName = data.business_name || 'WRS Accounting / KoboSocial';
        const renderRows = items => (items || []).map(item => `
            <tr><td>${escapeHtml(item.name)}</td><td class="text-right">${formatMoney(item.current_balance)}</td></tr>
        `).join('') || '<tr><td colspan="2" class="statement-empty">No accounts with active balances.</td></tr>';
        const assets = [...(data.current_assets || []), ...(data.non_current_assets || [])];
        const liabilities = [...(data.current_liabilities || []), ...(data.non_current_liabilities || [])];
        const renderSection = (title, items, totalLabel, total) => `
            <div class="statement-section-header">${title}</div>
            <table class="statement-data-table">
                <thead><tr><th>Account Name</th><th class="text-right" style="width: 30%;">Balance</th></tr></thead>
                <tbody>
                    ${renderRows(items)}
                    <tr class="statement-subtotal-row"><td>${totalLabel}</td><td class="text-right">${formatMoney(total)}</td></tr>
                </tbody>
            </table>
        `;

        container.innerHTML = `
            <article class="income-statement-paper">
                <table class="statement-header-table"><tbody><tr>
                    <td><div class="statement-brand-title">${escapeHtml(businessName)}</div><div class="statement-brand-sub">Financial Position</div></td>
                    <td><div class="statement-report-title">Balance Sheet</div><div class="statement-report-meta">As of ${escapeHtml(formatLongStatementDate(data.as_of))}</div></td>
                </tr></tbody></table>
                ${renderSection('Assets', assets, 'Total Assets', data.total_assets)}
                ${renderSection('Liabilities', liabilities, 'Total Liabilities', data.total_liabilities)}
                <div class="statement-section-header">Equity</div>
                <table class="statement-data-table">
                    <thead><tr><th>Account Name</th><th class="text-right" style="width: 30%;">Balance</th></tr></thead>
                    <tbody>
                        ${renderRows(data.equity)}
                        <tr><td>Retained Earnings (Unappropriated)</td><td class="text-right">${formatMoney(data.retained_earnings)}</td></tr>
                        <tr class="statement-subtotal-row"><td>Total Equity</td><td class="text-right">${formatMoney(data.total_equity)}</td></tr>
                    </tbody>
                </table>
                <table class="statement-grand-total-table"><tbody><tr class="statement-grand-total-row">
                    <td>Total Liabilities &amp; Equity</td><td class="text-right">${formatMoney(data.total_liabilities_and_equity)}</td>
                </tr></tbody></table>
                ${renderStatementFooter(businessName)}
            </article>
        `;
    } catch (err) {
        if (requestId !== activeReportRequestId) return;
        console.error('Failed to load balance sheet:', err);
        container.innerHTML = `<p class="text-center py-5 text-danger">Could not reach the server.</p>`;
    }
}

async function loadTrialBalance(requestId) {
    const container = document.getElementById('reportContent');
    if (!container) return;
    container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading...</p>`;

    const params = new URLSearchParams({
        action: 'trial-balance',
        date_to: document.getElementById('trialBalanceAsOfDate')?.value || today,
    });

    try {
        const response = await fetch(`${API_URL}?${params.toString()}`);
        const data = await response.json();
        if (requestId !== activeReportRequestId) return;

        if (!data.success) {
            container.innerHTML = `<p class="text-center py-5 text-danger">Failed to load report.</p>`;
            return;
        }

        const businessName = data.business_name || 'WRS Accounting / KoboSocial';
        const rows = data.lines?.length
            ? data.lines.map(line => `
                <tr>
                    <td>${escapeHtml(line.name)}</td>
                    <td>${escapeHtml(line.type_name)}</td>
                    <td class="text-right">${line.debit_balance > 0 ? formatMoney(line.debit_balance) : '—'}</td>
                    <td class="text-right">${line.credit_balance > 0 ? formatMoney(line.credit_balance) : '—'}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="4" class="statement-empty">No active accounts found as of this date.</td></tr>';

        container.innerHTML = `
            <article class="income-statement-paper">
                <table class="statement-header-table"><tbody><tr>
                    <td><div class="statement-brand-title">${escapeHtml(businessName)}</div><div class="statement-brand-sub">Trial Balance Report</div></td>
                    <td><div class="statement-report-title">Trial Balance</div><div class="statement-report-meta">As of ${escapeHtml(formatLongStatementDate(data.as_of))}</div></td>
                </tr></tbody></table>
                <table class="statement-data-table trial-balance-table">
                    <thead><tr>
                        <th>Account Name</th><th>Type</th>
                        <th class="text-right">Debit</th><th class="text-right">Credit</th>
                    </tr></thead>
                    <tbody>
                        ${rows}
                        <tr class="statement-subtotal-row">
                            <td colspan="2">TOTAL</td>
                            <td class="text-right">${formatMoney(data.total_debit)}</td>
                            <td class="text-right">${formatMoney(data.total_credit)}</td>
                        </tr>
                    </tbody>
                </table>
                ${renderStatementFooter(businessName)}
            </article>
        `;
    } catch (err) {
        if (requestId !== activeReportRequestId) return;
        console.error('Failed to load trial balance:', err);
        container.innerHTML = `<p class="text-center py-5 text-danger">Could not reach the server.</p>`;
    }
}

async function loadCashFlowStatement(requestId) {
    const container = document.getElementById('reportContent');
    if (!container) return;
    currentCashFlowData = null;
    updateCashFlowActionAvailability();
    container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading...</p>`;

    const dateFrom = document.getElementById('cashFlowDateFrom')?.value || '';
    const dateTo = document.getElementById('cashFlowDateTo')?.value || '';
    if (!dateFrom || !dateTo || dateFrom > dateTo) {
        container.innerHTML = `<p class="text-center py-5 text-danger">Choose a valid date range. The start date must not be after the end date.</p>`;
        updateCashFlowActionAvailability();
        return;
    }

    const params = new URLSearchParams({
        action: 'cash-flow-statement',
        date_from: dateFrom,
        date_to: dateTo,
    });

    try {
        const response = await fetch(`${API_URL}?${params.toString()}`);
        const data = await response.json();
        if (requestId !== activeReportRequestId) return;
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Failed to load the cash flow statement.');
        }
        currentCashFlowData = data;
        container.innerHTML = data.html;
        updateCashFlowActionAvailability();
    } catch (err) {
        if (requestId !== activeReportRequestId) return;
        console.error('Failed to load cash flow statement:', err);
        container.innerHTML = `<p class="text-center py-5 text-danger">${escapeHtml(err.message || 'Could not reach the server.')}</p>`;
        updateCashFlowActionAvailability();
    }
}

function renderCashFlowBreakdown(items, color) {
    if (!items.length) {
        return '<p class="text-center py-3 mb-0" style="color: var(--dark-text-muted);">No data for this period.</p>';
    }

    const maxTotal = Math.max(...items.map(item => Number(item.total) || 0), 0);
    return items.map(item => {
        const total = Number(item.total) || 0;
        const width = maxTotal > 0 ? (total / maxTotal) * 100 : 0;
        return `
            <div class="mb-3">
                <div class="d-flex justify-content-between small mb-1">
                    <span>${escapeHtml(item.category)}</span>
                    <span class="money fw-semibold">${formatMoney(total)}</span>
                </div>
                <div class="aging-progress">
                    <div style="background: ${color}; width: ${width}%; height: 100%;"></div>
                </div>
            </div>
        `;
    }).join('');
}

async function loadCashFlowAnalysis(reportRequestId) {
    const container = document.getElementById('reportContent');
    if (!container) return;
    currentCashFlowAnalysisChart?.destroy();
    currentCashFlowAnalysisChart = null;
    const requestId = ++cashFlowAnalysisRequestId;
    container.innerHTML = '<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading cash flow analysis...</p>';

    const dateFrom = document.getElementById('cashFlowAnalysisDateFrom')?.value || '';
    const dateTo = document.getElementById('cashFlowAnalysisDateTo')?.value || '';
    if (!dateFrom || !dateTo || dateFrom > dateTo) {
        container.innerHTML = '<p class="text-center py-5 text-danger">Choose a valid date range. The start date must not be after the end date.</p>';
        return;
    }

    const params = new URLSearchParams({
        action: 'cashflow-report',
        date_from: dateFrom,
        date_to: dateTo,
    });

    try {
        const response = await fetch(`${JOURNAL_API_URL}?${params.toString()}`);
        const data = await response.json();
        if (requestId !== cashFlowAnalysisRequestId
            || reportRequestId !== activeReportRequestId
            || currentReport !== 'cash-flow-analysis') return;
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Failed to load cash flow analysis.');
        }

        const inflow = Number(data.total_inflow) || 0;
        const outflow = Number(data.total_outflow) || 0;
        const netCashFlow = Number(data.net_cash_flow) || 0;
        container.innerHTML = `
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="kpi-label">Total Inflow</div>
                        <div class="kpi-value money positive">${formatMoney(inflow)}</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="kpi-label">Total Outflow</div>
                        <div class="kpi-value money negative">${formatMoney(outflow)}</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="kpi-label">Net Cash Flow</div>
                        <div class="kpi-value money ${netCashFlow >= 0 ? 'positive' : 'negative'}">${netCashFlow < 0 ? '-' : ''}${formatMoney(netCashFlow)}</div>
                    </div>
                </div>
            </div>
            <div class="panel mb-4">
                <div class="panel-header"><h2 class="h6 mb-0">Cash Flow Trend</h2></div>
                <div class="panel-body"><div class="cashflow-chart-container"><canvas id="cashFlowAnalysisChart"></canvas></div></div>
            </div>
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="panel">
                        <div class="panel-header"><h2 class="h6 mb-0">Inflow by Category</h2></div>
                        <div class="panel-body compact">${renderCashFlowBreakdown(data.inflow_breakdown || [], '#4ADE80')}</div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="panel">
                        <div class="panel-header"><h2 class="h6 mb-0">Outflow by Category</h2></div>
                        <div class="panel-body compact">${renderCashFlowBreakdown(data.outflow_breakdown || [], '#F87171')}</div>
                    </div>
                </div>
            </div>
        `;

        const canvas = document.getElementById('cashFlowAnalysisChart');
        const themeStyles = getComputedStyle(document.documentElement);
        const gridColor = themeStyles.getPropertyValue('--dark-border').trim();
        const tickColor = themeStyles.getPropertyValue('--dark-text-muted').trim();
        const chartSurface = themeStyles.getPropertyValue('--dark-surface').trim();
        const chartText = themeStyles.getPropertyValue('--dark-text').trim();
        const gradientIn = canvas.getContext('2d').createLinearGradient(0, 0, 0, 260);
        gradientIn.addColorStop(0, 'rgba(46, 144, 250, 0.35)');
        gradientIn.addColorStop(1, 'rgba(46, 144, 250, 0.02)');

        currentCashFlowAnalysisChart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Cash Inflow',
                        data: data.cash_in,
                        borderColor: '#2E90FA',
                        backgroundColor: gradientIn,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                    },
                    {
                        label: 'Cash Outflow',
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
                maintainAspectRatio: false,
                animation: false,
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
                        callbacks: { label: item => `${item.dataset.label}: ₱${item.formattedValue}` },
                    },
                },
                scales: {
                    y: {
                        ticks: { color: tickColor, callback: value => `₱${Number(value).toLocaleString()}`, font: { family: 'IBM Plex Mono', size: 11 } },
                        grid: { color: gridColor },
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: tickColor, font: { family: 'Inter', size: 11 } },
                    },
                },
            },
        });
    } catch (error) {
        if (requestId !== cashFlowAnalysisRequestId
            || reportRequestId !== activeReportRequestId
            || currentReport !== 'cash-flow-analysis') return;
        console.error('Failed to load cash flow analysis:', error);
        container.innerHTML = `<p class="text-center py-5 text-danger">${escapeHtml(error.message || 'Could not reach the server.')}</p>`;
    }
}

function updateCashFlowActionAvailability() {
    const dateFrom = document.getElementById('cashFlowDateFrom')?.value || '';
    const dateTo = document.getElementById('cashFlowDateTo')?.value || '';
    const isCurrentReport = currentReport === 'cash-flow-statement'
        && currentCashFlowData
        && currentCashFlowData.date_from === dateFrom
        && currentCashFlowData.date_to === dateTo;

    ['printCashFlowBtn', 'exportCashFlowExcelBtn'].forEach(id => {
        const button = document.getElementById(id);
        if (button) button.disabled = !isCurrentReport;
    });
}

// ---------- DOM Content Loaded Initializations ----------

document.addEventListener('DOMContentLoaded', () => {
    // Populate default date inputs
    const dateFromEl = document.getElementById('dateFrom');
    const dateToEl = document.getElementById('dateTo');
    const balanceSheetAsOfDateEl = document.getElementById('balanceSheetAsOfDate');
    const trialBalanceAsOfDateEl = document.getElementById('trialBalanceAsOfDate');
    const cashFlowAnalysisDateFromEl = document.getElementById('cashFlowAnalysisDateFrom');
    const cashFlowAnalysisDateToEl = document.getElementById('cashFlowAnalysisDateTo');
    const cashFlowDateFromEl = document.getElementById('cashFlowDateFrom');
    const cashFlowDateToEl = document.getElementById('cashFlowDateTo');

    if (dateFromEl) dateFromEl.value = dateFromStr;
    if (dateToEl) dateToEl.value = today;
    if (balanceSheetAsOfDateEl) balanceSheetAsOfDateEl.value = today;
    if (trialBalanceAsOfDateEl) trialBalanceAsOfDateEl.value = today;
    if (cashFlowAnalysisDateFromEl) cashFlowAnalysisDateFromEl.value = dateFromStr;
    if (cashFlowAnalysisDateToEl) cashFlowAnalysisDateToEl.value = today;
    if (cashFlowDateFromEl) cashFlowDateFromEl.value = dateFromStr;
    if (cashFlowDateToEl) cashFlowDateToEl.value = today;

    const projectSelect = document.getElementById('projectReportSelect');
    const openProjectReportButton = document.getElementById('openProjectReportBtn');
    projectSelect?.addEventListener('change', () => {
        if (openProjectReportButton) openProjectReportButton.disabled = !projectSelect.value;
    });
    openProjectReportButton?.addEventListener('click', () => {
        if (!projectSelect?.value) return;
        window.location.href = `project-details.php?project_id=${encodeURIComponent(projectSelect.value)}`;
    });
    if (projectSelect) {
        fetch(`${PROJECT_PNL_URL}?action=projects&status=active`)
            .then(response => response.json())
            .then(data => {
                if (!data.success) throw new Error(data.error || 'Failed to load projects.');
                projectSelect.replaceChildren(new Option(
                    data.projects.length ? '-- Select a project --' : 'No active projects',
                    ''
                ));
                data.projects.forEach(project => {
                    projectSelect.add(new Option(`${project.project_name} — ${project.client_name}`, String(project.id)));
                });
            })
            .catch(error => {
                console.error('Failed to load project reports:', error);
                projectSelect.replaceChildren(new Option('Could not load projects', ''));
            });
    }
    [cashFlowDateFromEl, cashFlowDateToEl].forEach(input => {
        input?.addEventListener('change', () => {
            currentCashFlowData = null;
            updateCashFlowActionAvailability();
        });
    });

    // Tab Event Listeners
    document.getElementById('tabIncomeStatement')?.addEventListener('click', () => setActiveTab('income-statement'));
    document.getElementById('tabBalanceSheet')?.addEventListener('click', () => setActiveTab('balance-sheet'));
    document.getElementById('tabTrialBalance')?.addEventListener('click', () => setActiveTab('trial-balance'));
    document.getElementById('tabCashFlowAnalysis')?.addEventListener('click', () => setActiveTab('cash-flow-analysis'));
    document.getElementById('tabCashFlowStatement')?.addEventListener('click', () => setActiveTab('cash-flow-statement'));

    const reportDateControls = {
        'dateFrom': 'income-statement',
        'dateTo': 'income-statement',
        'balanceSheetAsOfDate': 'balance-sheet',
        'trialBalanceAsOfDate': 'trial-balance',
        'cashFlowAnalysisDateFrom': 'cash-flow-analysis',
        'cashFlowAnalysisDateTo': 'cash-flow-analysis',
        'cashFlowDateFrom': 'cash-flow-statement',
        'cashFlowDateTo': 'cash-flow-statement',
    };
    Object.entries(reportDateControls).forEach(([id, report]) => {
        document.getElementById(id)?.addEventListener('change', () => {
            if (report === 'cash-flow-statement') {
                currentCashFlowData = null;
                updateCashFlowActionAvailability();
            }
            if (currentReport !== report) return;
            window.clearTimeout(reportReloadTimeout);
            reportReloadTimeout = window.setTimeout(loadActiveReport, 150);
        });
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
    const printCashFlowBtn = document.getElementById('printCashFlowBtn');
    const printIncomeStatementBtn = document.getElementById('printIncomeStatementBtn');
    const printBalanceSheetBtn = document.getElementById('printBalanceSheetBtn');
    const printTrialBalanceBtn = document.getElementById('printTrialBalanceBtn');
    const exportCashFlowExcelBtn = document.getElementById('exportCashFlowExcelBtn');

    printIncomeStatementBtn?.addEventListener('click', () => {
        if (currentReport === 'income-statement') window.print();
    });
    printBalanceSheetBtn?.addEventListener('click', () => {
        if (currentReport === 'balance-sheet') window.print();
    });
    printTrialBalanceBtn?.addEventListener('click', () => {
        if (currentReport === 'trial-balance') window.print();
    });

    printCashFlowBtn?.addEventListener('click', () => {
        if (!currentCashFlowData) return;
        window.print();
    });

    exportCashFlowExcelBtn?.addEventListener('click', () => {
        if (!currentCashFlowData) return;
        const params = new URLSearchParams({
            action: 'cash-flow-statement-xlsx',
            date_from: currentCashFlowData.date_from,
            date_to: currentCashFlowData.date_to,
        });
        window.location.href = `${EXPORT_URL}?${params.toString()}`;
    });

    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            const activeTab = currentReport;

            const dateFrom = document.getElementById('dateFrom')?.value || '';
            const dateTo = document.getElementById('dateTo')?.value || '';
            const balanceSheetAsOf = document.getElementById('balanceSheetAsOfDate')?.value || today;
            const trialBalanceAsOf = document.getElementById('trialBalanceAsOfDate')?.value || today;
            const cashFlowDateFrom = document.getElementById('cashFlowDateFrom')?.value || '';
            const cashFlowDateTo = document.getElementById('cashFlowDateTo')?.value || '';

            if (activeTab === 'cash-flow-statement') {
                if (!currentCashFlowData
                    || currentCashFlowData.date_from !== cashFlowDateFrom
                    || currentCashFlowData.date_to !== cashFlowDateTo) {
                    alert('Generate the cash flow statement before exporting it.');
                    return;
                }
            }

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
                    if (balanceSheetAsOf) params.append('date_to', balanceSheetAsOf);
                    break;

                case 'trial-balance':
                    action = 'trial-balance-xlsx';
                    isPdf = false; // Excel files do not support iframe preview
                    if (trialBalanceAsOf) params.append('date_to', trialBalanceAsOf);
                    break;

                case 'cash-flow-statement':
                    action = 'cash-flow-statement-pdf';
                    titleText = 'Statement of Cash Flows Preview';
                    params.append('date_from', cashFlowDateFrom);
                    params.append('date_to', cashFlowDateTo);
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

    // Keep old Cash Flow page bookmarks working after consolidating it into Reports.
    const requestedReport = new URLSearchParams(window.location.search).get('report');
    if (requestedReport === 'cash-flow-analysis') {
        setActiveTab('cash-flow-analysis');
    } else {
        setActiveTab('income-statement');
    }
});