<?php
declare(strict_types=1);

// Admin login (SIN registro)

require_once __DIR__ . '/api/session.php';

start_secure_session();

// Si ya está logueado -> panel
if (is_admin_authenticated()) {
    header('Location: admin_panel.php');
    exit;
}

// CSRF token
$csrf = get_csrf_token('admin');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Admin - AnonChat</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="static/css/style.css">
</head>
<body data-csrf="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
  <div class="page">
    <header>
      <span class="badge">AnonChat • Admin</span>
      <h1>Acceso de administrador</h1>
      <p class="lead">Inicia sesión para acceder al panel.</p>
    </header>

    <div class="panel">
      <form id="form-login">
        <h3 class="section-title">Login</h3>

        <label>Usuario
          <input type="text" name="user" required autocomplete="username" placeholder="admin">
        </label>

        <label>Contraseña
          <input type="password" name="password" required autocomplete="current-password" placeholder="password">
        </label>

        <p class="hint">Por seguridad, la contraseña debe tener al menos 12 caracteres.</p>

        <button class="primary" type="submit">Entrar</button>
        <p id="login-msg" class="muted" role="status" aria-live="polite"></p>
      </form>
    </div>

    <footer>AnonChat • Panel de administración</footer>
  </div>

  <script>
    const csrfToken = document.body.dataset.csrf;
    const api = 'api/admin_api.php';

    const formLogin = document.getElementById('form-login');
    const loginMsg = document.getElementById('login-msg');

    formLogin.addEventListener('submit', async (e) => {
      e.preventDefault();

      const user = formLogin.user.value.trim();
      const pass = formLogin.password.value;



      loginMsg.textContent = 'Validando...';

      const fd = new FormData(formLogin);
      fd.append('csrf_token', csrfToken);
      fd.append('action', 'login'); // acción en el body (NO por GET)

      const res = await fetch(api, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });

      const data = await res.json().catch(() => ({ success:false, error:'Respuesta inválida del servidor' }));
      if (!data.success) {
        loginMsg.textContent = data.error || 'Error';
        return;
      }
      window.location.href = data.data.redirect;
    });
  </script>
</body>
</html>