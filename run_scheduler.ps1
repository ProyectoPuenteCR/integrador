$uri = "http://10.17.40.37/CLEAR/report_scheduler.php?token=CLEAR-SCHEDULER-2026-X8K4-P9R2-L7M5"
$log = "C:\inetpub\wwwroot\CLEAR\logs\report_scheduler_http.log"

$logDirectory = Split-Path $log -Parent

if (-not (Test-Path $logDirectory)) {
    New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
}

try {
    $response = Invoke-WebRequest `
        -UseBasicParsing `
        -Uri $uri `
        -TimeoutSec 900

    $line = "{0} OK HTTP {1} {2}" -f `
        (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), `
        $response.StatusCode, `
        $response.Content

    Add-Content -Path $log -Value $line -Encoding UTF8
    exit 0
}
catch {
    $line = "{0} ERROR {1}" -f `
        (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), `
        $_.Exception.Message

    Add-Content -Path $log -Value $line -Encoding UTF8
    exit 1
}