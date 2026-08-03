const resetEmail = sessionStorage.getItem('reset_email');

// If someone lands on this page directly without going through
// forgot-password.html first, there's no email to verify against —
// send them back to start the flow properly.
if (!resetEmail) {
    window.location.href = 'forgot-password.html';
}

document.getElementById('emailDisplay').textContent = resetEmail;

document.getElementById('verifyForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const btn = document.getElementById('submitBtn');
    const errorMsg = document.getElementById('errorMsg');
    errorMsg.classList.add('d-none');

    const otpCode = document.getElementById('otpCode').value.trim();

    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Verifying...';

    try {
        const response = await fetch('../backend/routes/auth.php', {
            method: 'POST',
            body: new URLSearchParams({
                action: 'verify-otp',
                email: resetEmail,
                otp_code: otpCode,
            }),
        });

        const data = await response.json();

        if (data.success) {
            window.location.href = 'reset-password.html';
        } else {
            errorMsg.textContent = data.error || 'Invalid code. Please try again.';
            errorMsg.classList.remove('d-none');
        }
    } catch (err) {
        console.error('Verify OTP request failed:', err);
        errorMsg.textContent = 'Could not reach the server. Please try again.';
        errorMsg.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
});