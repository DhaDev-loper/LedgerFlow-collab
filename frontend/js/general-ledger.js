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
    const date = new Date(dateStr.replace(' ', 'T'));
    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
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

    container.innerHTML = `<p class="text-center py-5" style="color: var(--dark-text-muted);">Loading...</p>`;

    const dateFrom = document.getElementById('ledgerDateFrom').value;
    const dateTo = document.getElementById('ledgerDateTo').value;

    try {
        const params = new URLSearchParams({ action: 'ledger', account_id: accountId });
        if (dateFrom) params.append('date_from', dateFrom);
        if (dateTo) params.append('date_to', dateTo);

        const response = await fetch(`${API_URL}?${params.toString()}`);
        const data = await response.json();

        if (!data.success) {
            container.innerHTML = `<p class="text-center py-5 text-danger">${escapeHtml(data.error || 'Failed to load ledger.')}</p>`;
            return;
        }

        const rows = data.lines.map(line => `
            <tr>
                <td>${formatDate(line.date)}</td>
                <td>${escapeHtml(line.description)}</td>
                <td><span class="small" style="color: var(--dark-text-muted);">${escapeHtml(line.reference_no || '—')}</span></td>
                <td class="text-end money">${line.debit > 0 ? formatMoney(line.debit) : '—'}</td>
                <td class="text-end money">${line.credit > 0 ? formatMoney(line.credit) : '—'}</td>
                <td class="text-end money fw-semibold">${formatMoney(line.running_balance)}</td>
            </tr>
        `).join('') || `<tr><td colspan="6" class="text-center py-4" style="color: var(--dark-text-muted);">No transactions in this date range.</td></tr>`;

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
                <div class="table-responsive">
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
                        <tbody>${rows}</tbody>
                        <tfoot>
                            <tr style="border-top: 2px solid var(--dark-border);">
                                <td colspan="5" class="fw-bold">Closing Balance</td>
                                <td class="text-end money fw-bold">${formatMoney(data.closing_balance)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        `;

    } catch (err) {
        console.error('Failed to load ledger:', err);
        container.innerHTML = `<p class="text-center py-5 text-danger">Could not reach the server.</p>`;
    }
}

// Button listener to manual reload/filter
document.getElementById('loadLedgerBtn')?.addEventListener('click', loadLedger);

// Auto reload when changing account dropdown
document.getElementById('ledgerAccountId')?.addEventListener('change', loadLedger);

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