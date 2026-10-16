<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';

$userRole = $_SESSION['role'];
$isOwner = $userRole === 'owner';
$canEditBusiness = in_array($userRole, ['admin', 'owner'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — WRS Accounting</title>
<script src="../frontend/js/theme.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="../frontend/css/dashboard-style.css">
</head>
<body>

<div class="app-shell">
    <div class="main-content">
        <div class="content-area">

            <div class="settings-page-heading">
               <span class="settings-page-heading-icon"><i class="bi bi-sliders"></i></span>
               <div>
                   <h1 class="h4 mb-1">Settings</h1>
                   <p class="small mb-0" style="color: var(--dark-text-muted);">Manage your business profile, account, categories, and appearance.</p>
               </div>
            </div>

            <div class="settings-tabs" role="tablist" aria-label="Settings sections">
               <button type="button" class="quick-action-btn primary" id="tabBusiness" role="tab" aria-selected="true"><i class="bi bi-building"></i> Business Profile</button>
               <button type="button" class="quick-action-btn" id="tabAccount" role="tab" aria-selected="false"><i class="bi bi-person-gear"></i> My Account</button>
               <button type="button" class="quick-action-btn" id="tabCategories" role="tab" aria-selected="false"><i class="bi bi-tags"></i> Categories</button>
               <button type="button" class="quick-action-btn" id="tabAppearance" role="tab" aria-selected="false"><i class="bi bi-palette"></i> Appearance</button>
            </div>

            <div id="settingsContent" role="tabpanel" aria-live="polite"></div>

        </div>
    </div>

   <nav class="bottom-nav">
    <!-- LEFT SIDE (4 items) -->
    <a href="dashboard.php" class="bottom-nav-item">
        <i class="bi bi-speedometer2"></i>
        <span>Dashboard</span>
    </a>
    <a href="transaction.php" class="bottom-nav-item ">
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

    
    <!-- RIGHT SIDE (4 items balanced) -->
    <div class="bottom-nav-expandable " id="journalExpandable">
        <button type="button" class="bottom-nav-item nav-trigger" id="journalTrigger">
            <i class="bi bi-journal-text"></i>
            <span>Journal</span>
        </button>

        <div class="expandable-content active">
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

    <a href="settings.php" class="bottom-nav-item active">
        <i class="bi bi-gear"></i>
        <span>Settings</span>
    </a>

    <div class="bottom-nav-divider"></div>
    <a href="#" class="bottom-nav-item" id="logoutBtn">
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>
</nav>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const IS_OWNER = <?= $isOwner ? 'true' : 'false' ?>;
    const CAN_EDIT_BUSINESS = <?= $canEditBusiness ? 'true' : 'false' ?>;
</script>
<script src="../frontend/js/settings.js"></script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>