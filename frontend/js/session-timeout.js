(() => {
    const idleTimeoutMs = 30 * 60 * 1000;
    const warningDurationMs = 60 * 1000;
    const loginUrl = '../frontend/login.html';
    let lastActivity = Date.now();
    let warningTimer;
    let logoutTimer;
    let countdownTimer;
    let loggingOut = false;
    let warning;
    let countdown;

    function removeWarning() {
        if (countdownTimer) window.clearInterval(countdownTimer);
        countdownTimer = null;
        warning?.remove();
        warning = null;
        countdown = null;
    }

    async function logoutForInactivity() {
        if (loggingOut) return;
        loggingOut = true;
        removeWarning();

        try {
            await fetch('routes/auth.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'logout' }),
                credentials: 'same-origin',
                keepalive: true,
            });
        } catch (error) {
            console.error('Automatic logout request failed:', error);
        } finally {
            window.location.replace(loginUrl);
        }
    }

    function showTimeoutWarning() {
        if (loggingOut) return;
        const remainingMs = idleTimeoutMs - (Date.now() - lastActivity);
        if (remainingMs <= 0) {
            void logoutForInactivity();
            return;
        }

        warning = document.createElement('div');
        warning.className = 'position-fixed bottom-0 end-0 m-3 p-3 rounded-3 shadow-lg';
        warning.setAttribute('role', 'status');
        warning.style.cssText = 'z-index: 11000; max-width: 360px; color: var(--dark-text, #f8fafc); background: var(--dark-card, #172033); border: 1px solid var(--dark-border, #475569);';

        const message = document.createElement('p');
        message.className = 'small mb-2';
        message.textContent = 'You will be signed out soon due to inactivity.';

        countdown = document.createElement('strong');
        countdown.className = 'small d-block mb-3';

        const stayButton = document.createElement('button');
        stayButton.type = 'button';
        stayButton.className = 'btn btn-primary btn-sm me-2';
        stayButton.textContent = 'Stay signed in';
        stayButton.addEventListener('click', resetIdleTimer);

        const logoutButton = document.createElement('button');
        logoutButton.type = 'button';
        logoutButton.className = 'btn btn-outline-secondary btn-sm';
        logoutButton.textContent = 'Sign out now';
        logoutButton.addEventListener('click', () => void logoutForInactivity());

        warning.append(message, countdown, stayButton, logoutButton);
        document.body.appendChild(warning);

        const updateCountdown = () => {
            const secondsLeft = Math.max(0, Math.ceil((idleTimeoutMs - (Date.now() - lastActivity)) / 1000));
            if (countdown) countdown.textContent = `Automatic sign-out in ${secondsLeft} seconds.`;
            if (secondsLeft <= 0) void logoutForInactivity();
        };
        updateCountdown();
        countdownTimer = window.setInterval(updateCountdown, 1000);
    }

    function resetIdleTimer() {
        if (loggingOut) return;
        lastActivity = Date.now();
        removeWarning();
        window.clearTimeout(warningTimer);
        window.clearTimeout(logoutTimer);
        warningTimer = window.setTimeout(showTimeoutWarning, idleTimeoutMs - warningDurationMs);
        logoutTimer = window.setTimeout(() => void logoutForInactivity(), idleTimeoutMs);
    }

    ['pointerdown', 'pointermove', 'keydown', 'touchstart', 'scroll', 'input', 'change'].forEach(type => {
        document.addEventListener(type, event => {
            if (!event.isTrusted) return;
            if (type === 'pointermove' && Date.now() - lastActivity < 1000) return;
            resetIdleTimer();
        }, { passive: true });
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && Date.now() - lastActivity >= idleTimeoutMs) {
            void logoutForInactivity();
        }
    });

    resetIdleTimer();
})();
