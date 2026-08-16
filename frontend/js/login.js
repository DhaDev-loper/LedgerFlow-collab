/* this is the js file for login page, it handles the buttons actions and form submission 
   like the text box for email and password,
*/ 

// id = loginform, it handles the login form submission
document.getElementById('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const formData = new FormData(e.target);
    const errorMsg = document.getElementById('errorMsg');
    errorMsg.textContent = '';

    // validates the email and password at routes/auth.php, if the email and password are correct, it will redirect to dashboard.php
    try {
    const response = await fetch('../backend/routes/auth.php', {
        method: 'POST',
        body: formData,
    });

    const data = await response.json();

    if (data.success) {
        if (data.pin_required) {
            window.location.href = 'verify-pin.html';
        } else {
            window.location.href = '../backend/dashboard.php';
        }
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