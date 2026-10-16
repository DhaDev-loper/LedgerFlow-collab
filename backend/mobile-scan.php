<?php
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
        body { background-color: #f4f6f9; color: #16233a; min-height: 100vh; }
        .upload-card { background: #ffffff; border-radius: 1rem; border: 1px solid rgba(8, 13, 30, 0.12); box-shadow: 0 12px 32px rgba(22, 35, 58, 0.08); }
        .btn-capture { background: linear-gradient(135deg, #2563eb, #1d4ed8); border: none; font-weight: 600; }
        .text-light { color: #16233a !important; }
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

async function prepareReceiptImage(file) {
    if (file.type && !file.type.startsWith('image/')) {
        throw new Error('Please choose a receipt photo in an image format.');
    }

    const objectUrl = URL.createObjectURL(file);
    try {
        const image = new Image();
        image.src = objectUrl;
        await new Promise((resolve, reject) => {
            image.onload = resolve;
            image.onerror = () => reject(new Error('The phone could not read this photo format. Please take or choose a JPEG photo.'));
        });

        if (!image.naturalWidth || !image.naturalHeight) {
            throw new Error('The phone could not read this photo. Please take or choose the receipt photo again.');
        }

        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        if (!context) {
            throw new Error('This browser cannot prepare the photo for upload. Please try a different browser.');
        }

        const longestSide = Math.max(image.naturalWidth, image.naturalHeight);
        let scale = Math.min(1, 2000 / longestSide);
        let quality = 0.84;
        let blob = null;

        for (let attempt = 0; attempt < 8; attempt += 1) {
            canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
            canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(image, 0, 0, canvas.width, canvas.height);

            blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
            if (blob && blob.size <= 950 * 1024) break;

            if (quality > 0.62) {
                quality = Math.max(0.62, quality - 0.08);
            } else {
                scale *= 0.82;
            }
        }

        if (!blob || blob.size > 950 * 1024) {
            throw new Error('Could not reduce this photo enough for scanning. Please take a closer, lower-resolution photo of the receipt.');
        }

        return new File([blob], `receipt-${Date.now()}.jpg`, {
            type: 'image/jpeg',
            lastModified: Date.now()
        });
    } finally {
        URL.revokeObjectURL(objectUrl);
    }
}

fileInput.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    triggerBtn.classList.add('d-none');
    statusBox.classList.remove('d-none');

    try {
        statusText.textContent = 'Preparing photo...';
        const receiptImage = await prepareReceiptImage(file);
        const formData = new FormData();
        formData.append('action', 'push-mobile-receipt');
        formData.append('token', token);
        formData.append('receipt_image', receiptImage);

        statusText.textContent = 'Sending photo for scanning...';
        const uploadUrl = new URL('routes/journal.php', window.location.href);
        uploadUrl.searchParams.set('action', 'push-mobile-receipt');
        const response = await fetch(uploadUrl, {
            method: 'POST',
            body: formData
        });
        const responseBody = await response.text();
        let data;

        try {
            data = JSON.parse(responseBody);
        } catch {
            if (response.status === 413) {
                throw new Error('The photo is larger than the server upload limit. Use a lower-resolution photo or reduce its size.');
            }
            throw new Error(`The server returned an unexpected response (HTTP ${response.status}).`);
        }

        if (data.success) {
            statusText.textContent = 'Uploaded! Check your PC screen.';
            statusBox.innerHTML = '<i class="bi bi-check-circle-fill text-success display-4 d-block mb-2"></i><p class="text-light fw-semibold">Receipt Sent Successfully!</p>';
        } else {
            alert(data.error || `Photo upload failed (HTTP ${response.status}).`);
            triggerBtn.classList.remove('d-none');
            statusBox.classList.add('d-none');
        }
    } catch (err) {
        console.error('Mobile receipt upload failed:', err);
        alert(err instanceof Error ? err.message : 'Could not send the photo. Check the phone connection and try again.');
        triggerBtn.classList.remove('d-none');
        statusBox.classList.add('d-none');
    } finally {
        fileInput.value = '';
    }
});
</script>
</body>
</html>