<?php
// Minimal backend for admin_panel.php (list/get/update/delete_many/undo_delete).
// NOTE: This is a compact starter. Tighten allowlists as you add features.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

// Iniciar sesión de forma segura (verifica si ya está activa)
start_secure_session();

function out($ok, $data = null, $code = 200){
  http_response_code($code);
  echo json_encode([
    'success' => $ok,
    'data' => $ok ? $data : null,
    'error' => $ok ? null : $data,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (!is_admin_authenticated()) {
  out(false, 'No autorizado', 401);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
if (!validate_csrf_token($body['csrf'] ?? '', 'admin_panel')) {
  out(false, 'CSRF inválido', 403);
}

$pdo = get_pdo();

// Allowlist tables (important!)
$ALLOWED_TABLES = ['Conversation', 'Admin', 'Messages', 'Rate_Limit', 'Security_Log', 'Active_Messages'];

$action = $body['action'] ?? '';
$table  = $body['table'] ?? null;

function is_allowed_table($t, $allow){ return in_array($t, $allow, true); }

// Determine ID column (simple heuristic)
function id_column_for(string $table): string {
  return match($table){
    'Conversation' => 'ID',
    'Admin' => 'ID',
    'Messages' => 'ID',
    'Rate_Limit' => 'ID',
    'Security_Log' => 'ID',
    'Active_Messages' => 'ID',
    default => 'ID'
  };
}

// List columns safely using INFORMATION_SCHEMA
function get_columns(PDO $pdo, string $table): array {
  $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
  $stmt->execute([$table]);
  return array_map(fn($r) => $r['COLUMN_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Basic list (with optional multi-field LIKE search)
if ($action === 'list') {
  if (!is_allowed_table($table, $ALLOWED_TABLES)) out(false, 'Tabla no permitida', 400);

  $page = max(1, (int)($body['page'] ?? 1));
  $pageSize = min(100, max(5, (int)($body['pageSize'] ?? 25)));
  $offset = ($page - 1) * $pageSize;

  $columns = get_columns($pdo, $table);
  $idCol = id_column_for($table);

  $q = trim((string)($body['q'] ?? ''));
  $fields = $body['fields'] ?? [];
  if (!is_array($fields)) $fields = [];

  // allow only existing columns
  $fields = array_values(array_filter($fields, fn($f) => in_array($f, $columns, true)));

  $where = '';
  $params = [];

  if ($q !== '' && count($fields) > 0) {
    $parts = [];
    foreach ($fields as $f) {
      // Use CAST for non-text to keep it simple
      $parts[] = "CAST(`$f` AS CHAR) LIKE ?";
      $params[] = '%' . $q . '%';
    }
    $where = 'WHERE ' . implode(' OR ', $parts);
  }

  // total
  $countSql = "SELECT COUNT(*) AS c FROM `$table` $where";
  $stmt = $pdo->prepare($countSql);
  $stmt->execute($params);
  $totalRows = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];

  $totalPages = max(1, (int)ceil($totalRows / $pageSize));

  // rows
  $sql = "SELECT * FROM `$table` $where ORDER BY `$idCol` DESC LIMIT $pageSize OFFSET $offset";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  out(true, [
    'columns' => $columns,
    'rows' => $rows,
    'idColumn' => $idCol,
    'page' => $page,
    'pageSize' => $pageSize,
    'totalRows' => $totalRows,
    'totalPages' => $totalPages
  ]);
}

// Get single record
if ($action === 'get') {
  if (!is_allowed_table($table, $ALLOWED_TABLES)) out(false, 'Tabla no permitida', 400);
  $idCol = id_column_for($table);
  $id = $body['id'] ?? null;
  if ($id === null || $id === '') out(false, 'ID requerido', 422);

  $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$idCol` = ? LIMIT 1");
  $stmt->execute([$id]);
  $rec = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$rec) out(false, 'No encontrado', 404);

  // Make some fields readonly for safety
  $readonly = [$idCol];
  if ($table === 'Admin') $readonly[] = 'Password_Hash';

  out(true, ['record' => $rec, 'readonlyFields' => $readonly]);
}

// Update record (simple: updates provided fields except readonly)
if ($action === 'update') {
  if (!is_allowed_table($table, $ALLOWED_TABLES)) out(false, 'Tabla no permitida', 400);
  $idCol = id_column_for($table);
  $id = $body['id'] ?? null;
  $patch = $body['patch'] ?? null;
  if ($id === null || $id === '' || !is_array($patch)) out(false, 'Datos inválidos', 422);

  $columns = get_columns($pdo, $table);
  $readonly = [$idCol];
  if ($table === 'Admin') $readonly[] = 'Password_Hash';

  $sets = [];
  $params = [];
  foreach ($patch as $k => $v) {
    if (!in_array($k, $columns, true)) continue;
    if (in_array($k, $readonly, true)) continue;
    $sets[] = "`$k` = ?";
    $params[] = $v;
  }

  if (!$sets) out(false, 'Nada que actualizar', 422);

  $params[] = $id;
  $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE `$idCol` = ? LIMIT 1";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  out(true, ['updated' => true]);
}

// Bulk delete (HARD delete) + undo token (stores deleted rows in session for short time)
if ($action === 'delete_many') {
  if (!is_allowed_table($table, $ALLOWED_TABLES)) out(false, 'Tabla no permitida', 400);
  $idCol = id_column_for($table);
  $ids = $body['ids'] ?? [];
  if (!is_array($ids) || count($ids) === 0) out(false, 'IDs requeridos', 422);

  // Fetch rows for undo
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$idCol` IN ($placeholders)");
  $stmt->execute($ids);
  $deletedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Delete
  $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `$idCol` IN ($placeholders)");
  $stmt->execute($ids);

  // Save undo payload in session (short-lived)
  $token = bin2hex(random_bytes(16));
  $_SESSION['undo'][$token] = [
    'table' => $table,
    'rows' => $deletedRows,
    'created' => time(),
  ];

  out(true, ['deleted' => count($ids), 'undoToken' => $token]);
}

if ($action === 'undo_delete') {
  $token = $body['undoToken'] ?? '';
  if ($token === '' || empty($_SESSION['undo'][$token])) out(false, 'Nada que deshacer', 404);

  $payload = $_SESSION['undo'][$token];
  unset($_SESSION['undo'][$token]);

  // Expire after 60s
  if (time() - (int)$payload['created'] > 60) out(false, 'Expiró el deshacer', 410);

  $table = $payload['table'];
  if (!is_allowed_table($table, $ALLOWED_TABLES)) out(false, 'Tabla no permitida', 400);

  $rows = $payload['rows'];
  if (!is_array($rows) || count($rows) === 0) out(false, 'Nada que deshacer', 404);

  // Re-insert (best-effort). Requires all columns to match.
  $columns = get_columns($pdo, $table);

  $pdo->beginTransaction();
  try {
    foreach ($rows as $r) {
      $cols = [];
      $vals = [];
      $params = [];
      foreach ($columns as $c) {
        if (array_key_exists($c, $r)) {
          $cols[] = "`$c`";
          $vals[] = "?";
          $params[] = $r[$c];
        }
      }
      $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    out(false, 'No se pudo deshacer', 500);
  }

  out(true, ['restored' => count($rows)]);
}

out(false, 'Acción no soportada', 400);
