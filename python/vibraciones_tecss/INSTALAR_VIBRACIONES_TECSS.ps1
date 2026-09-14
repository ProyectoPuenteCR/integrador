[CmdletBinding()]
param(
    [string]$InstallDir = "C:\CLEAR\VibracionesTECSS",
    [ValidateRange(5, 1440)]
    [int]$IntervalMinutes = 15,
    [switch]$RegisterTask,
    [System.Management.Automation.PSCredential]$TaskCredential
)

$ErrorActionPreference = "Stop"
$SourceDir = Split-Path -Parent $MyInvocation.MyCommand.Path

function Resolve-Python {
    $launcher = Get-Command py.exe -ErrorAction SilentlyContinue
    if ($launcher) {
        & $launcher.Source -3.11 --version *> $null
        if ($LASTEXITCODE -eq 0) {
            return @{ Command = $launcher.Source; Prefix = @("-3.11") }
        }
    }
    $python = Get-Command python.exe -ErrorAction SilentlyContinue
    if ($python) {
        $version = & $python.Source -c "import sys; print(f'{sys.version_info.major}.{sys.version_info.minor}')"
        if ([version]$version -ge [version]"3.10" -and [version]$version -lt [version]"3.13") {
            return @{ Command = $python.Source; Prefix = @() }
        }
    }
    throw "No se encontro Python 3.10, 3.11 o 3.12 de 64 bits. Instale Python 3.11 x64 y vuelva a ejecutar este instalador."
}

$Python = Resolve-Python
New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null

foreach ($name in @("vibraciones_tecss.py", "requirements.txt", "config.example.json", "EJECUTAR_AHORA.cmd")) {
    Copy-Item -LiteralPath (Join-Path $SourceDir $name) -Destination (Join-Path $InstallDir $name) -Force
}

$VenvDir = Join-Path $InstallDir ".venv"
if (-not (Test-Path (Join-Path $VenvDir "Scripts\python.exe"))) {
    & $Python.Command @($Python.Prefix) -m venv $VenvDir
    if ($LASTEXITCODE -ne 0) { throw "No se pudo crear el entorno virtual de Python." }
}

$VenvPython = Join-Path $VenvDir "Scripts\python.exe"
& $VenvPython -m pip install --disable-pip-version-check --upgrade pip
if ($LASTEXITCODE -ne 0) { throw "No se pudo actualizar pip." }
& $VenvPython -m pip install --disable-pip-version-check -r (Join-Path $InstallDir "requirements.txt")
if ($LASTEXITCODE -ne 0) { throw "No se pudieron instalar las dependencias Python." }

& $VenvPython (Join-Path $InstallDir "vibraciones_tecss.py") --self-test
if ($LASTEXITCODE -ne 0) { throw "La autoprueba del analizador no fue satisfactoria." }

$ConfigPath = Join-Path $InstallDir "config.json"
$CreatedConfig = $false
if (-not (Test-Path $ConfigPath)) {
    Copy-Item -LiteralPath (Join-Path $InstallDir "config.example.json") -Destination $ConfigPath
    $CreatedConfig = $true
}

if ($RegisterTask) {
    if ($CreatedConfig) {
        throw "Se creo $ConfigPath. Complete primero sus credenciales y vuelva a ejecutar con -RegisterTask."
    }
    & $VenvPython (Join-Path $InstallDir "vibraciones_tecss.py") --config $ConfigPath --check
    if ($LASTEXITCODE -ne 0) { throw "La verificacion de conexiones fallo. No se registro la tarea." }

    # Protege la configuracion: solo SYSTEM y Administradores conservan acceso.
    & icacls.exe $ConfigPath /inheritance:r /grant:r '*S-1-5-18:F' '*S-1-5-32-544:F' | Out-Null

    if ($null -eq $TaskCredential) {
        $DefaultTaskUser = "$env:USERDOMAIN\$env:USERNAME"
        $TaskCredential = Get-Credential -UserName $DefaultTaskUser `
            -Message "Cuenta de Windows autorizada en SQL Server (por ejemplo SWPCRDLHSC051\Admin)"
    }
    if ($null -eq $TaskCredential -or [string]::IsNullOrWhiteSpace($TaskCredential.UserName)) {
        throw "Debe indicar la cuenta de Windows que tiene acceso a SQL Server."
    }

    $TaskName = "CLEAR - Vibraciones TECSS"
    $Arguments = '"' + (Join-Path $InstallDir "vibraciones_tecss.py") + '" --config "' + $ConfigPath + '"'
    $Action = New-ScheduledTaskAction -Execute $VenvPython -Argument $Arguments -WorkingDirectory $InstallDir
    $Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
        -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) `
        -RepetitionDuration (New-TimeSpan -Days 3650)
    $Settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -RestartCount 2 `
        -RestartInterval (New-TimeSpan -Minutes 2)
    $PasswordPtr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($TaskCredential.Password)
    try {
        $TaskPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($PasswordPtr)
        Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger `
            -Settings $Settings -User $TaskCredential.UserName -Password $TaskPassword `
            -RunLevel Highest -Description "Actualiza el analisis estadistico de vibraciones TECSS para CLEAR." -Force | Out-Null
    }
    finally {
        if ($PasswordPtr -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($PasswordPtr)
        }
        $TaskPassword = $null
    }
    Start-ScheduledTask -TaskName $TaskName
    Write-Host "Tarea '$TaskName' registrada cada $IntervalMinutes minutos con la cuenta $($TaskCredential.UserName)." -ForegroundColor Green
}

Write-Host "Componentes Python instalados en $InstallDir" -ForegroundColor Green
if (-not $RegisterTask) {
    Write-Host "Complete $ConfigPath y luego ejecute:" -ForegroundColor Yellow
    Write-Host ".\INSTALAR_VIBRACIONES_TECSS.ps1 -InstallDir `"$InstallDir`" -RegisterTask"
}
