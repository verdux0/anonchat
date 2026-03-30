# Spam API (FastAPI)

Este servicio expone el modelo de detección de spam como microservicio HTTP para evitar ejecutar Python por cada mensaje en PHP.

## Qué hace

- Expone `GET /health` para comprobar estado.
- Expone `POST /predict` para clasificar texto (`is_spam` + probabilidad).

## Requisitos

- Windows x64 (los scripts incluyen automatización con PowerShell).
- Python instalado (los scripts de setup intentan usar `py -3.13`).

## Setup (crear entorno e instalar dependencias)

El repo usa un único entorno virtual para spam: `.venv-tf`.

Desde la raíz del proyecto:

Windows:
```powershell
./setup.ps1
```

Linux/macOS:
```bash
./setup.sh
```

Opcional: eliminar `.venv` legacy
- Windows: `./setup.ps1 -CleanupOldVenv`
- Linux/macOS: `./setup.sh --cleanup-old-venv`

## Arranque

Desde la raíz del proyecto:

Windows:
```powershell
./run-spam.ps1
```

Linux/macOS:
```bash
./run-spam.sh
```

Por defecto escucha en:
- `http://127.0.0.1:8000`

Endpoints:
- `GET /health`
- `POST /predict`

## Integración con PHP

El backend PHP llama al predictor vía HTTP. Configuración mediante variables de entorno:

- `SPAM_API_URL` (por defecto `http://127.0.0.1:8000/predict`)
- `SPAM_API_TIMEOUT` (segundos, por defecto `4`, rango `1..15`)

Para levantar todo junto (Spam API + servidor PHP) existe el script de orquestación en la raíz:

```powershell
powershell -ExecutionPolicy Bypass -File ./start-services.ps1
```

## Ejemplo de petición

PowerShell:

```powershell
$body = @{ text = 'Mensaje a clasificar' } | ConvertTo-Json
Invoke-RestMethod -Method POST -Uri http://127.0.0.1:8000/predict -Body $body -ContentType 'application/json'
```

Respuesta (ejemplo):

```json
{
  "is_spam": false,
  "spam_probability": 0.123456,
  "threshold": 0.5,
  "model": "modelo_spam_completo.keras"
}
```

## Troubleshooting

- Si `GET /health` no responde, revisa que el proceso siga corriendo y que el puerto `8000` esté libre.
- Si PHP “no marca” spam:
  - Comprueba `SPAM_API_URL` y `SPAM_API_TIMEOUT`.
  - Prueba `POST /predict` manualmente para validar el modelo.

## Licencia

La Spam API forma parte del repositorio principal. Consulta la sección “Licencia” del README raíz para definir el uso y redistribución del proyecto.
