<?php
require_once __DIR__ . '/headers.php';
require_once __DIR__ . '/db.php';

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

            json_response(true, [
                'message' => 'Conversación creada',
                'code'    => $code,
            ], 201);
            break;

        case 'check_code':
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                json_response(false, 'Método no permitido', 405);
            }
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

            json_response(true, [
                'message'         => 'Acceso concedido',
                'conversation_id' => $row['ID'],
                'code'            => $code,
            ]);
            break;

        case 'get_messages':
            // Cambiado a POST para no enviar credenciales en query string
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_response(false, 'Método no permitido', 405);
            }
            $code     = trim($_POST['code'] ?? '');
            $password = $_POST['password'] ?? '';
            if ($code === '' || $password === '') {
                json_response(false, 'Código y contraseña son requeridos', 422);
            }

            $stmt = $pdo->prepare('SELECT ID, Password_Hash FROM Conversation WHERE Code = ?');
            $stmt->execute([$code]);
            $conv = $stmt->fetch();
            if (!$conv || !password_verify($password, $conv['Password_Hash'])) {
                json_response(false, 'Credenciales inválidas', 401);
            }

            if (password_needs_rehash($conv['Password_Hash'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE Conversation SET Password_Hash = ? WHERE ID = ?')->execute([$newHash, $conv['ID']]);
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
    json_response(false, 'Error del servidor: ' . $e->getMessage(), 500);
}