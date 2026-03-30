$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Resolve-Path (Join-Path $scriptDir "..")
$venvPath = Join-Path $projectRoot ".venv-tf"
$requirementsPath = Join-Path $scriptDir "requirements_api.txt"

function Get-Python313Path {
    try {
        $path = & py -3.13 -c "import sys; print(sys.executable)" 2>$null
        if ($LASTEXITCODE -eq 0 -and $path) {
            return $path.Trim()
        }
    }
    catch {
        return $null
    }
    return $null
}

$python313 = Get-Python313Path

if (-not $python313) {
    if (Get-Command winget -ErrorAction SilentlyContinue) {
        Write-Host "Python 3.13 no encontrado. Instalando con winget..."
        winget install --id Python.Python.3.13 -e --accept-package-agreements --accept-source-agreements
        $python313 = Get-Python313Path
    }
}

if (-not $python313) {
    throw "No se encontro Python 3.13. Instala Python 3.13 y vuelve a ejecutar este script."
}

if (-not (Test-Path $venvPath)) {
    Write-Host "Creando entorno virtual en $venvPath"
    & py -3.13 -m venv $venvPath
}

$pythonExe = Join-Path $venvPath "Scripts\python.exe"
if (-not (Test-Path $pythonExe)) {
    throw "No se encontro el ejecutable del entorno virtual: $pythonExe"
}

Write-Host "Actualizando pip/setuptools/wheel..."
& $pythonExe -m pip install --upgrade pip setuptools wheel

Write-Host "Instalando dependencias de Spam API..."
& $pythonExe -m pip install -r $requirementsPath

Write-Host "Listo. Para activar el entorno:"
Write-Host "  $venvPath\Scripts\Activate.ps1"
Write-Host "Para arrancar la API:"
Write-Host "  cd $scriptDir"
Write-Host "  $pythonExe check_spam.py"
