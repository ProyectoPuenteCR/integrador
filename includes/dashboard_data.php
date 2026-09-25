<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/dashboard_data.php
   El dashboard prioriza la caché operativa actualizada por SQL
   Agent. Si todavía no fue instalada, conserva el modo anterior.
============================================================= */

require_once __DIR__ . '/performance_cache.php';

function dashboard_surface_cache_metric($surfaceName, $fallbackName, $db)
{
    $rows = clear_performance_cache_metric($surfaceName, $db);
    if (!empty($rows)) return $rows;
    return clear_performance_cache_metric($fallbackName, $db) ?: [];
}

function dashboard_surface_cache_kpi($surfaceName, $fallbackName, $default, $db)
{
    $value = clear_performance_cache_kpi($surfaceName, null, $db);
    if ($value !== null) return $value;
    return clear_performance_cache_kpi($fallbackName, $default, $db);
}

function dashboard_surface_cache_text($surfaceName, $fallbackName, $default, $db)
{
    $value = clear_performance_cache_text($surfaceName, null, $db);
    if ($value !== null && trim((string)$value) !== '') return $value;
    return clear_performance_cache_text($fallbackName, $default, $db);
}

function dashboard_stats()
{
    $db = clear_db();
    if (!$db->ok()) return ['ok' => false, 'error' => $db->error()];

    $cache = clear_performance_cache_load($db);
    if ($cache !== null) {
        return [
            'ok'          => true,
            'total24h'    => (int)dashboard_surface_cache_kpi('INST_TOTAL24H', 'TOTAL24H', 0, $db),
            'criticas'    => (int)dashboard_surface_cache_kpi('INST_CRITICAS', 'CRITICAS', 0, $db),
            'activas'     => (int)dashboard_surface_cache_kpi('INST_ACTIVAS', 'ACTIVAS', 0, $db),
            'reconocidas' => (int)dashboard_surface_cache_kpi('INST_RECONOCIDAS', 'RECONOCIDAS', 0, $db),
            'suprimidas'  => (int)dashboard_surface_cache_kpi('INST_SUPRIMIDAS', 'SUPRIMIDAS', 0, $db),
            'latest_alarm'=> dashboard_surface_cache_text('INST_ULTIMA_ALARMA', 'ULTIMA_ALARMA', null, $db),
            'cache'       => true,
        ];
    }

    $count = function ($table) use ($db) {
        $val = $db->scalar("SELECT COUNT(*) AS n FROM $table");
        return $val === null ? 0 : (int)$val;
    };

    return [
        'ok'          => true,
        'total24h'    => $count('dbo.FIXALARMS_24H'),
        'criticas'    => (int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS_24H WHERE ALM_ALMPRIORITY=?", ['HIGH']),
        'activas'     => $count('dbo.FIXALARMS_ONLY'),
        'reconocidas' => $count('dbo.FIXALARMS_RECONOCIDAS'),
        'suprimidas'  => $count('dbo.FIXALARMS_SUPRIMIDAS24H'),
        'latest_alarm'=> $db->scalar("SELECT CONVERT(VARCHAR(19), MAX(ALM_NATIVETIMEIN), 120) FROM dbo.FIXALARMS_24H"),
        'cache'       => false,
    ];
}

function dashboard_module_metrics()
{
    $db = clear_db();
    $empty = [
        'top_alarmas' => [], 'tendencia' => [],
        'prioridad' => ['HIGH'=>0,'MEDIUM'=>0,'LOW'=>0,'INFO'=>0],
        'pozos' => ['total'=>0], 'operadores' => [], 'importadas' => ['total'=>0],
    ];
    if (!$db->ok()) return $empty;

    $data = $empty;
    $cache = clear_performance_cache_load($db);
    if ($cache !== null) {
        foreach (dashboard_surface_cache_metric('TOP_ALARMAS_INST', 'TOP_ALARMAS', $db) as $row) {
            if (count($data['top_alarmas']) >= 5) break;
            $data['top_alarmas'][] = ['label'=>trim((string)($row['DIMENSION1'] ?? '—')), 'value'=>(int)($row['VALOR1'] ?? 0)];
        }
        foreach (dashboard_surface_cache_metric('TENDENCIA_INST', 'TENDENCIA', $db) as $row) {
            $raw = trim((string)($row['DIMENSION1'] ?? ''));
            $ts = $raw !== '' ? strtotime($raw) : false;
            $data['tendencia'][] = ['label'=>$ts ? date('d/m', $ts) : '—', 'value'=>(int)($row['VALOR1'] ?? 0)];
        }
        foreach (dashboard_surface_cache_metric('PRIORIDAD_INST', 'PRIORIDAD', $db) as $row) {
            $p = strtoupper(trim((string)($row['DIMENSION1'] ?? 'INFO')));
            if (isset($data['prioridad'][$p])) $data['prioridad'][$p] += (int)($row['VALOR1'] ?? 0);
        }
        foreach (dashboard_surface_cache_metric('OPERADORES_INST', 'OPERADORES', $db) as $row) {
            $data['operadores'][] = ['label'=>trim((string)($row['DIMENSION1'] ?? '—')), 'value'=>(int)($row['VALOR1'] ?? 0)];
        }
        $data['pozos']['total'] = (int)clear_performance_cache_kpi('POZOS', 0, $db);
        $data['importadas']['total'] = (int)clear_performance_cache_kpi('IMPORTADAS', 0, $db);
        return $data;
    }

    /* Sin caché operativa no hacemos agregaciones en línea sobre FIXALARMS.
       Esas consultas podían bloquear IIS/SQL durante la apertura del dashboard.
       El job de caché completa estos indicadores sin afectar la navegación. */
    return $data;
}

function dashboard_visual_metrics()
{
    $db = clear_db();
    $empty = [
        'hourly'=>[], 'flow'=>[], 'pareto'=>[], 'heatmap'=>[], 'installations'=>[],
        'recognition'=>['total'=>0,'commented'=>0,'pending'=>0,'coverage'=>0],
        'pending_comments'=>[],
    ];
    if (!$db->ok()) return $empty;

    $data = $empty;
    $cache = clear_performance_cache_load($db);

    if ($cache !== null) {
        foreach (dashboard_surface_cache_metric('HORARIA_INST', 'HORARIA', $db) as $r) {
            $data['hourly'][] = ['hour'=>(int)($r['DIMENSION1'] ?? 0), 'high'=>(int)($r['VALOR1'] ?? 0), 'medium'=>(int)($r['VALOR2'] ?? 0), 'low'=>(int)($r['VALOR3'] ?? 0)];
        }
        foreach (dashboard_surface_cache_metric('FLUJO_INST', 'FLUJO', $db) as $r) {
            $data['flow'][] = ['hour'=>(int)($r['DIMENSION1'] ?? 0), 'active'=>(int)($r['VALOR1'] ?? 0), 'normalized'=>(int)($r['VALOR2'] ?? 0)];
        }
        $paretoRows = dashboard_surface_cache_metric('TOP_ALARMAS_INST', 'TOP_ALARMAS', $db);
        $paretoTotal = 0;
        foreach ($paretoRows as $r) $paretoTotal += (int)($r['VALOR1'] ?? 0);
        $acc = 0;
        foreach ($paretoRows as $r) {
            $value = (int)($r['VALOR1'] ?? 0); $acc += $value;
            $data['pareto'][] = ['tag'=>trim((string)($r['DIMENSION1'] ?? '—')), 'value'=>$value, 'cum'=>$paretoTotal > 0 ? round(($acc/$paretoTotal)*100,1) : 0];
        }
        foreach (dashboard_surface_cache_metric('HEATMAP_INST', 'HEATMAP', $db) as $r) {
            $data['heatmap'][] = ['date'=>substr((string)($r['DIMENSION1'] ?? ''),0,10), 'hour'=>(int)($r['DIMENSION2'] ?? 0), 'value'=>(int)($r['VALOR1'] ?? 0)];
        }
        foreach (clear_performance_cache_metric('INSTALACIONES', $db) as $r) {
            $data['installations'][] = ['name'=>trim((string)($r['DIMENSION1'] ?? '—')), 'total'=>(int)($r['VALOR1'] ?? 0), 'critical'=>(int)($r['VALOR2'] ?? 0)];
        }
        $recognized = (int)dashboard_surface_cache_kpi('INST_RECONOCIDAS', 'RECONOCIDAS', 0, $db);
        $commented = (int)dashboard_surface_cache_kpi('INST_COMENTADAS', 'COMENTADAS', 0, $db);
    } else {
        /* Modo seguro sin caché: solo tablas acotadas. Nunca agrupar FIXALARMS
           desde una petición web porque puede bloquear el dashboard. */
        $recognized=(int)($db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS_RECONOCIDAS")??0);
        $commented=(int)($db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS_COMENTARIOS WHERE ACTIVO=1 AND COMENTARIO IS NOT NULL AND LTRIM(RTRIM(COMENTARIO))<>''")??0);
    }

    $pending = max(0, $recognized - $commented);
    $data['recognition'] = ['total'=>$recognized,'commented'=>$commented,'pending'=>$pending,'coverage'=>$recognized>0?round(($commented/$recognized)*100,1):0];

    /* La lista detallada se carga solo cuando existe caché operativa; sin ella
       evitamos el NOT EXISTS masivo al abrir la portada. */
    if ($cache !== null) {
        $hasReconExternal = (int)($db->scalar(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS_RECONOCIDAS' AND COLUMN_NAME='ALM_ALMEXTFLD2'"
        ) ?? 0) > 0;
        $reconTagExpr = "UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),R.TAG_FIX),N''))))";
        $reconInstExpr = "LEFT($reconTagExpr,CHARINDEX(N'_',$reconTagExpr+N'_')-1)";
        $surfaceReconSql = $hasReconExternal
            ? " AND ($reconInstExpr=N'PIALH3' OR NOT (UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),R.ALM_ALMEXTFLD2),N'')))) LIKE N'YPF.SC%' OR $reconTagExpr LIKE N'YPF.SC%'))"
            : " AND ($reconInstExpr=N'PIALH3' OR ($reconTagExpr NOT LIKE N'YPF.SC%' AND NOT EXISTS (SELECT 1 FROM dbo.CLEAR_F_FIXALARMS A WHERE UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),A.ALM_TAGNAME),N'')))) COLLATE DATABASE_DEFAULT=$reconTagExpr COLLATE DATABASE_DEFAULT AND UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),A.ALM_ALMEXTFLD2),N'')))) LIKE N'YPF.SC%')))";

        foreach ($db->all(
            "SELECT TOP 20 R.TAG_FIX,R.OPERADOR,CONVERT(varchar(19),R.ALM_NATIVETIMEIN,120) FECHA,R.ALM_DESCR,R.ALM_ALMPRIORITY " .
            "FROM dbo.FIXALARMS_RECONOCIDAS R " .
            "WHERE NOT EXISTS (SELECT 1 FROM dbo.FIXALARMS_COMENTARIOS C WHERE C.ACTIVO=1 AND C.TAG_FIX=R.TAG_FIX AND C.OPERADOR=R.OPERADOR AND C.FECHA_RECONOCIMIENTO=R.ALM_NATIVETIMEIN)" .
            $surfaceReconSql .
            " ORDER BY CASE WHEN UPPER(ISNULL(R.ALM_ALMPRIORITY,''))='HIGH' THEN 0 ELSE 1 END,R.ALM_NATIVETIMEIN DESC"
        ) as $r) {
            $data['pending_comments'][]=['tag'=>trim((string)($r['TAG_FIX']??'')),'operator'=>trim((string)($r['OPERADOR']??'')),'date'=>trim((string)($r['FECHA']??'')),'description'=>trim((string)($r['ALM_DESCR']??'')),'priority'=>trim((string)($r['ALM_ALMPRIORITY']??''))];
        }
    }
    return $data;
}

function fmt_num($n) { return number_format((int)$n, 0, ',', '.'); }

