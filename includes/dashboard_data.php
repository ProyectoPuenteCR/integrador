<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/dashboard_data.php
   El dashboard prioriza la caché operativa actualizada por SQL
   Agent. Si todavía no fue instalada, conserva el modo anterior.
============================================================= */

require_once __DIR__ . '/performance_cache.php';

function dashboard_stats()
{
    $db = clear_db();
    if (!$db->ok()) return ['ok' => false, 'error' => $db->error()];

    $cache = clear_performance_cache_load($db);
    if ($cache !== null) {
        return [
            'ok'          => true,
            'total24h'    => (int)clear_performance_cache_kpi('TOTAL24H', 0, $db),
            'criticas'    => (int)clear_performance_cache_kpi('CRITICAS', 0, $db),
            'activas'     => (int)clear_performance_cache_kpi('ACTIVAS', 0, $db),
            'reconocidas' => (int)clear_performance_cache_kpi('RECONOCIDAS', 0, $db),
            'suprimidas'  => (int)clear_performance_cache_kpi('SUPRIMIDAS', 0, $db),
            'latest_alarm'=> clear_performance_cache_text('ULTIMA_ALARMA', null, $db),
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
        'latest_alarm'=> $db->scalar("SELECT CONVERT(VARCHAR(19), MAX(ALM_NATIVETIMEIN), 120) FROM dbo.FIXALARMS"),
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
        foreach (clear_performance_cache_metric('TOP_ALARMAS', $db) as $row) {
            if (count($data['top_alarmas']) >= 5) break;
            $data['top_alarmas'][] = ['label'=>trim((string)($row['DIMENSION1'] ?? '—')), 'value'=>(int)($row['VALOR1'] ?? 0)];
        }
        foreach (clear_performance_cache_metric('TENDENCIA', $db) as $row) {
            $raw = trim((string)($row['DIMENSION1'] ?? ''));
            $ts = $raw !== '' ? strtotime($raw) : false;
            $data['tendencia'][] = ['label'=>$ts ? date('d/m', $ts) : '—', 'value'=>(int)($row['VALOR1'] ?? 0)];
        }
        foreach (clear_performance_cache_metric('PRIORIDAD', $db) as $row) {
            $p = strtoupper(trim((string)($row['DIMENSION1'] ?? 'INFO')));
            if (isset($data['prioridad'][$p])) $data['prioridad'][$p] += (int)($row['VALOR1'] ?? 0);
        }
        foreach (clear_performance_cache_metric('OPERADORES', $db) as $row) {
            $data['operadores'][] = ['label'=>trim((string)($row['DIMENSION1'] ?? '—')), 'value'=>(int)($row['VALOR1'] ?? 0)];
        }
        $data['pozos']['total'] = (int)clear_performance_cache_kpi('POZOS', 0, $db);
        $data['importadas']['total'] = (int)clear_performance_cache_kpi('IMPORTADAS', 0, $db);
        return $data;
    }

    $top = $db->all("SELECT TOP 5 ALM_TAGNAME,TOTAL_ALARMAS FROM dbo.FIXALARMS_TOP20_24H ORDER BY TOTAL_ALARMAS DESC");
    if (!$top) $top = $db->all("SELECT TOP 5 ALM_TAGNAME,TOTAL_ALARMAS FROM dbo.FIXALARMS_TOP20_ALL ORDER BY TOTAL_ALARMAS DESC");
    foreach ($top as $r) $data['top_alarmas'][] = ['label'=>trim((string)($r['ALM_TAGNAME'] ?? '—')), 'value'=>(int)($r['TOTAL_ALARMAS'] ?? 0)];

    $trend = array_reverse($db->all("SELECT TOP 7 Fecha,Total_Alarmas FROM dbo.FIXALARMS_TENDENCIA_SEM ORDER BY Fecha DESC"));
    foreach ($trend as $r) {
        $raw = trim((string)($r['Fecha'] ?? ''));
        $ts = $raw !== '' ? strtotime($raw) : false;
        $data['tendencia'][] = ['label'=>$ts ? date('d/m', $ts) : '—', 'value'=>(int)($r['Total_Alarmas'] ?? 0)];
    }
    foreach ($db->all("SELECT ALM_ALMPRIORITY,TOTAL FROM dbo.FIXALARMS_PRIORITY") as $r) {
        $p = strtoupper(trim((string)($r['ALM_ALMPRIORITY'] ?? 'INFO')));
        if ($p === 'MED') $p = 'MEDIUM';
        if (isset($data['prioridad'][$p])) $data['prioridad'][$p] += (int)($r['TOTAL'] ?? 0); else $data['prioridad']['INFO'] += (int)($r['TOTAL'] ?? 0);
    }
    $data['pozos']['total'] = (int)($db->scalar("SELECT COUNT(*) FROM dbo.POZOS") ?? 0);
    foreach ($db->all("SELECT TOP 3 OPERADOR,SUM(CANTIDAD_RECONOCIMIENTOS) TOTAL FROM dbo.FIXALARMS_RECONOCIDAS_usr WHERE OPERADOR IS NOT NULL GROUP BY OPERADOR ORDER BY SUM(CANTIDAD_RECONOCIMIENTOS) DESC") as $r) {
        $data['operadores'][] = ['label'=>trim((string)($r['OPERADOR'] ?? '—')), 'value'=>(int)($r['TOTAL'] ?? 0)];
    }
    $data['importadas']['total'] = (int)($db->scalar("SELECT COUNT(*) FROM dbo.ALM_Importadas") ?? 0);
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
        foreach (clear_performance_cache_metric('HORARIA', $db) as $r) {
            $data['hourly'][] = ['hour'=>(int)($r['DIMENSION1'] ?? 0), 'high'=>(int)($r['VALOR1'] ?? 0), 'medium'=>(int)($r['VALOR2'] ?? 0), 'low'=>(int)($r['VALOR3'] ?? 0)];
        }
        foreach (clear_performance_cache_metric('FLUJO', $db) as $r) {
            $data['flow'][] = ['hour'=>(int)($r['DIMENSION1'] ?? 0), 'active'=>(int)($r['VALOR1'] ?? 0), 'normalized'=>(int)($r['VALOR2'] ?? 0)];
        }
        $paretoRows = clear_performance_cache_metric('TOP_ALARMAS', $db);
        $paretoTotal = 0;
        foreach ($paretoRows as $r) $paretoTotal += (int)($r['VALOR1'] ?? 0);
        $acc = 0;
        foreach ($paretoRows as $r) {
            $value = (int)($r['VALOR1'] ?? 0); $acc += $value;
            $data['pareto'][] = ['tag'=>trim((string)($r['DIMENSION1'] ?? '—')), 'value'=>$value, 'cum'=>$paretoTotal > 0 ? round(($acc/$paretoTotal)*100,1) : 0];
        }
        foreach (clear_performance_cache_metric('HEATMAP', $db) as $r) {
            $data['heatmap'][] = ['date'=>substr((string)($r['DIMENSION1'] ?? ''),0,10), 'hour'=>(int)($r['DIMENSION2'] ?? 0), 'value'=>(int)($r['VALOR1'] ?? 0)];
        }
        foreach (clear_performance_cache_metric('INSTALACIONES', $db) as $r) {
            $data['installations'][] = ['name'=>trim((string)($r['DIMENSION1'] ?? '—')), 'total'=>(int)($r['VALOR1'] ?? 0), 'critical'=>(int)($r['VALOR2'] ?? 0)];
        }
        $recognized = (int)clear_performance_cache_kpi('RECONOCIDAS', 0, $db);
        $commented = (int)clear_performance_cache_kpi('COMENTADAS', 0, $db);
    } else {
        foreach ($db->all("SELECT DATEPART(HOUR,ALM_NATIVETIMEIN) HORA,SUM(CASE WHEN UPPER(ISNULL(ALM_ALMPRIORITY,''))='HIGH' THEN 1 ELSE 0 END) ALTA,SUM(CASE WHEN UPPER(ISNULL(ALM_ALMPRIORITY,'')) IN ('MED','MEDIUM') THEN 1 ELSE 0 END) MEDIA,SUM(CASE WHEN UPPER(ISNULL(ALM_ALMPRIORITY,'')) NOT IN ('HIGH','MED','MEDIUM') THEN 1 ELSE 0 END) BAJA FROM dbo.FIXALARMS_24H GROUP BY DATEPART(HOUR,ALM_NATIVETIMEIN) ORDER BY HORA") as $r) {
            $data['hourly'][] = ['hour'=>(int)($r['HORA'] ?? 0),'high'=>(int)($r['ALTA'] ?? 0),'medium'=>(int)($r['MEDIA'] ?? 0),'low'=>(int)($r['BAJA'] ?? 0)];
        }
        foreach ($db->all("SELECT DATEPART(HOUR,ALM_NATIVETIMEIN) HORA,SUM(CASE WHEN UPPER(LTRIM(RTRIM(ISNULL(ALM_VALUE,'')))) IN ('NORMAL','OK','HABILITADO') THEN 1 ELSE 0 END) NORMALIZADAS,SUM(CASE WHEN UPPER(LTRIM(RTRIM(ISNULL(ALM_VALUE,'')))) NOT IN ('NORMAL','OK','HABILITADO') THEN 1 ELSE 0 END) ACTIVACIONES FROM dbo.FIXALARMS_24H GROUP BY DATEPART(HOUR,ALM_NATIVETIMEIN) ORDER BY HORA") as $r) {
            $data['flow'][] = ['hour'=>(int)($r['HORA'] ?? 0),'active'=>(int)($r['ACTIVACIONES'] ?? 0),'normalized'=>(int)($r['NORMALIZADAS'] ?? 0)];
        }
        $paretoRows = $db->all("SELECT TOP 20 ALM_TAGNAME,TOTAL_ALARMAS FROM dbo.FIXALARMS_TOP20_24H ORDER BY TOTAL_ALARMAS DESC");
        if (!$paretoRows) $paretoRows = $db->all("SELECT TOP 20 ALM_TAGNAME,TOTAL_ALARMAS FROM dbo.FIXALARMS_TOP20_ALL ORDER BY TOTAL_ALARMAS DESC");
        $paretoTotal = 0; foreach ($paretoRows as $r) $paretoTotal += (int)($r['TOTAL_ALARMAS'] ?? 0);
        $acc = 0; foreach ($paretoRows as $r) { $v=(int)($r['TOTAL_ALARMAS']??0);$acc+=$v;$data['pareto'][]=['tag'=>trim((string)($r['ALM_TAGNAME']??'—')),'value'=>$v,'cum'=>$paretoTotal>0?round(($acc/$paretoTotal)*100,1):0]; }
        foreach ($db->all("SELECT CONVERT(date,ALM_NATIVETIMEIN) FECHA,DATEPART(HOUR,ALM_NATIVETIMEIN) HORA,COUNT(*) TOTAL FROM dbo.FIXALARMS WHERE ALM_NATIVETIMEIN>=DATEADD(day,-6,CONVERT(date,GETDATE())) GROUP BY CONVERT(date,ALM_NATIVETIMEIN),DATEPART(HOUR,ALM_NATIVETIMEIN) ORDER BY FECHA,HORA") as $r) {
            $data['heatmap'][]=['date'=>substr((string)($r['FECHA']??''),0,10),'hour'=>(int)($r['HORA']??0),'value'=>(int)($r['TOTAL']??0)];
        }
        foreach ($db->all("SELECT TOP 8 CASE WHEN CHARINDEX('_',ALM_TAGNAME)>0 THEN LEFT(ALM_TAGNAME,CHARINDEX('_',ALM_TAGNAME)-1) ELSE ALM_TAGNAME END INSTALACION,COUNT(*) TOTAL,SUM(CASE WHEN UPPER(ISNULL(ALM_ALMPRIORITY,''))='HIGH' THEN 1 ELSE 0 END) CRITICAS FROM dbo.FIXALARMS_24H WHERE ALM_TAGNAME IS NOT NULL AND LTRIM(RTRIM(ALM_TAGNAME))<>'' GROUP BY CASE WHEN CHARINDEX('_',ALM_TAGNAME)>0 THEN LEFT(ALM_TAGNAME,CHARINDEX('_',ALM_TAGNAME)-1) ELSE ALM_TAGNAME END ORDER BY COUNT(*) DESC") as $r) {
            $data['installations'][]=['name'=>trim((string)($r['INSTALACION']??'—')),'total'=>(int)($r['TOTAL']??0),'critical'=>(int)($r['CRITICAS']??0)];
        }
        $recognized=(int)($db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS_RECONOCIDAS")??0);
        $commented=(int)($db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS_COMENTARIOS WHERE ACTIVO=1 AND COMENTARIO IS NOT NULL AND LTRIM(RTRIM(COMENTARIO))<>''")??0);
    }

    $pending = max(0, $recognized - $commented);
    $data['recognition'] = ['total'=>$recognized,'commented'=>$commented,'pending'=>$pending,'coverage'=>$recognized>0?round(($commented/$recognized)*100,1):0];

    foreach ($db->all("SELECT TOP 20 R.TAG_FIX,R.OPERADOR,CONVERT(varchar(19),R.ALM_NATIVETIMEIN,120) FECHA,R.ALM_DESCR,R.ALM_ALMPRIORITY FROM dbo.FIXALARMS_RECONOCIDAS R WHERE NOT EXISTS (SELECT 1 FROM dbo.FIXALARMS_COMENTARIOS C WHERE C.ACTIVO=1 AND C.TAG_FIX=R.TAG_FIX AND C.OPERADOR=R.OPERADOR AND C.FECHA_RECONOCIMIENTO=R.ALM_NATIVETIMEIN) ORDER BY CASE WHEN UPPER(ISNULL(R.ALM_ALMPRIORITY,''))='HIGH' THEN 0 ELSE 1 END,R.ALM_NATIVETIMEIN DESC") as $r) {
        $data['pending_comments'][]=['tag'=>trim((string)($r['TAG_FIX']??'')),'operator'=>trim((string)($r['OPERADOR']??'')),'date'=>trim((string)($r['FECHA']??'')),'description'=>trim((string)($r['ALM_DESCR']??'')),'priority'=>trim((string)($r['ALM_ALMPRIORITY']??''))];
    }
    return $data;
}

function fmt_num($n) { return number_format((int)$n, 0, ',', '.'); }

