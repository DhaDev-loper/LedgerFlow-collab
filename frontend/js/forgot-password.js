document.getElementById('forgotForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const form = e.target;
    const btn = document.getElementById('submitBtn');
    const errorMsg = document.getElementById('errorMsg');
    const successMsg = document.getElementById('successMsg');

    errorMsg.classList.add('d-none');
    successMsg.classList.add('d-none');

    const email = document.getElementById('email').value.trim();

    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Sending...';

    try {
        const formData = new FormData(form);
        formData.append('action', 'forgot-password');

        const response = await fetch('../backend/routes/auth.php', {
            method: 'POST',
            body: formData,
        });

        const data = await response.json();

        if (data.success) {
            // Save the email so the next page (verify-otp.html) knows
            // which address to check the code against.
            sessionStorage.setItem('reset_email', email);
            window.location.href = 'verify-otp.html';
        } else {
            errorMsg.textContent = data.error || 'Something went wrong. Please try again.';
            errorMsg.classList.remove('d-none');
        }
    } catch (err) {
        console.error('Forgot password request failed:', err);
        errorMsg.textContent = 'Could not reach the server. Please try again.';
        errorMsg.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
});