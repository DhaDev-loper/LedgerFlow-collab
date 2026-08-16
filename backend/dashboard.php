<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — WRS Accounting</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
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
            <a href="dashboard.php" class="nav-link-custom active">Dashboard</a>

            <div class="nav-section-label">Management</div>
            <a href="transaction.php" class="nav-link-custom">Transactions</a>
            <a href="accounts.php" class="nav-link-custom">Accounts</a>
            <a href="journal-entries.php" class="nav-link-custom">Journal Entries</a>

            <a href="reports.php" class="nav-link-custom">Reports</a>

            <?php if (in_array($_SESSION['role'], ['admin', 'owner'], true)): ?>
            <div class="nav-section-label">Admin</div>
            <a href="../backend/user-management.php" class="nav-link-custom">User Management</a>
            <?php endif; ?>

            <div class="nav-section-label">Settings</div>
            <a href="#" class="nav-link-custom">Settings</a>
            <a href="#" class="nav-link-custom" id="logoutBtn">Logout</a>
        </nav>
    </aside>

    <!-- Main content -->
    <div class="main-content">
        <div class="content-area">

            <!-- Greeting banner -->
            <div class="greeting-banner d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h1 class="mb-0">Good day, <span style="color: var(--accent-blue);">Admin</span> 👋</h1>
                    <p class="text-secondary small mb-0" style="color: var(--dark-text-muted) !important;">Here's a quick look at your system management today.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button class="quick-action-btn primary" data-bs-toggle="modal" data-bs-target="#quickSaleModal">+ New Transaction</button>
                    <button class="quick-action-btn" data-bs-toggle="modal" data-bs-target="#quickExpenseModal">Expense</button>
                  
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-label">Total Assets</div>
                        <div class="kpi-value money" id="kpiTotalAssets">₱0.00</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-label">Cash Inflow (this month)</div>
                        <div class="kpi-value money positive" id="kpiCashInflow">₱0.00</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-label">Cash Outflow (this month)</div>
                        <div class="kpi-value money negative" id="kpiCashOutflow">₱0.00</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-label">Net Cash Flow</div>
                        <div class="kpi-value money" id="kpiNetCashFlow">₱0.00</div>
                    </div>
                </div>
            </div>

            <!-- Main two-column layout -->
            <div class="row g-4">

                <!-- Left: chart + recent transactions -->
                <div class="col-lg-8 d-flex flex-column gap-4">

                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="h6 mb-0">Cash Flow</h2>
                            
                        </div>
                        <div class="panel-body">
                            <canvas id="cashflowChart" height="90"></canvas>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="h6 mb-0">Recent Transactions</h2>
                            <a href="backend/journal-entries.php" class="small text-decoration-none" style="color: var(--accent-blue);">View all</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-transactions mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:40px;"></th>
                                        <th>Description</th>
                                        <th>Category</th>
                                        <th>Date</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody id="recentTransactionsBody">
                                    <!-- rows injected by dashboard.js -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <!-- Right: stacked stat widgets -->
                <div class=" panel col-lg-4 d-flex flex-column gap-4">
                    <div class="panel-header d-flex justify-content-between align-items-center">
                         <h2 class="h6 mb-0">Accounts</h2>
                  <a href="../backend/accounts.php" class="small text-decoration-none" style="color: var(--accent-blue);">View all</a>
                     </div>
                  <div class="panel-body compact">
                        
                        <div id="dashboardAccountsList">
                            <p class="text-center py-3" style="color: var(--dark-text-muted);">Loading...</p>
                        </div>
                        <hr style="border-color: var(--dark-border);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="fw-semibold small">Total Assets</div>
                            <div class="money fw-semibold" id="dashboardTotalAssets">₱0.00</div>
                        </div>
                    </div>

                  
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Quick Sale Modal -->
<div class="modal fade" id="quickSaleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title">Record a Sale</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="quickSaleForm">
                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label small">Amount</label>
                        <input type="number" step="0.01" min="0.01" class="form-control form-control-dark" id="saleAmount" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Received Into</label>
                        <select class="form-select form-control-dark" id="saleReceivedInto" required></select>
                        <div class="form-text" style="color: var(--dark-text-muted);">Which account the money landed in (e.g. Cash Drawer)</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Revenue Type</label>
                        <select class="form-select form-control-dark" id="saleRevenueAccount" required></select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Description (optional)</label>
                        <input type="text" class="form-control form-control-dark" id="saleDescription" placeholder="e.g. Walk-in sale">
                    </div>

                    <div id="quickSaleError" class="alert alert-danger py-2 px-3 small d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn quick-action-btn primary" id="saveQuickSaleBtn">Record Sale</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Expense Modal -->
<div class="modal fade" id="quickExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title">Record an Expense</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="quickExpenseForm">
                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label small">Amount</label>
                        <input type="number" step="0.01" min="0.01" class="form-control form-control-dark" id="expenseAmount" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Paid From</label>
                        <select class="form-select form-control-dark" id="expensePaidFrom" required></select>
                        <div class="form-text" style="color: var(--dark-text-muted);">Which account the money came out of</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Expense Type</label>
                        <select class="form-select form-control-dark" id="expenseAccount" required></select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Description (optional)</label>
                        <input type="text" class="form-control form-control-dark" id="expenseDescription" placeholder="e.g. Electricity bill">
                    </div>

                    <div id="quickExpenseError" class="alert alert-danger py-2 px-3 small d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn quick-action-btn primary" id="saveQuickExpenseBtn">Record Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="../frontend/js/dashboard.js"></script>
</body>
</html>