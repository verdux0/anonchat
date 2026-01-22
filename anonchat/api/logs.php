<?php
/**
 * logs.php - Sistema de logging para AnonChat
 * 
 * Proporciona funciones para registrar eventos de seguridad y actividad
 * tanto en archivos de log como en la base de datos.
 * 
 * Funciones:
 * - ensure_logs_dir(): Asegura que el directorio de logs existe
 * - client_ip(): Obtiene la IP del cliente
 * - user_agent(): Obtiene el User-Agent del cliente
 * - file_log(): Registra eventos en archivos de log
 * - db_log(): Registra eventos en la base de datos
 */

declare(strict_types=1);

/**
 * Obtiene la dirección IP del cliente
 * Considera proxies y headers X-Forwarded-For
 * 
 * @return string IP del cliente o 'unknown' si no se puede determinar
 */
function client_ip(): string {
    // Prioridad: X-Forwarded-For (si existe), luego REMOTE_ADDR
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Obtiene el User-Agent del cliente
 * Limita la longitud a 255 caracteres para compatibilidad con DB
 * 
 * @return string User-Agent del cliente o 'unknown' si no está disponible
 */
function user_agent(): string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
}

/**
 * Asegura que el directorio de logs existe y lo crea si es necesario
 * 
 * @return string Ruta absoluta al directorio de logs
 */
function ensure_logs_dir(): string {
    // Obtiene el directorio padre de api/ (es decir, anonchat/)
    $root = realpath(__DIR__ . '/..'); 
    $dir  = $root . DIRECTORY_SEPARATOR . 'logs';
    
    // Crea el directorio si no existe (permisos 0750: rwxr-x---)
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    
    return $dir;
}

/**
 * Registra un evento en un archivo de log
 * Los logs se organizan por fecha: security-YYYY-MM-DD.log
 * 
 * @param string $eventType Tipo de evento (ej: 'login_success', 'login_failed')
 * @param array $data Datos adicionales del evento
 * @return void
 */
function file_log(string $eventType, array $data = []): void {
    $logDir = ensure_logs_dir();
    $fileName = 'security-' . date('Y-m-d') . '.log';
    $file = $logDir . DIRECTORY_SEPARATOR . $fileName;
    
    // Construye la línea de log en formato JSON
    $logEntry = [
        'ts'    => date('c'), // ISO 8601 timestamp
        'event' => $eventType,
        'ip'    => client_ip(),
        'ua'    => user_agent(),
        'data'  => $data,
    ];
    
    $line = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // Escribe al archivo con bloqueo para evitar condiciones de carrera
    @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Registra un evento en la base de datos (tabla Security_Log)
 * Solo permite tipos de eventos predefinidos por seguridad
 * 
 * @param PDO $pdo Conexión a la base de datos
 * @param string $eventType Tipo de evento (debe estar en la lista permitida)
 * @param string|null $details Detalles adicionales del evento
 * @return void
 */
function db_log(PDO $pdo, string $eventType, ?string $details = null): void {
    // Lista de tipos de eventos permitidos (whitelist)
    $allowed = [
        'login_success',
        'login_failed',
        'conversation_created',
        'suspicious_activity'
    ];
    
    // Si el tipo de evento no está permitido, usar 'suspicious_activity' como fallback
    if (!in_array($eventType, $allowed, true)) {
        $eventType = 'suspicious_activity';
    }
    
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO Security_Log (Event_Type, IP_Address, User_Agent, Details)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $eventType,
            client_ip(),
            user_agent(),
            $details
        ]);
    } catch (PDOException $e) {
        // Si falla la inserción en DB, al menos intentar log en archivo
        @file_log('db_log_error', ['error' => $e->getMessage(), 'event_type' => $eventType]);
    }
}

/**
 * Registra un evento tanto en archivo como en base de datos
 * Función de conveniencia para logging completo
 * 
 * @param PDO $pdo Conexión a la base de datos
 * @param string $eventType Tipo de evento
 * @param array $fileData Datos adicionales para el log de archivo
 * @param string|null $dbDetails Detalles para el log de base de datos
 * @return void
 */
function log_event(PDO $pdo, string $eventType, array $fileData = [], ?string $dbDetails = null): void {
    file_log($eventType, $fileData);
    db_log($pdo, $eventType, $dbDetails);
}
