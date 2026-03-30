<?php
require_once __DIR__ . '/api/session.php';

start_secure_session();

// Si hay sesión activa con conversación, redirigir a welcome.php
if (isset($_SESSION['authenticated']) && isset($_SESSION['conversation_code']) && isset($_SESSION['conversation_id'])) {
    header('Location: welcome.php');
    exit;
}

// CSRF token para peticiones POST
$csrf = get_csrf_token('default');
?>
<!doctype html>
<html lang="es">
<head>
  <link rel="icon" type="image/x-icon" href="/anonchat/static/img/favicon.png">
  <meta charset="utf-8">
  <title>AnonChat - Inicio</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/style.css">
</head>
<body data-csrf="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
  <div class="page">
    <header>
      <span class="badge">AnonChat • Privado y seguro</span>
      <h1>Tu espacio anónimo para conversar</h1>
      <p class="lead">Crea o retoma conversaciones protegidas con contraseña y un código único.</p>
    </header>

    <div class="panel">
      <div class="actions">
        <button id="btn-new" class="primary">Empezar nueva conversación</button>
        <button id="btn-continue" class="ghost">Continuar conversación</button>
      </div>

      <div class="row">
        <!-- Flujo: Nueva conversación -->
        <form id="form-new-step1" class="hidden">
          <h3 class="section-title">Paso 1: Descripción breve</h3>
          <label>Descripción
            <textarea name="description" rows="3" required placeholder="Ej: Tema, contexto o palabras clave"></textarea>
          </label>
          <p class="hint">Solo tú y tu interlocutor conocerán este contexto.</p>
          <button type="submit" class="primary">Continuar</button>
        </form>

        <form id="form-new-step2" class="hidden">
          <h3 class="section-title">Paso 2: Establece contraseña</h3>
          <label>Contraseña
            <input type="password" name="password" required placeholder="Mínimo 6 caracteres">
          </label>
          <label>Confirma contraseña
            <input type="password" name="password_confirm" required placeholder="Repite la contraseña">
      const res = await fetch(apiBase + '/conversations', {
          <button type="submit" class="primary">Crear conversación</button>
        </form>

        <div id="new-result" class="result hidden">
          <h3 class="section-title">Conversación creada</h3>
          <p class="muted">Guarda este código de acceso único. Lo necesitarás para continuar la conversación.</p>
          <div class="code-box" id="new-code"></div>
          <p class="muted"><em>Copia o guarda este código de forma segura.</em></p>
        </div>
      </div>

      <div class="divider"></div>

      <div class="row">
        <!-- Flujo: Continuar conversación -->
        <form id="form-continue-step1" class="hidden">
          <h3 class="section-title">Continuar - Paso 1</h3>
          <label>Código de conversación
            <input type="text" name="code" required placeholder="Pega tu código aquí">
          </label>
      const res = await fetch(`${apiBase}/conversations/validate?code=${encodeURIComponent(code)}`, {
        </form>

        <form id="form-continue-step2" class="hidden">
          <h3 class="section-title">Continuar - Paso 2</h3>
          <p id="continue-code-display" class="tag"></p>
          <label>Contraseña
            <input type="password" name="password" required placeholder="Tu contraseña de acceso">
          </label>
          <button type="submit" class="primary">Acceder</button>
        </form>

        <div id="continue-result" class="result hidden">
          <h3 class="section-title">Acceso concedido</h3>
          <p class="muted">Validado. Aquí cargarías la interfaz del chat.</p>
        </div>
      </div>

    <footer>AnonChat • Mantén tu privacidad y control</footer>
  </div>

  <script>
    const apiBase = '/api/v1';
    const csrfToken = document.body.dataset.csrf;

    const btnNew = document.getElementById('btn-new');
    const btnContinue = document.getElementById('btn-continue');

    const formNew1 = document.getElementById('form-new-step1');
    const formNew2 = document.getElementById('form-new-step2');
    const newResult = document.getElementById('new-result');
    const newCodeBox = document.getElementById('new-code');

    const formCont1 = document.getElementById('form-continue-step1');
    const formCont2 = document.getElementById('form-continue-step2');
    const contCodeDisplay = document.getElementById('continue-code-display');
    const contResult = document.getElementById('continue-result');

    let newDescription = '';
    let pendingCode = '';

    btnNew.addEventListener('click', () => {
      hideAll();
      formNew1.classList.remove('hidden');
    });

    btnContinue.addEventListener('click', () => {
      hideAll();
      formCont1.classList.remove('hidden');
    });

    formNew1.addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(formNew1);
      newDescription = (fd.get('description') || '').trim();
      if (!newDescription) return alert('Descripción requerida');
      formNew1.classList.add('hidden');
      formNew2.classList.remove('hidden');
    });

    formNew2.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(formNew2);
      fd.append('description', newDescription);
      fd.append('csrf_token', csrfToken);

      const res = await fetch(apiBase + '/conversations', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (!data.success) return alert(data.error || 'Error');
      newCodeBox.textContent = data.data.code;
      formNew2.classList.add('hidden');
      newResult.classList.remove('hidden');
      // Redirigir después de 2 segundos para mostrar el código
      setTimeout(() => {
        window.location.href = 'welcome.php';
      }, 2000);
    });

    formCont1.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(formCont1);
      const code = (fd.get('code') || '').trim();
      if (!code) return alert('Código requerido');
      const res = await fetch(`${apiBase}/conversations/validate?code=${encodeURIComponent(code)}`, {
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (!data.success) return alert(data.error || 'Código no válido');
      pendingCode = code;
      const masked = code.length <= 4 ? '****' : `${code.slice(0, 2)}****${code.slice(-2)}`;
      contCodeDisplay.textContent = `Código verificado: ${masked}`;
      formCont1.classList.add('hidden');
      formCont2.classList.remove('hidden');
    });

    formCont2.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(formCont2);
      fd.append('code', pendingCode);
      fd.append('csrf_token', csrfToken);
      const res = await fetch(apiBase + '/conversations/sessions', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (!data.success) return alert(data.error || 'Error');
      formCont2.classList.add('hidden');
      contResult.classList.remove('hidden');
      // Redirigir a welcome.php después de login exitoso
      setTimeout(() => {
        window.location.href = 'welcome.php';
      }, 1000);
    });

    function hideAll() {
      [formNew1, formNew2, newResult, formCont1, formCont2, contResult].forEach(el => el.classList.add('hidden'));
    }
  </script>
</body>
</html>
