<?php
/* Lecturas del pozo: contador de la caché existente y última alarma exacta.
 * No agrupar FIXALARMS ni aplicar funciones a la columna usada como filtro. */
function wa_parameter($metadata, $well) {
    $type = strtolower((string)($metadata['DATA_TYPE'] ?? ''));
    if (!in_array($type, ['char', 'varchar', 'nchar', 'nvarchar'], true)) {
        throw new RuntimeException('El campo del pozo no permite una búsqueda exacta compatible con su índice.');
    }
    $length = (int)($metadata['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
    $maximum = ($type === 'nchar' || $type === 'nvarchar') ? 4000 : 8000;
    if ($length === -1 && in_array($type, ['varchar', 'nvarchar'], true)) {
        return 'CONVERT('.$type.'(max), ?)';
    }
    if ($length < 1 || $length > $maximum) {
        throw new RuntimeException('No se pudo verificar el tipo del campo del pozo.');
    }
    // El parámetro nunca se trunca a otro pozo. Para nombres UTF-8, el tamaño
    // en bytes es una cota conservadora tanto para varchar como para nvarchar.
    if (strlen($well) > $length) {
        throw new InvalidArgumentException('El nombre del pozo excede el tamaño del campo del historial.');
    }
    return 'CONVERT('.$type.'('.$length.'), ?)';
}

function wa_alarms($db, $well, $since = null, $until = null) {
    // since/until se conservan por compatibilidad con los consumidores BM.
    // La ventana de 24 h ya la calcula el proceso que actualiza la caché.
    $metadata = $db->all("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME IN ('FIXALARMS','TELEMETRIA_POZOS_GENERAL_CACHE')");
    if ($db->error()) throw new RuntimeException('No se pudo consultar el historial de alarmas.');
    $tables = [];
    foreach ($metadata as $row) {
        $table = strtoupper((string)($row['TABLE_NAME'] ?? ''));
        $name = (string)($row['COLUMN_NAME'] ?? '');
        if ($name === '') continue;
        $row['identifier'] = '['.str_replace(']', ']]', $name).']';
        $tables[$table][strtoupper($name)] = $row;
    }
    $columns = $tables['FIXALARMS'] ?? [];
    foreach (['ALM_ALMEXTFLD2', 'ALM_NATIVETIMEIN'] as $required) {
        if (!isset($columns[$required])) throw new RuntimeException('El historial no tiene los campos de pozo y fecha requeridos.');
    }
    $pick = function ($names, $alias) use ($columns) {
        foreach ($names as $name) {
            if (isset($columns[$name])) return 'CONVERT(nvarchar(4000),'.$columns[$name]['identifier'].') AS ['.$alias.']';
        }
        return "N'' AS [$alias]";
    };
    $select = implode(',', [
        $pick(['ALM_DESCR','ALM_TAGDESC'], 'description'),
        $pick(['ALM_TAGNAME'], 'tag'), $pick(['ALM_VALUE'], 'value'),
        // Conservar el nombre original hasta pasar por la política CFN de DB.
        $pick(['ALM_ALMSTATUS'], 'ALM_ALMSTATUS'),
        $pick(['ALM_NATIVETIMEIN'], 'started_at'), $pick(['ALM_NATIVETIMELAST'], 'last_at')
    ]);
    $where = $columns['ALM_ALMEXTFLD2']['identifier'].' = '.wa_parameter($columns['ALM_ALMEXTFLD2'], $well);
    $date = $columns['ALM_NATIVETIMEIN']['identifier'];
    $latestRows = $db->all("SELECT TOP (1) $select FROM dbo.FIXALARMS WHERE $where ORDER BY $date DESC", [$well]);
    if ($db->error()) throw new RuntimeException('No se pudo consultar la última alarma.');
    $latest = $latestRows[0] ?? null;
    if ($latest !== null) {
        $latest['status'] = $latest['ALM_ALMSTATUS'] ?? '';
        unset($latest['ALM_ALMSTATUS']);
    }

    $result = ['latest' => $latest, 'count24h' => null, 'count24hAvailable' => false,
        'count24hSource' => 'TELEMETRIA_POZOS_GENERAL_CACHE',
        'count24hWarning' => 'El contador de 24 h no está disponible en la caché.'];
    $cache = $tables['TELEMETRIA_POZOS_GENERAL_CACHE'] ?? [];
    if (!isset($cache['POZO'], $cache['ALM'])) return $result;
    try {
        $cacheWhere = $cache['POZO']['identifier'].' = '.wa_parameter($cache['POZO'], $well);
        // MAX conserva el criterio de la grilla si un pozo tiene dos sistemas.
        // La conversión sólo afecta la proyección de esta tabla pequeña.
        $count = $db->scalar('SELECT MAX(TRY_CONVERT(bigint, '.$cache['ALM']['identifier'].'))
            FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE WHERE '.$cacheWhere, [$well]);
        if ($db->error() || $count === null || $count === '' || !is_numeric($count) || (float)$count < 0 || (float)$count > PHP_INT_MAX) return $result;
        $result['count24h'] = (int)$count;
        $result['count24hAvailable'] = true;
        $result['count24hWarning'] = '';
    } catch (Throwable $error) {
        // Una caché ausente/incompatible no oculta una última alarma válida.
    }
    return $result;
}
