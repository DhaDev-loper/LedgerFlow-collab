<?php
declare(strict_types=1);

$navigationRole = (string) ($_SESSION['role'] ?? 'staff');
$navigationName = (string) ($_SESSION['full_name'] ?? $navigationRole);
$navigationPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$navigationIsAdmin = in_array($navigationRole, ['admin', 'owner'], true);
$navigationGroups = [
    [
        'label' => 'Overview',
        'icon' => 'bi-speedometer2',
        'links' => [
            ['label' => 'Dashboard', 'description' => 'Key balances and recent activity', 'href' => 'dashboard.php', 'icon' => 'bi-house'],
        ],
    ],
    [
        'label' => 'Transactions',
        'icon' => 'bi-arrow-left-right',
        'links' => [
            ['label' => 'Transactions', 'description' => 'Record sales, expenses, bills, and settlements', 'href' => 'transaction.php', 'icon' => 'bi-arrow-left-right'],
            ['label' => 'Journal Entries', 'description' => 'Review and create balanced entries', 'href' => 'journal-entries.php', 'icon' => 'bi-journal-check'],
            ['label' => 'General Ledger', 'description' => 'Browse account activity', 'href' => 'general-ledger.php', 'icon' => 'bi-book'],
            ['label' => 'Scan Receipt', 'description' => 'Import a receipt into an entry', 'href' => 'accounts.php?openScanner=1', 'icon' => 'bi-qr-code-scan'],
        ],
    ],
    [
        'label' => 'Accounts & Entities',
        'icon' => 'bi-wallet2',
        'links' => [
            ['label' => 'Chart of Accounts', 'description' => 'Manage assets, liabilities, and equity', 'href' => 'accounts.php', 'icon' => 'bi-wallet2', 'excludeQuery' => ['openScanner']],
            ['label' => 'Clients & Vendors', 'description' => 'Manage customer and vendor profiles', 'href' => 'clients.php', 'icon' => 'bi-people'],
        ],
    ],
    [
        'label' => 'Reports & Admin',
        'icon' => 'bi-bar-chart',
        'links' => [
            ['label' => 'Financial Reports', 'description' => 'Statements, balances, and analysis', 'href' => 'reports.php', 'icon' => 'bi-file-earmark-bar-graph'],
            ['label' => 'Cash Flow', 'description' => 'View cash activity and analysis', 'href' => 'reports.php?report=cash-flow-analysis', 'icon' => 'bi-cash-stack'],
            ['label' => 'Settings', 'description' => 'Business, account, and appearance settings', 'href' => 'settings.php', 'icon' => 'bi-gear'],
            ...($navigationIsAdmin ? [
                ['label' => 'User Management', 'description' => 'Manage user access and roles', 'href' => 'user-management.php', 'icon' => 'bi-people'],
                ['label' => 'Audit Log', 'description' => 'Review recorded system changes', 'href' => 'audit-log.php', 'icon' => 'bi-shield-check'],
            ] : []),
        ],
    ],
];

$navigationCurrentQuery = [];
parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $navigationCurrentQuery);

$navigationLinkIsActive = static function (array $link) use ($navigationPage, $navigationCurrentQuery): bool {
    $targetPage = basename((string) parse_url($link['href'], PHP_URL_PATH));
    if ($targetPage !== $navigationPage) {
        return false;
    }

    $targetQuery = [];
    parse_str((string) parse_url($link['href'], PHP_URL_QUERY), $targetQuery);
    foreach ($targetQuery as $key => $value) {
        if (!array_key_exists($key, $navigationCurrentQuery) || (string) $navigationCurrentQuery[$key] !== (string) $value) {
            return false;
        }
    }

    foreach ($link['excludeQuery'] ?? [] as $key) {
        if (array_key_exists($key, $navigationCurrentQuery)) {
            return false;
        }
    }

    return true;
};

$renderNavigationLinks = static function (array $links) use ($navigationLinkIsActive): void {
    foreach ($links as $link) {
        $isActive = $navigationLinkIsActive($link);
        ?>
        <a class="mega-nav-link<?= $isActive ? ' is-active' : '' ?>"
           href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"
           <?= $isActive ? 'aria-current="page"' : '' ?>>
            <span class="mega-nav-link-icon"><i class="bi <?= htmlspecialchars($link['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i></span>
            <span>
                <span class="mega-nav-link-title"><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="mega-nav-link-description"><?= htmlspecialchars($link['description'], ENT_QUOTES, 'UTF-8') ?></span>
            </span>
        </a>
        <?php
    }
};
?>
<header class="global-mega-nav">
    <div class="global-mega-nav-bar">
        <a class="global-mega-nav-brand" href="dashboard.php" aria-label="KoboSocial home">
            <img class="global-mega-nav-logo" src="../frontend/assets/Logo-Kobo-Light.png" alt="KoboSocial">
        </a>

        <button class="global-mega-nav-toggle" type="button" aria-expanded="false" aria-controls="globalMegaNavMenu">
            <i class="bi bi-list" aria-hidden="true"></i><span>Menu</span>
        </button>

        <nav class="global-mega-nav-menu" id="globalMegaNavMenu" aria-label="Main navigation">
            <?php foreach ($navigationGroups as $group): ?>
                <?php $groupIsActive = count(array_filter($group['links'], static fn (array $link): bool => $navigationLinkIsActive($link))) > 0; ?>
                <details class="mega-nav-group<?= $groupIsActive ? ' has-active-link' : '' ?>">
                    <summary>
                        <i class="bi <?= htmlspecialchars($group['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                        <?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?>
                        <i class="bi bi-chevron-down mega-nav-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="mega-nav-panel">
                        <div class="mega-nav-panel-heading">
                            <span class="mega-nav-eyebrow">Explore</span>
                            <h2><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                        </div>
                        <div class="mega-nav-links">
                            <?php $renderNavigationLinks($group['links']); ?>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </nav>

        <div class="global-mega-nav-user">
            <?php if ($navigationPage === 'dashboard.php'): ?>
                <button type="button" class="global-mega-nav-icon-button" id="dueNotificationsButton"
                        data-bs-toggle="modal" data-bs-target="#dueNotificationsModal"
                        aria-label="Payment due notifications" title="Payment due notifications">
                    <i class="bi bi-bell" aria-hidden="true"></i>
                    <span class="dashboard-notification-badge d-none" id="dueNotificationsCount" aria-live="polite"></span>
                </button>
            <?php endif; ?>
            <span class="global-mega-nav-profile" title="<?= htmlspecialchars($navigationRole, ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-person-circle" aria-hidden="true"></i>
                <span><?= htmlspecialchars($navigationName, ENT_QUOTES, 'UTF-8') ?></span>
            </span>
            <a class="global-mega-nav-icon-button" href="#" id="logoutBtn" aria-label="Log out" title="Log out">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>

    <div class="global-mega-nav-backdrop" hidden></div>
</header>
<div class="logout-confirm-backdrop" id="logoutConfirmDialog" hidden aria-hidden="true">
    <section class="logout-confirm-panel" role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle" aria-describedby="logoutConfirmDescription" tabindex="-1">
        <div class="logout-confirm-icon" aria-hidden="true"><i class="bi bi-box-arrow-right"></i></div>
        <h2 id="logoutConfirmTitle">Log out?</h2>
        <p id="logoutConfirmDescription">Are you sure you want to end your session?</p>
        <div class="logout-confirm-actions">
            <button type="button" class="quick-action-btn" data-logout-cancel>Stay signed in</button>
            <button type="button" class="quick-action-btn primary" data-logout-confirm>Log out</button>
        </div>
        <div class="logout-confirm-progress" aria-hidden="true"><span></span></div>
    </section>
</div>
<script src="../frontend/js/mega-nav.js" defer></script>
