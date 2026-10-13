window.API_URL = window.API_URL || 'routes/journal.php';
window.CAN_CREATE = (typeof window.CAN_CREATE !== 'undefined') ? window.CAN_CREATE : false;
window.USER_ROLE  = (typeof window.USER_ROLE !== 'undefined')  ? window.USER_ROLE  : 'staff';

var entryModal, entryForm, formError;
if (CAN_CREATE) {
    entryModal = new bootstrap.Modal(document.getElementById('entryModal'));
    entryForm = document.getElementById('entryForm');
    formError = document.getElementById('entryFormError');
}

// Loads reference data for dropdowns
async function loadReferenceData() {
    if (!CAN_CREATE) return;

    try {
        const response = await fetch(`${API_URL}?action=reference-data`);
        const data = await response.json();
        
        if (!data.success) return;

        allAccountsForLines = data.accounts || [];
        const accountOptions = allAccountsForLines.map(a => `<option value="${a.id}">${a.name} (${a.type_name})</option>`).join('');

        const fromAcc = document.getElementById('fromAccountId');
        if (fromAcc) fromAcc.innerHTML = accountOptions;

        const toAcc = document.getElementById('toAccountId');
        if (toAcc) toAcc.innerHTML = accountOptions;

        if (data.types && data.types.length > 0) {
            const typeOptionsHtml = '<option value="">-- Select Type --</option>' + 
                data.types.map(t => `<option value="${t.id}">${t.type_name || t.name || t.transaction_type}</option>`).join('');

            const typeSelect = document.getElementById('transactionTypeId');
            if (typeSelect) typeSelect.innerHTML = typeOptionsHtml;

            const filterTypeSelect = document.getElementById('filterType');
            if (filterTypeSelect) filterTypeSelect.innerHTML = '<option value="">All Types</option>' +
                data.types.map(t => `<option value="${t.id}">${t.type_name || t.name || t.transaction_type}</option>`).join('');
        }

        const categorySelect = document.getElementById('categoryId');
        if (categorySelect && data.categories) {
            categorySelect.innerHTML = '<option value="">-- None --</option>' + 
                data.categories.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
        }

        const transDate = document.getElementById('transactionDate');
        if (transDate) transDate.value = new Date().toISOString().split('T')[0];

    } catch (err) {
        console.error('Failed to load reference data:', err);
    }
}

let journalEntries = [];
let journalCurrentPage = 1;
const journalRowsPerPage = 10;

function renderJournalPagination() {
    const pagination = document.getElementById('journalPagination');
    const pageInfo = document.getElementById('journalPageInfo');

    if (!pagination || !pageInfo) return;

    const totalPages = Math.max(
        1,
        Math.ceil(journalEntries.length / journalRowsPerPage)
    );

    journalCurrentPage = Math.min(journalCurrentPage, totalPages);

    const start = journalEntries.length === 0
        ? 0
        : (journalCurrentPage - 1) * journalRowsPerPage + 1;

    const end = Math.min(
        journalCurrentPage * journalRowsPerPage,
        journalEntries.length
    );

    pageInfo.textContent =
        journalEntries.length === 0
            ? 'No entries'
            : `Showing ${start}-${end} of ${journalEntries.length}`;

    let html = `
        <li class="page-item ${journalCurrentPage === 1 ? 'disabled' : ''}">
            <button class="page-link" data-page="${journalCurrentPage - 1}">
                Previous
            </button>
        </li>
    `;

    for (let page = 1; page <= totalPages; page++) {
        html += `
            <li class="page-item ${page === journalCurrentPage ? 'active' : ''}">
                <button class="page-link" data-page="${page}">
                    ${page}
                </button>
            </li>
        `;
    }

    html += `
        <li class="page-item ${journalCurrentPage === totalPages ? 'disabled' : ''}">
            <button class="page-link" data-page="${journalCurrentPage + 1}">
                Next
            </button>
        </li>
    `;

    pagination.innerHTML = html;

    pagination.querySelectorAll('[data-page]').forEach(button => {
        button.addEventListener('click', () => {
            const page = Number(button.dataset.page);

            if (page < 1 || page > totalPages) return;

            journalCurrentPage = page;
            renderJournalRows();
        });
    });
}

function renderJournalRows() {
    const tbody = document.getElementById('journalTableBody');

    const start = (journalCurrentPage - 1) * journalRowsPerPage;
    const pageEntries = journalEntries.slice(start, start + journalRowsPerPage);

    const totalCols = CAN_CREATE ? 5 : 4;

    if (pageEntries.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="${totalCols}" class="text-center py-4" style="color: var(--dark-text-muted);">
                    No journal entries found.
                </td>
            </tr>
        `;
        renderJournalPagination();
        return;
    }

    const currentRole = (typeof USER_ROLE !== 'undefined') ? USER_ROLE : 'staff';

    tbody.innerHTML = pageEntries.map(j => {
        const isPending = j.status === 'deletion_pending';
        const isVoided  = j.status === 'voided';

        let statusBadge = '';
        if (isPending) statusBadge = `<span class="badge bg-warning text-dark ms-2">Deletion Pending</span>`;
        if (isVoided)  statusBadge = `<span class="badge bg-secondary ms-2">Voided</span>`;

        const clientName = j.client_name || j.company_name || '';
        const clientBadge = clientName 
            ? `<span class="badge ms-2" style="background-color: rgba(59, 130, 246, 0.15); color: #60A5FA; border: 1px solid rgba(59, 130, 246, 0.3); font-weight: 500; font-size: 0.75rem;">${escapeHtml(clientName)}</span>`
            : '';

        const categoryText = j.category_name 
            ? `<div class="small" style="color: var(--dark-text-muted); font-size: 0.75rem;">${escapeHtml(j.category_name)}</div>`
            : '';

        let actionButtons = `
            <button type="button" class="table-action-btn" onclick="event.stopPropagation(); viewEntry(${j.id})">View</button>
        `;

        if (!isVoided) {
            if (currentRole === 'owner') {
                actionButtons += `
                    <button type="button" class="table-action-btn danger btn-direct-delete" 
                            onclick="event.stopPropagation(); window.executeDirectVoid(${j.id});">
                        Void
                    </button>
                `;
            } else if (['admin', 'accountant'].includes(currentRole) && !isPending) {
                actionButtons += `
                    <button type="button" class="table-action-btn danger btn-request-delete" 
                            onclick="event.stopPropagation(); window.executeRequestVoid(${j.id});">
                        Req
                    </button>
                `;
            }

            if (!isPending) {
                actionButtons += `
                    <button type="button" class="table-action-btn"
                        onclick='event.stopPropagation(); openEditEntryModal(${j.id}, ${JSON.stringify(j.description)}, ${JSON.stringify(j.reference_no || '')})'>
                        Edit
                    </button>
                `;
            }
        }

        return `
            <tr style="cursor: pointer;" class="${isVoided ? 'opacity-50 text-decoration-line-through' : ''}" onclick="viewEntry(${j.id})">
                <!-- Column 1: Date & Reference -->
                <td>
                    <div class="fw-medium">${formatDate(j.transaction_date)}</div>
                    <small style="color: var(--dark-text-muted); font-size: 0.75rem;">${escapeHtml(j.reference_no || '—')}</small>
                </td>

                <!-- Column 2: Description & Client Badge -->
                <td>
                    <div class="d-flex align-items-center flex-wrap gap-1">
                        <span class="fw-semibold">${escapeHtml(j.description)}</span>
                        ${clientBadge}
                        ${statusBadge}
                    </div>
                    <small style="color: var(--dark-text-muted); font-size: 0.75rem;">By ${escapeHtml(j.created_by_name || 'System')}</small>
                </td>

                <!-- Column 3: Type & Category -->
                <td>
                    <div>${escapeHtml(j.type_name)}</div>
                    ${categoryText}
                </td>

                <!-- Column 4: Amount -->
                <td class="text-end money fw-bold">
                    ${formatMoney(j.total_amount)}
                </td>

                <!-- Column 5: Actions -->
                ${CAN_CREATE ? `
                    <td class="text-end" onclick="event.stopPropagation();">
                        <div class="btn-group">
                            ${actionButtons}
                        </div>
                    </td>
                ` : ''}
            </tr>
        `;
    }).join('');

    renderJournalPagination();
}

// ---------- Load and render the journal entries list ----------
async function loadJournalEntries() {
    const tbody = document.getElementById('journalTableBody');

    const params = new URLSearchParams({ action: 'list' });

    const search   = document.getElementById('filterSearch')?.value || '';
    const dateFrom = document.getElementById('filterDateFrom')?.value || '';
    const dateTo   = document.getElementById('filterDateTo')?.value || '';
    const typeId   = document.getElementById('filterType')?.value || '';
    const status   = document.getElementById('filterStatus')?.value || 'active';

    if (search)   params.append('search', search);
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo)   params.append('date_to', dateTo);
    if (typeId)   params.append('transaction_type_id', typeId);
    if (status)   params.append('status', status);

    try {
        const response = await fetch(`${API_URL}?${params.toString()}`);
        const data = await response.json();

        if (!data.success) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-4 text-danger">
                        Failed to load entries.
                    </td>
                </tr>
            `;
            return;
        }

        journalEntries = data.journals || [];
        journalCurrentPage = 1;

        renderJournalRows();
        if (typeof loadPendingRequests === 'function') {
            loadPendingRequests();
        }

    } catch (error) {
        console.error('Failed to load journal entries:', error);

        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center py-4 text-danger">
                    Could not reach the server.
                </td>
            </tr>
        `;
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

// Reset form when opening the modal
document.getElementById('newEntryBtn')?.addEventListener('click', () => {
    document.getElementById('debitLinesContainer').innerHTML = '';
    document.getElementById('creditLinesContainer').innerHTML = '';
    document.getElementById('debitLinesContainer').appendChild(createAccountLineRow('debit'));
    document.getElementById('creditLinesContainer').appendChild(createAccountLineRow('credit'));
    recalculateLineTotals();
});

// ---------- Save a new entry ----------
if (CAN_CREATE) {
    entryForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        formError.classList.add('d-none');

        const debitLines = [];
        document.querySelectorAll('#debitLinesContainer > div').forEach(row => {
            const accSelect = row.querySelector('.line-account');
            const amtInput = row.querySelector('.line-amount');
            if (accSelect && amtInput) {
                debitLines.push({
                    account_id: accSelect.value,
                    amount: amtInput.value,
                });
            }
        });

        const creditLines = [];
        document.querySelectorAll('#creditLinesContainer > div').forEach(row => {
            const accSelect = row.querySelector('.line-account');
            const amtInput = row.querySelector('.line-amount');
            if (accSelect && amtInput) {
                creditLines.push({
                    account_id: accSelect.value,
                    amount: amtInput.value,
                });
            }
        });

        const saveBtn = document.getElementById('saveEntryBtn');
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        try {
            const formData = new FormData(entryForm);
            formData.append('action', 'create');
            formData.append('debit_lines', JSON.stringify(debitLines));
            formData.append('credit_lines', JSON.stringify(creditLines));

            const clientSelect = document.getElementById('clientIdSelect');
            if (clientSelect) {
                formData.set('client_id', clientSelect.value);
            }

            const response = await fetch(API_URL, { method: 'POST', body: formData });
            const data = await response.json();

            if (data.success) {
                entryModal.hide();
                entryForm.reset();
                document.getElementById('transactionDate').value = new Date().toISOString().split('T')[0];

                showToast('Journal entry recorded successfully!', 'success');

                loadJournalEntries();
                loadSummaryTotals();
            } else {
                formError.textContent = data.error || 'Something went wrong.';
                formError.classList.remove('d-none');
            }
        } catch (err) {
            console.error('Save entry failed:', err);
            formError.textContent = 'Could not reach the server.';
            formError.classList.remove('d-none');
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Entry';
        }
    });
}

let viewEntryModal;

async function viewEntry(journalId) {
    document.getElementById('viewEntryModal').dataset.journalId = journalId;

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
                <div class="fw-semibold fs-6">${escapeHtml(j.description)}</div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <div class="small" style="color: var(--dark-text-muted);">Date</div>
                    <div class="fw-medium">${formatDate(j.transaction_date)}</div>
                </div>
                <div class="col-6">
                    <div class="small" style="color: var(--dark-text-muted);">Type</div>
                    <div class="fw-medium">${escapeHtml(j.type_name)}</div>
                </div>
                <div class="col-6">
                    <div class="small" style="color: var(--dark-text-muted);">Reference No.</div>
                    <div class="fw-medium">${escapeHtml(j.reference_no || '—')}</div>
                </div>
                <div class="col-6">
                    <div class="small" style="color: var(--dark-text-muted);">Client</div>
                    <div class="fw-medium" style="color: ${j.client_name ? 'var(--dark-text-main, #fff)' : 'var(--dark-text-muted)'};">
                        ${escapeHtml(j.client_name || '—')}
                    </div>
                </div>
                <div class="col-6">
                    <div class="small" style="color: var(--dark-text-muted);">Category</div>
                    <div class="fw-medium">${escapeHtml(j.category_name || '—')}</div>
                </div>
                <div class="col-6">
                    <div class="small" style="color: var(--dark-text-muted);">Recorded By</div>
                    <div class="fw-medium">${escapeHtml(j.created_by_name || 'System')}</div>
                </div>
                <div class="col-12">
                    <div class="small" style="color: var(--dark-text-muted);">Total Amount</div>
                    <div class="money fw-bold fs-5">${formatMoney(j.total_amount)}</div>
                </div>
            </div>

            <hr style="border-color: var(--dark-border);">
            <div class="small fw-semibold mb-2" style="color: var(--dark-text-muted);">SPLIT LINES</div>
            ${linesHtml}
        `;

    } catch (err) {
        console.error('Failed to load entry details:', err);
        body.innerHTML = `<p class="text-center py-4 text-danger">Could not reach the server.</p>`;
    }
}

let lineCounter = 0;
let allAccountsForLines = [];

function createAccountLineRow(side) {
    lineCounter++;
    const lineId = `line-${lineCounter}`;

    const row = document.createElement('div');
    row.className = 'd-flex gap-2 align-items-center mb-2';
    row.dataset.lineId = lineId;
    row.dataset.side = side;

    row.innerHTML = `
        <select class="form-select form-control-dark line-account" style="flex: 2;">
            ${allAccountsForLines.map(a => `<option value="${a.id}">${a.name} (${a.type_name})</option>`).join('')}
        </select>
        <input type="number" step="0.01" min="0.01" class="form-control form-control-dark line-amount" 
               style="flex: 1;" placeholder="0.00">
        <button type="button" class="table-action-btn danger remove-line-btn">✕</button>
    `;

    row.querySelector('.remove-line-btn').addEventListener('click', () => {
        row.remove();
        recalculateLineTotals();
    });

    row.querySelector('.line-amount').addEventListener('input', recalculateLineTotals);

    return row;
}

document.getElementById('addDebitLineBtn')?.addEventListener('click', () => {
    document.getElementById('debitLinesContainer').appendChild(createAccountLineRow('debit'));
});

document.getElementById('addCreditLineBtn')?.addEventListener('click', () => {
    document.getElementById('creditLinesContainer').appendChild(createAccountLineRow('credit'));
});

function recalculateLineTotals() {
    let totalDebits = 0;
    let totalCredits = 0;

    document.querySelectorAll('#debitLinesContainer .line-amount').forEach(input => {
        totalDebits += parseFloat(input.value) || 0;
    });
    document.querySelectorAll('#creditLinesContainer .line-amount').forEach(input => {
        totalCredits += parseFloat(input.value) || 0;
    });

    document.getElementById('totalDebitsDisplay').textContent = totalDebits.toFixed(2);
    document.getElementById('totalCreditsDisplay').textContent = totalCredits.toFixed(2);

    const statusEl = document.getElementById('balanceStatusDisplay');
    const isBalanced = totalDebits === totalCredits && totalDebits > 0;

    statusEl.textContent = isBalanced ? '✓ Balanced' : '✗ Not balanced';
    statusEl.style.color = isBalanced ? '#4ADE80' : '#F87171';
}

let editEntryModal;

function openEditEntryModal(journalId, description, referenceNo) {
    if (!editEntryModal) {
        editEntryModal = new bootstrap.Modal(document.getElementById('editEntryModal'));
    }

    document.getElementById('editJournalId').value = journalId;
    document.getElementById('editDescription').value = description;
    document.getElementById('editReferenceNo').value = referenceNo;
    document.getElementById('editEntryError').classList.add('d-none');

    const categorySelect = document.getElementById('editCategoryId');
    const sourceOptions = document.getElementById('categoryId').innerHTML;
    categorySelect.innerHTML = sourceOptions;

    editEntryModal.show();
}

document.getElementById('editEntryForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const errorBox = document.getElementById('editEntryError');
    errorBox.classList.add('d-none');

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'edit',
                journal_id: document.getElementById('editJournalId').value,
                description: document.getElementById('editDescription').value,
                reference_no: document.getElementById('editReferenceNo').value,
                category_id: document.getElementById('editCategoryId').value,
            }),
        });
        const data = await response.json();

        if (data.success) {
            editEntryModal.hide();
            loadJournalEntries();
        } else {
            errorBox.textContent = data.error || 'Something went wrong.';
            errorBox.classList.remove('d-none');
        }
    } catch (err) {
        console.error('Edit entry failed:', err);
        errorBox.textContent = 'Could not reach the server.';
        errorBox.classList.remove('d-none');
    }
});

// ---------- DELETION REQUEST & VOID ACTIONS ----------

document.addEventListener('click', async (e) => {
    // 1. Trigger Request Deletion Modal (Admin / Accountant)
    const reqBtn = e.target.closest('.btn-request-delete');
    if (reqBtn) {
        const journalId = reqBtn.dataset.id;
        document.getElementById('deleteJournalId').value = journalId;
        document.getElementById('deleteReason').value = '';
        document.getElementById('requestDeletionError')?.classList.add('d-none');

        const requestModal = new bootstrap.Modal(document.getElementById('requestDeletionModal'));
        requestModal.show();
        return;
    }

    // 2. Direct Void Action (Owner)
const voidBtn = e.target.closest('.btn-direct-delete');
if (voidBtn) {
    e.preventDefault();
    e.stopPropagation(); // <-- Prevents row click (viewEntry modal) from opening

    const journalId = voidBtn.dataset.id;

    if (!confirm('Are you sure you want to void this entry? Account balances will be automatically adjusted.')) {
        return;
    }

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'void', journal_id: journalId })
        });

        const data = await response.json();
        if (data.success) {
            showToast('Entry voided and balances updated successfully.', 'success');
            loadJournalEntries();
            if (typeof loadSummaryTotals === 'function') loadSummaryTotals();
        } else {
            showToast(data.error || 'Failed to void entry.', 'danger');
        }
    } catch (err) {
        console.error('Void error:', err);
        showToast('Server error encountered while voiding entry.', 'danger');
    }
    return;
  }

    // 3. Owner Approval Actions (In Pending Approvals Modal)
    if (e.target.classList.contains('btn-approve-req')) {
        const requestId = e.target.dataset.id;
        if (!confirm('Approve this request? This will void the entry and adjust account balances.')) return;

        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                body: new URLSearchParams({ action: 'approve-deletion', request_id: requestId })
            });
            const data = await response.json();

            if (data.success) {
                showToast('Entry voided successfully.', 'success');
                loadPendingRequests();
                loadJournalEntries();
                loadSummaryTotals();
            } else {
                showToast(data.error || 'Approval failed.', 'danger');
            }
        } catch (err) {
            showToast('Server connection error.', 'danger');
        }
    }

    if (e.target.classList.contains('btn-reject-req')) {
        const requestId = e.target.dataset.id;
        const note = prompt('Optional rejection note for requester:');

        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                body: new URLSearchParams({ action: 'reject-deletion', request_id: requestId, owner_note: note || '' })
            });
            const data = await response.json();

            if (data.success) {
                showToast('Deletion request rejected.', 'info');
                loadPendingRequests();
                loadJournalEntries();
            } else {
                showToast(data.error || 'Rejection failed.', 'danger');
            }
        } catch (err) {
            showToast('Server connection error.', 'danger');
        }
    }
});

// Handle Deletion Request Form Submission
document.getElementById('requestDeletionForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const errorBox = document.getElementById('requestDeletionError');
    if (errorBox) errorBox.classList.add('d-none');

    const journalId = document.getElementById('deleteJournalId').value;
    const reason    = document.getElementById('deleteReason').value.trim();
    const submitBtn = document.getElementById('submitDeleteReqBtn');

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';
    }

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'request-deletion',
                journal_id: journalId,
                reason: reason
            })
        });

        const data = await response.json();

        if (data.success) {
            const modalEl = document.getElementById('requestDeletionModal');
            bootstrap.Modal.getInstance(modalEl)?.hide();

            showToast('Deletion request submitted for Owner approval!', 'info');
            loadJournalEntries();
        } else {
            if (errorBox) {
                errorBox.textContent = data.error || 'Failed to submit request.';
                errorBox.classList.remove('d-none');
            }
        }
    } catch (err) {
        console.error('Submit deletion request failed:', err);
        if (errorBox) {
            errorBox.textContent = 'Could not reach the server.';
            errorBox.classList.remove('d-none');
        }
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Request';
        }
    }
});

// Load Pending Requests List (Owner Only)
async function loadPendingRequests() {
    const currentRole = (typeof USER_ROLE !== 'undefined') ? USER_ROLE : 'staff';
    if (currentRole !== 'owner') return;

    try {
        const response = await fetch(`${API_URL}?action=list-pending-deletions`);
        const data = await response.json();

        const badge = document.getElementById('pendingRequestsBadge');
        const tbody = document.getElementById('pendingRequestsTableBody');

        if (!data.success || !data.requests || data.requests.length === 0) {
            if (badge) badge.classList.add('d-none');
            if (tbody) tbody.innerHTML = `<tr><td colspan="4" class="text-center py-3" style="color: var(--dark-text-muted);">No pending deletion requests.</td></tr>`;
            return;
        }

        if (badge) {
            badge.textContent = data.requests.length;
            badge.classList.remove('d-none');
        }

        if (tbody) {
            tbody.innerHTML = data.requests.map(req => `
                <tr>
                    <td><strong>${escapeHtml(req.requested_by_name)}</strong></td>
                    <td>${escapeHtml(req.reason)}</td>
                    <td><small style="color: var(--dark-text-muted);">${formatDate(req.created_at)}</small></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-success me-1 btn-approve-req" data-id="${req.id}">Approve & Void</button>
                        <button class="btn btn-sm btn-outline-secondary btn-reject-req" data-id="${req.id}">Reject</button>
                    </td>
                </tr>
            `).join('');
        }
    } catch (err) {
        console.error('Failed to load pending requests:', err);
    }
}

// ---------- REAL-TIME AUTO FILTERING LOGIC ----------

function debounce(func, delay = 300) {
    let timeout;
    return (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), delay);
    };
}

function applyAutoFilters() {
    loadJournalEntries();
    loadSummaryTotals();
}

document.getElementById('filterSearch')?.addEventListener('input', debounce(applyAutoFilters, 300));
document.getElementById('filterDateFrom')?.addEventListener('change', applyAutoFilters);
document.getElementById('filterDateTo')?.addEventListener('change', applyAutoFilters);
document.getElementById('filterType')?.addEventListener('change', applyAutoFilters);
document.getElementById('filterStatus')?.addEventListener('change', applyAutoFilters);

document.getElementById('clearFiltersBtn')?.addEventListener('click', () => {
    const search = document.getElementById('filterSearch');
    const dateFrom = document.getElementById('filterDateFrom');
    const dateTo = document.getElementById('filterDateTo');
    const type = document.getElementById('filterType');
    const status = document.getElementById('filterStatus');


    if (search) search.value = '';
    if (dateFrom) dateFrom.value = '';
    if (dateTo) dateTo.value = '';
    if (type) type.value = '';
    if (status) status.value = 'active';

    applyAutoFilters();
});

// ---------- Summary Totals Panel ----------
async function loadSummaryTotals() {
    const params = new URLSearchParams({ action: 'summary' });

    const search   = document.getElementById('filterSearch')?.value || '';
    const dateFrom = document.getElementById('filterDateFrom')?.value || '';
    const dateTo   = document.getElementById('filterDateTo')?.value || '';
    const typeId   = document.getElementById('filterType')?.value || '';

    if (search)   params.append('search', search);
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo)   params.append('date_to', dateTo);
    if (typeId)   params.append('transaction_type_id', typeId);

    try {
        const response = await fetch(`${API_URL}?${params.toString()}`);
        const data = await response.json();

        if (data.success) {
            const countEl   = document.getElementById('summaryCount');
            const inflowEl  = document.getElementById('summaryInflow');
            const outflowEl = document.getElementById('summaryOutflow');
            const netEl     = document.getElementById('summaryNet');

            // Exclude voided entries when computing client-side count
            const activeEntries = journalEntries.filter(entry => entry.status !== 'voided');
            if (countEl) countEl.textContent = activeEntries.length;

            if (inflowEl) inflowEl.textContent = formatMoney(data.cash_inflow || 0);
            if (outflowEl) outflowEl.textContent = formatMoney(data.cash_outflow || 0);
            if (netEl) netEl.textContent = formatMoney(data.net_cash_flow || 0);
        }
    } catch (err) {
        console.error('Failed to load summary totals:', err);
    }
}

// ---------- Manual / Auto Mode Templates ----------
const ENTRY_TEMPLATES = [
    {
        label: "Client Retainer Collection — Monthly Social Media / Ads Management",
        debitAccount: "Cash",
        creditAccount: "Unearned Revenue",
        transactionType: "Cash Inflow",
        description: "To record advance monthly retainer payment collected from client for agency services",
    },
    {
        label: "Recognize Monthly Retainer Revenue — End of Month Service Completion",
        debitAccount: "Unearned Revenue",
        creditAccount: "Service Revenue",
        transactionType: "Transfer",
        description: "To recognize monthly retainer revenue earned upon completion of agency deliverables",
    },
    {
        label: "Client Ad Budget Deposit — Pass-through Funds (Meta / TikTok / Google Ads)",
        debitAccount: "Cash",
        creditAccount: "Client Ad Budget Liability",
        transactionType: "Cash Inflow",
        description: "To record pass-through ad budget deposit received from client strictly for ad platform spend",
    },
    {
        label: "Pay Ad Platform on Behalf of Client — Meta / TikTok Ads",
        debitAccount: "Client Ad Budget Liability",
        creditAccount: "Cash",
        transactionType: "Cash Outflow",
        description: "To record payment disbursed directly to ad platforms from client's ad budget deposit",
    },
    {
        label: "One-Time Agency Project Billing — Logo, Web, or Video Campaign",
        debitAccount: "Accounts Receivable",
        creditAccount: "Service Revenue",
        transactionType: "Transfer",
        description: "To bill client for completed one-time design, production, or strategy project",
    },
    {
        label: "Sales Record — general cash sale",
        debitAccount: "Cash",
        creditAccount: "Sales Revenue",
        transactionType: "Cash Inflow",
        description: "To record cash sale",
    },
    {
        label: "Expense Record — general cash expense",
        debitAccount: "Purchases",
        creditAccount: "Cash",
        transactionType: "Cash Outflow",
        description: "To record cash expense",
    },
    {
        label: "Owner invests cash into the business",
        debitAccount: "Cash",
        creditAccount: "Owners Capital",
        transactionType: "Cash Inflow",
        description: "To record owner's initial investment",
    },
    {
        label: "Owner withdraws cash for personal use",
        debitAccount: "Owner's Drawing",
        creditAccount: "Cash",
        transactionType: "Cash Outflow",
        description: "To record cash withdrawal by the owner for personal expenses",
    },
    {
        label: "Paid for something in advance (prepaid expense)",
        debitAccount: "Prepaid Expense",
        creditAccount: "Cash",
        transactionType: "Cash Outflow",
        description: "To record payment of advance expense",
    },
    {
        label: "Received advance payment from a client",
        debitAccount: "Cash",
        creditAccount: "Unearned Revenue",
        transactionType: "Cash Inflow",
        description: "To record advance downpayment received from a client for future services",
    },
    {
        label: "Earned revenue that was collected in advance",
        debitAccount: "Unearned Revenue",
        creditAccount: "Service Revenue",
        transactionType: "Transfer",
        description: "To record the earning of revenue previously collected in advance",
    },
    {
        label: "Employee wages earned but not yet paid",
        debitAccount: "Salaries & Wages",
        creditAccount: "Salaries Payable",
        transactionType: "Transfer",
        description: "To record employee wages earned but unpaid at the end of the period",
    },
    {
        label: "Paid previously accrued wages",
        debitAccount: "Salaries Payable",
        creditAccount: "Cash",
        transactionType: "Cash Outflow",
        description: "To record cash payment for wages previously accrued",
    },
];

let currentMode = 'manual';

function setEntryMode(mode) {
    currentMode = mode;

    document.getElementById('modeManualBtn').classList.toggle('primary', mode === 'manual');
    document.getElementById('modeAutoBtn').classList.toggle('primary', mode === 'auto');
    document.getElementById('autoModeSection').classList.toggle('d-none', mode === 'manual');
}

document.getElementById('modeManualBtn')?.addEventListener('click', () => setEntryMode('manual'));
document.getElementById('modeAutoBtn')?.addEventListener('click', () => setEntryMode('auto'));

function loadTemplateDropdown() {
    const select = document.getElementById('templateSelect');
    if (!select) return;

    select.innerHTML = '<option value="">-- Select a transaction type --</option>' +
        ENTRY_TEMPLATES.map((t, i) => `<option value="${i}">${t.label}</option>`).join('');
}

document.getElementById('templateSelect')?.addEventListener('change', (e) => {
    const index = e.target.value;
    if (index === '') return;

    const template = ENTRY_TEMPLATES[index];

    const debitAccount = allAccountsForLines.find(a => a.name === template.debitAccount);
    const creditAccount = allAccountsForLines.find(a => a.name === template.creditAccount);

    if (!debitAccount || !creditAccount) {
        alert(`One of the accounts for this template ("${template.debitAccount}" or "${template.creditAccount}") wasn't found in your Chart of Accounts.`);
        return;
    }

    document.getElementById('debitLinesContainer').innerHTML = '';
    document.getElementById('creditLinesContainer').innerHTML = '';

    const debitRow = createAccountLineRow('debit');
    const creditRow = createAccountLineRow('credit');

    document.getElementById('debitLinesContainer').appendChild(debitRow);
    document.getElementById('creditLinesContainer').appendChild(creditRow);

    debitRow.querySelector('.line-account').value = debitAccount.id;
    creditRow.querySelector('.line-account').value = creditAccount.id;

    debitRow.querySelector('.line-account').disabled = true;
    creditRow.querySelector('.line-account').disabled = true;

    const typeOption = Array.from(document.getElementById('transactionTypeId').options)
        .find(opt => opt.textContent === template.transactionType);
    if (typeOption) document.getElementById('transactionTypeId').value = typeOption.value;

    document.getElementById('description').value = template.description;

    recalculateLineTotals();
});

const autoLockedFields = [
    document.getElementById('transactionTypeId'),
    document.getElementById('referenceNo'),
    document.getElementById('toAccountId'),
    document.getElementById('fromAccountId'),
];

function setAutoLock(locked) {
    autoLockedFields.forEach((field) => {
        if (!field) return;

        if (field.tagName === 'SELECT' || field.type === 'date') {
            field.classList.toggle('is-locked', locked);
            field.setAttribute('tabindex', locked ? '-1' : '0');
        } else {
            field.readOnly = locked;
            field.classList.toggle('is-locked', locked);
        }
    });
}

document.getElementById('modeManualBtn')?.addEventListener('click', () => {
    setAutoLock(false);
});

document.getElementById('modeAutoBtn')?.addEventListener('click', () => {
    if (document.getElementById('templateSelect')?.value) {
        setAutoLock(true);
    }
});

document.getElementById('templateSelect')?.addEventListener('change', (event) => {
    const hasTemplate = Boolean(event.target.value);
    if (hasTemplate) {
        setAutoLock(true);
    } else {
        setAutoLock(false);
    }
});

// ---------- Nav Expandable ----------
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

// ---------- Export PDF Receipt ----------
let receiptPreviewModal;

document.getElementById('printReceiptBtn')?.addEventListener('click', function () {
    const mainModalEl = document.getElementById('viewEntryModal');
    const previewModalEl = document.getElementById('receiptPreviewModal');
    const entryId = mainModalEl?.dataset.journalId;

    if (!entryId) {
        alert('No entry details loaded.');
        return;
    }

    if (!receiptPreviewModal) {
        receiptPreviewModal = new bootstrap.Modal(previewModalEl);

        previewModalEl.addEventListener('hidden.bs.modal', function () {
            mainModalEl.classList.remove('side-by-side');
            previewModalEl.classList.remove('side-by-side');
        });
    }

    mainModalEl.classList.add('side-by-side');
    previewModalEl.classList.add('side-by-side');

    const previewUrl = `routes/export.php?action=journal-entry-pdf&id=${entryId}&preview=1`;
    const downloadUrl = `routes/export.php?action=journal-entry-pdf&id=${entryId}`;
    
    document.getElementById('receiptPreviewFrame').src = previewUrl;

    document.getElementById('downloadReceiptBtn').onclick = function () {
        window.location.href = downloadUrl;
    };

    receiptPreviewModal.show();
});

// Client dropdown
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

document.addEventListener('DOMContentLoaded', () => {
    loadClientDropdown();
});

// Entry saved notification
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
        info: 'bi-info-circle-fill'
    };

    const toast = document.createElement('div');
    toast.className = `toast-glass toast-${type}`;
    toast.innerHTML = `
        <i class="bi ${iconMap[type] || iconMap.info} fs-5"></i>
        <span>${escapeHtml(message)}</span>
    `;

    container.appendChild(toast);

    setTimeout(() => toast.classList.add('show'), 50);

    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}



window.executeDirectVoid = async function(journalId) {
    console.log('executeDirectVoid called for ID:', journalId);
    
    if (!journalId || journalId <= 0) {
        showToast('Invalid journal ID provided.', 'danger');
        return;
    }

    if (!confirm('Are you sure you want to void this journal entry? Account balances will be automatically adjusted.')) {
        return;
    }

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'void', journal_id: journalId })
        });

        const data = await response.json();

        if (data.success) {
            showToast('Entry voided and balances adjusted successfully!', 'success');
            loadJournalEntries();
            loadSummaryTotals();
        } else {
            showToast(data.error || 'Failed to void entry.', 'danger');
        }
    } catch (err) {
        console.error('Void request error:', err);
        showToast('Server connection failure while attempting to void.', 'danger');
    }
};

window.executeRequestVoid = function(journalId) {
    if (!journalId || journalId <= 0) {
        showToast('Invalid journal ID provided.', 'danger');
        return;
    }

    const deleteJournalIdInput = document.getElementById('deleteJournalId');
    const deleteReasonInput = document.getElementById('deleteReason');
    const errorBox = document.getElementById('requestDeletionError');

    if (deleteJournalIdInput) deleteJournalIdInput.value = journalId;
    if (deleteReasonInput) deleteReasonInput.value = '';
    if (errorBox) errorBox.classList.add('d-none');

    const modalEl = document.getElementById('requestDeletionModal');
    if (modalEl) {
        const requestModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        requestModal.show();
    }
};

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
loadReferenceData();
loadJournalEntries();
loadSummaryTotals();
loadTemplateDropdown();