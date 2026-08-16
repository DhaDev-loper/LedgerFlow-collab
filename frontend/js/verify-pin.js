document.getElementById('pinForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const btn = document.getElementById('verifyPinBtn');
    const errorMsg = document.getElementById('errorMsg');
    errorMsg.classList.add('d-none');

    const pin = document.getElementById('pin').value;

    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Verifying...';

    try {
        const response = await fetch('../backend/routes/auth.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'verify-pin', pin: pin }),
        });
        const data = await response.json();

        if (data.success) {
            window.location.href = '../backend/dashboard.php';
        } else {
            errorMsg.textContent = data.error || 'Incorrect PIN.';
            errorMsg.classList.remove('d-none');
            document.getElementById('pin').value = '';
            document.getElementById('pin').focus();
        }
    } catch (err) {
        console.error('PIN verification failed:', err);
        errorMsg.textContent = 'Could not reach the server.';
        errorMsg.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
});