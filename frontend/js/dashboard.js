/* =====================================================================
   Dashboard logic
   ===================================================================== */

const gridColor = 'rgba(255, 255, 255, 0.06)';
const tickColor = 'rgba(241, 245, 249, 0.55)';

async function loadCashflowChart() {
    const ctx = document.getElementById('cashflowChart');
    if (!ctx) return;

    try {
        const response = await fetch('routes/journal.php?action=cashflow-chart&days=14');
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
                        backgroundColor: '#14243F',
                        borderColor: 'rgba(255,255,255,0.1)',
                        borderWidth: 1,
                        titleColor: '#F1F5F9',
                        bodyColor: '#F1F5F9',
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
            const icon = isInflow ? '↓' : '↑';
            const iconClass = isInflow ? 'in' : 'out';
            const amountClass = isInflow ? 'text-success' : 'text-danger';
            const sign = isInflow ? '+' : '-';

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

async function loadQuickTransactionAccounts() {
    try {
        const response = await fetch('routes/journal.php?action=reference-data');
        const data = await response.json();

        if (!data.success) return;

        quickTransactionAccounts = data.accounts;

        const assetAccounts = data.accounts.filter(a => a.type_name === 'Asset');
        const revenueAccounts = data.accounts.filter(a => a.type_name === 'Revenue');
        const expenseAccounts = data.accounts.filter(a => a.type_name === 'Expense');

        const optionsFor = (list) => list.map(a => `<option value="${a.id}">${a.name}</option>`).join('');

        document.getElementById('saleReceivedInto').innerHTML = optionsFor(assetAccounts);
        document.getElementById('saleRevenueAccount').innerHTML = optionsFor(revenueAccounts);

        document.getElementById('expensePaidFrom').innerHTML = optionsFor(assetAccounts);
        document.getElementById('expenseAccount').innerHTML = optionsFor(expenseAccounts);

        window.CASH_INFLOW_TYPE_ID = data.types.find(t => t.name === 'Cash Inflow')?.id;
        window.CASH_OUTFLOW_TYPE_ID = data.types.find(t => t.name === 'Cash Outflow')?.id;

    } catch (err) {
        console.error('Failed to load quick transaction accounts:', err);
    }
}

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
                from_account_id: fromAccount,
                to_account_id: toAccount,
                amount: amount,
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
                from_account_id: fromAccount,
                to_account_id: toAccount,
                amount: amount,
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