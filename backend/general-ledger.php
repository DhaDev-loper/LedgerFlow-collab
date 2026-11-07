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
<title>General Ledger — KoboSocial</title>
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

            <div class="mb-4">
                <h1 class="h4 mb-1">General Ledger</h1>
                <p class="small mb-0" style="color: var(--dark-text-muted);">Full transaction history and running balance for a single account.</p>
            </div>

            <div class="panel mb-4">
                <div class="panel-body compact">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-6">
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
                    </div>
                </div>
            </div>

            <div id="ledgerContent">
                <p class="text-center py-5" style="color: var(--dark-text-muted);">Choose an account or date range to view ledger activity.</p>
            </div>

        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../frontend/js/general-ledger.js"></script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>