<?php
/**
 * Router script for PHP built-in server (php -S).
 *
 * This enables clean routes like /api/v1/... in development without Apache/Nginx rewrites.
 *
 * Usage:
 *   php -S 127.0.0.1:8080 -t ./anonchat ./anonchat/router.php
 */

declare(strict_types=1);

if (!function_exists('starts_with')) {
    function starts_with(string $haystack, string $needle): bool {
        if ($needle == '') return true;
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

$uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uriPath = is_string($uriPath) && $uriPath !== '' ? $uriPath : '/';

// Serve existing files (static assets and .php scripts) normally.
$fullPath = __DIR__ . $uriPath;
if ($uriPath !== '/' && is_file($fullPath)) {
    return false;
}

// REST API v1
if (starts_with($uriPath, '/api/v1')) {
    require __DIR__ . '/api/v1/index.php';
    return true;
}

// Let the built-in server handle other routes (will 404 if missing).
return false;
