<?php
declare(strict_types=1);

function loadEnv(string $path): void
{
    if (!is_file($path)) {
        die("Missing .env file. Please create one based on the setup instructions.");
    }

    $config = parse_ini_file($path);

    foreach ($config as $key => $value) {
        putenv("$key=$value");
    }
}

function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

loadEnv(dirname(__DIR__, 2) . '/.env');