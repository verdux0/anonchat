<?php
/**
 * API del chat (AJAX/Fetch) — usa JSON body.
 *
 * Acceso:
 * - Admin: requiere $_SESSION['admin_auth']
 * - Usuario: requiere $_SESSION['authenticated'] y conversation_id/code
 *
 * Acciones:
 * - conversation_details
 * - list_messages (incremental con after_id)
 * - send_message
 * - delete_message
 * - mark_read
 * - typing (señal simple, guardada en sesión)
 * - admin_save_report (solo admin)
 * - admin_list_deleted (solo admin)
 * - admin_set_status (solo admin)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

function str_len($s) {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

function spam_log(string $event, array $context = []): void {
  $ctx = '';
  if (!empty($context)) {
    $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
      $ctx = ' ' . $json;
    }
  }
  error_log('[SPAM] ' . $event . $ctx);
}

function bool_from_mixed($value): bool {
  if (is_bool($value)) return $value;
  if (is_int($value) || is_float($value)) return ((float)$value) !== 0.0;
  if (is_string($value)) {
    $v = strtolower(trim($value));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
  }
  return !empty($value);
}


/**
 * Ejecuta una peticion POST JSON y devuelve el body como texto.
 */
function http_post_json(string $url, array $payload, int $timeoutSeconds = 2): ?string {
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) return null;

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    if ($ch === false) return null;

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) return null;
    return (string)$response;
  }

  $context = stream_context_create([
    'http' => [
      'method' => 'POST',
      'header' => "Content-Type: application/json\r\n",
      'content' => $json,
      'timeout' => $timeoutSeconds,
      'ignore_errors' => true,
    ]
  ]);
  $response = @file_get_contents($url, false, $context);
  if ($response === false) return null;

  $status = 0;
  if (isset($http_response_header[0]) && preg_match('/\\s(\\d{3})\\s/', (string)$http_response_header[0], $m)) {
    $status = (int)$m[1];
  }
  if ($status < 200 || $status >= 300) return null;

  return (string)$response;
}

/**
 * Ejecuta el clasificador anti-spam en un microservicio Python y devuelve metadatos.
 * Formato devuelto: ['ok'=>bool, 'is_spam'=>bool, 'spam_probability'=>?float, 'model'=>?string]
 */
function detect_spam_message(string $text): array {
  if ($text === '') {
    return ['ok' => true, 'is_spam' => false, 'spam_probability' => 0.0, 'model' => null, 'status' => 'empty'];
  }

  $spamApiUrl = trim((string)(getenv('SPAM_API_URL') ?: ''));
  if ($spamApiUrl === '') {
    $spamApiUrl = 'http://127.0.0.1:8000/predict';
  }

  $timeout = (int)(getenv('SPAM_API_TIMEOUT') ?: 4);
  if ($timeout < 1) $timeout = 1;
  if ($timeout > 15) $timeout = 15;

  $responseRaw = http_post_json($spamApiUrl, ['text' => $text], $timeout);
  if ($responseRaw === null) {
    spam_log('predict_call_failed', ['url' => $spamApiUrl, 'timeout' => $timeout]);
    return ['ok' => false, 'is_spam' => false, 'spam_probability' => null, 'model' => null, 'status' => 'request_failed'];
  }

  $json = json_decode($responseRaw, true);
  if (!is_array($json)) {
    spam_log('predict_invalid_json', ['body_preview' => substr($responseRaw, 0, 180)]);
    return ['ok' => false, 'is_spam' => false, 'spam_probability' => null, 'model' => null, 'status' => 'invalid_json'];
  }

  $isSpam = bool_from_mixed($json['is_spam'] ?? false);
  $prob = isset($json['spam_probability']) ? (float)$json['spam_probability'] : null;
  if ($prob !== null) {
    if ($prob < 0) $prob = 0.0;
    if ($prob > 1) $prob = 1.0;
  }

  return [
    'ok' => true,
    'is_spam' => $isSpam,
    'spam_probability' => $prob,
    'model' => isset($json['model']) ? (string)$json['model'] : null,
    'status' => 'ok',
  ];
}


function out(bool $ok, $payload=null, int $code=200): void {
  http_response_code($code);
  echo json_encode([
    'success'=>$ok,
    'data'=>$ok ? $payload : null,
    'error'=>$ok ? null : $payload
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

start_secure_session();

$isAdmin = is_admin_authenticated();
$isUser  = is_user_authenticated();

if (!$isAdmin && !$isUser) out(false, 'No autorizado', 401);

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$csrf = (string)($body['csrf'] ?? '');
if (!validate_csrf_token($csrf, 'chat')) {
  out(false, 'CSRF inválido', 403);
}

$pdo = get_pdo();
$action = (string)($body['action'] ?? '');
$conversationId = (int)($body['conversation_id'] ?? 0);

if ($conversationId <= 0) out(false, 'conversation_id requerido', 422);

// Usuario solo puede acceder a SU conversación
if ($isUser && get_conversation_id() !== $conversationId) {
  out(false, 'No autorizado', 403);
}

// Helpers
function conv_exists(PDO $pdo, int $id): array|false {
  $st = $pdo->prepare("SELECT ID, Code, Status, Title, Description, Created_At, Last_Activity, Expires_At, Creator_IP, Registered_At, Spam_Filter_Enabled, report
                       FROM Conversation WHERE ID=? LIMIT 1");
  $st->execute([$id]);
  $c = $st->fetch(PDO::FETCH_ASSOC);
  return $c ?: false;
}

if ($action === 'conversation_details') {
  $c = conv_exists($pdo, $conversationId);
  if (!$c) out(false, 'Conversación no encontrada', 404);
  out(true, ['conversation' => $c]);
}

if ($action === 'list_messages') {
  $afterId = (int)($body['after_id'] ?? 0);

  // Mensajes activos (no borrados)
  $st = $pdo->prepare("SELECT ID, Sender, Content, File_Path, Created_At, Is_Delivered, Delivered_At, Is_Read, Read_At
                       FROM Messages
                       WHERE Conversation_ID=? AND Deleted_At IS NULL AND ID > ?
                       ORDER BY ID ASC");
  $st->execute([$conversationId, $afterId]);
  $msgs = $st->fetchAll(PDO::FETCH_ASSOC);

  // Marcar como "candidatos a entregado/leído": mensajes del otro lado.
  $mark = [];
  $markDelivered = [];

  foreach ($msgs as $m) {
    // si yo soy admin: mensajes del otro lado = no-admin
    // si yo soy user: mensajes del otro lado = admin
    $sender = $m['Sender'];
    $isOther = $isAdmin ? ($sender !== 'admin') : ($sender === 'admin');
    if ($isOther) {
      $msgId = (int)$m['ID'];
      $mark[] = $msgId;
      $markDelivered[] = $msgId;
    }
  }

  // "Delivered": el mensaje ya llego al cliente receptor (aunque no se haya leido).
  if (!empty($markDelivered)) {
    $placeDelivered = implode(',', array_fill(0, count($markDelivered), '?'));

    if ($isAdmin) {
      $sqlDelivered = "UPDATE Messages SET Is_Delivered=1, Delivered_At=COALESCE(Delivered_At, NOW())
                       WHERE Conversation_ID=? AND Deleted_At IS NULL AND Is_Delivered=0 AND ID IN ($placeDelivered) AND Sender <> 'admin'";
    } else {
      $sqlDelivered = "UPDATE Messages SET Is_Delivered=1, Delivered_At=COALESCE(Delivered_At, NOW())
                       WHERE Conversation_ID=? AND Deleted_At IS NULL AND Is_Delivered=0 AND ID IN ($placeDelivered) AND Sender = 'admin'";
    }

    $paramsDelivered = array_merge([$conversationId], $markDelivered);
    $stDelivered = $pdo->prepare($sqlDelivered);
    $stDelivered->execute($paramsDelivered);
  }

  // Estado compartido: ambos participantes reciben el estado de todos los mensajes
  // para que los ticks sean consistentes entre admin y usuario.
  $stStatus = $pdo->prepare("SELECT ID, Is_Delivered, Delivered_At, Is_Read, Read_At
                             FROM Messages
                             WHERE Conversation_ID=? AND Deleted_At IS NULL
                             ORDER BY ID DESC
                             LIMIT 500");
  $stStatus->execute([$conversationId]);
  $statusUpdates = array_reverse($stStatus->fetchAll(PDO::FETCH_ASSOC));

  // Typing signal (simple en sesión, expira por tiempo)
  $typingKeyOther = $isAdmin ? "typing_user_{$conversationId}" : "typing_admin_{$conversationId}";
  $typingData = $_SESSION[$typingKeyOther] ?? null;
  $otherTyping = false;
  if (is_array($typingData) && !empty($typingData['ts'])) {
    $otherTyping = (time() - (int)$typingData['ts']) <= 3; // 3s de ventana
  }

  out(true, [
    'messages' => $msgs,
    'mark_read_ids' => $mark,
    'status_updates' => $statusUpdates,
    'other_typing' => $otherTyping
  ]);
}

if ($action === 'send_message') {
  $sender = (string)($body['sender'] ?? '');
  $content = trim((string)($body['content'] ?? ''));

  if ($content === '') out(false, 'Mensaje vacío', 422);
  if (str_len($content) > 5000) out(false, 'Mensaje demasiado largo', 422);

  // Sender permitido según rol
  if ($isAdmin) {
    if (!in_array($sender, ['admin','anonymous'], true)) out(false, 'Sender inválido', 422);
  } else {
    // usuario: siempre anonymous (o user si prefieres)
    $sender = 'anonymous';
  }

  // Insert
  $ins = $pdo->prepare("INSERT INTO Messages (Conversation_ID, Sender, Content) VALUES (?,?,?)");
  $ins->execute([$conversationId, $sender, $content]);
  $id = (int)$pdo->lastInsertId();

  // Anti-spam solo para mensajes de usuario/anonimo (nunca admin) y si esta activado en la conversacion.
  $spamMeta = [
    'enabled' => false,
    'analyzed' => false,
    'is_spam' => false,
    'spam_probability' => null,
    'model' => null,
    'source_status' => 'not_applicable'
  ];

  // Anti-spam solo para mensajes de usuario/anonimo (nunca admin) y si esta activado en la conversacion.
  if (!$isAdmin) {
    try {
      $stSpamEnabled = $pdo->prepare("SELECT Spam_Filter_Enabled FROM Conversation WHERE ID = ? LIMIT 1");
      $stSpamEnabled->execute([$conversationId]);
      $spamEnabled = ((int)($stSpamEnabled->fetchColumn() ?: 0)) === 1;
      $spamMeta['enabled'] = $spamEnabled;

      if ($spamEnabled) {
        $spamResult = detect_spam_message($content);
        $isSpam = !empty($spamResult['ok']) && bool_from_mixed($spamResult['is_spam'] ?? false);
        $spamProb = array_key_exists('spam_probability', $spamResult) ? $spamResult['spam_probability'] : null;
        $model = isset($spamResult['model']) ? (string)$spamResult['model'] : null;
        $sourceStatus = isset($spamResult['status']) ? (string)$spamResult['status'] : 'unknown';

        $spamMeta['analyzed'] = !empty($spamResult['ok']);
        $spamMeta['is_spam'] = $isSpam;
        $spamMeta['spam_probability'] = $spamProb;
        $spamMeta['model'] = $model;
        $spamMeta['source_status'] = $sourceStatus;

        $pdo->prepare("INSERT INTO Spam_Detection (Message_ID, Conversation_ID, Is_Spam, Spam_Probability, Model_Name)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$id, $conversationId, $isSpam ? 1 : 0, $spamProb, $model]);

        if ($isSpam) {
          // Regla de negocio: al segundo spam en la conversacion, se archiva automaticamente.
          $stCountSpam = $pdo->prepare("SELECT COUNT(*) FROM Spam_Detection WHERE Conversation_ID = ? AND Is_Spam = 1");
          $stCountSpam->execute([$conversationId]);
          $spamCount = (int)$stCountSpam->fetchColumn();

          if ($spamCount >= 2) {
            $pdo->prepare("UPDATE Conversation SET Status='archived', Updated_At=NOW() WHERE ID = ?")
                ->execute([$conversationId]);
          }
        }
      }
    } catch (Throwable $e) {
      // Failsafe: si la migracion de spam no esta aplicada o el modelo falla,
      // el mensaje no se bloquea y el chat sigue funcionando.
      spam_log('send_message_spam_exception', [
        'conversation_id' => $conversationId,
        'message_id' => $id,
        'error' => $e->getMessage()
      ]);
      $spamMeta['source_status'] = 'exception';
    }
  }

  // actualizar actividad
  $pdo->prepare("UPDATE Conversation SET Last_Activity = NOW(), Updated_At = NOW() WHERE ID = ?")->execute([$conversationId]);

  $st = $pdo->prepare("SELECT ID, Sender, Content, File_Path, Created_At, Is_Delivered, Delivered_At, Is_Read, Read_At
                       FROM Messages WHERE ID=? LIMIT 1");
  $st->execute([$id]);
  $msg = $st->fetch(PDO::FETCH_ASSOC);

  $payload = ['message' => $msg];
  if (!$isAdmin) {
    $payload['spam'] = $spamMeta;
  }

  out(true, $payload, 201);
}

if ($action === 'mark_read') {
  $ids = $body['ids'] ?? [];
  if (!is_array($ids) || count($ids) === 0) out(true, ['updated'=>0]);

  // solo marcar como leído mensajes del otro lado
  $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
  if (!$ids) out(true, ['updated'=>0]);

  $place = implode(',', array_fill(0, count($ids), '?'));

  if ($isAdmin) {
    // admin marca como leídos mensajes no-admin
    $sql = "UPDATE Messages SET Is_Read=1, Read_At=NOW(), Is_Delivered=1, Delivered_At=COALESCE(Delivered_At, NOW())
            WHERE Conversation_ID=? AND Deleted_At IS NULL AND Is_Read=0 AND ID IN ($place) AND Sender <> 'admin'";
  } else {
    // user marca como leídos mensajes admin
    $sql = "UPDATE Messages SET Is_Read=1, Read_At=NOW(), Is_Delivered=1, Delivered_At=COALESCE(Delivered_At, NOW())
            WHERE Conversation_ID=? AND Deleted_At IS NULL AND Is_Read=0 AND ID IN ($place) AND Sender = 'admin'";
  }

  $params = array_merge([$conversationId], $ids);
  $st = $pdo->prepare($sql);
  $st->execute($params);

  out(true, ['updated' => $st->rowCount()]);
}

if ($action === 'typing') {
  $typing = !empty($body['typing']);
  // Guardamos typing en la sesión del servidor (simple; para multi-servidor usar Redis)
  $key = $isAdmin ? "typing_admin_{$conversationId}" : "typing_user_{$conversationId}";
  if ($typing) {
    $_SESSION[$key] = ['ts' => time()];
  } else {
    unset($_SESSION[$key]);
  }
  out(true, ['ok'=>true]);
}

if ($action === 'delete_message' || $action === 'admin_delete_message') {
  $messageId = (int)($body['message_id'] ?? 0);
  if ($messageId <= 0) out(false, 'message_id requerido', 422);

  $st = $pdo->prepare("SELECT ID, Sender, Deleted_At
                       FROM Messages
                       WHERE ID = ? AND Conversation_ID = ?
                       LIMIT 1");
  $st->execute([$messageId, $conversationId]);
  $msg = $st->fetch(PDO::FETCH_ASSOC);
  if (!$msg) out(false, 'Mensaje no encontrado', 404);

  if (!empty($msg['Deleted_At'])) {
    out(true, ['deleted' => false, 'already_deleted' => true]);
  }

  // Permisos:
  // - Admin: puede borrar cualquier mensaje de la conversación.
  // - Usuario: solo puede borrar sus propios mensajes (anonymous/user).
  if (!$isAdmin) {
    $sender = (string)$msg['Sender'];
    if (!in_array($sender, ['anonymous', 'user'], true)) {
      out(false, 'No autorizado para borrar este mensaje', 403);
    }
  }

  $pdo->prepare("UPDATE Messages SET Deleted_At = NOW() WHERE ID = ? AND Deleted_At IS NULL")
      ->execute([$messageId]);

  out(true, ['deleted' => true]);
}

/* -------- Admin-only tools -------- */

if (!$isAdmin) out(false, 'No autorizado', 403);

if ($action === 'admin_save_report') {
  $report = (string)($body['report'] ?? '');
  if (str_len($report) > 10000) out(false, 'Reporte demasiado largo', 422);

  $pdo->prepare("UPDATE Conversation SET report = ?, Updated_At = NOW() WHERE ID = ?")
      ->execute([$report, $conversationId]);

  out(true, ['saved'=>true]);
}

if ($action === 'admin_list_deleted') {
  $st = $pdo->prepare("SELECT ID, Sender, Content, Created_At, Deleted_At
                       FROM Messages
                       WHERE Conversation_ID=? AND Deleted_At IS NOT NULL
                       ORDER BY Deleted_At DESC
                       LIMIT 200");
  $st->execute([$conversationId]);
  out(true, ['messages' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'admin_set_status') {
  $status = (string)($body['status'] ?? '');
  $allowed = ['pending','active','waiting','closed','archived'];
  if (!in_array($status, $allowed, true)) out(false, 'Estado inválido', 422);

  $pdo->prepare("UPDATE Conversation SET Status=?, Updated_At=NOW() WHERE ID=?")->execute([$status, $conversationId]);
  out(true, ['updated'=>true]);
}

if ($action === 'admin_spam_settings') {
  $st = $pdo->prepare("SELECT ID, Spam_Filter_Enabled FROM Conversation WHERE ID = ? LIMIT 1");
  $st->execute([$conversationId]);
  $conv = $st->fetch(PDO::FETCH_ASSOC);
  if (!$conv) out(false, 'Conversación no encontrada', 404);

  out(true, [
    'spam_filter_enabled' => ((int)$conv['Spam_Filter_Enabled']) === 1,
  ]);
}

if ($action === 'admin_set_spam_filter') {
  $enabled = !empty($body['enabled']) ? 1 : 0;

  $pdo->prepare("UPDATE Conversation SET Spam_Filter_Enabled=?, Updated_At=NOW() WHERE ID=?")
      ->execute([$enabled, $conversationId]);

  out(true, ['updated' => true, 'spam_filter_enabled' => $enabled === 1]);
}

if ($action === 'admin_list_spam') {
  $st = $pdo->prepare("SELECT
                          sd.ID,
                          sd.Message_ID,
                          sd.Is_Spam,
                          sd.Spam_Probability,
                          sd.Model_Name,
                          sd.Created_At AS Detected_At,
                          m.Sender,
                          m.Content,
                          m.Created_At AS Message_Created_At
                        FROM Spam_Detection sd
                        JOIN Messages m ON m.ID = sd.Message_ID
                        WHERE sd.Conversation_ID = ?
                        ORDER BY sd.ID DESC
                        LIMIT 300");
  $st->execute([$conversationId]);

  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  out(true, ['messages' => $rows]);
}

out(false, 'Acción no soportada', 400);