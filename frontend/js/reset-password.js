const inviteToken = new URLSearchParams(window.location.search).get('invite');
const recoveryToken = new URLSearchParams(window.location.search).get('token');
const submitButton = document.getElementById('submitBtn');
const errorMessage = document.getElementById('errorMsg');

document.querySelectorAll('.password-toggle-btn').forEach(toggleButton => {
    toggleButton.addEventListener('click', () => {
        const passwordInput = document.getElementById(toggleButton.getAttribute('aria-controls'));
        const showIcon = toggleButton.querySelector('.password-toggle-icon-show');
        const hideIcon = toggleButton.querySelector('.password-toggle-icon-hide');
        const showingPassword = passwordInput.type === 'password';

        passwordInput.type = showingPassword ? 'text' : 'password';
        showIcon.classList.toggle('d-none', showingPassword);
        hideIcon.classList.toggle('d-none', !showingPassword);
        toggleButton.setAttribute('aria-label', showingPassword ? 'Hide password' : 'Show password');
    });
});

if (inviteToken) {
    document.getElementById('passwordPageTitle').textContent = 'Set Up Your Password';
    document.getElementById('passwordPageDescription').textContent =
        'Choose a password to finish setting up your account.';
    submitButton.textContent = 'Set Password';
    submitButton.disabled = true;

    fetch('../backend/routes/auth.php', {
        method: 'POST',
        body: new URLSearchParams({
            action: 'validate-setup-invitation',
            token: inviteToken,
        }),
    })
        .then(async response => {
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'This setup link is invalid or has expired.');
            }
            submitButton.disabled = false;
        })
        .catch(err => {
            errorMessage.textContent = err.message || 'Could not validate the setup link.';
            errorMessage.classList.remove('d-none');
        });
} else if (recoveryToken) {
    submitButton.disabled = true;

    fetch(`../backend/auth/verify_reset.php?token=${encodeURIComponent(recoveryToken)}`)
        .then(async response => {
            const data = await response.json();
            if (!response.ok || !data.valid) {
                throw new Error(data.error || 'This recovery link is invalid or has expired.');
            }
            submitButton.disabled = false;
        })
        .catch(err => {
            errorMessage.textContent = err.message || 'Could not validate the recovery link.';
            errorMessage.classList.remove('d-none');
        });
} else {
    submitButton.disabled = true;
    errorMessage.textContent = 'This password link is invalid or has expired.';
    errorMessage.classList.remove('d-none');
}

document.getElementById('resetForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const btn = document.getElementById('submitBtn');
    const errorMsg = errorMessage;
    errorMsg.classList.add('d-none');

    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;

    if (newPassword !== confirmPassword) {
        errorMsg.textContent = 'Passwords do not match.';
        errorMsg.classList.remove('d-none');
        return;
    }

    if (newPassword.length < 8) {
        errorMsg.textContent = 'Password must be at least 8 characters.';
        errorMsg.classList.remove('d-none');
        return;
    }

    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = inviteToken ? 'Setting password...' : 'Resetting...';

    try {
        const requestData = new URLSearchParams({
            action: inviteToken ? 'accept-setup-invitation' : 'reset-password',
            new_password: newPassword,
        });
        const endpoint = inviteToken
            ? '../backend/routes/auth.php'
            : '../backend/auth/verify_reset.php';
        if (inviteToken) {
            requestData.set('token', inviteToken);
        } else if (recoveryToken) {
            requestData.delete('action');
            requestData.set('token', recoveryToken);
        }

        const response = await fetch(endpoint, {
            method: 'POST',
            body: requestData,
        });

        const data = await response.json();

        if (response.ok && data.success) {
            alert(inviteToken
                ? 'Password set successfully! You can now log in.'
                : 'Password reset successfully. Your account is unlocked and you can now sign in.');
            window.location.href = 'login.html';
        } else {
            errorMsg.textContent = data.error || 'Something went wrong. Please try again.';
            errorMsg.classList.remove('d-none');
        }
    } catch (err) {
        console.error('Reset password request failed:', err);
        errorMsg.textContent = 'Could not reach the server. Please try again.';
        errorMsg.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
});