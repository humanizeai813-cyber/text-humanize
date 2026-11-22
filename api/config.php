<?php
// Load .env file from project root if it exists
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

function envValue(string $key): string
{
    $value = $_ENV[$key] ?? getenv($key);
    return $value === false || $value === null ? '' : (string) $value;
}

define('GROQ_API_KEY',          envValue('GROQ_API_KEY'));
define('CLOUDFLARE_ACCOUNT_ID', envValue('CLOUDFLARE_ACCOUNT_ID'));
define('CLOUDFLARE_API_TOKEN',  envValue('CLOUDFLARE_API_TOKEN'));
define('NVIDIA_API_KEY',  envValue('NVIDIA_API_KEY'));
define('ALLOWED_ORIGIN',  envValue('ALLOWED_ORIGIN'));
define('COPYLEAKS_EMAIL', envValue('COPYLEAKS_EMAIL'));
define('COPYLEAKS_KEY',   envValue('COPYLEAKS_API_KEY'));
define('SAPLING_API_KEY', envValue('SAPLING_API_KEY'));
define('GPTZERO_API_KEY', envValue('GPTZERO_API_KEY'));
define('HF_TOKEN',        envValue('HF_TOKEN'));
