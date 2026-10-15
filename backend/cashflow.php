<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';

$userRole = $_SESSION['role'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cash Flow — WRS Accounting</title>
<script src="../frontend/js/theme.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../frontend/css/dashboard-style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>

<div class="app-shell">
    <div class="main-content">
        <div class="content-area">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h1 class="h4 mb-1">Cash Flow</h1>
                    <p class="small mb-0" style="color: var(--dark-text-muted);">Money in and out, broken down by category, over any date range.</p>
                </div>
                <div class="d-flex gap-2 align-items-end">
                    <div>
                        <label class="form-label small mb-1">From</label>
                        <input type="date" class="form-control form-control-dark" id="cfDateFrom">
                    </div>
                    <div>
                        <label class="form-label small mb-1">To</label>
                        <input type="date" class="form-control form-control-dark" id="cfDateTo">
                    </div>
                    <button class="quick-action-btn primary" id="cfGenerateBtn">Generate</button>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-4">
                    <div class="kpi-card">
                        <div class="kpi-label">Total Inflow</div>
                        <div class="kpi-value money positive" id="cfTotalInflow">₱0.00</div>
                    </div>
                </div>
                <div class="col-6 col-lg-4">
                    <div class="kpi-card">
                        <div class="kpi-label">Total Outflow</div>
                        <div class="kpi-value money negative" id="cfTotalOutflow">₱0.00</div>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="kpi-card">
                        <div class="kpi-label">Net Cash Flow</div>
                        <div class="kpi-value money" id="cfNetTotal">₱0.00</div>
                    </div>
                </div>
            </div>

            <div class="panel mb-4">
                <div class="panel-header">
                    <h2 class="h6 mb-0">Cash Flow Trend</h2>
                </div>
                <div class="panel-body">
                    <canvas id="cfChart" height="90"></canvas>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="panel">
                        <div class="panel-header"><h2 class="h6 mb-0">Inflow by Category</h2></div>
                        <div class="panel-body compact" id="cfInflowBreakdown">
                            <p class="text-center py-3" style="color: var(--dark-text-muted);">No data.</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="panel">
                        <div class="panel-header"><h2 class="h6 mb-0">Outflow by Category</h2></div>
                        <div class="panel-body compact" id="cfOutflowBreakdown">
                            <p class="text-center py-3" style="color: var(--dark-text-muted);">No data.</p>
                        </div>
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
        <div class="dropup">
            <a href="#" class="bottom-nav-item active" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-journal-text"></i>
                <span>Journal</span>
            </a>
            <ul class="dropdown-menu dropdown-menu-dark">
                <li><a class="dropdown-item" href="journal-entries.php">Journal Entries</a></li>
                <li><a class="dropdown-item" href="general-ledger.php">General Ledger</a></li>
                <li><a class="dropdown-item" href="cashflow.php">Cash Flow</a></li>
            </ul>
        </div>
        <a href="reports.php" class="bottom-nav-item">
            <i class="bi bi-bar-chart"></i>
            <span>Reports</span>
        </a>
        <?php if (in_array($userRole, ['admin', 'owner'], true)): ?>
        <div class="bottom-nav-divider"></div>
        <a href="user-management.php" class="bottom-nav-item">
            <i class="bi bi-people"></i>
            <span>Users</span>
        </a>
        <?php endif; ?>
        <div class="bottom-nav-divider"></div>
        <a href="settings.php" class="bottom-nav-item">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
        </a>
        <a href="#" class="bottom-nav-item" id="logoutBtn">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </nav>
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
                    <div class="bottom-nav-expandable active" id="journalExpandable">
                        <button type="button" class="bottom-nav-item nav-trigger" id="journalTrigger">
                            <i class="bi bi-journal-text"></i>
                            <span>Journal</span>
                        </button>

                        <div class="expandable-content">
                            <a href="journal-entries.php" class="sub-nav-item active">
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
                    <a href="user-management.php" class="bottom-nav-item">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="../frontend/js/cashflow.js"></script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>