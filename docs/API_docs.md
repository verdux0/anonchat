# Documentación de la API — AnonChat 
## Versiones

- **API v1 (recomendada):** rutas bajo `/api/v1/...`.
- **API legacy (compatibilidad):** un único endpoint con `?action=...`.

En general, usa **v1** cuando exista el equivalente. La legacy puede cambiar o eliminarse más adelante.

---

## Formato de respuesta

La API responde JSON con un formato uniforme:

```json
{
  "success": true,
  "data": {},
  "error": null
}
```

- En error: `success=false`, `data=null`, `error` contiene un mensaje.
- Los códigos HTTP pueden variar según el caso (p. ej. `200`, `201`, `4xx`, `5xx`).

---

## Sesión y protección anti-CSRF (alto nivel)

- El flujo típico desde navegador usa **cookies** (sesión) y, en operaciones sensibles, un **token anti-CSRF**.
- Como consumidor, debes:
  - Mantener cookies (en navegador: `credentials: 'same-origin'`/`include` según el caso).
  - Enviar el token anti-CSRF cuando la UI/servidor lo requiera.

Este documento no describe cómo se generan/validan cookies/tokens; solo cómo incluirlos cuando son necesarios.

---

## API v1

### Healthcheck

- `GET /api/v1/health`
- Respuesta (éxito):
  ```json
  {"success":true,"data":{"status":"ok"},"error":null}
  ```

### Crear conversación

- `POST /api/v1/conversations`
- Body (`application/x-www-form-urlencoded` o `multipart/form-data`):
  - `description` (string, opcional)
  - `password` (string)
  - `password_confirm` (string)
  - `csrf_token` (string, si aplica)
- Respuesta (éxito):
  ```json
  {
    "success": true,
    "data": {
      "message": "Conversación creada",
      "code": "...",
      "conversation_id": "..."
    },
    "error": null
  }
  ```

### Validar un código

- `GET /api/v1/conversations/validate?code=...`
- Respuesta (éxito):
  ```json
  {"success":true,"data":{"exists":true,"status":"..."},"error":null}
  ```

### Continuar conversación (crear sesión)

- `POST /api/v1/conversations/sessions`
- Body:
  - `code` (string)
  - `password` (string)
  - `csrf_token` (string, si aplica)
- Respuesta (éxito):
  ```json
  {
    "success": true,
    "data": {
      "message": "Acceso concedido",
      "conversation_id": "...",
      "code": "..."
    },
    "error": null
  }

---

## API legacy (compatibilidad)

La API legacy se consume como `POST/GET /api/api.php?action=...`.

Se recomienda usarla solo cuando una operación todavía no esté disponible en v1.

### Obtener mensajes (si tu versión aún no tiene v1)

- `POST /api/api.php?action=get_messages`
- Body: depende de si ya existe una sesión activa en el cliente.
- Respuesta (éxito):
  ```json
  {
    "success": true,
    "data": {"messages": []},
    "error": null
  }
  ```

---

## Ejemplos

### Crear conversación (fetch)

```js
const fd = new FormData();
fd.append('description', 'Charla sobre X');
fd.append('password', '...');
fd.append('password_confirm', '...');
// Si tu UI te provee un token anti-CSRF:
// fd.append('csrf_token', token);

const r = await fetch('/api/v1/conversations', {
  method: 'POST',
  body: fd,
  credentials: 'same-origin'
});
console.log(await r.json());
```

### Validar código (fetch)

```js
const code = '...';
const r = await fetch(`/api/v1/conversations/validate?code=${encodeURIComponent(code)}`, {
  method: 'GET',
  credentials: 'same-origin'
});
console.log(await r.json());
```

---
