<?php
require_once __DIR__ . '/headers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

start_secure_session();

$pdo = get_pdo();
function str_len($s) {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

function json_response($status, $data = null, $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $status,
        'data'    => $status ? $data : null,
        'error'   => $status ? null : $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_ip_simple(): string {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $v = trim((string)$_SERVER[$k]);
            if ($k === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $v);
                $v = trim((string)($parts[0] ?? ''));
            }
            if ($v !== '') return $v;
        }
    }
    return '0.0.0.0';
}

function rate_limit_api(PDO $pdo, string $actionType, int $maxAttempts, int $windowSeconds): void {
    $ip = client_ip_simple();
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

    if (($now - $windowStart) > $windowSeconds) {
        $pdo->prepare("UPDATE Rate_Limit SET Attempt_Count = 1, Window_Start = NOW() WHERE ID = ?")
            ->execute([$row['ID']]);
        return;
    }

    $attempts = (int)$row['Attempt_Count'] + 1;
    $pdo->prepare("UPDATE Rate_Limit SET Attempt_Count = ? WHERE ID = ?")
        ->execute([$attempts, $row['ID']]);

    if ($attempts > $maxAttempts) {
        json_response(false, 'Demasiadas solicitudes. Intenta de nuevo más tarde.', 429);
    }
}

/**
 * Verifica autenticación por sesión o por credenciales (code + password)
 * Retorna array con datos de la conversación o false si falla
 */
function verify_conversation_auth(PDO $pdo, ?string $code = null, ?string $password = null): array|false {
    // Primero intentar autenticación por sesión
    if (isset($_SESSION['conversation_code']) && isset($_SESSION['conversation_id'])) {
        $sessionCode = $_SESSION['conversation_code'];
        $stmt = $pdo->prepare('SELECT ID, Code, Status FROM Conversation WHERE Code = ? AND ID = ?');
        $stmt->execute([$sessionCode, $_SESSION['conversation_id']]);
        $conv = $stmt->fetch();
        if ($conv) {
            return $conv;
        }
    }
    
    // Si no hay sesión válida, verificar credenciales
    if ($code !== null && $code !== '' && $password !== null && $password !== '') {
        $stmt = $pdo->prepare('SELECT ID, Code, Password_Hash, Status FROM Conversation WHERE Code = ?');
        $stmt->execute([$code]);
        $conv = $stmt->fetch();
        if ($conv && password_verify($password, $conv['Password_Hash'])) {
            // Rehash si es necesario
            if (password_needs_rehash($conv['Password_Hash'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE Conversation SET Password_Hash = ? WHERE ID = ?')->execute([$newHash, $conv['ID']]);
            }
            return $conv;
        }
    }
    
    return false;
}

function base36_from_int(int $ref, array $digits): string {
    $base = count($digits);
    if ($ref === 0) return $digits[0];
    $out = '';
    while ($ref > 0) {
        $out .= $digits[$ref % $base];
        $ref = intdiv($ref, $base);
    }
    return strrev($out);
}

function generate_secure_code(PDO $pdo): string {
    $digits = ["0","1","2","3","4","5","6","7","8","9","A","B","C","D","E","F","G","H",
               "I","J","K","L","M","N","O","P","Q","R","S","T","U","V","W","X","Y","Z"];
    do {
        $timePart = base36_from_int(time(), $digits);
        $randInt  = unpack('N', random_bytes(4))[1] & 0x7fffffff; // 31 bits
        $randPart = base36_from_int($randInt, $digits);
        // Combina tiempo + aleatorio para evitar predictibilidad
        $code = $timePart . $randPart;

        $stmt = $pdo->prepare('SELECT 1 FROM Conversation WHERE Code = ?');
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn());

    return $code;
}

function validate_password(string $pwd): bool {
    return str_len($pwd) >= 8;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'create_conversation':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_response(false, 'Método no permitido', 405);
            }
            $csrf = (string)($_POST['csrf_token'] ?? '');
            if (!validate_csrf_token($csrf, 'default')) {
                json_response(false, 'CSRF inválido', 403);
            }
            rate_limit_api($pdo, 'user_create_conversation', 20, 300);

            $description = trim($_POST['description'] ?? '');
            $password    = $_POST['password'] ?? '';
            $password2   = $_POST['password_confirm'] ?? '';

            if ($description === '' || $password === '' || $password2 === '') {
                json_response(false, 'Campos requeridos faltantes', 422);
            }
            if (str_len($description) > 500) {
                json_response(false, 'Descripción demasiado larga (máx 500 caracteres)', 422);
            }
            if ($password !== $password2) {
                json_response(false, 'Las contraseñas no coinciden', 422);
            }
            if (!validate_password($password)) {
                json_response(false, 'La contraseña debe tener al menos 8 caracteres', 422);
            }

            $code = generate_secure_code($pdo);
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $insert = $pdo->prepare('INSERT INTO Conversation (Code, Password_Hash, Status, Description) VALUES (?, ?, ?, ?)');
            $insert->execute([$code, $hash, 'active', $description]);
            
            // Obtener el ID de la conversación recién creada
            $conversationId = $pdo->lastInsertId();
            
            set_user_session((int)$conversationId, $code, true);

            json_response(true, [
                'message' => 'Conversación creada',
                'code'    => $code,
                'conversation_id' => $conversationId,
            ], 201);
            break;

        case 'check_code':
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                json_response(false, 'Método no permitido', 405);
            }
            rate_limit_api($pdo, 'user_check_code', 60, 300);
            $code = trim($_GET['code'] ?? '');
            if ($code === '') {
                json_response(false, 'Código requerido', 422);
            }
            $stmt = $pdo->prepare('SELECT ID, Status FROM Conversation WHERE Code = ?');
            $stmt->execute([$code]);
            $row = $stmt->fetch();
            if (!$row) {
                // Mensaje genérico para evitar enumeración
                json_response(false, 'Código no válido o no disponible', 404);
            }
            json_response(true, ['exists' => true, 'status' => $row['Status']]);
            break;

        case 'continue_conversation':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_response(false, 'Método no permitido', 405);
            }
            $csrf = (string)($_POST['csrf_token'] ?? '');
            if (!validate_csrf_token($csrf, 'default')) {
                json_response(false, 'CSRF inválido', 403);
            }
            rate_limit_api($pdo, 'user_continue_conversation', 30, 300);

            $code     = trim($_POST['code'] ?? '');
            $password = $_POST['password'] ?? '';
            if ($code === '' || $password === '') {
                json_response(false, 'Código y contraseña son requeridos', 422);
            }

            $stmt = $pdo->prepare('SELECT ID, Password_Hash, Status FROM Conversation WHERE Code = ?');
            $stmt->execute([$code]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($password, $row['Password_Hash'])) {
                // Respuesta unificada para evitar enumeración/brute-force
                json_response(false, 'Credenciales inválidas', 401);
            }

            // Rehash si el algoritmo por defecto cambia en el futuro
            if (password_needs_rehash($row['Password_Hash'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE Conversation SET Password_Hash = ? WHERE ID = ?')->execute([$newHash, $row['ID']]);
            }

            $pdo->prepare('UPDATE Conversation SET Status = ?, Updated_At = NOW() WHERE ID = ?')
                ->execute(['active', $row['ID']]);

            set_user_session((int)$row['ID'], $code, true);

            json_response(true, [
                'message'         => 'Acceso concedido',
                'conversation_id' => $row['ID'],
                'code'            => $code,
            ]);
            break;

        case 'get_messages':
            // Acepta autenticación por sesión o por credenciales
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_response(false, 'Método no permitido', 405);
            }
            $code     = trim($_POST['code'] ?? '');
            $password = $_POST['password'] ?? '';
            
            // Verificar autenticación (por sesión o por credenciales)
            $conv = verify_conversation_auth($pdo, $code !== '' ? $code : null, $password !== '' ? $password : null);
            if (!$conv) {
                json_response(false, 'No autorizado. Requiere sesión activa o credenciales válidas', 401);
            }

            $msgStmt = $pdo->prepare('SELECT ID, Sender, Content, File_Path, Created_At FROM Messages WHERE Conversation_ID = ? ORDER BY Created_At ASC');
            $msgStmt->execute([$conv['ID']]);
            $messages = $msgStmt->fetchAll();

            json_response(true, ['messages' => $messages]);
            break;

        default:
            json_response(false, 'Acción no soportada', 400);
    }
} catch (Exception $e) {
    json_response(false, 'Error interno del servidor', 500);
}