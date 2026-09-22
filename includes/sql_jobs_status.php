<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/sql_jobs_status.php
   Seguimiento de Jobs del SQL Server Agent (SOLO LECTURA).
   - Lee msdb: sysjobs, sysjobschedules, sysschedules,
     sysjobhistory, sysjobactivity, syssessions.
   - No crea, modifica ni ejecuta Jobs.
   - Consultas separadas y unión en PHP (evita LEFT JOIN + ISNULL
     que pueden cortar el proceso PHP vía COM).
   Compatible PHP 7.4.
============================================================= */

/* Jobs que instala este proyecto (scripts de /SQL) + backup semanal.
   La clave se normaliza, por eso no importan tildes ni mayúsculas. */
function sqljobs_expected()
{
    return [
        'CLEAR - Aplicar configuración de alarmas' => ['modulo' => 'Configuración global CFN',        'script' => 'SQL/CLEAR_CONFIGURACION_GLOBAL_CFN_20260908.sql'],
        'CLEAR - Alarmas SCADA Real time'          => ['modulo' => 'SCADA Real time',                 'script' => 'SQL/CLEAR_SCADA_REALTIME_20260908.sql'],
        'CLEAR - Sincronizar puntos PI SCADA'      => ['modulo' => 'SCADA Real time (mapa PI)',       'script' => 'SQL/CLEAR_SCADA_REALTIME_MEJORAS_GRILLA_PI_20260908.sql'],
        'CLEAR - Caché operativa cada 5 minutos'   => ['modulo' => 'Dashboards / caché operativa',    'script' => 'SQL/10_OPTIMIZACION_GLOBAL_CLEAR8.sql'],
        'CLEAR - Actualizar caché Top 20 24H'      => ['modulo' => 'Top 20 alarmas 24H',              'script' => 'SQL/01_CREAR_CACHE_TOP20_24H_Y_JOB.sql'],
        'CLEAR - Actualizar grilla general de pozos' => ['modulo' => 'Grilla general de pozos',       'script' => 'SQL/CLEAR_TELEMETRIA_POZOS_CACHE_JOB.sql'],
        'CLEAR - Alarmas semanal caché'            => ['modulo' => 'Alarmas semanales',               'script' => 'SQL/CLEAR_ALARMAS_SEMANAL_CACHE_JOB.sql'],
        'CLEAR - Actualizar Inyección de Agua'     => ['modulo' => 'Inyección de Agua',               'script' => 'SQL/CLEAR_INYECCION_AGUA_CACHE_JOB.sql'],
        'CLEAR - Novedades semanales caché'        => ['modulo' => 'Novedades semanales',             'script' => 'SQL/CLEAR_NOVEDADES_SEMANALES.sql'],
        'CLEAR - Pozos sin telemetria Zafiro'      => ['modulo' => 'Sin telemetría en Zafiro',        'script' => 'SQL/CLEAR_ZAFIRO_SIN_TELEMETRIA_JOB.sql'],
        'CLEAR - Actualizar paro remoto'           => ['modulo' => 'Monitoreo Pozos · paro remoto',   'script' => 'SQL/CLEAR_PARO_REMOTO_TABLA_JOB.sql'],
        'CLEAR - Histórico TECSS diario 06hs'      => ['modulo' => '3Sigma TECSS',                    'script' => 'SQL/CLEAR_TECSS_3SIGMA_CACHE_JOB.sql'],
        'CLEAR - Carga diaria MASICOS SC'          => ['modulo' => 'Micros contables',                'script' => 'SQL/CLEAR_MICROS_CONTABLES.sql'],
        'Backup Semanal - Bases de Datos'          => ['modulo' => 'Backup LC_MDB / PIFD / pivision', 'script' => 'Informe técnico 12/05/2026'],
    ];
}

/* Clave de comparación: minúsculas y solo [a-z0-9].
   Tolera tildes y nombres creados con codificación distinta. */
function sqljobs_key($name)
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower((string)$name));
}

/* ¿Este job se muestra aunque no esté en la lista? (otros "CLEAR - ...") */
function sqljobs_is_project_job($name)
{
    return strpos(sqljobs_key($name), 'clear') === 0;
}

/* 20260917 + 60500 -> '2026-09-17 06:05:00' (hora local del servidor SQL) */
function sqljobs_agent_datetime($date, $time)
{
    $d = (int)$date;
    $t = (int)$time;
    if ($d <= 0) return null;
    $y = intdiv($d, 10000); $m = intdiv($d, 100) % 100; $dd = $d % 100;
    $h = intdiv($t, 10000); $i = intdiv($t, 100) % 100; $s = $t % 100;
    return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $m, $dd, $h, $i, $s);
}

/* run_duration HHMMSS -> segundos */
function sqljobs_duration_seconds($hhmmss)
{
    $v = (int)$hhmmss;
    return intdiv($v, 10000) * 3600 + (intdiv($v, 100) % 100) * 60 + ($v % 100);
}

function sqljobs_fmt_duration($seconds)
{
    $s = (int)$seconds;
    if ($s < 60) return $s . ' s';
    if ($s < 3600) return intdiv($s, 60) . ' min ' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT) . ' s';
    return intdiv($s, 3600) . ' h ' . str_pad((string)(intdiv($s, 60) % 60), 2, '0', STR_PAD_LEFT) . ' min';
}

function sqljobs_fmt_age($seconds)
{
    if ($seconds === null) return '';
    $s = max(0, (int)$seconds);
    if ($s < 60) return 'hace ' . $s . ' s';
    if ($s < 3600) return 'hace ' . intdiv($s, 60) . ' min';
    if ($s < 86400) return 'hace ' . intdiv($s, 3600) . ' h ' . (intdiv($s, 60) % 60) . ' min';
    return 'hace ' . intdiv($s, 86400) . ' d ' . (intdiv($s, 3600) % 24) . ' h';
}

function sqljobs_fmt_dt($dt)
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i:s', $ts) : (string)$dt;
}

function sqljobs_fmt_hhmm($t)
{
    $t = (int)$t;
    return sprintf('%02d:%02d', intdiv($t, 10000), intdiv($t, 100) % 100);
}

/* Período esperado (segundos) y descripción de una programación. */
function sqljobs_schedule_info(array $s)
{
    $type   = (int)$s['FREQ_TYPE'];
    $inter  = (int)$s['FREQ_INTERVAL'];
    $subT   = (int)$s['FREQ_SUBDAY_TYPE'];
    $subN   = max(1, (int)$s['FREQ_SUBDAY_INTERVAL']);
    $rf     = max(1, (int)$s['FREQ_RECURRENCE_FACTOR']);
    $start  = sqljobs_fmt_hhmm($s['ACTIVE_START_TIME']);

    $sub = null; $subTxt = '';
    if ($subT === 2) { $sub = $subN;        $subTxt = 'cada ' . $subN . ' s'; }
    if ($subT === 4) { $sub = $subN * 60;   $subTxt = 'cada ' . $subN . ' min'; }
    if ($subT === 8) { $sub = $subN * 3600; $subTxt = 'cada ' . $subN . ' h'; }

    if ($type === 4) {
        $base = max(1, $inter) * 86400;
        if ($sub !== null) return [$sub, ucfirst($subTxt) . ($start !== '00:00' ? ' (desde ' . $start . ')' : '')];
        return [$base, ($inter > 1 ? 'Cada ' . $inter . ' días' : 'Diario') . ' ' . $start];
    }
    if ($type === 8) {
        $dias = ['Dom' => 1, 'Lun' => 2, 'Mar' => 4, 'Mié' => 8, 'Jue' => 16, 'Vie' => 32, 'Sáb' => 64];
        $sel = [];
        foreach ($dias as $n => $bit) if ($inter & $bit) $sel[] = $n;
        $txt = 'Semanal (' . implode(', ', $sel) . ')' . ($rf > 1 ? ' cada ' . $rf . ' semanas' : '');
        if ($sub !== null) return [$sub, $txt . ' ' . $subTxt];
        return [7 * 86400 * $rf, $txt . ' ' . $start];
    }
    if ($type === 16 || $type === 32) return [31 * 86400 * $rf, 'Mensual ' . $start];
    if ($type === 1)   return [null, 'Una sola vez'];
    if ($type === 64)  return [null, 'Al iniciar SQL Agent'];
    if ($type === 128) return [null, 'Cuando el servidor está inactivo'];
    return [null, 'Programación tipo ' . $type];
}

/* Lectura completa. Devuelve ['ok'=>bool,'error'=>..., 'jobs'=>[...], 'agent'=>[...], 'summary'=>[...]] */
function sqljobs_status(DB $db)
{
    $out = ['ok' => false, 'error' => '', 'jobs' => [], 'agent' => [], 'summary' => [], 'server_now' => null];
    if (!$db->ok()) { $out['error'] = 'Sin conexión a SQL Server: ' . $db->error(); return $out; }

    /* 1) Prueba de permisos: devuelve siempre UNA fila si hay acceso. */
    $probe = $db->all(
        "SELECT CONVERT(varchar(19), GETDATE(), 120) AS AHORA,"
        . " (SELECT COUNT(*) FROM msdb.dbo.sysjobs WHERE 1 = 0) AS P1,"
        . " (SELECT COUNT(*) FROM msdb.dbo.sysjobhistory WHERE 1 = 0) AS P2,"
        . " (SELECT COUNT(*) FROM msdb.dbo.sysjobschedules WHERE 1 = 0) AS P3,"
        . " (SELECT COUNT(*) FROM msdb.dbo.sysschedules WHERE 1 = 0) AS P4,"
        . " (SELECT COUNT(*) FROM msdb.dbo.sysjobactivity WHERE 1 = 0) AS P5,"
        . " (SELECT COUNT(*) FROM msdb.dbo.syssessions WHERE 1 = 0) AS P6"
    );
    if (empty($probe)) {
        $out['error'] = 'La cuenta web no puede leer los Jobs en msdb. Ejecutar SQL/CLEAR_ESTADO_JOBS_PERMISOS_20260917.sql. Detalle: ' . $db->error();
        return $out;
    }
    $nowStr = $probe[0]['AHORA'];
    $now = strtotime($nowStr) ?: time();
    $out['server_now'] = $nowStr;

    /* 2) Jobs */
    $jobsRaw = $db->all(
        "SELECT CONVERT(varchar(36), job_id) AS JOB_ID, name AS NOMBRE, CAST(enabled AS int) AS HABILITADO,"
        . " CONVERT(varchar(19), date_modified, 120) AS MODIFICADO FROM msdb.dbo.sysjobs"
    );

    /* 3) Programaciones */
    $schedRaw = $db->all(
        "SELECT CONVERT(varchar(36), js.job_id) AS JOB_ID, CAST(s.enabled AS int) AS HAB,"
        . " s.freq_type AS FREQ_TYPE, s.freq_interval AS FREQ_INTERVAL, s.freq_subday_type AS FREQ_SUBDAY_TYPE,"
        . " s.freq_subday_interval AS FREQ_SUBDAY_INTERVAL, s.freq_recurrence_factor AS FREQ_RECURRENCE_FACTOR,"
        . " s.active_start_time AS ACTIVE_START_TIME, js.next_run_date AS NEXT_DATE, js.next_run_time AS NEXT_TIME"
        . " FROM msdb.dbo.sysjobschedules js INNER JOIN msdb.dbo.sysschedules s ON s.schedule_id = js.schedule_id"
    );

    /* 4) Últimas 15 ejecuciones por job (fila resumen step_id = 0) */
    $histRaw = $db->all(
        "SELECT JOB_ID, ESTADO, FECHA, HORA, DUR, MSG FROM ("
        . " SELECT CONVERT(varchar(36), job_id) AS JOB_ID, instance_id AS ID, run_status AS ESTADO,"
        . " run_date AS FECHA, run_time AS HORA, run_duration AS DUR, LEFT(message, 600) AS MSG,"
        . " ROW_NUMBER() OVER (PARTITION BY job_id ORDER BY instance_id DESC) AS RN"
        . " FROM msdb.dbo.sysjobhistory WHERE step_id = 0) h WHERE h.RN <= 15 ORDER BY JOB_ID, ID DESC"
    );

    /* 5) Último paso fallido por job (mensaje útil del error) */
    $failRaw = $db->all(
        "SELECT JOB_ID, PASO, FECHA, HORA, MSG FROM ("
        . " SELECT CONVERT(varchar(36), job_id) AS JOB_ID, step_name AS PASO, run_date AS FECHA, run_time AS HORA,"
        . " LEFT(message, 1500) AS MSG, ROW_NUMBER() OVER (PARTITION BY job_id ORDER BY instance_id DESC) AS RN"
        . " FROM msdb.dbo.sysjobhistory WHERE step_id > 0 AND run_status = 0) f WHERE f.RN = 1"
    );

    /* 6) Conteo de las últimas 24 h (según historial disponible) */
    $cntRaw = $db->all(
        "SELECT CONVERT(varchar(36), job_id) AS JOB_ID,"
        . " SUM(CASE WHEN run_status = 1 THEN 1 ELSE 0 END) AS OK_N,"
        . " SUM(CASE WHEN run_status = 0 THEN 1 ELSE 0 END) AS FAIL_N,"
        . " COUNT(*) AS TOT"
        . " FROM msdb.dbo.sysjobhistory"
        . " WHERE step_id = 0 AND CAST(run_date AS bigint) * 1000000 + run_time >="
        . " CAST(CONVERT(char(8), DATEADD(hour, -24, GETDATE()), 112) AS bigint) * 1000000"
        . " + CAST(REPLACE(CONVERT(char(8), DATEADD(hour, -24, GETDATE()), 108), ':', '') AS int)"
        . " GROUP BY job_id"
    );

    /* 7) En ejecución ahora (sesión actual del Agent) */
    $runRaw = $db->all(
        "SELECT CONVERT(varchar(36), a.job_id) AS JOB_ID, CONVERT(varchar(19), a.start_execution_date, 120) AS INICIO"
        . " FROM msdb.dbo.sysjobactivity a"
        . " WHERE a.session_id = (SELECT MAX(session_id) FROM msdb.dbo.syssessions)"
        . " AND a.start_execution_date IS NOT NULL AND a.stop_execution_date IS NULL"
    );

    /* 8) SQL Agent: último inicio y (si hay permiso VIEW SERVER STATE) estado del servicio */
    $agentStart = $db->scalar("SELECT CONVERT(varchar(19), MAX(agent_start_date), 120) FROM msdb.dbo.syssessions");
    $svc = $db->all("SELECT status_desc AS ESTADO FROM sys.dm_server_services WHERE servicename LIKE N'SQL Server Agent%'");

    /* ---- Índices por JOB_ID ---- */
    $sched = []; foreach ($schedRaw as $r) $sched[$r['JOB_ID']][] = $r;
    $hist  = []; foreach ($histRaw as $r)  $hist[$r['JOB_ID']][]  = $r;
    $fail  = []; foreach ($failRaw as $r)  $fail[$r['JOB_ID']]    = $r;
    $cnt   = []; foreach ($cntRaw as $r)   $cnt[$r['JOB_ID']]     = $r;
    $run   = []; foreach ($runRaw as $r)   $run[$r['JOB_ID']]     = $r;

    $expected = sqljobs_expected();
    $expByKey = [];
    foreach ($expected as $name => $meta) $expByKey[sqljobs_key($name)] = $name;

    $found = [];
    $jobs = [];
    $latestAnyRun = null;

    foreach ($jobsRaw as $j) {
        $key = sqljobs_key($j['NOMBRE']);
        $isExpected = isset($expByKey[$key]);
        if (!$isExpected && !sqljobs_is_project_job($j['NOMBRE'])) continue;
        $meta = $isExpected ? $expected[$expByKey[$key]] : ['modulo' => 'Otro Job CLEAR', 'script' => '—'];
        if ($isExpected) $found[$key] = true;

        $id = $j['JOB_ID'];

        /* Programación */
        $period = null; $freqTxt = []; $hasEnabledSched = false; $next = null;
        foreach (($sched[$id] ?? []) as $s) {
            list($p, $txt) = sqljobs_schedule_info($s);
            $freqTxt[] = $txt . ((int)$s['HAB'] === 1 ? '' : ' (deshabilitada)');
            if ((int)$s['HAB'] === 1) {
                $hasEnabledSched = true;
                if ($p !== null && ($period === null || $p < $period)) $period = $p;
                $n = sqljobs_agent_datetime($s['NEXT_DATE'], $s['NEXT_TIME']);
                if ($n && ($next === null || $n < $next)) $next = $n;
            }
        }

        /* Historial */
        $runs = [];
        foreach (($hist[$id] ?? []) as $h) {
            $start = sqljobs_agent_datetime($h['FECHA'], $h['HORA']);
            $runs[] = [
                'inicio'   => $start,
                'estado'   => (int)$h['ESTADO'],
                'duracion' => sqljobs_duration_seconds($h['DUR']),
                'mensaje'  => (string)$h['MSG'],
            ];
        }
        $last = $runs[0] ?? null;
        $lastAge = null;
        if ($last && $last['inicio']) {
            $lastEnd = strtotime($last['inicio']) + $last['duracion'];
            $lastAge = $now - strtotime($last['inicio']);
            if ($latestAnyRun === null || $lastEnd > $latestAnyRun) $latestAnyRun = $lastEnd;
        }

        /* Estado */
        $running = isset($run[$id]);
        $tolerance = $period !== null ? $period + max(600, (int)round($period * 0.5)) : null;
        $late = ($tolerance !== null && $lastAge !== null && $lastAge > $tolerance);

        if ((int)$j['HABILITADO'] !== 1)      { $st = 'disabled'; $stTxt = 'Deshabilitado'; }
        elseif ($running)                      { $st = 'running';  $stTxt = 'En ejecución'; }
        elseif (!$hasEnabledSched)             { $st = 'warn';     $stTxt = 'Sin programación activa'; }
        elseif (!$last)                        { $st = 'warn';     $stTxt = 'Sin ejecuciones registradas'; }
        elseif ($last['estado'] === 0)         { $st = 'fail';     $stTxt = 'Falló'; }
        elseif ($last['estado'] === 3)         { $st = 'warn';     $stTxt = 'Cancelado'; }
        elseif ($last['estado'] === 2)         { $st = 'warn';     $stTxt = 'Reintentando'; }
        elseif ($late)                         { $st = 'late';     $stTxt = 'Atrasado'; }
        else                                   { $st = 'ok';       $stTxt = 'OK'; }
        if ($running && $late) $stTxt = 'En ejecución (más de lo esperado)';

        $jobs[] = [
            'nombre'      => $j['NOMBRE'],
            'modulo'      => $meta['modulo'],
            'script'      => $meta['script'],
            'habilitado'  => (int)$j['HABILITADO'] === 1,
            'estado'      => $st,
            'estado_txt'  => $stTxt,
            'frecuencia'  => $freqTxt ? implode(' · ', $freqTxt) : 'Sin programación',
            'periodo'     => $period,
            'ultima'      => $last,
            'ultima_edad' => $lastAge,
            'proxima'     => $next,
            'corriendo'   => $running ? $run[$id]['INICIO'] : null,
            'ok24'        => isset($cnt[$id]) ? (int)$cnt[$id]['OK_N'] : 0,
            'fail24'      => isset($cnt[$id]) ? (int)$cnt[$id]['FAIL_N'] : 0,
            'tot24'       => isset($cnt[$id]) ? (int)$cnt[$id]['TOT'] : 0,
            'historial'   => $runs,
            'error_paso'  => $fail[$id] ?? null,
            'orden'       => $isExpected ? array_search($expByKey[$key], array_keys($expected), true) : 999,
        ];
    }

    /* Jobs esperados que no existen en el servidor */
    foreach ($expected as $name => $meta) {
        if (isset($found[sqljobs_key($name)])) continue;
        $jobs[] = [
            'nombre' => $name, 'modulo' => $meta['modulo'], 'script' => $meta['script'],
            'habilitado' => false, 'estado' => 'missing', 'estado_txt' => 'No instalado',
            'frecuencia' => '—', 'periodo' => null, 'ultima' => null, 'ultima_edad' => null,
            'proxima' => null, 'corriendo' => null, 'ok24' => 0, 'fail24' => 0, 'tot24' => 0,
            'historial' => [], 'error_paso' => null,
            'orden' => array_search($name, array_keys($expected), true),
        ];
    }

    /* Orden: problemas primero, después el orden de la lista */
    $weight = ['fail' => 0, 'late' => 1, 'warn' => 2, 'running' => 3, 'ok' => 4, 'disabled' => 5, 'missing' => 6];
    usort($jobs, function ($a, $b) use ($weight) {
        $wa = $weight[$a['estado']] ?? 9; $wb = $weight[$b['estado']] ?? 9;
        if ($wa !== $wb) return $wa <=> $wb;
        return $a['orden'] <=> $b['orden'];
    });

    /* Resumen */
    $sum = ['total' => 0, 'ok' => 0, 'fail' => 0, 'late' => 0, 'warn' => 0, 'running' => 0, 'disabled' => 0, 'missing' => 0];
    foreach ($jobs as $x) { $sum['total']++; $sum[$x['estado']] = ($sum[$x['estado']] ?? 0) + 1; }
    $out['summary'] = $sum;

    /* SQL Agent */
    $svcState = !empty($svc) ? (string)$svc[0]['ESTADO'] : null;
    $silence = $latestAnyRun !== null ? $now - $latestAnyRun : null;
    $hasFrequent = false;
    foreach ($jobs as $x) if ($x['habilitado'] && $x['periodo'] !== null && $x['periodo'] <= 900) $hasFrequent = true;

    if ($svcState !== null) {
        $agentOk = (stripos($svcState, 'running') !== false);
        $agentTxt = $agentOk ? 'Servicio en ejecución' : 'Servicio: ' . $svcState;
    } elseif ($hasFrequent && $silence !== null && $silence > 1200) {
        $agentOk = false;
        $agentTxt = 'Sin ejecuciones hace ' . intdiv($silence, 60) . ' min: el Agent podría estar detenido';
    } else {
        $agentOk = true;
        $agentTxt = 'Registrando ejecuciones';
    }
    $out['agent'] = [
        'ok'       => $agentOk,
        'texto'    => $agentTxt,
        'inicio'   => $agentStart,
        'servicio' => $svcState,
        'ultima'   => $latestAnyRun !== null ? date('Y-m-d H:i:s', $latestAnyRun) : null,
    ];

    $out['jobs'] = $jobs;
    $out['ok'] = true;
    return $out;
}
