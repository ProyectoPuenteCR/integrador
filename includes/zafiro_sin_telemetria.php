<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/zafiro_sin_telemetria.php
   Datos de la pantalla "Sin telemetría en Zafiro".

   Fuente: capturas diarias guardadas por el job
     SQL/CLEAR_ZAFIRO_SIN_TELEMETRIA_JOB.sql
       dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA  (una fila por día)
       dbo.CLEAR_ZAFIRO_SIN_TELEM_POZOS    (pozos con Zafiro Activo = Sin dato)

   Semana: lunes a domingo. El valor de cada semana es su última
   captura diaria (cierre de semana, o el día actual si está en curso).
   Solo lectura: esta capa nunca crea ni modifica objetos SQL.
   Compatible PHP 7.4.
============================================================= */

function zst_value($row, $key, $default = '')
{
    if (!is_array($row)) return $default;
    if (array_key_exists($key, $row)) return $row[$key] === null ? $default : $row[$key];
    foreach ($row as $k => $v) {
        if (strcasecmp((string)$k, (string)$key) === 0) return $v === null ? $default : $v;
    }
    return $default;
}

function zst_date_string($value)
{
    if ($value instanceof DateTimeInterface) return $value->format('Y-m-d');
    $text = trim((string)$value);
    if ($text === '') return '';
    $ts = strtotime($text);
    return $ts ? date('Y-m-d', $ts) : '';
}

function zst_datetime_string($value)
{
    if ($value instanceof DateTimeInterface) return $value->format('Y-m-d H:i:s');
    $text = trim((string)$value);
    if ($text === '') return '';
    $ts = strtotime($text);
    return $ts ? date('Y-m-d H:i:s', $ts) : '';
}

function zst_ready($db)
{
    if (!$db || !$db->ok()) return false;
    return (int)$db->scalar(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA',N'U') IS NOT NULL "
        . "AND OBJECT_ID(N'dbo.CLEAR_ZAFIRO_SIN_TELEM_POZOS',N'U') IS NOT NULL THEN 1 ELSE 0 END"
    ) === 1;
}

/** Lunes de la semana de una fecha. */
function zst_week_start(DateTimeImmutable $date)
{
    $date = $date->setTime(0, 0, 0);
    $dow = (int)$date->format('N'); // 1 = lunes
    return $dow === 1 ? $date : $date->modify('-' . ($dow - 1) . ' days');
}

function zst_week_label(DateTimeImmutable $monday)
{
    return 'Sem ' . (int)$monday->format('W');
}

/** Etiqueta de comunicación: misma regla que la grilla general (tg_comm_label). */
function zst_comm_label($value)
{
    $s = trim((string)$value);
    if ($s === '') return 'Sin dato';
    $u = strtoupper($s);
    if (preg_match('/FALL|ERROR|BAD|SIN|NO DATA|OFF|0/', $u)) return 'Sin comunicación';
    if (preg_match('/OK|NORMAL|GOOD|COMUNIC|ON|1/', $u)) return 'Comunicando';
    return $s;
}

function zst_filters(array $get)
{
    $week = trim((string)($get['semana'] ?? ''));
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $week);
    if (!$d || $d->format('Y-m-d') !== $week) $week = '';

    $view = strtolower(trim((string)($get['vista'] ?? 'sin')));
    if (!in_array($view, ['sin', 'nuevos', 'normalizados'], true)) $view = 'sin';

    $telemetry = strtoupper(trim((string)($get['telemetria'] ?? '')));
    if (!in_array($telemetry, ['SCADA', 'TECSS'], true)) $telemetry = '';

    $weeks = (int)($get['semanas'] ?? 6);
    if (!in_array($weeks, [6, 8, 12], true)) $weeks = 6;

    return [
        'week'      => $week,
        'zone'      => trim((string)($get['zona'] ?? '')),
        'battery'   => trim((string)($get['bateria'] ?? '')),
        'telemetry' => $telemetry,
        'q'         => trim((string)($get['q'] ?? '')),
        'view'      => $view,
        'weeks'     => $weeks,
    ];
}

function zst_row_matches(array $row, array $filters, $applySearch)
{
    if ($filters['zone'] !== '' && strcasecmp($row['zone'], $filters['zone']) !== 0) return false;
    if ($filters['battery'] !== '' && strcasecmp($row['battery'], $filters['battery']) !== 0) return false;
    if ($filters['telemetry'] !== '' && stripos($row['telemetry'], $filters['telemetry']) === false) return false;
    if ($applySearch && $filters['q'] !== '') {
        foreach (preg_split('/\s+/', $filters['q']) as $term) {
            if ($term !== '' && stripos($row['well'], $term) === false && stripos($row['battery'], $term) === false) return false;
        }
    }
    return true;
}

/**
 * Carga todo lo que necesita la pantalla y la exportación.
 * Devuelve ['ok'=>bool,'error'=>string, ...].
 */
function zst_load($db, array $filters)
{
    $out = [
        'ok' => false, 'error' => '', 'hasData' => false,
        'latestRun' => null, 'weekRun' => null, 'prevRun' => null,
        'weekStart' => null, 'weekEnd' => null,
        'current' => [], 'previous' => null,
        'normalized' => [], 'newWells' => [],
        'chart' => [], 'trend' => null,
        'zones' => [], 'batteries' => [],
        'rows' => [], 'total' => 0,
        'minWeek' => null, 'maxWeek' => null,
    ];
    if (!$db || !$db->ok()) { $out['error'] = $db ? (string)$db->error() : 'Sin conexión.'; return $out; }

    /* 1) Corridas diarias (pocas filas: una por día). */
    $runs = [];
    $runRows = $db->all(
        "SELECT CONVERT(varchar(10),FECHA,23) AS FECHA,CONVERT(varchar(19),FECHA_ZAFIRO,120) AS FECHA_ZAFIRO,"
        . "CONVERT(varchar(19),FECHA_GRILLA,120) AS FECHA_GRILLA,TOTAL_POZOS_GRILLA,TOTAL_ZAFIRO_ACTIVOS,"
        . "TOTAL_SIN_TELEMETRIA,CONVERT(int,ZAFIRO_DEL_DIA) AS ZAFIRO_DEL_DIA,CONVERT(varchar(19),FECHA_ACTUALIZACION,120) AS FECHA_ACTUALIZACION "
        . "FROM dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA WHERE FECHA>=DATEADD(day,-400,CAST(SYSDATETIME() AS date)) ORDER BY FECHA"
    );
    if (!$runRows && $db->error()) { $out['error'] = (string)$db->error(); return $out; }
    foreach ($runRows as $r) {
        $date = zst_date_string(zst_value($r, 'FECHA'));
        if ($date === '') continue;
        $runs[$date] = [
            'date' => $date,
            'zafiro' => zst_datetime_string(zst_value($r, 'FECHA_ZAFIRO')),
            'grid' => zst_datetime_string(zst_value($r, 'FECHA_GRILLA')),
            'totalGrid' => (int)zst_value($r, 'TOTAL_POZOS_GRILLA', 0),
            'totalZafiroActive' => (int)zst_value($r, 'TOTAL_ZAFIRO_ACTIVOS', 0),
            'totalWithout' => (int)zst_value($r, 'TOTAL_SIN_TELEMETRIA', 0),
            'zafiroSameDay' => (int)zst_value($r, 'ZAFIRO_DEL_DIA', 0) === 1,
            'updated' => zst_datetime_string(zst_value($r, 'FECHA_ACTUALIZACION')),
        ];
    }
    $out['ok'] = true;
    if (!$runs) return $out;
    $out['hasData'] = true;
    $out['latestRun'] = end($runs);

    /* 2) Cierre de cada semana = última captura dentro de la semana. */
    $weekClose = [];
    foreach ($runs as $date => $run) {
        $key = zst_week_start(new DateTimeImmutable($date))->format('Y-m-d');
        $weekClose[$key] = $date; // runs viene ordenado: queda la última
    }
    ksort($weekClose);
    $out['minWeek'] = array_key_first($weekClose);
    $out['maxWeek'] = array_key_last($weekClose);

    $selected = $filters['week'] !== ''
        ? zst_week_start(new DateTimeImmutable($filters['week']))
        : new DateTimeImmutable($out['maxWeek']);
    $out['weekStart'] = $selected;
    $out['weekEnd'] = $selected->modify('+6 days');

    $weekKey = function (DateTimeImmutable $monday, $offsetWeeks) {
        return $monday->modify(($offsetWeeks >= 0 ? '+' : '') . ($offsetWeeks * 7) . ' days')->format('Y-m-d');
    };

    /* Semanas del gráfico, la anterior a la primera (para nuevos/normalizados) y 4 atrás (tendencia). */
    $chartWeeks = [];
    for ($i = $filters['weeks'] - 1; $i >= 0; $i--) $chartWeeks[] = $weekKey($selected, -$i);
    $neededWeeks = array_merge($chartWeeks, [$weekKey($selected, -$filters['weeks']), $weekKey($selected, -4)]);
    $neededDates = [];
    foreach (array_unique($neededWeeks) as $wk) if (isset($weekClose[$wk])) $neededDates[$weekClose[$wk]] = true;

    /* 3) Detalle de pozos solo para las fechas de cierre necesarias. */
    $sets = [];      // fecha => [clave => row] (con filtros de dimensión)
    $zones = []; $batteries = [];
    if ($neededDates) {
        $dates = array_keys($neededDates);
        $placeholders = implode(',', array_fill(0, count($dates), 'CONVERT(date,?,23)'));
        $detail = $db->all(
            "SELECT CONVERT(varchar(10),FECHA,23) AS FECHA,POZO_CLAVE,POZO,BATERIA,ZONA,TELEMETRIA,COMUNICACION,ESTADO,OBSERVACIONES,"
            . "CONVERT(varchar(10),PRIMERA_DETECCION,23) AS PRIMERA_DETECCION "
            . "FROM dbo.CLEAR_ZAFIRO_SIN_TELEM_POZOS WHERE FECHA IN ($placeholders)",
            $dates
        );
        if (!$detail && $db->error()) { $out['ok'] = false; $out['error'] = (string)$db->error(); return $out; }
        foreach ($dates as $d) $sets[$d] = [];
        foreach ($detail as $r) {
            $date = zst_date_string(zst_value($r, 'FECHA'));
            $row = [
                'key' => (string)zst_value($r, 'POZO_CLAVE'),
                'well' => trim((string)zst_value($r, 'POZO')),
                'battery' => trim((string)zst_value($r, 'BATERIA')),
                'zone' => trim((string)zst_value($r, 'ZONA')),
                'telemetry' => trim((string)zst_value($r, 'TELEMETRIA')),
                'comm' => zst_comm_label(zst_value($r, 'COMUNICACION')),
                'state' => trim((string)zst_value($r, 'ESTADO')),
                'notes' => trim((string)zst_value($r, 'OBSERVACIONES')),
                'firstSeen' => zst_date_string(zst_value($r, 'PRIMERA_DETECCION')),
            ];
            if ($row['zone'] !== '') $zones[$row['zone']] = true;
            if ($row['battery'] !== '') $batteries[$row['battery']] = true;
            if (!zst_row_matches($row, $filters, false)) continue;
            $sets[$date][$row['key']] = $row;
        }
    }
    $zones = array_keys($zones); natcasesort($zones); $out['zones'] = array_values($zones);
    $batteries = array_keys($batteries); natcasesort($batteries); $out['batteries'] = array_values($batteries);

    $setForWeek = function ($wk) use ($weekClose, $sets) {
        return isset($weekClose[$wk]) && isset($sets[$weekClose[$wk]]) ? $sets[$weekClose[$wk]] : null;
    };

    /* 4) Serie semanal para el gráfico. */
    foreach ($chartWeeks as $wk) {
        $monday = new DateTimeImmutable($wk);
        $set = $setForWeek($wk);
        $prev = $setForWeek($weekKey($monday, -1));
        $out['chart'][] = [
            'week' => $wk,
            'label' => $monday->format('d/m'),
            'weekLabel' => zst_week_label($monday),
            'close' => $weekClose[$wk] ?? '',
            'count' => $set === null ? null : count($set),
            'newWells' => ($set === null || $prev === null) ? null : count(array_diff_key($set, $prev)),
            'normalized' => ($set === null || $prev === null) ? null : count(array_diff_key($prev, $set)),
        ];
    }

    /* 5) Semana seleccionada vs anterior. */
    $current = $setForWeek($selected->format('Y-m-d'));
    $previous = $setForWeek($weekKey($selected, -1));
    $out['weekRun'] = isset($weekClose[$selected->format('Y-m-d')]) ? $runs[$weekClose[$selected->format('Y-m-d')]] : null;
    $out['prevRun'] = isset($weekClose[$weekKey($selected, -1)]) ? $runs[$weekClose[$weekKey($selected, -1)]] : null;
    $out['current'] = $current ?? [];
    $out['previous'] = $previous;
    if ($current !== null && $previous !== null) {
        $out['normalized'] = array_diff_key($previous, $current);
        $out['newWells'] = array_diff_key($current, $previous);
    }

    /* 6) Tendencia: contra 4 semanas atrás o, si no hay, la semana más antigua disponible del gráfico. */
    if ($current !== null) {
        $baseWeeks = null; $baseCount = null;
        for ($back = 4; $back >= 1; $back--) {
            $set = $setForWeek($weekKey($selected, -$back));
            if ($set !== null) { $baseWeeks = $back; $baseCount = count($set); break; }
        }
        if ($baseWeeks !== null) {
            $now = count($current);
            $out['trend'] = [
                'weeks' => $baseWeeks,
                'from' => $baseCount,
                'to' => $now,
                'diff' => $now - $baseCount,
                'pct' => $baseCount > 0 ? round(($now - $baseCount) * 100 / $baseCount, 1) : null,
            ];
        }
    }

    /* 7) Listado según la vista elegida. */
    $closeDate = $out['weekRun']['date'] ?? '';
    if ($filters['view'] === 'normalizados') $source = $out['normalized'];
    elseif ($filters['view'] === 'nuevos') $source = $out['newWells'];
    else $source = $out['current'];

    foreach ($source as $key => $row) {
        if (!zst_row_matches($row, $filters, true)) continue;
        $isNormalized = $filters['view'] === 'normalizados';
        $weeksWithout = null;
        if (!$isNormalized && $row['firstSeen'] !== '' && $closeDate !== '') {
            $days = (int)(new DateTimeImmutable($row['firstSeen']))->diff(new DateTimeImmutable($closeDate))->format('%a');
            $weeksWithout = intdiv($days, 7) + 1;
        }
        $row['prevStatus'] = $previous === null ? 'unknown' : (isset($previous[$key]) ? 'without' : 'new');
        $row['currentStatus'] = $isNormalized ? 'normalized' : 'without';
        $row['weeksWithout'] = $weeksWithout;
        $out['rows'][] = $row;
    }
    usort($out['rows'], function ($a, $b) { return strnatcasecmp($a['well'], $b['well']); });
    $out['total'] = count($source);

    return $out;
}

function zst_fmt_date($ymd)
{
    $ts = strtotime((string)$ymd);
    return $ts ? date('d/m/Y', $ts) : '—';
}

function zst_fmt_datetime($value)
{
    $ts = strtotime((string)$value);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}
