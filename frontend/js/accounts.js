const API_URL = 'routes/accounts.php';

let accountModal, accountForm, formError, modalTitle;

if (CAN_MANAGE) {
    accountModal = new bootstrap.Modal(document.getElementById('accountModal'));
    accountForm = document.getElementById('accountForm');
    formError = document.getElementById('accountFormError');
    modalTitle = document.getElementById('accountModalTitle');
}

// ---------- Load account types into the dropdown ----------
async function loadAccountTypes() {
    if (!CAN_MANAGE) return;

    try {
        const response = await fetch(`${API_URL}?action=account-types`);
        const data = await response.json();

        if (data.success) {
            const select = document.getElementById('accountTypeId');
            select.innerHTML = data.types.map(t => `<option value="${t.id}">${t.name}</option>`).join('');
        }
    } catch (err) {
        console.error('Failed to load account types:', err);
    }
}

// ---------- Load and render accounts ----------
async function loadAccounts() {
    const tbody = document.getElementById('accountsTableBody');
    const summaryContainer = document.getElementById('summaryCards');

    try {
        const response = await fetch(`${API_URL}?action=list`);
        const rawText = await response.text();
        console.log('LIST RAW RESPONSE:', rawText);
        const data = JSON.parse(rawText);

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Failed to load accounts.</td></tr>`;
            return;
        }

        renderSummaryCards(data.accounts, summaryContainer);
        renderTable(data.accounts, tbody);

    } catch (err) {
        console.error('Failed to load accounts:', err);
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Could not reach the server.</td></tr>`;
    }
}

function renderSummaryCards(accounts, container) {
    const groups = {};
    accounts.forEach(acc => {
        if (!groups[acc.type_name]) groups[acc.type_name] = 0;
        if (acc.is_active == 1) groups[acc.type_name] += parseFloat(acc.current_balance);
    });

    const order = ['Asset', 'Revenue', 'Expense', 'Liability', 'Equity'];
    container.innerHTML = order
        .filter(type => groups[type] !== undefined)
        .map(type => `
            <div class="col-6 col-lg-3">
                <div class="kpi-card">
                    <div class="kpi-label">${type} Accounts</div>
                    <div class="kpi-value money">${formatMoney(groups[type])}</div>
                </div>
            </div>
        `).join('');
}

function renderTable(accounts, tbody) {
    if (accounts.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4" style="color: var(--dark-text-muted);">No accounts found.</td></tr>`;
        return;
    }

    tbody.innerHTML = accounts.map(acc => `
        <tr>
            <td>${escapeHtml(acc.name)}</td>
            <td><span class="role-badge role-${acc.type_name.toLowerCase()}">${acc.type_name}</span></td>
            <td class="text-end money fw-semibold">${formatMoney(acc.current_balance)}</td>
            <td>
                <span class="badge-${acc.is_active == 1 ? 'in' : 'out'}">
                    ${acc.is_active == 1 ? 'Active' : 'Inactive'}
                </span>
            </td>
            ${CAN_MANAGE ? `
            <td class="text-end">
                <button class="table-action-btn" onclick='openEditModal(${JSON.stringify(acc)})'>Edit</button>
                <button class="table-action-btn" onclick="toggleStatus(${acc.id})">
                    ${acc.is_active == 1 ? 'Deactivate' : 'Activate'}
                </button>
            </td>` : ''}
        </tr>
    `).join('');
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatMoney(amount) {
    return '₱' + parseFloat(amount).toLocaleString(undefined, { minimumFractionDigits: 2 });
}


if (CAN_MANAGE) {
    // ---------- Add Account button ----------
    document.getElementById('addAccountBtn').addEventListener('click', () => {
        accountForm.reset();
        document.getElementById('accountId').value = '';
        modalTitle.textContent = 'Add Account';
        document.getElementById('openingBalanceGroup').style.display = 'block';
        formError.classList.add('d-none');
    });

    // ---------- Edit (called inline from rendered rows) ----------
    window.openEditModal = function (account) {
        accountForm.reset();
        formError.classList.add('d-none');

        document.getElementById('accountId').value = account.id;
        document.getElementById('accountName').value = account.name;
        document.getElementById('accountTypeId').value = account.account_type_id;
        document.getElementById('accountNotes').value = account.notes || '';

        modalTitle.textContent = 'Edit Account';
        // Balances are only ever changed through journal entries, never
        // edited directly — hide this field when editing.
        document.getElementById('openingBalanceGroup').style.display = 'none';

        accountModal.show();
    };

    // ---------- Save (handles both create and edit) ----------
    accountForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        formError.classList.add('d-none');

        const accountId = document.getElementById('accountId').value;
        const isEdit = accountId !== '';

        const formData = new FormData(accountForm);
        formData.append('action', isEdit ? 'edit' : 'create');

        try {
                  const response = await fetch(API_URL, { method: 'POST', body: formData });
                  const rawText = await response.text();
                  console.log('RAW RESPONSE:', rawText);
                  const data = JSON.parse(rawText);
            if (data.success) {
                accountModal.hide();
                loadAccounts();
            } else {
                formError.textContent = data.error || 'Something went wrong.';
                formError.classList.remove('d-none');
            }
        } catch (err) {
            console.error('Save account failed:', err);
            formError.textContent = 'Could not reach the server.';
            formError.classList.remove('d-none');
        }
    });

    // ---------- Toggle status (called inline from rendered rows) ----------
    window.toggleStatus = async function (accountId) {
        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                body: new URLSearchParams({ action: 'toggle-status', account_id: accountId }),
            });
            const data = await response.json();

            if (data.success) {
                loadAccounts();
            } else {
                alert(data.error || 'Something went wrong.');
            }
        } catch (err) {
            console.error('Toggle status failed:', err);
            alert('Could not reach the server.');
        }
    };
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
loadAccountTypes();
loadAccounts();