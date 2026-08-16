<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';

$userRole = $_SESSION['role'];
$canCreate = in_array($userRole, ['admin', 'accountant'], true);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Journal Entries — WRS Accounting</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../frontend/css/dashboard-style.css">
</head>
<body>

<div class="app-shell">
     <!-- NAVIGATION SIDE BAR -->
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
            <a href="accounts.php" class="nav-link-custom">Accounts</a>
            <a href="journal-entries.php" class="nav-link-custom active">Journal Entries</a>
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
                    <h1 class="h4 mb-1">Journal Entries</h1>
                    <p class="small mb-0" style="color: var(--dark-text-muted);">Every recorded transaction, balanced by debit and credit.</p>
                </div>
                <?php if ($canCreate): ?>
                <button class="quick-action-btn primary" data-bs-toggle="modal" data-bs-target="#entryModal" id="newEntryBtn">
                    + New Entry
                </button>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="table-responsive">
                    <table class="table table-transactions mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Type</th>
                                <th>Reference</th>
                                <th>Recorded By</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody id="journalTableBody">
                            <tr><td colspan="6" class="text-center py-4" style="color: var(--dark-text-muted);">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<?php if ($canCreate): ?>
<!-- NEW ENTRY MODAL -->

<div class="modal fade" id="entryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content modal-dark">
            <div class="modal-header">

                <!-- HEADER - New Journal Entry -->
                <h5 class="modal-title">New Journal Entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="entryForm">
                <div class="modal-body">
                <!-- FIRST ROW - Transaction Type and Date -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small">Transaction Type</label>
                            <select class="form-select form-control-dark" id="transactionTypeId" name="transaction_type_id" required>
                                <!-- injected by journal-entries.js -->
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Date</label>
                            <input type="date" class="form-control form-control-dark" id="transactionDate" name="transaction_date" required>
                        </div>
                    </div>
                <!-- SECOND ROW - Description, Reference No., and Category -->
                    <div class="mb-3">
                        <label class="form-label small">Description</label>
                        <input type="text" class="form-control form-control-dark" id="description" name="description" placeholder="e.g. Walk-in sale, Electricity bill" required>
                    </div>
                <!-- THIRD ROW - From Account, To Account, and Amount -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small">Reference No. (optional)</label>
                            <input type="text" class="form-control form-control-dark" id="referenceNo" name="reference_no">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Category (optional)</label>
                            <select class="form-select form-control-dark" id="categoryId" name="category_id">
                                <option value="">-- None --</option>
                                
                            </select>
                        </div>
                    </div>

                    <hr style="border-color: var(--dark-border);">
                    <!-- FOURTH ROW - From Account(DEBIT)), To Account(CREDIT), and Amount -->
                        <div class="row g-3 mb-3">
                        <div class="col-md-5">
                              <label class="form-label small">DEBIT:</label>
                              <select class="form-select form-control-dark" id="fromAccountId" name="from_account_id" required></select>
                              <div class="form-text" style="color: var(--dark-text-muted);">Where the money is coming from</div>
                        </div>
                        <div class="col-md-5">
                              <label class="form-label small">CREDIT:</label>
                              <select class="form-select form-control-dark" id="toAccountId" name="to_account_id" required></select>
                              <div class="form-text" style="color: var(--dark-text-muted);">Where the money is going to</div>
                        </div>
                        <div class="col-md-2">
                              <label class="form-label small">Amount</label>
                              <input type="number" step="0.01" min="0.01" class="form-control form-control-dark" id="amount" name="amount" placeholder="0.00" required>
                        </div>
                        </div>

                   <div id="entryFormError" class="alert alert-danger py-2 px-3 small mt-3 d-none"></div>

                    <!-- THE BUTTONS -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn quick-action-btn primary" id="saveEntryBtn">Save Entry</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Journal Entry Detail Modal -->
<div class="modal fade" id="viewEntryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title">Entry Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewEntryBody">
                <p class="text-center py-4" style="color: var(--dark-text-muted);">Loading...</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const CAN_CREATE = <?= $canCreate ? 'true' : 'false' ?>;
</script>
<script src="../frontend/js/journal-entries.js"></script>
</body>
</html>