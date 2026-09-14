<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/reporting.php';
require_once __DIR__.'/includes/recon_comments.php';
require_once __DIR__.'/includes/screens.php';
require_once __DIR__.'/includes/ai_analysis.php';
$id=(int)($_GET['id']??0);$token=(string)($_GET['token']??'');$screen=trim((string)($_GET['screen']??'dashboard'));
if(!$id||!hash_equals(report_generate_token($id),$token)){http_response_code(403);exit('Acceso denegado');}
$db=clear_db();$rows=$db->all("SELECT * FROM dbo.CLEAR_REPORT_SCHEDULES WHERE ID=?",[$id]);if(!$rows){http_response_code(404);exit('Reporte no encontrado');}$schedule=$rows[0];
if($screen==='dashboard'){require __DIR__.'/report_dashboard.php';exit;}
$catalog=report_screen_catalog();if(!isset($catalog[$screen])){http_response_code(404);exit('Pantalla no soportada');}
date_default_timezone_set($schedule['ZONA_HORARIA']?:'America/Argentina/Buenos_Aires');
function rh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function rn($v){return number_format((float)$v,0,',','.');}
function rbar($label,$value,$max,$color='#1a596b'){ $pct=$max>0?max(2,round($value/$max*100)):0; echo '<div class="barrow"><span>'.rh($label).'</span><div class="bar"><i style="width:'.$pct.'%;background:'.$color.'"></i></div><b>'.rn($value).'</b></div>'; }
$title=$catalog[$screen];$subtitle='Reporte operativo automático';$cards=[];$panels=[];$genericData=[];$genericColumns=[];
if($screen==='analisis_ia'){
  ai_analysis_ensure_tables();
  $latest=$db->all("SELECT TOP 1 * FROM dbo.CLEAR_AI_DIAGNOSTICS ORDER BY FECHA_GENERACION DESC");
  $dataAi=ai_analysis_collect(7);
  $cards=[['Alarmas 7 días',$dataAi['total']],['Prioridad alta',$dataAi['alta'],'red'],['Tags únicos',$dataAi['tags_unicos']],['Pozos únicos',$dataAi['pozos_unicos']]];
  $aiReport=$latest?$latest[0]:null;
  $subtitle='Diagnóstico diario · última semana';
}elseif($screen==='pozos_alarmas24'){
  $to=(new DateTime())->format('Y-m-d H:i:s');$from=(new DateTime())->modify('-24 hours')->format('Y-m-d H:i:s');
  $date='ALM_NATIVETIMEIN';$last='ALM_NATIVETIMELAST';$tag='ALM_TAGNAME';$value='ALM_VALUE';$desc='ALM_DESCR';$status='ALM_ALMSTATUS';$priority='ALM_ALMPRIORITY';$well='ALM_ALMEXTFLD2';
  $pozo="LTRIM(RTRIM(CONVERT(nvarchar(255),[$well])))";$prio="UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100),[$priority]))))";
  $where="WHERE [$date]>=? AND [$date]<=? AND [$well] IS NOT NULL AND $pozo<>'' AND UPPER($pozo) LIKE 'YPF.SC%'";$params=[$from,$to];
  $total=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where",$params);$unique=(int)$db->scalar("SELECT COUNT(DISTINCT $pozo) FROM dbo.FIXALARMS $where",$params);
  $high=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND ($prio LIKE '%CRIT%' OR $prio IN ('HIGH','HI','ALTA'))",$params);
  $medium=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND $prio IN ('MEDIUM','MED','MEDIA')",$params);
  $low=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND $prio IN ('LOW','LO','BAJA','INFO')",$params);
  $cards=[['Alarmas de pozos 24 hs',$total],['Pozos únicos',$unique],['Alta',$high,'red'],['Media',$medium,'amber'],['Baja',$low,'green']];
  $data=$db->all("SELECT TOP 100 [$well] AS POZO,[$desc] AS DESCRIPCION,[$date] AS FECHA_INICIO,[$last] AS FECHA_ULTIMA,[$value] AS VALOR,[$tag] AS TAG,[$status] AS ESTADO,[$priority] AS PRIORIDAD FROM dbo.FIXALARMS $where ORDER BY [$date] DESC",$params);
  $subtitle='Alarmas de pozos · últimas 24 hs · criterio YPF.SC';
}elseif($screen==='pozos_top20'){
  $to=(new DateTime())->format('Y-m-d H:i:s');$from=(new DateTime())->modify('-6 days')->setTime(0,0,0)->format('Y-m-d H:i:s');
  $date='ALM_NATIVETIMEIN';$tag="LTRIM(RTRIM(CONVERT(nvarchar(255),ALM_TAGNAME)))";$well="LTRIM(RTRIM(CONVERT(nvarchar(255),ALM_ALMEXTFLD2)))";$desc="MAX(CONVERT(nvarchar(500),ALM_DESCR))";
  $where="WHERE [$date]>=? AND [$date]<=? AND ALM_ALMEXTFLD2 IS NOT NULL AND $well<>'' AND UPPER($well) LIKE 'YPF.SC%'";$params=[$from,$to];
  $total=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where",$params);$unique=(int)$db->scalar("SELECT COUNT(DISTINCT $tag) FROM dbo.FIXALARMS $where",$params);
  $top=$db->all("SELECT TOP 20 $well AS POZO,$tag AS TAG,$desc AS DESCRIPCION,COUNT(*) AS TOTAL_ALARMAS FROM dbo.FIXALARMS $where AND ALM_TAGNAME IS NOT NULL AND $tag<>'' GROUP BY $well,$tag ORDER BY COUNT(*) DESC",$params);
  $cards=[['Total 7 días',$total],['TAG únicos',$unique],['Pozos en ranking',count(array_unique(array_map(function($r){return $r['POZO']??'';},$top)))]];
  $panels[]=['Top 20 alarmas de pozos',$top,'TAG','TOTAL_ALARMAS'];$subtitle='Pozos · última semana · criterio YPF.SC';
}elseif($screen==='top_pozos'){
  $to=(new DateTime())->format('Y-m-d H:i:s');$from=(new DateTime())->modify('-24 hours')->format('Y-m-d H:i:s');
  $date='ALM_NATIVETIMEIN';$tag="LTRIM(RTRIM(CONVERT(nvarchar(255),ALM_TAGNAME)))";$prio="UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100),ALM_ALMPRIORITY))))";$pozo="LTRIM(RTRIM(CONVERT(nvarchar(255),ALM_ALMEXTFLD2)))";
  $priorityClass="CASE WHEN $prio LIKE '%CRIT%' OR $prio IN ('HIGH','HI','ALTA') THEN 'Alta' WHEN $prio IN ('MEDIUM','MED','MEDIA') THEN 'Media' WHEN $prio IN ('LOW','LO','BAJA','INFO') THEN 'Baja' ELSE 'Otra' END";
  $where="WHERE [$date]>=? AND [$date]<=? AND ALM_ALMEXTFLD2 IS NOT NULL AND $pozo<>'' AND UPPER($pozo) LIKE 'YPF.SC%'";$params=[$from,$to];
  $total=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where",$params);$unique=(int)$db->scalar("SELECT COUNT(DISTINCT $pozo) FROM dbo.FIXALARMS $where",$params);
  $high=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND ($prio LIKE '%CRIT%' OR $prio IN ('HIGH','HI','ALTA'))",$params);
  $medium=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND $prio IN ('MEDIUM','MED','MEDIA')",$params);$low=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND $prio IN ('LOW','LO','BAJA','INFO')",$params);
  $cards=[['Total 24 hs',$total],['Alta',$high,'red'],['Media',$medium,'amber'],['Baja',$low,'green'],['Pozos únicos',$unique]];
  $top=$db->all("SELECT TOP 12 $pozo pozo,COUNT(*) cantidad FROM dbo.FIXALARMS $where GROUP BY $pozo ORDER BY COUNT(*) DESC",$params);
  $tags=$db->all("SELECT TOP 12 $tag tag,COUNT(*) cantidad FROM dbo.FIXALARMS $where AND ALM_TAGNAME IS NOT NULL GROUP BY $tag ORDER BY COUNT(*) DESC",$params);
  $hours=$db->all("SELECT DATEPART(hour,[$date]) hora,COUNT(*) cantidad FROM dbo.FIXALARMS $where GROUP BY DATEPART(hour,[$date]) ORDER BY hora",$params);
  $panels[]=['Top pozos',$top,'pozo','cantidad'];$panels[]=['Top TAG',$tags,'tag','cantidad'];$panels[]=['Distribución horaria',$hours,'hora','cantidad'];$subtitle='Pozos · últimas 24 hs';
}elseif($screen==='top_total'){
  $from=(new DateTime('today'))->modify('-6 days')->format('Y-m-d');$to=(new DateTime('today'))->format('Y-m-d');
  $date='ALM_NATIVETIMEIN';$tag="LTRIM(RTRIM(CONVERT(nvarchar(255),ALM_TAGNAME)))";$prio="UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100),ALM_ALMPRIORITY))))";
  $where="WHERE [$date]>=? AND [$date]<DATEADD(day,1,?)";$params=[$from.' 00:00:00',$to.' 00:00:00'];
  $total=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where",$params);$unique=(int)$db->scalar("SELECT COUNT(DISTINCT $tag) FROM dbo.FIXALARMS $where",$params);
  $high=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND ($prio LIKE '%CRIT%' OR $prio IN ('HIGH','HI','ALTA'))",$params);
  $medium=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND $prio IN ('MEDIUM','MED','MEDIA')",$params);$low=max(0,$total-$high-$medium);
  $cards=[['Total 7 días',$total],['Alta',$high,'red'],['Media',$medium,'amber'],['Baja',$low,'green'],['TAG únicos',$unique]];
  $daily=$db->all("SELECT CONVERT(date,[$date]) fecha,COUNT(*) cantidad FROM dbo.FIXALARMS $where GROUP BY CONVERT(date,[$date]) ORDER BY fecha",$params);
  $top=$db->all("SELECT TOP 12 $tag tag,COUNT(*) cantidad FROM dbo.FIXALARMS $where AND ALM_TAGNAME IS NOT NULL GROUP BY $tag ORDER BY COUNT(*) DESC",$params);
  $hours=$db->all("SELECT DATEPART(hour,[$date]) hora,COUNT(*) cantidad FROM dbo.FIXALARMS $where GROUP BY DATEPART(hour,[$date]) ORDER BY hora",$params);
  $panels[]=['Total por día',$daily,'fecha','cantidad'];$panels[]=['Top TAG',$top,'tag','cantidad'];$panels[]=['Distribución horaria',$hours,'hora','cantidad'];$subtitle='dbo.FIXALARMS · '.$from.' al '.$to;
}elseif($screen==='top_hml'){
  $data=$db->all("SELECT TOP 7 * FROM dbo.FIXALAMRS_TOP_HML ORDER BY Fecha DESC");$ha=0;$me=0;$lo=0;foreach($data as $r){$ha+=(float)($r['PrioridadAlta']??0);$me+=(float)($r['PrioridadMedia']??0);$lo+=(float)($r['PrioridadBaja']??0);} $cards=[['Total semanal',$ha+$me+$lo],['Alta',$ha,'red'],['Media',$me,'amber'],['Baja',$lo,'green']];$panels[]=['Criticidad por día',$data,'Fecha','PrioridadBaja'];
}elseif(in_array($screen,['top20_24h','top20'],true)){
  $table=$screen==='top20_24h'?'dbo.CLEAR_CACHE_TOP20_24H':'dbo.FIXALARMS_TOP20';$data=$db->all("SELECT TOP 20 * FROM $table ORDER BY TOTAL_ALARMAS DESC");$total=0;foreach($data as $r)$total+=(float)($r['TOTAL_ALARMAS']??0);$cards=[['Total ranking',$total],['TAG incluidos',count($data)]];$panels[]=['Ranking de TAG',$data,'ALM_TAGNAME','TOTAL_ALARMAS'];
}elseif($screen==='alarmas24h'){
  $total=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS_24H");$crit=(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS_24H WHERE UPPER(CONVERT(nvarchar(100),ALM_ALMPRIORITY)) LIKE '%CRIT%'");$tags=(int)$db->scalar("SELECT COUNT(DISTINCT ALM_TAGNAME) FROM dbo.FIXALARMS_24H");$cards=[['Alarmas 24h',$total],['Críticas',$crit,'red'],['TAG únicos',$tags]];$data=$db->all("SELECT TOP 50 ALM_NATIVETIMEIN,ALM_TAGNAME,ALM_VALUE,ALM_DESCR,ALM_ALMSTATUS,ALM_ALMPRIORITY FROM dbo.FIXALARMS_24H ORDER BY ALM_NATIVETIMEIN DESC");
}elseif($screen==='reconocimientos'){
  $from=(new DateTime('today'))->modify('-6 days')->format('Y-m-d');
  $to=(new DateTime('today'))->format('Y-m-d');
  $allComments=clear_recon_comments_load_all();
  $data=array_values(array_filter($allComments,function($r)use($from,$to){
    $raw=trim((string)($r['created_at']??$r['updated_at']??''));
    $ts=$raw!==''?strtotime($raw):false;
    if($ts===false)return false;
    $d=date('Y-m-d',$ts);
    return $d>=$from&&$d<=$to;
  }));
  $uniqueTags=[];$operators=[];
  foreach($data as $r){$uniqueTags[strtoupper(trim((string)($r['tag']??'')))]=1;$operators[strtoupper(trim((string)($r['operator']??'')))]=1;}
  $cards=[['Comentarios 7 días',count($data)],['TAG con comentarios',count(array_filter(array_keys($uniqueTags)))],['Operadores',count(array_filter(array_keys($operators)))]];
  $subtitle='Comentarios de reconocimientos · '.$from.' al '.$to;
}elseif($screen==='todas_alarmas'){
  $genericColumns=[['ALM_NATIVETIMEIN','Fecha'],['ALM_TAGNAME','TAG'],['ALM_VALUE','Valor'],['ALM_UNIT','Unidad'],['ALM_MSGTYPE','Tipo'],['ALM_DESCR','Descripción'],['ALM_ALMSTATUS','Estado'],['ALM_ALMPRIORITY','Prioridad']];
  $genericData=$db->all("SELECT TOP 100 ALM_NATIVETIMEIN,ALM_TAGNAME,ALM_VALUE,ALM_UNIT,ALM_MSGTYPE,ALM_DESCR,ALM_ALMSTATUS,ALM_ALMPRIORITY FROM dbo.FIXALARMS ORDER BY ALM_NATIVETIMEIN DESC");
  $cards=[['Registros incluidos',count($genericData)]];$subtitle='Últimos 100 registros de dbo.FIXALARMS';
}elseif($screen==='pi_historico'){
  $genericColumns=[['ALM_TAGNAME','TAG'],['ALM_DESCR','Descripción'],['TOTAL','Eventos 24 hs']];
  $genericData=$db->all("SELECT TOP 50 ALM_TAGNAME,MAX(CONVERT(nvarchar(500),ALM_DESCR)) AS ALM_DESCR,COUNT(*) AS TOTAL FROM dbo.FIXALARMS_24H WHERE ALM_TAGNAME IS NOT NULL GROUP BY ALM_TAGNAME ORDER BY COUNT(*) DESC");
  $cards=[['TAG disponibles',count($genericData)]];$subtitle='Resumen de TAG disponibles para análisis histórico';
}elseif(in_array($screen,['alarmas_activas','suprimidas','reconocidas','reconocidas_usr','ranking24h','tendencia_sem','tend_sem_tags','prioridad','pozos','pozos_tecss','importadas'],true)){
  $screens=clear_screens();$def=$screens[$screen]??null;
  if($def){
    $genericColumns=[];$select=[];
    foreach(($def['cols']??[]) as $col){$genericColumns[]=[$col[0],$col[1]];$select[]='['.str_replace(']',']]', $col[0]).']';}
    $order=trim((string)($def['orderby']??''));
    $sql='SELECT TOP 100 '.implode(',',$select).' FROM '.$def['tabla'].($order!==''?' ORDER BY '.$order:'');
    $genericData=$db->all($sql);$cards=[['Registros incluidos',count($genericData)]];$subtitle=$def['subtitulo']??'Reporte operativo';
  }
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><title><?=rh($title)?></title><style>
@page{size:A4 landscape;margin:9mm}*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;color:#183746;background:#fff}.head{display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #1a596b;padding-bottom:9px}.brand{font-weight:800;font-size:23px}.sub{color:#718697;font-size:11px}.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:9px;margin:12px 0}.card,.panel{border:1px solid #d8e2e7;border-radius:9px;padding:11px;background:#fff}.card b{font-size:23px;display:block;margin-top:5px}.red{color:#e94035}.amber{color:#d98300}.green{color:#0c8a63}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.panel h2{font-size:14px;margin:0 0 9px;color:#1a596b}.barrow{display:grid;grid-template-columns:190px 1fr 60px;gap:7px;align-items:center;margin:5px 0;font-size:10px}.bar{height:8px;background:#e9f0f3;border-radius:6px;overflow:hidden}.bar i{display:block;height:100%}.footer{margin-top:10px;border-top:1px solid #d8e2e7;padding-top:7px;font-size:9px;color:#718697}table{width:100%;border-collapse:collapse;font-size:9px}th,td{padding:5px;border-bottom:1px solid #e5ecef;text-align:left}th{background:#1a596b;color:#fff}
</style></head><body><div class="head"><div><div class="brand">CLEAR PETROLEUM · <?=rh($title)?></div><div class="sub"><?=rh($schedule['NOMBRE'])?> · Generado <?=date('d/m/Y H:i:s')?></div></div><div class="sub"><?=rh($subtitle)?></div></div>
<?php if($cards):?><div class="cards"><?php foreach($cards as $c):?><div class="card"><span><?=rh($c[0])?></span><b class="<?=rh($c[2]??'')?>"><?=rn($c[1])?></b></div><?php endforeach;?></div><?php endif;?>
<?php if($panels):?><div class="grid"><?php foreach($panels as $p):$max=1;foreach($p[1] as $r)$max=max($max,(float)($r[$p[3]]??0));?><section class="panel"><h2><?=rh($p[0])?></h2><?php foreach($p[1] as $r)rbar($r[$p[2]]??'-',(float)($r[$p[3]]??0),$max);?></section><?php endforeach;?></div><?php endif;?>
<?php if(isset($data)&&$screen==='pozos_alarmas24'):?><section class="panel"><h2>Últimas alarmas de pozos</h2><table><thead><tr><th>Pozo</th><th>Descripción</th><th>Hora inicio</th><th>Última</th><th>Valor</th><th>TAG</th><th>Estado</th><th>Prioridad</th></tr></thead><tbody><?php foreach($data as $r):?><tr><td><?=rh($r['POZO']??'')?></td><td><?=rh($r['DESCRIPCION']??'')?></td><td><?=rh($r['FECHA_INICIO']??'')?></td><td><?=rh($r['FECHA_ULTIMA']??'')?></td><td><?=rh($r['VALOR']??'')?></td><td><?=rh($r['TAG']??'')?></td><td><?=rh($r['ESTADO']??'')?></td><td><?=rh($r['PRIORIDAD']??'')?></td></tr><?php endforeach;?><?php if(!$data):?><tr><td colspan="8">No hay alarmas de pozos en las últimas 24 horas.</td></tr><?php endif;?></tbody></table></section><?php endif;?>
<?php if(isset($data)&&$screen==='alarmas24h'):?><section class="panel"><h2>Últimos registros</h2><table><thead><tr><th>Fecha</th><th>TAG</th><th>Valor</th><th>Descripción</th><th>Estado</th><th>Prioridad</th></tr></thead><tbody><?php foreach($data as $r):?><tr><td><?=rh($r['ALM_NATIVETIMEIN']??'')?></td><td><?=rh($r['ALM_TAGNAME']??'')?></td><td><?=rh($r['ALM_VALUE']??'')?></td><td><?=rh($r['ALM_DESCR']??'')?></td><td><?=rh($r['ALM_ALMSTATUS']??'')?></td><td><?=rh($r['ALM_ALMPRIORITY']??'')?></td></tr><?php endforeach;?></tbody></table></section><?php endif;?>
<?php if(isset($data)&&$screen==='reconocimientos'):?><section class="panel"><h2>Comentarios de reconocimientos de la última semana</h2><table><thead><tr><th>Fecha</th><th>TAG</th><th>Descripción</th><th>Operador</th><th>Motivo</th><th>Usuario</th><th>Comentario</th></tr></thead><tbody><?php foreach($data as $r):$raw=trim((string)($r['created_at']??$r['updated_at']??''));$ts=$raw!==''?strtotime($raw):false;?><tr><td><?=rh($ts!==false?date('d/m/Y H:i',$ts):$raw)?></td><td><?=rh($r['tag']??'')?></td><td><?=rh($r['description']??'—')?></td><td><?=rh($r['operator']??'')?></td><td><?=rh($r['reason']??'')?></td><td><?=rh($r['user']??'')?></td><td><?=rh($r['comment']??'')?></td></tr><?php endforeach;?><?php if(!$data):?><tr><td colspan="7">No hay comentarios en los últimos 7 días.</td></tr><?php endif;?></tbody></table></section><?php endif;?>
<?php if($screen==='analisis_ia'):?><section class="panel"><h2>Diagnóstico automático de alarmas</h2><?php if($aiReport && ($aiReport['ESTADO']??'')==='OK'):?><div style="white-space:pre-wrap;line-height:1.45;font-size:11px"><?=rh($aiReport['RESUMEN']??'')?></div><?php elseif($aiReport):?><div style="color:#b42318"><?=rh($aiReport['ERROR']??'No se pudo generar el diagnóstico.')?></div><?php else:?><div>Sin diagnósticos generados todavía.</div><?php endif;?></section><?php endif;?>
<?php if($genericColumns):?><section class="panel"><h2><?=rh($title)?></h2><table><thead><tr><?php foreach($genericColumns as $c):?><th><?=rh($c[1])?></th><?php endforeach;?></tr></thead><tbody><?php foreach($genericData as $r):?><tr><?php foreach($genericColumns as $c):?><td><?=rh($r[$c[0]]??'')?></td><?php endforeach;?></tr><?php endforeach;?><?php if(!$genericData):?><tr><td colspan="<?=count($genericColumns)?>">Sin datos disponibles.</td></tr><?php endif;?></tbody></table></section><?php endif;?>
<div class="footer">Reporte generado automáticamente por CLEAR Plataforma. Pantalla seleccionada: <?=rh($title)?>.</div></body></html>
