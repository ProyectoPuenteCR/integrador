<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/zones.php';
require_once __DIR__ . '/includes/alarm_actions.php';
require_once __DIR__ . '/includes/installation_type.php';

auth_require(); permissions_require_menu('todas_alarmas');

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = 'todas_alarmas';

$db = clear_db();
$zones=clear_zones_all($db);
$zoneFilter=clear_zone_valid((string)($_GET['zona']??''),$zones);
$dbError = $db->ok() ? '' : $db->error();
$table = 'dbo.FIXALARMS';
$page = max(1, (int)($_GET['p'] ?? 1));
$allowedPerPage = ['50', '100', 'all'];
$perPageParam = strtolower(trim((string)($_GET['per_page'] ?? '50')));
if (!in_array($perPageParam, $allowedPerPage, true)) $perPageParam = '50';
$showAllRows = $perPageParam === 'all';
$perPage = $showAllRows ? 0 : (int)$perPageParam;
if ($showAllRows) $page = 1;
$tagSearch = trim((string)($_GET['tag'] ?? ''));
$descSearch = trim((string)($_GET['descripcion'] ?? ''));
$dateFrom = trim((string)($_GET['fecha_desde'] ?? ''));
$dateTo = trim((string)($_GET['fecha_hasta'] ?? ''));
$timeFrom = trim((string)($_GET['hora_desde'] ?? ''));
$timeTo = trim((string)($_GET['hora_hasta'] ?? ''));
$installationTypeFilter = clear_installation_type_normalize($_GET['tipo_instalacion'] ?? '');
$installationTypeOptions = clear_installation_type_options();

$allowedMsgTypes = ['OPERATOR','TEXT','ALARM','NETWORK'];
$msgTypeParamPresent = array_key_exists('msgtype', $_GET);
$physNodeParamPresent = array_key_exists('physnode', $_GET);
$msgTypeFilter = $msgTypeParamPresent ? strtoupper(trim((string)$_GET['msgtype'])) : strtoupper(trim((string)user_pref_get('todas_alarmas_msgtype', '')));
$physNodeFilter = $physNodeParamPresent ? trim((string)$_GET['physnode']) : trim((string)user_pref_get('todas_alarmas_physnode', ''));
if ($msgTypeFilter === '__ALL__' || !in_array($msgTypeFilter, $allowedMsgTypes, true)) $msgTypeFilter = '';
if ($physNodeFilter === '__ALL__') $physNodeFilter = '';
if ($msgTypeParamPresent) user_pref_set('todas_alarmas_msgtype', $msgTypeFilter);
if ($physNodeParamPresent) user_pref_set('todas_alarmas_physnode', $physNodeFilter);

function ta_valid_date($value) {
    if ($value === '') return '';
    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    return $dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $dt->format('Y-m-d') === $value ? $value : '';
}
function ta_valid_time($value) {
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : '';
}
function ta_terms($value) {
    $value = trim((string)$value);
    if ($value === '') return [];
    $parts = preg_split('/\s+/', $value);
    return array_values(array_filter(array_map('trim', $parts), function ($v) { return $v !== ''; }));
}
function ta_url_with(array $changes) {
    $params = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($params[$key]); else $params[$key] = $value;
    }
    unset($params['p']);
    return 'todas_alarmas.php?' . http_build_query($params);
}
function ta_format_value($value) {
    if ($value === null || $value === '') return '—';
    return (string)$value;
}

$dateFrom = ta_valid_date($dateFrom);
$dateTo = ta_valid_date($dateTo);
$timeFrom = ta_valid_time($timeFrom);
$timeTo = ta_valid_time($timeTo);
if ($dateFrom !== '' && $timeFrom === '') $timeFrom = '00:00';
if ($dateTo !== '' && $timeTo === '') $timeTo = '23:59';
if ($dateFrom !== '' && $dateTo !== '' && ($dateFrom . ' ' . $timeFrom) > ($dateTo . ' ' . $timeTo)) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    [$timeFrom, $timeTo] = [$timeTo, $timeFrom];
}

$columns = [];
$rows = [];
$total = 0;
$where = '';
$params = [];
$dateColumn = 'ALM_NATIVETIMEIN';
$tagColumn = 'ALM_TAGNAME';
$descriptionColumns = [];
$msgTypeColumn = 'ALM_MSGTYPE';
$physNodeColumn = 'ALM_PHYSNODE';
$msgTypeCounts = array_fill_keys($allowedMsgTypes, 0);
$physNodeOptions = [];
$installationTypeExpr = '';
$displayColumns = [];
$commentKeys = [];

if ($db->ok()) {
    $metaRows = $db->all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'FIXALARMS' ORDER BY ORDINAL_POSITION");
    foreach ($metaRows as $metaRow) {
        $column = trim((string)($metaRow['COLUMN_NAME'] ?? $metaRow['column_name'] ?? reset($metaRow)));
        if ($column !== '') $columns[] = $column;
    }
    // Respaldo para conexiones donde INFORMATION_SCHEMA no devuelva metadatos.
    if (!$columns) {
        $sample = $db->all("SELECT TOP 1 * FROM $table");
        if (!empty($sample)) $columns = array_keys($sample[0]);
    }

    $lookup = array_change_key_case(array_combine($columns, $columns), CASE_UPPER);
    if (!isset($lookup['ALM_NATIVETIMEIN'])) {
        foreach (['ALM_NATIVETIMELAST', 'FECHA_HORA', 'FECHA'] as $candidate) {
            if (isset($lookup[$candidate])) { $dateColumn = $lookup[$candidate]; break; }
        }
    }
    if (!isset($lookup['ALM_TAGNAME'])) {
        foreach (['TAG_FIX', 'TAG', 'TAGID'] as $candidate) {
            if (isset($lookup[$candidate])) { $tagColumn = $lookup[$candidate]; break; }
        }
    }
    foreach (['ALM_TAGDESC', 'ALM_DESCR', 'DESCRIPCION', 'DESCRIPCIÓN'] as $candidate) {
        if (isset($lookup[$candidate])) $descriptionColumns[] = $lookup[$candidate];
    }
    $msgTypeColumn = $lookup['ALM_MSGTYPE'] ?? $msgTypeColumn;
    $physNodeColumn = $lookup['ALM_PHYSNODE'] ?? ($lookup['ALM_PHYSLNODE'] ?? $physNodeColumn);
    $externalTypeColumn = $lookup['ALM_ALMEXTFLD2'] ?? '';
    if (in_array($tagColumn, $columns, true)) {
        $installationTypeExpr = clear_installation_type_sql('[' . $tagColumn . ']', $externalTypeColumn !== '' ? '[' . $externalTypeColumn . ']' : "N''");
    }

    if (in_array($physNodeColumn, $columns, true)) {
        $nodeRows = $db->all("SELECT DISTINCT LTRIM(RTRIM(CONVERT(nvarchar(255), [$physNodeColumn]))) AS node_value FROM $table WHERE [$physNodeColumn] IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255), [$physNodeColumn]))) <> '' ORDER BY node_value");
        foreach ($nodeRows as $nodeRow) {
            $nodeValue = trim((string)($nodeRow['node_value'] ?? $nodeRow['NODE_VALUE'] ?? reset($nodeRow)));
            if ($nodeValue !== '') $physNodeOptions[] = $nodeValue;
        }
        if ($physNodeFilter !== '' && !in_array($physNodeFilter, $physNodeOptions, true)) $physNodeFilter = '';
    } else {
        $physNodeFilter = '';
    }

    $conditions = [];
    if ($tagSearch !== '' && in_array($tagColumn, $columns, true)) {
        foreach (ta_terms($tagSearch) as $term) {
            $conditions[] = "(CONVERT(nvarchar(4000), [$tagColumn]) LIKE ? OR REPLACE(REPLACE(REPLACE(CONVERT(nvarchar(4000), [$tagColumn]), '_', ''), '-', ''), ' ', '') LIKE ?)";
            $params[] = '%' . $term . '%';
            $params[] = '%' . str_replace(['_', '-', ' '], '', $term) . '%';
        }
    }
    if ($descSearch !== '' && $descriptionColumns) {
        foreach (ta_terms($descSearch) as $term) {
            $or = [];
            foreach ($descriptionColumns as $descColumn) {
                $or[] = "CONVERT(nvarchar(4000), [$descColumn]) LIKE ?";
                $params[] = '%' . $term . '%';
            }
            $conditions[] = '(' . implode(' OR ', $or) . ')';
        }
    }
    if ($physNodeFilter !== '' && in_array($physNodeColumn, $columns, true)) {
        $conditions[] = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$physNodeColumn]))) = ?";
        $params[] = $physNodeFilter;
    }
    if ($installationTypeFilter !== '' && $installationTypeExpr !== '') {
        $conditions[] = "$installationTypeExpr = ?";
        $params[] = $installationTypeFilter;
    }
    if ($dateFrom !== '' && in_array($dateColumn, $columns, true)) {
        $conditions[] = "[$dateColumn] >= ?";
        $params[] = $dateFrom . ' ' . $timeFrom . ':00';
    }
    if ($dateTo !== '' && in_array($dateColumn, $columns, true)) {
        $conditions[] = "[$dateColumn] <= ?";
        $params[] = $dateTo . ' ' . $timeTo . ':59.997';
    }

    if (in_array($msgTypeColumn, $columns, true)) {
        $facetWhere = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $msgExpr = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100), [$msgTypeColumn]))))";
        $facetRows = $db->all("SELECT $msgExpr AS msg_type, COUNT(*) AS total FROM $table $facetWhere " . ($facetWhere !== '' ? 'AND' : 'WHERE') . " $msgExpr IN ('OPERATOR','TEXT','ALARM','NETWORK') GROUP BY $msgExpr", $params);
        foreach ($facetRows as $facetRow) {
            $type = strtoupper(trim((string)($facetRow['msg_type'] ?? $facetRow['MSG_TYPE'] ?? '')));
            if (array_key_exists($type, $msgTypeCounts)) $msgTypeCounts[$type] = (int)($facetRow['total'] ?? $facetRow['TOTAL'] ?? 0);
        }
        if ($msgTypeFilter !== '') {
            $conditions[] = "$msgExpr = ?";
            $params[] = $msgTypeFilter;
        }
    } else {
        $msgTypeFilter = '';
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $total = (int)$db->scalar("SELECT COUNT(*) FROM $table $where", $params);
    $offset = ($page - 1) * $perPage;
    $orderBy = in_array($dateColumn, $columns, true) ? "[$dateColumn] DESC" : '[' . ($columns[0] ?? 'ALM_TAGNAME') . '] DESC';
    $dataSql = "SELECT " . ($installationTypeExpr !== '' ? "$installationTypeExpr AS [__TIPO_INSTALACION]," : '') . "* FROM $table $where ORDER BY $orderBy";
    if (!$showAllRows) $dataSql .= " OFFSET $offset ROWS FETCH NEXT $perPage ROWS ONLY";
    $rows = $db->all($dataSql, $params);
    if (permissions_can('comments.view') || permissions_can('comments.create')) {
        $visibleCommentTags = [];
        foreach ($rows as $commentRow) {
            $commentTag = trim((string)($commentRow[$tagColumn] ?? ''));
            if ($commentTag !== '') $visibleCommentTags[] = $commentTag;
        }
        $commentKeys = clear_alarm_comments_load_subjects_sql($visibleCommentTags);
    }
}

$displayColumns = $installationTypeExpr !== '' ? array_merge(['__TIPO_INSTALACION'], $columns) : $columns;

$totalPages = $showAllRows ? 1 : max(1, (int)ceil($total / max(1,$perPage)));
$hasFilters = $tagSearch !== '' || $descSearch !== '' || $dateFrom !== '' || $dateTo !== '' || $msgTypeFilter !== '' || $physNodeFilter !== '' || $installationTypeFilter !== '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Todas las alarmas · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260803-grid1">
  <link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260807-2">
  <link rel="stylesheet" href="assets/css/installation_type.css?v=20260824-2">
  <style>
    .taFacetBar{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,.48fr);gap:12px;margin:0 0 14px}
    .taFacetCard{background:#fff;border:1px solid var(--line-mid);border-radius:12px;padding:13px 15px}
    .taFacetTitle{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--text-mut);margin-bottom:10px}
    .taFacetChips{display:flex;gap:8px;flex-wrap:wrap}.taFacetChip{display:inline-flex;align-items:center;gap:8px;text-decoration:none;border:1px solid var(--line-mid);border-radius:999px;padding:8px 11px;background:#f8fbfc;color:var(--petrol);font-size:12px;font-weight:700}.taFacetChip.is-active{background:var(--petrol);border-color:var(--petrol);color:#fff}.taFacetChip b{min-width:23px;height:23px;padding:0 6px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:rgba(26,77,92,.09);font-size:11px}.taFacetChip.is-active b{background:rgba(255,255,255,.2)}
    .taNodeSelect{display:flex;align-items:center;gap:8px;border:1px solid var(--line-mid);border-radius:9px;padding:0 10px;height:40px}.taNodeSelect select{width:100%;border:0;background:transparent;outline:0;color:var(--text);font:inherit}
    @media(max-width:900px){.taFacetBar{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page__head">
      <div>
        <h1 class="page__title">Todas las alarmas</h1>
        <div class="page__sub">Histórico completo de dbo.FIXALARMS · más nuevas primero</div>
      </div>
      <div class="page__live"><span class="dot"></span><?php echo $dbError ? 'Sin conexión' : 'En vivo'; ?></div>
    </div>

    <?php if ($dbError): ?>
      <div class="tablewrap"><div class="empty"><p><b>Sin conexión a la base:</b> <?php echo h($dbError); ?></p></div></div>
    <?php else: ?>
      <form class="allAlarmsToolbar" method="get" action="todas_alarmas.php" id="allAlarmsFilters"><label class="allAlarmsSearch"><span>ZONA</span><select name="zona" onchange="this.form.submit()"><option value="">Todas</option><?php foreach($zones as $z): ?><option value="<?php echo h($z); ?>"<?php echo $zoneFilter===$z?' selected':''; ?>><?php echo h($z); ?></option><?php endforeach; ?></select></label>
        <label class="allAlarmsSearch">
          <?php echo icon('search'); ?>
          <span>TAG</span>
          <input type="text" name="tag" value="<?php echo h($tagSearch); ?>" placeholder="Buscar aproximado por tag…" autocomplete="off">
        </label>
        <label class="allAlarmsSearch allAlarmsSearch--wide">
          <?php echo icon('search'); ?>
          <span>Descripción</span>
          <input type="text" name="descripcion" value="<?php echo h($descSearch); ?>" placeholder="Buscar palabras en la descripción…" autocomplete="off">
        </label>
        <label class="allAlarmsSearch">
          <?php echo icon('map'); ?>
          <span>ALM_PHYSNODE</span>
          <select name="physnode" aria-label="Filtrar por ALM_PHYSNODE">
            <option value="__ALL__">Todos</option>
            <?php foreach ($physNodeOptions as $nodeOption): ?><option value="<?php echo h($nodeOption); ?>" <?php echo $physNodeFilter === $nodeOption ? 'selected' : ''; ?>><?php echo h($nodeOption); ?></option><?php endforeach; ?>
          </select>
        </label>
        <input type="hidden" name="msgtype" value="<?php echo h($msgTypeFilter !== '' ? $msgTypeFilter : '__ALL__'); ?>">
        <div class="allAlarmsDateRange">
          <?php echo icon('calendar'); ?>
          <label><span>Desde</span><input type="date" name="fecha_desde" id="allAlarmDateFrom" value="<?php echo h($dateFrom); ?>"></label>
          <label><span>Hora</span><input type="time" name="hora_desde" id="allAlarmTimeFrom" value="<?php echo h($timeFrom); ?>"></label>
          <span class="allAlarmsDateSep">—</span>
          <label><span>Hasta</span><input type="date" name="fecha_hasta" id="allAlarmDateTo" value="<?php echo h($dateTo); ?>"></label>
          <label><span>Hora</span><input type="time" name="hora_hasta" id="allAlarmTimeTo" value="<?php echo h($timeTo); ?>"></label>
        </div>
        <button type="submit" class="toolbar__dateApply">Aplicar</button>
        <?php if ($hasFilters): ?><a class="btn-clear-filters" href="todas_alarmas.php?msgtype=__ALL__&amp;physnode=__ALL__">↺ Limpiar</a><?php endif; ?>
      </form>

      <div class="taFacetBar">
        <section class="taFacetCard">
          <div class="taFacetTitle">Filtro ALM_MSGTYPE · preferencia del usuario</div>
          <div class="taFacetChips">
            <a class="taFacetChip <?php echo $msgTypeFilter === '' ? 'is-active' : ''; ?>" href="<?php echo h(ta_url_with(['msgtype'=>'__ALL__'])); ?>"><span>Todos</span></a>
            <?php foreach ($allowedMsgTypes as $msgType): ?>
              <a class="taFacetChip <?php echo $msgTypeFilter === $msgType ? 'is-active' : ''; ?>" href="<?php echo h(ta_url_with(['msgtype'=>$msgType])); ?>"><span><?php echo h($msgType); ?></span><b><?php echo number_format($msgTypeCounts[$msgType] ?? 0,0,',','.'); ?></b></a>
            <?php endforeach; ?>
          </div>
        </section>
        <section class="taFacetCard">
          <div class="taFacetTitle">ALM_PHYSNODE seleccionado</div>
          <div style="font-size:14px;font-weight:700;color:var(--petrol)"><?php echo h($physNodeFilter !== '' ? $physNodeFilter : 'Todos'); ?></div>
          <div style="font-size:11px;color:var(--text-mut);margin-top:5px">La selección se guarda para <?php echo h($APP_USER); ?>.</div>
        </section>
      </div>

      <div class="allAlarmsActions">
        <div class="toolbar__count">
          <?php if ($total > 0): ?><?php if ($showAllRows): ?>Mostrando <b>todas las <?php echo number_format($total,0,',','.'); ?></b> filas<?php else: ?>Mostrando <b><?php echo number_format(min(($page - 1) * $perPage + 1, $total), 0, ',', '.'); ?></b>–<b><?php echo number_format(min($page * $perPage, $total), 0, ',', '.'); ?></b> de <b><?php echo number_format($total, 0, ',', '.'); ?></b><?php endif; ?><?php else: ?>Sin resultados<?php endif; ?>
        </div>
        <div class="allAlarmsActionButtons">
          <label class="autoRefresh" for="allAlarmsPerPage"><span>Filas</span>
            <select id="allAlarmsPerPage" aria-label="Cantidad de filas por página" onchange="var u=new URL(window.location.href);u.searchParams.set('per_page',this.value);u.searchParams.delete('p');window.location.href=u.toString();">
              <?php foreach ($allowedPerPage as $size): ?><option value="<?php echo h($size); ?>" <?php echo $perPageParam === $size ? 'selected' : ''; ?>><?php echo $size === 'all' ? 'Todas' : h($size); ?></option><?php endforeach; ?>
            </select>
          </label>
          <button type="button" class="btn-export" id="columnChooserButton"><?php echo icon('grid'); ?> Columnas</button>
          <button type="button" class="btn-export" id="exportAllAlarmsExcel"><?php echo icon('download'); ?> Exportar Excel</button>
        </div>
      </div>

      <div class="columnChooser" id="columnChooser" hidden>
        <div class="columnChooser__head">
          <div><b>Mostrar u ocultar columnas</b><span>La selección queda guardada en este navegador.</span></div>
          <div><button type="button" id="showAllColumns">Mostrar todas</button><button type="button" id="hideOptionalColumns">Vista compacta</button></div>
        </div>
        <div class="columnChooser__grid">
          <?php foreach ($displayColumns as $index => $column): ?>
            <label><input type="checkbox" data-column-toggle="<?php echo $index; ?>" checked> <?php echo h($column === '__TIPO_INSTALACION' ? 'TIPO DE INSTALACIÓN' : $column); ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="tablewrap allAlarmsTableWrap">
        <?php if (!$rows): ?>
          <div class="empty"><p>No se encontraron alarmas con los filtros seleccionados.</p></div>
        <?php else: ?>
          <div class="tablescroll allAlarmsScroll">
            <table class="grid grid--sortable js-sortable allAlarmsTable" id="allAlarmsTable">
              <thead>
                <tr><?php foreach ($displayColumns as $index => $column): ?><th data-column-index="<?php echo $index; ?>"><span><?php echo h($column === '__TIPO_INSTALACION' ? 'TIPO DE INSTALACIÓN ↕' : $column . ' ↕'); ?></span></th><?php endforeach; ?></tr>
                <tr class="gridFilterRow" aria-label="Filtros por columna">
                  <?php foreach ($displayColumns as $index => $column): ?><th data-column-index="<?php echo $index; ?>">
                    <?php if ($column === '__TIPO_INSTALACION'): ?><select name="tipo_instalacion" form="allAlarmsFilters" onchange="this.form.submit()" aria-label="Filtrar tipo de instalación"><option value="">Todos</option><?php foreach($installationTypeOptions as $typeValue=>$typeLabel): ?><option value="<?php echo h($typeValue); ?>" <?php echo $installationTypeFilter===$typeValue?'selected':''; ?>><?php echo h($typeLabel); ?></option><?php endforeach; ?></select>
                    <?php else: ?><input type="text" data-ta-column-filter="<?php echo $index; ?>" placeholder="Filtrar" aria-label="Filtrar <?php echo h($column); ?>"><?php endif; ?>
                  </th><?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $row):
                $rowTag = trim((string)($row[$tagColumn] ?? ''));
                $rowTimestamp = trim((string)($row[$dateColumn] ?? ''));
                $rowValue = trim((string)($row['ALM_VALUE'] ?? ($row['Valor'] ?? '')));
                $rowDescription = '';
                $rowHasComment = isset($commentKeys[strtoupper($rowTag)]);
                foreach ($descriptionColumns as $rowDescriptionColumn) {
                    $candidateDescription = trim((string)($row[$rowDescriptionColumn] ?? ''));
                    if ($candidateDescription !== '') { $rowDescription = $candidateDescription; break; }
                }
              ?>
                <tr class="js-detail-row" tabindex="0" aria-label="Ver detalle de alarma">
                  <?php foreach ($displayColumns as $index => $column): $value = $row[$column] ?? ''; ?>
                    <td data-column-index="<?php echo $index; ?>" data-label="<?php echo h($column === '__TIPO_INSTALACION' ? 'Tipo de instalación' : $column); ?>" data-raw-value="<?php echo h((string)$value); ?>" title="<?php echo h((string)$value); ?>"><?php if ($column === '__TIPO_INSTALACION'): $normalizedType=clear_installation_type_normalize($value); if($normalizedType==='')$normalizedType='SIN CLASIFICAR'; ?><span class="installationTypeBadge installationTypeBadge--<?php echo h(clear_installation_type_class($normalizedType)); ?>"><?php echo h($installationTypeOptions[$normalizedType] ?? $normalizedType); ?></span><?php elseif (strtoupper((string)$column) === strtoupper($tagColumn) && trim((string)$value) !== ''): ?><?php echo clear_alarm_actions_cell(['display'=>$value,'tag'=>$rowTag,'timestamp'=>$rowTimestamp,'value'=>$rowValue,'description'=>$rowDescription,'preserve_pi_link'=>true,'show_comment'=>true,'has_comment'=>$rowHasComment,'context'=>'todas_alarmas']); ?><?php else: ?><?php echo h(ta_format_value($value)); ?><?php endif; ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="pager">
        <div class="pager__info">Página <?php echo $page; ?> de <?php echo $totalPages; ?></div>
        <div class="pager__btns">
          <a class="pager__btn <?php echo $page <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo h(ta_url_with(['p' => max(1, $page - 1)])); ?>">‹</a>
          <?php $start = max(1, $page - 2); $end = min($totalPages, $start + 4); $start = max(1, $end - 4); for ($i = $start; $i <= $end; $i++): ?>
            <a class="pager__btn <?php echo $i === $page ? 'is-active' : ''; ?>" href="<?php echo h(ta_url_with(['p' => $i])); ?>"><?php echo $i; ?></a>
          <?php endfor; ?>
          <a class="pager__btn <?php echo $page >= $totalPages ? 'is-disabled' : ''; ?>" href="<?php echo h(ta_url_with(['p' => min($totalPages, $page + 1)])); ?>">›</a>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>

<?php clear_alarm_actions_modal(); ?>

<div class="piModal__overlay" id="piModalOverlay" hidden></div>
<section class="piModal" id="piModal" aria-hidden="true" aria-labelledby="piModalTitle">
  <div class="piModal__head">
    <div><div class="piModal__eyebrow">Vista rápida</div><h2 id="piModalTitle">PI Histórico</h2><p class="piModal__sub">Tendencia y referencias SQL existentes.</p></div>
    <div class="piModal__actions"><a class="piModal__link" id="piModalOpenPage" href="pi_historico.php" target="_blank" rel="noopener">Abrir página completa</a><button type="button" class="piModal__close" id="piModalClose" aria-label="Cerrar PI Histórico">×</button></div>
  </div>
  <div class="piModal__body"><iframe id="piModalFrame" class="piModal__frame" src="about:blank" title="PI Histórico embebido" loading="lazy"></iframe></div>
</section>

<div class="detailDrawer__overlay" id="detailDrawerOverlay" hidden></div>
<aside class="detailDrawer" id="detailDrawer" aria-hidden="true" aria-labelledby="detailDrawerTitle">
  <div class="detailDrawer__head"><div><div class="detailDrawer__eyebrow">Detalle de la alarma</div><h2 id="detailDrawerTitle">Alarma</h2></div><button type="button" class="detailDrawer__close" id="detailDrawerClose">×</button></div>
  <div class="detailDrawer__body" id="detailDrawerBody"></div>
</aside>

<script>
window.CLEAR_ALL_ALARMS_COLUMNS = <?php echo json_encode(array_map(function($column){return $column==='__TIPO_INSTALACION'?'TIPO DE INSTALACIÓN':$column;},$displayColumns), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/js/app.js?v=20260716-msgfilters1"></script>
<script src="assets/js/alarm_actions.js?v=20260826-central-1"></script>
<script src="assets/js/todas_alarmas.js?v=20260716-msgfilters1"></script>
<script>
(function(){
  var table=document.getElementById('allAlarmsTable');if(!table)return;
  var filters=Array.prototype.slice.call(table.querySelectorAll('[data-ta-column-filter]'));
  Array.prototype.forEach.call(table.querySelectorAll('.gridFilterRow select'),function(control){control.addEventListener('click',function(e){e.stopPropagation()})});
  function apply(){var rows=table.tBodies[0]?table.tBodies[0].rows:[];Array.prototype.forEach.call(rows,function(row){row.hidden=!filters.every(function(filter){var value=(filter.value||'').trim().toLocaleLowerCase('es');if(!value)return true;var cell=row.cells[parseInt(filter.getAttribute('data-ta-column-filter'),10)];return cell&&(cell.textContent||'').toLocaleLowerCase('es').indexOf(value)!==-1})})}
  filters.forEach(function(filter){filter.addEventListener('input',apply);filter.addEventListener('click',function(e){e.stopPropagation()})});
})();
</script>
</body>
</html>
