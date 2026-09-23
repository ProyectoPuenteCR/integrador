<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/gestion_telemetria.php
   Consolidado de demanda/disponibilidad de telemetría.

   Fuentes existentes:
     - dbo.TELEMETRIA_POZOS_GENERAL_CACHE
     - dbo.CLEAR_API_Q158_POZOS (Zafiro)
     - Query 164 (producción)
     - histórico de Sin telemetría en Zafiro
     - DYNA opcional: dbo.CLEAR_DYNA_POZOS_ENERGIA
       fallback: dbo.CLEAR_PARO_REMOTO.LINEA_ELECTRICA

   Solo lectura. No crea ni altera objetos SQL.
   Compatible PHP 7.4.
============================================================= */

require_once __DIR__ . '/production_q164.php';
require_once __DIR__ . '/zafiro_sin_telemetria.php';

function gt_q($name)
{
    return '[' . str_replace(']', ']]', (string)$name) . ']';
}

function gt_text($value)
{
    if ($value === null) return '';
    if ($value instanceof DateTimeInterface) return $value->format('Y-m-d H:i:s');
    return trim((string)$value);
}

function gt_key($value)
{
    $s = strtoupper(trim((string)$value));
    $s = strtr($s, [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ü'=>'U','ñ'=>'N'
    ]);
    $s = preg_replace('/[^A-Z0-9]/', '', $s);
    if (strpos($s, 'YPFSC') === 0) $s = substr($s, 5);
    return (string)$s;
}

function gt_object_columns($db, $object)
{
    if (!$db || !$db->ok()) return [];
    $rows = $db->all(
        "SELECT c.name AS COLUMN_NAME FROM sys.columns c "
        . "WHERE c.object_id=OBJECT_ID(?) ORDER BY c.column_id",
        ['dbo.' . $object]
    );
    $out = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['COLUMN_NAME'] ?? ''));
        if ($name !== '') $out[] = $name;
    }
    return $out;
}

function gt_find_column(array $columns, array $exact = [], array $contains = [], array $exclude = [])
{
    foreach ($exact as $wanted) {
        foreach ($columns as $column) {
            if (strcasecmp($column, $wanted) === 0) return $column;
        }
    }
    foreach ($columns as $column) {
        $u = strtoupper($column);
        $ok = true;
        foreach ($contains as $part) {
            if (strpos($u, strtoupper($part)) === false) { $ok = false; break; }
        }
        if (!$ok) continue;
        foreach ($exclude as $part) {
            if (strpos($u, strtoupper($part)) !== false) { $ok = false; break; }
        }
        if ($ok) return $column;
    }
    return '';
}

function gt_active_label($value)
{
    if ($value === null) return '';
    if (is_bool($value)) return $value ? 'true' : 'false';
    $u = strtoupper(trim((string)$value));
    if ($u === '') return '';
    if (in_array($u, ['1','TRUE','SI','SÍ','YES','ACTIVO','ACTIVE'], true)) return 'true';
    if (in_array($u, ['0','FALSE','NO','INACTIVO','INACTIVE'], true)) return 'false';
    return '';
}

function gt_load_telemetry_map($db)
{
    $out = [];
    if (!$db || !$db->ok()) return $out;
    if ((int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE',N'U') IS NULL THEN 0 ELSE 1 END") !== 1) return $out;

    $rows = $db->all(
        "SELECT POZO,BATERIA,TIPO,COMUNICACION,ESTADO,"
        . "CONVERT(varchar(19),ULTIMA_ACTUALIZACION,120) AS ULTIMA_ACTUALIZACION,"
        . "CONVERT(varchar(19),FECHA_CACHE,120) AS FECHA_CACHE "
        . "FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE WHERE POZO IS NOT NULL"
    );
    foreach ($rows as $row) {
        $well = trim((string)($row['POZO'] ?? ''));
        $key = gt_key($well);
        if ($key === '') continue;
        if (!isset($out[$key])) {
            $out[$key] = [
                'key'=>$key,'well'=>$well,'battery'=>trim((string)($row['BATERIA'] ?? '')),
                'types'=>[],'comm'=>zst_comm_label($row['COMUNICACION'] ?? ''),
                'state'=>trim((string)($row['ESTADO'] ?? '')),
                'updated'=>gt_text($row['ULTIMA_ACTUALIZACION'] ?? ''),
                'cache'=>gt_text($row['FECHA_CACHE'] ?? '')
            ];
        }
        $type = strtoupper(trim((string)($row['TIPO'] ?? '')));
        if ($type !== '') $out[$key]['types'][$type] = true;
        if ($out[$key]['battery'] === '' && trim((string)($row['BATERIA'] ?? '')) !== '') $out[$key]['battery'] = trim((string)$row['BATERIA']);
        if ($out[$key]['state'] === '' && trim((string)($row['ESTADO'] ?? '')) !== '') $out[$key]['state'] = trim((string)$row['ESTADO']);
        if ($out[$key]['comm'] === 'Sin dato' && zst_comm_label($row['COMUNICACION'] ?? '') !== 'Sin dato') $out[$key]['comm'] = zst_comm_label($row['COMUNICACION']);
        $candidate = gt_text($row['ULTIMA_ACTUALIZACION'] ?? '');
        if ($candidate !== '' && ($out[$key]['updated'] === '' || strcmp($candidate, $out[$key]['updated']) > 0)) $out[$key]['updated'] = $candidate;
    }

    foreach ($out as &$row) {
        $types = array_keys($row['types']);
        sort($types, SORT_NATURAL | SORT_FLAG_CASE);
        $hasTecss = false; $hasScada = false;
        foreach ($types as $type) {
            if ($type === 'TECSS' || $type === 'TECCS') $hasTecss = true; else $hasScada = true;
        }
        $row['telemetry'] = $hasTecss && $hasScada ? 'SCADA + TECSS' : ($hasTecss ? 'TECSS' : 'SCADA');
        $row['typeDetail'] = implode(' + ', $types);
        unset($row['types']);
    }
    unset($row);
    return $out;
}

function gt_load_zafiro_map($db)
{
    $map = [];
    if (!$db || !$db->ok()) return $map;
    if ((int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_API_Q158_POZOS') IS NULL THEN 0 ELSE 1 END") !== 1) return $map;

    $columns = gt_object_columns($db, 'CLEAR_API_Q158_POZOS');
    $well = gt_find_column($columns, ['Pozo']);
    $active = gt_find_column($columns, [], ['ISACTIVE']);
    $state = gt_find_column($columns,
        ['Resumen de Producción Teórica de Pozo>>Cambio de estado>>Estado'],
        ['CAMBIO DE ESTADO','ESTADO'], ['ISACTIVE']
    );
    if ($state === '') $state = gt_find_column($columns, ['Estado'], ['ESTADO'], ['ISACTIVE']);
    $method = gt_find_column($columns,
        ['Resumen de Producción Teórica de Pozo>>Sistema de Extracción>>Sistema de Extracción_name'],
        ['SISTEMA','EXTRAC'], []
    );
    $date = gt_find_column($columns, ['FechaCarga']);
    $cacheId = gt_find_column($columns, ['CacheId']);
    if ($well === '') return $map;

    $select = gt_q($well) . " AS __POZO,"
        . ($state !== '' ? gt_q($state) : 'NULL') . " AS __ESTADO,"
        . ($method !== '' ? gt_q($method) : 'NULL') . " AS __METODO,"
        . ($active !== '' ? "CONVERT(nvarchar(20)," . gt_q($active) . ")" : 'NULL') . " AS __ACTIVO";
    $sql = "SELECT " . $select . " FROM dbo.CLEAR_API_Q158_POZOS WHERE " . gt_q($well) . " IS NOT NULL";
    $order = [];
    if ($date !== '') $order[] = gt_q($date) . ' DESC';
    if ($cacheId !== '') $order[] = gt_q($cacheId) . ' DESC';
    if ($order) $sql .= ' ORDER BY ' . implode(',', $order);

    $rows = $db->all($sql);
    foreach ($rows as $row) {
        $key = gt_key($row['__POZO'] ?? '');
        if ($key === '') continue;
        $candidate = [
            'well'=>trim((string)($row['__POZO'] ?? '')),
            'state'=>trim((string)($row['__ESTADO'] ?? '')),
            'method'=>trim((string)($row['__METODO'] ?? '')),
            'active'=>gt_active_label($row['__ACTIVO'] ?? null)
        ];
        if (!isset($map[$key])) {
            $map[$key] = $candidate;
            continue;
        }
        foreach (['state','method','active'] as $field) {
            if ($map[$key][$field] === '' && $candidate[$field] !== '') $map[$key][$field] = $candidate[$field];
        }
    }
    return $map;
}

function gt_load_energy_map($db)
{
    $result = ['source'=>'','rows'=>[]];
    if (!$db || !$db->ok()) return $result;

    if ((int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_DYNA_POZOS_ENERGIA',N'U') IS NULL THEN 0 ELSE 1 END") === 1) {
        $columns = gt_object_columns($db, 'CLEAR_DYNA_POZOS_ENERGIA');
        $well = gt_find_column($columns, ['POZO','Pozo']);
        $line = gt_find_column($columns, ['LINEA_ENERGIA','LINEA ELECTRICA','LINEA_ELECTRICA','LINEA']);
        $feeder = gt_find_column($columns, ['ALIMENTADOR']);
        $substation = gt_find_column($columns, ['SUBESTACION']);
        if ($well !== '' && $line !== '') {
            $sql = "SELECT " . gt_q($well) . " AS __POZO," . gt_q($line) . " AS __LINEA,"
                . ($feeder !== '' ? gt_q($feeder) : 'NULL') . " AS __ALIMENTADOR,"
                . ($substation !== '' ? gt_q($substation) : 'NULL') . " AS __SUBESTACION "
                . "FROM dbo.CLEAR_DYNA_POZOS_ENERGIA WHERE " . gt_q($well) . " IS NOT NULL";
            foreach ($db->all($sql) as $row) {
                $key = gt_key($row['__POZO'] ?? '');
                if ($key === '') continue;
                $result['rows'][$key] = [
                    'well'=>trim((string)($row['__POZO'] ?? '')),
                    'line'=>trim((string)($row['__LINEA'] ?? '')),
                    'feeder'=>trim((string)($row['__ALIMENTADOR'] ?? '')),
                    'substation'=>trim((string)($row['__SUBESTACION'] ?? ''))
                ];
            }
            $result['source'] = 'DYNA';
            return $result;
        }
    }

    if ((int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_PARO_REMOTO',N'U') IS NULL THEN 0 ELSE 1 END") === 1) {
        $columns = gt_object_columns($db, 'CLEAR_PARO_REMOTO');
        $well = gt_find_column($columns, ['AF-POZO','POZO']);
        $line = gt_find_column($columns, ['LINEA_ELECTRICA','LINEA ELECTRICA','LINEA']);
        if ($well !== '' && $line !== '') {
            $sql = "SELECT " . gt_q($well) . " AS __POZO,MAX(CONVERT(nvarchar(255)," . gt_q($line) . ")) AS __LINEA "
                . "FROM dbo.CLEAR_PARO_REMOTO WHERE " . gt_q($well) . " IS NOT NULL GROUP BY " . gt_q($well);
            foreach ($db->all($sql) as $row) {
                $key = gt_key($row['__POZO'] ?? '');
                if ($key === '') continue;
                $result['rows'][$key] = [
                    'well'=>trim((string)($row['__POZO'] ?? '')),
                    'line'=>trim((string)($row['__LINEA'] ?? '')),
                    'feeder'=>'','substation'=>''
                ];
            }
            $result['source'] = 'CLEAR_PARO_REMOTO';
        }
    }
    return $result;
}

function gt_state_group($state)
{
    $u = strtoupper(trim((string)$state));
    $u = strtr($u, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
    if (strpos($u, 'ABANDON') !== false) return 'abandonado';
    if (strpos($u, 'SIN PRODU') !== false || strpos($u, 'NO PRODU') !== false) return 'sin_producir';
    if (strpos($u, 'INYECT') !== false) return 'inyector';
    if (strpos($u, 'CERRAD') !== false) return 'cerrado';
    return 'otro_inactivo';
}

function gt_state_label($group)
{
    $labels = [
        'abandonado'=>'Abandonado',
        'sin_producir'=>'Sin producir',
        'inyector'=>'Inyector',
        'cerrado'=>'Cerrado',
        'otro_inactivo'=>'Otro inactivo'
    ];
    return $labels[$group] ?? $group;
}

function gt_parse_filters(array $get)
{
    $view = strtolower(trim((string)($get['vista'] ?? 'resumen')));
    if (!in_array($view, ['resumen','necesitan','disponible','evolucion','energia'], true)) $view = 'resumen';
    $weeks = (int)($get['semanas'] ?? 8);
    if (!in_array($weeks, [6,8,12], true)) $weeks = 8;
    return [
        'view'=>$view,
        'battery'=>trim((string)($get['bateria'] ?? '')),
        'telemetry'=>strtoupper(trim((string)($get['telemetria'] ?? ''))),
        'state'=>trim((string)($get['estado'] ?? '')),
        'line'=>trim((string)($get['linea'] ?? '')),
        'q'=>trim((string)($get['q'] ?? '')),
        'weeks'=>$weeks
    ];
}

function gt_row_matches(array $row, array $filters, $allowState = true)
{
    if ($filters['battery'] !== '' && strcasecmp((string)($row['battery'] ?? ''), $filters['battery']) !== 0) return false;
    if ($filters['telemetry'] !== '' && stripos((string)($row['telemetry'] ?? ''), $filters['telemetry']) === false) return false;
    if ($filters['line'] !== '' && strcasecmp((string)($row['line'] ?? ''), $filters['line']) !== 0) return false;
    if ($allowState && $filters['state'] !== '' && (string)($row['stateGroup'] ?? '') !== $filters['state']) return false;
    if ($filters['q'] !== '') {
        $haystack = implode(' ', [
            $row['well'] ?? '', $row['battery'] ?? '', $row['state'] ?? '',
            $row['method'] ?? '', $row['telemetry'] ?? '', $row['line'] ?? ''
        ]);
        foreach (preg_split('/\s+/', $filters['q']) as $term) {
            if ($term !== '' && stripos($haystack, $term) === false) return false;
        }
    }
    return true;
}

function gt_production_for(array $productionMap, $well)
{
    $direct = clear_q164_well_key($well);
    if ($direct !== '' && isset($productionMap[$direct])) return $productionMap[$direct];
    $compact = gt_key($well);
    if ($compact === '') return null;
    foreach ($productionMap as $row) {
        if (gt_key($row['POZO'] ?? '') === $compact) return $row;
    }
    return null;
}

function gt_load($db, array $get)
{
    $filters = gt_parse_filters($get);
    $out = [
        'ok'=>false,'error'=>'','filters'=>$filters,
        'need'=>[],'needFiltered'=>[],'available'=>[],'availableFiltered'=>[],
        'needCount'=>0,'availableCount'=>0,'balance'=>0,
        'needBrute'=>0.0,'needNet'=>0.0,'availableBrute'=>0.0,'availableNet'=>0.0,
        'stateSummary'=>[],'chart'=>[],'trend'=>null,
        'energySource'=>'','energyRows'=>[],'dynaReady'=>false,
        'batteries'=>[],'lines'=>[],'latestTelemetry'=>'','latestZafiro'=>''
    ];
    if (!$db || !$db->ok()) { $out['error'] = $db ? (string)$db->error() : 'Sin conexión.'; return $out; }

    $telemetry = gt_load_telemetry_map($db);
    $zafiro = gt_load_zafiro_map($db);
    $energy = gt_load_energy_map($db);
    $production = clear_q164_latest_map($db);

    foreach ($telemetry as $row) {
        if ($row['cache'] !== '' && ($out['latestTelemetry'] === '' || strcmp($row['cache'], $out['latestTelemetry']) > 0)) $out['latestTelemetry'] = $row['cache'];
    }

    $available = [];
    foreach ($telemetry as $key => $teleRow) {
        $z = $zafiro[$key] ?? null;
        if (!$z || ($z['active'] ?? '') !== 'false') continue;
        $prod = gt_production_for($production, $teleRow['well']);
        $energyRow = $energy['rows'][$key] ?? ['line'=>'','feeder'=>'','substation'=>''];
        $group = gt_state_group($z['state'] ?? '');
        $available[$key] = [
            'key'=>$key,'well'=>$teleRow['well'],'battery'=>$teleRow['battery'],
            'state'=>$z['state'] !== '' ? $z['state'] : 'Inactivo',
            'stateGroup'=>$group,'method'=>$z['method'] ?? '',
            'telemetry'=>$teleRow['telemetry'],'typeDetail'=>$teleRow['typeDetail'],
            'comm'=>$teleRow['comm'],'updated'=>$teleRow['updated'],
            'productionLiquid'=>$prod['PRODUCCION_LIQUIDO'] ?? null,
            'productionOil'=>$prod['PRODUCCION_PETROLEO'] ?? null,
            'productionDate'=>gt_text($prod['DIA_OPERATIVO'] ?? ''),
            'line'=>$energyRow['line'] ?? '',
            'feeder'=>$energyRow['feeder'] ?? '',
            'substation'=>$energyRow['substation'] ?? ''
        ];
    }
    uasort($available, function($a,$b){ return strnatcasecmp($a['well'],$b['well']); });
    $out['available'] = array_values($available);

    $stateKeys = ['abandonado','sin_producir','inyector','cerrado','otro_inactivo'];
    foreach ($stateKeys as $key) $out['stateSummary'][$key] = ['label'=>gt_state_label($key),'count'=>0,'brute'=>0.0,'net'=>0.0];
    foreach ($out['available'] as $row) {
        $group = $row['stateGroup'];
        if (!isset($out['stateSummary'][$group])) $out['stateSummary'][$group] = ['label'=>gt_state_label($group),'count'=>0,'brute'=>0.0,'net'=>0.0];
        $out['stateSummary'][$group]['count']++;
        if ($row['productionLiquid'] !== null) $out['stateSummary'][$group]['brute'] += (float)$row['productionLiquid'];
        if ($row['productionOil'] !== null) $out['stateSummary'][$group]['net'] += (float)$row['productionOil'];
        if ($row['productionLiquid'] !== null) $out['availableBrute'] += (float)$row['productionLiquid'];
        if ($row['productionOil'] !== null) $out['availableNet'] += (float)$row['productionOil'];
    }

    if (zst_ready($db)) {
        $zFilters = zst_filters(['semanas'=>$filters['weeks']]);
        $zst = zst_load($db, $zFilters);
        if ($zst && !empty($zst['ok'])) {
            $out['need'] = $zst['rows'];
            $out['needBrute'] = (float)($zst['productionLiquidTotal'] ?? 0);
            $out['needNet'] = (float)($zst['productionOilTotal'] ?? 0);
            $out['chart'] = $zst['chart'] ?? [];
            $out['trend'] = $zst['trend'] ?? null;
            $out['latestZafiro'] = gt_text($zst['latestRun']['zafiro'] ?? '');
            foreach ($out['need'] as &$row) {
                $key = gt_key($row['well'] ?? '');
                $er = $energy['rows'][$key] ?? ['line'=>'','feeder'=>'','substation'=>''];
                $row['line'] = $er['line'] ?? '';
                $row['feeder'] = $er['feeder'] ?? '';
                $row['substation'] = $er['substation'] ?? '';
                $row['stateGroup'] = '';
                $row['method'] = '';
            }
            unset($row);
        }
    }

    $out['needCount'] = count($out['need']);
    $out['availableCount'] = count($out['available']);
    $out['balance'] = $out['availableCount'] - $out['needCount'];

    foreach ($out['available'] as $row) if (gt_row_matches($row, $filters, true)) $out['availableFiltered'][] = $row;
    foreach ($out['need'] as $row) if (gt_row_matches($row, $filters, false)) $out['needFiltered'][] = $row;

    $batteries = []; $lines = [];
    foreach (array_merge($out['available'], $out['need']) as $row) {
        $b = trim((string)($row['battery'] ?? '')); if ($b !== '') $batteries[$b] = true;
        $l = trim((string)($row['line'] ?? '')); if ($l !== '') $lines[$l] = true;
    }
    $batteries = array_keys($batteries); natcasesort($batteries); $out['batteries'] = array_values($batteries);
    $lines = array_keys($lines); natcasesort($lines); $out['lines'] = array_values($lines);

    $lineStats = [];
    foreach ($energy['rows'] as $er) {
        $line = trim((string)($er['line'] ?? ''));
        if ($line === '') continue;
        if (!isset($lineStats[$line])) $lineStats[$line] = ['line'=>$line,'associated'=>0,'need'=>0,'available'=>0,'feeder'=>'','substation'=>''];
        $lineStats[$line]['associated']++;
        if ($lineStats[$line]['feeder'] === '' && !empty($er['feeder'])) $lineStats[$line]['feeder'] = $er['feeder'];
        if ($lineStats[$line]['substation'] === '' && !empty($er['substation'])) $lineStats[$line]['substation'] = $er['substation'];
    }
    foreach ($out['need'] as $row) {
        $line = trim((string)($row['line'] ?? '')); if ($line === '') continue;
        if (!isset($lineStats[$line])) $lineStats[$line] = ['line'=>$line,'associated'=>0,'need'=>0,'available'=>0,'feeder'=>'','substation'=>''];
        $lineStats[$line]['need']++;
    }
    foreach ($out['available'] as $row) {
        $line = trim((string)($row['line'] ?? '')); if ($line === '') continue;
        if (!isset($lineStats[$line])) $lineStats[$line] = ['line'=>$line,'associated'=>0,'need'=>0,'available'=>0,'feeder'=>'','substation'=>''];
        $lineStats[$line]['available']++;
    }
    $out['energyRows'] = array_values($lineStats);
    usort($out['energyRows'], function($a,$b){
        if ($a['need'] !== $b['need']) return $b['need'] <=> $a['need'];
        if ($a['available'] !== $b['available']) return $b['available'] <=> $a['available'];
        return strnatcasecmp($a['line'],$b['line']);
    });
    $out['energySource'] = $energy['source'];
    $out['dynaReady'] = $energy['source'] === 'DYNA';
    $out['ok'] = true;
    return $out;
}

function gt_fmt_datetime($value)
{
    $ts = strtotime((string)$value);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

function gt_fmt_date($value)
{
    $ts = strtotime((string)$value);
    return $ts ? date('d/m/Y', $ts) : '—';
}
