const API_URL = 'routes/user-management.php';

const userModalEl = document.getElementById('userModal');
const userModal = new bootstrap.Modal(userModalEl);
const deleteModalEl = document.getElementById('deleteModal');
const deleteModal = new bootstrap.Modal(deleteModalEl);

const userForm = document.getElementById('userForm');
const formError = document.getElementById('formError');
const userModalTitle = document.getElementById('userModalTitle');
const passwordGroup = document.getElementById('passwordGroup');
const passwordInput = document.getElementById('password');

let userIdPendingDelete = null;

// ---------- Load and render the user list ----------
async function loadUsers() {
    const tbody = document.getElementById('usersTableBody');

    try {
        const response = await fetch(`${API_URL}?action=list`);
        const data = await response.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Failed to load users.</td></tr>`;
            return;
        }

        if (data.users.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4" style="color: var(--dark-text-muted);">No users found.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.users.map(user => `
            <tr>
                <td>${escapeHtml(user.full_name)}</td>
                <td>${escapeHtml(user.email)}</td>
                <td><span class="role-badge role-${user.role}">${capitalize(user.role)}</span></td>
                <td>
                    <span class="badge-${user.is_active == 1 ? 'in' : 'out'}">
                        ${user.is_active == 1 ? 'Active' : 'Inactive'}
                    </span>
                </td>
                <td><span class="small" style="color: var(--dark-text-muted);">${formatDate(user.created_at)}</span></td>
                <td class="text-end">
                    <button class="table-action-btn" onclick='openEditModal(${JSON.stringify(user)})'>Edit</button>
                    <button class="table-action-btn" onclick="toggleStatus(${user.id})">
                        ${user.is_active == 1 ? 'Deactivate' : 'Activate'}
                    </button>
                    <button class="table-action-btn danger" onclick='openDeleteModal(${user.id}, ${JSON.stringify(user.full_name)})'>Delete</button>
                </td>
            </tr>
        `).join('');

    } catch (err) {
        console.error('Failed to load users:', err);
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Could not reach the server.</td></tr>`;
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function formatDate(dateStr) {
    const date = new Date(dateStr.replace(' ', 'T'));
    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

// ---------- Add User button ----------
document.getElementById('addUserBtn').addEventListener('click', () => {
    userForm.reset();
    document.getElementById('userId').value = '';
    userModalTitle.textContent = 'Add User';
    passwordInput.required = true;
    passwordGroup.querySelector('.form-text').textContent = 'Minimum 8 characters.';
    formError.classList.add('d-none');
});

// ---------- Edit button (called inline from rendered rows) ----------
function openEditModal(user) {
    userForm.reset();
    formError.classList.add('d-none');

    document.getElementById('userId').value = user.id;
    document.getElementById('fullName').value = user.full_name;
    document.getElementById('email').value = user.email;
    document.getElementById('role').value = user.role;

    userModalTitle.textContent = 'Edit User';
    passwordInput.required = false;
    passwordGroup.querySelector('.form-text').textContent = 'Editing is not supported for password here — use Forgot Password instead.';
    passwordInput.disabled = true;

    userModal.show();
}

// ---------- Save (handles both create and edit) ----------
userForm.addEventListener('submit', async (e) => {
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
            passwordInput.disabled = false;
            loadUsers();
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

// ---------- Toggle status (called inline from rendered rows) ----------
async function toggleStatus(userId) {
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
}

// ---------- Delete flow ----------
function openDeleteModal(userId, userName) {
    userIdPendingDelete = userId;
    document.getElementById('deleteUserName').textContent = userName;
    deleteModal.show();
}

document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {
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

// ---------- Logout (same pattern as dashboard.js) ----------
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