document.getElementById('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const formData = new FormData(e.target);
    const errorMsg = document.getElementById('errorMsg');
    errorMsg.textContent = '';

    try {
        const response = await fetch('../backend/routes/auth.php', {
            method: 'POST',
            body: formData,
        });

        const data = await response.json();

        if (data.success) {
            window.location.href = '../backend/dashboard.php';
        } else {
            errorMsg.textContent = data.error || 'Login failed. Please try again.';
            errorMsg.classList.remove('d-none');
        }
    } catch (err) {
        console.error('Login request failed:', err);
        errorMsg.textContent = 'Something went wrong. Please try again.';
        errorMsg.classList.remove('d-none');
    }
});