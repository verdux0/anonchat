<?php
declare(strict_types=1);

require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/session.php';

start_secure_session();

$isAdmin = is_admin_authenticated();
$isUser  = is_user_authenticated();

if (!$isAdmin && !$isUser) {
  header('Location: index.php');
  exit;
}

// Conversación actual
$conversationId = $isUser ? get_conversation_id() : (int)($_GET['conversation_id'] ?? 0);
if ($conversationId <= 0) {
  if ($isAdmin) {
    header('Location: admin_panel.php');
    exit;
  }
  header('Location: index.php');
  exit;
}

// CSRF
$csrf = get_csrf_token('chat');

// Cargar conversación
$pdo = get_pdo();
$stmt = $pdo->prepare("SELECT ID, Code, Status, Title, Description, Created_At, Last_Activity, Expires_At, Creator_IP, Registered_At, report
                       FROM Conversation WHERE ID = ? LIMIT 1");
$stmt->execute([$conversationId]);
$conv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$conv) {
  header('Location: index.php');
  exit;
}

$code = htmlspecialchars((string)$conv['Code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$status = (string)$conv['Status'];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>AnonChat</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="stylesheet" href="static/css/style.css">
  <link rel="stylesheet" href="static/css/chat.css">

  <style>
    /* Pantalla completa SOLO con interfaz de chat */
    body {
      align-items: stretch;     /* override del style.css que centra verticalmente */
      justify-content: stretch; /* override del style.css */
      padding: 0;              /* sin padding global */
    }
    .page {
      width: 100%;
      max-width: none;
      min-height: 100vh;
      display: flex;
      align-items: stretch;
      justify-content: center;
      padding: 16px;
    }

    /* Si NO es admin, centramos el panel del chat y limitamos ancho */
    .page.is-user .chat-container {
      grid-template-columns: 1fr;       /* sin sidebar */
      justify-items: center;            /* centra */
    }
    .page.is-user .chat-panel {
      width: min(980px, 100%);
    }

    /* Si es admin, dos columnas */
    .page.is-admin .chat-container {
      grid-template-columns: 1fr 320px;
    }

    /* Quitar cualquier header visual (no se imprime) */
    header { display: none !important; }
  </style>
</head>

<body
  data-csrf="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"
  data-conversation-id="<?php echo (int)$conv['ID']; ?>"
  data-role="<?php echo $isAdmin ? 'admin' : 'user'; ?>"
>
  <div class="page <?php echo $isAdmin ? 'is-admin' : 'is-user'; ?>">
    <div class="chat-container">
      <!-- MAIN -->
      <section class="chat-panel">
        <div class="chat-header">
          <div class="chat-title">
            <h2>Conversación</h2>
            <div class="sticky-actions">
              <span class="tag" id="statusTag"></span>
            </div>
          </div>
          <div class="chat-code">Código: <span class="muted"><?php echo $code; ?></span></div>
        </div>

        <div class="chat-messages" id="messages" aria-live="polite" aria-relevant="additions"></div>

        <!-- typing indicator -->
        <div class="chat-messages hidden" id="typingRow" style="padding-top:0;border-top:none;border-bottom:none;background:transparent;">
          <div class="message anonymous" style="max-width:220px;">
            <div class="bubble" style="display:flex;align-items:center;gap:10px;">
              <span class="small">…</span>
              <div class="typing-dots" aria-label="Escribiendo">
                <span></span><span></span><span></span>
              </div>
            </div>
          </div>
        </div>

        <form class="chat-composer" id="composer" autocomplete="off">
          <textarea id="messageInput" name="content" rows="1" placeholder="Escribe un mensaje..." required></textarea>

          <div class="sender-select">
            <?php if ($isAdmin): ?>
              <label class="small">Remitente</label>
              <select id="sender" name="sender">
                <option value="admin" selected>Admin</option>
                <option value="anonymous">Anónimo</option>
              </select>
            <?php else: ?>
              <input type="hidden" id="sender" name="sender" value="anonymous">
              <div class="small">Enter para enviar • Shift+Enter salto</div>
            <?php endif; ?>
          </div>

          <button class="primary" type="submit">Enviar</button>
        </form>

        <div style="padding:10px 12px;display:flex;justify-content:flex-end;">
          <a class="alert" href="<?php echo $isAdmin ? 'logout-admin.php' : 'logout.php'; ?>">Cerrar sesión</a>
        </div>
      </section>

      <!-- SIDEBAR ADMIN -->
      <?php if ($isAdmin): ?>
      <aside class="chat-sidebar">
        <div class="section-title">Herramientas</div>

        <div class="accordion" id="adminAccordion">
          <details class="acc" open>
            <summary class="acc__sum">📝 Reporte</summary>
            <div class="acc__body">
              <textarea id="reportInput" rows="5" placeholder="Escribe un reporte interno..."><?php
                echo htmlspecialchars((string)($conv['report'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
              ?></textarea>
              <button class="primary" id="saveReportBtn" type="button">Guardar reporte</button>
              <p class="muted" id="reportMsg"></p>
            </div>
          </details>

          <details class="acc">
            <summary class="acc__sum">📄 Detalles</summary>
            <div class="acc__body">
              <div class="chat-meta">
                <div class="meta-row"><span>Código</span><strong><?php echo $code; ?></strong></div>
                <div class="meta-row"><span>Estado</span><strong><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="meta-row"><span>Creada</span><span><?php echo htmlspecialchars((string)$conv['Created_At'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="meta-row"><span>Última actividad</span><span><?php echo htmlspecialchars((string)($conv['Last_Activity'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="meta-row"><span>Expira</span><span><?php echo htmlspecialchars((string)($conv['Expires_At'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="meta-row"><span>IP creadora</span><span><?php echo htmlspecialchars((string)($conv['Creator_IP'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="meta-row"><span>Registrada</span><span><?php echo $conv['Registered_At'] ? 'Sí' : 'No'; ?></span></div>
              </div>
            </div>
          </details>

          <details class="acc">
            <summary class="acc__sum">🗑 Eliminados</summary>
            <div class="acc__body">
              <button class="ghost" id="loadDeletedBtn" type="button">Cargar</button>
              <div id="deletedList" class="card" style="background:#fff;border:1px dashed var(--border);"></div>
            </div>
          </details>

          <details class="acc">
            <summary class="acc__sum">🔄 Estado</summary>
            <div class="acc__body">
              <label class="small">Cambiar estado</label>
              <select id="statusSelect">
                <?php foreach (['pending','active','waiting','closed','archived'] as $s): ?>
                  <option value="<?php echo $s; ?>" <?php echo $s === $status ? 'selected' : ''; ?>><?php echo $s; ?></option>
                <?php endforeach; ?>
              </select>
              <button class="primary" id="saveStatusBtn" type="button">Aplicar</button>
              <p class="muted" id="statusMsg"></p>
            </div>
          </details>

          <details class="acc">
            <summary class="acc__sum">🛠 Admin panel</summary>
            <div class="acc__body">
              <a class="primary" href="admin_panel.php">Abrir</a>
            </div>
          </details>
        </div>

        <div style="margin-top:auto;">
          <a class="alert" href="logout-admin.php">Cerrar sesión (admin)</a>
        </div>
      </aside>
      <?php endif; ?>
    </div>
  </div>

  <script>
    // (tu JS igual; sin cambios funcionales)
    const csrf = document.body.dataset.csrf;
    const conversationId = Number(document.body.dataset.conversationId);
    const role = document.body.dataset.role;
    const api = 'api/chat_api.php';

    const messagesEl = document.getElementById('messages');
    const typingRow = document.getElementById('typingRow');

    const composer = document.getElementById('composer');
    const input = document.getElementById('messageInput');
    const senderEl = document.getElementById('sender');

    const statusTag = document.getElementById('statusTag');

    const reportInput = document.getElementById('reportInput');
    const saveReportBtn = document.getElementById('saveReportBtn');
    const reportMsg = document.getElementById('reportMsg');

    const loadDeletedBtn = document.getElementById('loadDeletedBtn');
    const deletedList = document.getElementById('deletedList');

    const statusSelect = document.getElementById('statusSelect');
    const saveStatusBtn = document.getElementById('saveStatusBtn');
    const statusMsg = document.getElementById('statusMsg');

    function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function fmtTime(dt){
      if (!dt) return '';
      const d = new Date(dt.replace(' ', 'T'));
      return isNaN(d) ? '' : d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
    }

    function statusDotClass(status){
      return ({active:'dot-active',pending:'dot-pending',closed:'dot-closed',waiting:'dot-waiting',archived:'dot-archived'}[status] || 'dot-pending');
    }
    function setStatus(status){
      statusTag.innerHTML = `<span class="chat-status"><span class="dot ${statusDotClass(status)}"></span><span>${esc(status)}</span></span>`;
    }

    let typingTimer = null;
    let lastTypingSent = 0;
    function sendTyping(isTyping){
      const now = Date.now();
      if (now - lastTypingSent < 400 && isTyping) return;
      lastTypingSent = now;

      fetch(api, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({csrf, action:'typing', conversation_id: conversationId, typing: !!isTyping})
      }).catch(()=>{});
    }

    input.addEventListener('input', () => {
      autoGrow();
      sendTyping(true);
      clearTimeout(typingTimer);
      typingTimer = setTimeout(() => sendTyping(false), 1400);
    });

    function autoGrow(){
      input.style.height = 'auto';
      const lineHeight = 20;
      const max = lineHeight * 4 + 24;
      input.style.height = Math.min(input.scrollHeight, max) + 'px';
    }
    autoGrow();

    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        composer.requestSubmit();
      }
    });

    const pending = new Map();
    let lastSeenId = 0;
    let isNearBottom = true;

    function scrollToBottom(force=false){
      if (!force && !isNearBottom) return;
      messagesEl.scrollTop = messagesEl.scrollHeight;
    }
    messagesEl.addEventListener('scroll', () => {
      const threshold = 120;
      const dist = messagesEl.scrollHeight - (messagesEl.scrollTop + messagesEl.clientHeight);
      isNearBottom = dist < threshold;
    });

    function tickMarkup(msg){
      if (msg._local_pending) return `<span class="small">⏳</span>`;
      if (msg.Is_Read && msg.Read_At) return `<span class="small" style="color:#1a73e8;">✔✔</span>`;
      if (msg._received) return `<span class="small">✔✔</span>`;
      return `<span class="small">✔</span>`;
    }

    function renderMessage(msg){
      const sender = msg.Sender;
      const cls = (sender === 'admin') ? 'admin' : (sender === 'user' ? 'user' : 'anonymous');
      const wrapper = document.createElement('div');
      wrapper.className = `message ${cls}`;
      wrapper.dataset.id = msg.ID || '';
      wrapper.innerHTML = `
        <div class="meta">
          <span>${fmtTime(msg.Created_At || '')}</span>
          <span>${tickMarkup(msg)}</span>
        </div>
        <div class="bubble">${esc(msg.Content || '')}</div>
        ${msg.File_Path ? `<div class="file">${esc(msg.File_Path)}</div>` : ``}
      `;
      return wrapper;
    }

    async function post(action, payload){
      const res = await fetch(api, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({csrf, action, ...payload})
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Error');
      return data.data;
    }

    async function loadHeader(){
      const data = await post('conversation_details', {conversation_id: conversationId});
      setStatus(data.conversation.Status);
    }

    async function loadMessages(){
      const data = await post('list_messages', {conversation_id: conversationId, after_id: lastSeenId});
      typingRow.classList.toggle('hidden', !data.other_typing);

      if (data.messages && data.messages.length) {
        data.messages.forEach(m => {
          lastSeenId = Math.max(lastSeenId, Number(m.ID));
          messagesEl.appendChild(renderMessage(m));
        });
        scrollToBottom(false);
      }

      if (data.mark_read_ids && data.mark_read_ids.length) {
        post('mark_read', {conversation_id: conversationId, ids: data.mark_read_ids}).catch(()=>{});
      }
    }

    composer.addEventListener('submit', async (e) => {
      e.preventDefault();
      const content = input.value.trim();
      if (!content) return;

      const sender = senderEl.value;
      input.value = '';
      autoGrow();

      const tempId = 'tmp_' + Date.now() + '_' + Math.random().toString(16).slice(2);
      const localMsg = {_local_pending:true, Sender: sender, Content: content, Created_At: new Date().toISOString()};
      const el = renderMessage(localMsg);
      el.dataset.tempId = tempId;
      messagesEl.appendChild(el);
      scrollToBottom(true);
      pending.set(tempId, el);

      try {
        const data = await post('send_message', {conversation_id: conversationId, sender, content});
        const real = data.message;
        lastSeenId = Math.max(lastSeenId, Number(real.ID));
        el.replaceWith(renderMessage(real));
        pending.delete(tempId);
      } catch {
        el.querySelector('.meta').innerHTML = `<span class="small" style="color:#ea4335;">Error</span>`;
      }
    });

    const acc = document.getElementById('adminAccordion');
    if (acc) {
      acc.querySelectorAll('details').forEach(d => {
        d.addEventListener('toggle', () => {
          if (d.open) acc.querySelectorAll('details').forEach(o => { if (o !== d) o.open = false; });
        });
      });
    }

    if (saveReportBtn) {
      saveReportBtn.addEventListener('click', async () => {
        reportMsg.textContent = 'Guardando...';
        try {
          await post('admin_save_report', {conversation_id: conversationId, report: reportInput.value});
          reportMsg.textContent = 'Guardado.';
        } catch (e) {
          reportMsg.textContent = e.message || 'Error';
        }
      });
    }

    if (loadDeletedBtn) {
      loadDeletedBtn.addEventListener('click', async () => {
        deletedList.textContent = 'Cargando...';
        try {
          const data = await post('admin_list_deleted', {conversation_id: conversationId});
          deletedList.innerHTML = data.messages.length
            ? data.messages.map(m => `
              <div style="padding:10px;border-bottom:1px dashed var(--border);">
                <div class="small">Eliminado: ${esc(m.Deleted_At)} • ${esc(m.Sender)} • ${esc(m.Created_At)}</div>
                <div style="color:#6b7280;text-decoration:line-through;white-space:pre-wrap;">${esc(m.Content || '')}</div>
              </div>`).join('')
            : `<p class="muted">No hay mensajes eliminados.</p>`;
        } catch (e) {
          deletedList.textContent = e.message || 'Error';
        }
      });
    }

    if (saveStatusBtn) {
      saveStatusBtn.addEventListener('click', async () => {
        statusMsg.textContent = 'Aplicando...';
        try {
          await post('admin_set_status', {conversation_id: conversationId, status: statusSelect.value});
          statusMsg.textContent = 'Estado actualizado.';
          await loadHeader();
        } catch (e) {
          statusMsg.textContent = e.message || 'Error';
        }
      });
    }

    async function tick(){
      try { await loadHeader(); await loadMessages(); } catch {}
      setTimeout(tick, 1200);
    }

    setStatus('<?php echo htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?>');
    tick();
  </script>
</body>
</html>