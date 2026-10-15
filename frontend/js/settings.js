const API_URL = 'routes/settings.php';
let currentTab = 'business';

function setActiveSettingsTab(tab) {
    currentTab = tab;

    const tabs = {
        business: 'tabBusiness',
        account: 'tabAccount',
        categories: 'tabCategories',
        appearance: 'tabAppearance',
    };
    Object.entries(tabs).forEach(([name, id]) => {
        const button = document.getElementById(id);
        if (!button) return;
        const isActive = tab === name;
        button.classList.toggle('primary', isActive);
        button.setAttribute('aria-selected', String(isActive));
    });

    if (tab === 'business') loadBusinessTab();
    else if (tab === 'account') loadAccountTab();
    else if (tab === 'categories') loadCategoriesTab();
    else loadAppearanceTab();
}

document.getElementById('tabBusiness').addEventListener('click', () => setActiveSettingsTab('business'));
document.getElementById('tabAccount').addEventListener('click', () => setActiveSettingsTab('account'));
document.getElementById('tabCategories').addEventListener('click', () => setActiveSettingsTab('categories'));
document.getElementById('tabAppearance').addEventListener('click', () => setActiveSettingsTab('appearance'));

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ---------- Business Profile tab ----------
async function loadBusinessTab() {
    const container = document.getElementById('settingsContent');
    container.innerHTML = `<p class="text-center py-4" style="color: var(--dark-text-muted);">Loading...</p>`;

    try {
        const response = await fetch(`${API_URL}?action=get-business`);
        const data = await response.json();

        if (!data.success) {
            container.innerHTML = `<p class="text-danger">Failed to load settings.</p>`;
            return;
        }

        const s = data.settings;
        const disabledAttr = CAN_EDIT_BUSINESS ? '' : 'disabled';

        container.innerHTML = `
            <div class="panel settings-card">
                <div class="panel-body">
                    <form id="businessForm">
                        <div class="mb-3">
                            <label class="form-label small">Business Name</label>
                            <input type="text" class="form-control form-control-dark" id="businessName" value="${escapeHtml(s.business_name)}" ${disabledAttr} required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Address</label>
                            <input type="text" class="form-control form-control-dark" id="businessAddress" value="${escapeHtml(s.address || '')}" ${disabledAttr}>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Contact Number</label>
                            <input type="text" class="form-control form-control-dark" id="businessContact" value="${escapeHtml(s.contact_number || '')}" ${disabledAttr}>
                        </div>
                        <div id="businessError" class="alert alert-danger py-2 px-3 small d-none"></div>
                        ${CAN_EDIT_BUSINESS ? '<button type="submit" class="quick-action-btn primary">Save Changes</button>' : '<p class="small" style="color: var(--dark-text-muted);">Only admins can edit business info.</p>'}
                    </form>
                </div>
            </div>
        `;

        document.getElementById('businessForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const errorBox = document.getElementById('businessError');
            errorBox.classList.add('d-none');

            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: 'update-business',
                        business_name: document.getElementById('businessName').value,
                        address: document.getElementById('businessAddress').value,
                        contact_number: document.getElementById('businessContact').value,
                    }),
                });
                const result = await res.json();

                if (result.success) {
                    alert('Business settings saved.');
                } else {
                    errorBox.textContent = result.error || 'Something went wrong.';
                    errorBox.classList.remove('d-none');
                }
            } catch (err) {
                console.error('Save business settings failed:', err);
                errorBox.textContent = 'Could not reach the server.';
                errorBox.classList.remove('d-none');
            }
        });

    } catch (err) {
        console.error('Failed to load business tab:', err);
        container.innerHTML = `<p class="text-danger">Could not reach the server.</p>`;
    }
}

async function loadAccountTab() {
    const container = document.getElementById('settingsContent');

    container.innerHTML = `
        <div class="panel settings-card mb-4">
            <div class="panel-header"><h2 class="h6 mb-0">Change Password</h2></div>
            <div class="panel-body">
                <form id="passwordForm">
                    <div class="mb-3">
                        <label class="form-label small">Current Password</label>
                        <input type="password" class="form-control form-control-dark" id="currentPassword" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">New Password</label>
                        <input type="password" class="form-control form-control-dark" id="newPassword" minlength="8" required>
                    </div>
                    <div id="passwordError" class="alert alert-danger py-2 px-3 small d-none"></div>
                    <button type="submit" class="quick-action-btn primary">Update Password</button>
                </form>
            </div>
        </div>

        ${IS_OWNER ? `
        <div class="panel settings-card">
            <div class="panel-header"><h2 class="h6 mb-0">Change Security PIN</h2></div>
            <div class="panel-body">
                <form id="pinForm">
                    <div class="mb-3">
                        <label class="form-label small">Current PIN</label>
                        <input type="password" class="form-control form-control-dark" id="currentPin" maxlength="4" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">New PIN (4 digits)</label>
                        <input type="password" class="form-control form-control-dark" id="newPin" maxlength="4" required>
                    </div>
                    <div id="pinError" class="alert alert-danger py-2 px-3 small d-none"></div>
                    <button type="submit" class="quick-action-btn primary">Update PIN</button>
                </form>
            </div>
        </div>` : ''}
    `;

    document.getElementById('passwordForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const errorBox = document.getElementById('passwordError');
        errorBox.classList.add('d-none');

        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'change-password',
                    current_password: document.getElementById('currentPassword').value,
                    new_password: document.getElementById('newPassword').value,
                }),
            });
            const result = await res.json();

            if (result.success) {
                alert('Password updated successfully.');
                document.getElementById('passwordForm').reset();
            } else {
                errorBox.textContent = result.error || 'Something went wrong.';
                errorBox.classList.remove('d-none');
            }
        } catch (err) {
            console.error('Change password failed:', err);
            errorBox.textContent = 'Could not reach the server.';
            errorBox.classList.remove('d-none');
        }
    });

    if (IS_OWNER) {
        document.getElementById('pinForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const errorBox = document.getElementById('pinError');
            errorBox.classList.add('d-none');

            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: 'change-pin',
                        current_pin: document.getElementById('currentPin').value,
                        new_pin: document.getElementById('newPin').value,
                    }),
                });
                const result = await res.json();

                if (result.success) {
                    alert('PIN updated successfully.');
                    document.getElementById('pinForm').reset();
                } else {
                    errorBox.textContent = result.error || 'Something went wrong.';
                    errorBox.classList.remove('d-none');
                }
            } catch (err) {
                console.error('Change PIN failed:', err);
                errorBox.textContent = 'Could not reach the server.';
                errorBox.classList.remove('d-none');
            }
        });
    }
}

async function loadCategoriesTab() {
    const container = document.getElementById('settingsContent');
    container.innerHTML = `<p class="text-center py-4" style="color: var(--dark-text-muted);">Loading...</p>`;

    try {
        const response = await fetch(`${API_URL}?action=list-categories`);
        const data = await response.json();

        if (!data.success) {
            container.innerHTML = `<p class="text-danger">Failed to load categories.</p>`;
            return;
        }

        const rows = data.categories.map(cat => `
            <tr>
                <td>${escapeHtml(cat.name)}</td>
                <td>
                    <span class="badge-${cat.is_active == 1 ? 'in' : 'out'}">
                        ${cat.is_active == 1 ? 'Active' : 'Inactive'}
                    </span>
                </td>
                <td class="text-end">
                    <button class="table-action-btn" onclick="toggleCategory(${cat.id})">
                        ${cat.is_active == 1 ? 'Deactivate' : 'Activate'}
                    </button>
                </td>
            </tr>
        `).join('');

        container.innerHTML = `
            <div class="panel settings-card mb-4">
                <div class="panel-header"><h2 class="h6 mb-0">Add Category</h2></div>
                <div class="panel-body">
                    <form id="categoryForm" class="d-flex gap-2">
                        <input type="text" class="form-control form-control-dark" id="newCategoryName" placeholder="Category name" required>
                        <button type="submit" class="quick-action-btn primary">Add</button>
                    </form>
                    <div id="categoryError" class="alert alert-danger py-2 px-3 small mt-2 d-none"></div>
                </div>
            </div>

            <div class="panel">
                <div class="table-responsive">
                    <table class="table table-transactions mb-0">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </div>
        `;

        document.getElementById('categoryForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const errorBox = document.getElementById('categoryError');
            errorBox.classList.add('d-none');

            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: 'create-category',
                        name: document.getElementById('newCategoryName').value,
                    }),
                });
                const result = await res.json();

                if (result.success) {
                    loadCategoriesTab();
                } else {
                    errorBox.textContent = result.error || 'Something went wrong.';
                    errorBox.classList.remove('d-none');
                }
            } catch (err) {
                console.error('Create category failed:', err);
                errorBox.textContent = 'Could not reach the server.';
                errorBox.classList.remove('d-none');
            }
        });

    } catch (err) {
        console.error('Failed to load categories tab:', err);
        container.innerHTML = `<p class="text-danger">Could not reach the server.</p>`;
    }
}

async function toggleCategory(categoryId) {
    try {
        const res = await fetch(API_URL, {
            method: 'POST',
            body: new URLSearchParams({ action: 'toggle-category', category_id: categoryId }),
        });
        const result = await res.json();

        if (result.success) {
            loadCategoriesTab();
        } else {
            alert(result.error || 'Something went wrong.');
        }
    } catch (err) {
        console.error('Toggle category failed:', err);
        alert('Could not reach the server.');
    }
}

function loadAppearanceTab() {
    const container = document.getElementById('settingsContent');
    const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';

    container.innerHTML = `
        <section class="panel settings-card" aria-labelledby="appearanceHeading">
            <div class="panel-header">
                <div>
                    <h2 class="h6 mb-1" id="appearanceHeading">Appearance</h2>
                    <p class="small mb-0" style="color: var(--dark-text-muted);">Choose a consistent theme for every module, report, and modal on this browser.</p>
                </div>
            </div>
            <div class="panel-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <button type="button" class="appearance-choice ${currentTheme === 'light' ? 'active' : ''}" id="themeLightBtn" aria-pressed="${currentTheme === 'light'}">
                            <span class="appearance-choice-icon"><i class="bi bi-sun"></i></span>
                            <span>
                                <span class="d-block fw-semibold">Light</span>
                                <span class="d-block small" style="color: var(--dark-text-muted);">Bright surfaces with clear contrast.</span>
                            </span>
                        </button>
                    </div>
                    <div class="col-md-6">
                        <button type="button" class="appearance-choice ${currentTheme === 'dark' ? 'active' : ''}" id="themeDarkBtn" aria-pressed="${currentTheme === 'dark'}">
                            <span class="appearance-choice-icon"><i class="bi bi-moon-stars"></i></span>
                            <span>
                                <span class="d-block fw-semibold">Dark</span>
                                <span class="d-block small" style="color: var(--dark-text-muted);">Low-light surfaces across the app.</span>
                            </span>
                        </button>
                    </div>
                </div>
                <div class="small mt-3" style="color: var(--dark-text-muted);">
                    <i class="bi bi-info-circle me-1"></i> Your preference is saved in this browser and applied on all application pages.
                </div>
            </div>
        </section>
    `;

    document.getElementById('themeDarkBtn')?.addEventListener('click', () => {
        applyTheme('dark');
        loadAppearanceTab();
    });

    document.getElementById('themeLightBtn')?.addEventListener('click', () => {
        applyTheme('light');
        loadAppearanceTab();
    });
}

//-- journal entries navigation expandble
document.addEventListener('DOMContentLoaded', () => {
    const journalExpandable = document.getElementById('journalExpandable');
    const journalTrigger = document.getElementById('journalTrigger');

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
loadBusinessTab();