<?php
// CSV export for current table + search (simple).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

start_secure_session();

if (!is_admin_authenticated()) {
  http_response_code(401);
  exit('No autorizado');
}

$pdo = get_pdo();

$ALLOWED_TABLES = ['Conversation', 'Admin', 'Messages', 'Rate_Limit', 'Security_Log', 'Active_Messages'];

$table = $_GET['table'] ?? '';
$q = trim((string)($_GET['q'] ?? ''));
$fields = json_decode($_GET['fields'] ?? '[]', true);
if (!is_array($fields)) $fields = [];

if (!in_array($table, $ALLOWED_TABLES, true)) {
  http_response_code(400);
  exit('Tabla no permitida');
}

// Columns
$stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
$stmt->execute([$table]);
$columns = array_map(fn($r) => $r['COLUMN_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC));

// filter fields
$fields = array_values(array_filter($fields, fn($f) => in_array($f, $columns, true)));

$where = '';
$params = [];
if ($q !== '' && count($fields) > 0) {
  $parts = [];
  foreach ($fields as $f) {
    $parts[] = "CAST(`$f` AS CHAR) LIKE ?";
    $params[] = '%' . $q . '%';
  }
  $where = 'WHERE ' . implode(' OR ', $parts);
}

$sql = "SELECT * FROM `$table` $where";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $table . '-' . date('Y-m-d_H-i') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, $columns);
foreach ($rows as $r) {
  $line = [];
  foreach ($columns as $c) $line[] = $r[$c] ?? '';
  fputcsv($out, $line);
}
fclose($out);