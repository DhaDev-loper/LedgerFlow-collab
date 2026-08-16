<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';

$userRole = $_SESSION['role'];
$canManage = in_array($userRole, ['admin', 'accountant'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chart of Accounts — WRS Accounting</title>
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
            <a href="transaction.php" class="nav-link-custom">Transactions</a>
            <a href="accounts.php" class="nav-link-custom active">Accounts</a>
            <a href="journal-entries.php" class="nav-link-custom">Journal Entries</a>
    
            <a href="reports.php" class="nav-link-custom">Reports</a>

            <?php if (in_array($_SESSION['role'], ['admin', 'owner'], true)): ?>
            <div class="nav-section-label">Admin</div>
            <a href="user-management.php" class="nav-link-custom">User Management</a>
            <?php endif; ?>

            <div class="nav-section-label">Settings</div>
            <a href="#" class="nav-link-custom">Settings</a>
            <a href="#" class="nav-link-custom" id="logoutBtn">Logout</a>
        </nav>
    </aside>

    <div class="main-content">
        <div class="content-area">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h1 class="h4 mb-1">Chart of Accounts</h1>
                    <p class="small mb-0" style="color: var(--dark-text-muted);">Every asset, revenue, and expense account your business tracks.</p>
                </div>
                <?php if ($canManage): ?>
                <button class="quick-action-btn primary" data-bs-toggle="modal" data-bs-target="#accountModal" id="addAccountBtn">
                    + Add Account
                </button>
                <?php endif; ?>
            </div>

            <!-- Summary cards, grouped by type -->
            <div class="row g-3 mb-4" id="summaryCards">
                <!-- injected by accounts.js -->
            </div>

            <div class="panel">
                <div class="table-responsive">
                    <table class="table table-transactions mb-0">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Type</th>
                                <th class="text-end">Balance</th>
                                <th>Status</th>
                                <?php if ($canManage): ?><th class="text-end">Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="accountsTableBody">
                            <tr><td colspan="6" class="text-center py-4" style="color: var(--dark-text-muted);">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<?php if ($canManage): ?>
<!-- Add / Edit Account Modal -->
<div class="modal fade" id="accountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title" id="accountModalTitle">Add Account</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="accountForm">
                <div class="modal-body">
                    <input type="hidden" id="accountId" name="account_id">

                    <div class="mb-3">
                        <label class="form-label small">Account Name</label>
                        <input type="text" class="form-control form-control-dark" id="accountName" name="name" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Account Type</label>
                        <select class="form-select form-control-dark" id="accountTypeId" name="account_type_id" required>
                            <!-- injected by accounts.js -->
                        </select>
                    </div>


                    <div class="mb-3" id="openingBalanceGroup">
                        <label class="form-label small">Opening Balance</label>
                        <input type="number" step="0.01" class="form-control form-control-dark" id="openingBalance" name="opening_balance" value="0.00">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Notes (optional)</label>
                        <textarea class="form-control form-control-dark" id="accountNotes" name="notes" rows="2"></textarea>
                    </div>

                    <div id="accountFormError" class="alert alert-danger py-2 px-3 small d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn quick-action-btn primary" id="saveAccountBtn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Let the frontend know whether this user can manage accounts,
    // without duplicating the role-check logic in JavaScript.
    const CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
</script>
<script src="../frontend/js/accounts.js"></script>
</body>
</html>