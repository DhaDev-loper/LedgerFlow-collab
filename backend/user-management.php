<?php
declare(strict_types=1);

require_once __DIR__ . '/config/admin_guard.php';

$fullName = $_SESSION['full_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management — WRS Accounting</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../frontend/css/dashboard-style.css">
</head>
<body>

<div class="app-shell">

    <aside class="sidebar">
        <div class="brand">
            <span class="droplet-mark"></span>
            <span class="fs-6">LedgerFlow</span>
        </div>

        <nav>
            <div class="nav-section-label">Overview</div>
            <a href="dashboard.php" class="nav-link-custom">Dashboard</a>

            <div class="nav-section-label">Management</div>
            <a href="accounts.php" class="nav-link-custom">Accounts</a>
            <a href="journal-entries.php" class="nav-link-custom">Journal Entries</a>
            <a href="inventory.php" class="nav-link-custom">Inventory</a>
            <a href="#" class="nav-link-custom">Customers</a>
            <a href="#" class="nav-link-custom">Dispenser Rentals</a>
            <a href="reports.php" class="nav-link-custom">Reports</a>

            <div class="nav-section-label">Admin</div>
            <a href="user-management.php" class="nav-link-custom active">User Management</a>

            <div class="nav-section-label">Settings</div>
            <a href="#" class="nav-link-custom">Settings</a>
            <a href="#" class="nav-link-custom" id="logoutBtn">Logout</a>
        </nav>
    </aside>

    <div class="main-content">
        <div class="content-area">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h1 class="h4 mb-1">User Management</h1>
                    <p class="small mb-0" style="color: var(--dark-text-muted);">Manage staff accounts and access levels.</p>
                </div>
                <button class="quick-action-btn primary" data-bs-toggle="modal" data-bs-target="#userModal" id="addUserBtn">
                    + Add User
                </button>
            </div>

            <div class="panel">
                <div class="table-responsive">
                    <table class="table table-transactions mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <tr><td colspan="6" class="text-center py-4" style="color: var(--dark-text-muted);">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Add / Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">Add User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="userForm">
                <div class="modal-body">
                    <input type="hidden" id="userId" name="user_id">

                    <div class="mb-3">
                        <label class="form-label small">Full Name</label>
                        <input type="text" class="form-control form-control-dark" id="fullName" name="full_name" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Email</label>
                        <input type="email" class="form-control form-control-dark" id="email" name="email" required>
                    </div>

                    <div class="mb-3" id="passwordGroup">
                        <label class="form-label small">Password</label>
                        <input type="text" class="form-control form-control-dark" id="password" name="password">
                        <div class="form-text" style="color: var(--dark-text-muted);">Minimum 8 characters. Leave blank when editing to keep the current password.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Role</label>
                        <select class="form-select form-control-dark" id="role" name="role">
                            <option value="staff">Staff</option>
                            <option value="accountant">Accountant</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>

                    <div id="formError" class="alert alert-danger py-2 px-3 small d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn quick-action-btn primary" id="saveUserBtn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title">Delete User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to delete <strong id="deleteUserName"></strong>? This account will be removed from active use, but their historical records will be preserved.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../frontend/js/user-management.js"></script>
</body>
</html>