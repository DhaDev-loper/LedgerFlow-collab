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
<title>Reports — WRS Accounting</title>
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
            <a href="accounts.php" class="nav-link-custom">Accounts</a>
            <a href="journal-entries.php" class="nav-link-custom">Journal Entries</a>
            <a href="reports.php" class="nav-link-custom active">Reports</a>

            <?php if (in_array($userRole, ['admin', 'owner'], true)): ?>
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

           <div class="mb-4">
    <h1 class="h4 mb-1">Reports</h1>
             <p class="small mb-0" style="color: var(--dark-text-muted);">Income Statement and Balance Sheet, generated from your journal entries.</p>
            </div>

      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
             <div class="btn-group" role="group">
               <button type="button" class="quick-action-btn primary" id="tabIncomeStatement">Income Statement</button>
               <button type="button" class="quick-action-btn" id="tabBalanceSheet">Balance Sheet</button>
            </div>

            <div class="d-flex align-items-end gap-2">
                   <div id="dateRangeControls" class="d-flex gap-2 align-items-end">
                         <div>
                               <label class="form-label small mb-1">From</label>
                               <input type="date" class="form-control form-control-dark" id="dateFrom">
                           </div>
                          <div>
                                <label class="form-label small mb-1">To</label>
                               <input type="date" class="form-control form-control-dark" id="dateTo">
                          </div>
                    </div>
                    <div id="asOfControl" class="d-none">
                            <label class="form-label small mb-1">As of</label>
                           <input type="date" class="form-control form-control-dark" id="asOfDate">
                     </div>
             <button class="quick-action-btn" id="generateReportBtn">Generate</button>
            </div>
      </div>

<div id="reportContent">
    <p class="text-center py-5" style="color: var(--dark-text-muted);">Select a report and click Generate.</p>
</div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../frontend/js/reports.js"></script>
</body>
</html>