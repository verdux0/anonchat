<?php
// Iniciar sesión
require_once __DIR__ . '/api/session.php';

start_secure_session();

// Comprobar las mismas claves que usa la API al autenticar
if (!is_user_authenticated()) {
    header('Location: index.php');
    exit;
}

// Preparar valores seguros para mostrar
$conversationCode = htmlspecialchars(get_conversation_code() ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$sessionId = session_id();
$username = $conversationCode;
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
      <div class="section-title">Sesión</div>
      <div>
        <p class="muted">ID de sesión:</p>
        <div class="code-box"><?php echo $sessionId; ?></div>
      </div>

      <div>
        <p class="muted">Código de conversación:</p>
        <div class="code-box"><?php echo $conversationCode; ?></div>
      </div>

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