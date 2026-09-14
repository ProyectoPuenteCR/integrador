<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/tecss_vibraciones_query.php';
require_once __DIR__ . '/includes/alarm_actions.php';
require_once __DIR__ . '/includes/novedades_semanales_common.php';

auth_require();
permissions_require_menu('tecss_vibraciones');

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'America/Argentina/Buenos_Aires');
$APP_USER = auth_user() ?: 'CLEAR';
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = 'tecss_vibraciones';
$db = clear_db();
$reportEnabled = $db->ok() && ns_report_ready($db) && permissions_can_menu('novedades_semanales_reporte');

$filters = tecss_vib_filters_from_request();
$sort = tecss_vib_sort_from_request();
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = (int)($_GET['filas'] ?? 50);
if (!in_array($pageSize, [50, 100], true)) $pageSize = 50;
$offset = ($page - 1) * $pageSize;

$rows = [];
$batteryOptions = [];
$totalFiltered = 0;
$error = '';
$queryMs = 0;
$summary = [
    'TOTAL' => 0, 'CRITICOS' => 0, 'ALERTAS' => 0, 'FRECUENTES' => 0,
    'PARADOS' => 0, 'NORMALES' => 0, 'ULTIMO_ANALISIS' => null, 'ULTIMO_DATO' => null,
];
$lastRun = [];

if (!$db->ok()) {
    $error = $db->error();
} elseif (!tecss_vib_tables_ready($db)) {
    $error = 'Falta instalar el módulo. Ejecutá SQL/CLEAR_TECSS_VIBRACIONES.sql en LC_MDB.';
} else {
    $summaryRows = $db->all(
        "SELECT COUNT_BIG(*) AS TOTAL," .
        "SUM(CASE WHEN ESTADO='critico' THEN 1 ELSE 0 END) AS CRITICOS," .
        "SUM(CASE WHEN ESTADO='alerta' THEN 1 ELSE 0 END) AS ALERTAS," .
        "SUM(CASE WHEN ESTADO='frecuente' THEN 1 ELSE 0 END) AS FRECUENTES," .
        "SUM(CASE WHEN ESTADO='parado' THEN 1 ELSE 0 END) AS PARADOS," .
        "SUM(CASE WHEN ESTADO='normal' THEN 1 ELSE 0 END) AS NORMALES," .
        "MAX(FECHA_ANALISIS) AS ULTIMO_ANALISIS,MAX(FECHA_ULTIMO_DATO) AS ULTIMO_DATO " .
        "FROM dbo.CLEAR_TECSS_VIBRACIONES_ESTADO"
    );
    if ($summaryRows) $summary = array_merge($summary, $summaryRows[0]);
    $runRows = $db->all(
        "SELECT TOP (1) ID,FECHA_INICIO,FECHA_FIN,ESTADO,FILAS_ORIGEN,FILAS_CACHE," .
        "POZOS_ANALIZADOS,EVENTOS_GENERADOS,MENSAJE " .
        "FROM dbo.CLEAR_TECSS_VIBRACIONES_EJECUCION ORDER BY ID DESC"
    );
    if ($runRows) $lastRun = $runRows[0];
    $batteryRows = $db->all(
        "SELECT DISTINCT TOP (500) LTRIM(RTRIM(BATERIA)) AS BATERIA " .
        "FROM dbo.CLEAR_TECSS_VIBRACIONES_ESTADO " .
        "WHERE BATERIA IS NOT NULL AND LTRIM(RTRIM(BATERIA))<>'' ORDER BY BATERIA"
    );
    foreach ($batteryRows as $batteryRow) {
        $battery = tecss_vib_text($batteryRow['BATERIA'] ?? '');
        if ($battery !== '') $batteryOptions[] = $battery;
    }
    list($whereSql, $params) = tecss_vib_build_where($filters);
    $sql = "SELECT POZO,NOMBRE,BATERIA,ESTADO,ESTADO_ETIQUETA,FECHA_ULTIMO_DATO," .
           "VIBR_ACTUAL,GPM_ACTUAL,VIBR_MEDIANA,VIBR_P25,VIBR_P75,UMBRAL_ALERTA," .
           "UMBRAL_CRITICO,FRECUENCIA_MEDIANA_MIN,N_REGISTROS,ALERTAS_48H,EXCESOS_48H," .
           "SCORE_SEVERIDAD,EXCESO_PROMEDIO_PCT,EXCESO_MAXIMO_PCT,ULTIMA_ALERTA," .
           "ULTIMA_CRITICA,HORAS_ULTIMA_ALERTA,FECHA_ANALISIS,COUNT_BIG(*) OVER() AS TOTAL_FILTRADO " .
           "FROM dbo.CLEAR_TECSS_VIBRACIONES_ESTADO" . $whereSql . tecss_vib_order_sql($sort) .
           " OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    $params[] = $offset;
    $params[] = $pageSize;
    $started = microtime(true);
    $rows = $db->all($sql, $params);
    $queryMs = round((microtime(true) - $started) * 1000, 1);
    if ($rows) $totalFiltered = (int)($rows[0]['TOTAL_FILTRADO'] ?? 0);
    elseif ($db->error()) $error = $db->error();
}

$canViewComments = permissions_can('comments.view');
$commentMap = [];
if ($canViewComments && $rows) {
    $subjects = [];
    foreach ($rows as $sourceRow) {
        $well = tecss_vib_text($sourceRow['POZO'] ?? '');
        if ($well !== '') $subjects[] = clear_alarm_comment_subject($well, 'pozo');
    }
    foreach (array_chunk(array_values(array_unique($subjects)), 1000) as $chunk) {
        foreach (clear_alarm_comments_load_subjects_sql($chunk) as $key => $commentRow) {
            $commentMap[$key] = $commentRow;
        }
    }
}

$totalPages = max(1, (int)ceil($totalFiltered / $pageSize));
if ($page > $totalPages) $page = $totalPages;
$stateLabels = tecss_vib_states();
$sortUrl = static function ($column) use ($sort) {
    $direction = $sort['key'] === $column && $sort['direction'] === 'ASC' ? 'desc' : 'asc';
    return tecss_vib_page_url(['orden' => $column, 'dir' => $direction, 'page' => null]);
};
$sortMark = static function ($column) use ($sort) {
    if ($sort['key'] !== $column) return '↕';
    return $sort['direction'] === 'ASC' ? '▲' : '▼';
};
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Análisis de Vibraciones TECSS · CLEAR</title>
    <link rel="stylesheet" href="assets/css/app.css?v=20260903-vibraciones-1">
    <link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260903-vibraciones-1">
    <link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260903-vibraciones-1">
    <link rel="stylesheet" href="assets/css/tecss_vibraciones.css?v=20260903-vibraciones-3">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/includes/topbar.php'; ?>
        <section class="vib-page">
            <header class="vib-head">
                <div>
                    <div class="vib-eyebrow">Telemetría de Pozos · Procesamiento estadístico</div>
                    <h1>Análisis de Vibraciones TECSS</h1>
                    <p>Vibración y golpes por minuto, con umbrales individuales y detección sostenida por pozo.</p>
                </div>
                <div class="vib-source <?php echo strtolower((string)($lastRun['ESTADO'] ?? '')) === 'error' ? 'is-error' : ''; ?>">
                    <span></span>
                    <?php echo $lastRun ? h('Proceso ' . tecss_vib_text($lastRun['ESTADO'] ?? '') . ' · ' . tecss_vib_date($lastRun['FECHA_FIN'] ?? $lastRun['FECHA_INICIO'] ?? null)) : 'Sin ejecuciones registradas'; ?>
                </div>
            </header>

            <?php if ($error !== ''): ?>
                <div class="vib-message is-error"><?php echo icon('shield'); ?><span><?php echo h($error); ?></span></div>
            <?php elseif ($lastRun && strtolower((string)($lastRun['ESTADO'] ?? '')) === 'error'): ?>
                <div class="vib-message is-error"><?php echo icon('shield'); ?><span>La última ejecución falló: <?php echo h(tecss_vib_text($lastRun['MENSAJE'] ?? 'Error sin detalle')); ?></span></div>
            <?php elseif ((int)$summary['TOTAL'] === 0): ?>
                <div class="vib-message is-info"><?php echo icon('wave'); ?><span>Las tablas están instaladas, pero todavía no hay resultados. Ejecutá una vez la tarea Python.</span></div>
            <?php endif; ?>

            <div class="vib-kpis">
                <a href="<?php echo h(tecss_vib_page_url(['estado'=>null,'page'=>null])); ?>" class="vib-kpi is-total"><span>Total analizados</span><strong><?php echo number_format((int)$summary['TOTAL'], 0, ',', '.'); ?></strong><small>Último dato: <?php echo h(tecss_vib_date($summary['ULTIMO_DATO'] ?? null)); ?></small></a>
                <a href="<?php echo h(tecss_vib_page_url(['estado'=>'critico','page'=>null])); ?>" class="vib-kpi is-critical"><span>Críticos</span><strong><?php echo number_format((int)$summary['CRITICOS'], 0, ',', '.'); ?></strong><small>Requieren atención</small></a>
                <a href="<?php echo h(tecss_vib_page_url(['estado'=>'alerta','page'=>null])); ?>" class="vib-kpi is-alert"><span>En alerta</span><strong><?php echo number_format((int)$summary['ALERTAS'], 0, ',', '.'); ?></strong><small>Monitoreo activo</small></a>
                <a href="<?php echo h(tecss_vib_page_url(['estado'=>'frecuente','page'=>null])); ?>" class="vib-kpi is-frequent"><span>Exceso frecuente</span><strong><?php echo number_format((int)$summary['FRECUENTES'], 0, ',', '.'); ?></strong><small>Picos recurrentes</small></a>
                <a href="<?php echo h(tecss_vib_page_url(['estado'=>'parado','page'=>null])); ?>" class="vib-kpi is-stopped"><span>Parados / Sin señal</span><strong><?php echo number_format((int)$summary['PARADOS'], 0, ',', '.'); ?></strong><small>Últimas lecturas en cero</small></a>
                <a href="<?php echo h(tecss_vib_page_url(['estado'=>'normal','page'=>null])); ?>" class="vib-kpi is-normal"><span>Normales</span><strong><?php echo number_format((int)$summary['NORMALES'], 0, ',', '.'); ?></strong><small>Dentro de límites</small></a>
            </div>

            <form class="vib-toolbar" id="vibFilterForm" method="get" action="tecss_vibraciones.php">
                <input type="hidden" name="pozo" id="vibGridPozoValue" value="<?php echo h($filters['pozo']); ?>">
                <input type="hidden" name="orden" value="<?php echo h($sort['key']); ?>">
                <input type="hidden" name="dir" value="<?php echo h(strtolower($sort['direction'])); ?>">
                <label class="vib-search"><?php echo icon('search'); ?><input type="search" name="q" value="<?php echo h($filters['q']); ?>" placeholder="Buscar pozo o batería..." maxlength="100"></label>
                <label><span>ESTADO</span><select name="estado"><option value="">Todos</option><?php foreach ($stateLabels as $stateKey => $stateLabel): ?><option value="<?php echo h($stateKey); ?>"<?php echo $filters['estado'] === $stateKey ? ' selected' : ''; ?>><?php echo h($stateLabel); ?></option><?php endforeach; ?></select></label>
                <label><span>BATERÍA</span><select name="bateria"><option value="">Todas</option><?php foreach ($batteryOptions as $battery): ?><option value="<?php echo h($battery); ?>"<?php echo $filters['bateria'] === $battery ? ' selected' : ''; ?>><?php echo h($battery); ?></option><?php endforeach; ?></select></label>
                <button class="vib-btn is-primary" type="submit">Aplicar</button>
                <a class="vib-btn" href="tecss_vibraciones.php">Limpiar</a>
                <a class="vib-btn" href="<?php echo h(tecss_vib_export_url()); ?>"><?php echo icon('download'); ?> Excel</a>
                <?php if ($reportEnabled): ?><button class="vib-btn" type="button" data-ns-report-open>Ver reporte <span data-ns-report-count class="nsReportCount" hidden>0</span></button><?php endif; ?>
                <button class="vib-btn" type="button" id="vibReload">Actualizar vista</button>
                <label class="vib-page-size"><span>FILAS</span><select name="filas" id="vibPageSize"><option value="50"<?php echo $pageSize === 50 ? ' selected' : ''; ?>>50</option><option value="100"<?php echo $pageSize === 100 ? ' selected' : ''; ?>>100</option></select></label>
                <div class="vib-columns"><button class="vib-btn" id="vibColumnsBtn" type="button">Columnas ▾</button><div class="vib-columns-menu" id="vibColumnsMenu"></div></div>
            </form>

            <div class="vib-meta">
                <span>Análisis: <b><?php echo h(tecss_vib_date($summary['ULTIMO_ANALISIS'] ?? null)); ?></b></span>
                <span>Consulta local: <b><?php echo h($queryMs); ?> ms</b></span>
                <span>Mostrando <b><?php echo count($rows); ?></b> de <b><?php echo number_format($totalFiltered, 0, ',', '.'); ?></b></span>
                <?php if ($lastRun): ?><span>Origen: <b><?php echo number_format((int)($lastRun['FILAS_ORIGEN'] ?? 0), 0, ',', '.'); ?> filas</b></span><?php endif; ?>
            </div>

            <div class="vib-table-wrap">
                <table class="vib-table<?php echo $reportEnabled ? ' has-report' : ''; ?>" id="vibTable">
                    <thead>
                    <tr class="vib-heading-row">
                        <?php if ($reportEnabled): ?><th data-column="__REPORTE" data-report-label="REPORTE">REPORTE</th><?php endif; ?>
                        <th data-column="DETALLE" data-report-exclude="1">TENDENCIA</th>
                        <th data-column="POZO" data-report-label="POZO"><a class="vib-sort" draggable="false" href="<?php echo h($sortUrl('pozo')); ?>">POZO <span><?php echo h($sortMark('pozo')); ?></span></a></th>
                        <th data-column="BATERIA" data-report-label="BATERÍA"><a class="vib-sort" draggable="false" href="<?php echo h($sortUrl('bateria')); ?>">BATERÍA <span><?php echo h($sortMark('bateria')); ?></span></a></th>
                        <th data-column="ESTADO" data-report-label="ESTADO"><a class="vib-sort" draggable="false" href="<?php echo h($sortUrl('estado')); ?>">ESTADO <span><?php echo h($sortMark('estado')); ?></span></a></th>
                        <th data-column="FECHA_ULTIMO_DATO" data-report-label="ÚLTIMO DATO">ÚLTIMO DATO</th>
                        <th data-column="VIBR_ACTUAL" data-report-label="VIBR ACTUAL">VIBR ACTUAL</th>
                        <th data-column="GPM_ACTUAL" data-report-label="GPM ACTUAL">GPM ACTUAL</th>
                        <th data-column="VIBR_MEDIANA" data-report-label="MEDIANA">MEDIANA</th>
                        <th data-column="UMBRAL_ALERTA" data-report-label="UMBRAL ALERTA">UMBRAL ALERTA</th>
                        <th data-column="UMBRAL_CRITICO" data-report-label="UMBRAL CRÍTICO">UMBRAL CRÍTICO</th>
                        <th data-column="ALERTAS_48H" data-report-label="ALERTAS 48H">ALERTAS 48H</th>
                        <th data-column="EXCESOS_48H" data-report-label="EXCESOS 48H">EXCESOS 48H</th>
                        <th data-column="SCORE_SEVERIDAD" data-report-label="SEVERIDAD">SEVERIDAD</th>
                        <th data-column="ULTIMA_ALERTA" data-report-label="ÚLTIMA ALERTA">ÚLTIMA ALERTA</th>
                        <th data-column="COMENTARIO" data-report-label="COMENTARIO">COMENTARIO</th>
                    </tr>
                    <tr class="vib-filter-row">
                        <?php if ($reportEnabled): ?><th data-column="__REPORTE"></th><?php endif; ?>
                        <th data-column="DETALLE"></th>
                        <th data-column="POZO"><input type="search" id="vibGridPozo" value="<?php echo h($filters['pozo']); ?>" placeholder="Filtrar pozo…" aria-label="Filtrar por pozo"></th>
                        <th data-column="BATERIA"><select id="vibGridBateria" aria-label="Filtrar por batería"><option value="">Todas</option><?php foreach ($batteryOptions as $battery): ?><option value="<?php echo h($battery); ?>"<?php echo $filters['bateria'] === $battery ? ' selected' : ''; ?>><?php echo h($battery); ?></option><?php endforeach; ?></select></th>
                        <th data-column="ESTADO"><select id="vibGridEstado" aria-label="Filtrar por estado"><option value="">Todos</option><?php foreach ($stateLabels as $stateKey => $stateLabel): ?><option value="<?php echo h($stateKey); ?>"<?php echo $filters['estado'] === $stateKey ? ' selected' : ''; ?>><?php echo h($stateLabel); ?></option><?php endforeach; ?></select></th>
                        <th data-column="FECHA_ULTIMO_DATO"></th>
                        <th data-column="VIBR_ACTUAL"></th>
                        <th data-column="GPM_ACTUAL"></th>
                        <th data-column="VIBR_MEDIANA"></th>
                        <th data-column="UMBRAL_ALERTA"></th>
                        <th data-column="UMBRAL_CRITICO"></th>
                        <th data-column="ALERTAS_48H"></th>
                        <th data-column="EXCESOS_48H"></th>
                        <th data-column="SCORE_SEVERIDAD"></th>
                        <th data-column="ULTIMA_ALERTA"></th>
                        <th data-column="COMENTARIO"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td class="vib-empty" colspan="<?php echo 15 + ($reportEnabled ? 1 : 0); ?>">No hay registros para los filtros seleccionados.</td></tr>
                    <?php else: foreach ($rows as $row):
                        $well = tecss_vib_text($row['POZO'] ?? '');
                        $battery = tecss_vib_text($row['BATERIA'] ?? '');
                        $state = strtolower(tecss_vib_text($row['ESTADO'] ?? 'normal'));
                        $commentSubject = clear_alarm_comment_subject($well, 'pozo');
                        $commentRow = $commentSubject !== '' ? ($commentMap[strtoupper($commentSubject)] ?? []) : [];
                        $comment = trim((string)($commentRow['COMENTARIO'] ?? $commentRow['comentario'] ?? ''));
                    ?>
                        <tr class="vib-row is-<?php echo h($state); ?>">
                            <?php if ($reportEnabled): $reportKey = ns_report_key('telemetry_tecss_vibraciones', [$well, $battery]); ?><td data-column="__REPORTE" data-raw=""><label class="nsReportPick" title="Agregar el análisis al reporte"><input type="checkbox" data-ns-report-add data-report-row data-report-key="<?php echo h($reportKey); ?>" data-report-type="row" data-report-title="<?php echo h('Vibraciones TECSS · ' . $well); ?>" data-report-group="telemetry_tecss_vibraciones" data-report-group-title="Análisis de Vibraciones TECSS"><span>Incluir</span></label></td><?php endif; ?>
                            <td data-column="DETALLE" data-raw=""><button type="button" class="vib-detail" data-vib-detail data-well="<?php echo h($well); ?>" data-state="<?php echo h($stateLabels[$state] ?? $state); ?>">Ver tendencia</button></td>
                            <td data-column="POZO" data-raw="<?php echo h($well); ?>"><b class="vib-well"><?php echo h($well); ?></b></td>
                            <td data-column="BATERIA" data-raw="<?php echo h($battery); ?>"><?php echo $battery !== '' ? h($battery) : '<span class="vib-muted">Sin batería</span>'; ?></td>
                            <td data-column="ESTADO" data-raw="<?php echo h($stateLabels[$state] ?? $state); ?>"><span class="vib-status is-<?php echo h($state); ?>"><i></i><?php echo h($stateLabels[$state] ?? $state); ?></span></td>
                            <td data-column="FECHA_ULTIMO_DATO" data-raw="<?php echo h(tecss_vib_text($row['FECHA_ULTIMO_DATO'] ?? '')); ?>"><?php echo h(tecss_vib_date($row['FECHA_ULTIMO_DATO'] ?? null)); ?></td>
                            <td data-column="VIBR_ACTUAL" data-raw="<?php echo h(tecss_vib_text($row['VIBR_ACTUAL'] ?? '')); ?>" class="is-number"><b><?php echo h(tecss_vib_number($row['VIBR_ACTUAL'] ?? null, 1)); ?></b></td>
                            <td data-column="GPM_ACTUAL" data-raw="<?php echo h(tecss_vib_text($row['GPM_ACTUAL'] ?? '')); ?>" class="is-number"><?php echo h(tecss_vib_number($row['GPM_ACTUAL'] ?? null, 1)); ?></td>
                            <td data-column="VIBR_MEDIANA" data-raw="<?php echo h(tecss_vib_text($row['VIBR_MEDIANA'] ?? '')); ?>" class="is-number"><?php echo h(tecss_vib_number($row['VIBR_MEDIANA'] ?? null, 1)); ?></td>
                            <td data-column="UMBRAL_ALERTA" data-raw="<?php echo h(tecss_vib_text($row['UMBRAL_ALERTA'] ?? '')); ?>" class="is-number is-alert-threshold"><?php echo h(tecss_vib_number($row['UMBRAL_ALERTA'] ?? null, 1)); ?></td>
                            <td data-column="UMBRAL_CRITICO" data-raw="<?php echo h(tecss_vib_text($row['UMBRAL_CRITICO'] ?? '')); ?>" class="is-number is-critical-threshold"><?php echo h(tecss_vib_number($row['UMBRAL_CRITICO'] ?? null, 1)); ?></td>
                            <td data-column="ALERTAS_48H" data-raw="<?php echo h(tecss_vib_text($row['ALERTAS_48H'] ?? '0')); ?>" class="is-number"><?php echo number_format((int)($row['ALERTAS_48H'] ?? 0), 0, ',', '.'); ?></td>
                            <td data-column="EXCESOS_48H" data-raw="<?php echo h(tecss_vib_text($row['EXCESOS_48H'] ?? '0')); ?>" class="is-number"><?php echo number_format((int)($row['EXCESOS_48H'] ?? 0), 0, ',', '.'); ?></td>
                            <td data-column="SCORE_SEVERIDAD" data-raw="<?php echo h(tecss_vib_text($row['SCORE_SEVERIDAD'] ?? '0')); ?>" class="is-number"><span class="vib-score"><?php echo h(tecss_vib_number($row['SCORE_SEVERIDAD'] ?? 0, 1)); ?></span></td>
                            <td data-column="ULTIMA_ALERTA" data-raw="<?php echo h(tecss_vib_text($row['ULTIMA_ALERTA'] ?? '')); ?>"><?php echo h(tecss_vib_date($row['ULTIMA_ALERTA'] ?? null)); ?></td>
                            <td data-column="COMENTARIO" data-raw="<?php echo h($canViewComments ? $comment : ''); ?>" data-comment-subject="<?php echo h($commentSubject); ?>"><div class="vib-comment-cell"><?php echo clear_alarm_actions_cell(['display'=>'Comentario','subject'=>$commentSubject,'subject_label'=>'Pozo '.$well,'context'=>'tecss_vibraciones','preserve_pi_link'=>false,'show_history'=>false,'show_comment'=>true,'has_comment'=>$comment!=='']); ?><span data-comment-preview title="<?php echo h($comment); ?>"><?php echo h($canViewComments ? ($comment !== '' ? $comment : 'Sin comentario') : 'Sin permiso'); ?></span></div></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?><nav class="vib-pagination" aria-label="Paginación"><a class="<?php echo $page <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo h(tecss_vib_page_url(['page'=>max(1, $page-1)])); ?>">Anterior</a><span>Página <?php echo $page; ?> de <?php echo $totalPages; ?></span><a class="<?php echo $page >= $totalPages ? 'is-disabled' : ''; ?>" href="<?php echo h(tecss_vib_page_url(['page'=>min($totalPages, $page+1)])); ?>">Siguiente</a></nav><?php endif; ?>
        </section>
    </main>
</div>

<div class="vib-modal" id="vibModal" aria-hidden="true">
    <div class="vib-modal__backdrop" data-vib-close></div>
    <section class="vib-modal__panel" role="dialog" aria-modal="true" aria-labelledby="vibModalTitle">
        <header><div><div class="vib-eyebrow">Detalle estadístico</div><h2 id="vibModalTitle">Tendencia del pozo</h2><p id="vibModalState"></p></div><button type="button" data-vib-close aria-label="Cerrar">×</button></header>
        <form class="vib-modal__filters" id="vibDetailForm"><input type="hidden" id="vibDetailWell"><label><span>DESDE</span><input type="date" id="vibDetailFrom" required></label><label><span>HASTA</span><input type="date" id="vibDetailTo" required></label><button class="vib-btn is-primary" type="submit">Aplicar</button></form>
        <div class="vib-modal__status" id="vibDetailStatus"></div>
        <div class="vib-modal__metrics" id="vibDetailMetrics"></div>
        <article class="vib-modal__chart-card nsCard" id="vibChartCard">
            <div class="vib-chart-actions">
                <div><strong>Gráfico de tendencia</strong><span id="vibChartRange">Seleccioná un período</span></div>
                <div>
                    <button class="vib-btn" type="button" id="vibChartMaximize" disabled>Maximizar</button>
                    <button class="vib-btn" type="button" id="vibChartExport" disabled>Descargar PNG</button>
                    <?php if ($reportEnabled): ?>
                        <label class="vib-chart-report"><input type="checkbox" id="vibChartReport" data-ns-report-add data-report-key="tecss-vibraciones-chart-pending" data-report-type="chart" data-report-title="Tendencia de Vibraciones TECSS" data-report-payload="<?php echo h(base64_encode(json_encode(['kind'=>'chart'], JSON_UNESCAPED_UNICODE))); ?>" disabled><span>Agregar al reporte</span></label>
                        <button class="vib-btn" type="button" data-ns-report-open>Ver reporte</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="vib-modal__chart"><canvas id="vibDetailChart"></canvas><div class="vib-modal__empty" id="vibDetailEmpty">No hay datos en el período seleccionado.</div></div>
        </article>
        <div class="vib-modal__events"><h3>Alertas y picos detectados</h3><div id="vibDetailEvents"></div></div>
    </section>
</div>

<?php clear_alarm_actions_modal(); ?>
<script src="assets/js/app.js?v=20260903-vibraciones-1"></script>
<script src="assets/js/chart.umd.js"></script>
<script src="assets/js/alarm_actions.js?v=20260903-vibraciones-1"></script>
<script src="assets/js/tecss_vibraciones.js?v=20260903-vibraciones-3"></script>
<?php if ($reportEnabled): ?><script>window.CLEAR_WEEKLY_NEWS={};</script><script src="assets/js/novedades_semanales.js?v=20260903-vibraciones-1"></script><?php endif; ?>
</body>
</html>
