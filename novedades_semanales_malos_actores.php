<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';
require_once __DIR__.'/includes/novedades_semanales_common.php';

auth_require();
permissions_require_menu('novedades_semanales_malos_actores');
$cfg=require __DIR__.'/config.php';
date_default_timezone_set($cfg['app']['tz']??'UTC');
$APP_USER=auth_user();
$APP_ROLE=auth_es_admin()?'Administrador':'Operador';
$ACTIVE='novedades_semanales_malos_actores';
$db=clear_db();
$ready=ns_module_ready($db);
$commentsReady=$ready&&ns_comments_ready($db);
$reportReady=$ready&&ns_report_ready($db);
$today=new DateTimeImmutable('now');
$prefs=ns_preferences();
$period=ns_period($_GET,$today,$prefs);
$filters=ns_bad_actor_filters($_GET,$period);
$badActorChartType=ns_chart_type($_GET['grafico']??'bar','bar');
if(!in_array($badActorChartType,['bar','line'],true))$badActorChartType='bar';
$perPage=(int)($_GET['por_pagina']??100);
if(!in_array($perPage,[50,100,200],true))$perPage=100;
$page=max(1,(int)($_GET['pagina']??1));
$offset=($page-1)*$perPage;
$rows=[];$total=0;$pages=1;$updated='';$badActorChartRows=[];
$canUseComments=$commentsReady&&(permissions_can('comments.view')||permissions_can('comments.create'));

if($ready){
    $params=[];
    $where=ns_bad_actor_where($filters,$params,$commentsReady);
    $whereSql='WHERE '.implode(' AND ',$where);
    $join=$commentsReady?"LEFT JOIN dbo.CLEAR_NOVEDADES_SEMANALES_COMENTARIOS CO ON CO.SEMANA_DESDE=C.SEMANA_DESDE AND CO.TIPO_INSTALACION=C.TIPO_INSTALACION AND CO.INSTALACION=C.INSTALACION AND CO.TAG=C.TAG":"";
    $total=(int)$db->scalar("SELECT COUNT_BIG(*) FROM dbo.CLEAR_NOVEDADES_SEMANALES_CACHE C $join $whereSql",$params);
    $pages=max(1,(int)ceil($total/$perPage));
    if($page>$pages){$page=$pages;$offset=($page-1)*$perPage;}
    $commentSelect=$commentsReady?",CO.COMENTARIO,CO.ACTIVO COMENTARIO_ACTIVO,CO.USUARIO_CARGA,CO.USUARIO_MODIFICACION":",N'' COMENTARIO,CONVERT(bit,0) COMENTARIO_ACTIVO";
    $rows=$db->all("SELECT ".ns_cache_select('C')."$commentSelect FROM dbo.CLEAR_NOVEDADES_SEMANALES_CACHE C $join $whereSql ORDER BY ".ns_order_sql($filters)." OFFSET $offset ROWS FETCH NEXT $perPage ROWS ONLY",$params);
    $badActorChartRows=$db->all("SELECT CONVERT(varchar(10),C.SEMANA_DESDE,23) SEMANA_DESDE,SUM(CONVERT(bigint,C.TOTAL_ALARMAS)) TOTAL FROM dbo.CLEAR_NOVEDADES_SEMANALES_CACHE C $join $whereSql GROUP BY C.SEMANA_DESDE ORDER BY C.SEMANA_DESDE",$params);
    $updated=(string)$db->scalar("SELECT CONVERT(varchar(19),MAX(FECHA_ACTUALIZACION),120) FROM dbo.CLEAR_NOVEDADES_SEMANALES_CACHE WHERE SEMANA_DESDE BETWEEN CONVERT(date,?,23) AND CONVERT(date,?,23)",[$period['from_value'],$period['to_value']]);
}
$badActorTotalsByWeek=[];
foreach($badActorChartRows as $chartRow){
    $badActorTotalsByWeek[(string)ns_value($chartRow,'SEMANA_DESDE')]=(int)ns_value($chartRow,'TOTAL',0);
}
$badActorLabels=[];$badActorValues=[];
for($week=$period['from'];$week<=$period['to'];$week=$week->modify('+7 days')){
    $weekKey=$week->format('Y-m-d');
    $badActorLabels[]='Sem. '.$week->modify('+6 days')->format('W');
    $badActorValues[]=$badActorTotalsByWeek[$weekKey]??0;
}
$badActorSeries=[['label'=>'Total de alarmas','values'=>$badActorValues]];
$badActorChart=['labels'=>$badActorLabels,'series'=>$badActorSeries,'type'=>$badActorChartType,'palette'=>'blues','unit'=>'alarmas'];
$badActorChartPayload=['kind'=>'chart','chart_type'=>$badActorChartType,'palette'=>'blues','axis_label'=>'Semana','unit'=>'alarmas','labels'=>$badActorLabels,'datasets'=>$badActorSeries,'context'=>ns_period_label($period).' · Sumatoria de alarmas por semana según los filtros aplicados.'];
$last=min($total,$offset+count($rows));
$weekOptions=[];
for($week=$period['to'];$week>=$period['from'];$week=$week->modify('-7 days'))$weekOptions[]=$week;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Malos actores · Novedades semanales</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260826-ns3">
  <link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260826-select-all-1">
</head>
<body><div class="app"><?php include __DIR__.'/includes/sidebar.php'; ?><main class="main"><?php include __DIR__.'/includes/topbar.php'; ?>
<div class="page__head nsHead"><div><h1 class="page__title">Malos actores semanales</h1><div class="page__sub">Ranking por período, instalación y TAG; con orden y búsqueda estándar en todas las columnas.</div></div><div class="page__live"><span class="dot"></span><?php echo $ready?'Sin consulta al histórico':'Requiere instalación'; ?></div></div>
<?php ns_render_module_nav($ACTIVE); ?>
<?php if(!$ready):ns_render_not_installed();else: ?>
<form method="get">
  <div class="nsToolbar">
    <label class="nsControl"><span>Semana desde</span><input type="date" name="desde" value="<?php echo h($period['from_value']); ?>"></label>
    <label class="nsControl"><span>Semana hasta</span><input type="date" name="hasta" value="<?php echo h($period['to_value']); ?>"></label>
    <label class="nsControl nsControl--wide"><span>Búsqueda general</span><input type="search" name="q" value="<?php echo h($filters['q']); ?>" placeholder="Instalación, TAG, descripción o campo externo"></label>
    <label class="nsControl"><span>Comentario</span><select name="comentario"><option value="">Todos</option><option value="con" <?php echo $filters['comment']==='con'?'selected':''; ?>>Con comentario</option><option value="sin" <?php echo $filters['comment']==='sin'?'selected':''; ?>>Sin comentario</option></select></label>
    <label class="nsControl"><span>Gráfico</span><select name="grafico" data-ns-chart-type><option value="bar" <?php echo $badActorChartType==='bar'?'selected':''; ?>>Barras</option><option value="line" <?php echo $badActorChartType==='line'?'selected':''; ?>>Líneas</option></select></label>
    <button class="nsButton" type="submit">Aplicar</button>
    <a class="nsButton is-secondary" href="novedades_semanales_export.php?vista=malos_actores&amp;<?php echo h(ns_query_string([],['pagina'])); ?>"><?php echo icon('download'); ?> Excel</a>
    <button class="nsButton is-secondary" type="button" data-ns-report-open>Reporte <span class="nsReportCount" data-ns-report-count hidden>0</span></button>
  </div>

  <section class="nsCards"><article class="nsCard nsCard--full"><div class="nsCard__head"><div><h2>Semanas vs. semanas</h2><p><?php echo h(ns_period_label($period)); ?> · sumatoria total de alarmas por semana según los filtros aplicados.</p></div><div class="nsChartControls"><?php if($reportReady)echo ns_report_pick(ns_report_key('malos-actores-grafico',[$period['from_value'],$period['to_value'],$filters,$badActorChartType]),'chart','Comparación semanal de alarmas · '.ns_period_label($period),$badActorChartPayload); ?></div></div><div class="nsCard__body"><div class="nsChartBox" style="height:350px"><canvas id="nsBadActorsChart"></canvas></div></div></article></section>

  <section class="nsTableCard">
    <div class="nsTableHead">
      <div><h2><?php echo h(ns_period_label($period)); ?></h2><p>Arrastrá los títulos para mover columnas. Elegí Columnas para mostrar u ocultar campos.</p></div>
      <div class="nsTableHead__actions">
        <?php if($reportReady): ?><button class="nsButton is-secondary" type="button" data-ns-report-add-visible>Agregar visibles al reporte</button><?php endif; ?>
        <div class="nsColumnTools"><button class="nsButton is-secondary" type="button" data-ns-columns-toggle>Columnas ▾</button><div class="nsColumnsMenu" data-ns-columns-menu></div></div>
        <div class="nsTableMeta"><b><?php echo ns_num($total); ?></b> resultados · <?php echo $total?ns_num($offset+1).'–'.ns_num($last):'0'; ?> · actualizado <?php echo h($updated?:'sin datos'); ?></div>
      </div>
    </div>
    <div class="tablescroll">
      <table class="grid nsTable nsTable--bad-actors" id="nsBadActorsTable" data-ns-custom-columns>
        <thead>
          <tr>
            <th data-column-key="report" data-column-label="Reporte">REPORTE</th>
            <th data-column-key="week" data-column-label="Semana"><?php echo ns_sort_link('semana','SEMANA'); ?></th>
            <th data-column-key="type" data-column-label="Tipo"><?php echo ns_sort_link('tipo','TIPO'); ?></th>
            <th data-column-key="installation" data-column-label="Instalación"><?php echo ns_sort_link('instalacion','INSTALACIÓN'); ?></th>
            <th data-column-key="tag" data-column-label="TAG"><?php echo ns_sort_link('tag','TAG'); ?></th>
            <th data-column-key="description" data-column-label="Descripción"><?php echo ns_sort_link('descripcion','DESCRIPCIÓN'); ?></th>
            <th data-column-key="comment" data-column-label="Comentario">COMENTARIO</th>
            <th data-column-key="total" data-column-label="Total"><?php echo ns_sort_link('total','TOTAL'); ?></th>
            <th data-column-key="external" data-column-label="Campo externo"><?php echo ns_sort_link('campo_ext','CAMPO EXT.'); ?></th>
          </tr>
          <tr class="nsFilterRow">
            <th data-column-key="report"></th>
            <th data-column-key="week"><select name="semana_filtro" onchange="this.form.submit()"><option value="">Todas</option><?php foreach($weekOptions as $week):$weekValue=$week->format('Y-m-d'); ?><option value="<?php echo h($weekValue); ?>" <?php echo $filters['selected_week']===$weekValue?'selected':''; ?>><?php echo h(ns_week_label($week,false)); ?></option><?php endforeach; ?></select></th>
            <th data-column-key="type"><select name="tipo_instalacion"><option value="">Todos</option><?php foreach(ns_type_options() as $v=>$l): ?><option value="<?php echo h($v); ?>" <?php echo $filters['type']===$v?'selected':''; ?>><?php echo h($l); ?></option><?php endforeach; ?></select></th>
            <th data-column-key="installation"><input name="instalacion" value="<?php echo h($filters['installation']); ?>" placeholder="Buscar"></th>
            <th data-column-key="tag"><input name="tag" value="<?php echo h($filters['tag']); ?>" placeholder="Buscar"></th>
            <th data-column-key="description"><input name="descripcion" value="<?php echo h($filters['description']); ?>" placeholder="Buscar"></th>
            <th data-column-key="comment"><button class="nsButton" style="min-height:28px;width:100%" type="submit">Filtrar</button></th>
            <th data-column-key="total"><div style="display:flex;gap:4px"><input type="number" name="total_desde" min="0" value="<?php echo $filters['minimum']===null?'':(int)$filters['minimum']; ?>" placeholder="Desde"><input type="number" name="total_hasta" min="0" value="<?php echo $filters['maximum']===null?'':(int)$filters['maximum']; ?>" placeholder="Hasta"></div></th>
            <th data-column-key="external"><input name="campo_ext" value="<?php echo h($filters['external']); ?>" placeholder="Buscar"></th>
          </tr>
        </thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="9" class="nsEmpty">No se encontraron filas con los filtros seleccionados.</td></tr>
        <?php else:foreach($rows as $row):
          $start=ns_parse_date(ns_value($row,'SEMANA_DESDE'));
          $weekLabel=$start?ns_week_label($start,false):'';
          $comment=trim((string)ns_value($row,'COMENTARIO'));
          $payload=ns_report_row_payload(['Semana'=>$weekLabel,'Tipo'=>ns_value($row,'TIPO_INSTALACION'),'Instalación'=>ns_value($row,'INSTALACION'),'TAG'=>ns_value($row,'TAG'),'Descripción'=>ns_value($row,'DESCRIPCION'),'Comentario'=>$comment,'Total alarmas'=>(int)ns_value($row,'TOTAL_ALARMAS'),'Campo externo'=>ns_value($row,'CAMPO_EXT')]);
        ?>
          <tr data-ns-report-row>
            <td class="nsCheckCell" data-column-key="report"><?php if($reportReady)echo ns_report_pick(ns_report_key('actor',[ns_value($row,'SEMANA_DESDE'),ns_value($row,'TIPO_INSTALACION'),ns_value($row,'INSTALACION'),ns_value($row,'TAG')]),'row','Mal actor · '.ns_value($row,'INSTALACION').' · '.ns_value($row,'TAG'),$payload,'Agregar'); ?></td>
            <td data-column-key="week"><b><?php echo h($weekLabel); ?></b></td>
            <td data-column-key="type"><span class="nsType <?php echo h(ns_type_class(ns_value($row,'TIPO_INSTALACION'))); ?>"><?php echo h(ns_value($row,'TIPO_INSTALACION')); ?></span></td>
            <td data-column-key="installation"><b><?php echo h(ns_value($row,'INSTALACION')); ?></b></td>
            <td data-column-key="tag"><?php echo h(ns_value($row,'TAG')); ?></td>
            <td data-column-key="description"><?php echo h(ns_value($row,'DESCRIPCION')); ?></td>
            <td data-column-key="comment"><div class="nsCommentCell"><?php echo ns_render_comment_button($row,$weekLabel,$canUseComments); ?><span class="nsCommentPreview" data-comment-preview><?php echo h($comment?:'Sin comentario'); ?></span></div></td>
            <td data-column-key="total"><span class="nsTotal"><?php echo ns_num(ns_value($row,'TOTAL_ALARMAS')); ?></span></td>
            <td data-column-key="external"><?php echo h(ns_value($row,'CAMPO_EXT')?:'—'); ?></td>
          </tr>
        <?php endforeach;endif; ?>
        </tbody>
      </table>
    </div>
    <div class="nsPager"><div>Filas por página: <select name="por_pagina" onchange="this.form.submit()"><option value="50" <?php echo $perPage===50?'selected':''; ?>>50</option><option value="100" <?php echo $perPage===100?'selected':''; ?>>100</option><option value="200" <?php echo $perPage===200?'selected':''; ?>>200</option></select></div><div><a class="<?php echo $page<=1?'is-disabled':''; ?>" href="?<?php echo h(ns_query_string(['pagina'=>max(1,$page-1)])); ?>">‹</a><span>Página <?php echo $page; ?> de <?php echo $pages; ?></span><a class="<?php echo $page>=$pages?'is-disabled':''; ?>" href="?<?php echo h(ns_query_string(['pagina'=>min($pages,$page+1)])); ?>">›</a></div></div>
  </section>
</form>
<?php endif; ?>
</main></div>
<?php if($ready&&$canUseComments)ns_render_comment_modal(permissions_can('comments.create')); ?>
<?php if($ready): ?><script>window.CLEAR_WEEKLY_NEWS=<?php echo json_encode(['badActors'=>$badActorChart],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;</script><script src="assets/js/chart.umd.js"></script><?php endif; ?>
<script src="assets/js/app.js?v=20260826-ns3"></script><script src="assets/js/novedades_semanales.js?v=20260902-cambio1"></script>
</body></html>
