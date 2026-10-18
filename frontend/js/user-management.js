const API_URL = 'routes/user-management.php';

const userModalEl = document.getElementById('userModal');
const userModal = new bootstrap.Modal(userModalEl);
const deleteModalEl = document.getElementById('deleteModal');
const deleteModal = new bootstrap.Modal(deleteModalEl);

const userForm = document.getElementById('userForm');
const formError = document.getElementById('formError');
const userModalTitle = document.getElementById('userModalTitle');
const passwordGroup = document.getElementById('passwordGroup');

let rawUsersList = [];
let activeRoleFilter = 'all';
let userIdPendingDelete = null;
const roleSortOrder = { owner: 0, admin: 1, accountant: 2, staff: 3 };

// ---------- Load and render users ----------
async function loadUsers() {
    const tbody = document.getElementById('usersTableBody');

    try {
        const response = await fetch(`${API_URL}?action=list`);
        const responseText = await response.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            console.error('User list endpoint returned a non-JSON response:', response.status, responseText);
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">The server returned an invalid response (HTTP ${response.status}). Check the PHP error log and database migrations.</td></tr>`;
            return;
        }

        if (!response.ok || !data.success) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">${escapeHtml(data.error || `Failed to load users (HTTP ${response.status}).`)}</td></tr>`;
            return;
        }

        rawUsersList = data.users || [];
        updateRoleBadgeCounts();
        applyUserFilters();

    } catch (err) {
        console.error('Failed to load users:', err);
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Could not contact the server. Check your connection and try again.</td></tr>`;
    }
}

// ---------- Update Tab Badge Counts ----------
function updateRoleBadgeCounts() {
    const counts = { all: rawUsersList.length, owner: 0, admin: 0, accountant: 0, staff: 0 };

    rawUsersList.forEach(u => {
        const r = (u.role || '').toLowerCase();
        if (r in counts) counts[r]++;
    });

    document.getElementById('cntAll').textContent = counts.all;
    document.getElementById('cntOwner').textContent = counts.owner;
    document.getElementById('cntAdmin').textContent = counts.admin;
    document.getElementById('cntAccountant').textContent = counts.accountant;
    document.getElementById('cntStaff').textContent = counts.staff;
}

// ---------- Filter Logic ----------
window.setRoleFilter = function (role) {
    activeRoleFilter = role;

    const btns = document.querySelectorAll('#roleFilterContainer .role-filter-btn');
    btns.forEach(btn => {
        if (btn.getAttribute('onclick').includes(`'${role}'`)) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    applyUserFilters();
};

document.getElementById('userSearchInput')?.addEventListener('input', applyUserFilters);

function applyUserFilters() {
    const tbody = document.getElementById('usersTableBody');
    const searchTerm = (document.getElementById('userSearchInput')?.value || '').toLowerCase().trim();

    let filtered = rawUsersList;

    if (activeRoleFilter !== 'all') {
        filtered = filtered.filter(u => (u.role || '').toLowerCase() === activeRoleFilter);
    }

    if (searchTerm !== '') {
        filtered = filtered.filter(u => 
            (u.full_name || '').toLowerCase().includes(searchTerm) || 
            (u.email || '').toLowerCase().includes(searchTerm)
        );
    }

    const organizedUsers = [...filtered].sort((a, b) => {
        const roleA = (a.role || 'staff').toLowerCase();
        const roleB = (b.role || 'staff').toLowerCase();
        const roleOrderDifference = (roleSortOrder[roleA] ?? Number.MAX_SAFE_INTEGER)
            - (roleSortOrder[roleB] ?? Number.MAX_SAFE_INTEGER);

        return roleOrderDifference || (a.full_name || '').localeCompare(b.full_name || '', undefined, {
            sensitivity: 'base',
        });
    });

    renderTableRows(organizedUsers, tbody);
}

// ---------- Render Table ----------
function renderTableRows(users, tbody) {
    if (users.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4" style="color: var(--dark-text-muted);">No users match your filter.</td></tr>`;
        return;
    }

    tbody.innerHTML = users.map(user => {
        const initials = getInitials(user.full_name);
        const roleLower = (user.role || 'staff').toLowerCase();
        const isSelf = parseInt(user.id, 10) === parseInt(typeof CURRENT_USER_ID !== 'undefined' ? CURRENT_USER_ID : 0, 10);
        const isLockedOut = Number(user.suspension_level) >= 4;
        const setupPending = Number(user.password_setup_required) === 1;
        const suspensionEnds = user.locked_until ? new Date(user.locked_until.replace(' ', 'T')) : null;
        const isSuspended = suspensionEnds && suspensionEnds > new Date();
        const isOwner = typeof CURRENT_USER_ROLE !== 'undefined' && CURRENT_USER_ROLE === 'owner';
        const statusLabel = isLockedOut
            ? 'Locked out — CEO activation required'
            : setupPending
                ? 'Setup pending'
                : isSuspended
                    ? `Suspended until ${suspensionEnds.toLocaleString()}`
                    : user.is_active == 1 ? 'Active' : 'Inactive';

        const actionsHtml = isSelf
            ? `<button class="action-icon-btn" onclick='openEditModal(${JSON.stringify(user)})' title="Edit Profile">
                   <i class="bi bi-pencil"></i>
               </button>`
            : `<button class="action-icon-btn" onclick='openEditModal(${JSON.stringify(user)})' title="Edit User">
                   <i class="bi bi-pencil"></i>
               </button>
            ${isLockedOut && !isOwner ? '' : `<button class="action-icon-btn warning" onclick="toggleStatus(${user.id})" title="${isLockedOut ? 'Reactivate locked-out user' : user.is_active == 1 ? 'Deactivate' : 'Activate'} User">
                   <i class="bi bi-power"></i>
            </button>`}
               <button class="action-icon-btn danger" onclick='openDeleteModal(${user.id}, ${JSON.stringify(user.full_name)})' title="Delete User">
                   <i class="bi bi-trash"></i>
               </button>`;

        return `
            <tr>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="avatar-initials avatar-${roleLower}">
                            ${initials}
                        </div>
                        <span class="fw-semibold text-white">${escapeHtml(user.full_name)}${isSelf ? ' <small class="text-muted">(You)</small>' : ''}</span>
                    </div>
                </td>
                <td><span style="color: var(--dark-text-muted);">${escapeHtml(user.email)}</span></td>
                <td><span class="role-badge role-${roleLower}">${escapeHtml(user.role_title || capitalize(user.role))}</span></td>
                <td>
                    <span class="badge-${setupPending || user.is_active != 1 || isSuspended ? 'out' : 'in'}">
                        ${escapeHtml(statusLabel)}
                    </span>
                </td>
                <td><span class="small" style="color: var(--dark-text-muted);">${formatDate(user.created_at)}</span></td>
                <td class="text-end">
                    ${actionsHtml}
                    ${setupPending && user.is_active == 1 ? `<button class="action-icon-btn" onclick="resendSetupInvitation(${user.id})" title="Resend setup invitation">
                        <i class="bi bi-envelope-arrow-up"></i>
                    </button>` : ''}
                </td>
            </tr>
        `;
    }).join('');
}

function getInitials(name) {
    if (!name) return 'U';
    const parts = name.trim().split(' ');
    if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function capitalize(str) {
    return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const date = new Date(dateStr.replace(' ', 'T'));
    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

// ---------- Add User button ----------
document.getElementById('addUserBtn')?.addEventListener('click', () => {
    userForm.reset();
    document.getElementById('userId').value = '';
    userModalTitle.textContent = 'Add User';
    
    // Reset Role Container to standard dropdown
    renderRoleField(false, 'staff');

    passwordGroup.classList.remove('d-none');
    formError.classList.add('d-none');
});

// ---------- Helper to render Role field dynamically ----------
function renderRoleField(isOwner, currentRole = 'staff') {
    const roleContainer = document.getElementById('roleContainer');
    if (!roleContainer) return;

    if (isOwner) {
        roleContainer.innerHTML = `
            <label class="form-label small">Role</label>
            <input type="text" class="form-control form-control-dark" value="CEO" disabled>
            <input type="hidden" name="role" value="owner">
            <div class="form-text" style="color: var(--dark-text-muted);">CEO role cannot be changed.</div>
        `;
    } else {
        roleContainer.innerHTML = `
            <label class="form-label small">Role</label>
            <select class="form-select form-control-dark" id="role" name="role">
                <option value="staff" ${currentRole === 'staff' ? 'selected' : ''}>Accounts Executive</option>
                <option value="accountant" ${currentRole === 'accountant' ? 'selected' : ''}>Senior Accountant</option>
                <option value="admin" ${currentRole === 'admin' ? 'selected' : ''}>CFO</option>
            </select>
        `;
    }
}

// ---------- Edit button ----------
window.openEditModal = function (user) {
    userForm.reset();
    formError.classList.add('d-none');

    document.getElementById('userId').value = user.id;
    document.getElementById('fullName').value = user.full_name;
    document.getElementById('email').value = user.email;

    const isOwner = (user.role || '').toLowerCase() === 'owner';
    renderRoleField(isOwner, (user.role || '').toLowerCase());

    userModalTitle.textContent = isOwner ? 'Edit Profile' : 'Edit User';
    passwordGroup.classList.add('d-none');

    userModal.show();
};

// ---------- Save User (Create/Edit) ----------
userForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    formError.classList.add('d-none');

    const userId = document.getElementById('userId').value;
    const isEdit = userId !== '';

    const formData = new FormData(userForm);
    formData.append('action', isEdit ? 'edit' : 'create');

    try {
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            userModal.hide();
            loadUsers();
            if (data.warning) {
                alert(data.warning);
            }
        } else {
            formError.textContent = data.error || 'Something went wrong.';
            formError.classList.remove('d-none');
        }
    } catch (err) {
        console.error('Save user failed:', err);
        formError.textContent = 'Could not reach the server.';
        formError.classList.remove('d-none');
    }
});

window.resendSetupInvitation = async function (userId) {
    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'resend-setup-invitation',
                user_id: userId,
            }),
        });
        const data = await response.json();

        if (data.success) {
            alert(data.message || 'A new setup link was sent.');
        } else {
            alert(data.error || 'Could not send the setup link.');
        }
    } catch (err) {
        console.error('Resending setup invitation failed:', err);
        alert('Could not reach the server.');
    }
};

// ---------- Toggle status ----------
window.toggleStatus = async function (userId) {
    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: new URLSearchParams({ action: 'toggle-status', user_id: userId }),
        });
        const data = await response.json();

        if (data.success) {
            loadUsers();
        } else {
            alert(data.error || 'Something went wrong.');
        }
    } catch (err) {
        console.error('Toggle status failed:', err);
        alert('Could not reach the server.');
    }
};

// ---------- Delete flow ----------
window.openDeleteModal = function (userId, userName) {
    userIdPendingDelete = userId;
    document.getElementById('deleteUserName').textContent = userName;
    deleteModal.show();
};

document.getElementById('confirmDeleteBtn')?.addEventListener('click', async () => {
    if (!userIdPendingDelete) return;

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: new URLSearchParams({ action: 'delete', user_id: userIdPendingDelete }),
        });
        const data = await response.json();

        if (data.success) {
            deleteModal.hide();
            loadUsers();
        } else {
            alert(data.error || 'Something went wrong.');
        }
    } catch (err) {
        console.error('Delete failed:', err);
        alert('Could not reach the server.');
    } finally {
        userIdPendingDelete = null;
    }
});

// -- journal entries navigation expandable
document.addEventListener('DOMContentLoaded', () => {
    const journalExpandable = document.getElementById('journalExpandable');
    const journalTrigger = document.getElementById('journalTrigger');

    if (journalExpandable && journalTrigger) {
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

// ---------- Archive Modal & Restoration Flow ----------

// Function to show modal using Bootstrap 5 API and load data
async function openArchiveModal() {
    const archiveModalElem = document.getElementById('archiveModal');
    const bsModal = bootstrap.Modal.getOrCreateInstance(archiveModalElem);
    bsModal.show();
    
    await loadArchivedUsers();
}

// Fetch archived accounts using the uniform API_URL route
async function loadArchivedUsers() {
    const tbody = document.getElementById('archivedUsersTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Loading archived accounts...</td></tr>';

    try {
        const response = await fetch(`${API_URL}?action=list-archived`);
        const data = await response.json();

        if (!data.success || !data.users || data.users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No archived accounts found.</td></tr>';
            return;
        }

        tbody.innerHTML = data.users.map(user => `
            <tr class="border-bottom border-secondary border-opacity-10">
                <td class="fw-semibold text-white">${escapeHtml(user.full_name)}</td>
                <td class="text-muted">${escapeHtml(user.email)}</td>
                <td>
                    <span class="badge bg-secondary bg-opacity-25 text-light px-2 py-1">${escapeHtml(user.role_title || capitalize(user.role))}</span>
                    ${Number(user.suspension_level) >= 4 ? '<span class="badge bg-danger bg-opacity-25 text-danger ms-1">Locked out</span>' : ''}
                </td>
                <td class="text-muted small">${user.deleted_at ? new Date(user.deleted_at).toLocaleDateString() : 'N/A'}</td>
                <td class="text-end">
                    ${Number(user.suspension_level) >= 4 && CURRENT_USER_ROLE !== 'owner'
                        ? '<span class="small text-muted">CEO only</span>'
                        : `<button type="button" onclick="restoreUser(${user.id})" class="btn btn-sm btn-success bg-opacity-25 text-success border-0 px-3 rounded-pill">Restore</button>`}
                </td>
            </tr>
        `).join('');
    } catch (err) {
        console.error("Archive fetch error:", err);
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Failed to load archived accounts.</td></tr>';
    }
}

// Restore archived account using the uniform API_URL route
async function restoreUser(userId) {
    if (!confirm('Restore this account and email the user a password setup link?')) return;

    const formData = new FormData();
    formData.append('action', 'restore');
    formData.append('user_id', userId);

    try {
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            await loadArchivedUsers();
            loadUsers(); // Refresh main active users table
            if (data.warning) {
                alert(data.warning);
            }
        } else {
            alert(data.error || 'Failed to restore account.');
        }
    } catch (err) {
        alert('An error occurred while attempting to restore the account.');
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
loadUsers();