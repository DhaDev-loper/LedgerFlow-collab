<?php
declare(strict_types=1);

require_once __DIR__ . '/config/admin_guard.php';
$userName = $_SESSION['full_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log — WRS Accounting</title>
    <script src="../frontend/js/theme.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../frontend/css/dashboard-style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>
<div class="app-shell">
    <div class="main-content">
        <div class="content-area">
            <div class="d-flex justify-content-between align-items-center mb-4 pb-2">
                <div class="d-flex align-items-center">
                    <img src="../frontend/assets/Logo-Kobo-Light.png" alt="KoboSocial Logo" style="height: 42px; width: auto; object-fit: contain;">
                </div>
                <div class="d-flex align-items-center gap-2 px-3 py-1 rounded-pill" style="background: rgba(255, 255, 255, 0.04); border: 1px solid var(--dark-border, rgba(255, 255, 255, 0.08));">
                    <i class="bi bi-person-circle fs-5 text-gradient-neon"></i>
                    <span class="fw-semibold small text-light"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h1 class="h4 mb-1">Audit Log</h1>
                    <p class="small mb-0" style="color: var(--dark-text-muted);">Review recorded sign-ins and system changes.</p>
                </div>
                <a class="quick-action-btn" href="user-management.php"><i class="bi bi-arrow-left me-1"></i> User Management</a>
            </div>

            <div class="panel p-3 mb-3">
                <form id="auditFilters" class="row g-2 align-items-end">
                    <div class="col-12 col-lg-4">
                        <label for="auditSearch" class="form-label small">Search</label>
                        <input id="auditSearch" class="form-control form-control-dark" type="search" placeholder="Search by user or details">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label for="auditAction" class="form-label small">Action</label>
                        <select id="auditAction" class="form-select form-control-dark">
                            <option value="">All actions</option>
                            <option value="LOGIN_SUCCESS">Signed in</option>
                            <option value="CREATE_JOURNAL_ENTRY">Recorded journal entry</option>
                            <option value="EDIT_JOURNAL_ENTRY">Edited journal entry</option>
                            <option value="VOID_JOURNAL_ENTRY">Voided journal entry</option>
                            <option value="CREATE_ACCOUNT">Created account</option>
                            <option value="EDIT_ACCOUNT">Updated account</option>
                            <option value="TOGGLE_ACCOUNT_STATUS">Changed account status</option>
                            <option value="CREATE_CLIENT">Added client</option>
                            <option value="EDIT_CLIENT">Updated client</option>
                            <option value="TOGGLE_CLIENT_STATUS">Changed client status</option>
                            <option value="CREATE_USER">Added user</option>
                            <option value="EDIT_USER">Updated user</option>
                            <option value="TOGGLE_USER_STATUS">Changed user status</option>
                            <option value="DELETE_USER">Removed user</option>
                            <option value="RESTORE_USER">Restored user</option>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label for="auditEntity" class="form-label small">Entity</label>
                        <select id="auditEntity" class="form-select form-control-dark">
                            <option value="">All areas</option>
                            <option value="journal">Journal entries</option>
                            <option value="account">Accounts</option>
                            <option value="client">Clients</option>
                            <option value="user">Users and sign-ins</option>
                        </select>
                    </div>
                    <div class="col-6 col-lg-1">
                        <label for="auditDateFrom" class="form-label small">From</label>
                        <input id="auditDateFrom" class="form-control form-control-dark" type="date">
                    </div>
                    <div class="col-6 col-lg-1">
                        <label for="auditDateTo" class="form-label small">To</label>
                        <input id="auditDateTo" class="form-control form-control-dark" type="date">
                    </div>
                    <div class="col-12 col-lg-2 d-flex gap-2">
                        <button class="quick-action-btn primary flex-grow-1" type="submit"><i class="bi bi-search me-1"></i> Filter</button>
                        <button class="quick-action-btn" type="reset" id="clearAuditFilters" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></button>
                    </div>
                </form>
            </div>

            <div class="panel">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3 border-bottom" style="border-color: var(--dark-border) !important;">
                    <span id="auditResultCount" class="small" style="color: var(--dark-text-muted);">Loading audit events...</span>
                    <label class="small d-flex align-items-center gap-2">
                        Rows
                        <select id="auditPerPage" class="form-select form-select-sm form-control-dark" style="width: auto;">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </label>
                </div>
                <div class="table-responsive">
                    <table class="table table-transactions mb-0">
                        <thead>
                            <tr>
                                <th>Date / Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Area</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody id="auditTableBody">
                            <tr><td colspan="5" class="text-center py-4" style="color: var(--dark-text-muted);">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center p-3">
                    <button id="auditPrev" class="quick-action-btn" type="button"><i class="bi bi-chevron-left me-1"></i> Previous</button>
                    <span id="auditPageLabel" class="small" style="color: var(--dark-text-muted);"></span>
                    <button id="auditNext" class="quick-action-btn" type="button">Next <i class="bi bi-chevron-right ms-1"></i></button>
                </div>
            </div>
        </div>
    </div>
</div>

<nav class="bottom-nav">
    <a href="dashboard.php" class="bottom-nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
    <a href="transaction.php" class="bottom-nav-item"><i class="bi bi-arrow-left-right"></i><span>Transact</span></a>
    <a href="accounts.php" class="bottom-nav-item"><i class="bi bi-wallet2"></i><span>Accounts</span></a>
    <a href="clients.php" class="bottom-nav-item"><i class="bi bi-people-fill"></i><span>Clients</span></a>
    <div class="bottom-nav-expandable" id="journalExpandable">
        <button type="button" class="bottom-nav-item nav-trigger" id="journalTrigger"><i class="bi bi-journal-text"></i><span>Journal</span></button>
        <div class="expandable-content">
            <a href="journal-entries.php" class="sub-nav-item"><i class="bi bi-journal-check"></i><span>Entries</span></a>
            <a href="general-ledger.php" class="sub-nav-item"><i class="bi bi-book"></i><span>Ledger</span></a>
            <a href="cashflow.php" class="sub-nav-item"><i class="bi bi-cash-stack"></i><span>Cash Flow</span></a>
        </div>
    </div>
    <a href="user-management.php" class="bottom-nav-item active"><i class="bi bi-people"></i><span>Users</span></a>
    <a href="reports.php" class="bottom-nav-item"><i class="bi bi-bar-chart"></i><span>Reports</span></a>
    <a href="settings.php" class="bottom-nav-item"><i class="bi bi-gear"></i><span>Settings</span></a>
    <div class="bottom-nav-divider"></div>
    <a href="#" class="bottom-nav-item" id="logoutBtn"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../frontend/js/audit-log.js"></script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>
