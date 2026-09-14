<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';

auth_require();
permissions_require_menu('micros_contables_cierre');
auth_start();

$cfg=require __DIR__.'/config.php';
date_default_timezone_set($cfg['app']['tz']??'UTC');
$db=clear_db();
$APP_USER=auth_user();
$APP_ROLE=auth_es_admin()?'Administrador':'Operador';
$ACTIVE='micros_contables_cierre';
$ready=$db->ok()&&(int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_VW_MASICOS_DIARIOS',N'V') IS NOT NULL THEN 1 ELSE 0 END")===1;

function mcc_clean($value,$max=255){$value=trim((string)$value);if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))return '';return function_exists('mb_substr')?mb_substr($value,0,$max,'UTF-8'):substr($value,0,$max);}
function mcc_date($value){$value=trim((string)$value);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $d&&$d->format('Y-m-d')===$value?$value:'';}
function mcc_value($row,$key,$default=''){foreach($row as $k=>$v)if(strcasecmp((string)$k,(string)$key)===0)return $v;return $default;}
function mcc_number($value){return number_format((float)$value,3,',','.');}
function mcc_csv($value){$value=str_replace(["\r","\n"],' ',(string)$value);return '"'.str_replace('"','""',$value).'"';}

$latest=$ready?(string)$db->scalar("SELECT CONVERT(varchar(10),MAX(FECHA_DATO),23) FROM dbo.CLEAR_VW_MASICOS_DIARIOS"):'';
$selectedDate=mcc_date($_GET['fecha']??'')?:($latest?:date('Y-m-d'));
$selectedDateObject=DateTimeImmutable::createFromFormat('!Y-m-d',$selectedDate)?:new DateTimeImmutable('today');
$weekFrom=$selectedDateObject->modify('-6 days')->format('Y-m-d');
$zone=mcc_clean($_GET['zona']??'',150);
$battery=mcc_clean($_GET['bateria']??'',100);
$installation=mcc_clean($_GET['instalacion']??'',255);
$plc=mcc_clean($_GET['plc001']??'',100);
$query=mcc_clean($_GET['q']??'',255);

$rows=[];$weekly=[];$zones=[];$batteries=[];$plcs=[];
if($ready){
    $scope=[];$scopeParams=[];
    if($zone!==''){$scope[]='ZONA=?';$scopeParams[]=$zone;}
    if($battery!==''){$scope[]='BATERIA=?';$scopeParams[]=$battery;}
    if($installation!==''){$scope[]='NOMBRE_INSTALACION LIKE ?';$scopeParams[]='%'.$installation.'%';}
    if($plc!==''){$scope[]='PLC001=?';$scopeParams[]=$plc;}
    if($query!==''){$scope[]="CONCAT(ISNULL([Name],N''),N' ',ISNULL(NOMBRE_INSTALACION,N''),N' ',ISNULL(BATERIA,N''),N' ',ISNULL(BATERIA_MAESTRO,N''),N' ',ISNULL(ZONA,N''),N' ',ISNULL(PLC001,N'')) LIKE ?";$scopeParams[]='%'.$query.'%';}

    $dayWhere=array_merge(['FECHA_DATO=CONVERT(date,?,23)'],$scope);$dayParams=array_merge([$selectedDate],$scopeParams);
    $rows=$db->all("SELECT TOP (10000) ID,CONVERT(varchar(10),FECHA_DATO,23) FECHA_DATO,CONVERT(varchar(19),FECHA_INSERCION,120) FECHA_INSERCION,[Name],NOMBRE_INSTALACION,BATERIA,BATERIA_MAESTRO,ZONA,FT001,ACUM_BRUTA,PROY_BRUTA,CAUDAL_INSTANTANEO,PROY_NETA,ACUM_NETA,LT001,PROMEDIO_BRUTA_7_DIAS,PLC001 FROM dbo.CLEAR_VW_MASICOS_DIARIOS WHERE ".implode(' AND ',$dayWhere)." ORDER BY ZONA,BATERIA,NOMBRE_INSTALACION",$dayParams);

    $weekWhere=array_merge(['FECHA_DATO BETWEEN CONVERT(date,?,23) AND CONVERT(date,?,23)'],$scope);$weekParams=array_merge([$weekFrom,$selectedDate],$scopeParams);
    $weekly=$db->all("SELECT CONVERT(varchar(10),FECHA_DATO,23) FECHA_DATO,SUM(ISNULL(ACUM_BRUTA,0)) ACUM_BRUTA,SUM(ISNULL(ACUM_NETA,0)) ACUM_NETA,SUM(ISNULL(CAUDAL_INSTANTANEO,0)) CAUDAL_INSTANTANEO FROM dbo.CLEAR_VW_MASICOS_DIARIOS WHERE ".implode(' AND ',$weekWhere)." GROUP BY FECHA_DATO ORDER BY FECHA_DATO",$weekParams);

    $zones=$db->all("SELECT DISTINCT ZONA FROM dbo.CLEAR_VW_MASICOS_DIARIOS WHERE ZONA IS NOT NULL AND LTRIM(RTRIM(ZONA))<>N'' ORDER BY ZONA");
    $batteries=$db->all("SELECT DISTINCT BATERIA FROM dbo.CLEAR_VW_MASICOS_DIARIOS WHERE BATERIA IS NOT NULL AND LTRIM(RTRIM(BATERIA))<>N'' ORDER BY BATERIA");
    $plcs=$db->all("SELECT DISTINCT PLC001 FROM dbo.CLEAR_VW_MASICOS_DIARIOS WHERE PLC001 IS NOT NULL AND LTRIM(RTRIM(PLC001))<>N'' ORDER BY PLC001");
}

$totals=['FT001'=>0,'ACUM_BRUTA'=>0,'PROY_BRUTA'=>0,'CAUDAL_INSTANTANEO'=>0,'PROY_NETA'=>0,'ACUM_NETA'=>0];
foreach($rows as $row)foreach($totals as $key=>$unused)$totals[$key]+=(float)mcc_value($row,$key,0);

$exportQuery=$_GET;$exportQuery['export']='excel';$exportUrl='?'.http_build_query($exportQuery);
if($ready&&($_GET['export']??'')==='excel'){
    audit_log('EXPORTACION','micros_contables_cierre','Cierre diario '.$selectedDate.' · '.count($rows).' registros');
    header('Content-Type:text/csv; charset=UTF-8');header('Content-Disposition:attachment; filename="Cierre_diario_MASICOS_'.$selectedDate.'.csv"');echo "\xEF\xBB\xBF";
    echo "Fecha;Zona;Batería;Instalación;FT001;Acum. Bruta;Proy. Bruta;Caudal instantáneo;Proy. Neta;Acum. Neta;LT001;Promedio Bruta 7 días;PLC001\r\n";
    foreach($rows as $r)echo implode(';',[mcc_csv(mcc_value($r,'FECHA_DATO')),mcc_csv(mcc_value($r,'ZONA')),mcc_csv(mcc_value($r,'BATERIA')),mcc_csv(mcc_value($r,'NOMBRE_INSTALACION')),mcc_csv(mcc_value($r,'FT001')),mcc_csv(mcc_value($r,'ACUM_BRUTA')),mcc_csv(mcc_value($r,'PROY_BRUTA')),mcc_csv(mcc_value($r,'CAUDAL_INSTANTANEO')),mcc_csv(mcc_value($r,'PROY_NETA')),mcc_csv(mcc_value($r,'ACUM_NETA')),mcc_csv(mcc_value($r,'LT001')),mcc_csv(mcc_value($r,'PROMEDIO_BRUTA_7_DIAS')),mcc_csv(mcc_value($r,'PLC001'))])."\r\n";
    exit;
}

$chart=['labels'=>[],'acum_bruta'=>[],'acum_neta'=>[],'caudal'=>[]];
foreach($weekly as $row){$d=(string)mcc_value($row,'FECHA_DATO');$chart['labels'][]=$d?date('d/m',strtotime($d)):'';$chart['acum_bruta'][]=(float)mcc_value($row,'ACUM_BRUTA',0);$chart['acum_neta'][]=(float)mcc_value($row,'ACUM_NETA',0);$chart['caudal'][]=(float)mcc_value($row,'CAUDAL_INSTANTANEO',0);}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Cierre diario MASICOS · CLEAR</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260825-mc15">
  <link rel="stylesheet" href="assets/css/micros_contables.css?v=20260825-mc15">
</head>
<body><div class="app"><?php include __DIR__.'/includes/sidebar.php';?><main class="main"><?php include __DIR__.'/includes/topbar.php';?>
<div class="page__head mcHead"><div><h1 class="page__title">Cierre diario MASICOS</h1><div class="page__sub">Cierre productivo diario y evolución semanal desde MASICOS_SC_HISTORICO</div></div><div class="page__live"><span class="dot"></span><?php echo h(date('d/m/Y',strtotime($selectedDate)));?></div></div>
<?php if(!$ready):?><div class="mcMessage err">Falta la vista local <code>dbo.CLEAR_VW_MASICOS_DIARIOS</code>. Instalá primero Micros contables v1.3.</div><?php else:?>

<form class="mcToolbar mcCloseToolbar" method="get">
  <label><span>Fecha de cierre</span><input type="date" name="fecha" value="<?php echo h($selectedDate);?>" max="<?php echo h($latest?:date('Y-m-d'));?>"></label>
  <label><span>Zona</span><select name="zona"><option value="">Todas</option><?php foreach($zones as $o):$v=mcc_value($o,'ZONA');?><option value="<?php echo h($v);?>" <?php echo $zone===$v?'selected':'';?>><?php echo h($v);?></option><?php endforeach;?></select></label>
  <label><span>Batería</span><select name="bateria"><option value="">Todas</option><?php foreach($batteries as $o):$v=mcc_value($o,'BATERIA');?><option value="<?php echo h($v);?>" <?php echo $battery===$v?'selected':'';?>><?php echo h($v);?></option><?php endforeach;?></select></label>
  <label><span>Instalación</span><input name="instalacion" value="<?php echo h($installation);?>" placeholder="Todas"></label>
  <label><span>PLC001</span><select name="plc001"><option value="">Todos</option><?php foreach($plcs as $o):$v=mcc_value($o,'PLC001');?><option value="<?php echo h($v);?>" <?php echo $plc===$v?'selected':'';?>><?php echo h($v);?></option><?php endforeach;?></select></label>
  <label class="is-wide"><span>Buscar</span><input name="q" value="<?php echo h($query);?>" placeholder="Batería, instalación, zona o estado"></label>
  <button class="mcBtn is-primary" type="submit">Aplicar</button><a class="mcBtn" href="<?php echo h($exportUrl);?>"><?php echo icon('download');?> Exportar Excel</a>
</form>

<section class="mcCloseTotals">
  <article class="is-blue"><span>FT001</span><strong><?php echo mcc_number($totals['FT001']);?></strong><small>Suma del cierre</small></article>
  <article class="is-petrol"><span>Acumulado Bruta</span><strong><?php echo mcc_number($totals['ACUM_BRUTA']);?></strong><small>Suma del cierre</small></article>
  <article class="is-cyan"><span>Acumulado Neta</span><strong><?php echo mcc_number($totals['ACUM_NETA']);?></strong><small>Suma del cierre</small></article>
  <article class="is-indigo"><span>Proyectado Bruta</span><strong><?php echo mcc_number($totals['PROY_BRUTA']);?></strong><small>Suma del cierre</small></article>
  <article class="is-green"><span>Proyectado Neta</span><strong><?php echo mcc_number($totals['PROY_NETA']);?></strong><small>Suma del cierre</small></article>
  <article class="is-orange"><span>Caudal instantáneo</span><strong><?php echo mcc_number($totals['CAUDAL_INSTANTANEO']);?></strong><small>Suma del cierre</small></article>
</section>

<section class="mcCard mcCloseChartCard"><div class="mcCardHead"><div><h2>Evolución semanal del cierre</h2><p><?php echo h(date('d/m/Y',strtotime($weekFrom)));?> al <?php echo h(date('d/m/Y',strtotime($selectedDate)));?> · acumulados y caudal instantáneo con vista configurable.</p></div><div class="mcChartTools"><label>Gráfico<select data-mcc-chart-type><option value="combined">Combinado</option><option value="bar">Barras</option><option value="line">Líneas</option><option value="doughnut">Dona</option></select></label><label>Escala<select data-mcc-scale><option value="auto">Automática</option><option value="linear">Lineal</option><option value="logarithmic">Logarítmica</option></select></label><label>Acum. Bruta<input type="color" value="#0b7189" data-mcc-color-primary></label><label>Acum. Neta<input type="color" value="#16457f" data-mcc-color-secondary></label><label>Caudal<input type="color" value="#38a3d1" data-mcc-color-tertiary></label><button class="mcBtn" type="button" data-mcc-save-prefs>Guardar preferencias</button></div></div><div class="mcCloseChart"><canvas id="mccWeeklyChart" data-clear-chart-local="true"></canvas></div></section>

<section class="mcCard" data-mcc-table-wrap>
  <div class="mcCardHead"><div><h2>Detalle del cierre diario</h2><p><?php echo count($rows);?> registros · solo lectura · fuente local.</p></div><span class="mcHint">Filtros y orden por columna</span></div>
  <div class="tablescroll"><table class="grid mcCloseTable" data-mcc-table>
    <thead><tr>
      <th><button data-mcc-sort data-column="0" data-kind="date">Fecha</button></th><th><button data-mcc-sort data-column="1">Zona</button></th><th><button data-mcc-sort data-column="2">Batería</button></th><th><button data-mcc-sort data-column="3">Instalación</button></th><th><button data-mcc-sort data-column="4" data-kind="number">FT001</button></th><th><button data-mcc-sort data-column="5" data-kind="number">Acum. Bruta</button></th><th><button data-mcc-sort data-column="6" data-kind="number">Proy. Bruta</button></th><th><button data-mcc-sort data-column="7" data-kind="number">Caudal Inst.</button></th><th><button data-mcc-sort data-column="8" data-kind="number">Proy. Neta</button></th><th><button data-mcc-sort data-column="9" data-kind="number">Acum. Neta</button></th><th><button data-mcc-sort data-column="10" data-kind="number">LT001</button></th><th><button data-mcc-sort data-column="11" data-kind="number">Prom. Bruta 7 días</button></th><th><button data-mcc-sort data-column="12">PLC001</button></th>
    </tr><tr class="mcFilterRow">
      <th><input data-mcc-filter data-column="0" placeholder="Fecha"></th><th><select data-mcc-filter data-column="1"><option value="">Todas</option><?php foreach($zones as $o):?><option><?php echo h(mcc_value($o,'ZONA'));?></option><?php endforeach;?></select></th><th><input data-mcc-filter data-column="2" placeholder="Batería"></th><th><input data-mcc-filter data-column="3" placeholder="Instalación"></th><th><input data-mcc-filter data-column="4" placeholder="Valor"></th><th><input data-mcc-filter data-column="5" placeholder="Valor"></th><th><input data-mcc-filter data-column="6" placeholder="Valor"></th><th><input data-mcc-filter data-column="7" placeholder="Valor"></th><th><input data-mcc-filter data-column="8" placeholder="Valor"></th><th><input data-mcc-filter data-column="9" placeholder="Valor"></th><th><input data-mcc-filter data-column="10" placeholder="Valor"></th><th><input data-mcc-filter data-column="11" placeholder="Valor"></th><th><select data-mcc-filter data-column="12"><option value="">Todos</option><?php foreach($plcs as $o):?><option><?php echo h(mcc_value($o,'PLC001'));?></option><?php endforeach;?></select></th>
    </tr></thead>
    <tbody><?php if(!$rows):?><tr><td colspan="13" class="mcEmpty">No hay datos para el cierre y los filtros seleccionados.</td></tr><?php else:foreach($rows as $r):$fd=mcc_value($r,'FECHA_DATO');?><tr data-mcc-row><td data-sort="<?php echo h($fd);?>"><?php echo h(date('d/m/Y',strtotime($fd)));?></td><td><?php echo h(mcc_value($r,'ZONA')?:'Sin vincular');?></td><td><b><?php echo h(mcc_value($r,'BATERIA'));?></b></td><td><?php echo h(mcc_value($r,'NOMBRE_INSTALACION'));?></td><td data-sort="<?php echo h(mcc_value($r,'FT001',0));?>"><?php echo mcc_number(mcc_value($r,'FT001',0));?></td><td data-sort="<?php echo h(mcc_value($r,'ACUM_BRUTA',0));?>"><?php echo mcc_number(mcc_value($r,'ACUM_BRUTA',0));?></td><td data-sort="<?php echo h(mcc_value($r,'PROY_BRUTA',0));?>"><?php echo mcc_number(mcc_value($r,'PROY_BRUTA',0));?></td><td data-sort="<?php echo h(mcc_value($r,'CAUDAL_INSTANTANEO',0));?>"><?php echo mcc_number(mcc_value($r,'CAUDAL_INSTANTANEO',0));?></td><td data-sort="<?php echo h(mcc_value($r,'PROY_NETA',0));?>"><?php echo mcc_number(mcc_value($r,'PROY_NETA',0));?></td><td data-sort="<?php echo h(mcc_value($r,'ACUM_NETA',0));?>"><?php echo mcc_number(mcc_value($r,'ACUM_NETA',0));?></td><td data-sort="<?php echo h(mcc_value($r,'LT001',0));?>"><?php echo mcc_number(mcc_value($r,'LT001',0));?></td><td data-sort="<?php echo h(mcc_value($r,'PROMEDIO_BRUTA_7_DIAS',0));?>"><?php echo mcc_number(mcc_value($r,'PROMEDIO_BRUTA_7_DIAS',0));?></td><td><?php echo h(mcc_value($r,'PLC001')?:'—');?></td></tr><?php endforeach;endif;?></tbody>
  </table></div>
  <div class="mcPager"><span data-mcc-count></span><div><button type="button" data-mcc-prev>‹</button><span data-mcc-pages></span><button type="button" data-mcc-next>›</button></div><label>Mostrar <select data-mcc-page-size><option>25</option><option selected>50</option><option>100</option><option value="10000">Todos</option></select></label></div>
</section>
<?php endif;?></main></div>
<?php if($ready):?><script>window.CLEAR_MASICOS_CIERRE=<?php echo json_encode(['chart'=>$chart],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);?>;</script><script src="assets/js/chart.umd.js"></script><?php endif;?>
<script src="assets/js/app.js?v=20260825-mc15"></script><script src="assets/js/micros_contables_cierre.js?v=20260826-chart-fix-1"></script>
</body></html>
