# AnonChat

AnonChat es un sistema de chat anónimo orientado a entornos tipo RRHH: un usuario crea una conversación protegida con contraseña y obtiene un código único; con ese código puede continuar la conversación y chatear con el equipo administrador.

Arquitectura:
- App principal: PHP + MySQL/MariaDB (carpeta `anonchat/`).
- API HTTP versionada (v1) con router para desarrollo (sirviendo bajo `/api/v1/...`).
- Microservicio opcional anti-spam: FastAPI + TensorFlow (carpeta `spam/`).

## Contenido
- [Quickstart (Windows)](#quickstart-windows)
- [Requisitos](#requisitos)
- [Estructura](#estructura)
- [Base de datos](#base-de-datos)
- [Arranque](#arranque)
- [Configuración](#configuración)
- [Documentación](#documentación)
- [Seguridad](#seguridad)
- [Soporte](#soporte)
- [Contribuir](#contribuir)
- [Licencia](#licencia)
- [Estado del proyecto](#estado-del-proyecto)

## Quickstart (Windows)

1) Crear la base de datos y tablas:

```powershell
Get-Content .\scripts\DB\create_DB.sql | mysql -u root -p
Get-Content .\scripts\DB\seed.sql | mysql -u root -p
```

2) Configurar credenciales de MySQL en `anonchat/api/db.php`.

3) Levantar el servidor PHP (desarrollo):

```powershell
php -S 127.0.0.1:8080 -t .\anonchat .\anonchat\router.php
```

4) Abrir la app:
- Usuario: `http://127.0.0.1:8080/index.php`
- Admin: `http://127.0.0.1:8080/admin.php`

Opcional (anti-spam): ver [spam/README_API.md](spam/README_API.md).

## Requisitos

- PHP 7.4+ (recomendado: PHP 8.x)
- MySQL/MariaDB
- (Opcional) Python para la Spam API

## Estructura

Archivos/carpetas clave:

- `anonchat/` — aplicación PHP (UI + endpoints HTTP)
- `anonchat/router.php` — router para `php -S` (permite rutas limpias como `/api/v1/...` en desarrollo)
- `anonchat/api/v1/index.php` — front controller de la API v1
- `anonchat/api/api.php` — API legacy basada en `?action=...` (compatibilidad)
- `start-services.ps1` — orquestador: Spam API (si aplica) + servidor PHP
- `run-spam.ps1` — arranque solo de la Spam API

## Base de datos

Los scripts SQL viven en:
- `scripts/DB/create_DB.sql` (crea `anonchatDB`)
- `scripts/DB/seed.sql` (datos de ejemplo)

Nota: en PowerShell no funciona el operador `<` para redirección a `mysql` igual que en bash; por eso los ejemplos usan `Get-Content ... | mysql ...`.

## Arranque

### Solo PHP (sin anti-spam)

```powershell
php -S 127.0.0.1:8080 -t .\anonchat .\anonchat\router.php
```

### Spam API + PHP (orquestado)

Este repositorio incluye un script que levanta la Spam API si no está corriendo y después arranca el servidor PHP:

```powershell
powershell -ExecutionPolicy Bypass -File .\start-services.ps1
```

Parámetros útiles:
- `-PhpPort 8080`
- `-SpamPort 8000`
- `-HostIp 127.0.0.1`

## Configuración

### Conexión a MySQL

Editar `anonchat/api/db.php` y ajustar `host`, `dbname`, `user`, `pass`.

### Anti-spam (opcional)

La app PHP llama al predictor vía HTTP. Variables de entorno:
- `SPAM_API_URL` (por defecto `http://127.0.0.1:8000/predict`)
- `SPAM_API_TIMEOUT` (segundos, por defecto `4`, rango `1..15`)

Más detalles en [spam/README_API.md](spam/README_API.md).

### Rate limiting / sesión (PHP)

Parámetros ajustables en `anonchat/api/config.php`.

## Documentación

- API: [docs/API_docs.md](docs/API_docs.md)
- Spam API: [spam/README_API.md](spam/README_API.md)

Diagrama (estados):
- [docs/img/maquinaEstados.jpg](docs/img/maquinaEstados.jpg)

## Seguridad

Medidas incluidas (nivel básico):
- Gestión segura de credenciales (hash de contraseñas).
- Sesión y protección anti-CSRF en acciones sensibles.
- Cabeceras de seguridad a nivel HTTP.

Recomendación para producción:
- Servir siempre bajo HTTPS.
- Revisar CSP/headers según tu dominio.
- Rotar credenciales y no usar los datos de `seed.sql`.

## Soporte

Si algo falla al arrancar, revisa:
- Conexión a MySQL en `anonchat/api/db.php`.
- Que el puerto `8080` esté libre (o cambia `-PhpPort`).
- Si usas anti-spam: que `http://127.0.0.1:8000/health` responda.

Si vas a mantener el proyecto, suele merecer la pena llevar un `CHANGELOG.md` (por ejemplo, siguiendo el formato “Keep a Changelog”) para que los cambios sean fáciles de seguir.

## Contribuir

- Issues y PRs son bienvenidos.
- Mantén los cambios pequeños y enfocados.
- Para cambios en PHP, al menos valida sintaxis con `php -l` sobre los ficheros tocados.

## Licencia

Actualmente el repositorio no incluye un fichero `LICENSE`. Si vas a publicarlo o aceptar contribuciones externas, añade una licencia explícita (p. ej. MIT/GPL/Apache) para evitar ambigüedades legales.

## Estado del proyecto

Proyecto en evolución (orientado a entorno local / desarrollo). La API y el frontend pueden cambiar mientras se endurecen aspectos de seguridad y se completa la documentación de despliegue.

