<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';
require_once __DIR__ . '/config/accounting_periods.php';

if (!ACCOUNTING_PERIOD_LOCKS_ENABLED) {
    header('Location: dashboard.php');
    exit;
}

$userRole = (string) $_SESSION['role'];
if (!in_array($userRole, ['admin', 'owner'], true)) {
    http_response_code(403);
    die('<h2>Access Denied</h2><p>Accounting periods are restricted to administrators and owners.</p>');
}
$userName = $_SESSION['full_name'] ?? $userRole;
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Accounting Periods — KoboSocial</title>
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
                <h1 class="h4 mb-1">Accounting Periods</h1>
                <p class="small mb-0" style="color: var(--dark-text-muted);">Close finalized months or years to prevent changes to their journal entries.</p>
            </div>

            <div class="alert alert-warning" role="alert">
                Closing a period blocks new entries dated in it, edits, and void/deletion actions for its entries.
                Corrections should be new entries dated in an open period and linked to the original closed entry.
                Only the owner can reopen a period.
            </div>

            <div class="panel mb-4">
                <div class="panel-header"><h2 class="h6 mb-0">Close a Period</h2></div>
                <div class="panel-body">
                    <form id="closePeriodForm" class="row g-3 align-items-end">
                        <div class="col-sm-3">
                            <label for="periodType" class="form-label small">Period type</label>
                            <select class="form-select form-control-dark" id="periodType">
                                <option value="month">Month</option>
                                <option value="year">Year</option>
                            </select>
                        </div>
                        <div class="col-sm-3" id="monthValueGroup">
                            <label for="monthValue" class="form-label small">Month</label>
                            <input class="form-control form-control-dark" id="monthValue" type="month" required>
                        </div>
                        <div class="col-sm-3 d-none" id="yearValueGroup">
                            <label for="yearValue" class="form-label small">Year</label>
                            <input class="form-control form-control-dark" id="yearValue" type="number" min="1900" max="9999" step="1">
                        </div>
                        <div class="col-sm-3">
                            <label for="closeReason" class="form-label small">Reason</label>
                            <input class="form-control form-control-dark" id="closeReason" maxlength="2000" required placeholder="e.g. Monthly reports finalized">
                        </div>
                        <div class="col-sm-3">
                            <button class="quick-action-btn primary w-100" type="submit" id="closePeriodBtn"><i class="bi bi-lock-fill me-1"></i> Close Period</button>
                        </div>
                        <div class="col-12"><div class="small" id="periodFormMessage" role="status"></div></div>
                    </form>
                </div>
            </div>

            <div class="panel">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3 border-bottom" style="border-color: var(--dark-border) !important;">
                    <h2 class="h6 mb-0">Currently Closed Periods</h2>
                    <button class="quick-action-btn" type="button" id="refreshPeriodsBtn"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-transactions mb-0">
                        <thead>
                            <tr><th>Period</th><th>Closed by</th><th>Closed at</th><th>Reason</th><?php if ($userRole === 'owner'): ?><th>Action</th><?php endif; ?></tr>
                        </thead>
                        <tbody id="closedPeriodsBody">
                            <tr><td colspan="<?= $userRole === 'owner' ? 5 : 4 ?>" class="text-center py-4">Loading periods...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
window.ACCOUNTING_PERIOD_ROLE = <?= json_encode($userRole, JSON_THROW_ON_ERROR) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../frontend/js/accounting-periods.js"></script>
</body>
</html>
