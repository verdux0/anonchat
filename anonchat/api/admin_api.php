<?php
/**
 * admin_api.php (LOGIN ADMIN) — actualizado
 *
 * Qué hace:
 * - Recibe credenciales SOLO por POST (FormData) y devuelve JSON.
 * - Comprueba si el usuario existe en la tabla Admin (SELECT ... WHERE User = ?).
 * - Verifica contraseña con password_verify() contra Password_Hash.
 * - Aplica: CSRF, rate limit por IP (Rate_Limit), bloqueo por cuenta (Failed_Login_Attempts/Locked_Until),
 *   sesión segura (cookies HttpOnly/SameSite, regenerate_id), logging a DB + archivo.
 *
 * Nota importante:
 * - Ajusta el require de db.php según tu estructura. Si este archivo está en /api, normalmente es ../db.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/session.php';

// Cargar configuración
$config = require __DIR__ . '/config.php';
/* -------------------- Utilidades base -------------------- */

function json_response(bool $ok, $payload = null, int $code = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

    http_response_code($code);
    echo json_encode([
        'success' => $ok,
        'data'    => $ok ? $payload : null,
        'error'   => $ok ? null : $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Rate limit por IP+acción usando Rate_Limit.
 */
function rate_limit(PDO $pdo, string $actionType, int $maxAttempts, int $windowSeconds): void {
    $ip = client_ip();
    $now = time();

    $stmt = $pdo->prepare("SELECT ID, Attempt_Count, Window_Start FROM Rate_Limit WHERE IP_Address = ? AND Action_Type = ? LIMIT 1");
    $stmt->execute([$ip, $actionType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->prepare("INSERT INTO Rate_Limit (IP_Address, Action_Type, Attempt_Count, Window_Start) VALUES (?, ?, 1, NOW())")
            ->execute([$ip, $actionType]);
        return;
    }

    $windowStart = strtotime((string)$row['Window_Start']);
    if ($windowStart === false) $windowStart = $now;

    // reset si expiró
    if (($now - $windowStart) > $windowSeconds) {
        $pdo->prepare("UPDATE Rate_Limit SET Attempt_Count = 1, Window_Start = NOW() WHERE ID = ?")
            ->execute([$row['ID']]);
        return;
    }

    $attempts = (int)$row['Attempt_Count'] + 1;
    $pdo->prepare("UPDATE Rate_Limit SET Attempt_Count = ? WHERE ID = ?")
        ->execute([$attempts, $row['ID']]);

    if ($attempts > $maxAttempts) {
        $retryIn = max(1, $windowSeconds - ($now - $windowStart));
        json_response(false, "Demasiados intentos. Intenta de nuevo en ~{$retryIn}s.", 429);
    }
}

start_secure_session();
$pdo = get_pdo();


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Método no permitido', 405);
}

$action = $_POST['action'] ?? '';
if ($action !== 'login') {
    json_response(false, 'Acción no soportada', 400);
}


$csrf = (string)($_POST['csrf_token'] ?? '');
if (!validate_csrf_token($csrf, 'admin')) {
    db_log($pdo, 'suspicious_activity', 'admin_api_csrf_invalid');
    file_log('admin_csrf_invalid');
    json_response(false, 'CSRF inválido', 403);
}


rate_limit(
    $pdo, 
    'login_attempt', 
    $config['rate_limit']['login_attempts'], 
    $config['rate_limit']['login_window']
);

/* -------------------- Login: comprobar usuario existe + password_verify -------------------- */

$user = trim((string)($_POST['user'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($user === '' || $password === '') {
    json_response(false, 'Usuario y contraseña requeridos', 422);
}

// Comprobar si el usuario existe: SELECT ... WHERE User = ?
// Nota: No validamos formato de usuario ya que cada empresa puede tener su propio formato
$stmt = $pdo->prepare(
    "SELECT ID, User, Password_Hash, Failed_Login_Attempts, Locked_Until
     FROM Admin
     WHERE User = ?
     LIMIT 1"
);
$stmt->execute([$user]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Política de bloqueo por cuenta (desde configuración)
$maxAttempts = $config['account_lockout']['max_attempts'];
$lockMinutes = $config['account_lockout']['lock_duration'];

if (!$admin) {
    db_log($pdo, 'login_failed', "admin_login_failed:user={$user}");
    file_log('admin_login_failed', ['user' => $user, 'reason' => 'invalid_credentials']);
    usleep($config['security']['failed_login_delay']); // retraso anti brute-force configurable
    json_response(false, 'Credenciales inválidas', 401);
}

// Bloqueado
if (!empty($admin['Locked_Until']) && strtotime((string)$admin['Locked_Until']) > time()) {
    db_log($pdo, 'login_failed', "admin_login_locked:user={$user}");
    file_log('admin_login_locked', ['user' => $user]);
    json_response(false, 'Cuenta bloqueada temporalmente', 429);
}

// Contraseña incorrecta
if (!password_verify($password, (string)$admin['Password_Hash'])) {
    $attempts = (int)$admin['Failed_Login_Attempts'] + 1;
    $lockedUntil = null;

    if ($attempts >= $maxAttempts) {
        $lockedUntil = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
    }

    $pdo->prepare("UPDATE Admin SET Failed_Login_Attempts = ?, Locked_Until = ? WHERE ID = ?")
        ->execute([$attempts, $lockedUntil, $admin['ID']]);

    db_log($pdo, 'login_failed', "admin_login_failed:user={$user};attempts={$attempts}");
    file_log('admin_login_failed', ['user' => $user, 'attempts' => $attempts]);

    if ($lockedUntil) {
        json_response(false, "Demasiados intentos. Cuenta bloqueada {$lockMinutes} min.", 429);
    }

    $left = max(0, $maxAttempts - $attempts);
    json_response(false, "Credenciales inválidas. Intentos: {$attempts}/{$maxAttempts} (restantes: {$left})", 401);
}

// OK: rehash si toca
if (password_needs_rehash((string)$admin['Password_Hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE Admin SET Password_Hash = ? WHERE ID = ?")->execute([$newHash, $admin['ID']]);
}

$pdo->prepare("UPDATE Admin SET Failed_Login_Attempts = 0, Locked_Until = NULL, Last_Login = NOW() WHERE ID = ?")
    ->execute([$admin['ID']]);

set_admin_session((int)$admin['ID'], (string)$admin['User'], true);

db_log($pdo, 'login_success', "admin_login_success:user={$user}");
file_log('admin_login_success', ['user' => $user, 'admin_id' => (int)$admin['ID']]);

json_response(true, ['redirect' => 'admin_panel.php']);