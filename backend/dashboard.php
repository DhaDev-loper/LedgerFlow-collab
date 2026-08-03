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

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="brand">
            <span class="droplet-mark"></span>
            <span class="fs-6">LedgerFlow</span>
        </div>

        <nav>
            <div class="nav-section-label">Overview</div>
            <a href="dashboard.php" class="nav-link-custom active">Dashboard</a>

            <div class="nav-section-label">Management</div>
            <a href="accounts.php" class="nav-link-custom">Accounts</a>
            <a href="journal-entries.php" class="nav-link-custom">Journal Entries</a>
            <a href="inventory.php" class="nav-link-custom">Inventory</a>
            <a href="#" class="nav-link-custom">Customers</a>
            <a href="#" class="nav-link-custom">Dispenser Rentals</a>
            <a href="reports.php" class="nav-link-custom">Reports</a>

            <?php if ($_SESSION['role'] === 'admin'): ?>
            <div class="nav-section-label">Admin</div>
            <a href="/backend/user-management.php" class="nav-link-custom">User Management</a>
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
                    <h1 class="mb-0">Good evening, <span style="color: var(--accent-blue);">Admin</span> 👋</h1>
                    <p class="text-secondary small mb-0" style="color: var(--dark-text-muted) !important;">Here's a quick look at your system management today.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button class="quick-action-btn primary">+ New Transaction</button>
                    <button class="quick-action-btn">Customer</button>
                    <button class="quick-action-btn">Inventory</button>
                    <button class="quick-action-btn">Expense</button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-label">Net Worth</div>
                        <div class="kpi-value money">₱128,450.00</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-label">Cash In (this month)</div>
                        <div class="kpi-value money positive">+₱42,300.00</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-label">Cash Out (this month)</div>
                        <div class="kpi-value money negative">-₱18,720.00</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="kpi-card">
                        <div class="kpi-label">Net Cash Flow</div>
                        <div class="kpi-value money positive">+₱23,580.00</div>
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
                            <span class="date-pill">Jul 1 – Jul 29, 2026</span>
                        </div>
                        <div class="panel-body">
                            <canvas id="cashflowChart" height="90"></canvas>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="h6 mb-0">Recent Transactions</h2>
                            <a href="journal-entries.php" class="small text-decoration-none" style="color: var(--accent-blue);">View all</a>
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
                <div class="col-lg-4 d-flex flex-column gap-4">

                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="h6 mb-0">Today's Sales</h2>
                        </div>
                        <div class="panel-body compact">
                            <div class="d-flex gap-2 mb-3">
                                <div class="stat-mini">
                                    <div class="stat-mini-icon">₱</div>
                                    <div class="stat-mini-label">Walk-in Sales</div>
                                    <div class="stat-mini-value">₱1,240.00</div>
                                </div>
                                <div class="stat-mini">
                                    <div class="stat-mini-icon">↑</div>
                                    <div class="stat-mini-label">Growth vs Yesterday</div>
                                    <div class="stat-mini-value" style="color:#4ADE80;">+12%</div>
                                </div>
                            </div>
                            <canvas id="miniSalesChart" height="110"></canvas>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="h6 mb-0">Accounts</h2>
                            <a href="accounts.php" class="small text-decoration-none" style="color: var(--accent-blue);">Manage</a>
                        </div>
                        <div class="panel-body compact">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <div class="fw-semibold small">Cash Drawer</div>
                                    <div class="small" style="color: var(--dark-text-muted);">Asset</div>
                                </div>
                                <div class="money fw-semibold">₱8,240.00</div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <div class="fw-semibold small">BDO Business Checking</div>
                                    <div class="small" style="color: var(--dark-text-muted);">Asset</div>
                                </div>
                                <div class="money fw-semibold">₱96,110.00</div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <div class="fw-semibold small">GCash Business</div>
                                    <div class="small" style="color: var(--dark-text-muted);">Asset</div>
                                </div>
                                <div class="money fw-semibold">₱24,100.00</div>
                            </div>
                            <hr style="border-color: var(--dark-border);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="fw-semibold small">Total Assets</div>
                                <div class="money fw-semibold">₱128,450.00</div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="../frontend/js/dashboard.js"></script>
</body>
</html>