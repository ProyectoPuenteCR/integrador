<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/zones.php';
require_once __DIR__ . '/includes/alarm_actions.php';
require_once __DIR__ . '/includes/instalaciones_top20_week.php';
require_once __DIR__ . '/includes/installation_type.php';
require_once __DIR__ . '/includes/novedades_semanales_common.php';

auth_require();
permissions_require_menu('top20_all');

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = 'top20_all';
$db = clear_db();
$dbError = $db->ok() ? '' : $db->error();

$search = trim((string)($_GET['q'] ?? ''));
$refresh = trim((string)($_GET['refresh'] ?? '0'));
if (!in_array($refresh, ['0','300','600','1800'], true)) $refresh = '0';
$today = new DateTimeImmutable('now');
$selectedWeek = it20_selected_week($_GET, $today);
$currentWeekStart = it20_week_for_date($today)['start']->format('Y-m-d');
$weekOptions = it20_week_options($today, 53);
$fromDate = $selectedWeek['start']->format('Y-m-d');
$toDate = $selectedWeek['end']->format('Y-m-d');
$fromSql = $fromDate . 'T00:00:00';
$toSqlExclusive = $selectedWeek['next']->format('Y-m-d') . 'T00:00:00';

function it20_terms($value)
{
    $value = trim((string)$value);
    return $value === '' ? [] : array_values(array_filter(preg_split('/\s+/', $value)));
}
function it20_num($value) { return number_format((int)$value, 0, ',', '.'); }
function it20_pct($value) { return number_format((float)$value, 1, ',', '.') . '%'; }

$rows = [];
$zones = [];
$installations = [];
$zone = '';
$installationFilter = '';
$installationTypeFilter = clear_installation_type_normalize($_GET['tipo_instalacion'] ?? '');
$installationTypeOptions = clear_installation_type_options();
$total = 0;
$topTag = '';
$topTagCount = 0;
$topInstallation = '';
$topInstallationCount = 0;
$top5Count = 0;
$top5Pct = 0;
$weeklyComments = [];
$canViewComments = permissions_can('comments.view');
$canCreateComments = permissions_can('comments.create');
$reportEnabled=permissions_can_menu('novedades_semanales_reporte');

if ($db->ok()) {
    $zones = clear_zones_all($db);
    $zone = clear_zone_valid(trim((string)($_GET['zona'] ?? '')), $zones);
    $installationFilter = trim((string)($_GET['instalacion'] ?? ''));
    if (strlen($installationFilter) > 100 || preg_match('/[\x00-\x1F\x7F]/', $installationFilter)) $installationFilter = '';

    $metaRows = $db->all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS'");
    $lookup = [];
    foreach ($metaRows as $metaRow) {
        $column = trim((string)($metaRow['COLUMN_NAME'] ?? $metaRow['column_name'] ?? reset($metaRow)));
        if ($column !== '') $lookup[strtoupper($column)] = $column;
    }
    $tagColumn = $lookup['ALM_TAGNAME'] ?? 'ALM_TAGNAME';
    $descColumn = $lookup['ALM_DESCR'] ?? ($lookup['ALM_TAGDESC'] ?? 'ALM_DESCR');
    $externalColumn = $lookup['ALM_ALMEXTFLD2'] ?? 'ALM_ALMEXTFLD2';
    $dateColumn = $lookup['ALM_NATIVETIMEIN'] ?? 'ALM_NATIVETIMEIN';
    $tagExpr = "LTRIM(RTRIM(CONVERT(nvarchar(255),[$tagColumn])))";
    $descExpr = "LTRIM(RTRIM(CONVERT(nvarchar(1000),[$descColumn])))";
    $externalExpr = "LTRIM(RTRIM(CONVERT(nvarchar(500),[$externalColumn])))";
    $installationExpr = "LEFT($tagExpr,CHARINDEX('_',$tagExpr+'_')-1)";
    $installationTypeExpr = clear_installation_type_sql($tagExpr, $externalExpr);
    $tagOnlyInstallationTypeExpr = clear_installation_type_sql($tagExpr, "N''");
    $groupedInstallationTypeExpr = "CASE WHEN MAX(CASE WHEN UPPER($externalExpr) LIKE N'YPF.SC%' THEN 1 ELSE 0 END)=1 THEN N'POZO' ELSE ($tagOnlyInstallationTypeExpr) END";
    $entityExpr = $installationTypeFilter === 'POZO' ? $externalExpr : $installationExpr;

    $baseConditions = [
        "[$tagColumn] IS NOT NULL",
        "$tagExpr<>''",
        "[$dateColumn]>=CONVERT(datetime2,?,126)",
        "[$dateColumn]<CONVERT(datetime2,?,126)",
    ];
    $baseParams = [$fromSql, $toSqlExclusive];
    if ($zone !== '') {
        $baseConditions[] = "EXISTS (SELECT 1 FROM [CLEAR].[ZONAS] Z WHERE LTRIM(RTRIM(Z.ZONA)) COLLATE DATABASE_DEFAULT=? COLLATE DATABASE_DEFAULT AND LTRIM(RTRIM(Z.BATERIA)) COLLATE DATABASE_DEFAULT=$installationExpr COLLATE DATABASE_DEFAULT)";
        $baseParams[] = $zone;
    }
    if ($installationTypeFilter !== '') {
        $baseConditions[] = "$installationTypeExpr=?";
        $baseParams[] = $installationTypeFilter;
    }

    $installationWhere = 'WHERE ' . implode(' AND ', $baseConditions);
    foreach ($db->all(
        "SELECT DISTINCT $entityExpr AS INSTALACION FROM dbo.FIXALARMS $installationWhere AND $entityExpr<>N'' ORDER BY INSTALACION",
        $baseParams
    ) as $installationRow) {
        $value = trim((string)($installationRow['INSTALACION'] ?? $installationRow['instalacion'] ?? reset($installationRow)));
        if ($value !== '') $installations[] = $value;
    }

    $conditions = $baseConditions;
    $params = $baseParams;
    if ($installationFilter !== '') {
        $conditions[] = "$entityExpr=?";
        $params[] = $installationFilter;
    }
    foreach (it20_terms($search) as $term) {
        $conditions[] = "($tagExpr LIKE ? OR $descExpr LIKE ? OR $entityExpr LIKE ?)";
        $params[] = '%' . $term . '%';
        $params[] = '%' . $term . '%';
        $params[] = '%' . $term . '%';
    }
    $where = 'WHERE ' . implode(' AND ', $conditions);

    $total = (int)$db->scalar("SELECT COUNT_BIG(*) FROM dbo.FIXALARMS $where", $params);
    $rows = $db->all(
        "SELECT TOP 20 $groupedInstallationTypeExpr AS TIPO_INSTALACION,$entityExpr AS INSTALACION,$tagExpr AS TAG,MAX($descExpr) AS DESCRIPCION," .
        "COUNT_BIG(*) AS TOTAL_ALARMAS,MAX(NULLIF($externalExpr,'')) AS CAMPO_EXT " .
        "FROM dbo.FIXALARMS $where GROUP BY $entityExpr,$tagExpr " .
        "ORDER BY COUNT_BIG(*) DESC,$entityExpr,$tagExpr",
        $params
    );

    if ($rows) {
        $topTag = trim((string)($rows[0]['TAG'] ?? $rows[0]['tag'] ?? ''));
        $topTagCount = (int)($rows[0]['TOTAL_ALARMAS'] ?? $rows[0]['total_alarmas'] ?? 0);
        foreach (array_slice($rows, 0, 5) as $row) {
            $top5Count += (int)($row['TOTAL_ALARMAS'] ?? $row['total_alarmas'] ?? 0);
        }
    }
    $topInstallationRows = $db->all(
        "SELECT TOP 1 $entityExpr AS INSTALACION,COUNT_BIG(*) AS CANTIDAD " .
        "FROM dbo.FIXALARMS $where GROUP BY $entityExpr ORDER BY COUNT_BIG(*) DESC,$entityExpr",
        $params
    );
    if ($topInstallationRows) {
        $topInstallation = trim((string)($topInstallationRows[0]['INSTALACION'] ?? $topInstallationRows[0]['instalacion'] ?? ''));
        $topInstallationCount = (int)($topInstallationRows[0]['CANTIDAD'] ?? $topInstallationRows[0]['cantidad'] ?? 0);
    }
    if ($total > 0) $top5Pct = $top5Count * 100 / $total;

    if (($canViewComments || $canCreateComments) && $rows) {
        $commentSubjects = [];
        foreach ($rows as $commentSourceRow) {
            $commentInstallation = trim((string)($commentSourceRow['INSTALACION'] ?? $commentSourceRow['instalacion'] ?? ''));
            $commentType = clear_installation_type_normalize($commentSourceRow['TIPO_INSTALACION'] ?? $commentSourceRow['tipo_instalacion'] ?? '');
            if ($commentInstallation !== '') $commentSubjects[] = clear_alarm_comment_subject($commentInstallation, $commentType === 'POZO' ? 'pozo' : 'instalacion');
        }
        $weeklyComments = clear_alarm_comments_load_subjects_sql($commentSubjects);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Top 20 alarmas · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260811-it20">
  <link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260807-2">
  <link rel="stylesheet" href="assets/css/installation_type.css?v=20260824-1">
  <?php if($reportEnabled): ?><link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260826-select-all-1"><?php endif; ?>
  <style>
    .it20Toolbar{display:grid;grid-template-columns:minmax(250px,1fr) repeat(3,minmax(150px,.48fr)) minmax(260px,.65fr) auto auto auto;gap:12px;align-items:center;margin-bottom:15px}.it20Input,.it20Select,.it20Week{display:flex;align-items:center;gap:9px;background:var(--surface,#fff);border:1px solid var(--line-mid);border-radius:10px;min-height:42px;padding:0 12px;overflow:hidden}.it20Input svg,.it20Select svg{width:16px!important;height:16px!important;min-width:16px!important}.it20Input span,.it20Select span,.it20Week span{font-size:9px;font-weight:800;letter-spacing:.7px;color:var(--text-mut);text-transform:uppercase}.it20Input input,.it20Select select,.it20Week select{border:0;outline:0;background:transparent;color:var(--text);font:inherit;min-width:0;flex:1}.it20Week select{min-width:190px}.it20Cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}.it20Table .alarmCell__text{font-weight:700}.it20CommentButton{position:relative;display:inline-flex;align-items:center;justify-content:center;gap:7px;min-width:38px;height:31px;padding:0 10px;border:1px solid var(--line-mid);border-radius:8px;background:var(--surface,#fff);color:var(--petrol);cursor:pointer}.it20CommentButton svg{width:15px;height:15px}.it20CommentButton:hover,.it20CommentButton:focus-visible{border-color:var(--petrol);background:var(--petrol-soft);outline:0}.it20CommentButton.has-comment{background:var(--petrol);border-color:var(--petrol);color:#fff}.it20CommentButton__dot{display:none;width:7px;height:7px;border-radius:50%;background:#38d58b}.it20CommentButton.has-comment .it20CommentButton__dot{display:block}.it20CommentOverlay{position:fixed;inset:0;z-index:151;background:rgba(12,35,44,.45);backdrop-filter:blur(3px)}.it20CommentModal{position:fixed;z-index:152;left:50%;top:50%;width:min(590px,calc(100vw - 28px));transform:translate(-50%,-46%) scale(.985);background:var(--surface,#fff);border:1px solid var(--line-mid);border-radius:18px;box-shadow:0 28px 72px rgba(12,35,44,.32);opacity:0;pointer-events:none;transition:.17s ease;overflow:hidden}.it20CommentModal.is-open{opacity:1;pointer-events:auto;transform:translate(-50%,-50%) scale(1)}.it20CommentModal__head{display:flex;justify-content:space-between;gap:18px;padding:20px 22px 16px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,rgba(26,77,92,.08),transparent)}.it20CommentModal__eyebrow{margin-bottom:4px;color:var(--text-mut);font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase}.it20CommentModal h2{margin:0;color:var(--petrol);font-family:var(--font-head);font-size:26px}.it20CommentModal__head p{margin:5px 0 0;color:var(--text-soft);font-size:12px}.it20CommentModal__close{width:38px;height:38px;border:1px solid var(--line-mid);border-radius:10px;background:var(--surface,#fff);color:var(--text-soft);font-size:24px;cursor:pointer}.it20CommentModal__body{padding:18px 22px}.it20CommentModal__body label{display:block;margin-bottom:7px;color:var(--text-soft);font-size:11px;font-weight:800;letter-spacing:.7px;text-transform:uppercase}.it20CommentModal textarea{width:100%;min-height:145px;resize:vertical;border:1px solid var(--line-mid);border-radius:11px;padding:11px 13px;background:var(--surface,#fff);color:var(--text);font:inherit;line-height:1.5;outline:0}.it20CommentModal textarea:focus{border-color:var(--petrol);box-shadow:0 0 0 3px var(--petrol-soft)}.it20CommentModal__previous,.it20CommentModal__status{margin-bottom:12px;padding:10px 12px;border-radius:10px;font-size:12px}.it20CommentModal__previous{border:1px solid #cae4d6;background:#f2fbf6;color:var(--green-tx)}.it20CommentModal__status{margin:10px 0 0;background:#eef6f9;color:var(--petrol)}.it20CommentModal__status.is-error{background:#fff1ef;color:var(--red)}.it20CommentModal__status.is-ok{background:#edf9f3;color:var(--green-tx)}.it20CommentModal__foot{display:flex;justify-content:flex-end;gap:10px;padding:0 22px 20px}.it20CommentModal__foot button{min-height:40px;padding:0 15px;border-radius:10px;font:inherit;cursor:pointer}.it20CommentModal__cancel{border:1px solid var(--line-mid);background:var(--surface,#fff);color:var(--text)}.it20CommentModal__save{border:0;background:var(--petrol);color:#fff;font-weight:700}.it20CommentModal__save:disabled{opacity:.58;cursor:wait}body.has-it20-comment{overflow:hidden}@media(max-width:1550px){.it20Toolbar{grid-template-columns:repeat(2,1fr)}}@media(max-width:900px){.it20Cards{grid-template-columns:repeat(2,1fr)}}@media(max-width:650px){.it20Toolbar,.it20Cards{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page__head">
      <div><h1 class="page__title">Top 20 alarmas · Semanal</h1><div class="page__sub">Alarmas de pozos e instalaciones agrupadas por entidad y TAG · semanas fijas de miércoles a martes</div></div>
      <div class="page__live"><span class="dot"></span><?php echo $dbError ? 'Sin conexión' : 'En vivo'; ?></div>
    </div>

    <?php if ($dbError): ?>
      <div class="tablewrap"><div class="empty"><p><b>Sin conexión:</b> <?php echo h($dbError); ?></p></div></div>
    <?php else: ?>
      <form class="it20Toolbar" method="get" action="instalaciones_top20.php" id="instalacionesTop20Filters" data-current-week="<?php echo h($currentWeekStart); ?>" data-selected-week="<?php echo h($fromDate); ?>">
        <label class="it20Input"><?php echo icon('search'); ?><span>Buscar</span><input type="search" name="q" value="<?php echo h($search); ?>" placeholder="Buscar TAG, descripción o instalación…"></label>
        <label class="it20Select"><?php echo icon('map'); ?><span>Zona</span><select name="zona"><option value="">Todas</option><?php foreach ($zones as $zoneValue): ?><option value="<?php echo h($zoneValue); ?>" <?php echo $zone === $zoneValue ? 'selected' : ''; ?>><?php echo h($zoneValue); ?></option><?php endforeach; ?></select></label>
        <label class="it20Select"><?php echo icon('map'); ?><span>Entidad</span><select name="instalacion"><option value="">Todas</option><?php foreach ($installations as $installationValue): ?><option value="<?php echo h($installationValue); ?>" <?php echo $installationFilter === $installationValue ? 'selected' : ''; ?>><?php echo h($installationValue); ?></option><?php endforeach; ?><?php if ($installationFilter !== '' && !in_array($installationFilter, $installations, true)): ?><option value="<?php echo h($installationFilter); ?>" selected><?php echo h($installationFilter); ?></option><?php endif; ?></select></label>
        <label class="it20Select"><?php echo icon('grid'); ?><span>Tipo</span><select name="tipo_instalacion"><option value="">Todos</option><?php foreach ($installationTypeOptions as $typeValue => $typeLabel): ?><option value="<?php echo h($typeValue); ?>" <?php echo $installationTypeFilter === $typeValue ? 'selected' : ''; ?>><?php echo h($typeLabel); ?></option><?php endforeach; ?></select></label>
        <label class="it20Week"><span>Semana</span><select name="semana" id="it20Week"><?php foreach ($weekOptions as $weekOption): ?><option value="<?php echo h($weekOption['value']); ?>" <?php echo $weekOption['value'] === $fromDate ? 'selected' : ''; ?>><?php echo h($weekOption['label']); ?></option><?php endforeach; ?></select></label>
        <label class="it20Select"><span>Actualizar</span><select name="refresh" id="it20Refresh"><option value="0" <?php echo $refresh==='0'?'selected':''; ?>>Desactivada</option><option value="300" <?php echo $refresh==='300'?'selected':''; ?>>5 minutos</option><option value="600" <?php echo $refresh==='600'?'selected':''; ?>>10 minutos</option><option value="1800" <?php echo $refresh==='1800'?'selected':''; ?>>30 minutos</option></select></label>
        <button type="submit" class="toolbar__dateApply">Aplicar</button>
        <button type="button" class="btn-export" id="it20Export"><?php echo icon('download'); ?> Exportar CSV</button>
      </form>

      <section class="it20Cards">
        <article class="stat acc-blue"><div class="stat__label">Total semanal de alarmas</div><div class="stat__value is-blue"><?php echo it20_num($total); ?></div><div class="stat__detail">Semana del <b><?php echo h(date('d/m/Y', strtotime($fromDate))); ?></b> al <b><?php echo h(date('d/m/Y', strtotime($toDate))); ?></b>.</div></article>
        <article class="stat acc-red"><div class="stat__label">TAG más frecuente</div><div class="stat__value is-red" style="font-size:18px"><?php echo h($topTag !== '' ? $topTag : 'Sin datos'); ?></div><div class="stat__detail"><b><?php echo it20_num($topTagCount); ?></b> alarmas.</div></article>
        <article class="stat acc-green"><div class="stat__label">Entidad principal</div><div class="stat__value is-green" style="font-size:18px"><?php echo h($topInstallation !== '' ? $topInstallation : 'Sin datos'); ?></div><div class="stat__detail"><b><?php echo it20_num($topInstallationCount); ?></b> alarmas en la semana.</div></article>
        <article class="stat acc-amber"><div class="stat__label">Concentración del Top 5</div><div class="stat__value is-amber"><?php echo it20_pct($top5Pct); ?></div><div class="stat__detail">Los cinco agrupamientos principales concentran <b><?php echo it20_num($top5Count); ?></b> alarmas.</div></article>
      </section>

      <?php if($reportEnabled): ?><div class="nsReportTools"><button class="nsButton" type="button" data-clear-report-add-selected>Agregar seleccionadas al reporte</button><button class="nsButton is-secondary" type="button" data-ns-report-open>Ver y enviar reporte <span class="nsReportCount" data-ns-report-count hidden>0</span></button></div><?php endif; ?>

      <div class="tablewrap">
        <?php if (!$rows): ?><div class="empty"><p>No se encontraron alarmas para la semana y los filtros seleccionados.</p></div><?php else: ?>
        <div class="tablescroll"><table class="grid grid--sortable js-sortable it20Table" id="instalacionesTop20Table">
          <thead>
            <tr><th>TIPO DE INSTALACIÓN ↕</th><th>ENTIDAD ↕</th><th>TAG ↕</th><th>DESCRIPCIÓN ↕</th><th>TOTAL ALARMAS ↕</th><th>CAMPO EXT.</th><th>COMENTARIO</th><?php if($reportEnabled): ?><th>REPORTE</th><?php endif; ?></tr>
            <tr class="gridFilterRow" aria-label="Filtros por columna">
              <th><select data-it20-column-filter="0" aria-label="Filtrar tipo"><option value="">Todos</option><?php foreach ($installationTypeOptions as $typeLabel): ?><option value="<?php echo h($typeLabel); ?>"><?php echo h($typeLabel); ?></option><?php endforeach; ?></select></th>
              <th><input type="text" data-it20-column-filter="1" placeholder="Filtrar" aria-label="Filtrar instalación"></th>
              <th><input type="text" data-it20-column-filter="2" placeholder="Filtrar" aria-label="Filtrar TAG"></th>
              <th><input type="text" data-it20-column-filter="3" placeholder="Filtrar" aria-label="Filtrar descripción"></th>
              <th><input type="text" data-it20-column-filter="4" placeholder="Filtrar" aria-label="Filtrar cantidad"></th>
              <th><input type="text" data-it20-column-filter="5" placeholder="Filtrar" aria-label="Filtrar campo externo"></th>
              <th class="gridFilterRow__empty"></th>
              <?php if($reportEnabled): ?><th class="gridFilterRow__empty"></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row):
            $tag = trim((string)($row['TAG'] ?? $row['tag'] ?? ''));
            $installation = trim((string)($row['INSTALACION'] ?? $row['instalacion'] ?? ''));
            $rowType = clear_installation_type_normalize($row['TIPO_INSTALACION'] ?? $row['tipo_instalacion'] ?? 'SIN CLASIFICAR'); if ($rowType === '') $rowType = 'SIN CLASIFICAR';
            $commentSubject = clear_alarm_comment_subject($installation, $rowType === 'POZO' ? 'pozo' : 'instalacion');
            $hasWeeklyComment = isset($weeklyComments[strtoupper($commentSubject)]);
            $commentRow=$weeklyComments[strtoupper($commentSubject)]??[];$commentText=trim((string)($commentRow['COMENTARIO']??$commentRow['comentario']??''));
            $reportColumns=['Semana'=>date('d/m/Y',strtotime($fromDate)).' al '.date('d/m/Y',strtotime($toDate)),'Tipo de instalación'=>$installationTypeOptions[$rowType]??$rowType,'Entidad'=>$installation,'TAG'=>$tag,'Descripción'=>$row['DESCRIPCION']??$row['descripcion']??'','Total alarmas'=>$row['TOTAL_ALARMAS']??$row['total_alarmas']??0,'Campo externo'=>$row['CAMPO_EXT']??$row['campo_ext']??''];if($commentText!=='')$reportColumns['Comentario']=$commentText;
          ?>
            <tr>
              <td><span class="installationTypeBadge installationTypeBadge--<?php echo h(clear_installation_type_class($rowType)); ?>"><?php echo h($installationTypeOptions[$rowType] ?? $rowType); ?></span></td>
              <td><b><?php echo h($installation); ?></b></td>
              <td><?php echo clear_alarm_actions_cell(['display'=>$tag,'tag'=>$tag,'preserve_pi_link'=>true,'show_comment'=>false]); ?></td>
              <td><?php echo h((string)($row['DESCRIPCION'] ?? $row['descripcion'] ?? '')); ?></td>
              <td><?php echo it20_num($row['TOTAL_ALARMAS'] ?? $row['total_alarmas'] ?? 0); ?></td>
              <td><?php echo h((string)($row['CAMPO_EXT'] ?? $row['campo_ext'] ?? '')); ?></td>
              <td><?php echo clear_alarm_actions_cell(['display'=>'Comentario','tag'=>$tag,'subject'=>$commentSubject,'subject_label'=>($rowType === 'POZO' ? 'Pozo ' : 'Instalación ').$installation,'context'=>'instalaciones_top20','preserve_pi_link'=>false,'show_history'=>false,'show_comment'=>true,'has_comment'=>$hasWeeklyComment]); ?></td>
              <?php if($reportEnabled): ?><td><?php echo ns_report_pick(ns_report_key('top20-semanal',[$fromDate,$installation,$tag]),'row','Top 20 semanal · '.$installation.' · '.$tag,ns_report_row_payload($reportColumns),'Incluir'); ?></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>
<?php clear_alarm_actions_modal(); ?>
<div class="piModal__overlay" id="piModalOverlay" hidden></div>
<section class="piModal" id="piModal" aria-hidden="true" aria-labelledby="piModalTitle"><div class="piModal__head"><div><div class="piModal__eyebrow">Vista rápida</div><h2 id="piModalTitle">PI Histórico</h2><p class="piModal__sub">Tendencia y referencias SQL existentes.</p></div><div class="piModal__actions"><a class="piModal__link" id="piModalOpenPage" href="pi_historico.php" target="_blank" rel="noopener">Abrir página completa</a><button type="button" class="piModal__close" id="piModalClose" aria-label="Cerrar PI Histórico">×</button></div></div><div class="piModal__body"><iframe id="piModalFrame" class="piModal__frame" src="about:blank" title="PI Histórico embebido" loading="lazy"></iframe></div></section>
<script src="assets/js/app.js?v=20260811-it20"></script>
<script src="assets/js/alarm_actions.js?v=20260826-central-1"></script>
<?php if($reportEnabled): ?><script src="assets/js/novedades_semanales.js?v=20260826-select-all-1"></script><?php endif; ?>
<script>
(function(){
  var table=document.getElementById('instalacionesTop20Table');if(!table)return;
  var filters=Array.prototype.slice.call(table.querySelectorAll('[data-it20-column-filter]'));
  function apply(){
    var rows=table.tBodies[0]?table.tBodies[0].rows:[];
    Array.prototype.forEach.call(rows,function(row){
      var visible=filters.every(function(filter){
        var needle=(filter.value||'').trim().toLocaleLowerCase('es');if(!needle)return true;
        var index=parseInt(filter.getAttribute('data-it20-column-filter'),10);
        var cell=row.cells[index];return cell&&(cell.textContent||'').toLocaleLowerCase('es').indexOf(needle)!==-1;
      });
      row.hidden=!visible;
    });
  }
  filters.forEach(function(filter){filter.addEventListener(filter.tagName==='SELECT'?'change':'input',apply);filter.addEventListener('click',function(e){e.stopPropagation()})});
})();
</script>
</body>
</html>
