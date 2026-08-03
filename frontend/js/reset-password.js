document.getElementById('resetForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const btn = document.getElementById('submitBtn');
    const errorMsg = document.getElementById('errorMsg');
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
    btn.textContent = 'Resetting...';

    try {
        const response = await fetch('../backend/routes/auth.php', {
            method: 'POST',
            body: new URLSearchParams({
                action: 'reset-password',
                new_password: newPassword,
            }),
        });

        const data = await response.json();

        if (data.success) {
            // Clean up — this reset flow is complete, don't leave the
            // email sitting around in sessionStorage.
            sessionStorage.removeItem('reset_email');
            alert('Password reset successfully! Please log in with your new password.');
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