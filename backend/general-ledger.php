<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';

$userRole = $_SESSION['role'];
$userName = $_SESSION['full_name'] ?? $_SESSION['role'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>General Ledger — WRS Accounting</title>
<script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('theme') || 'dark');
</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../frontend/css/dashboard-style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>

<div class="app-shell">
    <div class="main-content">
        <div class="content-area">
            <!-- Global Top Navigation Bar -->
            <div class="d-flex justify-content-between align-items-center mb-4 pb-2">
                <!-- Top-Left: Logo -->
                <div class="d-flex align-items-center">
                    <img src="../frontend/assets/Logo-Kobo-Light.png" alt="KoboSocial Logo" style="height: 42px; width: auto; object-fit: contain;">
                </div>

                <!-- Top-Right: User Profile -->
                <div class="d-flex align-items-center gap-2 px-3 py-1.5 rounded-pill" style="background: rgba(255, 255, 255, 0.04); border: 1px solid var(--dark-border, rgba(255, 255, 255, 0.08));">
                    <i class="bi bi-person-circle fs-5 text-gradient-neon"></i>
                    <span class="fw-semibold small text-light"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
            <div class="mb-4">
                <h1 class="h4 mb-1">General Ledger</h1>
                <p class="small mb-0" style="color: var(--dark-text-muted);">Full transaction history and running balance for a single account.</p>
            </div>

            <div class="panel mb-4">
                <div class="panel-body compact">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Account</label>
                            <select class="form-select form-control-dark" id="ledgerAccountId"></select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">From</label>
                            <input type="date" class="filter-input" id="ledgerDateFrom">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">To</label>
                            <input type="date" class="filter-input" id="ledgerDateTo">
                        </div>
                        <div class="col-md-2">
                            <button class="quick-action-btn primary w-100" id="loadLedgerBtn">View Ledger</button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="ledgerContent">
                <p class="text-center py-5" style="color: var(--dark-text-muted);">Select an account and click View Ledger.</p>
            </div>

        </div>
    </div>

           <nav class="bottom-nav">
    <!-- LEFT SIDE (4 items) -->
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
    <a href="clients.php" class="bottom-nav-item">
        <i class="bi bi-people-fill"></i>
        <span>Clients</span>
    </a>

    <!-- DEAD-CENTER FLOATING SCANNER BUTTON -->
    <button type="button" class="bottom-nav-scan-btn" id="triggerScanModalBtn" data-bs-toggle="modal" data-bs-target="#receiptScannerModal" title="Scan / Import Receipt">
        <div class="scan-btn-icon">
            <i class="bi bi-qr-code-scan"></i>
        </div>
        <span>Scan</span>
    </button>
    
    <!-- RIGHT SIDE (4 items balanced) -->
    <div class="bottom-nav-expandable active" id="journalExpandable">
        <button type="button" class="bottom-nav-item nav-trigger" id="journalTrigger">
            <i class="bi bi-journal-text"></i>
            <span>Journal</span>
        </button>

        <div class="expandable-content active">
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
     <?php if (in_array($_SESSION['role'], ['admin', 'owner'], true)): ?>
            <a href="user-management.php" class="bottom-nav-item ">
                <i class="bi bi-people"></i>
                <span>Users</span>
            </a>
     <?php endif; ?>
    <a href="reports.php" class="bottom-nav-item">
        <i class="bi bi-bar-chart"></i>
        <span>Reports</span>
    </a>

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
<script src="../frontend/js/general-ledger.js"></script>
</body>
</html>