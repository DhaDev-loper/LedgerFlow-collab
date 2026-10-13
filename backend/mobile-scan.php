<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// No session_start() needed here to prevent session conflicts!
$token = $_GET['token'] ?? '';
if (empty($token) || !preg_match('/^[a-f0-9]{16}$/i', $token)) {
    die('Invalid or expired scanner link.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WRS Receipt Scanner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #0f172a; color: #f8fafc; min-height: 100vh; }
        .upload-card { background: #1e293b; border-radius: 1rem; border: 1px solid #334155; }
        .btn-capture { background: linear-gradient(135deg, #2563eb, #1d4ed8); border: none; font-weight: 600; }
    </style>
</head>
<body>
<div class="container d-flex flex-column align-items-center justify-content-center min-vh-100 p-3">
    <div class="upload-card text-center p-4 w-100 style-mobile-card" style="max-width: 400px;">
        <i class="bi bi-camera-fill display-1 text-primary mb-3"></i>
        <h4 class="fw-bold mb-2">Scan Receipt</h4>
        <p class="text-secondary small mb-4">Snap a clear photo of the physical receipt on your desk.</p>

        <form id="mobileUploadForm">
            <input type="hidden" id="sessionToken" value="<?= htmlspecialchars($token) ?>">
            <input type="file" id="mobileFileInput" accept="image/*" capture="environment" class="d-none">
            
            <button type="button" class="btn btn-primary btn-capture btn-lg w-100 mb-3" id="triggerCameraBtn">
                <i class="bi bi-camera me-2"></i> Take Photo
            </button>
        </form>

        <div id="uploadStatus" class="d-none">
            <div class="spinner-border text-primary mb-2" role="status"></div>
            <p class="small text-secondary mb-0" id="uploadStatusText">Sending to PC screen...</p>
        </div>
    </div>
</div>

<script>
const token = document.getElementById('sessionToken').value;
const fileInput = document.getElementById('mobileFileInput');
const triggerBtn = document.getElementById('triggerCameraBtn');
const statusBox = document.getElementById('uploadStatus');
const statusText = document.getElementById('uploadStatusText');

triggerBtn.addEventListener('click', () => fileInput.click());

fileInput.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    triggerBtn.classList.add('d-none');
    statusBox.classList.remove('d-none');

    const formData = new FormData();
    formData.append('action', 'push-mobile-receipt');
    formData.append('token', token);
    formData.append('receipt_image', file);

    try {
        const response = await fetch('/wrs-accounting-collab/backend/routes/journal.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            statusText.textContent = 'Uploaded! Check your PC screen.';
            statusBox.innerHTML = '<i class="bi bi-check-circle-fill text-success display-4 d-block mb-2"></i><p class="text-light fw-semibold">Receipt Sent Successfully!</p>';
        } else {
            alert(data.error || 'Failed to send receipt.');
            triggerBtn.classList.remove('d-none');
            statusBox.classList.add('d-none');
        }
    } catch (err) {
        alert('Server connection error.');
        triggerBtn.classList.remove('d-none');
        statusBox.classList.add('d-none');
    }
});
</script>
</body>
</html>