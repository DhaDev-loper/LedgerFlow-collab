//-- journal entries navigation expandable
document.addEventListener('DOMContentLoaded', () => {
    const journalExpandable = document.getElementById('journalExpandable');
    const journalTrigger = document.getElementById('journalTrigger');

    if (journalTrigger && journalExpandable) {
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
    }
});

const API_URL = 'routes/journal.php';
let ledgerRequestId = 0;
let activeLedgerData = null;
let ledgerCurrentPage = 1;
let ledgerRowsPerPage = 25;

function renderLedgerTable() {
    const tbody = document.getElementById('ledgerTableBody');
    if (!tbody || !activeLedgerData) return;

    const query = (document.getElementById('ledgerSearch')?.value || '').trim().toLocaleLowerCase();
    const filteredLines = (activeLedgerData.lines || []).filter(line =>
        [line.date, line.description, line.reference_no]
            .some(value => String(value || '').toLocaleLowerCase().includes(query))
    );
    const pageSize = Number(document.getElementById('ledgerRowsPerPage')?.value || ledgerRowsPerPage);
    ledgerRowsPerPage = [10, 25, 50, 100].includes(pageSize) ? pageSize : 25;
    const totalPages = Math.max(1, Math.ceil(filteredLines.length / ledgerRowsPerPage));
    ledgerCurrentPage = Math.min(ledgerCurrentPage, totalPages);
    const start = filteredLines.length ? (ledgerCurrentPage - 1) * ledgerRowsPerPage : 0;
    const pageLines = filteredLines.slice(start, start + ledgerRowsPerPage);

    tbody.innerHTML = pageLines.map(line => `
        <tr>
            <td>${formatDate(line.date)}</td>
            <td>${escapeHtml(line.description)}</td>
            <td><span class="small" style="color: var(--dark-text-muted);">${escapeHtml(line.reference_no || '—')}</span></td>
            <td class="text-end money">${line.debit > 0 ? formatMoney(line.debit) : '—'}</td>
            <td class="text-end money">${line.credit > 0 ? formatMoney(line.credit) : '—'}</td>
            <td class="text-end money fw-semibold">${formatMoney(line.running_balance)}</td>
        </tr>
    `).join('') || `<tr><td colspan="6" class="text-center py-4" style="color: var(--dark-text-muted);">${query ? 'No matching ledger entries.' : 'No transactions in this date range.'}</td></tr>`;

    const pageInfo = document.getElementById('ledgerPageInfo');
    if (pageInfo) pageInfo.textContent = filteredLines.length
        ? `Showing ${start + 1}-${Math.min(start + ledgerRowsPerPage, filteredLines.length)} of ${filteredLines.length}`
        : (query ? 'No matching entries' : 'No entries');

    const pagination = document.getElementById('ledgerPagination');
    if (pagination) {
        let html = `<li class="page-item ${ledgerCurrentPage === 1 ? 'disabled' : ''}"><button class="page-link" data-page="${ledgerCurrentPage - 1}">Previous</button></li>`;
        for (let page = 1; page <= totalPages; page++) html += `<li class="page-item ${page === ledgerCurrentPage ? 'active' : ''}"><button class="page-link" data-page="${page}">${page}</button></li>`;
        html += `<li class="page-item ${ledgerCurrentPage === totalPages ? 'disabled' : ''}"><button class="page-link" data-page="${ledgerCurrentPage + 1}">Next</button></li>`;
        pagination.innerHTML = html;
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatMoney(amount) {
    const num = parseFloat(amount) || 0;
    const sign = num < 0 ? '-' : '';
    return sign + '₱' + Math.abs(num).toLocaleString(undefined, { minimumFractionDigits: 2 });
}

function formatDate(dateStr) {
    return window.formatDateMDY(dateStr, '');
}

async function loadLedgerAccounts() {
    try {
        const response = await fetch(`${API_URL}?action=reference-data`);
        const data = await response.json();

        if (!data.success || !data.accounts || data.accounts.length === 0) return;

        const select = document.getElementById('ledgerAccountId');
        select.innerHTML = data.accounts.map(a => `<option value="${a.id}">${escapeHtml(a.name)} (${escapeHtml(a.type_name)})</option>`).join('');

        // Automatically select Cash (Asset) if available, otherwise default to first account
        const cashAccount = data.accounts.find(a => a.name.toLowerCase().includes('cash'));
        if (cashAccount) {
            select.value = cashAccount.id;
        } else {
            select.value = data.accounts[0].id;
        }

        // Automatically load ledger view for the selected account
        loadLedger();

    } catch (err) {
        console.error('Failed to load accounts:', err);
    }
}

async function loadLedger() {
    const container = document.getElementById('ledgerContent');
    const accountId = document.getElementById('ledgerAccountId').value;

    if (!accountId) return;

    const requestId = ++ledgerRequestId;
    container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading...</p>`;

    const dateFrom = document.getElementById('ledgerDateFrom').value;
    const dateTo = document.getElementById('ledgerDateTo').value;

    try {
        const params = new URLSearchParams({ action: 'ledger', account_id: accountId });
        if (dateFrom) params.append('date_from', dateFrom);
        if (dateTo) params.append('date_to', dateTo);

        const response = await fetch(`${API_URL}?${params.toString()}`);
        const data = await response.json();
        if (requestId !== ledgerRequestId) return;

        if (!data.success) {
            container.innerHTML = `<p class="text-center py-5 text-danger">${escapeHtml(data.error || 'Failed to load ledger.')}</p>`;
            return;
        }

        activeLedgerData = data;
        ledgerCurrentPage = 1;

        container.innerHTML = `
            <div class="panel mb-3">
                <div class="panel-body compact d-flex justify-content-between">
                    <div>
                        <div class="h6 mb-0">${escapeHtml(data.account_name)}</div>
                        <div class="small" style="color: var(--dark-text-muted);">${escapeHtml(data.account_type)}</div>
                    </div>
                    <div class="text-end">
                        <div class="small" style="color: var(--dark-text-muted);">Opening Balance</div>
                        <div class="money fw-semibold">${formatMoney(data.opening_balance)}</div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="ledger-table-toolbar">
                    <input type="search" class="filter-input-search" id="ledgerSearch" placeholder="Search date, description, or reference..." aria-label="Search ledger entries">
                    <label for="ledgerRowsPerPage" class="d-flex align-items-center gap-2 small mb-0" style="color: var(--dark-text-muted);">Show
                        <select id="ledgerRowsPerPage" class="form-select form-select-sm form-control-dark" style="width: auto;">
                            <option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option>
                        </select> entries
                    </label>
                </div>
                <div class="table-responsive ledger-table-scroll">
                    <table class="table table-transactions mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Reference</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody id="ledgerTableBody"></tbody>
                        <tfoot>
                            <tr class="ledger-closing-balance">
                                <td colspan="5" class="fw-bold">Closing Balance</td>
                                <td class="text-end money fw-bold">${formatMoney(data.closing_balance)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="ledger-table-footer">
                    <small id="ledgerPageInfo" style="color: var(--dark-text-muted);"></small>
                    <nav aria-label="General ledger pagination"><ul id="ledgerPagination" class="pagination pagination-sm mb-0"></ul></nav>
                </div>
            </div>
        `;
        renderLedgerTable();

    } catch (err) {
        if (requestId !== ledgerRequestId) return;
        console.error('Failed to load ledger:', err);
        container.innerHTML = `<p class="text-center py-5 text-danger">Could not reach the server.</p>`;
    }
}

document.getElementById('ledgerContent')?.addEventListener('input', event => {
    if (event.target.id !== 'ledgerSearch') return;
    ledgerCurrentPage = 1;
    renderLedgerTable();
});
document.getElementById('ledgerContent')?.addEventListener('change', event => {
    if (event.target.id !== 'ledgerRowsPerPage') return;
    ledgerCurrentPage = 1;
    renderLedgerTable();
});
document.getElementById('ledgerContent')?.addEventListener('click', event => {
    const button = event.target.closest('#ledgerPagination [data-page]');
    if (!button) return;
    const page = Number(button.dataset.page);
    const query = (document.getElementById('ledgerSearch')?.value || '').trim().toLocaleLowerCase();
    const filteredCount = (activeLedgerData?.lines || []).filter(line => [line.date, line.description, line.reference_no]
        .some(value => String(value || '').toLocaleLowerCase().includes(query))).length;
    const totalPages = Math.max(1, Math.ceil(filteredCount / ledgerRowsPerPage));
    if (page < 1 || page > totalPages) return;
    ledgerCurrentPage = page;
    renderLedgerTable();
});

document.getElementById('ledgerAccountId')?.addEventListener('change', loadLedger);
document.getElementById('ledgerDateFrom')?.addEventListener('change', loadLedger);
document.getElementById('ledgerDateTo')?.addEventListener('change', loadLedger);

document.getElementById('logoutBtn')?.addEventListener('click', async (e) => {
    e.preventDefault();
    await fetch('routes/auth.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'logout' }),
    });
    window.location.href = '../frontend/login.html';
});

// Initial startup load
loadLedgerAccounts();
