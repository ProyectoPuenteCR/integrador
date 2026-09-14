<?php
/* =============================================================
   Alarmas semanales · funciones compartidas (Pozos/Instalaciones)
   Compatible con PHP 7.4.
============================================================= */

function as_value(array $row, $key, $default = '')
{
    foreach ($row as $column => $value) {
        if (strcasecmp((string)$column, (string)$key) === 0) return $value;
    }
    return $default;
}

function as_valid_date($value)
{
    $value = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $date : null;
}

function as_week_for_date(DateTimeImmutable $date)
{
    $date = $date->setTime(0, 0, 0);
    $daysSinceWednesday = (((int)$date->format('N')) - 3 + 7) % 7;
    $start = $daysSinceWednesday ? $date->modify('-' . $daysSinceWednesday . ' days') : $date;
    return [
        'start' => $start,
        'end' => $start->modify('+6 days'),
        'next' => $start->modify('+7 days'),
    ];
}

function as_selected_week(array $query, DateTimeImmutable $today)
{
    $candidate = as_valid_date($query['semana'] ?? '');
    if (!$candidate) $candidate = $today;
    return as_week_for_date($candidate);
}

function as_week_options(DateTimeImmutable $today, $count = 16)
{
    $current = as_week_for_date($today);
    $rows = [];
    for ($i = 0; $i < max(1, (int)$count); $i++) {
        $start = $current['start']->modify('-' . ($i * 7) . ' days');
        $end = $start->modify('+6 days');
        $rows[] = [
            'value' => $start->format('Y-m-d'),
            'label' => $start->format('d/m/Y') . ' al ' . $end->format('d/m/Y'),
        ];
    }
    return $rows;
}

function as_terms($value)
{
    $value = trim((string)$value);
    if ($value === '') return [];
    return array_values(array_filter(preg_split('/\s+/', $value)));
}

function as_clean_text($value, $maxLength = 120)
{
    $value = trim((string)$value);
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) return '';
    if (function_exists('mb_substr')) return mb_substr($value, 0, $maxLength, 'UTF-8');
    return substr($value, 0, $maxLength);
}

function as_num($value, $decimals = 0)
{
    return number_format((float)$value, (int)$decimals, ',', '.');
}

function as_column_lookup($db)
{
    $rows = $db->all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS' ORDER BY ORDINAL_POSITION");
    $lookup = [];
    foreach ($rows as $row) {
        $name = trim((string)as_value($row, 'COLUMN_NAME', reset($row)));
        if ($name !== '') $lookup[strtoupper($name)] = $name;
    }
    return $lookup;
}

function as_quote_column($name)
{
    return '[' . str_replace(']', ']]', (string)$name) . ']';
}

function as_installation_type_options()
{
    return [
        'POZO' => 'Pozo',
        'BATERIA' => 'Batería',
        'SATELITE' => 'Satélite',
        'GAS' => 'Gas',
        'PIAS' => 'PIAS',
        'ENERGIA' => 'Energía',
        'PLANTA_TRAT' => 'Planta Trat.',
        'PLANTA_LH' => 'Planta LH',
        'SIN_CLASIFICAR' => 'Sin clasificar',
    ];
}

function as_installation_type_sql_labels()
{
    return [
        'POZO'=>'POZO', 'BATERIA'=>'BATERÍA', 'SATELITE'=>'SATÉLITE',
        'GAS'=>'GAS', 'PIAS'=>'PIAS', 'ENERGIA'=>'ENERGÍA',
        'PLANTA_TRAT'=>'PLANTA TRAT.', 'PLANTA_LH'=>'PLANTA LH',
        'SIN_CLASIFICAR'=>'SIN CLASIFICAR',
    ];
}

function as_cache_conditions($type, array $filters, array &$params)
{
    $conditions = ['TIPO=?', 'FECHA>=CONVERT(date,?,23)', 'FECHA<CONVERT(date,?,23)'];
    $params = [$type, $filters['from'], $filters['to_exclusive']];

    if ($type === 'P' && $filters['prefix'] !== '') {
        $conditions[] = 'UPPER(ENTIDAD) LIKE ?';
        $params[] = strtoupper($filters['prefix']) . '%';
    }
    if ($type === 'I' && $filters['installation'] !== '') {
        $conditions[] = 'ENTIDAD=?';
        $params[] = $filters['installation'];
    }
    if ($type === 'I' && $filters['installation_type'] !== '') {
        $typeLabels = as_installation_type_sql_labels();
        $conditions[] = 'TIPO_INSTALACION=?';
        $params[] = $typeLabels[$filters['installation_type']];
    }
    if ($filters['entity'] !== '') {
        $conditions[] = 'ENTIDAD LIKE ?';
        $params[] = '%' . $filters['entity'] . '%';
    }
    foreach (as_terms($filters['q']) as $term) {
        $conditions[] = '(ENTIDAD LIKE ? OR TAG LIKE ? OR DESCRIPCION LIKE ?)';
        $params[] = '%' . $term . '%';
        $params[] = '%' . $term . '%';
        $params[] = '%' . $term . '%';
    }
    foreach (as_terms($filters['tag']) as $term) {
        $conditions[] = 'TAG LIKE ?';
        $params[] = '%' . $term . '%';
    }
    foreach (as_terms($filters['description']) as $term) {
        $conditions[] = 'DESCRIPCION LIKE ?';
        $params[] = '%' . $term . '%';
    }
    if ($filters['day'] !== '') {
        $conditions[] = 'FECHA=CONVERT(date,?,23)';
        $params[] = $filters['day'];
    }
    if ($filters['hour'] !== '') {
        $conditions[] = 'HORA=?';
        $params[] = (int)$filters['hour'];
    }
    return $conditions;
}

function as_source_parts($type, array $filters, array $columns, array &$params)
{
    $dateName = $columns['ALM_NATIVETIMEIN'] ?? '';
    $tagName = $columns['ALM_TAGNAME'] ?? '';
    $descName = $columns['ALM_DESCR'] ?? ($columns['ALM_TAGDESC'] ?? '');
    $wellName = $columns['ALM_ALMEXTFLD2'] ?? '';
    $statusName = $columns['ALM_ALMSTATUS'] ?? '';
    $priorityName = $columns['ALM_ALMPRIORITY'] ?? '';
    $valueName = $columns['ALM_VALUE'] ?? '';
    $unitName = $columns['ALM_UNIT'] ?? '';

    if ($dateName === '' || $tagName === '') return ['ok'=>false, 'error'=>'No se encontraron ALM_NATIVETIMEIN y ALM_TAGNAME en dbo.FIXALARMS.'];

    $date = as_quote_column($dateName);
    $tagColumn = as_quote_column($tagName);
    $tag = "LTRIM(RTRIM(CONVERT(nvarchar(255),$tagColumn)))";
    $desc = $descName !== '' ? "LTRIM(RTRIM(CONVERT(nvarchar(1000)," . as_quote_column($descName) . ")))" : "N''";
    $well = $wellName !== '' ? "LTRIM(RTRIM(CONVERT(nvarchar(255)," . as_quote_column($wellName) . ")))" : "N''";
    $installation = "LEFT($tag,CHARINDEX('_',$tag+'_')-1)";
    $entity = $type === 'P' ? $well : $installation;
    /*
       Clasificación operativa derivada del propio registro de FIXALARMS:
       - ALM_ALMEXTFLD2 YPF.SC identifica alarmas asociadas a pozos.
       - PIALH3 corresponde a PIAS.
       - Los prefijos EBB... y ELH... corresponden a Energía.
       - Los prefijos GL... corresponden a Gas.
       - Los prefijos PT... corresponden a Planta Trat.
       - Los prefijos PLH... corresponden a Planta LH.
       - Los prefijos RL... y B... corresponden a baterías.
       - Los prefijos S... corresponden a satélites.
       No requiere JOIN ni consulta adicional.
    */
    $installationType = "CASE " .
        "WHEN UPPER($installation) = N'PIALH3' THEN N'PIAS' " .
        "WHEN UPPER($well) LIKE N'YPF.SC%' THEN N'POZO' " .
        "WHEN UPPER($installation) LIKE N'EBB%' OR UPPER($installation) LIKE N'ELH%' THEN N'ENERGÍA' " .
        "WHEN UPPER($installation) LIKE N'GL%' THEN N'GAS' " .
        "WHEN UPPER($installation) LIKE N'PLH%' THEN N'PLANTA LH' " .
        "WHEN UPPER($installation) LIKE N'PT%' THEN N'PLANTA TRAT.' " .
        "WHEN UPPER($installation) LIKE N'RL%' OR UPPER($installation) LIKE N'B%' THEN N'BATERÍA' " .
        "WHEN UPPER($installation) LIKE N'S%' THEN N'SATÉLITE' " .
        "ELSE N'SIN CLASIFICAR' END";
    $status = $statusName !== '' ? "LTRIM(RTRIM(CONVERT(nvarchar(120)," . as_quote_column($statusName) . ")))" : "N''";
    $priority = $priorityName !== '' ? "LTRIM(RTRIM(CONVERT(nvarchar(120)," . as_quote_column($priorityName) . ")))" : "N''";
    $value = $valueName !== '' ? "LTRIM(RTRIM(CONVERT(nvarchar(255)," . as_quote_column($valueName) . ")))" : "N''";
    $unit = $unitName !== '' ? "LTRIM(RTRIM(CONVERT(nvarchar(100)," . as_quote_column($unitName) . ")))" : "N''";

    $conditions = [
        "$date>=CONVERT(datetime2,?,126)",
        "$date<CONVERT(datetime2,?,126)",
        "$tagColumn IS NOT NULL",
        "$tag<>N''",
    ];
    $params = [$filters['range_from'], $filters['range_to']];

    if ($type === 'P') {
        if ($wellName === '') return ['ok'=>false, 'error'=>'No se encontró ALM_ALMEXTFLD2 para identificar pozos.'];
        $conditions[] = as_quote_column($wellName) . ' IS NOT NULL';
        $conditions[] = "$well<>N''";
        $conditions[] = "UPPER($well) LIKE ?";
        $params[] = strtoupper($filters['prefix'] !== '' ? $filters['prefix'] : 'YPF.SC') . '%';
    } elseif ($filters['installation'] !== '') {
        $conditions[] = "$installation=?";
        $params[] = $filters['installation'];
    }

    if ($type === 'I' && $filters['installation_type'] !== '') {
        $typeLabels = as_installation_type_sql_labels();
        $conditions[] = "$installationType=?";
        $params[] = $typeLabels[$filters['installation_type']];
    }

    if ($filters['entity'] !== '') {
        $conditions[] = "$entity LIKE ?";
        $params[] = '%' . $filters['entity'] . '%';
    }
    foreach (as_terms($filters['q']) as $term) {
        $conditions[] = "($entity LIKE ? OR $tag LIKE ? OR $desc LIKE ?)";
        $params[] = '%' . $term . '%';
        $params[] = '%' . $term . '%';
        $params[] = '%' . $term . '%';
    }
    foreach (as_terms($filters['tag']) as $term) {
        $conditions[] = "$tag LIKE ?";
        $params[] = '%' . $term . '%';
    }
    foreach (as_terms($filters['description']) as $term) {
        $conditions[] = "$desc LIKE ?";
        $params[] = '%' . $term . '%';
    }
    if ($filters['status'] !== '') {
        $conditions[] = "$status LIKE ?";
        $params[] = '%' . $filters['status'] . '%';
    }
    if ($filters['priority'] !== '') {
        $conditions[] = "$priority LIKE ?";
        $params[] = '%' . $filters['priority'] . '%';
    }
    if ($filters['hour'] !== '') {
        $conditions[] = "DATEPART(HOUR,$date)=?";
        $params[] = (int)$filters['hour'];
    }

    return [
        'ok'=>true,
        'date'=>$date,
        'tag'=>$tag,
        'description'=>$desc,
        'well'=>$well,
        'installation'=>$installation,
        'installation_type'=>$installationType,
        'entity'=>$entity,
        'status'=>$status,
        'priority'=>$priority,
        'value'=>$value,
        'unit'=>$unit,
        'where'=>'WHERE ' . implode(' AND ', $conditions),
    ];
}

function as_build_filters($type, array $query, array $week)
{
    $day = as_valid_date($query['dia'] ?? '');
    if ($day && ($day < $week['start'] || $day > $week['end'])) $day = null;
    $hourRaw = trim((string)($query['hora'] ?? ''));
    $hour = ($hourRaw !== '' && ctype_digit($hourRaw) && (int)$hourRaw >= 0 && (int)$hourRaw <= 23) ? (string)(int)$hourRaw : '';
    /* El caché de Pozos usa el mismo prefijo operativo que las pantallas actuales. */
    $prefix = $type === 'P' ? 'YPF.SC' : '';
    $installation = $type === 'I' ? as_clean_text($query['instalacion'] ?? '', 100) : '';
    $installationType = $type === 'I' ? strtoupper(as_clean_text($query['tipo_instalacion'] ?? '', 30)) : '';
    if (!array_key_exists($installationType, as_installation_type_options())) $installationType = '';

    $rangeStart = $day ?: $week['start'];
    $rangeEnd = $day ? $day->modify('+1 day') : $week['next'];
    return [
        'from'=>$week['start']->format('Y-m-d'),
        'to_exclusive'=>$week['next']->format('Y-m-d'),
        'range_from'=>$rangeStart->format('Y-m-d') . 'T00:00:00',
        'range_to'=>$rangeEnd->format('Y-m-d') . 'T00:00:00',
        'day'=>$day ? $day->format('Y-m-d') : '',
        'hour'=>$hour,
        'prefix'=>$prefix,
        'installation'=>$installation,
        'installation_type'=>$installationType,
        'q'=>as_clean_text($query['q'] ?? '', 120),
        'entity'=>as_clean_text($query['entidad'] ?? '', 120),
        'tag'=>as_clean_text($query['tag'] ?? '', 120),
        'description'=>as_clean_text($query['descripcion'] ?? '', 160),
        'status'=>as_clean_text($query['estado'] ?? '', 80),
        'priority'=>as_clean_text($query['prioridad'] ?? '', 80),
    ];
}

function as_query_string(array $overrides = [], array $remove = [])
{
    $query = $_GET;
    foreach ($remove as $key) unset($query[$key]);
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
        else $query[$key] = $value;
    }
    return http_build_query($query);
}
