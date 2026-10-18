document.getElementById('forgotForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const form = e.target;
    const btn = document.getElementById('submitBtn');
    const errorMsg = document.getElementById('errorMsg');
    const successMsg = document.getElementById('successMsg');

    errorMsg.classList.add('d-none');
    successMsg.classList.add('d-none');

    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Sending...';

    try {
        const response = await fetch('../backend/auth/request_reset.php', {
            method: 'POST',
            body: new FormData(form),
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            errorMsg.textContent = data.error || 'Something went wrong. Please try again.';
            errorMsg.classList.remove('d-none');
        } else {
            successMsg.textContent = data.message;
            successMsg.classList.remove('d-none');
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