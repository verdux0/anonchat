<?php
// Admin panel (UI shell). Data loads via AJAX (admin_panel_api.php).

require_once __DIR__ . '/api/session.php';

start_secure_session();

if (!is_admin_authenticated()) {
  header('Location: admin.php');
  exit;
}

$csrf = get_csrf_token('admin_panel');

$tables = ['Conversation', 'Admin', 'Messages', 'Rate_Limit', 'Security_Log', 'Active_Messages']; // menú
$adminUser = htmlspecialchars(get_admin_user() ?? 'admin', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Panel - AnonChat</title>
  <link rel="stylesheet" href="static/css/admin_panel.css">
</head>
<body data-csrf="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
  <div class="admin-root">
    <!-- LEFT -->
    <aside class="left-col">
      <div class="brand">
        <div class="brand__badge">AnonChat • Admin</div>
        <div class="brand__user">Sesión: <strong><?php echo $adminUser; ?></strong></div>
      </div>

      <nav class="table-list" aria-label="Tablas">
        <div class="table-list__title">Tablas</div>
        <?php foreach ($tables as $t): ?>
          <button class="table-list__item" type="button" data-table="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>
          </button>
        <?php endforeach; ?>
      </nav>

      <div class="left-footer">
        <a class="link" href="index.php">Ir al inicio</a>
        <a class="link link--danger" href="logout_admin.php">Cerrar sesión</a>
      </div>
    </aside>

    <!-- RIGHT -->
    <main class="right-col">
      <!-- Context header -->
      <section class="context-head">
        <div>
          <h1 class="title">Panel de administración</h1>
          <p class="subtitle" id="contextSubtitle">Selecciona una tabla para ver su contenido.</p>
        </div>
        <div class="toasts" id="toasts" aria-live="polite" aria-atomic="true"></div>
      </section>

      <!-- Table tools (shown when table selected) -->
      <section class="card hidden" id="tableTools">
        <div class="table-toolbar">
          <div class="search-multifield">
            <span class="search-icon" aria-hidden="true">⌕</span>
            <input id="searchInput" type="search" placeholder="Buscar..." autocomplete="off">
            <button class="pill" id="fieldsToggle" type="button" aria-expanded="false">Campos</button>
          </div>

          <button class="btn" id="exportBtn" type="button">Exportar CSV</button>
          <button class="btn btn--ghost" id="refreshBtn" type="button">Actualizar</button>
        </div>

        <!-- Multiselect: fields -->
        <div class="fields-panel hidden" id="fieldsPanel" role="dialog" aria-label="Seleccionar campos de búsqueda">
          <div class="fields-panel__head">
            <div class="fields-panel__title">Buscar en columnas</div>
            <input id="fieldsSearch" type="search" placeholder="Filtrar columnas...">
          </div>

          <div class="chips" id="chips"></div>

          <div class="fields-panel__list" id="fieldsList" aria-label="Lista de columnas">
            <!-- JS renders checkable fields here -->
          </div>

          <div class="fields-panel__actions">
            <button class="btn btn--ghost" id="fieldsClear" type="button">Limpiar</button>
            <button class="btn" id="fieldsDone" type="button">Hecho</button>
          </div>
        </div>
      </section>

      <!-- Bulk toolbar (shown only when selection exists) -->
      <section class="bulk-toolbar hidden" id="bulkToolbar">
        <div class="bulk-toolbar__left">
          <label class="check">
            <input type="checkbox" id="selectAll">
            <span>Seleccionar todo (página)</span>
          </label>
          <span class="bulk-count" id="bulkCount">0 seleccionados</span>
        </div>
        <div class="bulk-toolbar__right">
          <button class="btn btn--danger" id="bulkDeleteBtn" type="button">Borrar seleccionados</button>
        </div>
      </section>

      <!-- Data table -->
      <section class="card">
        <div class="table-wrap">
          <table class="data-table" id="dataTable" aria-label="Contenido de tabla">
            <thead id="tableHead">
              <tr>
                <th class="col-check"></th>
                <th>Selecciona una tabla</th>
              </tr>
            </thead>
            <tbody id="tableBody">
              <tr>
                <td></td>
                <td class="muted">No hay datos cargados.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="pagination" id="pagination">
          <button class="btn btn--ghost" id="prevPage" type="button">Anterior</button>
          <span class="page-info" id="pageInfo">—</span>
          <button class="btn btn--ghost" id="nextPage" type="button">Siguiente</button>
        </div>
      </section>

      <!-- Record detail -->
      <section class="card hidden" id="recordCard">
        <div class="record-head">
          <div>
            <div class="record-title">Detalle del registro</div>
            <div class="muted" id="recordMeta">—</div>
          </div>
          <button class="btn btn--ghost" id="closeRecord" type="button">Cerrar</button>
        </div>

        <form class="record-form" id="recordForm">
          <!-- JS renders inputs here -->
          <div class="record-actions">
            <button class="btn" type="submit">Guardar cambios</button>
            <button class="btn btn--ghost" type="button" id="resetRecord">Revertir</button>
          </div>
        </form>
      </section>
    </main>
  </div>

  <!-- Confirm modal -->
  <div class="modal hidden" id="confirmModal" role="dialog" aria-modal="true" aria-label="Confirmar acción">
    <div class="modal__card">
      <div class="modal__title" id="confirmTitle">Confirmar</div>
      <div class="modal__body" id="confirmBody">¿Seguro?</div>
      <div class="modal__actions">
        <button class="btn btn--ghost" id="confirmCancel" type="button">Cancelar</button>
        <button class="btn btn--danger" id="confirmOk" type="button">Borrar</button>
      </div>
    </div>
  </div>

  <script>
    // Minimal JS: selection, table load, search, record load/update, bulk delete + undo.
    const csrf = document.body.dataset.csrf;
    const api = 'api/admin_panel_api.php';

    // State
    let currentTable = null;
    let currentPage = 1;
    const pageSize = 25;

    let columns = [];         // current table columns
    let rows = [];            // current page rows
    let selectedIds = new Set();
    let idColumn = 'ID';      // default, overwritten by API

    // Search state
    let q = '';
    let selectedFields = [];  // columns to search

    // Undo state
    let lastDelete = null; // {table, ids, at, undoToken}

    // UI refs
    const contextSubtitle = document.getElementById('contextSubtitle');
    const tableTools = document.getElementById('tableTools');
    const tableHead = document.getElementById('tableHead');
    const tableBody = document.getElementById('tableBody');
    const pagination = document.getElementById('pagination');
    const pageInfo = document.getElementById('pageInfo');
    const prevPage = document.getElementById('prevPage');
    const nextPage = document.getElementById('nextPage');

    const bulkToolbar = document.getElementById('bulkToolbar');
    const bulkCount = document.getElementById('bulkCount');
    const selectAll = document.getElementById('selectAll');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');

    const searchInput = document.getElementById('searchInput');
    const refreshBtn = document.getElementById('refreshBtn');
    const exportBtn = document.getElementById('exportBtn');

    const fieldsToggle = document.getElementById('fieldsToggle');
    const fieldsPanel = document.getElementById('fieldsPanel');
    const fieldsSearch = document.getElementById('fieldsSearch');
    const fieldsList = document.getElementById('fieldsList');
    const chips = document.getElementById('chips');
    const fieldsClear = document.getElementById('fieldsClear');
    const fieldsDone = document.getElementById('fieldsDone');

    const recordCard = document.getElementById('recordCard');
    const recordForm = document.getElementById('recordForm');
    const recordMeta = document.getElementById('recordMeta');
    const closeRecord = document.getElementById('closeRecord');
    const resetRecord = document.getElementById('resetRecord');

    const toasts = document.getElementById('toasts');

    // Helpers
    function toast(message, opts = {}) {
      const el = document.createElement('div');
      el.className = 'toast' + (opts.variant ? ` toast--${opts.variant}` : '');
      el.innerHTML = `<div class="toast__msg">${escapeHtml(message)}</div>` + (opts.actionText ? `<button class="toast__btn">${escapeHtml(opts.actionText)}</button>` : '');
      toasts.appendChild(el);

      if (opts.onAction && opts.actionText) {
        el.querySelector('.toast__btn').addEventListener('click', () => opts.onAction());
      }

      setTimeout(() => { el.classList.add('toast--hide'); }, 3500);
      setTimeout(() => { el.remove(); }, 4200);
    }

    function escapeHtml(s) {
      return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    async function postJSON(url, payload) {
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Error');
      return data.data;
    }

    function setHidden(el, hidden) {
      el.classList.toggle('hidden', !!hidden);
    }

    function updateBulkUI() {
      const count = selectedIds.size;
      setHidden(bulkToolbar, count === 0);
      bulkCount.textContent = `${count} seleccionado(s)`;
      // "select all" only reflects visible page selection:
      const visibleIds = rows.map(r => r[idColumn]).filter(v => v != null);
      const allVisibleSelected = visibleIds.length > 0 && visibleIds.every(id => selectedIds.has(String(id)));
      selectAll.checked = allVisibleSelected;
    }

    // Table list click
    document.querySelectorAll('.table-list__item').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.table-list__item').forEach(b => b.classList.remove('is-active'));
        btn.classList.add('is-active');
        openTable(btn.dataset.table);
      });
    });

    async function openTable(table) {
      currentTable = table;
      currentPage = 1;
      selectedIds.clear();
      recordCard.classList.add('hidden');
      contextSubtitle.textContent = `Tabla: ${table}`;
      setHidden(tableTools, false);
      await loadTable();
    }

    async function loadTable() {
      if (!currentTable) return;

      const data = await postJSON(api, {
        csrf,
        action: 'list',
        table: currentTable,
        page: currentPage,
        pageSize,
        q,
        fields: selectedFields
      });

      columns = data.columns;
      rows = data.rows;
      idColumn = data.idColumn || 'ID';

      renderTable();
      renderFieldsUI();
      updateBulkUI();

      pageInfo.textContent = `Página ${data.page} de ${data.totalPages} • ${data.totalRows} filas`;
      prevPage.disabled = data.page <= 1;
      nextPage.disabled = data.page >= data.totalPages;
    }

    function renderTable() {
      // Head
      tableHead.innerHTML = '';
      const trh = document.createElement('tr');

      const thCheck = document.createElement('th');
      thCheck.className = 'col-check';
      thCheck.innerHTML = ''; // checkbox "select all" lives in bulk toolbar
      trh.appendChild(thCheck);

      columns.forEach(col => {
        const th = document.createElement('th');
        th.textContent = col;
        trh.appendChild(th);
      });

      tableHead.appendChild(trh);

      // Body
      tableBody.innerHTML = '';
      if (!rows.length) {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td></td><td class="muted" colspan="${columns.length}">Sin resultados.</td>`;
        tableBody.appendChild(tr);
        return;
      }

      rows.forEach(row => {
        const tr = document.createElement('tr');
        tr.dataset.id = row[idColumn];

        // Checkbox
        const tdC = document.createElement('td');
        tdC.className = 'col-check';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = selectedIds.has(String(row[idColumn]));
        cb.addEventListener('click', (e) => {
          e.stopPropagation();
          toggleSelect(row[idColumn], cb.checked);
        });
        tdC.appendChild(cb);
        tr.appendChild(tdC);

        // Cells
        columns.forEach(col => {
          const td = document.createElement('td');
          td.textContent = row[col] == null ? '' : String(row[col]);
          tr.appendChild(td);
        });

        // Row click -> record detail
        tr.addEventListener('click', () => openRecord(row[idColumn]));
        tableBody.appendChild(tr);
      });
    }

    function toggleSelect(id, checked) {
      const key = String(id);
      if (checked) selectedIds.add(key);
      else selectedIds.delete(key);
      updateBulkUI();
    }

    // Pagination
    prevPage.addEventListener('click', async () => { currentPage = Math.max(1, currentPage - 1); await loadTable(); });
    nextPage.addEventListener('click', async () => { currentPage = currentPage + 1; await loadTable(); });

    // Select all (visible page)
    selectAll.addEventListener('change', () => {
      const visibleIds = rows.map(r => r[idColumn]).filter(v => v != null);
      if (selectAll.checked) visibleIds.forEach(id => selectedIds.add(String(id)));
      else visibleIds.forEach(id => selectedIds.delete(String(id)));
      renderTable();
      updateBulkUI();
    });

    // Search
    let searchTimer = null;
    searchInput.addEventListener('input', () => {
      q = searchInput.value.trim();
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { if (currentTable) loadTable(); }, 250);
    });

    refreshBtn.addEventListener('click', () => loadTable());

    exportBtn.addEventListener('click', () => {
      if (!currentTable) return;
      // Simple: let server stream CSV based on current query.
      const params = new URLSearchParams({
        table: currentTable,
        q,
        fields: JSON.stringify(selectedFields || [])
      });
      window.location.href = `api/admin_panel_export.php?${params.toString()}`;
    });

    // Fields multiselect (chips)
    fieldsToggle.addEventListener('click', () => {
      const open = fieldsPanel.classList.contains('hidden');
      setHidden(fieldsPanel, !open);
      fieldsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      fieldsSearch.value = '';
      renderFieldsUI();
    });

    fieldsDone.addEventListener('click', () => {
      setHidden(fieldsPanel, true);
      fieldsToggle.setAttribute('aria-expanded', 'false');
      if (currentTable) loadTable();
    });

    fieldsClear.addEventListener('click', () => {
      selectedFields = [];
      renderFieldsUI();
      if (currentTable) loadTable();
    });

    fieldsSearch.addEventListener('input', () => renderFieldsUI());

    function renderFieldsUI() {
      // Chips
      chips.innerHTML = '';
      selectedFields.forEach(f => {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'chip';
        chip.innerHTML = `${escapeHtml(f)} <span aria-hidden="true">×</span>`;
        chip.addEventListener('click', () => {
          selectedFields = selectedFields.filter(x => x !== f);
          renderFieldsUI();
        });
        chips.appendChild(chip);
      });

      // List
      const filter = (fieldsSearch.value || '').toLowerCase();
      fieldsList.innerHTML = '';
      (columns || []).filter(c => c.toLowerCase().includes(filter)).forEach(col => {
        const row = document.createElement('label');
        row.className = 'field-row';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = selectedFields.includes(col);
        cb.addEventListener('change', () => {
          if (cb.checked) selectedFields = Array.from(new Set([...selectedFields, col]));
          else selectedFields = selectedFields.filter(x => x !== col);
          renderFieldsUI();
        });
        row.appendChild(cb);
        row.appendChild(document.createTextNode(' ' + col));
        fieldsList.appendChild(row);
      });
    }

    // Record detail
    let recordSnapshot = null;

    async function openRecord(id) {
      if (!currentTable) return;
      const data = await postJSON(api, {
        csrf,
        action: 'get',
        table: currentTable,
        id
      });

      recordSnapshot = data.record;
      setHidden(recordCard, false);
      recordMeta.textContent = `${currentTable} • ${idColumn}=${id}`;
      renderRecordForm(data.record, data.readonlyFields || [idColumn]);
    }

    function renderRecordForm(record, readonlyFields = []) {
      // wipe everything except actions block
      const actions = recordForm.querySelector('.record-actions');
      recordForm.innerHTML = '';
      Object.keys(record).forEach(key => {
        const wrap = document.createElement('div');
        wrap.className = 'form-row';
        const label = document.createElement('label');
        label.textContent = key;

        const input = document.createElement('input');
        input.name = key;
        input.value = record[key] == null ? '' : String(record[key]);
        input.autocomplete = 'off';
        if (readonlyFields.includes(key)) {
          input.readOnly = true;
          input.classList.add('is-readonly');
        }

        wrap.appendChild(label);
        wrap.appendChild(input);
        recordForm.appendChild(wrap);
      });

      recordForm.appendChild(actions);
    }

    closeRecord.addEventListener('click', () => setHidden(recordCard, true));

    resetRecord.addEventListener('click', () => {
      if (recordSnapshot) renderRecordForm(recordSnapshot, [idColumn]);
      toast('Cambios revertidos.', { variant: 'info' });
    });

    recordForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!currentTable) return;

      const fd = new FormData(recordForm);
      const patch = {};
      for (const [k, v] of fd.entries()) patch[k] = v;

      try {
        await postJSON(api, {
          csrf,
          action: 'update',
          table: currentTable,
          id: patch[idColumn],
          patch
        });
        toast('Guardado.', { variant: 'success' });
        await loadTable();
      } catch (err) {
        toast(err.message || 'Error al guardar', { variant: 'danger' });
      }
    });

    // Bulk delete with confirm + undo
    const modal = document.getElementById('confirmModal');
    const confirmTitle = document.getElementById('confirmTitle');
    const confirmBody = document.getElementById('confirmBody');
    const confirmCancel = document.getElementById('confirmCancel');
    const confirmOk = document.getElementById('confirmOk');

    function openConfirm({title, body, okText = 'Aceptar', onOk}) {
      confirmTitle.textContent = title;
      confirmBody.textContent = body;
      confirmOk.textContent = okText;
      setHidden(modal, false);

      const clean = () => {
        setHidden(modal, true);
        confirmOk.onclick = null;
      };

      confirmCancel.onclick = clean;
      confirmOk.onclick = async () => {
        clean();
        await onOk();
      };
    }

    bulkDeleteBtn.addEventListener('click', () => {
      if (!currentTable || selectedIds.size === 0) return;
      const ids = Array.from(selectedIds);

      openConfirm({
        title: 'Borrar seleccionados',
        body: `Vas a borrar ${ids.length} registro(s) de ${currentTable}. ¿Continuar?`,
        okText: 'Borrar',
        onOk: async () => {
          try {
            const data = await postJSON(api, {
              csrf,
              action: 'delete_many',
              table: currentTable,
              ids
            });

            // Save undo info
            lastDelete = {
              table: currentTable,
              ids,
              undoToken: data.undoToken || null,
              at: Date.now()
            };

            selectedIds.clear();
            updateBulkUI();
            await loadTable();

            toast(`Borrados ${ids.length}.`, {
              variant: 'danger',
              actionText: 'Deshacer',
              onAction: async () => {
                if (!lastDelete || !lastDelete.undoToken) {
                  toast('No se puede deshacer (sin token).', { variant: 'info' });
                  return;
                }
                try {
                  await postJSON(api, {
                    csrf,
                    action: 'undo_delete',
                    undoToken: lastDelete.undoToken
                  });
                  toast('Deshecho.', { variant: 'success' });
                  await loadTable();
                } catch (e) {
                  toast(e.message || 'No se pudo deshacer', { variant: 'danger' });
                }
              }
            });

          } catch (err) {
            toast(err.message || 'Error al borrar', { variant: 'danger' });
          }
        }
      });
    });

    // Close modal on backdrop click
    modal.addEventListener('click', (e) => { if (e.target === modal) setHidden(modal, true); });

  </script>
</body>
</html>