<?php
declare(strict_types=1);

require_once __DIR__ . '/config/admin_guard.php';

$fullName = $_SESSION['full_name'];
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUserRole = $_SESSION['role'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — WRS Accounting</title>
    <script src="../frontend/js/theme.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../frontend/css/dashboard-style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        .avatar-initials {
            width: 32px;
            height: 32px;
            font-size: 0.8rem;
            font-weight: 700;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex-shrink: 0;
        }
        .avatar-owner { background: linear-gradient(135deg, #a100ff, #7928ca); }
        .avatar-admin { background: linear-gradient(135deg, #0d6efd, #00d2ff); }
        .avatar-accountant { background: linear-gradient(135deg, #0dcaf0, #008080); }
        .avatar-staff { background: linear-gradient(135deg, #6c757d, #495057); }

        .role-filter-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--dark-text-muted);
            transition: all 0.2s ease;
        }
        .role-filter-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .role-filter-btn.active {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.3);
            color: #fff;
            font-weight: 600;
        }

        .action-icon-btn {
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.6);
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.15s ease;
        }
        .action-icon-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .action-icon-btn.danger:hover {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        .action-icon-btn.warning:hover {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
    </style>
</head>
<body>

    <div class="app-shell">
        <div class="main-content">
            <div class="content-area">

                <!-- Header Row -->
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                    <div>
                        <h1 class="h4 mb-1">User Management</h1>
                        <p class="small mb-0" style="color: var(--dark-text-muted);">Manage staff accounts and security permissions.</p>
                    </div>
                    
                    <!-- Grouped Action Buttons -->
                    <div class="d-flex align-items-center gap-2">
                        <a href="audit-log.php" class="quick-action-btn" title="View system audit log">
                            <i class="bi bi-shield-check me-1"></i> Audit Log
                        </a>
                        <!-- Icon-Only Archive Button -->
                        <button type="button" 
                                onclick="openArchiveModal()" 
                                class="quick-action-btn"
                                title="Archived Accounts"
                                aria-label="Archived Accounts">
                            <i class="bi bi-archive-fill"></i>
                        </button>

                        <!-- Add User Button -->
                        <button class="quick-action-btn primary" data-bs-toggle="modal" data-bs-target="#userModal" id="addUserBtn">
                            <i class="bi bi-person-plus-fill me-1"></i> Add User
                        </button>
                    </div>
                </div>
                <!-- Access Control Info Banner -->
                <div class="panel p-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background: rgba(255, 255, 255, 0.02); border-left: 3px solid var(--bs-primary);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-2 rounded bg-primary-subtle text-primary">
                            <i class="bi bi-shield-lock-fill fs-5"></i>
                        </div>
                        <div>
                            <div class="fw-semibold text-white small">Role-Based Access Control</div>
                            <div class="small" style="color: var(--dark-text-muted); font-size: 0.8rem;">Owners and Admins can create staff, update access levels, and toggle account availability.</div>
                        </div>
                    </div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 small">
                        <i class="bi bi-check-circle-fill me-1"></i> RBAC Active
                    </span>
                </div>

                <!-- Filters & Search Controls Bar -->
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <div class="d-flex gap-2 flex-wrap" id="roleFilterContainer">
                        <button class="btn btn-sm role-filter-btn active" onclick="setRoleFilter('all')">
                            All <span class="badge rounded-pill bg-secondary ms-1" id="cntAll">0</span>
                        </button>
                        <button class="btn btn-sm role-filter-btn" onclick="setRoleFilter('owner')">
                            Owners <span class="badge rounded-pill bg-danger-subtle text-danger ms-1" id="cntOwner">0</span>
                        </button>
                        <button class="btn btn-sm role-filter-btn" onclick="setRoleFilter('admin')">
                            Admins <span class="badge rounded-pill bg-primary-subtle text-primary ms-1" id="cntAdmin">0</span>
                        </button>
                        <button class="btn btn-sm role-filter-btn" onclick="setRoleFilter('accountant')">
                            Accountants <span class="badge rounded-pill bg-info-subtle text-info ms-1" id="cntAccountant">0</span>
                        </button>
                        <button class="btn btn-sm role-filter-btn" onclick="setRoleFilter('staff')">
                            Staff <span class="badge rounded-pill bg-secondary-subtle text-secondary ms-1" id="cntStaff">0</span>
                        </button>
                    </div>
                   
                    <div class="position-relative" style="min-width: 240px;">
                        <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3" style="color: var(--dark-text-muted);"></i>
                        <input type="text" class="form-control form-control-sm ps-5 form-control-dark" id="userSearchInput" placeholder="Search by name or email...">
                    </div>
                </div>

                <!-- Table Panel -->
                <div class="panel">
                    <div class="table-responsive">
                        <table class="table table-transactions mb-0">
                            <thead>
                                <tr>
                                    <th>User</th>
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

    <nav class="bottom-nav">
            <a href="dashboard.php" class="bottom-nav-item">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
            <a href="transaction.php" class="bottom-nav-item">
                <i class="bi bi-arrow-left-right"></i>
                <span>Transact</span>
            </a>
            <a href="accounts.php" class="bottom-nav-item">
                <i class="bi bi-wallet2"></i>
                <span>Accounts</span>
            </a>
            <!-- Journal Expandable Nav Item -->
            <div class="bottom-nav-expandable" id="journalExpandable">
                <button type="button" class="bottom-nav-item nav-trigger" id="journalTrigger">
                    <i class="bi bi-journal-text"></i>
                    <span>Journal</span>
                </button>

                <div class="expandable-content">
                    <a href="journal-entries.php" class="sub-nav-item">
                        <i class="bi bi-journal-check"></i>
                        <span>Entries</span>
                    </a>
                    <a href="general-ledger.php" class="sub-nav-item">
                        <i class="bi bi-book"></i>
                        <span>Ledger</span>
                    </a>
                    <a href="cashflow.php" class="sub-nav-item">
                        <i class="bi bi-cash-stack"></i>
                        <span>Cash Flow</span>
                    </a>
                </div>
            </div>
            <a href="reports.php" class="bottom-nav-item">
                <i class="bi bi-bar-chart"></i>
                <span>Reports</span>
            </a>

            <?php if (in_array($_SESSION['role'], ['admin', 'owner'], true)): ?>
            <div class="bottom-nav-divider"></div>
            <a href="user-management.php" class="bottom-nav-item active">
                <i class="bi bi-people"></i>
                <span>Users</span>
            </a>
            <?php endif; ?>
             <div class="bottom-nav-divider"></div>
            <a href="settings.php" class="bottom-nav-item">
                <i class="bi bi-gear"></i>
                <span>Settings</span>
            </a>
            <div class="bottom-nav-divider"></div>
            <a href="#" class="bottom-nav-item" id="logoutBtn">
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>
        </nav>


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
                            <div class="form-text" style="color: var(--dark-text-muted);">Minimum 8 characters. Leave blank when editing to keep current password.</div>
                        </div>

                        
                        <div class="mb-3" id="roleContainer">
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
                    <p class="mb-0">Are you sure you want to delete <strong id="deleteUserName"></strong>? This account will be removed from active use, but historical records will be preserved.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
                </div>
            </div>
        </div>
    </div>

  <!-- Archived Accounts Modal -->
<div class="modal fade" id="archiveModal" tabindex="-1" aria-labelledby="archiveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="background-color: var(--dark-card-bg, #1e1b2e); color: #fff; border-radius: 1rem;">
            
            <!-- Modal Header -->
            <div class="modal-header border-bottom border-secondary border-opacity-25 px-4 py-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="p-2 rounded-3 bg-secondary bg-opacity-25 text-light">
                        <i class="bi bi-archive-fill"></i>
                    </div>
                    <div>
                        <h5 class="modal-title h6 mb-0 text-white font-weight-bold" id="archiveModalLabel">Archived User Accounts</h5>
                        <small class="text-muted" style="font-size: 0.75rem;">View and restore soft-deleted staff accounts.</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-4" style="max-height: 60vh; overflow-y: auto;">
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                        <thead class="text-uppercase text-muted border-bottom border-secondary border-opacity-25" style="font-size: 0.75rem; letter-spacing: 0.05em;">
                            <tr>
                                <th scope="col" class="pb-3 bg-transparent">User</th>
                                <th scope="col" class="pb-3 bg-transparent">Email</th>
                                <th scope="col" class="pb-3 bg-transparent">Role</th>
                                <th scope="col" class="pb-3 bg-transparent">Archived Date</th>
                                <th scope="col" class="pb-3 bg-transparent text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="archivedUsersTableBody">
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    Loading archived accounts...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer border-top border-secondary border-opacity-25 px-4 py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>

    <script>
        const CURRENT_USER_ID = <?php echo $currentUserId; ?>;
        const CURRENT_USER_ROLE = <?php echo json_encode($currentUserRole); ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../frontend/js/user-management.js"></script>
    <script src="../frontend/js/session-timeout.js"></script>
</body>
</html>