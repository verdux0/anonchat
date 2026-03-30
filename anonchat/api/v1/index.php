<?php
/**
 * API v1 front controller.
 *
 * Goals:
 * - Provide RESTful routes under /api/v1/...
 * - Stay compatible with the existing JSON envelope: {success, data, error}
 * - Reuse legacy implementation initially to reduce risk.
 */

declare(strict_types=1);

// Delegate common security headers + OPTIONS handling.
require_once __DIR__ . '/../headers.php';

if (!function_exists('starts_with')) {
    function starts_with(string $haystack, string $needle): bool {
        if ($needle == '') return true;
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

// Strip the /api/v1 prefix.
$prefix = '/api/v1';
if (starts_with($path, $prefix)) {
    $path = (string)substr($path, strlen($prefix));
}
if ($path === '') $path = '/';

function v1_json(bool $ok, $payload = null, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode([
        'success' => $ok,
        'data' => $ok ? $payload : null,
        'error' => $ok ? null : $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Minimal health endpoint
if ($method === 'GET' && $path === '/health') {
    v1_json(true, ['ok' => true, 'version' => 'v1']);
}

// ---- Conversations (public) ----
// POST /api/v1/conversations  -> legacy create_conversation
if ($method === 'POST' && $path === '/conversations') {
    $_GET['action'] = 'create_conversation';
    require __DIR__ . '/../api.php';
    exit;
}

// GET /api/v1/conversations/validate?code=... -> legacy check_code
if ($method === 'GET' && $path === '/conversations/validate') {
    $_GET['action'] = 'check_code';
    require __DIR__ . '/../api.php';
    exit;
}

// POST /api/v1/conversations/sessions -> legacy continue_conversation
if ($method === 'POST' && $path === '/conversations/sessions') {
    $_GET['action'] = 'continue_conversation';
    require __DIR__ . '/../api.php';
    exit;
}

v1_json(false, 'Ruta no encontrada', 404);
