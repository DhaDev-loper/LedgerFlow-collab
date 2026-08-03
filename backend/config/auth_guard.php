<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Location:../frontend/login.html');
    exit;
}

