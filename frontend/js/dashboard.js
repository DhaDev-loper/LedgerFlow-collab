/* =====================================================================
   Dashboard logic
   ===================================================================== */

const dashboardThemeStyles = getComputedStyle(document.documentElement);
const gridColor = dashboardThemeStyles.getPropertyValue('--dark-border').trim();
const tickColor = dashboardThemeStyles.getPropertyValue('--dark-text-muted').trim();
const chartSurface = dashboardThemeStyles.getPropertyValue('--dark-surface').trim();
const chartText = dashboardThemeStyles.getPropertyValue('--dark-text').trim();
let dashboardCashFlowRequestId = 0;


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

function getDashboardDateInputValue(date) {
    const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
    return localDate.toISOString().slice(0, 10);
}

async function loadDashboardCashFlowKpis() {
    const periodFilter = document.getElementById('periodFilter');
    const periodDays = Number(periodFilter?.value);
    if (![1, 7, 30, 365].includes(periodDays)) return;

    const endDate = new Date();
    const startDate = new Date(endDate);
    startDate.setDate(startDate.getDate() - periodDays + 1);
    const dateFrom = getDashboardDateInputValue(startDate);
    const dateTo = getDashboardDateInputValue(endDate);
    const periodLabel = periodFilter.selectedOptions[0]?.textContent.trim() || 'Selected period';
    const requestId = ++dashboardCashFlowRequestId;

    try {
        const params = new URLSearchParams({
            action: 'cashflow-report',
            date_from: dateFrom,
            date_to: dateTo,
            summary_only: '1',
        });
        const response = await fetch(`routes/journal.php?${params.toString()}`);
        const data = await response.json();
        if (requestId !== dashboardCashFlowRequestId) return;
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Could not load cash flow totals.');
        }

        document.getElementById('dashboardCashInflow').textContent = formatMoney(data.total_inflow);
        document.getElementById('dashboardCashOutflow').textContent = formatMoney(data.total_outflow);
        const netCashFlow = Number(data.net_cash_flow) || 0;
        const netElement = document.getElementById('dashboardNetCashFlow');
        netElement.textContent = `${netCashFlow < 0 ? '-' : ''}${formatMoney(netCashFlow)}`;
        netElement.classList.toggle('positive', netCashFlow >= 0);
        netElement.classList.toggle('negative', netCashFlow < 0);

        ['dashboardCashInflowPeriod', 'dashboardCashOutflowPeriod', 'dashboardNetCashFlowPeriod']
            .forEach(id => {
                document.getElementById(id).textContent = periodLabel;
            });
    } catch (error) {
        if (requestId !== dashboardCashFlowRequestId) return;
        console.error('Failed to load dashboard cash flow KPIs:', error);
        ['dashboardCashInflow', 'dashboardCashOutflow', 'dashboardNetCashFlow']
            .forEach(id => {
                document.getElementById(id).textContent = 'Unavailable';
            });
    }
}

document.getElementById('toggleDashboardKpiView')?.addEventListener('click', event => {
    const button = event.currentTarget;
    const showCashFlow = button.dataset.view !== 'cash-flow';
    const arApView = document.getElementById('receivablesPayablesKpiView');
    const cashFlowView = document.getElementById('cashFlowKpiView');
    const activeView = showCashFlow ? cashFlowView : arApView;
    const inactiveView = showCashFlow ? arApView : cashFlowView;

    inactiveView.classList.add('d-none');
    inactiveView.classList.remove('dashboard-kpi-view-enter');
    inactiveView.setAttribute('aria-hidden', 'true');
    inactiveView.inert = true;

    activeView.classList.remove('d-none');
    activeView.classList.add('dashboard-kpi-view-enter');
    activeView.setAttribute('aria-hidden', 'false');
    activeView.inert = false;
    button.dataset.view = showCashFlow ? 'cash-flow' : 'receivables-payables';
    button.setAttribute('aria-pressed', String(showCashFlow));
    button.textContent = showCashFlow ? 'Show Receivables & Payables' : 'Show Cash Flow KPIs';
});

// =====================================================================
// Shared helpers — defined ONCE, used everywhere in this file
// =====================================================================

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, match => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[match]));
}

function formatDate(dateStr) {
    return window.formatDateMDY(dateStr);
}

function formatMoney(amount) {
    return '₱' + Math.abs(parseFloat(amount) || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });
}

function showToast(message, type = 'success') {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        document.body.appendChild(container);
    }

    const iconMap = {
        success: 'bi-check-circle-fill',
        danger: 'bi-x-circle-fill',
        info: 'bi-info-circle-fill',
    };
    const toast = document.createElement('div');
    toast.className = `toast-glass toast-${type}`;
    toast.innerHTML = `<i class="bi ${iconMap[type] || iconMap.info} fs-5"></i><span>${escapeHtml(message)}</span>`;
    container.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 50);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

async function loadDueNotifications() {
    const list = document.getElementById('dueNotificationsList');
    const countBadge = document.getElementById('dueNotificationsCount');
    const notificationButton = document.getElementById('dueNotificationsButton');
    if (!list || !countBadge || !notificationButton) return;

    list.innerHTML = '<p class="text-center py-4 mb-0">Loading payment notifications...</p>';
    try {
        const response = await fetch('routes/journal.php?action=due-notifications');
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Could not load payment due notifications.');
        }

        const items = Array.isArray(data.items) ? data.items : [];
        countBadge.textContent = items.length > 99 ? '99+' : String(items.length);
        countBadge.classList.toggle('d-none', items.length === 0);
        notificationButton.setAttribute(
            'aria-label',
            items.length ? `${items.length} payment due notifications` : 'No payment due notifications'
        );

        if (items.length === 0) {
            list.innerHTML = '<p class="text-center py-4 mb-0">No overdue payments or items due in the next 7 days.</p>';
            return;
        }

        list.replaceChildren();
        const overdueItems = items.filter(item => item.is_overdue);
        const upcomingItems = items.filter(item => !item.is_overdue);
        const renderSection = (title, sectionItems) => {
            if (!sectionItems.length) return;
            const section = document.createElement('section');
            section.className = 'mb-4';
            const heading = document.createElement('h6');
            heading.className = 'mb-2';
            heading.textContent = title;
            const itemList = document.createElement('div');
            itemList.className = 'd-flex flex-column gap-2';

            sectionItems.forEach(item => {
                const row = document.createElement('div');
                row.className = 'd-flex flex-wrap justify-content-between align-items-center gap-3 rounded border p-3';
                row.style.borderColor = 'var(--dark-border)';

                const details = document.createElement('div');
                details.className = 'flex-grow-1';
                const party = document.createElement('div');
                party.className = 'fw-semibold';
                party.textContent = item.company_name
                    ? `${item.party_name} (${item.company_name})`
                    : item.party_name;
                const description = document.createElement('div');
                description.className = 'small';
                description.textContent = [
                    item.type === 'ar' ? 'A/R · Customer invoice' : 'A/P · Vendor bill',
                    item.reference_no ? `Ref ${item.reference_no}` : item.description,
                    `Due ${formatDate(item.due_date)}`,
                ].filter(Boolean).join(' · ');
                details.append(party, description);

                const right = document.createElement('div');
                right.className = 'd-flex align-items-center gap-3';
                const amount = document.createElement('strong');
                amount.className = 'money text-nowrap';
                amount.textContent = formatMoney(item.remaining);
                const link = document.createElement('a');
                link.className = 'btn quick-action-btn';
                link.href = `clients.php?client_id=${encodeURIComponent(Number(item.client_id))}`;
                link.textContent = 'View';
                link.setAttribute(
                    'aria-label',
                    `View ${item.type === 'ar' ? 'customer' : 'vendor'} ${item.party_name}`
                );
                right.append(amount, link);
                row.append(details, right);
                itemList.appendChild(row);
            });

            section.append(heading, itemList);
            list.appendChild(section);
        };

        renderSection('Overdue', overdueItems);
        renderSection('Due in the next 7 days', upcomingItems);
    } catch (error) {
        countBadge.classList.add('d-none');
        notificationButton.setAttribute('aria-label', 'Payment notifications unavailable');
        list.replaceChildren();
        const errorMessage = document.createElement('p');
        errorMessage.className = 'text-center text-danger py-4 mb-0';
        errorMessage.textContent = error.message;
        list.appendChild(errorMessage);
        console.error('Failed to load payment due notifications:', error);
    }
}

document.getElementById('dueNotificationsModal')?.addEventListener('show.bs.modal', loadDueNotifications);

// =====================================================================
// Recent Transactions table
// =====================================================================

let recentTransactionsPage = 1;
let recentTransactionsRowsPerPage = 10;
let recentTransactionsRequestId = 0;
let recentTransactionsSearchTimeout;

function applyRecentTransactionFilters() {
    recentTransactionsPage = 1;
    loadRecentTransactions();
}

document.getElementById('transactionSearch')?.addEventListener('input', () => {
    window.clearTimeout(recentTransactionsSearchTimeout);
    recentTransactionsSearchTimeout = window.setTimeout(applyRecentTransactionFilters, 300);
});
['transactionTypeFilter', 'transactionDateFrom', 'transactionDateTo'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', applyRecentTransactionFilters);
});
document.getElementById('clearTransactionFilters')?.addEventListener('click', () => {
    ['transactionSearch', 'transactionTypeFilter', 'transactionDateFrom', 'transactionDateTo'].forEach(id => {
        const control = document.getElementById(id);
        if (control) control.value = '';
    });
    window.clearTimeout(recentTransactionsSearchTimeout);
    applyRecentTransactionFilters();
});

document.getElementById('dashboardRecentRowsPerPage')?.addEventListener('change', event => {
    const selectedPageSize = Number(event.target.value);
    if (![10, 25, 50, 100].includes(selectedPageSize)) return;

    recentTransactionsRowsPerPage = selectedPageSize;
    recentTransactionsPage = 1;
    loadRecentTransactions();
});

function renderRecentTransactionsPagination(total) {
    const pagination = document.getElementById('dashboardRecentPagination');
    const pageInfo = document.getElementById('dashboardRecentPageInfo');
    if (!pagination || !pageInfo) return;

    const totalPages = Math.max(1, Math.ceil(total / recentTransactionsRowsPerPage));
    recentTransactionsPage = Math.min(recentTransactionsPage, totalPages);

    const start = total === 0
        ? 0
        : (recentTransactionsPage - 1) * recentTransactionsRowsPerPage + 1;
    const end = Math.min(recentTransactionsPage * recentTransactionsRowsPerPage, total);

    pageInfo.textContent = total === 0
        ? 'No entries'
        : `Showing ${start}-${end} of ${total}`;

    let html = `
        <li class="page-item ${recentTransactionsPage === 1 ? 'disabled' : ''}">
            <button class="page-link" data-page="${recentTransactionsPage - 1}">Previous</button>
        </li>
    `;

    for (let page = 1; page <= totalPages; page++) {
        html += `
            <li class="page-item ${page === recentTransactionsPage ? 'active' : ''}">
                <button class="page-link" data-page="${page}">${page}</button>
            </li>
        `;
    }

    html += `
        <li class="page-item ${recentTransactionsPage === totalPages ? 'disabled' : ''}">
            <button class="page-link" data-page="${recentTransactionsPage + 1}">Next</button>
        </li>
    `;

    pagination.innerHTML = html;
    pagination.querySelectorAll('[data-page]').forEach(button => {
        button.addEventListener('click', () => {
            const page = Number(button.dataset.page);
            if (page < 1 || page > totalPages || page === recentTransactionsPage) return;

            recentTransactionsPage = page;
            loadRecentTransactions();
        });
    });
}

async function loadRecentTransactions() {
    const tbody = document.getElementById('recentTransactionsBody');
    if (!tbody) return;

    const requestId = ++recentTransactionsRequestId;
    try {
        const offset = (recentTransactionsPage - 1) * recentTransactionsRowsPerPage;
        const params = new URLSearchParams({
            action: 'recent',
            limit: String(recentTransactionsRowsPerPage),
            offset: String(offset),
        });
        const search = document.getElementById('transactionSearch')?.value.trim() || '';
        const typeId = document.getElementById('transactionTypeFilter')?.value || '';
        const dateFrom = document.getElementById('transactionDateFrom')?.value || '';
        const dateTo = document.getElementById('transactionDateTo')?.value || '';
        if (search) params.set('search', search);
        if (typeId) params.set('transaction_type_id', typeId);
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);

        const response = await fetch(`routes/journal.php?${params.toString()}`);
        const data = await response.json();
        if (requestId !== recentTransactionsRequestId) return;

        if (!response.ok || !data.success || !Array.isArray(data.transactions)) {
            throw new Error(data.error || 'Failed to load recent transactions.');
        }

        if (data.transactions.length === 0) {
            const hasFilters = Boolean(
                document.getElementById('transactionSearch')?.value.trim()
                || document.getElementById('transactionTypeFilter')?.value
                || document.getElementById('transactionDateFrom')?.value
                || document.getElementById('transactionDateTo')?.value
            );
            const emptyMessage = hasFilters ? 'No transactions match these filters.' : 'No transactions yet.';
            tbody.innerHTML = `<tr><td colspan="5" class="text-center py-3" style="color: var(--dark-text-muted);">${emptyMessage}</td></tr>`;
        } else {
            tbody.innerHTML = data.transactions.map(tx => {
                const isInflow = tx.type_name === 'Cash Inflow';
                const isOutflow = tx.type_name === 'Cash Outflow';
                const icon = isInflow ? '↑' : isOutflow ? '↓' : '↔';
                const iconClass = isInflow ? 'in' : isOutflow ? 'out' : '';
                const amountClass = isInflow ? 'text-success' : isOutflow ? 'text-danger' : '';
                const sign = isInflow ? '+' : isOutflow ? '-' : '';

                return `
                    <tr>
                        <td><span class="tx-icon ${iconClass}">${icon}</span></td>
                        <td class="dashboard-recent-description">
                            <div class="dashboard-recent-primary" title="${escapeHtml(tx.description || '')}">${escapeHtml(tx.description || tx.type_name)}</div>
                            ${tx.client_name ? `<div class="dashboard-recent-secondary" title="${escapeHtml(tx.client_name)}">${escapeHtml(tx.client_name)}</div>` : ''}
                        </td>
                        <td class="dashboard-recent-category"><span class="small" title="${escapeHtml(tx.category_name || tx.type_name)}">${escapeHtml(tx.category_name || tx.type_name)}</span></td>
                        <td class="dashboard-recent-date"><span class="small">${formatDate(tx.transaction_date)}</span></td>
                        <td class="dashboard-recent-amount text-end money fw-semibold ${amountClass}">
                            ${sign}${formatMoney(tx.total_amount)}
                        </td>
                    </tr>
                `;
            }).join('');
        }

        renderRecentTransactionsPagination(Number(data.total) || 0);

    } catch (err) {
        if (requestId !== recentTransactionsRequestId) return;
        console.error('Failed to load recent transactions:', err);
        tbody.innerHTML = `<tr><td colspan="5" class="text-center py-3 text-danger">Could not load transactions.</td></tr>`;
        renderRecentTransactionsPagination(0);
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

        const totalAssets = document.getElementById('kpiTotalAssets');
        const cashInflow = document.getElementById('kpiCashInflow');
        const cashOutflow = document.getElementById('kpiCashOutflow');
        if (totalAssets) totalAssets.textContent = formatMoney(data.total_assets);
        if (cashInflow) cashInflow.textContent = '+' + formatMoney(data.cash_inflow);
        if (cashOutflow) cashOutflow.textContent = '-' + formatMoney(data.cash_outflow);

        const netCashFlowEl = document.getElementById('kpiNetCashFlow');
        if (!netCashFlowEl) return;
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

        const transactionTypeFilter = document.getElementById('transactionTypeFilter');
        if (transactionTypeFilter) {
            transactionTypeFilter.replaceChildren(new Option('All types', ''));
            data.types.forEach(type => transactionTypeFilter.add(new Option(type.name, String(type.id))));
        }

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

        const customers = (data.clients || []).filter(client =>
            client.status === 'active' && (client.party_type === 'customer' || client.party_type === 'both')
        );
        document.querySelectorAll('.project-client-select').forEach(select => {
            select.replaceChildren(new Option('-- Select customer --', ''));
            customers.forEach(customer => {
                const label = customer.company_name ? `${customer.name} (${customer.company_name})` : customer.name;
                select.add(new Option(label, String(customer.id)));
            });
        });
        const projectList = document.getElementById('activeProjectNames');
        if (projectList) {
            projectList.replaceChildren(...(data.projects || []).map(project => new Option(project.project_name, project.project_name)));
        }
        document.querySelectorAll('.project-name-input').forEach(input => {
            const clientSelect = document.getElementById(input.id.replace('Name', 'Client'));
            if (!clientSelect) return;
            const syncProjectClient = () => {
                const name = input.value.trim().toLocaleLowerCase();
                const match = (data.projects || []).find(project => project.project_name.toLocaleLowerCase() === name);
                if (match) clientSelect.value = String(match.client_id);
                clientSelect.required = name !== '';
            };
            input.addEventListener('input', syncProjectClient);
            clientSelect.addEventListener('change', syncProjectClient);
            input.closest('form')?.addEventListener('reset', () => {
                clientSelect.required = false;
            });
        });

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
                project_name: document.getElementById('vendorBillProjectName').value.trim(),
                project_client_id: document.getElementById('vendorBillProjectClient').value,
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
        showToast(`Vendor bill recorded. Reference: ${data.reference_no}`, 'success');
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
                project_name: document.getElementById('saleProjectName')?.value.trim() || '',
                project_client_id: document.getElementById('saleProjectClient')?.value || '',
                debit_lines: JSON.stringify([{ account_id: toAccount, amount: amount }]),
                credit_lines: JSON.stringify([{ account_id: fromAccount, amount: amount }]),
            }),
        });
        const data = await response.json();

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('quickSaleModal')).hide();
            document.getElementById('quickSaleForm').reset();
            showToast(`Sale recorded. Reference: ${data.reference_no}`, 'success');
            loadRecentTransactions();
            loadDashboardSummary();
            loadDashboardAccounts();
            loadCashflowChart();
            loadDashboardCashFlowKpis();
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
                project_name: document.getElementById('expenseProjectName')?.value.trim() || '',
                project_client_id: document.getElementById('expenseProjectClient')?.value || '',
                debit_lines: JSON.stringify([{ account_id: toAccount, amount: amount }]),
                credit_lines: JSON.stringify([{ account_id: fromAccount, amount: amount }]),
            }),
        });
        const data = await response.json();

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('quickExpenseModal')).hide();
            document.getElementById('quickExpenseForm').reset();
            showToast(`Expense recorded. Reference: ${data.reference_no}`, 'success');
            loadRecentTransactions();
            loadDashboardSummary();
            loadDashboardAccounts();
            loadCashflowChart();
            loadDashboardCashFlowKpis();
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
    const totalElement = document.getElementById(`${prefix}Total`);
    const currentAmountElement = document.getElementById(`${prefix}CurrentAmount`);
    const overdueAmountElement = document.getElementById(`${prefix}OverdueAmount`);
    const currentBar = document.getElementById(`${prefix}CurrentBar`);
    const overdueBar = document.getElementById(`${prefix}OverdueBar`);
    if (!totalElement || !currentAmountElement || !overdueAmountElement || !currentBar || !overdueBar) return;

    totalElement.textContent = formatMoney(info.total);
    currentAmountElement.textContent = formatMoney(info.current);
    overdueAmountElement.textContent = formatMoney(info.overdue);

    const sum = info.current + info.overdue;
    const currentPercent = sum > 0 ? (info.current / sum) * 100 : 100;
    const overduePercent = sum > 0 ? (info.overdue / sum) * 100 : 0;

    currentBar.style.width = currentPercent + '%';
    overdueBar.style.width = overduePercent + '%';
}

let arApDetailsSourceCard = null;
let arApDetailsClosing = false;

document.querySelectorAll('.ar-ap-summary-card').forEach(card => {
    card.addEventListener('click', () => loadArApDetails(card.dataset.arApType, card));
    card.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        loadArApDetails(card.dataset.arApType, card);
    });
});

function animateArApDetailsDialog(dialog, sourceCard, reverse = false) {
    if (
        typeof dialog.animate !== 'function'
        || !sourceCard?.isConnected
        || window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
        return null;
    }

    const source = sourceCard.getBoundingClientRect();
    const target = dialog.getBoundingClientRect();
    if (!source.width || !source.height || !target.width || !target.height) return null;

    const translateX = source.left - target.left;
    const translateY = source.top - target.top;
    const scaleX = source.width / target.width;
    const scaleY = source.height / target.height;
    const collapsedTransform = `translate(${translateX}px, ${translateY}px) scale(${scaleX}, ${scaleY})`;

    return dialog.animate(
        reverse
            ? [
                { transform: 'translate(0px, 0px) scale(1, 1)', opacity: 1 },
                { transform: collapsedTransform, opacity: 0.55 },
            ]
            : [
                { transform: collapsedTransform, opacity: 0.55 },
                { transform: 'translate(0px, 0px) scale(1, 1)', opacity: 1 },
            ],
        {
            duration: 620,
            easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
        }
    );
}

function finishArApDetailsClose() {
    const modalElement = document.getElementById('arApDetailsModal');
    modalElement.classList.remove('ar-ap-custom-open');
    modalElement.classList.remove('show');
    modalElement.setAttribute('aria-hidden', 'true');
    modalElement.removeAttribute('aria-modal');
    modalElement.style.display = 'none';
    document.body.classList.remove('modal-open');
    arApDetailsSourceCard?.focus();
    arApDetailsSourceCard = null;
    arApDetailsClosing = false;
}

function closeArApDetails() {
    if (arApDetailsClosing) return;
    arApDetailsClosing = true;

    const modalElement = document.getElementById('arApDetailsModal');
    const dialog = modalElement.querySelector('.ar-ap-expand-dialog');
    const sourceCard = arApDetailsSourceCard;
    const dialogAnimation = animateArApDetailsDialog(dialog, sourceCard, true);
    const backdropAnimation = window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ? null
        : modalElement.animate(
            [{ opacity: 1 }, { opacity: 0 }],
            { duration: 360, easing: 'ease-in' }
        );

    if (!dialogAnimation) {
        backdropAnimation?.cancel();
        finishArApDetailsClose();
        return;
    }

    dialogAnimation.addEventListener('finish', () => {
        dialogAnimation.cancel();
        backdropAnimation?.cancel();
        finishArApDetailsClose();
    }, { once: true });
}

async function loadArApDetails(type, sourceCard) {
    if (!['ar', 'ap'].includes(type)) return;

    const modalElement = document.getElementById('arApDetailsModal');
    const dialog = modalElement.querySelector('.ar-ap-expand-dialog');
    const title = document.getElementById('arApDetailsTitle');
    const total = document.getElementById('arApDetailsTotal');
    const tbody = document.getElementById('arApDetailsBody');
    const isReceivable = type === 'ar';

    arApDetailsSourceCard = sourceCard;
    arApDetailsClosing = false;
    modalElement.dataset.arApType = type;
    title.textContent = isReceivable ? 'Outstanding Accounts Receivable' : 'Outstanding Accounts Payable';
    total.textContent = 'Loading outstanding items...';
    tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">Loading...</td></tr>';

    modalElement.classList.add('ar-ap-custom-open');
    modalElement.classList.add('show');
    modalElement.style.display = 'block';
    modalElement.setAttribute('aria-hidden', 'false');
    modalElement.setAttribute('aria-modal', 'true');
    document.body.classList.add('modal-open');
    modalElement.querySelector('.ar-ap-details-close').focus({ preventScroll: true });

    requestAnimationFrame(() => {
        const backdropAnimation = window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? null
            : modalElement.animate(
                [{ opacity: 0 }, { opacity: 1 }],
                { duration: 240, easing: 'ease-out' }
            );
        const dialogAnimation = animateArApDetailsDialog(dialog, arApDetailsSourceCard);
        dialogAnimation?.addEventListener('finish', () => dialogAnimation.cancel(), { once: true });
        backdropAnimation?.addEventListener('finish', () => backdropAnimation.cancel(), { once: true });
    });

    try {
        const response = await fetch(`routes/journal.php?action=receivables-payables-items&type=${type}`);
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Could not load outstanding details.');
        }

        total.textContent = `Total outstanding: ${formatMoney(data.total)}`;
        if (!data.items.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4" style="color: var(--dark-text-muted);">There are no outstanding invoices or bills.</td></tr>';
            return;
        }

        const today = data.as_of;
        tbody.innerHTML = data.items.map(item => {
            const isOverdue = item.due_date && item.due_date < today;
            const party = item.company_name
                ? `${escapeDashboardHtml(item.party_name)} <small class="d-block" style="color: var(--dark-text-muted);">${escapeDashboardHtml(item.company_name)}</small>`
                : escapeDashboardHtml(item.party_name);
            const reference = item.reference_no
                ? `<small class="d-block" style="color: var(--dark-text-muted);">Ref ${escapeDashboardHtml(item.reference_no)}</small>`
                : '';

            return `
                <tr>
                    <td>${party}</td>
                    <td>${escapeDashboardHtml(item.description || '')}${reference}</td>
                    <td class="text-nowrap">${escapeDashboardHtml(formatDate(item.transaction_date))}</td>
                    <td class="text-nowrap">${escapeDashboardHtml(item.due_date ? formatDate(item.due_date) : 'No due date')}</td>
                    <td class="text-end text-nowrap">${formatMoney(item.amount)}</td>
                    <td class="text-end text-nowrap">${formatMoney(item.applied)}</td>
                    <td class="text-end text-nowrap fw-semibold">${formatMoney(item.remaining)}</td>
                    <td><span class="badge ${isOverdue ? 'bg-danger' : 'bg-primary'}">${isOverdue ? 'Overdue' : 'Current'}</span></td>
                </tr>
            `;
        }).join('');
    } catch (error) {
        console.error(`Failed to load ${isReceivable ? 'receivables' : 'payables'} details:`, error);
        total.textContent = 'Unable to load outstanding details.';
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-danger">${escapeDashboardHtml(error.message || 'Could not load outstanding details.')}</td></tr>`;
    }
}

const arApDetailsModal = document.getElementById('arApDetailsModal');
arApDetailsModal?.addEventListener('click', event => {
    if (
        event.target === arApDetailsModal
        || event.target.closest('.ar-ap-details-close')
    ) {
        event.preventDefault();
        closeArApDetails();
    }
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && arApDetailsModal?.classList.contains('ar-ap-custom-open')) {
        event.preventDefault();
        closeArApDetails();
    }
});

function escapeDashboardHtml(value) {
    const element = document.createElement('span');
    element.textContent = String(value ?? '');
    return element.innerHTML;
}

document.getElementById('periodFilter')?.addEventListener('change', () => {
    loadCashflowChart();
    loadDashboardCashFlowKpis();
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
    await loadClientsIntoScanDropdown();
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
        const response = await fetch(API_URL, { method: 'POST', body: new URLSearchParams({ action: 'init-scan-session' }) });
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
            const response = await fetch(API_URL, { method: 'POST', body: new URLSearchParams({ action: 'check-scan-session', token: currentScanToken }) });
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
            const checkRes = await fetch(API_URL, { method: 'POST', body: new URLSearchParams({ action: 'check-scan-session', token: currentScanToken }) });
            const checkData = await checkRes.json();
            if (checkData.success && checkData.status === 'completed') {
                stopScanPolling();
                openVerificationForm(checkData.image_path, checkData.extracted, checkData.image_url);
            }
        } else {
            showToast(data.error || 'Failed to process receipt file.', 'danger');
        }
    } catch (err) {
        showToast('Server error during local file processing.', 'danger');
    }
});

// 4. Populate Form and Switch to Step 2 Verification Panel
function openVerificationForm(imagePath, extracted, imageUrl = imagePath) {
    currentReceiptImagePath = imagePath;

    document.getElementById('scannedImagePreview').src = imageUrl;
    document.getElementById('scannedVendor').value = extracted.vendor || '';
    document.getElementById('scannedDate').value = extracted.date || new Date().toISOString().split('T')[0];
    document.getElementById('scannedRef').value = extracted.reference_no || '';
    document.getElementById('scannedAmount').value = extracted.amount || '0.00';
    window.formatCurrencyInput?.(document.getElementById('scannedAmount'));

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
    const amount = window.parseCurrencyValue(document.getElementById('scannedAmount').value);
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
    formData.append('client_id', document.getElementById('scannedClient')?.value || '');
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
        const response = await fetch('routes/cleanup-scans.php?ajax=1', { method: 'POST' });
        if (!response.ok) {
            console.error(`Receipt scan cleanup failed with HTTP ${response.status}.`);
        }
    } catch (err) {
        console.error('Receipt scan cleanup request failed:', err);
    }
}

async function loadClientDropdown() {
    const clientSelect = document.getElementById('clientIdSelect');
    if (!clientSelect) return;

    clientSelect.replaceChildren(new Option('Loading active clients...', ''));
    try {
        const response = await fetch('routes/clients.php?action=list&status=active');
        const data = await response.json();
        if (!response.ok || !data.success || !Array.isArray(data.clients)) {
            throw new Error(data.error || 'Could not load active clients.');
        }

        clientSelect.replaceChildren(new Option('-- Optional: Select Client --', ''));
        data.clients.forEach(client => {
            const label = client.company_name
                ? `${client.name} (${client.company_name})`
                : client.name;
            clientSelect.add(new Option(label, client.id));
        });
    } catch (err) {
        console.error('Error loading clients:', err);
        clientSelect.replaceChildren(new Option('Unable to load clients', ''));
        clientSelect.options[0].disabled = true;
    }
}

async function loadClientsIntoScanDropdown() {
    const clientSelect = document.getElementById('scannedClient');
    if (!clientSelect) return;

    clientSelect.replaceChildren(new Option('Loading active clients...', ''));
    try {
        const response = await fetch('routes/clients.php?action=list&status=active');
        const data = await response.json();
        if (!response.ok || !data.success || !Array.isArray(data.clients)) {
            throw new Error(data.error || 'Could not load active clients.');
        }

        clientSelect.replaceChildren(new Option('-- Select Client (Optional) --', ''));
        data.clients.forEach(client => {
            const label = client.company_name
                ? `${client.name} (${client.company_name})`
                : client.name;
            clientSelect.add(new Option(label, client.id));
        });
    } catch (err) {
        console.error('Failed to load clients into scan modal:', err);
        clientSelect.replaceChildren(new Option('Unable to load clients', ''));
        clientSelect.options[0].disabled = true;
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
loadQuickTransactionAccounts();
loadRecentTransactions();
loadDashboardSummary();
loadCashflowChart();
loadDashboardCashFlowKpis();
loadDashboardAccounts();
loadReceivablesPayables() // for A/R and A/P in the dashboard and journal entry page
loadDueNotifications();
