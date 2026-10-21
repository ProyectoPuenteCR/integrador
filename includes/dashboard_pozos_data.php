<?php
/*
 * Dashboard y reporte de pozos: lectura compartida de cachés locales.
 * No consulta RTQP/OPENQUERY ni escribe objetos desde una petición web.
 * Compatible con PHP 7.4. Los estados actuales y los eventos del día son
 * métricas distintas: ALM (alarmas de 24 h) nunca sustituye FALLAS_DIA.
 */

function dpd_text($value)
{
    if ($value === null) return '';
    return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : trim((string)$value);
}

function dpd_value(array $row, $key, $default = null)
{
    foreach ($row as $name => $value) if (strcasecmp((string)$name, (string)$key) === 0) return $value;
    return $default;
}

function dpd_upper($value)
{
    return strtoupper(strtr(dpd_text($value), [
        'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ü'=>'U','ñ'=>'N',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'
    ]));
}

function dpd_well_key($value)
{
    // Misma clave que CLEAR_DASH_POZOS_ACTUAL; conserva ceros a la izquierda.
    $key = str_replace([' ','.','-','_','/'], '', dpd_upper($value));
    if (strpos($key, 'YPFSC') === 0) $key = substr($key, 5);
    if (function_exists('mb_substr')) return mb_substr($key, 0, 100, 'UTF-8');
    if (preg_match('/^[\x00-\x7f]*$/D', $key)) return substr($key, 0, 100);
    $characters = preg_split('//u', $key, -1, PREG_SPLIT_NO_EMPTY);
    return $characters === false ? '' : implode('', array_slice($characters, 0, 100));
}

function dpd_battery_key($value)
{
    return str_replace([' ','.','-','_','/'], '', dpd_upper($value));
}

function dpd_timestamp($value)
{
    if ($value instanceof DateTimeInterface) return $value->getTimestamp();
    if (dpd_text($value) === '') return null;
    try { return (new DateTimeImmutable(dpd_text($value)))->getTimestamp(); }
    catch (Throwable $e) { return null; }
}

function dpd_comm($value)
{
    $text = dpd_upper($value);
    if ($text === '') return 'Sin dato';
    if ($text === '0' || preg_match('/BAD|FALL|NO DATA|DESC|ERROR|SIN COM|NO COM|OFFLINE/', $text)) return 'Sin comunicación';
    if (preg_match('/INTER|DEMOR|WARN/', $text)) return 'Intermitente';
    if (in_array($text, ['1','ON','OK'], true) || preg_match('/NORMAL|GOOD|COMUNIC|ONLINE|HOST/', $text)) return 'Comunicando';
    return 'Sin dato';
}

function dpd_state($value)
{
    $text = dpd_upper($value);
    // Quitar solo la negación; "sin alarma, motor en falla" sigue en falla.
    $text = preg_replace('/\b(?:SIN|NO)\s+(?:FALLAS?|FAULTS?|ERRORES|ERROR|ALARMAS?)\b/', '', $text);
    if (preg_match('/FALL|FAULT|ALAR|ERROR|EMER|TRIP|DEFECT/', $text)) return 'En falla';
    if (preg_match('/PARO|PARAD|STOP|OFF|INACT|DETEN/', $text)) return 'Parado';
    if (preg_match('/MARCH|NORMAL|OPER|RUN|BOMB|OK/', $text)) return 'En marcha';
    return 'Desconocido';
}

function dpd_method($value)
{
    $text = dpd_upper($value);
    if ($text === '' || in_array($text, ['NO POSEE','SIN DATO','SIN DATOS','SIN METODO','SIN IDENTIFICAR','N/A'], true)) return 'Sin identificar';
    if (preg_match('/\b(?:PCP|BCP)\b|CAVIDAD(?:ES)? PROGRESIVA|PROGRESSIVE CAVITY/', $text)) return 'PCP';
    if (preg_match('/\b(?:BES|ESP)\b|ELECTRO\s*SUMERGIBLE/', $text)) return 'BES';
    if (preg_match('/\b(?:BM|AIB)\b|BOMBEO MECANICO/', $text)) return 'BM';
    return 'Otro';
}

function dpd_operation($now, $cutoff)
{
    $parts = explode(':', (string)$cutoff);
    $date = (new DateTimeImmutable('@' . (int)$now))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $start = $date->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0), (int)($parts[2] ?? 0));
    if ($date < $start) $start = $start->modify('-1 day');
    return ['date'=>$start->format('Y-m-d'), 'start'=>$start->getTimestamp(), 'end'=>$start->modify('+1 day')->getTimestamp()];
}

function dpd_fresh($stamp, $now, $maxMinutes)
{
    return $stamp !== null && $stamp <= $now && $stamp >= $now - max(1, (int)$maxMinutes) * 60;
}

function dpd_q($column)
{
    return '[' . str_replace(']', ']]', (string)$column) . ']';
}

function dpd_column(array $columns, array $names)
{
    foreach ($names as $name) if (isset($columns[strtoupper($name)])) return $columns[strtoupper($name)];
    return '';
}

function dpd_base_row($well, $key)
{
    return [
        'pozo_clave'=>$key, 'pozo'=>$well, 'tipo'=>'SCADA', 'bateria'=>'',
        'comm'=>'Sin dato', 'state'=>'Desconocido', 'date'=>null,
        'en_scada'=>false, 'en_tecss'=>false, 'sin_com_desde'=>null,
        'sin_com_estimado'=>false, 'zona'=>'Sin zona', 'supervisor'=>'Sin asignar',
        'metodo'=>'Sin identificar', 'detalle_falla'=>'', 'fallas_dia'=>null,
        'stale'=>false, 'scada_comm'=>null, 'tecss_comm'=>null,
        'scada_state'=>null, 'tecss_state'=>null, '_tipos'=>[]
    ];
}

function dpd_group_telemetry(array $source, $now, $maxMinutes)
{
    $groups = [];
    foreach ($source as $item) {
        $well = dpd_text(dpd_value($item, 'POZO'));
        $key = dpd_well_key($well);
        if ($key === '') continue;
        $stamp = dpd_timestamp(dpd_value($item, 'FECHA_CACHE'));
        // Una fecha futura pasa a dato desconocido antes de elegir la última
        // muestra, igual que SQL; nunca desplaza una lectura válida anterior.
        $groups[$key][] = [
            'POZO'=>$well, 'BATERIA'=>dpd_value($item, 'BATERIA'),
            'TIPO'=>dpd_value($item, 'TIPO'), 'COMUNICACION'=>dpd_value($item, 'COMUNICACION'),
            'ESTADO'=>dpd_value($item, 'ESTADO'),
            'FECHA_CACHE'=>$stamp !== null && $stamp <= $now ? date('Y-m-d H:i:s', $stamp) : null
        ];
    }
    $rows = [];
    $expired = 0;
    foreach ($groups as $key => $items) {
        $latest = ['scada'=>null, 'tecss'=>null];
        foreach ($items as $item) {
            $sourceKey = in_array(dpd_upper(dpd_value($item, 'TIPO')), ['TECSS','TECCS'], true) ? 'tecss' : 'scada';
            $stamp = dpd_timestamp(dpd_value($item, 'FECHA_CACHE'));
            if ($stamp !== null && ($latest[$sourceKey] === null || $stamp > $latest[$sourceKey])) $latest[$sourceKey] = $stamp;
        }
        // Un alias anterior de la misma fuente no conserva una falla ya
        // recuperada. Los empates conservan la prioridad de estado acordada.
        $items = array_values(array_filter($items, static function ($item) use ($latest) {
            $sourceKey = in_array(dpd_upper(dpd_value($item, 'TIPO')), ['TECSS','TECCS'], true) ? 'tecss' : 'scada';
            return dpd_timestamp(dpd_value($item, 'FECHA_CACHE')) === $latest[$sourceKey];
        }));
        usort($items, static function ($a, $b) {
            $aDate = dpd_timestamp(dpd_value($a, 'FECHA_CACHE')) ?: 0;
            $bDate = dpd_timestamp(dpd_value($b, 'FECHA_CACHE')) ?: 0;
            return $bDate <=> $aDate ?: strcmp(dpd_text(dpd_value($a, 'POZO')), dpd_text(dpd_value($b, 'POZO')));
        });
        $row = dpd_base_row(dpd_text(dpd_value($items[0], 'POZO')), $key);
        $communications = []; $states = []; $details = []; $freshCount = 0;
        $bySource = ['scada'=>['comm'=>[], 'state'=>[]], 'tecss'=>['comm'=>[], 'state'=>[]]];
        $row['date'] = dpd_timestamp(dpd_value($items[0], 'FECHA_CACHE'));
        foreach ($items as $item) {
            $type = dpd_upper(dpd_value($item, 'TIPO'));
            if ($type !== '') $row['_tipos'][$type] = true;
            $sourceKey = in_array($type, ['TECSS','TECCS'], true) ? 'tecss' : 'scada';
            $row['en_' . $sourceKey] = true;
            if ($row['bateria'] === '') $row['bateria'] = dpd_text(dpd_value($item, 'BATERIA'));
            if (!dpd_fresh(dpd_timestamp(dpd_value($item, 'FECHA_CACHE')), $now, $maxMinutes)) { $expired++; continue; }
            $freshCount++;
            $comm = dpd_comm(dpd_value($item, 'COMUNICACION'));
            $state = dpd_state(dpd_value($item, 'ESTADO'));
            $communications[$comm] = true;
            $states[$state] = true;
            $bySource[$sourceKey]['comm'][$comm] = true;
            $bySource[$sourceKey]['state'][$state] = true;
            if ($state === 'En falla') $details[dpd_text(dpd_value($item, 'ESTADO'))] = true;
        }
        foreach (['Comunicando','Intermitente','Sin comunicación','Sin dato'] as $label) {
            if (isset($communications[$label])) { $row['comm'] = $label; break; }
        }
        foreach (['En falla','Parado','En marcha','Desconocido'] as $label) {
            if (isset($states[$label])) { $row['state'] = $label; break; }
        }
        foreach ($bySource as $sourceKey => $statuses) {
            if (!$row['en_' . $sourceKey]) continue;
            $row[$sourceKey . '_comm'] = 'Sin dato';
            $row[$sourceKey . '_state'] = 'Desconocido';
            foreach (['Comunicando','Intermitente','Sin comunicación','Sin dato'] as $label) {
                if (isset($statuses['comm'][$label])) { $row[$sourceKey . '_comm'] = $label; break; }
            }
            foreach (['En falla','Parado','En marcha','Desconocido'] as $label) {
                if (isset($statuses['state'][$label])) { $row[$sourceKey . '_state'] = $label; break; }
            }
        }
        $row['tipo'] = $row['en_scada'] && $row['en_tecss'] ? 'SCADA + TECSS' : ($row['en_tecss'] ? 'TECSS' : 'SCADA');
        $row['detalle_falla'] = implode(' · ', array_keys($details));
        $row['stale'] = $freshCount === 0;
        $rows[] = $row;
    }
    return ['rows'=>$rows, 'expired'=>$expired];
}

function dpd_metadata($db, array $catalog, array &$rows, array &$errors)
{
    if (!$rows) return;
    $zones = []; $supervisors = []; $methods = []; $tecssTypes = [];
    $zoneColumns = $catalog['CLEAR.ZONAS'] ?? [];
    if (isset($zoneColumns['ZONA'], $zoneColumns['BATERIA_POZOS'])) {
        $zoneRows = $db->all('SELECT ZONA,BATERIA_POZOS FROM [CLEAR].[ZONAS] WHERE ZONA IS NOT NULL AND BATERIA_POZOS IS NOT NULL');
        foreach ($zoneRows as $item) {
            $key = dpd_battery_key(dpd_value($item, 'BATERIA_POZOS'));
            $zone = dpd_text(dpd_value($item, 'ZONA'));
            if ($key !== '' && $zone !== '') $zones[$key][$zone] = true;
        }
        if (!$zoneRows) $errors[] = 'No se obtuvieron asignaciones del catálogo de zonas; se muestra Sin zona.';
    } else $errors[] = 'No está disponible el catálogo de zonas de pozos; se muestra Sin zona.';

    $superColumns = $catalog['dbo.CLEAR_SUPERVISORES_INSTALACIONES'] ?? [];
    if (isset($superColumns['BATERIA'], $superColumns['SUPERVISOR'], $superColumns['ACTIVO'])) {
        foreach ($db->all('SELECT BATERIA,SUPERVISOR FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES WHERE ACTIVO=1') as $item) {
            $key = dpd_battery_key(dpd_value($item, 'BATERIA'));
            $supervisor = dpd_text(dpd_value($item, 'SUPERVISOR'));
            if ($key !== '' && $supervisor !== '') $supervisors[$key][$supervisor] = true;
        }
    } else $errors[] = 'No está disponible el maestro de supervisores; se muestra Sin asignar.';

    $qColumns = $catalog['dbo.CLEAR_API_Q158_POZOS'] ?? [];
    $wellColumn = dpd_column($qColumns, ['Pozo']);
    $methodColumn = dpd_column($qColumns, ['Resumen de Producción Teórica de Pozo>>Sistema de Extracción>>Sistema de Extracción_name','METODO_ZAFIRO','METODO']);
    if ($methodColumn === '') foreach ($qColumns as $candidate) {
        $upper = dpd_upper($candidate);
        if (strpos($upper, 'SISTEMA') !== false && strpos($upper, 'EXTRAC') !== false && strpos($upper, '_NAME') !== false) { $methodColumn = $candidate; break; }
    }
    if ($wellColumn !== '' && $methodColumn !== '') {
        $dateColumn = dpd_column($qColumns, ['FechaCarga']);
        $idColumn = dpd_column($qColumns, ['CacheId']);
        $order = [];
        if ($dateColumn !== '') $order[] = dpd_q($dateColumn) . ' DESC';
        if ($idColumn !== '') $order[] = dpd_q($idColumn) . ' DESC';
        $sql = 'SELECT ' . dpd_q($wellColumn) . ' AS POZO,' . dpd_q($methodColumn) . ' AS METODO FROM dbo.CLEAR_API_Q158_POZOS WHERE ' . dpd_q($wellColumn) . ' IS NOT NULL';
        if ($order) $sql .= ' ORDER BY ' . implode(',', $order);
        foreach ($db->all($sql) as $item) {
            $key = dpd_well_key(dpd_value($item, 'POZO'));
            if ($key === '') continue;
            $method = dpd_method(dpd_value($item, 'METODO'));
            if (!isset($methods[$key])) $methods[$key] = $method;
            elseif (!$order && $methods[$key] !== $method) $methods[$key] = 'Sin identificar';
        }
    }
    $tecssColumns = $catalog['dbo.CLEAR_TECSS_POZOS_CACHE'] ?? [];
    if (isset($tecssColumns['POZO'], $tecssColumns['TIPO'])) {
        foreach ($db->all('SELECT Pozo,tipo FROM dbo.CLEAR_TECSS_POZOS_CACHE') as $item) {
            $key = dpd_well_key(dpd_value($item, 'POZO'));
            $method = dpd_method(dpd_value($item, 'tipo'));
            if ($key !== '' && in_array($method, ['BM','BES','PCP'], true)) $tecssTypes[$key][$method] = true;
        }
    }
    foreach ($rows as &$row) {
        $key = dpd_battery_key($row['bateria']);
        $found = $zones[$key] ?? [];
        $row['zona'] = count($found) === 1 ? (string)array_key_first($found) : (count($found) > 1 ? 'Zona ambigua' : 'Sin zona');
        $found = $supervisors[$key] ?? [];
        $row['supervisor'] = count($found) === 1 ? (string)array_key_first($found) : (count($found) > 1 ? 'Asignación ambigua' : 'Sin asignar');
        $wellKey = $row['pozo_clave'];
        if (isset($methods[$wellKey])) $row['metodo'] = $methods[$wellKey];
        elseif (isset($tecssTypes[$wellKey]) && count($tecssTypes[$wellKey]) === 1) $row['metodo'] = (string)array_key_first($tecssTypes[$wellKey]);
        // TIPO de la caché identifica la fuente; BM_RTQP también puede tener PCP.
        // Si no hay método explícito nunca lo deducimos desde el origen técnico.
    }
    unset($row);
}

function dp_data_load($db)
{
    $result = ['ready'=>false, 'errors'=>[], 'rows'=>[], 'daily'=>[],
        'config'=>['hora_corte'=>'00:00', 'retencion_dias'=>400, 'max_antiguedad_min'=>30],
        'now'=>time(), 'stale'=>false, 'failure_counts_ready'=>false, 'source_status'=>[]];
    $result['operation'] = dpd_operation($result['now'], $result['config']['hora_corte']);
    if (!$db || !$db->ok()) { $result['errors'][] = 'Sin conexión a SQL Server; los indicadores no están disponibles.'; return $result; }
    $clock = dpd_timestamp($db->scalar('SELECT CONVERT(varchar(19),SYSDATETIME(),120) AS AHORA'));
    if ($clock !== null) $result['now'] = $clock;
    $meta = $db->all("SELECT S.name AS SCHEMA_NAME,O.name AS OBJECT_NAME,C.name AS COLUMN_NAME
        FROM sys.columns C INNER JOIN sys.objects O ON O.object_id=C.object_id
        INNER JOIN sys.schemas S ON S.schema_id=O.schema_id
        WHERE (S.name=N'dbo' AND O.type=N'U' AND O.name IN
          (N'CLEAR_DASH_POZOS_CONFIG',N'CLEAR_DASH_POZOS_ACTUAL',N'CLEAR_DASH_POZOS_DIARIO',
           N'CLEAR_DASH_POZOS_FALLAS_DIA',N'CLEAR_DASH_POZOS_ESTADO_FUENTES',
           N'TELEMETRIA_POZOS_GENERAL_CACHE',N'CLEAR_API_Q158_POZOS',
           N'CLEAR_TECSS_POZOS_CACHE',N'CLEAR_SUPERVISORES_INSTALACIONES'))
          OR (S.name=N'CLEAR' AND O.name=N'ZONAS')");
    $catalog = [];
    foreach ($meta as $item) {
        $schema = dpd_text(dpd_value($item, 'SCHEMA_NAME'));
        $object = dpd_text(dpd_value($item, 'OBJECT_NAME'));
        $column = dpd_text(dpd_value($item, 'COLUMN_NAME'));
        if ($schema !== '' && $object !== '' && $column !== '') $catalog[$schema . '.' . $object][strtoupper($column)] = $column;
    }
    if (isset($catalog['dbo.CLEAR_DASH_POZOS_CONFIG'])) {
        $configRows = $db->all('SELECT CONVERT(varchar(8),HORA_CORTE,108) AS HORA_CORTE,RETENCION_DIAS,MAX_ANTIGUEDAD_MIN FROM dbo.CLEAR_DASH_POZOS_CONFIG WHERE ID=1');
        if ($configRows) {
            $cutoff = dpd_text(dpd_value($configRows[0], 'HORA_CORTE'));
            if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $cutoff)) $result['config']['hora_corte'] = $cutoff;
            $result['config']['retencion_dias'] = max(1, (int)dpd_value($configRows[0], 'RETENCION_DIAS', 400));
            $result['config']['max_antiguedad_min'] = max(1, (int)dpd_value($configRows[0], 'MAX_ANTIGUEDAD_MIN', 30));
        }
    }
    $now = $result['now']; $maxMinutes = $result['config']['max_antiguedad_min'];
    $result['operation'] = dpd_operation($now, $result['config']['hora_corte']);
    $result['ready'] = isset($catalog['dbo.CLEAR_DASH_POZOS_ACTUAL']);
    if ($result['ready']) {
        $actual = $db->all('SELECT POZO_CLAVE,POZO,BATERIA,TIPO,EN_SCADA,EN_TECSS,COMUNICACION,ESTADO,DETALLE_ESTADO,
            SCADA_COMUNICACION,TECSS_COMUNICACION,SCADA_ESTADO,TECSS_ESTADO,
            CONVERT(varchar(19),FECHA_CACHE,120) AS FECHA_CACHE,
            CONVERT(varchar(19),SIN_COM_DESDE,120) AS SIN_COM_DESDE,SIN_COM_INICIO_ESTIMADO
            FROM dbo.CLEAR_DASH_POZOS_ACTUAL ORDER BY POZO');
        foreach ($actual as $item) {
            $well = dpd_text(dpd_value($item, 'POZO'));
            $key = dpd_text(dpd_value($item, 'POZO_CLAVE'));
            if ($key === '') $key = dpd_well_key($well);
            if ($key === '') continue;
            $row = dpd_base_row($well, $key);
            $row['bateria'] = dpd_text(dpd_value($item, 'BATERIA'));
            $row['en_scada'] = (int)dpd_value($item, 'EN_SCADA') === 1;
            $row['en_tecss'] = (int)dpd_value($item, 'EN_TECSS') === 1;
            $row['tipo'] = $row['en_scada'] && $row['en_tecss'] ? 'SCADA + TECSS' : ($row['en_tecss'] ? 'TECSS' : 'SCADA');
            $rawType = dpd_upper(dpd_value($item, 'TIPO'));
            if ($rawType !== '') $row['_tipos'][$rawType] = true;
            $comm = dpd_text(dpd_value($item, 'COMUNICACION'));
            $state = dpd_text(dpd_value($item, 'ESTADO'));
            $row['comm'] = in_array($comm, ['Comunicando','Intermitente','Sin comunicación','Sin dato'], true) ? $comm : dpd_comm($comm);
            $row['state'] = in_array($state, ['En falla','Parado','En marcha','Desconocido'], true) ? $state : dpd_state($state);
            foreach (['scada','tecss'] as $sourceKey) {
                if (!$row['en_' . $sourceKey]) continue;
                $sourceComm = dpd_text(dpd_value($item, strtoupper($sourceKey) . '_COMUNICACION'));
                $sourceState = dpd_text(dpd_value($item, strtoupper($sourceKey) . '_ESTADO'));
                $row[$sourceKey . '_comm'] = in_array($sourceComm, ['Comunicando','Intermitente','Sin comunicación','Sin dato'], true) ? $sourceComm : dpd_comm($sourceComm);
                $row[$sourceKey . '_state'] = in_array($sourceState, ['En falla','Parado','En marcha','Desconocido'], true) ? $sourceState : dpd_state($sourceState);
            }
            $row['date'] = dpd_timestamp(dpd_value($item, 'FECHA_CACHE'));
            $row['stale'] = !dpd_fresh($row['date'], $now, $maxMinutes);
            $row['detalle_falla'] = dpd_text(dpd_value($item, 'DETALLE_ESTADO'));
            if ($row['comm'] === 'Sin comunicación') {
                $since = dpd_timestamp(dpd_value($item, 'SIN_COM_DESDE'));
                if ($since !== null && $since <= $row['date']) $row['sin_com_desde'] = $since;
                $row['sin_com_estimado'] = (int)dpd_value($item, 'SIN_COM_INICIO_ESTIMADO') === 1;
            }
            $result['rows'][] = $row;
        }
        if (!$actual) $result['errors'][] = 'El registro del dashboard todavía no tiene datos disponibles. Verificá su primera actualización SQL.';
        if (isset($catalog['dbo.CLEAR_DASH_POZOS_DIARIO'])) {
            $result['daily'] = $db->all('SELECT CONVERT(varchar(10),DIA_OPERATIVO,23) AS DIA_OPERATIVO,
                CONVERT(varchar(19),FECHA_PRIMERA_MUESTRA,120) AS FECHA_PRIMERA_MUESTRA,
                CONVERT(varchar(19),FECHA_ULTIMA_MUESTRA,120) AS FECHA_ULTIMA_MUESTRA,
                TOTAL,SIN_COM,COMUNICANDO,INTERMITENTE,SIN_DATO,EN_FALLA,MUESTRAS,COBERTURA_COMPLETA,
                CONVERT(varchar(8),HORA_CORTE,108) AS HORA_CORTE,BRECHA_MAX_MIN
                FROM dbo.CLEAR_DASH_POZOS_DIARIO WHERE DIA_OPERATIVO>=DATEADD(day,-400,CONVERT(date,SYSDATETIME())) ORDER BY DIA_OPERATIVO');
        }
    } else {
        $result['errors'][] = 'Los días sin comunicación y los KPI históricos requieren la instalación SQL del Dashboard de Pozos. Se muestra la telemetría actual disponible.';
        $columns = $catalog['dbo.TELEMETRIA_POZOS_GENERAL_CACHE'] ?? [];
        if (isset($columns['POZO'], $columns['BATERIA'], $columns['TIPO'], $columns['COMUNICACION'], $columns['ESTADO'], $columns['FECHA_CACHE'])) {
            $source = $db->all('SELECT POZO,BATERIA,TIPO,COMUNICACION,ESTADO,CONVERT(varchar(19),FECHA_CACHE,120) AS FECHA_CACHE FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE WHERE POZO IS NOT NULL ORDER BY POZO');
            $grouped = dpd_group_telemetry($source, $now, $maxMinutes);
            $result['rows'] = $grouped['rows'];
            if ($grouped['expired']) $result['errors'][] = $grouped['expired'] . ' lecturas vencidas o sin fecha se excluyeron de la clasificación actual.';
            if (!$source) $result['errors'][] = 'No se pudieron obtener pozos de la caché de telemetría.';
        } else $result['errors'][] = 'No está disponible la caché local de telemetría de pozos.';
    }
    dpd_metadata($db, $catalog, $result['rows'], $result['errors']);

    if (isset($catalog['dbo.CLEAR_DASH_POZOS_ESTADO_FUENTES'])) {
        $statusRows = $db->all('SELECT CONVERT(varchar(19),FECHA_TELEMETRIA,120) AS FECHA_TELEMETRIA,
            CONVERT(varchar(19),FECHA_FALLAS,120) AS FECHA_FALLAS,FALLAS_DISPONIBLES,
            CONVERT(varchar(10),DIA_OPERATIVO_FALLAS,23) AS DIA_OPERATIVO_FALLAS,MENSAJE
            FROM dbo.CLEAR_DASH_POZOS_ESTADO_FUENTES WHERE ID=1');
        if ($statusRows) {
            $result['source_status'] = $statusRows[0];
            $result['failure_counts_ready'] = (int)dpd_value($statusRows[0], 'FALLAS_DISPONIBLES') === 1
                && dpd_text(dpd_value($statusRows[0], 'DIA_OPERATIVO_FALLAS')) === $result['operation']['date']
                && dpd_fresh(dpd_timestamp(dpd_value($statusRows[0], 'FECHA_FALLAS')), $now, $maxMinutes)
                && isset($catalog['dbo.CLEAR_DASH_POZOS_FALLAS_DIA']);
        }
    }
    if ($result['failure_counts_ready']) {
        // La fila vacía confirma una lectura correcta aun cuando no hay fallas.
        // Si SELECT falla, no confundir [] con una jornada de cero eventos.
        $events = $db->all('SELECT POZO_CLAVE,DETALLE_FALLA,CANT_FALLAS,
            CONVERT(varchar(19),ULTIMA_FALLA,120) AS ULTIMA_FALLA
            FROM dbo.CLEAR_DASH_POZOS_FALLAS_DIA WHERE DIA_OPERATIVO=CONVERT(date,?,23)
            UNION ALL SELECT NULL,NULL,CONVERT(bigint,0),NULL
            ORDER BY ULTIMA_FALLA DESC', [$result['operation']['date']]);
        if (!$events) {
            $result['failure_counts_ready'] = false;
            $result['errors'][] = 'No se pudo leer el conteo de fallas del día operativo; se muestra Sin dato.';
        }
        $eventMap = [];
        foreach ($events as $event) {
            $key = dpd_text(dpd_value($event, 'POZO_CLAVE'));
            if ($key === '') continue;
            if (!isset($eventMap[$key])) $eventMap[$key] = ['count'=>0, 'details'=>[]];
            $eventMap[$key]['count'] += max(0, (int)dpd_value($event, 'CANT_FALLAS'));
            $detail = dpd_text(dpd_value($event, 'DETALLE_FALLA'));
            if ($detail !== '') $eventMap[$key]['details'][$detail] = true;
        }
        foreach ($result['rows'] as &$row) {
            if (!$result['failure_counts_ready']) continue;
            $eventsForWell = $eventMap[$row['pozo_clave']] ?? ['count'=>0, 'details'=>[]];
            $row['fallas_dia'] = $eventsForWell['count'];
            if ($eventsForWell['details']) $row['detalle_falla'] = implode(' · ', array_keys($eventsForWell['details']));
        }
        unset($row);
    } elseif ($result['rows']) $result['errors'][] = 'El conteo de fallas del día operativo no tiene una lectura vigente disponible; se muestra Sin dato.';

    $staleCount = 0;
    foreach ($result['rows'] as &$row) {
        if ($row['stale']) $staleCount++;
        unset($row['_tipos']);
    }
    unset($row);
    $result['stale'] = $staleCount > 0;
    if ($staleCount && $result['ready']) $result['errors'][] = $staleCount . ' pozos muestran un estado guardado de hace más de ' . $maxMinutes . ' minutos o sin fecha válida. No se extiende su duración sin comunicación.';
    usort($result['rows'], static function ($a, $b) { return strnatcasecmp($a['pozo'], $b['pozo']); });
    $result['errors'] = array_values(array_unique($result['errors']));
    return $result;
}
