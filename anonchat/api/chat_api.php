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
  $st = $pdo->prepare("SELECT ID, Code, Status, Title, Description, Created_At, Last_Activity, Expires_At, Creator_IP, Registered_At, report
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
  $st = $pdo->prepare("SELECT ID, Sender, Content, File_Path, Created_At, Is_Read, Read_At
                       FROM Messages
                       WHERE Conversation_ID=? AND Deleted_At IS NULL AND ID > ?
                       ORDER BY ID ASC");
  $st->execute([$conversationId, $afterId]);
  $msgs = $st->fetchAll(PDO::FETCH_ASSOC);

  // Marcar como "candidatos a leído": los del otro lado (en cliente se llama mark_read)
  $mark = [];
  $viewerSender = $isAdmin ? 'admin' : 'anonymous';

  foreach ($msgs as $m) {
    // si yo soy admin, marco como leído los de anonymous/user
    // si yo soy user, marco como leído los de admin
    $sender = $m['Sender'];
    $isOther = $isAdmin ? ($sender !== 'admin') : ($sender === 'admin');
    if ($isOther) $mark[] = (int)$m['ID'];
  }

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

  // actualizar actividad
  $pdo->prepare("UPDATE Conversation SET Last_Activity = NOW(), Updated_At = NOW() WHERE ID = ?")->execute([$conversationId]);

  $st = $pdo->prepare("SELECT ID, Sender, Content, File_Path, Created_At, Is_Read, Read_At
                       FROM Messages WHERE ID=? LIMIT 1");
  $st->execute([$id]);
  $msg = $st->fetch(PDO::FETCH_ASSOC);

  out(true, ['message' => $msg], 201);
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
    $sql = "UPDATE Messages SET Is_Read=1, Read_At=NOW()
            WHERE Conversation_ID=? AND Deleted_At IS NULL AND Is_Read=0 AND ID IN ($place) AND Sender <> 'admin'";
  } else {
    // user marca como leídos mensajes admin
    $sql = "UPDATE Messages SET Is_Read=1, Read_At=NOW()
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

out(false, 'Acción no soportada', 400);