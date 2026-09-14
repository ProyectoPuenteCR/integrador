<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';
require_once __DIR__.'/includes/novedades_semanales_common.php';

auth_require();
permissions_require_menu('micros_contables');
auth_start();

$cfg=require __DIR__.'/config.php';
date_default_timezone_set($cfg['app']['tz']??'UTC');
$db=clear_db();
$APP_USER=auth_user();
$APP_ROLE=auth_es_admin()?'Administrador':'Operador';
$ACTIVE='micros_contables';
$canDelete=auth_es_admin();
$ready=$db->ok()&&(int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_MICROS_CONTABLES',N'U') IS NOT NULL AND OBJECT_ID(N'dbo.CLEAR_VW_MICROS_CONTABLES',N'V') IS NOT NULL AND OBJECT_ID(N'dbo.CLEAR_VW_MASICOS_DIARIOS',N'V') IS NOT NULL THEN 1 ELSE 0 END")===1;
$reportReady=$ready&&ns_report_ready($db)&&permissions_can_menu('novedades_semanales_reporte');
$csrf=$_SESSION['clear_csrf_micros']??'';
if($csrf===''){$csrf=bin2hex(random_bytes(24));$_SESSION['clear_csrf_micros']=$csrf;}
$message='';$messageType='ok';

function mc_clean($value,$max=255){$value=trim((string)$value);if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))return '';return function_exists('mb_substr')?mb_substr($value,0,$max,'UTF-8'):substr($value,0,$max);}
function mc_date($value){$value=trim((string)$value);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $d&&$d->format('Y-m-d')===$value?$value:'';}
function mc_decimal($value){$value=trim((string)$value);if($value==='')return null;$value=str_replace(['. ', ' '],'',$value);if(strpos($value,',')!==false)$value=str_replace(['.',','],['','.'],$value);return is_numeric($value)?round((float)$value,3):null;}
function mc_number($value){return $value===null||$value===''?'—':number_format((float)$value,3,',','.');}
function mc_csv($value){$value=str_replace(["\r","\n"],' ',(string)$value);return '"'.str_replace('"','""',$value).'"';}
function mc_key($row,$key,$default=''){foreach($row as $k=>$v)if(strcasecmp((string)$k,(string)$key)===0)return $v;return $default;}
function mc_battery_keys($value){
    $base=strtoupper(trim((string)$value));
    $base=preg_replace('/[\s\-_\.\/]+/u','',$base);
    $normalize=function($v){
        if(preg_match('/^([A-ZÑ]+)0*([0-9]+)$/u',$v,$m))return $m[1].(string)((int)$m[2]);
        return $v;
    };
    $keys=[$normalize($base)];
    if(strlen($base)>1&&substr($base,0,1)==='B')$keys[]=$normalize(substr($base,1));
    return array_values(array_unique(array_filter($keys,function($v){return $v!=='';})));
}
function mc_ids($value,$limit=500){
    $ids=[];
    foreach(explode(',',(string)$value) as $raw){$id=(int)trim($raw);if($id>0)$ids[$id]=$id;if(count($ids)>=$limit)break;}
    return array_values($ids);
}

if($_SERVER['REQUEST_METHOD']==='POST'&&$ready){
    if(!hash_equals($csrf,(string)($_POST['csrf']??''))){$message='La sesión del formulario venció. Recargá la pantalla.';$messageType='err';}
    else{
        $action=(string)($_POST['accion']??'');
        $id=(int)($_POST['id']??0);
        if($action==='eliminar'){
            if(!$canDelete){$message='Solamente un administrador puede eliminar registros.';$messageType='err';}
            else{
                $ok=$id>0&&$db->execute("UPDATE dbo.CLEAR_MICROS_CONTABLES SET ACTIVO=0,EDITADO_MANUAL=1,FECHA_MODIFICACION=SYSDATETIME(),USUARIO_MODIFICACION=? WHERE ID=?",[$APP_USER,$id]);
                $message=$ok?'Registro eliminado de Micros contables.':'No se pudo eliminar el registro. '.$db->error();$messageType=$ok?'ok':'err';
                if($ok)audit_log('MICRO_CONTABLE_ELIMINADO','micros_contables',json_encode(['id'=>$id],JSON_UNESCAPED_UNICODE));
            }
        }elseif($action==='guardar'){
            $record=[
                'fecha'=>mc_date($_POST['fecha_dato']??''),'name'=>mc_clean($_POST['name']??'',255),
                'instalacion'=>mc_clean($_POST['nombre_instalacion']??'',255),'bateria'=>mc_clean($_POST['bateria']??'',100),
                'zona'=>mc_clean($_POST['zona']??'',150),'modalidad'=>mc_clean($_POST['modalidad']??'',30),
                'ft001'=>mc_decimal($_POST['ft001']??''),'acum_bruta'=>mc_decimal($_POST['acum_bruta']??''),
                'proy_bruta'=>mc_decimal($_POST['proy_bruta']??''),'caudal'=>mc_decimal($_POST['caudal_instantaneo']??''),
                'proy_neta'=>mc_decimal($_POST['proy_neta']??''),'acum_neta'=>mc_decimal($_POST['acum_neta']??''),
                'lt001'=>mc_decimal($_POST['lt001']??''),'promedio'=>mc_decimal($_POST['promedio_bruta_7_dias']??''),
                'plc001'=>mc_clean($_POST['plc001']??'',100),'supervisor'=>mc_clean($_POST['supervisor']??'',200),
                'jefe'=>mc_clean($_POST['jefe_zona']??'',200),'observacion'=>mc_clean($_POST['observacion']??'',2000)
            ];
            $allowed=['POR MEDIDOR','PRODUCCION TEORICA','SIN DEFINIR'];
            if($record['fecha']===''||$record['bateria']===''){$message='Completá Fecha y Batería.';$messageType='err';}
            elseif(!in_array($record['modalidad'],$allowed,true)){$message='La modalidad seleccionada no es válida.';$messageType='err';}
            else{
                /* La fecha+batería mandan: se vuelven a resolver en el servidor
                   para no depender del autocompletado del navegador. */
                $masicoId=null;$masicoFecha=null;$keys=mc_battery_keys($record['bateria']);$dailyMatch=[];
                if($keys){
                    $marks=implode(',',array_fill(0,count($keys),'?'));
                    $dailyRowsFound=$db->all("SELECT TOP (1) * FROM dbo.CLEAR_VW_MASICOS_DIARIOS WHERE FECHA_DATO=CONVERT(date,?,23) AND BATERIA_CLAVE IN ($marks) ORDER BY CASE WHEN BATERIA=? THEN 0 ELSE 1 END,ID DESC",array_merge([$record['fecha']],$keys,[$record['bateria']]));
                    if($dailyRowsFound)$dailyMatch=$dailyRowsFound[0];
                }
                if($dailyMatch){
                    $masicoId=(int)mc_key($dailyMatch,'ID');$masicoFecha=mc_key($dailyMatch,'FECHA_INSERCION');
                    $record['name']=mc_key($dailyMatch,'Name',$record['name']);
                    $record['instalacion']=mc_key($dailyMatch,'NOMBRE_INSTALACION',$record['instalacion']);
                    /* En Partes se guarda el nombre operativo del maestro (CE 08,
                       CG 011, etc.). La segunda grilla conserva el código técnico
                       original de MASICOS (BCE008, BCG011, etc.). */
                    $record['bateria']=mc_key($dailyMatch,'BATERIA_MAESTRO')?:mc_key($dailyMatch,'BATERIA',$record['bateria']);
                    foreach(['ft001'=>'FT001','acum_bruta'=>'ACUM_BRUTA','proy_bruta'=>'PROY_BRUTA','caudal'=>'CAUDAL_INSTANTANEO','proy_neta'=>'PROY_NETA','acum_neta'=>'ACUM_NETA','lt001'=>'LT001','promedio'=>'PROMEDIO_BRUTA_7_DIAS'] as $rk=>$dk)$record[$rk]=mc_decimal(mc_key($dailyMatch,$dk));
                    $record['plc001']=mc_clean(mc_key($dailyMatch,'PLC001'),100);
                    if(trim((string)mc_key($dailyMatch,'ZONA'))!=='')$record['zona']=mc_clean(mc_key($dailyMatch,'ZONA'),150);
                    if(trim((string)mc_key($dailyMatch,'SUPERVISOR'))!=='')$record['supervisor']=mc_clean(mc_key($dailyMatch,'SUPERVISOR'),200);
                    if(trim((string)mc_key($dailyMatch,'JEFE_ZONA'))!=='')$record['jefe']=mc_clean(mc_key($dailyMatch,'JEFE_ZONA'),200);
                }else{
                    foreach($db->all("SELECT BATERIA,ZONA,SUPERVISOR,JEFE_ZONA FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES WHERE ACTIVO=1 ORDER BY ID") as $resp){
                        if(!array_intersect($keys,mc_battery_keys(mc_key($resp,'BATERIA'))))continue;
                        $record['zona']=mc_clean(mc_key($resp,'ZONA'),150);$record['supervisor']=mc_clean(mc_key($resp,'SUPERVISOR'),200);$record['jefe']=mc_clean(mc_key($resp,'JEFE_ZONA'),200);break;
                    }
                }
                $params=[$masicoId,$masicoFecha,$record['fecha'],$record['name'],$record['instalacion'],$record['bateria'],$record['zona'],$record['modalidad'],$record['ft001'],$record['acum_bruta'],$record['proy_bruta'],$record['caudal'],$record['proy_neta'],$record['acum_neta'],$record['lt001'],$record['promedio'],$record['plc001'],$record['supervisor'],$record['jefe'],$record['observacion'],$APP_USER];
                if($id>0){
                    $params[]=$id;
                    $ok=$db->execute("UPDATE dbo.CLEAR_MICROS_CONTABLES SET MASICO_HISTORICO_ID=?,FECHA_INSERCION_MASICO=?,FECHA_DATO=CONVERT(date,?,23),[Name]=?,NOMBRE_INSTALACION=?,BATERIA=?,ZONA=?,MODALIDAD=?,FT001=?,ACUM_BRUTA=?,PROY_BRUTA=?,CAUDAL_INSTANTANEO=?,PROY_NETA=?,ACUM_NETA=?,LT001=?,PROMEDIO_BRUTA_7_DIAS=?,PLC001=?,SUPERVISOR=?,JEFE_ZONA=?,OBSERVACION=?,EDITADO_MANUAL=1,ACTIVO=1,FECHA_MODIFICACION=SYSDATETIME(),USUARIO_MODIFICACION=? WHERE ID=?",$params);
                }else{
                    $insertParams=array_slice($params,0,20);$insertParams[]=$APP_USER;$insertParams[]=$APP_USER;
                    $ok=$db->execute("INSERT dbo.CLEAR_MICROS_CONTABLES(MASICO_HISTORICO_ID,FECHA_INSERCION_MASICO,ORIGEN,EDITADO_MANUAL,FECHA_DATO,[Name],NOMBRE_INSTALACION,BATERIA,ZONA,MODALIDAD,FT001,ACUM_BRUTA,PROY_BRUTA,CAUDAL_INSTANTANEO,PROY_NETA,ACUM_NETA,LT001,PROMEDIO_BRUTA_7_DIAS,PLC001,SUPERVISOR,JEFE_ZONA,OBSERVACION,ACTIVO,USUARIO_ALTA,USUARIO_MODIFICACION) VALUES(?,?,N'MANUAL',1,CONVERT(date,?,23),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)",$insertParams);
                }
                $message=$ok?($id>0?'Parte actualizado correctamente.':'Nuevo parte agregado correctamente.'):'No se pudo guardar. '.$db->error();$messageType=$ok?'ok':'err';
                if($ok)audit_log($id>0?'MICRO_CONTABLE_ACTUALIZADO':'MICRO_CONTABLE_CREADO','micros_contables',json_encode(['id'=>$id,'fecha'=>$record['fecha'],'bateria'=>$record['bateria']],JSON_UNESCAPED_UNICODE));
            }
        }
    }
}

$today=new DateTimeImmutable('today');
$from=mc_date($_GET['desde']??'')?:$today->modify('-13 days')->format('Y-m-d');
$to=mc_date($_GET['hasta']??'')?:$today->format('Y-m-d');
if($from>$to){$tmp=$from;$from=$to;$to=$tmp;}
$zone=mc_clean($_GET['zona']??'',150);$battery=mc_clean($_GET['bateria']??'',100);$supervisor=mc_clean($_GET['supervisor']??'',200);$mode=mc_clean($_GET['modalidad']??'',30);$query=mc_clean($_GET['q']??'',255);
$parts=[];$dailyRows=[];$responsibles=[];$zones=[];$batteries=[];$supervisors=[];
if($ready){
    $partConditions=['ACTIVO=1',"(ORIGEN=N'MANUAL' OR EDITADO_MANUAL=1 OR NULLIF(LTRIM(RTRIM(OBSERVACION)),N'') IS NOT NULL)",'FECHA_DATO BETWEEN CONVERT(date,?,23) AND CONVERT(date,?,23)'];$partParams=[$from,$to];
    if($zone!==''){$partConditions[]='ZONA=?';$partParams[]=$zone;}if($battery!==''){$partConditions[]='(dbo.FN_CLEAR_NORMALIZAR_BATERIA(BATERIA,0)=dbo.FN_CLEAR_NORMALIZAR_BATERIA(?,0) OR dbo.FN_CLEAR_NORMALIZAR_BATERIA(BATERIA,0)=dbo.FN_CLEAR_NORMALIZAR_BATERIA(?,1) OR dbo.FN_CLEAR_NORMALIZAR_BATERIA(BATERIA,1)=dbo.FN_CLEAR_NORMALIZAR_BATERIA(?,0) OR dbo.FN_CLEAR_NORMALIZAR_BATERIA(BATERIA,1)=dbo.FN_CLEAR_NORMALIZAR_BATERIA(?,1))';$partParams[]=$battery;$partParams[]=$battery;$partParams[]=$battery;$partParams[]=$battery;}if($supervisor!==''){$partConditions[]='SUPERVISOR=?';$partParams[]=$supervisor;}if($mode!==''){$partConditions[]='MODALIDAD=?';$partParams[]=$mode;}
    if($query!==''){$partConditions[]="CONCAT(ISNULL([Name],N''),N' ',ISNULL(NOMBRE_INSTALACION,N''),N' ',ISNULL(BATERIA,N''),N' ',ISNULL(ZONA,N''),N' ',ISNULL(SUPERVISOR,N''),N' ',ISNULL(JEFE_ZONA,N''),N' ',ISNULL(OBSERVACION,N'')) LIKE ?";$partParams[]='%'.$query.'%';}
    $parts=$db->all("SELECT TOP (10000) ID,MASICO_HISTORICO_ID,ORIGEN,EDITADO_MANUAL,CONVERT(varchar(10),FECHA_DATO,23) FECHA_DATO,CONVERT(varchar(19),FECHA_INSERCION_MASICO,120) FECHA_INSERCION_MASICO,[Name],NOMBRE_INSTALACION,BATERIA,ZONA,MODALIDAD,FT001,ACUM_BRUTA,PROY_BRUTA,CAUDAL_INSTANTANEO,PROY_NETA,ACUM_NETA,LT001,PROMEDIO_BRUTA_7_DIAS,PLC001,SUPERVISOR,JEFE_ZONA,OBSERVACION,CONVERT(varchar(19),FECHA_MODIFICACION,120) FECHA_MODIFICACION,USUARIO_MODIFICACION FROM dbo.CLEAR_VW_MICROS_CONTABLES WHERE ".implode(' AND ',$partConditions)." ORDER BY FECHA_DATO DESC,BATERIA,NOMBRE_INSTALACION",$partParams);

    $dailyConditions=['FECHA_DATO BETWEEN CONVERT(date,?,23) AND CONVERT(date,?,23)'];$dailyParams=[$from,$to];
    if($zone!==''){$dailyConditions[]='ZONA=?';$dailyParams[]=$zone;}if($battery!==''){$dailyConditions[]='(dbo.FN_CLEAR_NORMALIZAR_BATERIA(BATERIA,1)=dbo.FN_CLEAR_NORMALIZAR_BATERIA(?,1) OR dbo.FN_CLEAR_NORMALIZAR_BATERIA(BATERIA,1)=dbo.FN_CLEAR_NORMALIZAR_BATERIA(?,0))';$dailyParams[]=$battery;$dailyParams[]=$battery;}if($supervisor!==''){$dailyConditions[]='SUPERVISOR=?';$dailyParams[]=$supervisor;}if($mode!==''){$dailyConditions[]="CASE WHEN ABS(ISNULL(FT001,0))>0 THEN N'POR MEDIDOR' ELSE N'PRODUCCION TEORICA' END=?";$dailyParams[]=$mode;}
    if($query!==''){$dailyConditions[]="CONCAT(ISNULL([Name],N''),N' ',ISNULL(NOMBRE_INSTALACION,N''),N' ',ISNULL(BATERIA,N''),N' ',ISNULL(ZONA,N''),N' ',ISNULL(SUPERVISOR,N''),N' ',ISNULL(JEFE_ZONA,N''),N' ',ISNULL(PLC001,N'')) LIKE ?";$dailyParams[]='%'.$query.'%';}
    $dailyRows=$db->all("SELECT TOP (10000) ID,CONVERT(varchar(10),FECHA_DATO,23) FECHA_DATO,CONVERT(varchar(19),FECHA_INSERCION,120) FECHA_INSERCION,[Name],NOMBRE_INSTALACION,BATERIA,BATERIA_CLAVE,BATERIA_MAESTRO,ZONA,SUPERVISOR,JEFE_ZONA,CASE WHEN ABS(ISNULL(FT001,0))>0 THEN N'POR MEDIDOR' ELSE N'PRODUCCION TEORICA' END MODALIDAD,FT001,ACUM_BRUTA,PROY_BRUTA,CAUDAL_INSTANTANEO,PROY_NETA,ACUM_NETA,LT001,PROMEDIO_BRUTA_7_DIAS,PLC001 FROM dbo.CLEAR_VW_MASICOS_DIARIOS WHERE ".implode(' AND ',$dailyConditions)." ORDER BY FECHA_DATO DESC,BATERIA,NOMBRE_INSTALACION",$dailyParams);

    $responsibles=$db->all("SELECT ID,BATERIA,ZONA,SUPERVISOR,JEFE_ZONA FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES WHERE ACTIVO=1 ORDER BY ZONA,BATERIA");
    $zones=$db->all("SELECT DISTINCT ZONA FROM (SELECT ZONA FROM dbo.CLEAR_VW_MASICOS_DIARIOS UNION SELECT ZONA FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES WHERE ACTIVO=1) Z WHERE ZONA IS NOT NULL AND LTRIM(RTRIM(ZONA))<>N'' ORDER BY ZONA");
    $batteries=$db->all("SELECT DISTINCT BATERIA FROM (SELECT BATERIA FROM dbo.CLEAR_VW_MASICOS_DIARIOS UNION SELECT BATERIA FROM dbo.CLEAR_MICROS_CONTABLES WHERE ACTIVO=1 UNION SELECT BATERIA FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES WHERE ACTIVO=1) B WHERE BATERIA IS NOT NULL AND LTRIM(RTRIM(BATERIA))<>N'' ORDER BY BATERIA");
    $supervisors=$db->all("SELECT DISTINCT SUPERVISOR FROM (SELECT SUPERVISOR FROM dbo.CLEAR_VW_MASICOS_DIARIOS UNION SELECT SUPERVISOR FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES WHERE ACTIVO=1) S WHERE SUPERVISOR IS NOT NULL AND LTRIM(RTRIM(SUPERVISOR))<>N'' ORDER BY SUPERVISOR");
}

if($ready&&isset($_GET['export'])){
    $exportType=$_GET['export']==='datos'?'datos':'partes';$selectedIds=$exportType==='partes'?mc_ids($_GET['ids']??''):[];$exportRows=$exportType==='datos'?$dailyRows:$parts;
    if($exportType==='partes'&&$selectedIds){
        $marks=implode(',',array_fill(0,count($selectedIds),'?'));
        $exportRows=$db->all("SELECT ID,CONVERT(varchar(10),FECHA_DATO,23) FECHA_DATO,ZONA,BATERIA,SUPERVISOR,JEFE_ZONA,OBSERVACION FROM dbo.CLEAR_VW_MICROS_CONTABLES WHERE ACTIVO=1 AND ID IN ($marks) ORDER BY FECHA_DATO DESC,BATERIA",$selectedIds);
    }
    audit_log('EXPORTACION','micros_contables','Exportación '.$exportType.' de '.count($exportRows).' registros');
    header('Content-Type:text/csv; charset=UTF-8');header('Content-Disposition:attachment; filename="Micros_contables_'.$exportType.'_'.date('Ymd_His').'.csv"');echo "\xEF\xBB\xBF";
    if($exportType==='partes'){
        echo "Fecha;Zona;Batería;Supervisor;Jefe de producción;Observaciones\r\n";
        foreach($exportRows as $r)echo implode(';',[mc_csv(mc_key($r,'FECHA_DATO')),mc_csv(mc_key($r,'ZONA')),mc_csv(mc_key($r,'BATERIA')),mc_csv(mc_key($r,'SUPERVISOR')),mc_csv(mc_key($r,'JEFE_ZONA')),mc_csv(mc_key($r,'OBSERVACION'))])."\r\n";
    }else{
        echo "Fecha;Batería;Instalación;FT001;Acum. Bruta;Proy. Bruta;Caudal instantáneo;Proy. Neta\r\n";
        foreach($dailyRows as $r)echo implode(';',[mc_csv(mc_key($r,'FECHA_DATO')),mc_csv(mc_key($r,'BATERIA')),mc_csv(mc_key($r,'NOMBRE_INSTALACION')),mc_csv(mc_key($r,'FT001')),mc_csv(mc_key($r,'ACUM_BRUTA')),mc_csv(mc_key($r,'PROY_BRUTA')),mc_csv(mc_key($r,'CAUDAL_INSTANTANEO')),mc_csv(mc_key($r,'PROY_NETA'))])."\r\n";
    }
    exit;
}

$partMap=[];$dailyMap=[];
foreach($parts as $r)$partMap[(string)mc_key($r,'ID')]=$r;
foreach($dailyRows as $r)$dailyMap[(string)mc_key($r,'ID')]=$r;
$currentQuery=$_GET;unset($currentQuery['export']);$exportParts=http_build_query(array_merge($currentQuery,['export'=>'partes']));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Micros contables · CLEAR</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260826-mc16">
  <link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260826-select-all-1">
  <link rel="stylesheet" href="assets/css/micros_contables.css?v=20260826-mc16">
</head>
<body><div class="app"><?php include __DIR__.'/includes/sidebar.php';?><main class="main"><?php include __DIR__.'/includes/topbar.php';?>
<div class="page__head mcHead"><div><h1 class="page__title">Micros contables</h1><div class="page__sub">Partes diarios por batería, responsables y observaciones</div></div><button class="mcBtn is-primary" type="button" data-mc-add><?php echo icon('plus');?> Agregar parte</button></div>
<?php if($message):?><div class="mcMessage <?php echo h($messageType);?>"><?php echo h($message);?></div><?php endif;?>
<?php if(!$ready):?><div class="mcMessage err">Faltan los objetos SQL de Micros contables. Ejecutá <code>SQL/CLEAR_MICROS_CONTABLES.sql</code> completo en LC_MDB.</div><?php else:?>

<form class="mcToolbar" method="get">
  <label><span>Desde</span><input type="date" name="desde" value="<?php echo h($from);?>"></label>
  <label><span>Hasta</span><input type="date" name="hasta" value="<?php echo h($to);?>"></label>
  <label><span>Zona</span><select name="zona"><option value="">Todas</option><?php foreach($zones as $o):$v=mc_key($o,'ZONA');?><option value="<?php echo h($v);?>" <?php echo $zone===$v?'selected':'';?>><?php echo h($v);?></option><?php endforeach;?></select></label>
  <label><span>Batería</span><select name="bateria"><option value="">Todas</option><?php foreach($batteries as $o):$v=mc_key($o,'BATERIA');?><option value="<?php echo h($v);?>" <?php echo $battery===$v?'selected':'';?>><?php echo h($v);?></option><?php endforeach;?></select></label>
  <label><span>Supervisor</span><select name="supervisor"><option value="">Todos</option><?php foreach($supervisors as $o):$v=mc_key($o,'SUPERVISOR');?><option value="<?php echo h($v);?>" <?php echo $supervisor===$v?'selected':'';?>><?php echo h($v);?></option><?php endforeach;?></select></label>
  <label class="is-wide"><span>Buscar</span><input name="q" value="<?php echo h($query);?>" placeholder="Batería, responsable u observación"></label>
  <button class="mcBtn is-primary" type="submit">Aplicar</button>
</form>

<section class="mcCard" data-mc-table-wrap>
  <div class="mcCardHead"><div><h2>Partes de Micros contables</h2><p>Seleccioná uno o varios partes para enviarlos o incorporarlos al reporte semanal. Clic fuera del check para editar.</p></div><div class="mcPartActions"><span class="mcSelectionCount" data-mc-selected-count>0 seleccionados</span><?php if($reportReady):?><button class="mcBtn is-report" type="button" data-mc-selected-report disabled><?php echo icon('plus');?> Agregar al reporte</button><button class="mcBtn" type="button" data-ns-report-open>Reporte <span class="nsReportCount" data-ns-report-count hidden>0</span></button><?php endif;?><button class="mcBtn" type="button" data-mc-selected-pdf disabled><?php echo icon('file');?> PDF</button><button class="mcBtn" type="button" data-mc-selected-excel disabled><?php echo icon('download');?> Excel</button><button class="mcBtn" type="button" data-mc-selected-email disabled><?php echo icon('message');?> Email</button><a class="mcBtn is-quiet" href="?<?php echo h($exportParts);?>" title="Exportar todos los partes filtrados"><?php echo icon('download');?> Excel completo</a></div></div>
  <div class="tablescroll"><table class="grid mcTable mcTable--parts" data-mc-table>
    <thead><tr>
      <th><div class="mcDateSelectHead"><input type="checkbox" data-mc-select-all aria-label="Seleccionar todos los partes filtrados"><button data-mc-sort data-column="0" data-kind="date">Fecha</button></div></th><th><button data-mc-sort data-column="1">Zona</button></th><th><button data-mc-sort data-column="2">Batería</button></th><th><button data-mc-sort data-column="3">Supervisor</button></th><th><button data-mc-sort data-column="4">Jefe de producción</button></th><th><button data-mc-sort data-column="5">Observaciones</button></th>
    </tr><tr class="mcFilterRow">
      <th><input data-mc-filter data-column="0" placeholder="Fecha"></th><th><select data-mc-filter data-column="1"><option value="">Todas</option><?php foreach($zones as $o):?><option><?php echo h(mc_key($o,'ZONA'));?></option><?php endforeach;?></select></th>
      <th><input data-mc-filter data-column="2" placeholder="Buscar batería"></th><th><input data-mc-filter data-column="3" placeholder="Buscar supervisor"></th><th><input data-mc-filter data-column="4" placeholder="Buscar jefe"></th><th><input data-mc-filter data-column="5" placeholder="Buscar observación"></th>
    </tr></thead>
    <tbody><?php if(!$parts):?><tr><td colspan="6" class="mcEmpty">No hay partes para el período y filtros seleccionados.</td></tr><?php else:foreach($parts as $r):$fd=mc_key($r,'FECHA_DATO');$partId=(int)mc_key($r,'ID');$reportPayload=ns_report_row_payload(['Fecha'=>$fd?date('d/m/Y',strtotime($fd)):'','Zona'=>mc_key($r,'ZONA'),'Batería'=>mc_key($r,'BATERIA'),'Supervisor'=>mc_key($r,'SUPERVISOR'),'Jefe de producción'=>mc_key($r,'JEFE_ZONA'),'Observaciones'=>mc_key($r,'OBSERVACION')]);$reportPayloadEncoded=base64_encode(json_encode($reportPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));?><tr data-mc-part-row="<?php echo $partId;?>" data-report-key="<?php echo h(ns_report_key('micros-contables',[$partId,$fd,mc_key($r,'BATERIA')]));?>" data-report-type="row" data-report-title="<?php echo h('Micro contable · '.mc_key($r,'BATERIA').' · '.$fd);?>" data-report-payload="<?php echo h($reportPayloadEncoded);?>" title="Clic en la fila para editar"><td data-sort="<?php echo h($fd);?>"><label class="mcRowSelect"><input type="checkbox" data-mc-select-part value="<?php echo $partId;?>" aria-label="Seleccionar parte del <?php echo h($fd);?>"><span><?php echo h(date('d/m/Y',strtotime($fd)));?></span></label></td><td><?php echo h(mc_key($r,'ZONA')?:'Sin vincular');?></td><td><b><?php echo h(mc_key($r,'BATERIA'));?></b></td><td><?php echo h(mc_key($r,'SUPERVISOR')?:'Sin vincular');?></td><td><?php echo h(mc_key($r,'JEFE_ZONA')?:'Sin vincular');?></td><td><?php echo h(mc_key($r,'OBSERVACION')?:'—');?></td></tr><?php endforeach;endif;?></tbody>
  </table></div>
  <div class="mcPager"><span data-mc-count></span><div><button type="button" data-mc-prev>‹</button><span data-mc-pages></span><button type="button" data-mc-next>›</button></div><label>Mostrar <select data-mc-page-size><option>25</option><option selected>50</option><option>100</option><option value="10000">Todos</option></select></label></div>
</section>

<?php endif;?></main></div>

<?php if($ready):?>
<div class="mcOverlay" id="mcOverlay" hidden></div>
<section class="mcModal" id="mcModal" hidden aria-hidden="true"><form method="post" id="mcForm">
  <input type="hidden" name="csrf" value="<?php echo h($csrf);?>"><input type="hidden" name="id" id="mcId" value="0">
  <div class="mcModalHead"><div><h2 id="mcModalTitle">Agregar parte</h2><p id="mcModalMeta">Nuevo registro manual</p></div><button type="button" data-mc-close>×</button></div>
  <div class="mcModalBody">
    <div class="mcSectionTitle">Información operativa</div>
    <div class="mcFormGrid">
      <label><span>Fecha *</span><input type="date" name="fecha_dato" id="mcFecha" required></label>
      <label><span>Batería *</span><input name="bateria" id="mcBateria" list="mcBaterias" maxlength="100" required></label>
      <label><span>Instalación *</span><input name="nombre_instalacion" id="mcInstalacion" list="mcInstalaciones" maxlength="255" required></label>
      <label><span>Nombre técnico</span><input name="name" id="mcName" maxlength="255"></label>
      <label><span>Zona</span><input name="zona" id="mcZona" maxlength="150" readonly></label>
      <label><span>Modalidad *</span><select name="modalidad" id="mcModalidad" required><option value="POR MEDIDOR">Por medidor</option><option value="PRODUCCION TEORICA">Producción teórica</option><option value="SIN DEFINIR">Sin definir</option></select></label>
      <label><span>Supervisor</span><input name="supervisor" id="mcSupervisor" maxlength="200" readonly></label>
      <label><span>Jefe de producción</span><input name="jefe_zona" id="mcJefe" maxlength="200" readonly></label>
    </div>
    <div class="mcAutoNote">Al elegir Fecha y Batería se completan Instalación, Zona, Supervisor, Jefe de producción y los datos másicos.</div>
    <label class="mcObservation"><span>Observaciones</span><textarea name="observacion" id="mcObservacion" maxlength="2000" rows="3"></textarea></label>
    <div class="mcSectionTitle">Datos másicos del día <small>solo lectura · tres decimales</small></div>
    <div class="mcMetricGrid">
      <label><span>FT001</span><input name="ft001" id="mcFT001" readonly></label><label><span>Acumulado Bruta</span><input name="acum_bruta" id="mcAcumBruta" readonly></label><label><span>Proyectado Bruta</span><input name="proy_bruta" id="mcProyBruta" readonly></label><label><span>Caudal Instantáneo</span><input name="caudal_instantaneo" id="mcCaudal" readonly></label><label><span>Proyectado Neta</span><input name="proy_neta" id="mcProyNeta" readonly></label><label><span>Acumulado Neta</span><input name="acum_neta" id="mcAcumNeta" readonly></label><label><span>LT001</span><input name="lt001" id="mcLT001" readonly></label><label><span>Promedio Bruta 7 días</span><input name="promedio_bruta_7_dias" id="mcPromedio" readonly></label><label><span>PLC001</span><input name="plc001" id="mcPLC001" readonly></label>
    </div>
    <div class="mcSource" id="mcSource">Elegí Fecha y Batería para buscar la fotografía diaria.</div>
  </div>
  <div class="mcModalFoot"><?php if($canDelete):?><button class="mcBtn is-danger" type="submit" name="accion" value="eliminar" data-mc-delete formnovalidate hidden><?php echo icon('trash');?> Eliminar</button><?php endif;?><span></span><button class="mcBtn" type="button" data-mc-close>Cancelar</button><button class="mcBtn is-primary" type="submit" name="accion" value="guardar">Guardar parte</button></div>
</form></section>
<datalist id="mcBaterias"><?php foreach($batteries as $o):?><option value="<?php echo h(mc_key($o,'BATERIA'));?>"><?php endforeach;?></datalist>
<datalist id="mcInstalaciones"><?php $seen=[];foreach(array_merge($dailyRows,$parts) as $r):$v=mc_key($r,'NOMBRE_INSTALACION');if($v===''||isset($seen[$v]))continue;$seen[$v]=1;?><option value="<?php echo h($v);?>"><?php endforeach;?></datalist>
<script>window.CLEAR_MICROS=<?php echo json_encode(['parts'=>$partMap,'daily'=>$dailyMap,'responsibles'=>$responsibles,'today'=>$today->format('Y-m-d'),'canDelete'=>$canDelete],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);?>;</script>
<?php endif;?>
<script src="assets/js/app.js?v=20260826-mc16"></script><script src="assets/js/novedades_semanales.js?v=20260826-select-all-1"></script><script src="assets/js/micros_contables.js?v=20260826-mc16"></script>
</body></html>
