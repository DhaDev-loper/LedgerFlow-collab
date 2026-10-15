/* this is the js file for login page, it handles the buttons actions and form submission 
   like the text box for email and password,
*/ 

const suspensionTimer = document.getElementById('suspensionTimer');
const suspensionTimerLabel = document.getElementById('suspensionTimerLabel');
const suspensionTimerCountdown = document.getElementById('suspensionTimerCountdown');
let suspensionCountdownInterval = null;

function showSuspensionTimer(level, remainingSeconds) {
    if (suspensionCountdownInterval !== null) {
        window.clearInterval(suspensionCountdownInterval);
    }

    let secondsLeft = Math.max(0, Math.floor(Number(remainingSeconds)));
    const suspensionName = Number(level) >= 1 && Number(level) <= 3
        ? `Level ${level} suspension`
        : 'Suspension';

    suspensionTimerLabel.textContent = `${suspensionName} — time remaining`;
    suspensionTimer.classList.remove('d-none');

    const renderCountdown = () => {
        const minutes = Math.floor(secondsLeft / 60);
        const seconds = secondsLeft % 60;
        suspensionTimerCountdown.textContent =
            `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;

        if (secondsLeft <= 0) {
            window.clearInterval(suspensionCountdownInterval);
            suspensionCountdownInterval = null;
            suspensionTimerLabel.textContent = 'Suspension timer ended';
            suspensionTimerCountdown.textContent = 'You may try signing in again';
            return;
        }

        secondsLeft -= 1;
    };

    renderCountdown();
    suspensionCountdownInterval = window.setInterval(renderCountdown, 1000);
}

// id = loginform, it handles the login form submission
document.getElementById('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const formData = new FormData(e.target);
    const errorMsg = document.getElementById('errorMsg');
    errorMsg.textContent = '';
    suspensionTimer.classList.add('d-none');
    if (suspensionCountdownInterval !== null) {
        window.clearInterval(suspensionCountdownInterval);
        suspensionCountdownInterval = null;
    }

    // validates the email and password at routes/auth.php, if the email and password are correct, it will redirect to dashboard.php
    try {
    const response = await fetch('../backend/routes/auth.php', {
        method: 'POST',
        body: formData,
    });

    const responseText = await response.text();
    let data;
    try {
        data = JSON.parse(responseText);
    } catch (parseError) {
        console.error('Login endpoint returned a non-JSON response. HTTP status:', response.status);
        throw new Error('The login service returned an invalid response. Please contact the administrator.');
    }

    if (data.success) {
        if (data.pin_required) {
            window.location.href = 'verify-pin.html';
        } else {
            window.location.href = '../backend/dashboard.php';
        }
    } else {
        errorMsg.textContent = data.error || 'Login failed. Please try again.';
        errorMsg.classList.remove('d-none');
        if (data.suspended && Number(data.remaining_seconds) > 0) {
            showSuspensionTimer(data.suspension_level, data.remaining_seconds);
        }
    }
} catch (err) {
    console.error('Login request failed:', err);
    errorMsg.textContent = err.message || 'Something went wrong. Please try again.';
    errorMsg.classList.remove('d-none');
}
});

// it handles the toggle hide and show of the password in the login form
document.getElementById('togglePassword').addEventListener('click', () => {
    const passwordInput = document.getElementById('password');
    const toggleBtn = document.getElementById('togglePassword');

    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleBtn.textContent = '🙈';
        toggleBtn.setAttribute('aria-label', 'Hide password');
    } else {
        passwordInput.type = 'password';
        toggleBtn.textContent = '👁';
        toggleBtn.setAttribute('aria-label', 'Show password');
    }
});