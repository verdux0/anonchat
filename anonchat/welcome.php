<?php
// Iniciar sesión
require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/db.php';

start_secure_session();

// Comprobar las mismas claves que usa la API al autenticar
if (!is_user_authenticated()) {
    header('Location: index.php');
    exit;
}

$conversationCodeRaw = (string)(get_conversation_code() ?? '');
$conversationCode = htmlspecialchars($conversationCodeRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$sessionId = htmlspecialchars(session_id(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$username = 'usuario';

$revealError = '';
$allowReveal = false;
$revealCsrf = get_csrf_token('welcome_reveal');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  $password = (string)($_POST['password'] ?? '');

  if (!validate_csrf_token($csrf, 'welcome_reveal')) {
    $revealError = 'CSRF inválido.';
  } elseif ($password === '') {
    $revealError = 'Debes introducir la contraseña.';
  } else {
    $conversationId = get_conversation_id();
    if ($conversationId === null) {
      $revealError = 'Sesión inválida.';
    } else {
      $pdo = get_pdo();
      $st = $pdo->prepare('SELECT Password_Hash FROM Conversation WHERE ID = ? LIMIT 1');
      $st->execute([$conversationId]);
      $hash = (string)($st->fetchColumn() ?: '');
      if ($hash !== '' && password_verify($password, $hash)) {
        $allowReveal = true;
      } else {
        $revealError = 'Contraseña incorrecta.';
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Bienvenido - AnonChat</title>
  <link rel="stylesheet" href="static/css/style.css">
</head>
<body>
  <div class="page welcome-panel">
    <header>
      <div class="badge">AnonChat</div>
      <h1>Bienvenido, <?php echo $username; ?></h1>
      <p class="lead">Has iniciado sesión correctamente.</p>
    </header>

    <div class="panel">
      <div class="section-title">Privacidad</div>
      <p class="muted">Los datos sensibles no se muestran automáticamente.</p>

      <form method="post" autocomplete="off" style="margin-top:12px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($revealCsrf, ENT_QUOTES, 'UTF-8'); ?>">
        <label>Contraseña para ver datos sensibles
          <input type="password" name="password" required placeholder="Introduce tu contraseña">
        </label>
        <button class="primary" type="submit" style="margin-top:10px;">Mostrar datos</button>
      </form>

      <?php if ($revealError !== ''): ?>
        <p class="muted" style="color:#b42318;"><?php echo htmlspecialchars($revealError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
      <?php endif; ?>

      <?php if ($allowReveal): ?>
        <div style="margin-top:12px;">
          <p class="muted">ID de sesión:</p>
          <div class="code-box"><?php echo $sessionId; ?></div>
        </div>

        <div>
          <p class="muted">Código de conversación:</p>
          <div class="code-box"><?php echo $conversationCode; ?></div>
        </div>
      <?php endif; ?>

      <div class="divider"></div>

      <div class="row">
        <a class="primary" href="chat.php">Chat</a>
        <a class="alert" href="logout.php">Cerrar sesión</a>
      </div>
    </div>

    <footer>
      <p class="muted">Si no esperabas ver esta página, cierra sesión y revisa tu navegador.</p>
    </footer>
  </div>
</body>
</html>