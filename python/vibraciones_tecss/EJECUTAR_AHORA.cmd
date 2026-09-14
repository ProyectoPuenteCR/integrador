@echo off
setlocal
set "CLEAR_VIB_DIR=%~dp0"
"%CLEAR_VIB_DIR%.venv\Scripts\python.exe" "%CLEAR_VIB_DIR%vibraciones_tecss.py" --config "%CLEAR_VIB_DIR%config.json"
set "CLEAR_VIB_EXIT=%ERRORLEVEL%"
echo.
if not "%CLEAR_VIB_EXIT%"=="0" echo El analisis finalizo con error. Revise la carpeta logs.
if "%CLEAR_VIB_EXIT%"=="0" echo Analisis completado correctamente.
exit /b %CLEAR_VIB_EXIT%
