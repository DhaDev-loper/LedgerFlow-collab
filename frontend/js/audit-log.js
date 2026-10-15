document.addEventListener('DOMContentLoaded', () => {
    const tableBody = document.getElementById('auditTableBody');
    const countLabel = document.getElementById('auditResultCount');
    const pageLabel = document.getElementById('auditPageLabel');
    const perPageSelect = document.getElementById('auditPerPage');
    const previousButton = document.getElementById('auditPrev');
    const nextButton = document.getElementById('auditNext');
    const filtersForm = document.getElementById('auditFilters');
    const clearButton = document.getElementById('clearAuditFilters');
    const journalExpandable = document.getElementById('journalExpandable');
    const journalTrigger = document.getElementById('journalTrigger');
    let page = 1;
    let total = 0;

    journalTrigger?.addEventListener('click', (event) => {
        event.preventDefault();
        journalExpandable?.classList.toggle('active');
    });

    document.getElementById('logoutBtn')?.addEventListener('click', async (event) => {
        event.preventDefault();
        try {
            await fetch('routes/auth.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'logout' }),
                credentials: 'same-origin',
            });
        } finally {
            window.location.href = '../frontend/login.html';
        }
    });

    const appendCell = (row, value) => {
        const cell = document.createElement('td');
        cell.textContent = value == null || value === '' ? '—' : String(value);
        row.appendChild(cell);
    };

    const actionLabels = {
        LOGIN_SUCCESS: 'Signed in',
        CREATE_JOURNAL_ENTRY: 'Recorded journal entry',
        EDIT_JOURNAL_ENTRY: 'Edited journal entry',
        VOID_JOURNAL_ENTRY: 'Voided journal entry',
        CREATE_ACCOUNT: 'Created account',
        EDIT_ACCOUNT: 'Updated account',
        TOGGLE_ACCOUNT_STATUS: 'Changed account status',
        CREATE_CLIENT: 'Added client',
        EDIT_CLIENT: 'Updated client',
        TOGGLE_CLIENT_STATUS: 'Changed client status',
        CREATE_USER: 'Added user',
        EDIT_USER: 'Updated user',
        TOGGLE_USER_STATUS: 'Changed user status',
        DELETE_USER: 'Removed user',
        RESTORE_USER: 'Restored user',
    };
    const entityLabels = {
        account: 'Account',
        client: 'Client',
        journal: 'Journal entry',
        user: 'User',
    };
    const fieldLabels = {
        category_id: 'Reporting category',
        classification: 'Classification',
        code: 'Account code',
        description: 'Description',
        document_type: 'Document type',
        email: 'Email',
        is_active: 'Status',
        is_cash_account: 'Cash or bank account',
        method: 'Sign-in method',
        name: 'Name',
        reference_no: 'Reference',
        role: 'Role',
        account_type_id: 'Account type',
        total_amount: 'Amount',
    };

    function formatAuditDetails(details) {
        if (!details) return '—';

        let values;
        try {
            values = JSON.parse(details);
        } catch {
            return details;
        }
        if (!values || typeof values !== 'object' || Array.isArray(values)) {
            return String(values ?? '—');
        }

        const readable = Object.entries(values).flatMap(([key, value]) => {
            if (value === null || value === '') return [];
            const label = fieldLabels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase());
            let displayValue = value;

            if (key === 'is_active') displayValue = value ? 'Active' : 'Inactive';
            else if (key === 'is_cash_account') displayValue = value ? 'Yes' : 'No';
            else if (key === 'classification') displayValue = value === 'non_current' ? 'Non-current' : 'Current';
            else if (key === 'account_type_id') {
                displayValue = ({ 1: 'Asset', 2: 'Liability', 3: 'Equity', 4: 'Revenue', 5: 'Expense' })[value] || 'Account';
            } else if (key === 'settlement_allocations') {
                return Array.isArray(value) && value.length > 0
                    ? [`Settlement allocations: ${value.length} recorded`]
                    : [];
            }
            else if (key === 'role' || key === 'method' || key === 'document_type') {
                displayValue = String(value).replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase());
            } else if (key === 'total_amount' && Number.isFinite(Number(value))) {
                displayValue = `₱${Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            } else if (key === 'category_id') {
                displayValue = Number(value) > 0 ? 'Assigned' : 'Removed';
            } else if (key === 'transaction_type_id') {
                displayValue = ({
                    1: 'Cash outflow',
                    2: 'Cash inflow',
                    3: 'Transfer',
                    4: 'Opening balance',
                })[value] || 'Journal entry';
                return [`Type: ${displayValue}`];
            } else if (key === 'client_id') {
                return value ? ['Client linked'] : [];
            } else if (typeof value === 'object') {
                return [];
            }
            return [`${label}: ${String(displayValue)}`];
        });

        return readable.length > 0 ? readable.join(' · ') : 'Change recorded';
    }

    function formatAuditDate(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime())
            ? String(value)
            : date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }

    async function loadAuditLogs() {
        tableBody.innerHTML = '<tr><td colspan="5" class="text-center py-4">Loading...</td></tr>';
        const query = new URLSearchParams({
            page: String(page),
            per_page: perPageSelect.value,
            search: document.getElementById('auditSearch').value.trim(),
            action_filter: document.getElementById('auditAction').value.trim(),
            entity_type: document.getElementById('auditEntity').value.trim(),
            date_from: document.getElementById('auditDateFrom').value,
            date_to: document.getElementById('auditDateTo').value,
        });

        try {
            const response = await fetch(`routes/audit.php?${query.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.error || 'Unable to load audit logs.');
            }

            total = result.total;
            tableBody.replaceChildren();
            if (result.logs.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="6" class="text-center py-4">No audit events found.</td></tr>';
            } else {
                result.logs.forEach((log) => {
                    const row = document.createElement('tr');
                    appendCell(row, formatAuditDate(log.created_at));
                    appendCell(row, log.user_name || (log.user_id ? 'Former user' : 'System'));
                    appendCell(row, actionLabels[log.action] || log.action.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase()));
                    appendCell(row, entityLabels[log.entity_type] || log.entity_type);
                    appendCell(row, formatAuditDetails(log.details));
                    tableBody.appendChild(row);
                });
            }

            const perPage = Number(perPageSelect.value);
            const pageCount = Math.max(1, Math.ceil(total / perPage));
            countLabel.textContent = `Showing ${total === 0 ? 0 : (page - 1) * perPage + 1}–${Math.min(page * perPage, total)} of ${total} events`;
            pageLabel.textContent = `Page ${page} of ${pageCount}`;
            previousButton.disabled = page <= 1;
            nextButton.disabled = page >= pageCount;
        } catch (error) {
            tableBody.replaceChildren();
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 5;
            cell.className = 'text-center text-danger py-4';
            cell.textContent = error.message;
            row.appendChild(cell);
            tableBody.appendChild(row);
            countLabel.textContent = 'Audit events could not be loaded.';
            pageLabel.textContent = '';
            previousButton.disabled = true;
            nextButton.disabled = true;
        }
    }

    filtersForm.addEventListener('submit', (event) => {
        event.preventDefault();
        page = 1;
        loadAuditLogs();
    });
    clearButton.addEventListener('click', () => {
        window.setTimeout(() => {
            page = 1;
            loadAuditLogs();
        });
    });
    perPageSelect.addEventListener('change', () => {
        page = 1;
        loadAuditLogs();
    });
    previousButton.addEventListener('click', () => {
        if (page > 1) {
            page -= 1;
            loadAuditLogs();
        }
    });
    nextButton.addEventListener('click', () => {
        if (page * Number(perPageSelect.value) < total) {
            page += 1;
            loadAuditLogs();
        }
    });
    loadAuditLogs();
});
