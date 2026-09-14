@echo off
REM Ajustar la ruta de PHP y del proyecto si fueran diferentes.
"C:\PHP\php.exe" "C:\inetpub\wwwroot\CLEAR\report_scheduler.php" >> "C:\inetpub\wwwroot\CLEAR\logs\report_scheduler.log" 2>&1
