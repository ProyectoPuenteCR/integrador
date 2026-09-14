<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/tecss_3sigma_query.php';
require_once __DIR__ . '/includes/alarm_actions.php';
require_once __DIR__ . '/includes/novedades_semanales_common.php';

auth_require();
permissions_require_menu('tecss_3sigma');

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'America/Argentina/Buenos_Aires');
$APP_USER = auth_user() ?: 'CLEAR';
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = 'tecss_3sigma';
$db = clear_db();
$reportEnabled=$db->ok()&&ns_report_ready($db)&&permissions_can_menu('novedades_semanales_reporte');

$columns = sigma_columns();
$filterMap = sigma_filter_map();
$filters = sigma_filters_from_request();
$globalSearch = trim((string)($_GET['q'] ?? ''));
$requestedZone = trim((string)($_GET['zona'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = (int)($_GET['filas'] ?? 50);
if (!in_array($pageSize, [50,100], true)) $pageSize = 50;
$offset = ($page - 1) * $pageSize;

$rows = [];
$filterOptions = [];
$zoneOptions = [];
$summary = ['TOTAL_POZOS'=>0,'SIN_ANALISIS'=>0,'CON_EXCESOS'=>0,'MAX_EXCESOS'=>null,'ULTIMA_CACHE'=>null];
$totalFiltered = 0;
$error = '';
$queryMs = 0;
$selectedZone = '';

if (!$db->ok()) {
    $error = $db->error();
} elseif (!sigma_cache_exists($db)) {
    $error = 'Falta instalar la caché de 3Sigma TECSS. Ejecutá SQL/CLEAR_TECSS_3SIGMA_CACHE_JOB.sql una sola vez.';
} else {
    $zoneOptions = clear_zones_all($db);
    list($whereSql, $whereParams, $selectedZone) = sigma_build_where($db, $filters, $globalSearch, $requestedZone, $zoneOptions);
    $filterOptions = sigma_filter_options($db);

    $summaryRows = $db->all(
        "SELECT COUNT_BIG(*) AS TOTAL_POZOS,
                SUM(CASE WHEN NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255),[3SIGMA]))),'') IS NULL THEN 1 ELSE 0 END) AS SIN_ANALISIS,
                SUM(CASE WHEN [CONT_EXCESOS_NUM]>0 THEN 1 ELSE 0 END) AS CON_EXCESOS,
                MAX([CONT_EXCESOS_NUM]) AS MAX_EXCESOS,
                MAX([FECHA_CACHE]) AS ULTIMA_CACHE
         FROM dbo.CLEAR_CACHE_TECSS_3SIGMA"
    );
    if ($summaryRows) $summary = array_merge($summary, $summaryRows[0]);

    $select = implode(',', array_map('sigma_q', $columns));
    $sql = "SELECT $select,COUNT_BIG(*) OVER() AS TOTAL_FILTRADO
            FROM dbo.CLEAR_CACHE_TECSS_3SIGMA$whereSql
            ORDER BY CASE WHEN [CONT_EXCESOS_NUM] IS NULL THEN 1 ELSE 0 END,[CONT_EXCESOS_NUM] DESC,
                     TRY_CONVERT(datetime2,[HOY]) DESC,[POZO_BUSQUEDA]
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    $whereParams[] = $offset;
    $whereParams[] = $pageSize;
    $started = microtime(true);
    $rows = $db->all($sql, $whereParams);
    $queryMs = round((microtime(true) - $started) * 1000, 1);
    if ($rows) $totalFiltered = (int)($rows[0]['TOTAL_FILTRADO'] ?? 0);
    elseif ($db->error()) $error = $db->error();
}

$canViewSigmaComments=permissions_can('comments.view');
$sigmaCommentMap=[];
if($canViewSigmaComments&&$rows){
    $sigmaSubjects=[];
    foreach($rows as $sigmaCommentSource){
        $sigmaWell=sigma_text($sigmaCommentSource['POZO']??'');
        if($sigmaWell!=='')$sigmaSubjects[]=clear_alarm_comment_subject($sigmaWell,'pozo');
    }
    foreach(array_chunk(array_values(array_unique($sigmaSubjects)),1000) as $sigmaSubjectChunk){
        foreach(clear_alarm_comments_load_subjects_sql($sigmaSubjectChunk) as $sigmaCommentKey=>$sigmaCommentRow)$sigmaCommentMap[$sigmaCommentKey]=$sigmaCommentRow;
    }
}

$totalPages = max(1, (int)ceil($totalFiltered / $pageSize));
if ($page > $totalPages) $page = $totalPages;

function sigma_header_filter($column, array $spec, array $filters, array $options)
{
    $value = (string)($filters[$column] ?? '');
    $param = $spec['param'];
    if ($spec['type'] === 'text') {
        echo '<input class="sigma-col-filter" type="search" name="' . h($param) . '" value="' . h($value) . '" placeholder="Filtrar pozo" maxlength="100">';
        return;
    }
    echo '<select class="sigma-col-filter" name="' . h($param) . '"><option value="">Todos</option>';
    if ($spec['type'] === 'sigma') {
        echo '<option value="con"' . ($value==='con'?' selected':'') . '>Con análisis</option><option value="sin"' . ($value==='sin'?' selected':'') . '>Sin análisis</option>';
    } elseif ($spec['type'] === 'excess') {
        echo '<option value="con"' . ($value==='con'?' selected':'') . '>Con excesos</option><option value="sin"' . ($value==='sin'?' selected':'') . '>Sin excesos</option>';
    } elseif ($spec['type'] === 'link') {
        echo '<option value="con"' . ($value==='con'?' selected':'') . '>Con pantalla</option><option value="sin"' . ($value==='sin'?' selected':'') . '>Sin pantalla</option>';
    } else {
        $columnOptions = $options[$column] ?? [];
        if ($value!=='' && !in_array($value,$columnOptions,true)) array_unshift($columnOptions,$value);
        foreach ($columnOptions as $option) {
            echo '<option value="' . h($option) . '"' . ($value===$option?' selected':'') . '>' . h($option) . '</option>';
        }
    }
    echo '</select>';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>3Sigma TECSS · CLEAR</title>
    <link rel="stylesheet" href="assets/css/app.css?v=20260902-column-menu-1">
	    <link rel="stylesheet" href="assets/css/telemetry_modal.css?v=3.1.9">
	    <link rel="stylesheet" href="assets/css/tecss_3sigma.css?v=20260902-column-menu-1">
	    <link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260901-report-grid-1">
	    <link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260901-report-grid-1">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/includes/topbar.php'; ?>
        <section class="sigma-page">
            <header class="sigma-head">
                <div><div class="sigma-eyebrow">Telemetría de Pozos</div><h1>3Sigma TECSS</h1><p>Último valor registrado por pozo · fuente SQL local</p></div>
                <div class="sigma-source"><span class="sigma-source__dot"></span>Caché: <?php echo h(sigma_date($summary['ULTIMA_CACHE'] ?? null)); ?></div>
            </header>

            <?php if ($error !== ''): ?><div class="sigma-error"><?php echo icon('shield'); ?><span><?php echo h($error); ?></span></div><?php endif; ?>

            <form id="sigmaFilterForm" method="get" action="tecss_3sigma.php">
                <div class="sigma-toolbar">
                    <label class="sigma-zone"><span>ZONA</span><select name="zona"><option value="">Todas</option><?php foreach ($zoneOptions as $zone): ?><option value="<?php echo h($zone); ?>"<?php echo $selectedZone===$zone?' selected':''; ?>><?php echo h($zone); ?></option><?php endforeach; ?></select></label>
                    <label class="sigma-search"><?php echo icon('search'); ?><input type="search" name="q" value="<?php echo h($globalSearch); ?>" placeholder="Buscar en toda la grilla..." maxlength="100"></label>
                    <button class="sigma-btn sigma-btn--primary" type="submit">Aplicar</button>
                    <a class="sigma-btn" href="tecss_3sigma.php">Limpiar filtros</a>
	                    <a class="sigma-btn" href="<?php echo h(sigma_export_url()); ?>"><?php echo icon('download'); ?> Exportar Excel</a>
	                    <?php if($reportEnabled): ?><button class="sigma-btn" type="button" data-ns-report-open>Ver reporte <span data-ns-report-count class="nsReportCount" hidden>0</span></button><?php endif; ?>
                    <button class="sigma-btn" id="sigmaRefresh" type="button">Actualizar</button>
                    <label class="sigma-page-size"><span>FILAS</span><select name="filas" id="sigmaPageSize"><option value="50"<?php echo $pageSize===50?' selected':''; ?>>50</option><option value="100"<?php echo $pageSize===100?' selected':''; ?>>100</option></select></label>
                    <select class="sigma-interval" id="sigmaInterval" aria-label="Actualización automática"><option value="60">Cada 1 minuto</option><option value="300">Cada 5 minutos</option><option value="600">Cada 10 minutos</option><option value="0">Sin actualizar</option></select>
                    <div class="sigma-columns"><button class="sigma-btn" id="sigmaColumnsBtn" type="button">Columnas ▾</button><div class="sigma-columns-menu" id="sigmaColumnsMenu"></div></div>
                    <span class="sigma-count">Mostrando <?php echo count($rows); ?> de <?php echo number_format($totalFiltered,0,',','.'); ?> visibles</span>
                </div>

                <div class="sigma-kpis">
                    <article class="sigma-kpi sigma-kpi--main"><span class="sigma-kpi__icon"><?php echo icon('monitor'); ?></span><div><strong><?php echo number_format((int)$summary['TOTAL_POZOS'],0,',','.'); ?></strong><span>Pozos analizados</span></div></article>
                    <a class="sigma-kpi" href="<?php echo h(sigma_page_url(['sigma'=>'sin','page'=>null])); ?>"><span class="sigma-kpi__icon sigma-kpi__icon--muted"><?php echo icon('file'); ?></span><div><strong><?php echo number_format((int)$summary['SIN_ANALISIS'],0,',','.'); ?></strong><span>Sin análisis</span></div></a>
                    <a class="sigma-kpi sigma-kpi--warn" href="<?php echo h(sigma_page_url(['excesos'=>'con','page'=>null])); ?>"><span class="sigma-kpi__icon"><?php echo icon('gauge'); ?></span><div><strong><?php echo number_format((int)$summary['CON_EXCESOS'],0,',','.'); ?></strong><span>Con excesos</span></div></a>
                    <article class="sigma-kpi sigma-kpi--danger"><span class="sigma-kpi__icon"><?php echo icon('chart'); ?></span><div><strong><?php echo h(sigma_number($summary['MAX_EXCESOS'],0)); ?></strong><span>Mayor CONT_EXCESOS</span></div></article>
                </div>

                <div class="sigma-meta"><span>Consulta local: <?php echo h($queryMs); ?> ms</span><span>Una fila por pozo</span><span>Orden: excesos de mayor a menor</span></div>

                <div class="sigma-table-wrap">
	                    <table class="sigma-table<?php echo $reportEnabled?' has-report':''; ?>" id="sigmaTable">
	                        <thead><tr>
	                        <?php if($reportEnabled): ?><th data-column="__REPORTE" data-report-label="REPORTE"><div class="sigma-th-title">REPORTE</div></th><?php endif; ?>
	                        <?php foreach ($columns as $column): ?>
	                            <th data-column="<?php echo h($column); ?>" data-report-label="<?php echo h($column); ?>"<?php echo $column==='PANTALLA'?' data-report-exclude="1"':''; ?>><div class="sigma-th-title"><?php echo h($column); ?> <span>↕</span></div><?php sigma_header_filter($column,$filterMap[$column],$filters,$filterOptions); ?></th>
	                        <?php endforeach; ?>
	                            <th data-column="COMENTARIO" data-report-label="COMENTARIO"><div class="sigma-th-title">COMENTARIO <span>↕</span></div></th>
	                        </tr></thead>
	                        <tbody>
	                        <?php if (!$rows): ?>
	                            <tr><td class="sigma-empty" colspan="<?php echo count($columns)+1+($reportEnabled?1:0); ?>">No hay registros para los filtros seleccionados.</td></tr>
	                        <?php else: foreach ($rows as $row):
	                            $well = sigma_text($row['POZO'] ?? '');
	                            $sigmaRaw = sigma_text($row['3SIGMA'] ?? null);
	                            $excessRaw = sigma_text($row['CONT_EXCESOS'] ?? null);
	                            $excessNum = is_numeric(str_replace(',','.',$excessRaw)) ? (float)str_replace(',','.',$excessRaw) : 0;
	                            $sigmaCommentSubject=clear_alarm_comment_subject($well,'pozo');
	                            $sigmaCommentRow=$sigmaCommentSubject!==''?($sigmaCommentMap[strtoupper($sigmaCommentSubject)]??[]):[];
	                            $sigmaComment=trim((string)($sigmaCommentRow['COMENTARIO']??$sigmaCommentRow['comentario']??''));
	                        ?>
	                            <tr class="<?php echo $excessNum>0?'has-excess':''; ?>">
	                            <?php if($reportEnabled):$sigmaReportKey=ns_report_key('telemetry_tecss_3sigma',[$well,sigma_text($row['BATERIA']??'')]); ?><td data-column="__REPORTE" data-raw=""><label class="nsReportPick" title="Agregar el estado 3Sigma al reporte"><input type="checkbox" data-ns-report-add data-report-row data-report-key="<?php echo h($sigmaReportKey); ?>" data-report-type="row" data-report-title="<?php echo h('3Sigma TECSS · '.$well); ?>" data-report-group="telemetry_tecss_3sigma" data-report-group-title="3Sigma TECSS"><span>Incluir</span></label></td><?php endif; ?>
	                            <?php foreach ($columns as $column): $raw=sigma_text($row[$column]??null); ?>
                                <td data-column="<?php echo h($column); ?>" data-raw="<?php echo h($raw); ?>" class="<?php echo in_array($column,['3SIGMA','CONT_EXCESOS','FC','PI-005-PL','VI-001-V','SI-002-SPM','YL-007-WS','QT:GOLPES-MIN','TI-002-TAE','TI-003-TBP','TI-004-TE'],true)?'is-number':''; ?>">
                                <?php if ($column==='POZO'): ?>
                                    <span class="sigma-well"><b><?php echo h($well); ?></b><?php if ($well!==''): ?><a class="sigma-icon-link" href="tecss_3sigma_historico.php?<?php echo h(http_build_query(['pozo'=>$well])); ?>" data-telemetry-modal-url="tecss_3sigma_historico.php?<?php echo h(http_build_query(['pozo'=>$well,'embedded'=>'1'])); ?>" data-telemetry-modal-open-url="tecss_3sigma_historico.php?<?php echo h(http_build_query(['pozo'=>$well])); ?>" data-telemetry-modal-title="<?php echo h('Histórico 3Sigma · '.$well); ?>" data-telemetry-modal-subtitle="Tendencia registrada en la tabla histórica SQL" data-telemetry-modal-eyebrow="Histórico 3Sigma" title="Ver histórico 3Sigma del pozo"><?php echo icon('chart'); ?></a><?php endif; ?></span>
                                <?php elseif ($column==='PANTALLA'): ?>
                                    <?php if (sigma_is_link($raw)): ?><a class="sigma-icon-link" href="<?php echo h($raw); ?>" target="_blank" rel="noopener" data-telemetry-popup-url="<?php echo h($raw); ?>" data-telemetry-popup-name="CLEAR_PI_VISION" title="Abrir pantalla"><?php echo icon('monitor'); ?></a><?php else: ?><span class="sigma-muted">—</span><?php endif; ?>
                                <?php elseif ($column==='3SIGMA'): ?>
                                    <?php if ($sigmaRaw===''): ?><span class="sigma-badge sigma-badge--empty">Sin análisis</span><?php else: ?><span class="sigma-badge <?php echo $excessNum>0?'sigma-badge--warn':'sigma-badge--ok'; ?>"><?php echo h(sigma_number($sigmaRaw,4)); ?></span><?php endif; ?>
                                <?php elseif ($column==='CONT_EXCESOS'): ?>
                                    <?php if ($excessRaw===''): ?><span class="sigma-muted">—</span><?php else: ?><span class="sigma-excess <?php echo $excessNum>0?'is-positive':''; ?>"><?php echo h(sigma_number($excessRaw,0)); ?></span><?php endif; ?>
                                <?php elseif ($column==='HOY'): ?><?php echo h(sigma_date($raw)); ?>
                                <?php elseif (in_array($column,['FC','PI-005-PL','VI-001-V','SI-002-SPM','YL-007-WS','QT:GOLPES-MIN','TI-002-TAE','TI-003-TBP','TI-004-TE'],true)): ?><?php echo h(sigma_number($raw,2)); ?>
                                <?php else: echo $raw===''?'<span class="sigma-muted">—</span>':h($raw); endif; ?>
	                                </td>
	                            <?php endforeach; ?>
	                            <td data-column="COMENTARIO" data-raw="<?php echo h($canViewSigmaComments?$sigmaComment:''); ?>" data-comment-subject="<?php echo h($sigmaCommentSubject); ?>"><div class="sigma-comment-cell"><?php echo clear_alarm_actions_cell(['display'=>'Comentario','subject'=>$sigmaCommentSubject,'subject_label'=>'Pozo '.$well,'context'=>'tecss_3sigma','preserve_pi_link'=>false,'show_history'=>false,'show_comment'=>true,'has_comment'=>$sigmaComment!=='']); ?><span data-comment-preview title="<?php echo h($sigmaComment); ?>"><?php echo h($canViewSigmaComments?($sigmaComment!==''?$sigmaComment:'Sin comentario'):'Sin permiso'); ?></span></div></td>
	                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <?php if ($totalPages>1): ?><nav class="sigma-pagination" aria-label="Paginación"><a class="<?php echo $page<=1?'is-disabled':''; ?>" href="<?php echo h(sigma_page_url(['page'=>max(1,$page-1)])); ?>">Anterior</a><span>Página <?php echo $page; ?> de <?php echo $totalPages; ?></span><a class="<?php echo $page>=$totalPages?'is-disabled':''; ?>" href="<?php echo h(sigma_page_url(['page'=>min($totalPages,$page+1)])); ?>">Siguiente</a></nav><?php endif; ?>
        </section>
    </main>
</div>
	<?php include __DIR__ . '/includes/telemetry_modal.php'; ?>
	<?php clear_alarm_actions_modal(); ?>
	<script src="assets/js/app.js?v=20260812-sigma2"></script>
	<script src="assets/js/telemetry_modal.js?v=3.1.9"></script>
	<script src="assets/js/tecss_3sigma.js?v=20260812-sigma2"></script>
	<script src="assets/js/alarm_actions.js?v=20260901-report-grid-1"></script>
	<?php if($reportEnabled): ?><script>window.CLEAR_WEEKLY_NEWS={};</script><script src="assets/js/novedades_semanales.js?v=20260901-report-grid-1"></script><?php endif; ?>
</body>
</html>
