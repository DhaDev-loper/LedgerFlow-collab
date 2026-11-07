<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';

header('Location: reports.php?report=cash-flow-analysis', true, 302);
exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cash Flow — KoboSocial</title>
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
                    <div class="cashflow-chart-container">
                        <canvas id="cfChart"></canvas>
                    </div>
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

</div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="../frontend/js/cashflow.js"></script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>