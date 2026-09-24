<?php
/* =============================================================
   CLEAR PLATAFORMA — list.php  (motor genérico de listas)
   Uso: list.php?s=alarmas24h
   Lee la config de screens.php y muestra la tabla con el diseño
   de la plataforma. Búsqueda, orden y paginación por URL.
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/screens.php';
require_once __DIR__ . '/includes/recon_comments.php';
require_once __DIR__ . '/includes/zones.php';
require_once __DIR__ . '/includes/alarm_actions.php';
require_once __DIR__ . '/includes/performance_cache.php';
require_once __DIR__ . '/includes/installation_type.php';
require_once __DIR__ . '/includes/novedades_semanales_common.php';

auth_require();

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = $cfg['app']['role'] ?? 'Administrador';

$screens = clear_screens();
$key = isset($_GET['s']) ? $_GET['s'] : '';
if (!isset($screens[$key])) {
    http_response_code(404);
    $sc = null;
} else {
    $sc = $screens[$key];
}
$ACTIVE = $key;
if ($sc !== null) permissions_require_menu($key);
$CAN_VIEW_COMMENTS = permissions_can('comments.view');
$CAN_CREATE_COMMENTS = permissions_can('comments.create');
$reportEnabled = in_array($key,['alarmas24h','alarmas_activas','suprimidas','reconocidas','reconocidas_usr','top20_all','top20_24h','ranking24h','tendencia_sem','tend_sem_tags','prioridad','importadas'],true) && permissions_can_menu('novedades_semanales_reporte');

/* --- Parámetros de URL --- */
$page       = max(1, (int)($_GET['p'] ?? 1));
$allowedPerPage = ['50', '100', 'all'];
$perPageParam = strtolower(trim((string)($_GET['per_page'] ?? '50')));
if (!in_array($perPageParam, $allowedPerPage, true)) $perPageParam = '50';
$showAllRows = $perPageParam === 'all';
$perPage = $showAllRows ? 0 : (int)$perPageParam;
if ($showAllRows) $page = 1;
$q          = trim($_GET['q'] ?? '');
$filterZone         = trim($_GET['zona'] ?? '');
$filterInstallation = trim($_GET['instalacion'] ?? '');
$filterStatus       = trim($_GET['estado'] ?? '');
$filterPriority     = trim($_GET['prioridad'] ?? '');
$filterValue        = trim($_GET['valor'] ?? '');
$filterInstallationType = trim($_GET['tipo_instalacion'] ?? '');
$alarmScope = strtolower(trim((string)($_GET['scope'] ?? '')));
if (!in_array($alarmScope, ['instalaciones','pozos'], true)) $alarmScope = '';
$columnFiltersRaw = isset($_GET['col']) && is_array($_GET['col']) ? $_GET['col'] : [];
$filterFrom     = trim($_GET['fecha_desde'] ?? '');
$filterFromTime = trim($_GET['hora_desde'] ?? '');
$filterTo       = trim($_GET['fecha_hasta'] ?? '');
$filterToTime   = trim($_GET['hora_hasta'] ?? '');
$sortCol        = $_GET['sort'] ?? '';
$sortDir        = (strtolower($_GET['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';

// Compatibilidad con enlaces anteriores que usaban un único parámetro "fecha".
$legacyDate = trim($_GET['fecha'] ?? '');
if ($filterFrom === '' && $filterTo === '' && $legacyDate !== '') {
    $filterFrom = $legacyDate;
    $filterTo = $legacyDate;
}

// Aceptar únicamente fechas ISO válidas (AAAA-MM-DD).
function clear_valid_iso_date($value) {
    if ($value === '') return '';
    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    $isValid = $dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $dt->format('Y-m-d') === $value;
    return $isValid ? $value : '';
}

function clear_valid_hhmm_time($value) {
    if ($value === '') return '';
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) return '';
    return $value;
}

// La instalación es el prefijo del TAG anterior al primer guion bajo.
function clear_valid_installation($value) {
    $value = trim((string)$value);
    if ($value === '' || strlen($value) > 100) return '';
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) return '';
    return $value;
}

// Validación común para valores seleccionados desde combos dinámicos.
function clear_valid_combo_value($value) {
    $value = trim((string)$value);
    if ($value === '' || strlen($value) > 150) return '';
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) return '';
    return $value;
}

function clear_effective_from_datetime($date, $time) {
    if ($date === '') return null;
    $time = $time !== '' ? $time : '00:00';
    return $date . ' ' . $time;
}

function clear_effective_to_datetime($date, $time) {
    if ($date === '') return null;
    $time = $time !== '' ? $time : '00:00';
    return $date . ' ' . $time;
}

function clear_label_date_range($date, $time) {
    if ($date === '') return '';
    $stamp = strtotime($date . ' ' . ($time !== '' ? $time : '00:00'));
    return $stamp ? date('d/m/Y', $stamp) . ($time !== '' ? ' ' . $time : '') : $date . ($time !== '' ? ' ' . $time : '');
}

function clear_related_tag_query($tag) {
    $tag = trim((string)$tag);
    if ($tag === '') return '';
    $parts = explode('_', $tag);
    if (count($parts) > 1) {
        array_pop($parts);
        $group = trim(implode('_', $parts));
        if ($group !== '') return $group;
    }
    return $tag;
}

$filterInstallation = clear_valid_installation($filterInstallation);
$filterStatus       = clear_valid_combo_value($filterStatus);
$filterPriority     = clear_valid_combo_value($filterPriority);
$filterValue        = clear_valid_combo_value($filterValue);
$filterInstallationType = clear_installation_type_normalize($filterInstallationType);
$filterFrom = clear_valid_iso_date($filterFrom);
$filterTo   = clear_valid_iso_date($filterTo);
$filterFromTime = clear_valid_hhmm_time($filterFromTime);
$filterToTime   = clear_valid_hhmm_time($filterToTime);
if ($filterFrom !== '' && $filterFromTime === '') $filterFromTime = '00:00';
if ($filterTo !== '' && $filterToTime === '') $filterToTime = '00:00';

// Si el usuario invierte el rango, lo normalizamos automáticamente.
$fromComparable = clear_effective_from_datetime($filterFrom, $filterFromTime);
$toComparable   = clear_effective_to_datetime($filterTo, $filterToTime);
if ($fromComparable !== null && $toComparable !== null && $fromComparable > $toComparable) {
    $tmpDate = $filterFrom;
    $tmpTime = $filterFromTime;
    $filterFrom = $filterTo;
    $filterFromTime = $filterToTime;
    $filterTo = $tmpDate;
    $filterToTime = $tmpTime;
}

/* --- Construir consulta segura --- */
$rows = [];
$total = 0;
$zones = [];
$zoneBatteries = [];
$installations = [];
$statuses = [];
$priorities = [];
$installationTypeOptions = clear_installation_type_options();
$installationTypeEnabled = false;
$installationTypeExpr = '';
$installationTypeExternalField = '';
$columnFilters = [];
$columnTypes = [];
$statusField = '';
$showCfnStatus = false;
$priorityField = '';
$dbError = '';
$reconCommentCounts = [];
$showAlarmas24Summary = false;
$alarmas24Summary = [
    'total_alarms' => 0,
    'tag_count' => 0,
    'top_tag' => '',
    'top_tag_count' => 0,
    'values' => ['ALARMA' => 0, 'NORMAL' => 0, 'FALLA' => 0],
    'statuses' => ['OK' => 0, 'HI' => 0, 'LO' => 0, 'CFN' => 0],
    'priorities' => ['LOW' => 0, 'CRITICAL' => 0, 'INFO' => 0],
];
$showReconocidasSummary = false;
$reconSummary = [
    'operator_count' => 0,
    'priority_count' => 0,
    'top_tag' => '',
    'top_tag_count' => 0,
];
$showTop20Summary = false;
$top20Summary = [
    'total_alarms' => 0,
    'top_tag' => '',
    'top_tag_alarms' => 0,
    'top_tag_percent' => 0,
    'top_installation' => '',
    'top_installation_alarms' => 0,
    'top_installation_percent' => 0,
    'top5_alarms' => 0,
    'top5_percent' => 0,
];
$showWeeklyHmlSummary = false;
$weeklyHmlSummary = [
    'start_date' => '',
    'end_date' => '',
    'days' => 0,
    'total' => 0,
    'high' => 0,
    'medium' => 0,
    'low' => 0,
    'high_percent' => 0,
    'medium_percent' => 0,
    'low_percent' => 0,
    'dominant_label' => '',
    'dominant_value' => 0,
    'chart_labels' => [],
    'chart_high' => [],
    'chart_medium' => [],
    'chart_low' => [],
    'chart_total' => [],
];
$top24Charts = [
    'labels' => [],
    'values' => [],
    'type_labels' => [],
    'type_values' => [],
];
$top24CommentsEnabled = false;
$top24CommentTimestampField = '';
$top24CommentKeys = [];
$alarmCommentKeys = [];

if ($sc) {
    $db = clear_db();
    if (!$db->ok()) {
        $dbError = $db->error();
    } else {
        $table = $sc['tabla'];
        $showCfnStatus = clear_alarm_cfn_show_status($db);
        if (!$showCfnStatus && strtoupper($filterStatus) === 'CFN') $filterStatus = '';
        $zones = clear_zones_all($db);
        $filterZone = clear_zone_valid($filterZone, $zones);
        $zoneBatteries = clear_zone_batteries($db, $filterZone);
        $performanceCacheAvailable = clear_performance_cache_load($db) !== null;

        // Lista blanca de columnas (evita inyección en ORDER BY / WHERE)
        $validCols = array_map(function ($c) { return $c[0]; }, $sc['cols']);
        /* Los comentarios ya no dependen de una fecha disponible en la vista:
           todas las pantallas con TAG usan el comentario central del TAG. */
        foreach ($sc['cols'] as $columnConfig) {
            $field = (string)($columnConfig[0] ?? '');
            $columnTypes[$field] = (string)($columnConfig[2] ?? 'text');
        }
        if (!empty($sc['standard_filters'])) {
            foreach ($columnFiltersRaw as $field => $value) {
                if (!is_string($field) || !in_array($field, $validCols, true) || is_array($value)) continue;
                $cleanValue = clear_valid_combo_value((string)$value);
                if ($cleanValue !== '') $columnFilters[$field] = $cleanValue;
            }
        }

        // Detectar automáticamente las columnas Estado y Prioridad de cada pantalla.
        // También admite estado_col / prioridad_col explícitos en screens.php.
        $statusField = $sc['estado_col'] ?? '';
        $priorityField = $sc['prioridad_col'] ?? '';
        foreach ($sc['cols'] as $columnConfig) {
            $columnField = $columnConfig[0] ?? '';
            $columnLabel = trim((string)($columnConfig[1] ?? ''));
            $columnType  = $columnConfig[2] ?? '';
            if ($statusField === '' && ($columnType === 'status' || strcasecmp($columnLabel, 'Estado') === 0)) {
                $statusField = $columnField;
            }
            if ($priorityField === '' && ($columnType === 'prio' || strcasecmp($columnLabel, 'Prioridad') === 0)) {
                $priorityField = $columnField;
            }
        }
        if (!in_array($statusField, $validCols, true)) $statusField = '';
        if (!in_array($priorityField, $validCols, true)) $priorityField = '';
        $valueField = $sc['valor_col'] ?? '';
        if ($valueField === '') {
            foreach ($sc['cols'] as $columnConfig) {
                $columnField = $columnConfig[0] ?? '';
                $columnLabel = trim((string)($columnConfig[1] ?? ''));
                if (strcasecmp($columnLabel, 'Valor') === 0) {
                    $valueField = $columnField;
                    break;
                }
            }
        }
        if (!in_array($valueField, $validCols, true)) $valueField = '';

        // Cargar valores únicos para los combos Estado y Prioridad.
        if ($statusField !== '' && $key === 'alarmas24h' && $performanceCacheAvailable) {
            foreach (clear_performance_cache_metric('FILTRO_ESTADO', $db) as $statusRow) {
                $value = trim((string)($statusRow['DIMENSION1'] ?? ''));
                if ($value !== '' && ($showCfnStatus || strtoupper($value) !== 'CFN')) $statuses[] = $value;
            }
        } elseif ($statusField !== '') {
            $normalizedStatusExpr = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$statusField])))";
            $statusRows = $db->all(
                "SELECT DISTINCT $normalizedStatusExpr AS valor_filtro " .
                "FROM $table WHERE [$statusField] IS NOT NULL AND $normalizedStatusExpr <> '' " .
                "ORDER BY valor_filtro"
            );
            foreach ($statusRows as $statusRow) {
                $raw = $statusRow['valor_filtro'] ?? ($statusRow['VALOR_FILTRO'] ?? reset($statusRow));
                $value = trim((string)$raw);
                if ($value !== '' && ($showCfnStatus || strtoupper($value) !== 'CFN')) $statuses[] = $value;
            }
        }
        if ($priorityField !== '' && $key === 'alarmas24h' && $performanceCacheAvailable) {
            foreach (clear_performance_cache_metric('FILTRO_PRIORIDAD', $db) as $priorityRow) {
                $value = trim((string)($priorityRow['DIMENSION1'] ?? ''));
                if ($value !== '') $priorities[] = $value;
            }
        } elseif ($priorityField !== '') {
            $normalizedPriorityExpr = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$priorityField])))";
            $priorityRows = $db->all(
                "SELECT DISTINCT $normalizedPriorityExpr AS valor_filtro " .
                "FROM $table WHERE [$priorityField] IS NOT NULL AND $normalizedPriorityExpr <> '' " .
                "ORDER BY valor_filtro"
            );
            foreach ($priorityRows as $priorityRow) {
                $raw = $priorityRow['valor_filtro'] ?? ($priorityRow['VALOR_FILTRO'] ?? reset($priorityRow));
                $value = trim((string)$raw);
                if ($value !== '') $priorities[] = $value;
            }
        }

        $dateField = $sc['fecha_col'] ?? '';

        // El filtro Instalación usa el texto anterior al primer guion bajo del TAG.
        $tagField = $sc['tag_col'] ?? '';
        $installationExpr = '';
        if ($tagField !== '' && in_array($tagField, $validCols, true)) {
            $normalizedTagExpr = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$tagField])))";
            $installationExpr = "LEFT($normalizedTagExpr, CHARINDEX('_', $normalizedTagExpr + '_') - 1)";

            $installationTypeEnabled = !empty($sc['installation_type']);
            if ($installationTypeEnabled) {
                $configuredExternal = trim((string)($sc['installation_type_external_col'] ?? ''));
                if ($configuredExternal !== '' && preg_match('/^[A-Za-z0-9_]+$/', $configuredExternal) && clear_installation_type_has_column($db, $table, $configuredExternal)) {
                    $installationTypeExternalField = $configuredExternal;
                }
                $externalTypeExpr = $installationTypeExternalField !== '' ? '[' . $installationTypeExternalField . ']' : "N''";
                $installationTypeExpr = clear_installation_type_sql('[' . $tagField . ']', $externalTypeExpr);
            }

            $installationZoneSql = '';
            $installationZoneParams = [];
            if ($filterZone !== '') {
                $installationZoneSql = " AND EXISTS (SELECT 1 FROM [CLEAR].[ZONAS] Z WHERE LTRIM(RTRIM(Z.ZONA)) COLLATE DATABASE_DEFAULT = ? COLLATE DATABASE_DEFAULT AND LTRIM(RTRIM(Z.BATERIA)) COLLATE DATABASE_DEFAULT = $installationExpr COLLATE DATABASE_DEFAULT)";
                $installationZoneParams[] = $filterZone;
            }
            if ($key === 'alarmas24h' && $performanceCacheAvailable && $filterZone === '') {
                $installationRows = [];
                foreach (clear_performance_cache_metric('FILTRO_INSTALACION', $db) as $cachedInstallation) {
                    $installationRows[] = ['instalacion' => $cachedInstallation['DIMENSION1'] ?? ''];
                }
            } else {
                $installationRows = $db->all(
                    "SELECT DISTINCT $installationExpr AS instalacion " .
                    "FROM $table " .
                    "WHERE [$tagField] IS NOT NULL AND $normalizedTagExpr <> '' $installationZoneSql " .
                    "ORDER BY instalacion",
                    $installationZoneParams
                );
            }
            foreach ($installationRows as $installationRow) {
                $installationRaw = $installationRow['instalacion'] ?? ($installationRow['INSTALACION'] ?? reset($installationRow));
                $installationValue = trim((string)$installationRaw);
                if ($installationValue !== '') $installations[] = $installationValue;
            }
        }

        // Generador reutilizable de filtros. Permite calcular facetas ignorando
        // únicamente el filtro de su propio grupo (Valor, Estado o Prioridad).
        $buildFilterSql = function ($includeValue = true, $includeStatus = true, $includePriority = true) use (
            $q, $sc, $filterZone, $filterInstallation, $installationExpr, $filterValue, $valueField,
            $filterStatus, $statusField, $filterPriority, $priorityField,
            $dateField, $filterFrom, $filterFromTime, $filterTo, $filterToTime,
            $filterInstallationType, $installationTypeExpr, $columnFilters, $alarmScope
        ) {
            $localConditions = [];
            $localParams = [];

            if ($q !== '' && !empty($sc['buscar'])) {
                $likes = [];
                foreach ($sc['buscar'] as $col) {
                    $likes[] = "[$col] LIKE ?";
                    $localParams[] = '%' . $q . '%';
                }
                if ($likes) $localConditions[] = '(' . implode(' OR ', $likes) . ')';
            }

            if ($filterZone !== '' && $installationExpr !== '') {
                $localConditions[] = "EXISTS (SELECT 1 FROM [CLEAR].[ZONAS] Z WHERE LTRIM(RTRIM(Z.ZONA)) COLLATE DATABASE_DEFAULT = ? COLLATE DATABASE_DEFAULT AND LTRIM(RTRIM(Z.BATERIA)) COLLATE DATABASE_DEFAULT = $installationExpr COLLATE DATABASE_DEFAULT)";
                $localParams[] = $filterZone;
            }

            if ($filterInstallation !== '' && $installationExpr !== '') {
                $localConditions[] = "$installationExpr = ?";
                $localParams[] = $filterInstallation;
            }

            if ($alarmScope !== '' && $installationTypeExpr !== '') {
                if ($alarmScope === 'pozos') {
                    $localConditions[] = "$installationTypeExpr = N'POZO'";
                } else {
                    $localConditions[] = "$installationTypeExpr <> N'POZO'";
                }
            }

            if ($filterInstallationType !== '' && $installationTypeExpr !== '') {
                $localConditions[] = "$installationTypeExpr = ?";
                $localParams[] = $filterInstallationType;
            }

            foreach ($columnFilters as $columnField => $columnValue) {
                $localConditions[] = "LTRIM(RTRIM(CONVERT(nvarchar(1000), [$columnField]))) LIKE ?";
                $localParams[] = '%' . $columnValue . '%';
            }

            if ($includeValue && $filterValue !== '' && $valueField !== '') {
                $localConditions[] = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$valueField])))) = ?";
                $localParams[] = strtoupper($filterValue);
            }

            if ($includeStatus && $filterStatus !== '' && $statusField !== '') {
                $localConditions[] = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$statusField])))) = ?";
                $localParams[] = strtoupper($filterStatus);
            }

            if ($includePriority && $filterPriority !== '' && $priorityField !== '') {
                $normalizedPriorityFilter = strtoupper($filterPriority);
                if ($normalizedPriorityFilter === 'LOW') {
                    $localConditions[] = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$priorityField])))) IN ('LOW','BAJA')";
                } elseif ($normalizedPriorityFilter === 'CRITICAL') {
                    $localConditions[] = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$priorityField])))) IN ('CRITICAL','CRITICA','CRÍTICA')";
                } else {
                    $localConditions[] = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$priorityField])))) = ?";
                    $localParams[] = $normalizedPriorityFilter;
                }
            }

            if ($dateField !== '') {
                if ($filterFrom !== '') {
                    $localConditions[] = "[$dateField] >= ?";
                    $localParams[] = $filterFrom . ' ' . ($filterFromTime !== '' ? $filterFromTime : '00:00') . ':00';
                }
                if ($filterTo !== '') {
                    $localConditions[] = "[$dateField] < DATEADD(minute, 1, ?)";
                    $localParams[] = $filterTo . ' ' . ($filterToTime !== '' ? $filterToTime : '00:00') . ':00';
                }
            }

            return [
                'conditions' => $localConditions,
                'params' => $localParams,
                'where' => $localConditions ? 'WHERE ' . implode(' AND ', $localConditions) : '',
            ];
        };

        $mainFilterSql = $buildFilterSql(true, true, true);
        $conditions = $mainFilterSql['conditions'];
        $params = $mainFilterSql['params'];
        $where = $mainFilterSql['where'];
        $useAlarmas24CacheSummary = $key === 'alarmas24h' && $performanceCacheAvailable && $alarmScope === '' &&
            $q === '' && $filterZone === '' && $filterInstallation === '' && $filterStatus === '' &&
            $filterPriority === '' && $filterValue === '' && $filterInstallationType === '' && !$columnFilters && $filterFrom === '' && $filterTo === '';

        // ORDER BY
        if ($sortCol === '__TIPO_INSTALACION' && $installationTypeExpr !== '') {
            $orderBy = "$installationTypeExpr $sortDir";
        } elseif ($sortCol !== '' && in_array($sortCol, $validCols, true)) {
            $orderBy = "[$sortCol] $sortDir";
        } else {
            $orderBy = $sc['orderby'];
        }

        // La pantalla Top semanal se presenta solo con indicadores, sin grilla.
        if ($key !== 'top_hml') {
            // Total
            $total = $useAlarmas24CacheSummary
                ? (int)clear_performance_cache_kpi('TOTAL24H', 0, $db)
                : (int)$db->scalar("SELECT COUNT(*) AS n FROM $table $where", $params);

            // Página de datos (SQL Server: OFFSET/FETCH, requiere ORDER BY)
            $offset = ($page - 1) * $perPage;
            // Consultar únicamente las columnas utilizadas por la pantalla.
            // Evita transferir toda la fila de FIXALARMS cuando la grilla muestra pocos campos.
            $selectFields = $validCols;
            if (in_array($key, ['reconocidas_usr', 'reconocidas'], true)) {
                foreach (['TAG_FIX', 'OPERADOR', 'ALM_NATIVETIMEIN'] as $dependencyField) {
                    if (!in_array($dependencyField, $selectFields, true)) $selectFields[] = $dependencyField;
                }
            }
            if ($top24CommentsEnabled && !in_array($top24CommentTimestampField, $selectFields, true)) {
                $selectFields[] = $top24CommentTimestampField;
            }
            $selectFields = array_values(array_unique(array_filter($selectFields, static function ($field) {
                return preg_match('/^[A-Za-z0-9_]+$/', (string)$field);
            })));
            $selectSql = implode(', ', array_map(static function ($field) {
                return '[' . str_replace(']', ']]', $field) . ']';
            }, $selectFields));
            if ($selectSql === '') $selectSql = '*';
            if ($installationTypeExpr !== '') $selectSql = "$installationTypeExpr AS [__TIPO_INSTALACION], $selectSql";
            if ($showAllRows) {
                $sql = "SELECT $selectSql FROM $table $where ORDER BY $orderBy";
            } else {
                $sql = "SELECT $selectSql FROM $table $where ORDER BY $orderBy "
                     . "OFFSET $offset ROWS FETCH NEXT $perPage ROWS ONLY";
            }
            $rows = $db->all($sql, $params);

            /* Un solo acceso acotado para marcar comentarios centrales (o el
               último comentario histórico como compatibilidad) de los TAG visibles. */
            if (($CAN_VIEW_COMMENTS || $CAN_CREATE_COMMENTS) && $tagField !== '' && !empty($rows) && (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.FIXALARMS_COMENTARIOS',N'U') IS NULL THEN 0 ELSE 1 END") === 1) {
                $visibleTags = [];
                foreach ($rows as $visibleRow) {
                    $visibleTag = trim((string)($visibleRow[$tagField] ?? ''));
                    if ($visibleTag !== '') $visibleTags[strtoupper($visibleTag)] = $visibleTag;
                }
                if (!empty($visibleTags)) {
                    $alarmCommentKeys = clear_alarm_comments_load_subjects_sql(array_values($visibleTags));
                }
            }
        }

        // Los gráficos de Top 20 24h se calculan sobre la tabla caché ya
        // agregada. Es una lectura acotada y evita consultar FIXALARMS.
        if (!empty($sc['installation_type_charts']) && $installationTypeExpr !== '') {
            $metricTagField = $sc['tag_col'] ?? '';
            $metricTotalField = 'TOTAL_ALARMAS';
            if (in_array($metricTagField, $validCols, true) && in_array($metricTotalField, $validCols, true)) {
                $chartRows = $db->all(
                    "SELECT [$metricTagField] AS chart_tag, TRY_CONVERT(bigint,[$metricTotalField]) AS chart_total, " .
                    "$installationTypeExpr AS chart_type FROM $table $where",
                    $params
                );
                $tagTotals = [];
                $typeTotals = [];
                foreach ($chartRows as $chartRow) {
                    $chartTag = trim((string)($chartRow['chart_tag'] ?? $chartRow['CHART_TAG'] ?? ''));
                    $chartType = trim((string)($chartRow['chart_type'] ?? $chartRow['CHART_TYPE'] ?? 'SIN CLASIFICAR'));
                    $chartTotal = (int)($chartRow['chart_total'] ?? $chartRow['CHART_TOTAL'] ?? 0);
                    if ($chartTag !== '') $tagTotals[$chartTag] = ($tagTotals[$chartTag] ?? 0) + $chartTotal;
                    if ($chartType === '') $chartType = 'SIN CLASIFICAR';
                    $typeTotals[$chartType] = ($typeTotals[$chartType] ?? 0) + $chartTotal;
                }
                arsort($tagTotals);
                arsort($typeTotals);
                $tagTotals = array_slice($tagTotals, 0, 10, true);
                $top24Charts['labels'] = array_keys($tagTotals);
                $top24Charts['values'] = array_values($tagTotals);
                $top24Charts['type_labels'] = array_keys($typeTotals);
                $top24Charts['type_values'] = array_values($typeTotals);
            }
        }

        // Indicadores y filtros rápidos para Alarmas 24h.
        if ($key === 'alarmas24h') {
            $showAlarmas24Summary = true;
            $alarmas24Summary['total_alarms'] = (int)$total;

            if ($useAlarmas24CacheSummary) {
                $alarmas24Summary['tag_count'] = (int)clear_performance_cache_kpi('TAGS_UNICOS24H', 0, $db);
                $cachedTop = clear_performance_cache_metric('TOP_ALARMAS', $db);
                if (!empty($cachedTop)) {
                    $alarmas24Summary['top_tag'] = trim((string)($cachedTop[0]['DIMENSION1'] ?? ''));
                    $alarmas24Summary['top_tag_count'] = (int)($cachedTop[0]['VALOR1'] ?? 0);
                }
                foreach (clear_performance_cache_metric('VALORES', $db) as $cachedRow) {
                    $cachedKey = strtoupper(trim((string)($cachedRow['DIMENSION1'] ?? '')));
                    if (array_key_exists($cachedKey, $alarmas24Summary['values'])) $alarmas24Summary['values'][$cachedKey] = (int)($cachedRow['VALOR1'] ?? 0);
                }
                foreach (clear_performance_cache_metric('ESTADOS', $db) as $cachedRow) {
                    $cachedKey = strtoupper(trim((string)($cachedRow['DIMENSION1'] ?? '')));
                    if (array_key_exists($cachedKey, $alarmas24Summary['statuses'])) $alarmas24Summary['statuses'][$cachedKey] = (int)($cachedRow['VALOR1'] ?? 0);
                }
                foreach (clear_performance_cache_metric('PRIORIDADES', $db) as $cachedRow) {
                    $cachedKey = strtoupper(trim((string)($cachedRow['DIMENSION1'] ?? '')));
                    if (array_key_exists($cachedKey, $alarmas24Summary['priorities'])) $alarmas24Summary['priorities'][$cachedKey] = (int)($cachedRow['VALOR1'] ?? 0);
                }
            }

            if (!$useAlarmas24CacheSummary && $tagField !== '' && in_array($tagField, $validCols, true)) {
                $summaryTagExpr = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$tagField])))";
                $tagCountWhere = $where !== ''
                    ? $where . " AND [$tagField] IS NOT NULL AND $summaryTagExpr <> ''"
                    : "WHERE [$tagField] IS NOT NULL AND $summaryTagExpr <> ''";
                $alarmas24Summary['tag_count'] = (int)$db->scalar(
                    "SELECT COUNT(*) AS n FROM $table $tagCountWhere",
                    $params
                );

                $topTagRows = $db->all(
                    "SELECT TOP 1 $summaryTagExpr AS top_tag, COUNT(*) AS repeticiones " .
                    "FROM $table $tagCountWhere GROUP BY $summaryTagExpr " .
                    "ORDER BY COUNT(*) DESC, $summaryTagExpr ASC",
                    $params
                );
                if (!empty($topTagRows)) {
                    $topTagRow = $topTagRows[0];
                    $topTagValues = array_values($topTagRow);
                    $alarmas24Summary['top_tag'] = trim((string)($topTagRow['top_tag'] ?? $topTagRow['TOP_TAG'] ?? ($topTagValues[0] ?? '')));
                    $alarmas24Summary['top_tag_count'] = (int)($topTagRow['repeticiones'] ?? $topTagRow['REPETICIONES'] ?? ($topTagValues[1] ?? 0));
                }
            }

            if (!$useAlarmas24CacheSummary && $valueField !== '') {
                $valueFacet = $buildFilterSql(false, true, true);
                $valueExpr = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$valueField]))))";
                $valueWhere = $valueFacet['where'] !== '' ? $valueFacet['where'] . " AND $valueExpr IN ('ALARMA','NORMAL','FALLA')" : "WHERE $valueExpr IN ('ALARMA','NORMAL','FALLA')";
                $valueRows = $db->all(
                    "SELECT $valueExpr AS facet_value, COUNT(*) AS facet_count FROM $table $valueWhere GROUP BY $valueExpr",
                    $valueFacet['params']
                );
                foreach ($valueRows as $valueRow) {
                    $rowValues = array_values($valueRow);
                    $facetValue = strtoupper(trim((string)($valueRow['facet_value'] ?? $valueRow['FACET_VALUE'] ?? ($rowValues[0] ?? ''))));
                    $facetCount = (int)($valueRow['facet_count'] ?? $valueRow['FACET_COUNT'] ?? ($rowValues[1] ?? 0));
                    if (array_key_exists($facetValue, $alarmas24Summary['values'])) $alarmas24Summary['values'][$facetValue] = $facetCount;
                }
            }

            if (!$useAlarmas24CacheSummary && $statusField !== '') {
                $statusFacet = $buildFilterSql(true, false, true);
                $statusExpr = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$statusField]))))";
                $allowedStatusSql = $showCfnStatus ? "('OK','HI','LO','CFN')" : "('OK','HI','LO')";
                $statusWhere = $statusFacet['where'] !== '' ? $statusFacet['where'] . " AND $statusExpr IN $allowedStatusSql" : "WHERE $statusExpr IN $allowedStatusSql";
                $statusRowsSummary = $db->all(
                    "SELECT $statusExpr AS facet_value, COUNT(*) AS facet_count FROM $table $statusWhere GROUP BY $statusExpr",
                    $statusFacet['params']
                );
                foreach ($statusRowsSummary as $statusRowSummary) {
                    $rowValues = array_values($statusRowSummary);
                    $facetValue = strtoupper(trim((string)($statusRowSummary['facet_value'] ?? $statusRowSummary['FACET_VALUE'] ?? ($rowValues[0] ?? ''))));
                    $facetCount = (int)($statusRowSummary['facet_count'] ?? $statusRowSummary['FACET_COUNT'] ?? ($rowValues[1] ?? 0));
                    if (array_key_exists($facetValue, $alarmas24Summary['statuses'])) $alarmas24Summary['statuses'][$facetValue] = $facetCount;
                }
            }

            if (!$useAlarmas24CacheSummary && $priorityField !== '') {
                $priorityFacet = $buildFilterSql(true, true, false);
                $priorityExpr = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$priorityField]))))";
                $priorityWhere = $priorityFacet['where'] !== ''
                    ? $priorityFacet['where'] . " AND $priorityExpr IN ('LOW','BAJA','CRITICAL','CRITICA','CRÍTICA','INFO')"
                    : "WHERE $priorityExpr IN ('LOW','BAJA','CRITICAL','CRITICA','CRÍTICA','INFO')";
                $priorityRowsSummary = $db->all(
                    "SELECT CASE " .
                    "WHEN $priorityExpr IN ('LOW','BAJA') THEN 'LOW' " .
                    "WHEN $priorityExpr IN ('CRITICAL','CRITICA','CRÍTICA') THEN 'CRITICAL' " .
                    "ELSE $priorityExpr END AS facet_value, COUNT(*) AS facet_count " .
                    "FROM $table $priorityWhere GROUP BY CASE " .
                    "WHEN $priorityExpr IN ('LOW','BAJA') THEN 'LOW' " .
                    "WHEN $priorityExpr IN ('CRITICAL','CRITICA','CRÍTICA') THEN 'CRITICAL' " .
                    "ELSE $priorityExpr END",
                    $priorityFacet['params']
                );
                foreach ($priorityRowsSummary as $priorityRowSummary) {
                    $rowValues = array_values($priorityRowSummary);
                    $facetValue = strtoupper(trim((string)($priorityRowSummary['facet_value'] ?? $priorityRowSummary['FACET_VALUE'] ?? ($rowValues[0] ?? ''))));
                    $facetCount = (int)($priorityRowSummary['facet_count'] ?? $priorityRowSummary['FACET_COUNT'] ?? ($rowValues[1] ?? 0));
                    if (array_key_exists($facetValue, $alarmas24Summary['priorities'])) $alarmas24Summary['priorities'][$facetValue] = $facetCount;
                }
            }
        }

        // Indicadores específicos para la pantalla Top 20 alarmas.
        if ($key === 'top20_all') {
            $showTop20Summary = true;
            $totalField = 'TOTAL_ALARMAS';
            $metricTagField = $tagField !== '' ? $tagField : 'ALM_TAGNAME';
            $numericTotalExpr = "COALESCE(TRY_CONVERT(decimal(38, 2), [$totalField]), 0)";

            if (in_array($totalField, $validCols, true)) {
                $top20Summary['total_alarms'] = (float)$db->scalar(
                    "SELECT COALESCE(SUM($numericTotalExpr), 0) AS total_alarmas FROM $table $where",
                    $params
                );

                if ($metricTagField !== '' && in_array($metricTagField, $validCols, true)) {
                    $topTagRows = $db->all(
                        "SELECT TOP 1 LTRIM(RTRIM(CONVERT(nvarchar(255), [$metricTagField]))) AS top_tag, " .
                        "$numericTotalExpr AS total_tag FROM $table $where " .
                        "ORDER BY $numericTotalExpr DESC, [$metricTagField] ASC",
                        $params
                    );
                    if (!empty($topTagRows)) {
                        $topTagRow = $topTagRows[0];
                        $topTagValues = array_values($topTagRow);
                        $top20Summary['top_tag'] = trim((string)($topTagRow['top_tag'] ?? $topTagRow['TOP_TAG'] ?? ($topTagValues[0] ?? '')));
                        $top20Summary['top_tag_alarms'] = (float)($topTagRow['total_tag'] ?? $topTagRow['TOTAL_TAG'] ?? ($topTagValues[1] ?? 0));
                    }

                    $installationMetricExpr = "LEFT(LTRIM(RTRIM(CONVERT(nvarchar(255), [$metricTagField]))), " .
                        "CHARINDEX('_', LTRIM(RTRIM(CONVERT(nvarchar(255), [$metricTagField]))) + '_') - 1)";
                    $topInstallationRows = $db->all(
                        "SELECT TOP 1 $installationMetricExpr AS instalacion, " .
                        "COALESCE(SUM($numericTotalExpr), 0) AS total_instalacion " .
                        "FROM $table $where GROUP BY $installationMetricExpr " .
                        "ORDER BY COALESCE(SUM($numericTotalExpr), 0) DESC, $installationMetricExpr ASC",
                        $params
                    );
                    if (!empty($topInstallationRows)) {
                        $topInstallationRow = $topInstallationRows[0];
                        $topInstallationValues = array_values($topInstallationRow);
                        $top20Summary['top_installation'] = trim((string)($topInstallationRow['instalacion'] ?? $topInstallationRow['INSTALACION'] ?? ($topInstallationValues[0] ?? '')));
                        $top20Summary['top_installation_alarms'] = (float)($topInstallationRow['total_instalacion'] ?? $topInstallationRow['TOTAL_INSTALACION'] ?? ($topInstallationValues[1] ?? 0));
                    }
                }

                $top20Summary['top5_alarms'] = (float)$db->scalar(
                    "SELECT COALESCE(SUM(top_value), 0) AS total_top5 FROM (" .
                    "SELECT TOP 5 $numericTotalExpr AS top_value FROM $table $where " .
                    "ORDER BY $numericTotalExpr DESC" .
                    ") AS top_five",
                    $params
                );

                if ($top20Summary['total_alarms'] > 0) {
                    $top20Summary['top_tag_percent'] = ($top20Summary['top_tag_alarms'] / $top20Summary['total_alarms']) * 100;
                    $top20Summary['top_installation_percent'] = ($top20Summary['top_installation_alarms'] / $top20Summary['total_alarms']) * 100;
                    $top20Summary['top5_percent'] = ($top20Summary['top5_alarms'] / $top20Summary['total_alarms']) * 100;
                }
            }
        }

        // Resumen semanal de criticidad: últimos 7 días disponibles en la tabla.
        if ($key === 'top_hml') {
            $showWeeklyHmlSummary = true;
            $weeklyMaxDate = $db->scalar(
                "SELECT MAX(TRY_CONVERT(date, [Fecha])) AS fecha_maxima FROM $table"
            );

            if ($weeklyMaxDate) {
                $weeklyEndTs = strtotime((string)$weeklyMaxDate);
                if ($weeklyEndTs !== false) {
                    $weeklyStartTs = strtotime('-6 days', $weeklyEndTs);
                    $weeklyStart = date('Y-m-d', $weeklyStartTs);
                    $weeklyEnd = date('Y-m-d', $weeklyEndTs);
                    $weeklyRows = $db->all(
                        "SELECT " .
                        "COUNT(DISTINCT TRY_CONVERT(date, [Fecha])) AS dias, " .
                        "COALESCE(SUM(COALESCE(TRY_CONVERT(decimal(38,2), [PrioridadAlta]), 0)), 0) AS total_alta, " .
                        "COALESCE(SUM(COALESCE(TRY_CONVERT(decimal(38,2), [PrioridadMedia]), 0)), 0) AS total_media, " .
                        "COALESCE(SUM(COALESCE(TRY_CONVERT(decimal(38,2), [PrioridadBaja]), 0)), 0) AS total_baja " .
                        "FROM $table WHERE TRY_CONVERT(date, [Fecha]) >= ? AND TRY_CONVERT(date, [Fecha]) <= ?",
                        [$weeklyStart, $weeklyEnd]
                    );

                    if (!empty($weeklyRows)) {
                        $weeklyRow = $weeklyRows[0];
                        $weeklyValues = array_values($weeklyRow);
                        $weeklyHmlSummary['days'] = (int)($weeklyRow['dias'] ?? $weeklyRow['DIAS'] ?? ($weeklyValues[0] ?? 0));
                        $weeklyHmlSummary['high'] = (float)($weeklyRow['total_alta'] ?? $weeklyRow['TOTAL_ALTA'] ?? ($weeklyValues[1] ?? 0));
                        $weeklyHmlSummary['medium'] = (float)($weeklyRow['total_media'] ?? $weeklyRow['TOTAL_MEDIA'] ?? ($weeklyValues[2] ?? 0));
                        $weeklyHmlSummary['low'] = (float)($weeklyRow['total_baja'] ?? $weeklyRow['TOTAL_BAJA'] ?? ($weeklyValues[3] ?? 0));
                    }

                    // Serie diaria de los 7 días, incluyendo días sin registros con valor cero.
                    $weeklyDailyRows = $db->all(
                        "SELECT " .
                        "CONVERT(varchar(10), TRY_CONVERT(date, [Fecha]), 23) AS fecha_dia, " .
                        "COALESCE(SUM(COALESCE(TRY_CONVERT(decimal(38,2), [PrioridadAlta]), 0)), 0) AS total_alta, " .
                        "COALESCE(SUM(COALESCE(TRY_CONVERT(decimal(38,2), [PrioridadMedia]), 0)), 0) AS total_media, " .
                        "COALESCE(SUM(COALESCE(TRY_CONVERT(decimal(38,2), [PrioridadBaja]), 0)), 0) AS total_baja " .
                        "FROM $table WHERE TRY_CONVERT(date, [Fecha]) >= ? AND TRY_CONVERT(date, [Fecha]) <= ? " .
                        "GROUP BY TRY_CONVERT(date, [Fecha]) ORDER BY TRY_CONVERT(date, [Fecha])",
                        [$weeklyStart, $weeklyEnd]
                    );

                    $weeklyDailyMap = [];
                    foreach ($weeklyDailyRows as $weeklyDailyRow) {
                        $dailyValues = array_values($weeklyDailyRow);
                        $dailyDate = (string)($weeklyDailyRow['fecha_dia'] ?? $weeklyDailyRow['FECHA_DIA'] ?? ($dailyValues[0] ?? ''));
                        if ($dailyDate === '') continue;
                        $dailyHigh = (float)($weeklyDailyRow['total_alta'] ?? $weeklyDailyRow['TOTAL_ALTA'] ?? ($dailyValues[1] ?? 0));
                        $dailyMedium = (float)($weeklyDailyRow['total_media'] ?? $weeklyDailyRow['TOTAL_MEDIA'] ?? ($dailyValues[2] ?? 0));
                        $dailyLow = (float)($weeklyDailyRow['total_baja'] ?? $weeklyDailyRow['TOTAL_BAJA'] ?? ($dailyValues[3] ?? 0));
                        $weeklyDailyMap[$dailyDate] = [
                            'high' => $dailyHigh,
                            'medium' => $dailyMedium,
                            'low' => $dailyLow,
                            'total' => $dailyHigh + $dailyMedium + $dailyLow,
                        ];
                    }

                    for ($weeklyDayIndex = 0; $weeklyDayIndex < 7; $weeklyDayIndex++) {
                        $weeklyDayTs = strtotime('+' . $weeklyDayIndex . ' days', $weeklyStartTs);
                        $weeklyDayKey = date('Y-m-d', $weeklyDayTs);
                        $weeklyDayData = $weeklyDailyMap[$weeklyDayKey] ?? ['high' => 0, 'medium' => 0, 'low' => 0, 'total' => 0];
                        $weeklyHmlSummary['chart_labels'][] = date('d/m', $weeklyDayTs);
                        $weeklyHmlSummary['chart_high'][] = $weeklyDayData['high'];
                        $weeklyHmlSummary['chart_medium'][] = $weeklyDayData['medium'];
                        $weeklyHmlSummary['chart_low'][] = $weeklyDayData['low'];
                        $weeklyHmlSummary['chart_total'][] = $weeklyDayData['total'];
                    }

                    $weeklyHmlSummary['start_date'] = $weeklyStart;
                    $weeklyHmlSummary['end_date'] = $weeklyEnd;
                    $weeklyHmlSummary['total'] = $weeklyHmlSummary['high'] + $weeklyHmlSummary['medium'] + $weeklyHmlSummary['low'];
                    if ($weeklyHmlSummary['total'] > 0) {
                        $weeklyHmlSummary['high_percent'] = ($weeklyHmlSummary['high'] / $weeklyHmlSummary['total']) * 100;
                        $weeklyHmlSummary['medium_percent'] = ($weeklyHmlSummary['medium'] / $weeklyHmlSummary['total']) * 100;
                        $weeklyHmlSummary['low_percent'] = ($weeklyHmlSummary['low'] / $weeklyHmlSummary['total']) * 100;
                    }

                    $weeklyLevels = [
                        'Alta' => $weeklyHmlSummary['high'],
                        'Media' => $weeklyHmlSummary['medium'],
                        'Baja' => $weeklyHmlSummary['low'],
                    ];
                    arsort($weeklyLevels);
                    $weeklyHmlSummary['dominant_label'] = (string)key($weeklyLevels);
                    $weeklyHmlSummary['dominant_value'] = (float)current($weeklyLevels);
                }
            }
        }

        // Resumen específico para la pantalla de reconocidas.
        if ($key === 'reconocidas') {
            $showReconocidasSummary = true;
            $operatorField = 'OPERADOR';
            $summaryTagField = $tagField;
            $summaryPriorityField = $priorityField !== '' ? $priorityField : 'ALM_ALMPRIORITY';

            if (in_array($operatorField, $validCols, true)) {
                $operatorExpr = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$operatorField])))";
                $operatorWhere = $where !== ''
                    ? $where . " AND [$operatorField] IS NOT NULL AND $operatorExpr <> ''"
                    : "WHERE [$operatorField] IS NOT NULL AND $operatorExpr <> ''";
                $reconSummary['operator_count'] = (int)$db->scalar("SELECT COUNT(*) AS n FROM $table $operatorWhere", $params);
            }

            if ($summaryPriorityField !== '' && in_array($summaryPriorityField, $validCols, true)) {
                $priorityExprSummary = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$summaryPriorityField])))";
                $priorityWhere = $where !== ''
                    ? $where . " AND [$summaryPriorityField] IS NOT NULL AND $priorityExprSummary <> ''"
                    : "WHERE [$summaryPriorityField] IS NOT NULL AND $priorityExprSummary <> ''";
                $reconSummary['priority_count'] = (int)$db->scalar("SELECT COUNT(*) AS n FROM $table $priorityWhere", $params);
            }

            if ($summaryTagField !== '' && in_array($summaryTagField, $validCols, true)) {
                $topTagRows = $db->all(
                    "SELECT TOP 1 [$summaryTagField] AS top_tag, COUNT(*) AS repeticiones FROM $table $where " .
                    "GROUP BY [$summaryTagField] ORDER BY COUNT(*) DESC, [$summaryTagField] ASC",
                    $params
                );
                if (!empty($topTagRows)) {
                    $topTagRow = $topTagRows[0];
                    $topTagValues = array_values($topTagRow);
                    $reconSummary['top_tag'] = trim((string)($topTagRow['top_tag'] ?? $topTagRow['TOP_TAG'] ?? ($topTagValues[0] ?? '')));
                    $reconSummary['top_tag_count'] = (int)($topTagRow['repeticiones'] ?? $topTagRow['REPETICIONES'] ?? ($topTagValues[1] ?? 0));
                }
            }

        }
    }
}

if ($CAN_VIEW_COMMENTS && in_array($key, ['reconocidas_usr', 'reconocidas'], true)) {
    $reconCommentCounts = clear_recon_comment_counts_by_group();
}

$totalPages = $showAllRows ? 1 : max(1, (int)ceil($total / max(1,$perPage)));
$hasActiveFilters = ($q !== '' || $filterZone !== '' || $filterInstallation !== '' || $filterInstallationType !== '' || $filterStatus !== '' || $filterPriority !== '' || $filterValue !== '' || !empty($columnFilters) ||
    $filterFrom !== '' || $filterTo !== '' || $filterFromTime !== '' || $filterToTime !== '' || $sortCol !== '');

/* --- Helpers de formato de celdas --- */
function badge_prio($v) {
    $u = strtoupper(trim($v));
    if ($u === 'HIGH')                 return ['red',   'Alta'];
    if ($u === 'MED' || $u === 'MEDIUM') return ['amber', 'Media'];
    if ($u === 'LOW' || $u === 'BAJA') return ['cyan',  'Baja'];
    if (in_array($u, ['CRITICAL','CRITICA','CRÍTICA'], true)) return ['red', 'Crítica'];
    if ($u === 'INFO')                 return ['gray',  'Info'];
    return ['gray', $v !== '' ? $v : '—'];
}
function badge_status($v) {
    $u = strtoupper(trim($v));
    if (in_array($u, ['ALARMA','ALARM','HI','LO','HIHI','LOLO'], true)) return ['red',   $v];
    if (in_array($u, ['OK','NORMAL','RTN'], true))                           return ['green', $v];
    if ($u === 'PARADA' || $u === 'STOP')                                    return ['amber', $v];
    if ($u === 'CFN')                                                         return ['amber', 'CFN'];
    if ($u === '')                                                           return ['gray',  '—'];
    return ['gray', $v];
}

function clear_format_metric_number($value) {
    $number = (float)$value;
    $decimals = abs($number - round($number)) < 0.00001 ? 0 : 2;
    return number_format($number, $decimals, ',', '.');
}

function clear_format_metric_percent($value) {
    return number_format((float)$value, 1, ',', '.') . '%';
}

/* URL helper que conserva parámetros */
function url_with($params) {
    $base = $_GET;
    foreach ($params as $k => $v) { $base[$k] = $v; }
    return '?' . http_build_query($base);
}
function url_without($keys) {
    $base = $_GET;
    foreach ((array)$keys as $key) unset($base[$key]);
    return '?' . http_build_query($base);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $sc ? h($sc['titulo']) : 'No encontrado'; ?> · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260803-grid1">
  <link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260807-2">
  <link rel="stylesheet" href="assets/css/installation_type.css?v=20260824-1">
  <?php if($reportEnabled): ?><link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260826-select-all-1"><?php endif; ?>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <?php if (!$sc): ?>
      <div class="page__head"><div>
        <h1 class="page__title">Pantalla no encontrada</h1>
        <div class="page__sub">La vista solicitada no existe</div>
      </div></div>
      <div class="tablewrap"><div class="empty">
        <?php echo icon('file'); ?>
        <p>No hay una pantalla con ese identificador.</p>
        <p style="margin-top:10px"><a href="index.php" style="color:var(--cyan)">Volver al dashboard</a></p>
      </div></div>
    <?php else: ?>

      <div class="page__head">
        <div>
          <h1 class="page__title"><?php echo h($sc['titulo']); ?></h1>
          <div class="page__sub"><?php echo h($sc['subtitulo']); ?></div>
        </div>
        <div class="page__live">
          <span class="dot"></span>
          <?php echo $dbError ? 'Sin conexión' : 'En vivo'; ?>
        </div>
      </div>

      <?php if ($dbError): ?>
        <div class="tablewrap"><div class="empty">
          <?php echo icon('shield'); ?>
          <p><b style="color:var(--red)">Sin conexión a la base.</b></p>
          <p style="margin-top:8px;font-size:13px"><?php echo h($dbError); ?></p>
        </div></div>
      <?php else: ?>

        <!-- Toolbar: búsqueda + calendario + contador -->
        <form class="toolbar" method="get" action="list.php" id="tableFilters">
          <input type="hidden" name="s" value="<?php echo h($key); ?>">
          <?php if ($filterValue !== ''): ?>
            <input type="hidden" name="valor" value="<?php echo h($filterValue); ?>">
          <?php endif; ?>
          <?php if ($sortCol !== ''): ?>
            <input type="hidden" name="sort" value="<?php echo h($sortCol); ?>">
            <input type="hidden" name="dir" value="<?php echo h(strtolower($sortDir)); ?>">
          <?php endif; ?>
          <?php if ($key !== 'top_hml'): ?>
          <?php if (!empty($sc['buscar'])): ?>
          <div class="toolbar__searchWrap">
            <label class="toolbar__search" for="tableSearchInput">
              <?php echo icon('search'); ?>
              <input type="text" id="tableSearchInput" name="q" value="<?php echo h($q); ?>"
                     placeholder="Buscar en <?php echo h($sc['titulo']); ?>…" autocomplete="off"
                     <?php if (!empty($sc['tag_col'])): ?>data-tag-autocomplete="true" data-screen="<?php echo h($key); ?>"<?php endif; ?>>
            </label>
            <?php if (!empty($sc['tag_col'])): ?>
              <div class="tagAutocomplete" id="tagAutocomplete" role="listbox" aria-label="Sugerencias de tags" hidden></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($installationTypeEnabled && empty($sc['standard_filters'])): ?>
          <div class="toolbar__selectFilter <?php echo $filterInstallationType !== '' ? 'is-active' : ''; ?>">
            <span class="toolbar__selectIcon" aria-hidden="true"><?php echo icon('grid'); ?></span>
            <label class="toolbar__selectLabel" for="tipoInstalacion">Tipo</label>
            <select id="tipoInstalacion" name="tipo_instalacion" data-auto-submit="true" aria-label="Filtrar por tipo de instalación">
              <option value="">Todos</option>
              <?php foreach ($installationTypeOptions as $typeValue => $typeLabel): ?>
                <option value="<?php echo h($typeValue); ?>" <?php echo $filterInstallationType === $typeValue ? 'selected' : ''; ?>><?php echo h($typeLabel); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($filterInstallationType !== ''): ?>
              <a class="toolbar__selectClear" href="<?php echo h(url_without(['tipo_instalacion','p'])); ?>" title="Quitar filtro de tipo" aria-label="Quitar filtro de tipo de instalación">×</a>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if (!empty($sc['tag_col'])): ?>
          <div class="toolbar__installation <?php echo $filterZone !== '' ? 'is-active' : ''; ?>">
            <span class="toolbar__installationIcon" aria-hidden="true"><?php echo icon('map'); ?></span>
            <label class="toolbar__installationLabel" for="zona">Zona</label>
            <select id="zona" name="zona" data-auto-submit="true" aria-label="Filtrar por zona">
              <option value="">Todas</option>
              <?php foreach ($zones as $zoneValue): ?>
                <option value="<?php echo h($zoneValue); ?>" <?php echo $filterZone === $zoneValue ? 'selected' : ''; ?>><?php echo h($zoneValue); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($filterZone !== ''): ?>
              <a class="toolbar__installationClear" href="<?php echo h(url_without(['zona','instalacion','p'])); ?>" title="Quitar filtro de zona" aria-label="Quitar filtro de zona">×</a>
            <?php endif; ?>
          </div>
          <div class="toolbar__installation <?php echo $filterInstallation !== '' ? 'is-active' : ''; ?>">
            <span class="toolbar__installationIcon" aria-hidden="true"><?php echo icon('map'); ?></span>
            <label class="toolbar__installationLabel" for="instalacion">Instalación</label>
            <select id="instalacion" name="instalacion" data-auto-submit="true" aria-label="Filtrar por instalación">
              <option value="">Todas</option>
              <?php foreach ($installations as $installationValue): ?>
                <option value="<?php echo h($installationValue); ?>" <?php echo $filterInstallation === $installationValue ? 'selected' : ''; ?>><?php echo h($installationValue); ?></option>
              <?php endforeach; ?>
              <?php if ($filterInstallation !== '' && !in_array($filterInstallation, $installations, true)): ?>
                <option value="<?php echo h($filterInstallation); ?>" selected><?php echo h($filterInstallation); ?></option>
              <?php endif; ?>
            </select>
            <?php if ($filterInstallation !== ''): ?>
              <a class="toolbar__installationClear" href="<?php echo h(url_without(['instalacion','p'])); ?>" title="Quitar filtro de instalación" aria-label="Quitar filtro de instalación">×</a>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($statusField !== ''): ?>
          <div class="toolbar__selectFilter <?php echo $filterStatus !== '' ? 'is-active' : ''; ?>">
            <span class="toolbar__selectIcon" aria-hidden="true"><?php echo icon('check'); ?></span>
            <label class="toolbar__selectLabel" for="estado">Estado</label>
            <select id="estado" name="estado" data-auto-submit="true" aria-label="Filtrar por estado">
              <option value="">Todos</option>
              <?php foreach ($statuses as $statusValue): ?>
                <option value="<?php echo h($statusValue); ?>" <?php echo $filterStatus === $statusValue ? 'selected' : ''; ?>><?php echo h($statusValue); ?></option>
              <?php endforeach; ?>
              <?php if ($filterStatus !== '' && !in_array($filterStatus, $statuses, true)): ?>
                <option value="<?php echo h($filterStatus); ?>" selected><?php echo h($filterStatus); ?></option>
              <?php endif; ?>
            </select>
            <?php if ($filterStatus !== ''): ?>
              <a class="toolbar__selectClear" href="<?php echo h(url_without(['estado','p'])); ?>" title="Quitar filtro de estado" aria-label="Quitar filtro de estado">×</a>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($priorityField !== ''): ?>
          <div class="toolbar__selectFilter <?php echo $filterPriority !== '' ? 'is-active' : ''; ?>">
            <span class="toolbar__selectIcon" aria-hidden="true"><?php echo icon('gauge'); ?></span>
            <label class="toolbar__selectLabel" for="prioridad">Prioridad</label>
            <select id="prioridad" name="prioridad" data-auto-submit="true" aria-label="Filtrar por prioridad">
              <option value="">Todas</option>
              <?php foreach ($priorities as $priorityValue):
                list($priorityBadgeColor, $priorityLabel) = badge_prio($priorityValue);
              ?>
                <option value="<?php echo h($priorityValue); ?>" <?php echo $filterPriority === $priorityValue ? 'selected' : ''; ?>><?php echo h($priorityLabel); ?></option>
              <?php endforeach; ?>
              <?php if ($filterPriority !== '' && !in_array($filterPriority, $priorities, true)):
                list($selectedPriorityColor, $selectedPriorityLabel) = badge_prio($filterPriority);
              ?>
                <option value="<?php echo h($filterPriority); ?>" selected><?php echo h($selectedPriorityLabel); ?></option>
              <?php endif; ?>
            </select>
            <?php if ($filterPriority !== ''): ?>
              <a class="toolbar__selectClear" href="<?php echo h(url_without(['prioridad','p'])); ?>" title="Quitar filtro de prioridad" aria-label="Quitar filtro de prioridad">×</a>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if (!empty($sc['fecha_col'])): ?>
          <div class="toolbar__dateRange <?php echo ($filterFrom !== '' || $filterTo !== '' || $filterFromTime !== '' || $filterToTime !== '') ? 'is-active' : ''; ?>">
            <span class="toolbar__dateIcon" aria-hidden="true"><?php echo icon('calendar'); ?></span>

            <div class="toolbar__dateGroup">
              <label for="fechaDesde">
                <span>Desde</span>
                <input type="date" id="fechaDesde" name="fecha_desde" value="<?php echo h($filterFrom); ?>"
                       max="<?php echo h($filterTo); ?>" aria-label="Fecha desde">
              </label>
              <label for="horaDesde" class="toolbar__timeField">
                <span>Hora</span>
                <input type="time" id="horaDesde" name="hora_desde" value="<?php echo h($filterFromTime); ?>"
                       step="60" aria-label="Hora desde opcional" title="Opcional. Valor predeterminado: 00:00">
              </label>
            </div>

            <span class="toolbar__dateSeparator" aria-hidden="true">—</span>

            <div class="toolbar__dateGroup">
              <label for="fechaHasta">
                <span>Hasta</span>
                <input type="date" id="fechaHasta" name="fecha_hasta" value="<?php echo h($filterTo); ?>"
                       min="<?php echo h($filterFrom); ?>" aria-label="Fecha hasta">
              </label>
              <label for="horaHasta" class="toolbar__timeField">
                <span>Hora</span>
                <input type="time" id="horaHasta" name="hora_hasta" value="<?php echo h($filterToTime); ?>"
                       step="60" aria-label="Hora hasta opcional" title="Opcional. Valor predeterminado: 00:00">
              </label>
            </div>

            <button class="toolbar__dateApply" type="submit">Aplicar</button>
            <?php if ($filterFrom !== '' || $filterTo !== '' || $filterFromTime !== '' || $filterToTime !== ''): ?>
              <a class="toolbar__dateClear" href="<?php echo h(url_without(['fecha','fecha_desde','hora_desde','fecha_hasta','hora_hasta','p'])); ?>" title="Quitar rango de fechas y horas" aria-label="Quitar rango de fechas y horas">×</a>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php endif; ?>

          <div class="toolbar__utilities">
            <?php if ($hasActiveFilters): ?>
              <a class="btn-clear-filters" href="list.php?s=<?php echo urlencode($key); ?>" title="Quitar búsqueda, filtros, fechas y ordenamiento">↺ Limpiar filtros</a>
            <?php endif; ?>
            <label class="autoRefresh" for="autoRefreshSelect">
              <span>Actualizar</span>
              <select id="autoRefreshSelect" data-page-key="<?php echo h($key); ?>" data-default-seconds="0" aria-label="Actualización automática">
                <option value="0" <?php echo $key !== 'alarmas24h' ? 'selected' : ''; ?>>Desactivada</option>
                <option value="60">1 minuto</option>
                <option value="300" <?php echo $key === 'alarmas24h' ? 'selected' : ''; ?>>5 minutos</option>
                <option value="600">10 minutos</option>
                <option value="1800">30 minutos</option>
              </select>
            </label>
            <span class="autoRefresh__status" id="autoRefreshStatus" aria-live="polite"></span>
          </div>

          <?php if ($key !== 'top_hml'): ?>
          <label class="autoRefresh" for="perPageSelect">
            <span>Filas</span>
            <select id="perPageSelect" name="per_page" data-auto-submit="true" aria-label="Cantidad de filas por página">
              <?php foreach ($allowedPerPage as $size): ?>
                <option value="<?php echo h($size); ?>" <?php echo $perPageParam === $size ? 'selected' : ''; ?>><?php echo $size === 'all' ? 'Todas' : h($size); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="toolbar__count" id="tableResultCount">
            <?php if ($total > 0): ?>
              <?php if ($showAllRows): ?>
                Mostrando <b>todas las <?php echo number_format($total,0,',','.'); ?></b> filas
              <?php else: ?>
                Mostrando <b><?php echo number_format(min(($page-1)*$perPage+1, $total),0,',','.'); ?></b>–<b><?php echo number_format(min($page*$perPage, $total),0,',','.'); ?></b>
                de <b><?php echo number_format($total,0,',','.'); ?></b>
              <?php endif; ?>
            <?php else: ?>
              Sin resultados
            <?php endif; ?>
          </div>
          <?php if (auth_es_admin()):
            $exportParams = ['s' => $key];
            if ($q !== '') $exportParams['q'] = $q;
            if ($filterZone !== '') $exportParams['zona'] = $filterZone;
            if ($filterInstallation !== '') $exportParams['instalacion'] = $filterInstallation;
            if ($filterInstallationType !== '') $exportParams['tipo_instalacion'] = $filterInstallationType;
            if ($columnFilters) $exportParams['col'] = $columnFilters;
            if ($filterStatus !== '') $exportParams['estado'] = $filterStatus;
            if ($filterPriority !== '') $exportParams['prioridad'] = $filterPriority;
            if ($filterValue !== '') $exportParams['valor'] = $filterValue;
            if ($filterFrom !== '') $exportParams['fecha_desde'] = $filterFrom;
            if ($filterFromTime !== '') $exportParams['hora_desde'] = $filterFromTime;
            if ($filterTo !== '') $exportParams['fecha_hasta'] = $filterTo;
            if ($filterToTime !== '') $exportParams['hora_hasta'] = $filterToTime;
            if ($sortCol !== '') { $exportParams['sort'] = $sortCol; $exportParams['dir'] = strtolower($sortDir); }
            $exportUrl = 'export.php?' . http_build_query($exportParams);
          ?>
          <a class="btn-export" href="<?php echo h($exportUrl); ?>">
            <?php echo icon('download'); ?> Exportar CSV
          </a>
          <?php endif; ?>
          <?php endif; ?>
        </form>

        <?php if ($showAlarmas24Summary): ?>
        <?php
          $activeValue = strtoupper($filterValue);
          $activeStatus = strtoupper($filterStatus);
          $activePriority = strtoupper($filterPriority);
        ?>
        <section class="alarmas24-summary" id="alarmas24Summary" aria-label="Resumen y filtros rápidos de Alarmas 24h">
          <div class="alarmas24-summary__primary">
            <article class="alarmas24-kpi alarmas24-kpi--total">
              <span class="alarmas24-kpi__label">Cantidad de alarmas</span>
              <strong class="alarmas24-kpi__value"><?php echo number_format((int)$alarmas24Summary['total_alarms'], 0, ',', '.'); ?></strong>
              <span class="alarmas24-kpi__detail">Registros según los filtros actuales</span>
            </article>

            <article class="alarmas24-kpi alarmas24-kpi--tags">
              <span class="alarmas24-kpi__label">Cantidad en columna TAG</span>
              <strong class="alarmas24-kpi__value"><?php echo number_format((int)$alarmas24Summary['tag_count'], 0, ',', '.'); ?></strong>
              <span class="alarmas24-kpi__detail">Tags informados, sin valores vacíos</span>
            </article>

            <article class="alarmas24-kpi alarmas24-kpi--top">
              <span class="alarmas24-kpi__label">Tag que más se repite</span>
              <?php if ($alarmas24Summary['top_tag'] !== ''): ?>
                <a class="alarmas24-kpi__tag" href="<?php echo h(url_with(['q' => $alarmas24Summary['top_tag'], 'p' => 1])); ?>" title="Filtrar por este tag">
                  <?php echo h($alarmas24Summary['top_tag']); ?>
                </a>
                <span class="alarmas24-kpi__detail"><b><?php echo number_format((int)$alarmas24Summary['top_tag_count'], 0, ',', '.'); ?></b> repeticiones · clic para filtrar</span>
              <?php else: ?>
                <strong class="alarmas24-kpi__value alarmas24-kpi__value--small">Sin datos</strong>
                <span class="alarmas24-kpi__detail">No hay tags para el resultado actual</span>
              <?php endif; ?>
            </article>
          </div>

          <div class="alarmas24-summary__facets">
            <article class="facet-card">
              <div class="facet-card__head">
                <span>Columna Valor</span>
                <?php if ($filterValue !== ''): ?><a href="<?php echo h(url_without(['valor','p'])); ?>">Quitar filtro</a><?php endif; ?>
              </div>
              <div class="facet-card__items">
                <?php foreach (['ALARMA' => 'Alarma', 'NORMAL' => 'Normal', 'FALLA' => 'Falla'] as $facetKey => $facetLabel):
                  $facetActive = $activeValue === $facetKey;
                  $facetUrl = $facetActive ? url_without(['valor','p']) : url_with(['valor' => $facetKey, 'p' => 1]);
                ?>
                  <a class="facet-chip facet-chip--value <?php echo $facetActive ? 'is-active' : ''; ?>" href="<?php echo h($facetUrl); ?>" aria-pressed="<?php echo $facetActive ? 'true' : 'false'; ?>">
                    <span><?php echo h($facetLabel); ?></span>
                    <b><?php echo number_format((int)$alarmas24Summary['values'][$facetKey], 0, ',', '.'); ?></b>
                  </a>
                <?php endforeach; ?>
              </div>
            </article>

            <article class="facet-card">
              <div class="facet-card__head">
                <span>Columna Estado</span>
                <?php if ($filterStatus !== ''): ?><a href="<?php echo h(url_without(['estado','p'])); ?>">Quitar filtro</a><?php endif; ?>
              </div>
              <div class="facet-card__items">
                <?php $statusFacets=['OK'=>'OK','HI'=>'HI','LO'=>'LO']; if($showCfnStatus)$statusFacets['CFN']='CFN'; ?>
                <?php foreach ($statusFacets as $facetKey => $facetLabel):
                  $facetActive = $activeStatus === $facetKey;
                  $facetUrl = $facetActive ? url_without(['estado','p']) : url_with(['estado' => $facetKey, 'p' => 1]);
                ?>
                  <a class="facet-chip facet-chip--status facet-chip--<?php echo strtolower($facetKey); ?> <?php echo $facetActive ? 'is-active' : ''; ?>" href="<?php echo h($facetUrl); ?>" aria-pressed="<?php echo $facetActive ? 'true' : 'false'; ?>">
                    <span><?php echo h($facetLabel); ?></span>
                    <b><?php echo number_format((int)$alarmas24Summary['statuses'][$facetKey], 0, ',', '.'); ?></b>
                  </a>
                <?php endforeach; ?>
              </div>
            </article>

            <article class="facet-card">
              <div class="facet-card__head">
                <span>Columna Prioridad</span>
                <?php if ($filterPriority !== ''): ?><a href="<?php echo h(url_without(['prioridad','p'])); ?>">Quitar filtro</a><?php endif; ?>
              </div>
              <div class="facet-card__items">
                <?php foreach (['LOW' => 'Baja', 'CRITICAL' => 'Crítica', 'INFO' => 'Info'] as $facetKey => $facetLabel):
                  $facetActive = ($facetKey === 'LOW' && in_array($activePriority, ['LOW','BAJA'], true)) ||
                    ($facetKey === 'CRITICAL' && in_array($activePriority, ['CRITICAL','CRITICA','CRÍTICA'], true)) ||
                    ($facetKey === 'INFO' && $activePriority === 'INFO');
                  $facetUrl = $facetActive ? url_without(['prioridad','p']) : url_with(['prioridad' => $facetKey, 'p' => 1]);
                ?>
                  <a class="facet-chip facet-chip--priority facet-chip--<?php echo strtolower($facetKey); ?> <?php echo $facetActive ? 'is-active' : ''; ?>" href="<?php echo h($facetUrl); ?>" aria-pressed="<?php echo $facetActive ? 'true' : 'false'; ?>">
                    <span><?php echo h($facetLabel); ?></span>
                    <b><?php echo number_format((int)$alarmas24Summary['priorities'][$facetKey], 0, ',', '.'); ?></b>
                  </a>
                <?php endforeach; ?>
              </div>
            </article>
          </div>
        </section>
        <?php endif; ?>

        <?php if ($showReconocidasSummary): ?>
        <section class="stats stats--recon" id="reconocidasSummary" aria-label="Resumen de alarmas reconocidas">
          <article class="stat acc-blue">
            <div class="stat__label">Registros con operador</div>
            <div class="stat__value is-blue"><?php echo number_format((int)$reconSummary['operator_count'], 0, ',', '.'); ?></div>
            <div class="stat__detail">Cantidad de valores cargados en la columna <b>Operador</b> para el resultado actual.</div>
          </article>

          <article class="stat acc-amber">
            <div class="stat__label">Registros con prioridad</div>
            <div class="stat__value is-amber"><?php echo number_format((int)$reconSummary['priority_count'], 0, ',', '.'); ?></div>
            <div class="stat__detail">Cantidad de valores cargados en la columna <b>Prioridad</b> sobre los filtros actuales.</div>
          </article>

          <article class="stat acc-green">
            <div class="stat__label">Tag más repetido</div>
            <div class="stat__value stat__value--tag"><?php echo h($reconSummary['top_tag'] !== '' ? $reconSummary['top_tag'] : 'Sin datos'); ?></div>
            <div class="stat__detail">
              <?php if ((int)$reconSummary['top_tag_count'] > 0): ?>
                Repeticiones encontradas: <b><?php echo number_format((int)$reconSummary['top_tag_count'], 0, ',', '.'); ?></b>
              <?php else: ?>
                No hay suficientes registros para calcular repeticiones.
              <?php endif; ?>
            </div>
          </article>

        </section>
        <?php endif; ?>

        <?php if ($showTop20Summary): ?>
        <section class="stats stats--top20" id="top20Summary" aria-label="Resumen del Top 20 de alarmas">
          <article class="stat acc-blue">
            <div class="stat__label">Total de alarmas</div>
            <div class="stat__value is-blue"><?php echo h(clear_format_metric_number($top20Summary['total_alarms'])); ?></div>
            <div class="stat__detail">Acumulado de la columna <b>Total alarmas</b> para el ranking y los filtros actuales.</div>
          </article>

          <article class="stat acc-red">
            <div class="stat__label">Tag más frecuente</div>
            <div class="stat__value stat__value--tag"><?php echo h($top20Summary['top_tag'] !== '' ? $top20Summary['top_tag'] : 'Sin datos'); ?></div>
            <div class="stat__detail">
              <b><?php echo h(clear_format_metric_number($top20Summary['top_tag_alarms'])); ?></b> alarmas
              · <?php echo h(clear_format_metric_percent($top20Summary['top_tag_percent'])); ?> del total.
            </div>
          </article>

          <article class="stat acc-green">
            <div class="stat__label">Instalación principal</div>
            <div class="stat__value stat__value--tag is-green"><?php echo h($top20Summary['top_installation'] !== '' ? $top20Summary['top_installation'] : 'Sin datos'); ?></div>
            <div class="stat__detail">
              <b><?php echo h(clear_format_metric_number($top20Summary['top_installation_alarms'])); ?></b> alarmas
              · <?php echo h(clear_format_metric_percent($top20Summary['top_installation_percent'])); ?> del total.
            </div>
          </article>

          <article class="stat acc-amber">
            <div class="stat__label">Concentración del Top 5</div>
            <div class="stat__value is-amber"><?php echo h(clear_format_metric_percent($top20Summary['top5_percent'])); ?></div>
            <div class="stat__detail">Los cinco tags principales concentran <b><?php echo h(clear_format_metric_number($top20Summary['top5_alarms'])); ?></b> alarmas.</div>
          </article>
        </section>
        <?php endif; ?>

        <?php if ($showWeeklyHmlSummary): ?>
        <section class="weekly-summary" id="weeklyHmlSummary" aria-label="Resumen semanal por criticidad">
          <div class="weekly-summary__head">
            <div>
              <span class="weekly-summary__eyebrow">Top semanal de criticidad</span>
              <h2>Alarmas de los últimos 7 días</h2>
            </div>
            <span class="weekly-summary__period">
              <?php if ($weeklyHmlSummary['start_date'] !== '' && $weeklyHmlSummary['end_date'] !== ''): ?>
                <?php echo h(date('d/m/Y', strtotime($weeklyHmlSummary['start_date']))); ?> — <?php echo h(date('d/m/Y', strtotime($weeklyHmlSummary['end_date']))); ?>
              <?php else: ?>
                Sin datos disponibles
              <?php endif; ?>
            </span>
          </div>

          <div class="stats stats--weekly">
            <article class="stat acc-blue">
              <div class="stat__label">Total semanal</div>
              <div class="stat__value is-blue"><?php echo h(clear_format_metric_number($weeklyHmlSummary['total'])); ?></div>
              <div class="stat__detail">
                Acumulado de <b><?php echo number_format((int)$weeklyHmlSummary['days'], 0, ',', '.'); ?> días</b>.
                <?php if ($weeklyHmlSummary['dominant_label'] !== ''): ?>Predomina la prioridad <b><?php echo h($weeklyHmlSummary['dominant_label']); ?></b>.<?php endif; ?>
              </div>
            </article>

            <article class="stat acc-red weekly-priority weekly-priority--high">
              <div class="stat__label">Prioridad alta</div>
              <div class="stat__value is-red"><?php echo h(clear_format_metric_number($weeklyHmlSummary['high'])); ?></div>
              <div class="stat__detail">
                <b><?php echo h(clear_format_metric_percent($weeklyHmlSummary['high_percent'])); ?></b> del total semanal.
                <span class="weekly-progress"><i style="width:<?php echo max(0, min(100, (float)$weeklyHmlSummary['high_percent'])); ?>%"></i></span>
              </div>
            </article>

            <article class="stat acc-amber weekly-priority weekly-priority--medium">
              <div class="stat__label">Prioridad media</div>
              <div class="stat__value is-amber"><?php echo h(clear_format_metric_number($weeklyHmlSummary['medium'])); ?></div>
              <div class="stat__detail">
                <b><?php echo h(clear_format_metric_percent($weeklyHmlSummary['medium_percent'])); ?></b> del total semanal.
                <span class="weekly-progress"><i style="width:<?php echo max(0, min(100, (float)$weeklyHmlSummary['medium_percent'])); ?>%"></i></span>
              </div>
            </article>

            <article class="stat acc-green weekly-priority weekly-priority--low">
              <div class="stat__label">Prioridad baja</div>
              <div class="stat__value is-green"><?php echo h(clear_format_metric_number($weeklyHmlSummary['low'])); ?></div>
              <div class="stat__detail">
                <b><?php echo h(clear_format_metric_percent($weeklyHmlSummary['low_percent'])); ?></b> del total semanal.
                <span class="weekly-progress"><i style="width:<?php echo max(0, min(100, (float)$weeklyHmlSummary['low_percent'])); ?>%"></i></span>
              </div>
            </article>
          </div>

          <div class="weekly-charts" aria-label="Gráficos diarios del resumen semanal">
            <article class="weekly-chart-card">
              <div class="weekly-chart-card__head">
                <div>
                  <span class="weekly-summary__eyebrow">Criticidad por día</span>
                  <h3>Alta, media y baja</h3>
                </div>
                <span class="weekly-chart-card__hint">Comparación diaria</span>
              </div>
              <div class="weekly-chart-card__canvas">
                <canvas id="weeklyPriorityChart" aria-label="Alarmas altas, medias y bajas por día" role="img"></canvas>
              </div>
            </article>

            <article class="weekly-chart-card">
              <div class="weekly-chart-card__head">
                <div>
                  <span class="weekly-summary__eyebrow">Volumen diario</span>
                  <h3>Total de alarmas por día</h3>
                </div>
                <span class="weekly-chart-card__hint">Suma de criticidades</span>
              </div>
              <div class="weekly-chart-card__canvas">
                <canvas id="weeklyTotalChart" aria-label="Total de alarmas por día" role="img"></canvas>
              </div>
            </article>
          </div>

          <script type="application/json" id="weeklyChartsData"><?php echo json_encode([
            'labels' => $weeklyHmlSummary['chart_labels'],
            'high' => $weeklyHmlSummary['chart_high'],
            'medium' => $weeklyHmlSummary['chart_medium'],
            'low' => $weeklyHmlSummary['chart_low'],
            'total' => $weeklyHmlSummary['chart_total'],
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
        </section>
        <div id="listTableArea" hidden></div>
        <?php else: ?>

        <?php if ($key === 'top20_24h'): ?>
        <section class="top24Charts" aria-label="Gráficos del Top 20 de alarmas en 24 horas">
          <article class="top24ChartCard">
            <h2>Alarmas por TAG</h2>
            <p>Los diez TAG con mayor cantidad dentro de los filtros actuales.</p>
            <div class="top24ChartCard__canvas"><canvas id="top24TagChart" aria-label="Gráfico de alarmas por TAG"></canvas></div>
            <div class="nsReportChartAction"><?php if($reportEnabled)echo ns_report_pick(ns_report_key('top20-24h-tags',[$top24Charts['labels'],$top24Charts['values']]),'chart','Top 20 24h · Alarmas por TAG',['kind'=>'chart','chart_type'=>'bar','labels'=>$top24Charts['labels'],'datasets'=>[['label'=>'Alarmas','values'=>$top24Charts['values']]]]); ?></div>
          </article>
          <article class="top24ChartCard">
            <h2>Alarmas por tipo</h2>
            <p>Distribución por tipo de instalación sobre la caché de 24 horas.</p>
            <div class="top24ChartCard__canvas"><canvas id="top24TypeChart" aria-label="Gráfico de alarmas por tipo de instalación"></canvas></div>
            <div class="nsReportChartAction"><?php if($reportEnabled)echo ns_report_pick(ns_report_key('top20-24h-tipos',[$top24Charts['type_labels'],$top24Charts['type_values']]),'chart','Top 20 24h · Alarmas por tipo',['kind'=>'chart','chart_type'=>'doughnut','labels'=>$top24Charts['type_labels'],'datasets'=>[['label'=>'Alarmas','values'=>$top24Charts['type_values']]]]); ?></div>
          </article>
        </section>
        <script type="application/json" id="top24ChartsData"><?php echo json_encode($top24Charts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
        <?php endif; ?>

        <?php if($reportEnabled): ?><div class="nsReportTools"><button class="nsButton" type="button" data-clear-report-add-selected>Agregar seleccionadas al reporte</button><button class="nsButton is-secondary" type="button" data-ns-report-open>Ver y enviar reporte <span class="nsReportCount" data-ns-report-count hidden>0</span></button><span class="nsReportTools__count">Marcá las alarmas que querés incluir.</span></div><?php endif; ?>

        <!-- Tabla -->
        <div class="tablewrap" id="listTableArea">
          <?php if (empty($rows)): ?>
            <div class="empty">
              <?php echo icon('search'); ?>
              <p>No se encontraron registros<?php echo $q !== '' ? ' para “' . h($q) . '”' : ''; ?><?php
                if ($filterInstallation !== '') {
                    echo ' en la instalación “' . h($filterInstallation) . '”';
                }
                if ($filterInstallationType !== '') {
                    echo ' del tipo “' . h($installationTypeOptions[$filterInstallationType] ?? $filterInstallationType) . '”';
                }
                if ($filterValue !== '') {
                    echo ' con valor “' . h($filterValue) . '”';
                }
                if ($filterStatus !== '') {
                    echo ' con estado “' . h($filterStatus) . '”';
                }
                if ($filterPriority !== '') {
                    list($emptyPriorityColor, $emptyPriorityLabel) = badge_prio($filterPriority);
                    echo ' con prioridad “' . h($emptyPriorityLabel) . '”';
                }
                if ($filterFrom !== '' && $filterTo !== '') {
                    echo ' entre el ' . h(clear_label_date_range($filterFrom, $filterFromTime)) . ' y el ' . h(clear_label_date_range($filterTo, $filterToTime));
                } elseif ($filterFrom !== '') {
                    echo ' desde el ' . h(clear_label_date_range($filterFrom, $filterFromTime));
                } elseif ($filterTo !== '') {
                    echo ' hasta el ' . h(clear_label_date_range($filterTo, $filterToTime));
                }
              ?>.</p>
            </div>
          <?php else: ?>
          <div class="tablescroll">
          <?php $tableClass = trim('grid grid--sortable ' . ($sc['table_class'] ?? '')); ?>
          <table class="<?php echo h($tableClass); ?>">
            <thead>
              <tr>
                <?php if($reportEnabled): ?><th class="no-sort" data-sortable="false"><span>Reporte</span></th><?php endif; ?>
                <?php if ($installationTypeEnabled):
                  $typeIsSorted = $sortCol === '__TIPO_INSTALACION';
                  $typeNextDir = ($typeIsSorted && $sortDir === 'ASC') ? 'desc' : 'asc';
                ?>
                  <th class="<?php echo $typeIsSorted ? 'is-sorted' : ''; ?>" aria-sort="<?php echo $typeIsSorted ? ($sortDir === 'ASC' ? 'ascending' : 'descending') : 'none'; ?>">
                    <a href="<?php echo h(url_with(['sort'=>'__TIPO_INSTALACION','dir'=>$typeNextDir,'p'=>1])); ?>" title="Ordenar por tipo de instalación"><span>Tipo de instalación</span><span class="sort-indicator" aria-hidden="true"><?php echo $typeIsSorted ? ($sortDir === 'ASC' ? '▲' : '▼') : '↕'; ?></span></a>
                  </th>
                <?php endif; ?>
                <?php foreach ($sc['cols'] as $c):
                  list($field, $label, $type) = $c;
                  $isSorted = ($sortCol === $field);
                  $nextDir = ($isSorted && $sortDir === 'ASC') ? 'desc' : 'asc';
                ?>
                  <th class="<?php echo $isSorted ? 'is-sorted' : ''; ?>" aria-sort="<?php echo $isSorted ? ($sortDir === 'ASC' ? 'ascending' : 'descending') : 'none'; ?>">
                    <a href="<?php echo h(url_with(['sort'=>$field,'dir'=>$nextDir,'p'=>1])); ?>" title="Ordenar por <?php echo h($label); ?>">
                      <span><?php echo h($label); ?></span>
                      <span class="sort-indicator" aria-hidden="true"><?php echo $isSorted ? ($sortDir === 'ASC' ? '▲' : '▼') : '↕'; ?></span>
                    </a>
                  </th>
                  <?php if ($CAN_VIEW_COMMENTS && in_array($key, ['reconocidas_usr', 'reconocidas'], true) && $field === 'OPERADOR'): ?>
                    <th class="no-sort" data-sortable="false"><span>Comentarios</span></th>
                  <?php endif; ?>
                  <?php if ($top24CommentsEnabled && $field === ($sc['tag_col'] ?? '')): ?>
                    <th class="no-sort" data-sortable="false"><span>Comentarios</span></th>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tr>
              <?php if (!empty($sc['standard_filters'])): ?>
              <tr class="gridFilterRow" aria-label="Filtros por columna">
                <?php if($reportEnabled): ?><th class="gridFilterRow__empty"><span class="sr-only">Selección de reporte</span></th><?php endif; ?>
                <?php if ($installationTypeEnabled): ?>
                  <th>
                    <select class="installationTypeFilter" name="tipo_instalacion" form="tableFilters" data-auto-submit="true" aria-label="Filtrar columna Tipo de instalación">
                      <option value="">Todos</option>
                      <?php foreach ($installationTypeOptions as $typeValue => $typeLabel): ?><option value="<?php echo h($typeValue); ?>" <?php echo $filterInstallationType === $typeValue ? 'selected' : ''; ?>><?php echo h($typeLabel); ?></option><?php endforeach; ?>
                    </select>
                  </th>
                <?php endif; ?>
                <?php foreach ($sc['cols'] as $c):
                  list($field, $label, $type) = $c;
                  $columnFilterValue = $columnFilters[$field] ?? '';
                ?>
                  <th>
                    <?php if ($type === 'status' && $statuses): ?>
                      <select name="col[<?php echo h($field); ?>]" form="tableFilters" data-auto-submit="true" aria-label="Filtrar <?php echo h($label); ?>"><option value="">Todos</option><?php foreach ($statuses as $optionValue): ?><option value="<?php echo h($optionValue); ?>" <?php echo $columnFilterValue === $optionValue ? 'selected' : ''; ?>><?php echo h($optionValue); ?></option><?php endforeach; ?></select>
                    <?php elseif ($type === 'prio' && $priorities): ?>
                      <select name="col[<?php echo h($field); ?>]" form="tableFilters" data-auto-submit="true" aria-label="Filtrar <?php echo h($label); ?>"><option value="">Todas</option><?php foreach ($priorities as $optionValue): ?><option value="<?php echo h($optionValue); ?>" <?php echo $columnFilterValue === $optionValue ? 'selected' : ''; ?>><?php echo h($optionValue); ?></option><?php endforeach; ?></select>
                    <?php else: ?>
                      <input type="text" name="col[<?php echo h($field); ?>]" form="tableFilters" value="<?php echo h($columnFilterValue); ?>" placeholder="Filtrar" aria-label="Filtrar <?php echo h($label); ?>" data-column-filter="true">
                    <?php endif; ?>
                  </th>
                  <?php if ($CAN_VIEW_COMMENTS && in_array($key, ['reconocidas_usr', 'reconocidas'], true) && $field === 'OPERADOR'): ?><th class="gridFilterRow__empty"></th><?php endif; ?>
                  <?php if ($top24CommentsEnabled && $field === ($sc['tag_col'] ?? '')): ?><th class="gridFilterRow__empty"><span class="sr-only">Sin filtro</span></th><?php endif; ?>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>
            </thead>
            <tbody>
              <?php foreach ($rows as $row):
                $rowActionTagField = trim((string)($sc['tag_col'] ?? ''));
                $rowActionDateField = trim((string)($sc['comment_timestamp_col'] ?? ($sc['fecha_col'] ?? '')));
                $rowActionValueField = trim((string)($sc['valor_col'] ?? ''));
                $rowActionTag = $rowActionTagField !== '' ? trim((string)($row[$rowActionTagField] ?? '')) : '';
                $rowActionTimestamp = $rowActionDateField !== '' ? trim((string)($row[$rowActionDateField] ?? '')) : '';
                $rowActionValue = $rowActionValueField !== '' ? trim((string)($row[$rowActionValueField] ?? '')) : trim((string)($row['ALM_VALUE'] ?? ($row['Valor'] ?? '')));
                $rowActionDescription = trim((string)($row['ALM_DESCR'] ?? ($row['DESCRIPCION'] ?? ($row['Descripcion'] ?? ''))));
                $rowSupportsQuickComment = $rowActionTag !== '';
                $rowTop24CommentKey = strtoupper($rowActionTag) . '|' . clear_alarm_event_normalize_datetime($rowActionTimestamp);
                $rowHasTop24Comment = isset($alarmCommentKeys[strtoupper($rowActionTag)]);
                $reportCommentRow=$alarmCommentKeys[strtoupper($rowActionTag)]??[];
                $reportComment=trim((string)($reportCommentRow['COMENTARIO']??$reportCommentRow['comentario']??''));
                $reportColumns=[];
                if($installationTypeEnabled)$reportColumns['Tipo de instalación']=$row['__TIPO_INSTALACION']??'';
                foreach($sc['cols'] as $reportColumn)$reportColumns[(string)$reportColumn[1]]=$row[(string)$reportColumn[0]]??'';
                if($reportComment!=='')$reportColumns['Comentario']=$reportComment;
                $reportPayload=ns_report_row_payload($reportColumns);
                $reportKey=ns_report_key($key,[$rowActionTag,$rowActionTimestamp,$rowActionValue,$reportColumns]);
              ?>
                <tr class="js-detail-row<?php echo in_array($key, ['reconocidas_usr', 'reconocidas'], true) ? ' js-recon-user-row' : ''; ?>" tabindex="0" aria-label="Ver detalle del registro"<?php if (in_array($key, ['reconocidas_usr', 'reconocidas'], true)): ?> data-recon-view="<?php echo h($key); ?>" data-recon-tag="<?php echo h((string)($row['TAG_FIX'] ?? '')); ?>" data-recon-operator="<?php echo h((string)($row['OPERADOR'] ?? '')); ?>" data-recon-date="<?php echo h((string)($row['ALM_NATIVETIMEIN'] ?? '')); ?>" data-recon-comment-count="<?php echo (int)($reconCommentCounts[clear_recon_comment_group_key((string)($row['TAG_FIX'] ?? ''), (string)($row['OPERADOR'] ?? ''))] ?? 0); ?>"<?php endif; ?>>
                  <?php if($reportEnabled): ?><td data-label="Reporte"><?php echo ns_report_pick($reportKey,'row',($sc['titulo']??'Alarma').' · '.($rowActionTag!==''?$rowActionTag:'registro'),$reportPayload,'Incluir'); ?></td><?php endif; ?>
                  <?php if ($installationTypeEnabled):
                    $rowInstallationType = clear_installation_type_normalize($row['__TIPO_INSTALACION'] ?? '');
                    if ($rowInstallationType === '') $rowInstallationType = 'SIN CLASIFICAR';
                  ?>
                    <td data-label="Tipo de instalación" data-raw-value="<?php echo h($rowInstallationType); ?>"><span class="installationTypeBadge installationTypeBadge--<?php echo h(clear_installation_type_class($rowInstallationType)); ?>"><?php echo h($installationTypeOptions[$rowInstallationType] ?? $rowInstallationType); ?></span></td>
                  <?php endif; ?>
                  <?php foreach ($sc['cols'] as $c):
                    list($field, $label, $type) = $c;
                    $val = isset($row[$field]) ? $row[$field] : '';
                    $tdAttrs = ' data-label="' . h($label) . '" data-raw-value="' . h((string)$val) . '"';
                    switch ($type):
                      case 'prio':
                        list($bc, $bt) = badge_prio($val);
                        echo '<td'.$tdAttrs.'><span class="badge badge--'.$bc.'">'.h($bt).'</span></td>';
                        break;
                      case 'status':
                        list($bc, $bt) = badge_status($val);
                        echo '<td'.$tdAttrs.'><span class="badge badge--'.$bc.'">'.h($bt).'</span></td>';
                        break;
                      case 'num':
                        $disp = is_numeric($val) ? number_format((float)$val, (floor((float)$val)==$val?0:2), ',', '.') : h($val);
                        echo '<td'.$tdAttrs.' class="cell-num">'.$disp.'</td>';
                        break;
                      case 'mono':
                        echo '<td'.$tdAttrs.' class="cell-mono">'.h($val).'</td>';
                        break;
                      case 'long':
                        echo '<td'.$tdAttrs.' class="cell-long" title="'.h($val).'"><div class="cell-clamp">'.h($val).'</div></td>';
                        break;
                      case 'operator':
                        $operator = trim((string)$val);
                        echo '<td'.$tdAttrs.' class="cell-operator" title="'.h($operator).'"><span class="operator-chip">'.h($operator !== '' ? $operator : '—').'</span></td>';
                        if ($CAN_VIEW_COMMENTS && in_array($key, ['reconocidas_usr', 'reconocidas'], true)) {
                            $rowTag = trim((string)($row['TAG_FIX'] ?? ''));
                            $rowOperator = trim((string)($row['OPERADOR'] ?? ''));
                            $rowReconDate = trim((string)($row['ALM_NATIVETIMEIN'] ?? ''));
                            $commentCount = (int)($reconCommentCounts[clear_recon_comment_group_key($rowTag, $rowOperator)] ?? 0);
                            $btnClass = 'reconCommentsBtn' . ($commentCount > 0 ? ' has-comments' : '');
                            $btnTitle = $commentCount > 0 ? 'Este registro tiene comentarios cargados' : ($CAN_CREATE_COMMENTS ? 'Agregar comentario' : 'Sin comentarios');
                            echo '<td class="cell-comments" data-label="Comentarios" data-raw-value="'.(int)$commentCount.'"><button type="button" class="'.h($btnClass).'" title="'.h($btnTitle).'" data-recon-open="true" data-can-create="'.($CAN_CREATE_COMMENTS?'1':'0').'" data-tag="'.h($rowTag).'" data-operator="'.h($rowOperator).'" data-event-date="'.h($rowReconDate).'"><span class="reconCommentsBtn__icon" aria-hidden="true">💬</span>';
                            if ($key === 'reconocidas') {
                                echo $commentCount > 0 ? 'Comentar · ' . number_format($commentCount, 0, ',', '.') : 'Comentar';
                            } else {
                                echo $commentCount > 0 ? number_format($commentCount, 0, ',', '.') . ' Ver' : 'Agregar';
                            }
                            if ($commentCount > 0) {
                                echo '<span class="reconCommentsBtn__flag" aria-hidden="true" title="Tiene comentarios">●</span>';
                            }
                            echo '</button></td>';
                        }
                        break;
                      default:
                        $isTagCell = !empty($sc['tag_col']) && $field === $sc['tag_col'];
                        if ($isTagCell && trim((string)$val) !== '') {
                            echo '<td'.$tdAttrs.' class="cell-text">'.clear_alarm_actions_cell([
                                'display' => $val,
                                'tag' => $rowActionTag !== '' ? $rowActionTag : $val,
                                'timestamp' => $rowActionTimestamp,
                                'value' => $rowActionValue,
                                'description' => $rowActionDescription,
                                'query' => clear_related_tag_query($val),
                                'preserve_pi_link' => true,
                                'show_comment' => $rowSupportsQuickComment,
                                'has_comment' => $rowHasTop24Comment,
                                'context' => $key,
                            ]).'</td>';
                        } else {
                            echo '<td'.$tdAttrs.' class="cell-text">'.h($val).'</td>';
                        }
                    endswitch;
                    if ($top24CommentsEnabled && $field === $rowActionTagField) {
                        echo '<td class="cell-comments" data-label="Comentarios" data-raw-value="'.($rowHasTop24Comment?'1':'0').'">'.clear_alarm_actions_cell([
                            'display' => 'Comentario',
                            'tag' => $rowActionTag,
                            'timestamp' => $rowActionTimestamp,
                            'value' => $rowActionValue,
                            'description' => $rowActionDescription,
                            'preserve_pi_link' => false,
                            'show_history' => false,
                            'show_comment' => true,
                            'has_comment' => $rowHasTop24Comment,
                        ]).'</td>';
                    }
                  endforeach; ?>

                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>
        </div>

        <!-- Paginación -->
        <?php if ($totalPages > 1): ?>
        <div class="pager" id="listPager">
          <div class="pager__info">Página <?php echo $page; ?> de <?php echo $totalPages; ?></div>
          <div class="pager__btns">
            <a class="pager__btn <?php echo $page<=1?'is-disabled':''; ?>" href="<?php echo h(url_with(['p'=>max(1,$page-1)])); ?>">‹</a>
            <?php
              $start = max(1, $page - 2);
              $end   = min($totalPages, $start + 4);
              $start = max(1, $end - 4);
              for ($i = $start; $i <= $end; $i++): ?>
              <a class="pager__btn <?php echo $i==$page?'is-active':''; ?>" href="<?php echo h(url_with(['p'=>$i])); ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            <a class="pager__btn <?php echo $page>=$totalPages?'is-disabled':''; ?>" href="<?php echo h(url_with(['p'=>min($totalPages,$page+1)])); ?>">›</a>
          </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>

      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>

<?php clear_alarm_actions_modal(); ?>

<div class="reconCommentsModal__overlay" id="reconCommentsOverlay" hidden></div>
<section class="reconCommentsModal" id="reconCommentsModal" aria-hidden="true" aria-labelledby="reconCommentsTitle">
  <div class="reconCommentsModal__head">
    <div>
      <div class="reconCommentsModal__eyebrow">Alarmas reconocidas</div>
      <h2 id="reconCommentsTitle">Detalle de reconocimientos</h2>
      <p id="reconCommentsSubtitle">Seleccioná un reconocimiento para cargar su motivo.</p>
    </div>
    <button type="button" class="reconCommentsModal__close" id="reconCommentsClose" aria-label="Cerrar">×</button>
  </div>
  <div class="reconCommentsModal__body">
    <div class="reconCommentsSummary" id="reconCommentsSummary"></div>
    <div class="reconCommentsStatus" id="reconCommentsStatus" hidden></div>
    <div class="reconCommentsTableWrap">
      <table class="reconCommentsTable">
        <thead><tr><th>Fecha y hora</th><th>Descripción</th><th>Estado</th><th>Prioridad</th><th>Motivo</th><th>Comentario</th></tr></thead>
        <tbody id="reconCommentsRows"></tbody>
      </table>
    </div>
    <form class="reconCommentForm" id="reconCommentForm" hidden>
      <input type="hidden" id="reconEventKey" name="event_key">
      <input type="hidden" id="reconTag" name="tag">
      <input type="hidden" id="reconOperator" name="operator">
      <input type="hidden" id="reconEventDate" name="event_date">
      <input type="hidden" id="reconDescription" name="description">
      <input type="hidden" id="reconPriority" name="priority">
      <div class="reconCommentForm__title">Agregar o editar comentario del reconocimiento seleccionado</div>
      <div class="reconCommentForm__grid">
        <label>Motivo del reconocimiento <b>*</b>
          <select id="reconReason" name="reason" required></select>
        </label>
        <label>Comentario <b>*</b>
          <textarea id="reconComment" name="comment" rows="4" maxlength="2000" required placeholder="Describí por qué se reconoció la alarma..."></textarea>
        </label>
      </div>
      <div class="reconCommentMeta" id="reconCommentMeta"></div>
      <div class="reconCommentForm__actions">
        <button type="button" class="btn-secondary" id="reconCommentCancel">Cancelar</button>
        <button type="submit" class="btn-primary-inline">Guardar comentario</button>
      </div>
    </form>
  </div>
</section>

<div class="piModal__overlay" id="piModalOverlay" hidden></div>
<section class="piModal" id="piModal" aria-hidden="true" aria-labelledby="piModalTitle">
  <div class="piModal__head">
    <div>
      <div class="piModal__eyebrow">Vista rápida</div>
      <h2 id="piModalTitle">PI Histórico</h2>
      <p class="piModal__sub">Tendencia y sugerencias de tags relacionadas.</p>
    </div>
    <div class="piModal__actions">
      <a class="piModal__link" id="piModalOpenPage" href="pi_historico.php" target="_blank" rel="noopener">Abrir página completa</a>
      <button type="button" class="piModal__close" id="piModalClose" aria-label="Cerrar PI Histórico">×</button>
    </div>
  </div>
  <div class="piModal__body">
    <iframe id="piModalFrame" class="piModal__frame" src="about:blank" title="PI Histórico embebido" loading="lazy"></iframe>
  </div>
</section>

<div class="detailDrawer__overlay" id="detailDrawerOverlay" hidden></div>
<aside class="detailDrawer" id="detailDrawer" aria-hidden="true" aria-labelledby="detailDrawerTitle">
  <div class="detailDrawer__head">
    <div>
      <div class="detailDrawer__eyebrow">Detalle del registro</div>
      <h2 id="detailDrawerTitle">Alarma</h2>
    </div>
    <button type="button" class="detailDrawer__close" id="detailDrawerClose" aria-label="Cerrar detalle">×</button>
  </div>
  <div class="detailDrawer__body" id="detailDrawerBody"></div>
</aside>

<?php if ($showWeeklyHmlSummary || $key === 'top20_24h'): ?>
<script src="assets/js/chart.umd.js"></script>
<?php endif; ?>
<script src="assets/js/app.js?v=20260826-filtros-perf-safe-2"></script>
<script src="assets/js/alarm_actions.js?v=20260826-central-1"></script>
<?php if($reportEnabled): ?><script src="assets/js/novedades_semanales.js?v=20260901-report-chart-2"></script><?php endif; ?>

<script>
(function(){
  var form=document.getElementById('tableFilters');
  if(!form)return;
  var timer=0;
  document.querySelectorAll('[data-column-filter="true"]').forEach(function(input){
    input.addEventListener('input',function(){
      window.clearTimeout(timer);
      timer=window.setTimeout(function(){
        var page=form.querySelector('[name="p"]');if(page)page.value='1';
        if(form.requestSubmit)form.requestSubmit();else form.submit();
      },650);
    });
  });
})();
</script>

<script>
(function(){
  var node=document.getElementById('top24ChartsData');
  if(!node||typeof Chart==='undefined')return;
  var data;
  try{data=JSON.parse(node.textContent||'{}')}catch(e){return}
  var fmt=new Intl.NumberFormat('es-AR');
  function applyChartFilter(name,value){var url=new URL(window.location.href);url.searchParams.set(name,value);url.searchParams.delete('p');window.location.assign(url.toString())}
  var base={responsive:true,maintainAspectRatio:false,onClick:function(evt,items,chart){if(items.length)applyChartFilter('q',chart.data.labels[items[0].index])},plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return ' '+fmt.format(c.raw||0)+' alarmas'}}}},scales:{y:{beginAtZero:true,ticks:{precision:0,callback:function(v){return fmt.format(v)}}},x:{ticks:{maxRotation:28,minRotation:0}}}};
  var tagCanvas=document.getElementById('top24TagChart');
  if(tagCanvas)new Chart(tagCanvas,{type:'bar',data:{labels:data.labels||[],datasets:[{label:'Alarmas',data:data.values||[],backgroundColor:'#195568',borderRadius:7,maxBarThickness:58}]},options:base});
  var typeCanvas=document.getElementById('top24TypeChart');
  if(typeCanvas)new Chart(typeCanvas,{type:'doughnut',data:{labels:data.type_labels||[],datasets:[{data:data.type_values||[],backgroundColor:['#178c64','#7650a7','#2d88a5','#d18a17','#b94b3c','#5a51a6','#238f86','#c29a18','#91a4ad'],borderColor:'#fff',borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'62%',onClick:function(evt,items,chart){if(items.length)applyChartFilter('tipo_instalacion',chart.data.labels[items[0].index])},plugins:{legend:{position:'bottom',labels:{boxWidth:10,usePointStyle:true}},tooltip:{callbacks:{label:function(c){return ' '+c.label+': '+fmt.format(c.raw||0)}}}}}});
})();
</script>

<script>
(function(){
 var cfg=null;
 <?php if ($key === 'top_hml'): ?>cfg={screen:'top_semanal',insertBefore:'#weeklyHmlSummary',groups:[{id:'kpis',container:'.stats--weekly',item:':scope > .stat',grid:true,defaultSize:'small'},{id:'charts',container:'.weekly-charts',item:':scope > .weekly-chart-card',grid:true,defaultSize:'medium'}]};
 <?php elseif ($key === 'alarmas24h'): ?>cfg={screen:'alarmas24',insertBefore:'#alarmas24Summary',groups:[{id:'primary',container:'.alarmas24-summary__primary',item:':scope > *',grid:true,defaultSize:'medium'},{id:'facets',container:'.alarmas24-summary__facets',item:':scope > .facet-card',grid:true,defaultSize:'medium'}]};
 <?php elseif ($key === 'reconocidas'): ?>cfg={screen:'reconocidas',insertBefore:'#reconocidasSummary',groups:[{id:'kpis',container:'#reconocidasSummary',item:':scope > .stat',grid:true,defaultSize:'small'}]};
 <?php endif; ?>
 if(cfg)ClearLayoutCustomizer.init(cfg);
})();
</script>
</body>
</html>
