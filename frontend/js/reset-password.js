const inviteToken = new URLSearchParams(window.location.search).get('invite');
const submitButton = document.getElementById('submitBtn');
const errorMessage = document.getElementById('errorMsg');

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
        const requestData = {
            action: inviteToken ? 'accept-setup-invitation' : 'reset-password',
            new_password: newPassword,
        };
        if (inviteToken) {
            requestData.token = inviteToken;
        }

        const response = await fetch('../backend/routes/auth.php', {
            method: 'POST',
            body: new URLSearchParams(requestData),
        });

        const data = await response.json();

        if (data.success) {
            // Clean up — this reset flow is complete, don't leave the
            // email sitting around in sessionStorage.
            sessionStorage.removeItem('reset_email');
            alert(inviteToken
                ? 'Password set successfully! You can now log in.'
                : 'Password reset successfully! Please log in with your new password.');
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