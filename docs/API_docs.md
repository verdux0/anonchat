# Documentación de la API — AnonChat

Fecha: 2025-12-15  
Autor: Verdux0

Este documento describe la API implementada en `api.php` (junto con `db.php` y `headers.php`) para el proyecto de chat anónimo. Explica los endpoints disponibles, el formato de datos, la autenticación (sesiones y credenciales), validaciones, respuestas y recomendaciones de seguridad y mejora.

---

## Resumen general

- La API está pensada para uso local / desarrollo y sirve acciones específicas a través del parámetro `action` en la query string (por ejemplo: `api.php?action=create_conversation`).
- Respuestas JSON con formato uniforme:
  ```json
  {
    "success": true|false,
    "data": {...} | null,
    "error": null | "mensaje de error"
  }
  ```
- Se usa PDO con sentencias preparadas para acceder a MySQL (ver `db.php`).
- Se usa `session_start()` en la API para soportar autenticación por sesión: la sesión guarda `conversation_code`, `conversation_id` y `authenticated` cuando se crea o continua una conversación.
- `headers.php` aplica cabeceras de seguridad y CORS (en desarrollo `Access-Control-Allow-Origin: *`).

---

## Esquema de la base de datos (relevante)

Tablas principales usadas por la API:

- `Conversation`:
  - ID (BIGINT, PK)
  - Code (VARCHAR(50), UNIQUE) — código de acceso de la conversación
  - Password_Hash (VARCHAR(255)) — hash de la contraseña (password_hash)
  - Created_At, Updated_At
  - Status ENUM('pending','active','closed','waiting','archived')
  - Title, Description

- `Messages`:
  - ID, Conversation_ID (FK), Sender, Content, File_Path, Created_At

---

## Autenticación y sesión

La API soporta dos modos de autenticación:

1. Autenticación por sesión PHP:
   - Cuando el usuario crea o continúa una conversación con credenciales válidas, la API guarda:
     - `$_SESSION['conversation_code']` = código de la conversación
     - `$_SESSION['conversation_id']` = ID numérico de la conversación
     - `$_SESSION['authenticated']` = true
   - Las llamadas posteriores pueden usar esta sesión para autorizarse (sin volver a enviar contraseña).

2. Autenticación por credenciales (code + password) en cada petición:
   - Algunos endpoints aceptan enviar `code` y `password` en el body (POST) para autorizarse en esa operación.
   - El helper `verify_conversation_auth()` intenta primero la autenticación por sesión y, si falla, valida `code` + `password` contra la base de datos (comparando con `password_verify`).

Observaciones:
- Las contraseñas se guardan con `password_hash()` y se re-hashean si `password_needs_rehash()` indica necesidad.
- Las sesiones dependen de la configuración de PHP. En producción usar HTTPS y poner `session.cookie_secure = 1`.

---

## Endpoints

Todos los endpoints se invocan a través de `api.php?action=<nombre>`.

### 1) create_conversation
- URL: `api.php?action=create_conversation`
- Método: POST
- Parámetros (form-data / body x-www-form-urlencoded):
  - `description` (string) — breve descripción (máx 500 caracteres)
  - `password` (string)
  - `password_confirm` (string)
- Validaciones:
  - Todos los campos obligatorios.
  - `description` ≤ 500 caracteres.
  - Contraseña mínima: 8 caracteres (`validate_password`).
  - `password` debe coincidir con `password_confirm`.
- Comportamiento:
  - Genera un código seguro único (`generate_secure_code()`).
  - Crea la fila en `Conversation` con `Status = 'active'`.
  - Guarda `conversation_code`, `conversation_id` y `authenticated` en la sesión.
- Respuesta exitosa (201):
  ```json
  {
    "success": true,
    "data": {
      "message": "Conversación creada",
      "code": "GENERATED_CODE",
      "conversation_id": 123
    },
    "error": null
  }
  ```
- Errores comunes:
  - 422 Si falta un campo o validación.
  - 405 Método no permitido.
  - 500 Error de servidor.

### 2) check_code
- URL: `api.php?action=check_code&code=ELCODIGO`
- Método: GET
- Parámetros:
  - `code` (en query string)
- Comportamiento:
  - Comprueba si existe una conversación con ese `Code`.
  - Responde información mínima (`exists` y `status`) si existe.
- Respuesta exitosa:
  ```json
  {
    "success": true,
    "data": { "exists": true, "status": "active" },
    "error": null
  }
  ```
- Errores:
  - 422 si `code` vacío.
  - 404 si no existe (mensaje genérico: "Código no válido o no disponible").
  - 405 método no permitido.

### 3) continue_conversation
- URL: `api.php?action=continue_conversation`
- Método: POST
- Parámetros (body):
  - `code` (string)
  - `password` (string)
- Comportamiento:
  - Busca la conversación por `Code`.
  - Usa `password_verify()` para validar contraseña.
  - Si válida:
    - Actualiza `Status = 'active'` y `Updated_At`.
    - Guarda `conversation_code`, `conversation_id`, `authenticated` en sesión.
  - Si inválida -> respuesta genérica de fallo para evitar enumeración.
- Respuesta exitosa:
  ```json
  {
    "success": true,
    "data": {
      "message": "Acceso concedido",
      "conversation_id": 123,
      "code": "ELCODIGO"
    },
    "error": null
  }
  ```
- Errores:
  - 401 Credenciales inválidas (unificado para código/contraseña incorrectos).
  - 422 parámetros faltantes.
  - 405 método no permitido.

### 4) get_messages
- URL: `api.php?action=get_messages`
- Método: POST
- Parámetros (body):
  - Opcionalmente `code` y `password` si no hay sesión activa.
  - Si hay sesión válida (`$_SESSION['conversation_id']`/`conversation_code`) no es necesario enviar credenciales.
- Comportamiento:
  - Llama a `verify_conversation_auth()` que acepta:
    - sesión válida, o
    - `code` + `password` válidos
  - Si autorizado, devuelve todos los mensajes de `Messages` para `Conversation_ID` (ordenados asc por `Created_At`).
- Respuesta exitosa:
  ```json
  {
    "success": true,
    "data": {
      "messages": [
        { "ID": 1, "Sender": "anonymous", "Content": "Hola", "File_Path": null, "Created_At": "2025-..." },
        ...
      ]
    },
    "error": null
  }
  ```
- Errores:
  - 401 No autorizado (ni sesión ni credenciales).
  - 405 método no permitido.

---

## Helpers y utilidades importantes

- `generate_secure_code(PDO $pdo)`:
  - Crea un código único combinando:
    - Tiempo (time() convertido a un alfabeto base36 definido en `digits`).
    - Entropía aleatoria (un entero de 31 bits convertido a la misma base).
  - Verifica en bucle que el código no exista ya en la tabla antes de devolverlo.

- `validate_password(string $pwd)`:
  - Actualmente valida longitud mínima: 8 caracteres.

- `verify_conversation_auth(PDO $pdo, ?string $code, ?string $password)`:
  - Primero intenta autenticar usando la sesión PHP.
  - Si no, intenta validar `code` + `password`.
  - Si pasa, devuelve array de datos de la conversación, si no devuelve `false`.

- `str_len($s)`:
  - Wrapper que usa `mb_strlen` si está disponible para contar caracteres Unicode correctamente.

---

## Formato de errores y códigos HTTP

- 200 OK — respuesta estándar para operaciones exitosas sin creación.
- 201 Created — creación de conversación exitosa.
- 400 Bad Request — acción no soportada u otros errores de petición.
- 401 Unauthorized — credenciales inválidas o acceso no autorizado.
- 404 Not Found — recurso no encontrado (p. ej. check_code).
- 405 Method Not Allowed — método HTTP incorrecto para el endpoint.
- 422 Unprocessable Entity — validaciones fallidas (parámetros faltantes o inválidos).
- 500 Internal Server Error — excepción no prevista.

La respuesta JSON siempre tendrá la forma ya descrita (`success`, `data`, `error`) para facilitar su consumo desde frontend.

---

## Ejemplos de uso (fetch)

1) Crear conversación (frontend):
```js
const fd = new FormData();
fd.append('description', 'Charla sobre X');
fd.append('password', 'miContraseñaSegura');
fd.append('password_confirm', 'miContraseñaSegura');

fetch('api.php?action=create_conversation', {
  method: 'POST',
  body: fd,
  credentials: 'include' // importante para enviar cookies de sesión
}).then(r => r.json()).then(console.log);
```

2) Comprobar código:
```js
fetch('api.php?action=check_code&code=ELCODIGO', { method: 'GET', credentials: 'include' })
  .then(r => r.json()).then(console.log);
```

3) Continuar conversación (login por código+pass):
```js
const fd = new FormData();
fd.append('code', 'ELCODIGO');
fd.append('password', 'miContraseñaSegura');

fetch('api.php?action=continue_conversation', {
  method: 'POST',
  body: fd,
  credentials: 'include'
}).then(r => r.json()).then(console.log);
```

4) Obtener mensajes (si hay sesión activa no hace falta enviar credenciales):
```js
const fd = new FormData();
// opcional: fd.append('code', 'ELCODIGO'); fd.append('password','...');

fetch('api.php?action=get_messages', {
  method: 'POST',
  body: fd,
  credentials: 'include'
}).then(r => r.json()).then(console.log);
```

Nota: `credentials: 'include'` permite que fetch envíe/reciba cookies de sesión cuando el frontend y backend estén en orígenes compatibles.

---

## Seguridad, privacidad y recomendaciones

1. HTTPS obligatorio en producción:
   - Nunca enviar contraseñas por HTTP sin TLS.

2. CORS:
   - `headers.php` actualmente permite `Access-Control-Allow-Origin: *` (útil en desarrollo). En producción restringir al origen del frontend.

3. Protección frente a fuerza bruta:
   - Implementar limitación de intentos por IP/código (rate limiting).
   - Agregar retrasos o bloqueo temporal tras N intentos fallidos.
   - Registrar intentos de acceso fallidos (para auditoría).

4. Almacenamiento de contraseñas:
   - Está bien usar `password_hash()` y `password_verify()`.
   - Si planeas migrar a otro algoritmo, `password_needs_rehash()` ya está contemplado.

5. Sesiones:
   - Configurar cookies de sesión con `Secure`, `HttpOnly` y `SameSite=strict` o `lax`.
   - Considerar expiración corta de la sesión y renovación (rotación de ID de sesión).
   - Evitar exponer en el frontend información sensible en la sesión.

6. Mensajes y contenido:
   - Sanitizar/escapar contenido al mostrar en el frontend para prevenir XSS.
   - Si permites subir archivos, validar tipo y almacenar fuera del webroot o en bucket con firma.

7. Validaciones extras:
   - Forzar longitud máxima y/o saneamiento adicional en `description` y otros campos.
   - Validar `Code` con un formato definido (p. ej. longitud máxima) para prevenir inyección en queries (aunque ya se usan prepared statements).

8. Errores y logging:
   - No devolver trazas de stack al cliente en producción. Mantener logging en servidor (con rotación).
   - Usar mensajes de error genéricos para evitar revelación de información sobre existencia de recursos.

9. Tokens vs sesiones:
   - Para APIs REST puro a veces es preferible usar JWT o tokens de sesión con expiración. La solución actual usa sesiones PHP, válida y simple para prototipos.

10. Rate limits y CDN/WAF:
   - Considerar colocar WAF o reglas en capa de aplicación para prevenir abuso.

---

## Consideraciones sobre diseño y mejoras futuras

- Añadir endpoints para:
  - Crear/obtener mensajes (`POST api.php?action=post_message`) con validaciones y subida segura de archivos.
  - Paginación de mensajes (`limit`, `offset` o `since` timestamp).
  - Estado de lectura, reacciones, moderación, etc.
- Añadir tabla `Users` y `ConversationParticipants` si se quiere soportar usuarios identificados.
- Implementar notificaciones en tiempo real (WebSocket / SSE) para la experiencia de chat.
- Separar la API en rutas RESTful (p. ej. `/api/conversations`, `/api/conversations/{id}/messages`) sería más estándar que el parámetro `action`.

---

## Resumen de tratamiento de datos

- Contraseñas: nunca almacenadas en texto; `password_hash()` + rehash cuando sea necesario.
- Códigos de conversación: generados criptográficamente con una mezcla de tiempo + entropía y verificación de unicidad.
- Sesiones: información mínima guardada (`conversation_code`, `conversation_id`, `authenticated`).
- Mensajes: leídos desde la tabla `Messages` solo si la autenticación por sesión o credenciales pasa.
- Validaciones: longitud de descripción y contraseña mínima (8), sanitización de salidas (ej. `htmlspecialchars` en el frontend cuando se renderice).

---

Si quieres, puedo:
- Generar ejemplos de curl para cada endpoint.
- Añadir endpoint para publicar un mensaje (`post_message`) seguro.
- Migrar la API a rutas REST más limpias (por ejemplo usando PATH_INFO o un pequeño router).
- Implementar limitación de intentos para `continue_conversation`.

¿Quieres que añada ejemplos curl y un endpoint `post_message` básico ahora?
