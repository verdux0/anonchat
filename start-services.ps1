param(
    [string]$HostIp = "127.0.0.1",
    [int]$SpamPort = 8000,
    [int]$PhpPort = 8080,
    [string]$PhpDocRoot = "anonchat"
)

$ErrorActionPreference = "Stop"

function Test-TcpPort {
    param(
        [Parameter(Mandatory = $true)][string]$HostName,
        [Parameter(Mandatory = $true)][int]$Port,
        [int]$TimeoutMs = 800
    )

    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $iar = $client.BeginConnect($HostName, $Port, $null, $null)
        if (-not $iar.AsyncWaitHandle.WaitOne($TimeoutMs, $false)) {
            $client.Close()
            return $false
        }
        $client.EndConnect($iar)
        $client.Close()
        return $true
    }
    catch {
        return $false
    }
}

function Wait-ForHttp {
    param(
        [Parameter(Mandatory = $true)][string]$Url,
        [int]$Retries = 20,
        [int]$SleepMs = 600
    )

    for ($i = 0; $i -lt $Retries; $i++) {
        try {
            $null = Invoke-RestMethod -Method GET -Uri $Url -TimeoutSec 2
            return $true
        }
        catch {
            Start-Sleep -Milliseconds $SleepMs
        }
    }

    return $false
}

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$pythonExe = Join-Path $root ".venv-tf\Scripts\python.exe"
$spamScript = Join-Path $root "spam\check_spam.py"
$phpDocRootPath = Join-Path $root $PhpDocRoot
$spamHealthUrl = "http://${HostIp}:$SpamPort/health"
$phpRouterPath = Join-Path $phpDocRootPath "router.php"

if (-not (Test-Path $pythonExe)) {
    throw "No se encontro .venv-tf. Ejecuta primero .\setup.ps1"
}
if (-not (Test-Path $spamScript)) {
    throw "No se encontro script de spam: $spamScript"
}
if (-not (Test-Path $phpDocRootPath)) {
    throw "No se encontro el docroot PHP: $phpDocRootPath"
}
if (-not (Test-Path $phpRouterPath)) {
    throw "No se encontro el router PHP: $phpRouterPath"
}

if (Test-TcpPort -HostName $HostIp -Port $SpamPort) {
    Write-Host "Spam API ya esta escuchando en ${HostIp}:$SpamPort"
}
else {
    Write-Host "Iniciando Spam API en segundo plano (${HostIp}:$SpamPort)..."

    $spamProcess = Start-Process -FilePath $pythonExe `
        -ArgumentList "check_spam.py" `
        -WorkingDirectory (Join-Path $root "spam") `
        -PassThru

    if (-not (Wait-ForHttp -Url $spamHealthUrl)) {
        try { Stop-Process -Id $spamProcess.Id -Force -ErrorAction SilentlyContinue } catch {}
        throw "La Spam API no respondio en /health despues de iniciar."
    }

    Write-Host "Spam API iniciada (PID $($spamProcess.Id))."
}

if (Test-TcpPort -HostName $HostIp -Port $PhpPort) {
    Write-Host "PHP ya esta escuchando en ${HostIp}:$PhpPort."
    Write-Host "Abre: http://${HostIp}:$PhpPort"
    exit 0
}

Write-Host "Iniciando servidor PHP en primer plano..."
Write-Host "URL: http://${HostIp}:$PhpPort"
Write-Host "DocRoot: $phpDocRootPath"
Write-Host "Router: $phpRouterPath"

Push-Location $root
try {
    & php -S "$HostIp`:$PhpPort" -t $PhpDocRootPath $phpRouterPath
}
finally {
    Pop-Location
}
