$ErrorActionPreference = 'Stop'
$taskName = 'CLEAR - Reportes automaticos'
$appDir = 'C:\inetpub\wwwroot\CLEAR'
$runner = Join-Path $appDir 'run_scheduler.bat'

if (-not (Test-Path $runner)) {
    throw "No se encontro $runner. Copie primero el parche sobre la carpeta de CLEAR."
}

$action = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument ('/c "' + $runner + '"') -WorkingDirectory $appDir
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 20)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Start-ScheduledTask -TaskName $taskName
Write-Host "Tarea instalada y ejecutada: $taskName"
Write-Host "Revise Reportes por correo: el estado debe aparecer Activo dentro de los proximos minutos."
