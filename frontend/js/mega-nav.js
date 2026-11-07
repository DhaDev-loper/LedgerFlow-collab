(() => {
    const navigation = document.querySelector('.global-mega-nav');
    const menuToggle = navigation?.querySelector('.global-mega-nav-toggle');
    const backdrop = navigation?.querySelector('.global-mega-nav-backdrop');
    const menu = navigation?.querySelector('.global-mega-nav-menu');
    if (!navigation || !menuToggle || !backdrop || !menu) return;

    const closeMenus = () => {
        menu.querySelectorAll('.mega-nav-group[open]').forEach(group => {
            group.open = false;
        });
    };

    const closeMobileMenu = () => {
        navigation.classList.remove('is-mobile-open');
        document.body.classList.remove('mega-menu-open');
        menuToggle.setAttribute('aria-expanded', 'false');
        menuToggle.innerHTML = '<i class="bi bi-list" aria-hidden="true"></i><span>Menu</span>';
        backdrop.hidden = true;
    };

    menu.querySelectorAll('.mega-nav-group').forEach(group => {
        group.addEventListener('toggle', () => {
            if (!group.open) return;
            menu.querySelectorAll('.mega-nav-group[open]').forEach(otherGroup => {
                if (otherGroup !== group) otherGroup.open = false;
            });
        });
    });

    menuToggle.addEventListener('click', () => {
        const isOpen = navigation.classList.toggle('is-mobile-open');
        document.body.classList.toggle('mega-menu-open', isOpen);
        menuToggle.setAttribute('aria-expanded', String(isOpen));
        menuToggle.innerHTML = isOpen
            ? '<i class="bi bi-x-lg" aria-hidden="true"></i><span>Close</span>'
            : '<i class="bi bi-list" aria-hidden="true"></i><span>Menu</span>';
        backdrop.hidden = !isOpen;
        if (!isOpen) closeMenus();
    });

    backdrop.addEventListener('click', () => {
        closeMobileMenu();
        closeMenus();
    });

    document.addEventListener('click', event => {
        if (!navigation.contains(event.target)) closeMenus();
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const mobileWasOpen = navigation.classList.contains('is-mobile-open');
        const openGroup = menu.querySelector('.mega-nav-group[open]');
        closeMenus();
        closeMobileMenu();
        if (mobileWasOpen) menuToggle.focus();
        else openGroup?.querySelector('summary')?.focus();
    });

    const logoutDialog = document.getElementById('logoutConfirmDialog');
    const logoutPanel = logoutDialog?.querySelector('.logout-confirm-panel');
    const logoutCancel = logoutDialog?.querySelector('[data-logout-cancel]');
    const logoutConfirm = logoutDialog?.querySelector('[data-logout-confirm]');
    const logoutDescription = document.getElementById('logoutConfirmDescription');
    let pendingLogoutLink = null;
    let logoutInProgress = false;

    const closeLogoutDialog = () => {
        if (!logoutDialog || logoutInProgress) return;
        logoutDialog.classList.remove('is-open');
        logoutDialog.setAttribute('aria-hidden', 'true');
        window.setTimeout(() => {
            if (!logoutDialog.classList.contains('is-open')) logoutDialog.hidden = true;
        }, 180);
        pendingLogoutLink?.focus();
        pendingLogoutLink = null;
    };

    const openLogoutDialog = logoutLink => {
        if (!logoutDialog || !logoutPanel) {
            if (window.confirm('Are you sure you want to log out?')) performLogout(logoutLink);
            return;
        }
        pendingLogoutLink = logoutLink;
        closeMenus();
        closeMobileMenu();
        logoutDialog.hidden = false;
        logoutDialog.setAttribute('aria-hidden', 'false');
        logoutPanel.classList.remove('is-logging-out');
        logoutPanel.setAttribute('aria-busy', 'false');
        logoutDescription.textContent = 'Are you sure you want to end your session?';
        logoutCancel.disabled = false;
        logoutConfirm.disabled = false;
        requestAnimationFrame(() => logoutDialog.classList.add('is-open'));
        logoutCancel.focus();
    };

    const performLogout = async logoutLink => {
        if (logoutInProgress) return;
        logoutInProgress = true;
        if (logoutConfirm) logoutConfirm.disabled = true;
        if (logoutCancel) logoutCancel.disabled = true;
        if (logoutPanel) {
            logoutPanel.classList.add('is-logging-out');
            logoutPanel.setAttribute('aria-busy', 'true');
        }
        if (logoutDescription) logoutDescription.textContent = 'Signing you out…';
        logoutLink?.setAttribute('aria-disabled', 'true');

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const animationDelay = new Promise(resolve => window.setTimeout(resolve, reducedMotion ? 0 : 420));
        const logoutRequest = fetch('routes/auth.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'logout' }),
            credentials: 'same-origin',
        }).catch(error => console.error('Logging out failed:', error));

        await Promise.all([animationDelay, logoutRequest]);
        window.location.href = '../frontend/login.html';
    };

    logoutCancel?.addEventListener('click', closeLogoutDialog);
    logoutConfirm?.addEventListener('click', () => performLogout(pendingLogoutLink));
    logoutDialog?.addEventListener('click', event => {
        if (event.target === logoutDialog) closeLogoutDialog();
    });
    logoutDialog?.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeLogoutDialog();
        } else if (event.key === 'Tab' && !logoutInProgress) {
            const focusable = [logoutCancel, logoutConfirm].filter(button => button && !button.disabled);
            if (event.shiftKey && document.activeElement === focusable[0]) {
                event.preventDefault();
                focusable.at(-1)?.focus();
            } else if (!event.shiftKey && document.activeElement === focusable.at(-1)) {
                event.preventDefault();
                focusable[0]?.focus();
            }
        }
    });

    document.addEventListener('click', event => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const logoutLink = target.closest('#logoutBtn');
        if (!logoutLink) return;

        event.preventDefault();
        event.stopPropagation();
        openLogoutDialog(logoutLink);
    }, true);
})();
