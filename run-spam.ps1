$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$pythonExe = Join-Path $root ".venv-tf\Scripts\python.exe"
$spamDir = Join-Path $root "spam"

if (-not (Test-Path $pythonExe)) {
    throw "No se encontro .venv-tf. Ejecuta primero .\setup.ps1"
}

Push-Location $spamDir
try {
    & $pythonExe "check_spam.py"
}
finally {
    Pop-Location
}
