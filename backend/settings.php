<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';

$userRole = $_SESSION['role'];
$isOwner = $userRole === 'owner';
$canEditBusiness = in_array($userRole, ['admin', 'owner'], true);
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — KoboSocial</title>
<script src="../frontend/js/theme.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../frontend/css/dashboard-style.css?v=dashboard-direct-link-20261126">
</head>
<body>

<div class="app-shell">
    <div class="main-content">
        <div class="content-area">
            <?php require __DIR__ . '/includes/navbar.php'; ?>

            <div class="settings-page-heading">
               <span class="settings-page-heading-icon"><i class="bi bi-sliders"></i></span>
               <div>
                   <h1 class="h4 mb-1">Settings</h1>
                   <p class="small mb-0" style="color: var(--dark-text-muted);">Manage printed document details, your account, and appearance.</p>
               </div>
            </div>

            <div class="settings-tabs" role="tablist" aria-label="Settings sections">
               <button type="button" class="quick-action-btn primary" id="tabBusiness" role="tab" aria-selected="true"><i class="bi bi-building"></i> Document Details</button>
               <button type="button" class="quick-action-btn" id="tabAccount" role="tab" aria-selected="false"><i class="bi bi-person-gear"></i> My Account</button>
               <button type="button" class="quick-action-btn" id="tabAppearance" role="tab" aria-selected="false"><i class="bi bi-palette"></i> Appearance</button>
            </div>

            <div id="settingsContent" role="tabpanel" aria-live="polite"></div>

        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const IS_OWNER = <?= $isOwner ? 'true' : 'false' ?>;
    const CAN_EDIT_BUSINESS = <?= $canEditBusiness ? 'true' : 'false' ?>;
</script>
<script src="../frontend/js/settings.js?v=remove-categories-20261126"></script>
<script src="../frontend/js/session-timeout.js"></script>
</body>
</html>
