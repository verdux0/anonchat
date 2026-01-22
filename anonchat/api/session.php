<?php
/**
 * session.php - Funciones compartidas para manejo de sesiones
 * 
 * Proporciona funciones reutilizables para iniciar sesiones de forma segura
 * en todos los archivos de la API y del sistema.
 * 
 * Centraliza:
 * - Inicio de sesión seguro
 * - Verificación de autenticación (admin/usuario)
 * - Generación y validación de tokens CSRF
 * - Regeneración de ID de sesión
 * - Establecimiento de variables de sesión comunes
 */

declare(strict_types=1);

/**
 * Verifica si la conexión es HTTPS
 * 
 * @return bool true si es HTTPS, false en caso contrario
 */
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

/**
 * Inicia una sesión segura con todas las configuraciones de seguridad
 * Verifica si la sesión ya está activa antes de intentar iniciarla
 * 
 * @return void
 */
function start_secure_session(): void {
    // Evitar iniciar sesión si ya está activa (previene warnings/errores)
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = is_https();

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,  
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_start();
}

/**
 * Verifica si el usuario actual es un administrador autenticado
 * 
 * @return bool true si es admin autenticado, false en caso contrario
 */
function is_admin_authenticated(): bool {
    return !empty($_SESSION['admin_auth']) && !empty($_SESSION['admin_id']);
}

/**
 * Verifica si el usuario actual es un usuario normal autenticado
 * 
 * @return bool true si es usuario autenticado, false en caso contrario
 */
function is_user_authenticated(): bool {
    return !empty($_SESSION['authenticated']) 
        && !empty($_SESSION['conversation_id']) 
        && !empty($_SESSION['conversation_code']);
}

/**
 * Obtiene el ID del administrador actual
 * 
 * @return int|null ID del admin o null si no está autenticado
 */
function get_admin_id(): ?int {
    return is_admin_authenticated() ? (int)$_SESSION['admin_id'] : null;
}

/**
 * Obtiene el nombre de usuario del administrador actual
 * 
 * @return string|null Nombre del admin o null si no está autenticado
 */
function get_admin_user(): ?string {
    return is_admin_authenticated() ? (string)($_SESSION['admin_user'] ?? null) : null;
}

/**
 * Obtiene el ID de conversación del usuario actual
 * 
 * @return int|null ID de conversación o null si no está autenticado
 */
function get_conversation_id(): ?int {
    return is_user_authenticated() ? (int)$_SESSION['conversation_id'] : null;
}

/**
 * Obtiene el código de conversación del usuario actual
 * 
 * @return string|null Código de conversación o null si no está autenticado
 */
function get_conversation_code(): ?string {
    return is_user_authenticated() ? (string)$_SESSION['conversation_code'] : null;
}

/**
 * Genera o obtiene un token CSRF para un contexto específico
 * 
 * @param string $context Contexto del token (ej: 'admin', 'admin_panel', 'chat', 'default')
 * @return string Token CSRF
 */
function get_csrf_token(string $context = 'default'): string {
    $key = 'csrf_' . $context;
    
    if (empty($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION[$key];
}

/**
 * Valida un token CSRF para un contexto específico
 * 
 * @param string $token Token a validar
 * @param string $context Contexto del token (ej: 'admin', 'admin_panel', 'chat', 'default')
 * @return bool true si el token es válido, false en caso contrario
 */
function validate_csrf_token(string $token, string $context = 'default'): bool {
    $key = 'csrf_' . $context;
    
    if (empty($_SESSION[$key]) || $token === '') {
        return false;
    }
    
    return hash_equals($_SESSION[$key], $token);
}

/**
 * Establece la sesión de administrador después de un login exitoso
 * 
 * @param int $adminId ID del administrador
 * @param string $adminUser Nombre de usuario del administrador
 * @param bool $regenerateId Si true, regenera el ID de sesión (anti session fixation)
 * @return void
 */
function set_admin_session(int $adminId, string $adminUser, bool $regenerateId = true): void {
    if ($regenerateId) {
        session_regenerate_id(true);
    }
    
    $_SESSION['admin_auth'] = true;
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_user'] = $adminUser;
    $_SESSION['admin_last_activity'] = time();
}

/**
 * Establece la sesión de usuario después de autenticación exitosa
 * 
 * @param int $conversationId ID de la conversación
 * @param string $conversationCode Código de la conversación
 * @return void
 */
function set_user_session(int $conversationId, string $conversationCode): void {
    $_SESSION['authenticated'] = true;
    $_SESSION['conversation_id'] = $conversationId;
    $_SESSION['conversation_code'] = $conversationCode;
}

/**
 * Destruye la sesión actual y limpia todas las variables
 * 
 * @return void
 */
function destroy_session(): void {
    $_SESSION = [];
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    
    session_destroy();
}
