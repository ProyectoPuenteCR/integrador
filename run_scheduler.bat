@echo off
setlocal EnableExtensions

set "APP_DIR=C:\inetpub\wwwroot\CLEAR"
set "LOG_DIR=%APP_DIR%\logs"
set "LOG_FILE=%LOG_DIR%\report_scheduler_http.log"
set "SCHEDULER_URL=http://10.17.40.37/CLEAR/report_scheduler.php?token=CLEAR-SCHEDULER-2026-X8K4-P9R2-L7M5"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

for /f "tokens=1-3 delims=/ " %%a in ("%date%") do set "LOG_DATE=%%c-%%b-%%a"
set "LOG_TIME=%time: =0%"

echo.>> "%LOG_FILE%"
echo ==================================================>> "%LOG_FILE%"
echo Inicio: %LOG_DATE% %LOG_TIME%>> "%LOG_FILE%"

powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
  "$ErrorActionPreference='Stop';" ^
  "try {" ^
  "  $r = Invoke-WebRequest -UseBasicParsing -Uri '%SCHEDULER_URL%' -TimeoutSec 900;" ^
  "  Add-Content -Path '%LOG_FILE%' -Value ('HTTP ' + $r.StatusCode);" ^
  "  Add-Content -Path '%LOG_FILE%' -Value $r.Content;" ^
  "  exit 0;" ^
  "} catch {" ^
  "  Add-Content -Path '%LOG_FILE%' -Value ('ERROR: ' + $_.Exception.Message);" ^
  "  exit 1;" ^
  "}"

set "EXIT_CODE=%ERRORLEVEL%"
echo Codigo de salida: %EXIT_CODE%>> "%LOG_FILE%"
echo Fin: %date% %time%>> "%LOG_FILE%"

exit /b %EXIT_CODE%
