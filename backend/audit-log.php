<?php
declare(strict_types=1);

require_once __DIR__ . '/config/admin_guard.php';
$userName = $_SESSION['full_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log — KoboSocial</title>
    <script src="../frontend/js/theme.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../frontend/css/dashboard-style.css?v=mega-nav-1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>
<div class="app-shell">
    <div class="main-content">
        <div class="content-area">
            <?php require __DIR__ . '/includes/navbar.php'; ?>

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
                            <option value="CLOSE_ACCOUNTING_PERIOD">Closed accounting period</option>
                            <option value="REOPEN_ACCOUNTING_PERIOD">Reopened accounting period</option>
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
                        <input id="auditDateFrom" class="form-control form-control-dark" type="date" lang="en-US">
                    </div>
                    <div class="col-6 col-lg-1">
                        <label for="auditDateTo" class="form-label small">To</label>
                        <input id="auditDateTo" class="form-control form-control-dark" type="date" lang="en-US">
                    </div>
                    <div class="col-12 col-lg-2 d-flex align-items-center gap-2">
                        <button class="quick-action-btn" type="reset" id="clearAuditFilters" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></button>
                        <span class="small" style="color: var(--dark-text-muted);">Updates automatically</span>
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



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../frontend/js/audit-log.js"></script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>
