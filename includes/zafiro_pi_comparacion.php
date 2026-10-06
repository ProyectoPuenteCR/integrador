<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/zafiro_pi_comparacion.php
   Cruce Q412 (Zafiro) vs grilla general proveniente de PI.
============================================================= */

function zpc_norm_pozo($value)
{
    $v = trim((string)$value);
    if ($v === '') return '';
    $v = function_exists('mb_strtoupper') ? mb_strtoupper($v, 'UTF-8') : strtoupper($v);
    $v = preg_replace('/\s+/', '', $v);
    if (strpos($v, 'YPF.SC.') === 0) $v = substr($v, 7);
    return $v;
}

function zpc_ready($db)
{
    if (!$db || !$db->ok()) return false;
    return (int)$db->scalar(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_API_Q412_CACHE',N'U') IS NOT NULL
          AND OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE',N'U') IS NOT NULL
          THEN 1 ELSE 0 END"
    ) === 1;
}

function zpc_json_pref($key, array $default = [])
{
    $raw = (string)user_pref_get($key, '');
    if ($raw === '') return $default;
    $value = json_decode($raw, true);
    return is_array($value) ? $value : $default;
}

function zpc_method_key($value)
{
    $value = trim((string)$value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function zpc_load($db)
{
    $result = [
        'ok' => false,
        'error' => '',
        'rows' => [],
        'methods' => [],
        'states' => [],
        'piCount' => 0,
        'zafiroCount' => 0,
        'latestQ412' => null,
        'latestPI' => null,
    ];

    if (!zpc_ready($db)) {
        $result['error'] = 'Faltan CLEAR_API_Q412_CACHE o TELEMETRIA_POZOS_GENERAL_CACHE.';
        return $result;
    }

    $q412 = $db->all(
        "SELECT PozoId,Pozo,SubArea,Area,Instalacion,DiaOperativo,Petroleo24,Agua24,Liquido24,Gas24,
                DiasSinTest,EstadoZafiro,EstadoActivo,MetodoZafiro,TipoMetodoZafiro,FechaCarga
         FROM dbo.CLEAR_API_Q412_CACHE
         WHERE Pozo IS NOT NULL
         ORDER BY Pozo"
    );
    if ($q412 === false) {
        $result['error'] = $db->error();
        return $result;
    }

    $piRows = $db->all(
        "SELECT
             POZO,
             BATERIA,
             TIPO,
             COMUNICACION,
             ESTADO,
             COALESCE(ULTIMA_ACTUALIZACION,FECHA_CACHE) AS FECHA_TELEMETRIA
         FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE
         WHERE POZO IS NOT NULL"
    );
    if ($piRows === false) {
        $result['error'] = $db->error();
        return $result;
    }

    $pi = [];
    foreach ($piRows as $r) {
        $key = zpc_norm_pozo($r['POZO'] ?? '');
        if ($key === '') continue;
        $source = trim((string)($r['TIPO'] ?? ''));
        if ($source === '') $source = 'PI';
        $fecha = trim((string)($r['FECHA_TELEMETRIA'] ?? ''));
        if (!isset($pi[$key])) {
            $pi[$key] = [
                'pozo' => (string)($r['POZO'] ?? ''),
                'battery' => (string)($r['BATERIA'] ?? ''),
                'sources' => [],
                'last' => $fecha,
                'comm' => (string)($r['COMUNICACION'] ?? ''),
                'state' => (string)($r['ESTADO'] ?? ''),
            ];
        }
        if (!in_array($source, $pi[$key]['sources'], true)) $pi[$key]['sources'][] = $source;
        if ($fecha !== '' && ($pi[$key]['last'] === '' || strcmp($fecha, $pi[$key]['last']) > 0)) {
            $pi[$key]['last'] = $fecha;
            $pi[$key]['comm'] = (string)($r['COMUNICACION'] ?? '');
            $pi[$key]['state'] = (string)($r['ESTADO'] ?? '');
        }
    }

    $methodCounts = [];
    $stateCounts = [];
    $rows = [];
    $latestQ412 = null;
    foreach ($q412 as $r) {
        $method = trim((string)($r['MetodoZafiro'] ?? ''));
        if ($method === '') $method = 'Sin método';
        if (!isset($methodCounts[$method])) $methodCounts[$method] = 0;
        $methodCounts[$method]++;

        $stateName = trim((string)($r['EstadoZafiro'] ?? ''));
        if ($stateName === '') $stateName = 'Sin estado';
        if (!isset($stateCounts[$stateName])) $stateCounts[$stateName] = 0;
        $stateCounts[$stateName]++;

        $key = zpc_norm_pozo($r['Pozo'] ?? '');
        $hit = $key !== '' && isset($pi[$key]) ? $pi[$key] : null;
        $fechaCarga = trim((string)($r['FechaCarga'] ?? ''));
        if ($fechaCarga !== '' && ($latestQ412 === null || strcmp($fechaCarga, $latestQ412) > 0)) $latestQ412 = $fechaCarga;

        $rows[] = [
            'key' => $key,
            'pozo' => (string)($r['Pozo'] ?? ''),
            'instalacion' => (string)($r['Instalacion'] ?? ''),
            'zona' => (string)($r['SubArea'] ?? ''),
            'area' => (string)($r['Area'] ?? ''),
            'metodo' => $method,
            'tipoMetodo' => (string)($r['TipoMetodoZafiro'] ?? ''),
            'estado' => $stateName,
            'activo' => (int)($r['EstadoActivo'] ?? 0),
            'petroleo' => $r['Petroleo24'] === null ? null : (float)$r['Petroleo24'],
            'liquido' => $r['Liquido24'] === null ? null : (float)$r['Liquido24'],
            'enPi' => $hit !== null,
            'piSource' => $hit ? implode(' + ', $hit['sources']) : '',
            'piLast' => $hit ? $hit['last'] : '',
            'piComm' => $hit ? $hit['comm'] : '',
            'piState' => $hit ? $hit['state'] : '',
        ];
    }

    arsort($methodCounts);
    arsort($stateCounts);
    $latestPI = null;
    foreach ($pi as $p) {
        if ($p['last'] !== '' && ($latestPI === null || strcmp($p['last'], $latestPI) > 0)) $latestPI = $p['last'];
    }

    $result['ok'] = true;
    $result['rows'] = $rows;
    $result['methods'] = $methodCounts;
    $result['states'] = $stateCounts;
    $result['piCount'] = count($pi);
    $result['zafiroCount'] = count($rows);
    $result['latestQ412'] = $latestQ412;
    $result['latestPI'] = $latestPI;
    return $result;
}

function zpc_selected_methods(array $methodCounts)
{
    $available = array_keys($methodCounts);
    $stored = zpc_json_pref('zafiro_pi_metodos', []);
    if (!$stored) {
        return array_values(array_filter($available, function ($m) {
            return zpc_method_key($m) !== zpc_method_key('No Posee');
        }));
    }
    $map = [];
    foreach ($available as $m) $map[zpc_method_key($m)] = $m;
    $selected = [];
    foreach ($stored as $m) {
        $k = zpc_method_key($m);
        if (isset($map[$k])) $selected[] = $map[$k];
    }
    return array_values(array_unique($selected));
}

function zpc_selected_states(array $stateCounts)
{
    $available = array_keys($stateCounts);
    $stored = zpc_json_pref('zafiro_pi_estados', []);
    if (!$stored) return $available;
    $map = [];
    foreach ($available as $s) $map[zpc_method_key($s)] = $s;
    $selected = [];
    foreach ($stored as $s) {
        $k = zpc_method_key($s);
        if (isset($map[$k])) $selected[] = $map[$k];
    }
    return array_values(array_unique($selected));
}

function zpc_selected_zones(array $rows)
{
    $available = zpc_unique($rows, 'zona');
    $stored = zpc_json_pref('zafiro_pi_zonas', []);
    if (!$stored) return $available;
    $map = [];
    foreach ($available as $z) $map[zpc_method_key($z)] = $z;
    $selected = [];
    foreach ($stored as $z) {
        $k = zpc_method_key($z);
        if (isset($map[$k])) $selected[] = $map[$k];
    }
    return array_values(array_unique($selected));
}

function zpc_excluded_wells()
{
    return array_values(array_unique(array_filter(array_map('strval', zpc_json_pref('zafiro_pi_exclusiones', [])))));
}

function zpc_apply_filters(array $rows, array $selectedMethods, array $selectedStates, array $selectedZones, array $excludedWells, array $query)
{
    $selected = [];
    foreach ($selectedMethods as $m) $selected[zpc_method_key($m)] = true;
    $states = [];
    foreach ($selectedStates as $s) $states[zpc_method_key($s)] = true;
    $zones = [];
    foreach ($selectedZones as $z) $zones[zpc_method_key($z)] = true;
    $excluded = [];
    foreach ($excludedWells as $w) $excluded[zpc_norm_pozo($w)] = true;

    $zone = trim((string)($query['zona'] ?? ''));
    $battery = trim((string)($query['bateria'] ?? ''));
    $state = trim((string)($query['estado'] ?? ''));
    $q = trim((string)($query['q'] ?? ''));
    $pi = trim((string)($query['pi'] ?? 'missing'));
    $showExcluded = !empty($query['ver_excluidos']);

    $out = [];
    foreach ($rows as $r) {
        if (!isset($selected[zpc_method_key($r['metodo'])])) continue;
        if (!isset($states[zpc_method_key($r['estado'])])) continue;
        if (!isset($zones[zpc_method_key($r['zona'])])) continue;
        $isExcluded = isset($excluded[$r['key']]);
        if ($isExcluded && !$showExcluded) continue;
        if ($zone !== '' && strcasecmp($r['zona'], $zone) !== 0) continue;
        if ($battery !== '' && strcasecmp($r['instalacion'], $battery) !== 0) continue;
        if ($state !== '' && strcasecmp($r['estado'], $state) !== 0) continue;
        if ($q !== '' && stripos($r['pozo'].' '.$r['instalacion'].' '.$r['zona'], $q) === false) continue;
        if ($pi === 'missing' && $r['enPi']) continue;
        if ($pi === 'found' && !$r['enPi']) continue;
        $r['excluded'] = $isExcluded;
        $out[] = $r;
    }
    return $out;
}

function zpc_unique(array $rows, $field)
{
    $values = [];
    foreach ($rows as $r) {
        $v = trim((string)($r[$field] ?? ''));
        if ($v !== '') $values[$v] = true;
    }
    $values = array_keys($values);
    natcasesort($values);
    return array_values($values);
}
