$ErrorActionPreference = "Stop"

param(
    [switch]$CleanupOldVenv
)

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$spamSetup = Join-Path $root "spam\setup_tf_env.ps1"
$legacyVenv = Join-Path $root ".venv"

if (-not (Test-Path $spamSetup)) {
    throw "No se encontro script de setup en: $spamSetup"
}

Write-Host "[1/2] Configurando entorno Python para Spam API (.venv-tf)..."
& powershell -ExecutionPolicy Bypass -File $spamSetup

if (Test-Path $legacyVenv) {
    if ($CleanupOldVenv) {
        Write-Host "Eliminando entorno legacy .venv..."
        Remove-Item -Recurse -Force $legacyVenv
    }
    else {
        Write-Host "Aviso: existe .venv legacy. Ejecuta .\setup.ps1 -CleanupOldVenv para eliminarlo."
    }
}

Write-Host "[2/2] Setup completado."
Write-Host "Para arrancar la API de spam: .\run-spam.ps1"
