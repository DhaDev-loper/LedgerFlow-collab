const API_URL = 'routes/journal.php';

let entryModal, entryForm, formError;

if (CAN_CREATE) {
    entryModal = new bootstrap.Modal(document.getElementById('entryModal'));
    entryForm = document.getElementById('entryForm');
    formError = document.getElementById('entryFormError');
}

// ---------- Load accounts, types, categories into the form ----------
async function loadReferenceData() {
    if (!CAN_CREATE) return;

    try {
        const response = await fetch(`${API_URL}?action=reference-data`);
        const data = await response.json();

        if (!data.success) return;

        const accountOptions = data.accounts.map(a => `<option value="${a.id}">${a.name} (${a.type_name})</option>`).join('');

        document.getElementById('fromAccountId').innerHTML = accountOptions;
        document.getElementById('toAccountId').innerHTML = accountOptions;

        const typeSelect = document.getElementById('transactionTypeId');
        typeSelect.innerHTML = data.types.map(t => `<option value="${t.id}">${t.name}</option>`).join('');

        const categorySelect = document.getElementById('categoryId');
        categorySelect.innerHTML += data.categories.map(c => `<option value="${c.id}">${c.name}</option>`).join('');

        document.getElementById('transactionDate').value = new Date().toISOString().split('T')[0];

    } catch (err) {
        console.error('Failed to load reference data:', err);
    }
}

// ---------- Load and render the journal entries list ----------
async function loadJournalEntries() {
    const tbody = document.getElementById('journalTableBody');

    try {
        const response = await fetch(`${API_URL}?action=list`);
        const data = await response.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Failed to load entries.</td></tr>`;
            return;
        }

        if (data.journals.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4" style="color: var(--dark-text-muted);">No journal entries yet.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.journals.map(j => `
            <tr style="cursor: pointer;" onclick="viewEntry(${j.id})">
                <td>${formatDate(j.transaction_date)}</td>
                <td>${escapeHtml(j.description)}</td>
                <td><span class="small" style="color: var(--dark-text-muted);">${escapeHtml(j.type_name)}</span></td>
                <td><span class="small" style="color: var(--dark-text-muted);">${escapeHtml(j.reference_no || '—')}</span></td>
                <td><span class="small" style="color: var(--dark-text-muted);">${escapeHtml(j.created_by_name)}</span></td>
                <td class="text-end money fw-semibold">${formatMoney(j.total_amount)}</td>
            </tr>
        `).join('');

    } catch (err) {
        console.error('Failed to load journal entries:', err);
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Could not reach the server.</td></tr>`;
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatMoney(amount) {
    return '₱' + parseFloat(amount).toLocaleString(undefined, { minimumFractionDigits: 2 });
}

function formatDate(dateStr) {
    const date = new Date(dateStr.replace(' ', 'T'));
    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

// ---------- Save a new entry ----------
if (CAN_CREATE) {
    entryForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        formError.classList.add('d-none');

        const fromAccount = document.getElementById('fromAccountId').value;
        const toAccount = document.getElementById('toAccountId').value;

        if (fromAccount === toAccount) {
            formError.textContent = 'From and To accounts must be different.';
            formError.classList.remove('d-none');
            return;
        }

        const formData = new FormData(entryForm);
        formData.append('action', 'create');

        try {
            const response = await fetch(API_URL, { method: 'POST', body: formData });
            const data = await response.json();

            if (data.success) {
                entryModal.hide();
                entryForm.reset();
                document.getElementById('transactionDate').value = new Date().toISOString().split('T')[0];
                loadJournalEntries();
            } else {
                formError.textContent = data.error || 'Something went wrong.';
                formError.classList.remove('d-none');
            }
        } catch (err) {
            console.error('Save entry failed:', err);
            formError.textContent = 'Could not reach the server.';
            formError.classList.remove('d-none');
        }
    });
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

let viewEntryModal;

async function viewEntry(journalId) {
    if (!viewEntryModal) {
        viewEntryModal = new bootstrap.Modal(document.getElementById('viewEntryModal'));
    }

    const body = document.getElementById('viewEntryBody');
    body.innerHTML = `<p class="text-center py-4" style="color: var(--dark-text-muted);">Loading...</p>`;
    viewEntryModal.show();

    try {
        const response = await fetch(`${API_URL}?action=view&journal_id=${journalId}`);
        const data = await response.json();

        if (!data.success) {
            body.innerHTML = `<p class="text-center py-4 text-danger">${escapeHtml(data.error || 'Failed to load entry.')}</p>`;
            return;
        }

        const j = data.journal;
        // we use this sorting so that debit lines appear before credit lines in the display
        const sortedLines = [...data.lines].sort((a, b) => {
            if (a.entry_type === 'debit' && b.entry_type !== 'debit') return -1;
            if (a.entry_type !== 'debit' && b.entry_type === 'debit') return 1;
            return 0;
        });

        const linesHtml = sortedLines.map(line => `
            <div class="d-flex justify-content-between align-items-center py-2" style="border-bottom: 1px solid var(--dark-border);">
                <div>
                    <div class="fw-semibold small">${escapeHtml(line.account_name)}</div>
                    <div class="small" style="color: var(--dark-text-muted);">${escapeHtml(line.account_type)}</div>
                </div>
                <div class="text-end">
                    <span class="role-badge role-${line.entry_type === 'debit' ? 'admin' : 'accountant'}">
                        ${line.entry_type === 'debit' ? 'Debit' : 'Credit'}
                    </span>
                    <div class="money fw-semibold mt-1">${formatMoney(line.amount)}</div>
                </div>
            </div>
        `).join('');

        body.innerHTML = `
            <div class="mb-3">
                <div class="small" style="color: var(--dark-text-muted);">Description</div>
                <div class="fw-semibold">${escapeHtml(j.description)}</div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <div class="small" style="color: var(--dark-text-muted);">Date</div>
                    <div>${formatDate(j.transaction_date)}</div>
                </div>
                <div class="col-6">
                    <div class="small" style="color: var(--dark-text-muted);">Type</div>
                    <div>${escapeHtml(j.type_name)}</div>
                </div>
                <div class="col-6">
                    <div class="small" style="color: var(--dark-text-muted);">Reference No.</div>
                    <div>${escapeHtml(j.reference_no || '—')}</div>
                </div>
                <div class="col-6">
                    <div class="small" style="color: var(--dark-text-muted);">Category</div>
                    <div>${escapeHtml(j.category_name || '—')}</div>
                </div>
                <div class="col-6">
                    <div class="small" style="color: var(--dark-text-muted);">Recorded By</div>
                    <div>${escapeHtml(j.created_by_name)}</div>
                </div>
                <div class="col-6">
                    <div class="small" style="color: var(--dark-text-muted);">Amount</div>
                    <div class="money fw-semibold">${formatMoney(j.total_amount)}</div>
                </div>
            </div>

            <hr style="border-color: var(--dark-border);">
            <div class="small mb-2" style="color: var(--dark-text-muted);">SPLIT LINES</div>
            ${linesHtml}
        `;

    } catch (err) {
        console.error('Failed to load entry details:', err);
        body.innerHTML = `<p class="text-center py-4 text-danger">Could not reach the server.</p>`;
    }
}

// ---------- Initial load ----------
loadReferenceData();
loadJournalEntries();