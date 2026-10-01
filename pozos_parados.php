<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';
require_once __DIR__.'/includes/alarm_actions.php';

auth_require();
permissions_require_menu('pozos_parados');
$cfg=require __DIR__.'/config.php';
date_default_timezone_set($cfg['app']['tz']??'UTC');
$APP_USER=auth_user();
$APP_ROLE=auth_es_admin()?'Administrador':'Operador';
$ACTIVE='pozos_parados';
$db=clear_db();

/* Configuracion global de paros. Se guarda en SQL para todos los usuarios
   y cada guardado queda auditado con usuario, fecha y detalle de cambios. */
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && (string)($_POST['action']??'')==='save_paro_config'){
  header('Content-Type: application/json; charset=utf-8');
  if(!auth_es_admin()){
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Solo un administrador puede modificar la configuracion de paros.'],JSON_UNESCAPED_UNICODE);
    exit;
  }
  if(!$db->ok()){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$db->error()],JSON_UNESCAPED_UNICODE);
    exit;
  }
  $hasZ=(int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_POZOS_PARADOS_ZAFIRO_CONFIG',N'U') IS NULL THEN 0 ELSE 1 END")===1;
  $hasC=(int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_POZOS_PARADOS_CRITERIOS_CONFIG',N'U') IS NULL THEN 0 ELSE 1 END")===1;
  if(!$hasZ||!$hasC){
    http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>'Falta instalar la configuracion. Ejecuta SQL/CLEAR_REPORTE_POZOS_PARADOS.sql actualizado.'],JSON_UNESCAPED_UNICODE);
    exit;
  }

  $payload=json_decode((string)($_POST['config']??''),true);
  if(!is_array($payload)){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'La configuracion recibida no es valida.'],JSON_UNESCAPED_UNICODE);
    exit;
  }

  $changes=[];
  $user=$APP_USER?:'CLEAR';

  $zWanted=[];
  foreach((array)($payload['zafiro']??[]) as $item){
    if(!is_array($item))continue;
    $value=trim((string)($item['value']??''));
    if($value==='')continue;
    $zWanted[$value]=!empty($item['include'])?1:0;
  }
  foreach($db->all("SELECT ESTADO_ZAFIRO,INCLUIR FROM dbo.CLEAR_POZOS_PARADOS_ZAFIRO_CONFIG") as $row){
    $value=trim((string)($row['ESTADO_ZAFIRO']??''));
    if($value===''||!array_key_exists($value,$zWanted))continue;
    $old=((int)($row['INCLUIR']??1))===1?1:0; $new=$zWanted[$value];
    if($old===$new)continue;
    if(!$db->execute("UPDATE dbo.CLEAR_POZOS_PARADOS_ZAFIRO_CONFIG SET INCLUIR=?,FECHA_MODIFICACION=SYSDATETIME(),USUARIO_MODIFICACION=? WHERE ESTADO_ZAFIRO=?",[$new,$user,$value])){
      http_response_code(500); echo json_encode(['ok'=>false,'error'=>$db->error()],JSON_UNESCAPED_UNICODE); exit;
    }
    $changes[]=['tipo'=>'ZAFIRO','sistema'=>'TODOS','valor'=>$value,'antes'=>$old,'despues'=>$new];
  }

  $cWanted=[];
  foreach((array)($payload['criterios']??[]) as $item){
    if(!is_array($item))continue;
    $type=strtoupper(trim((string)($item['type']??'')));
    $system=strtoupper(trim((string)($item['system']??'')));
    $value=trim((string)($item['value']??''));
    if(!in_array($type,['DIAGNOSTICO','TELEMETRIA'],true)||!in_array($system,['MONITOREO','PCP','BES','TECSS'],true)||$value==='')continue;
    $cWanted[$type.'|'.$system.'|'.$value]=!empty($item['include'])?1:0;
  }
  foreach($db->all("SELECT TIPO,SISTEMA,VALOR,INCLUIR FROM dbo.CLEAR_POZOS_PARADOS_CRITERIOS_CONFIG") as $row){
    $type=strtoupper(trim((string)($row['TIPO']??'')));
    $system=strtoupper(trim((string)($row['SISTEMA']??'')));
    $value=trim((string)($row['VALOR']??''));
    $key=$type.'|'.$system.'|'.$value;
    if(!array_key_exists($key,$cWanted))continue;
    $old=((int)($row['INCLUIR']??1))===1?1:0; $new=$cWanted[$key];
    if($old===$new)continue;
    if(!$db->execute("UPDATE dbo.CLEAR_POZOS_PARADOS_CRITERIOS_CONFIG SET INCLUIR=?,FECHA_MODIFICACION=SYSDATETIME(),USUARIO_MODIFICACION=? WHERE TIPO=? AND SISTEMA=? AND VALOR=?",[$new,$user,$type,$system,$value])){
      http_response_code(500); echo json_encode(['ok'=>false,'error'=>$db->error()],JSON_UNESCAPED_UNICODE); exit;
    }
    $changes[]=['tipo'=>$type,'sistema'=>$system,'valor'=>$value,'antes'=>$old,'despues'=>$new];
  }

  if(!$db->execute("EXEC dbo.SP_CLEAR_POZOS_PARADOS_REFRESCAR")){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'La configuracion se guardo, pero fallo el refresco del reporte. '.$db->error()],JSON_UNESCAPED_UNICODE);
    exit;
  }

  if($changes){
    audit_log('CONFIG_PAROS_GUARDADA','pozos_parados',json_encode([
      'cantidad'=>count($changes),
      'cambios'=>$changes
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$user);
  }
  echo json_encode(['ok'=>true,'changes'=>count($changes),'message'=>$changes?'Configuracion guardada y aplicada.':'No habia cambios para guardar.'],JSON_UNESCAPED_UNICODE);
  exit;
}

$ready=$db->ok() && (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_POZOS_PARADOS_CACHE',N'U') IS NULL THEN 0 ELSE 1 END")===1;
$rows=[];$lastCache=null;
$auxMap=[];$lastAlarmMap=[];$commentMap=[];$zafiroConfig=[];$criteriaConfig=[];$configAudit=[];$canViewComments=permissions_can('comments.view');
if($ready){
  $rows=$db->all("SELECT SISTEMA,POZO,BATERIA,ESTADO_TELEMETRIA,ESTADO_POZO,ESTADO_ZAFIRO,METODO_ZAFIRO,RPM,VARIADOR,LLAVE,LLAVE_AUTO,PRODUCCION_PETROLEO,PERDIDA_INSTANTANEA,PERDIDA_24H,FECHA_DATO,FECHA_CACHE FROM dbo.CLEAR_POZOS_PARADOS_CACHE ORDER BY CASE SISTEMA WHEN 'MONITOREO' THEN 1 WHEN 'PCP' THEN 2 WHEN 'BES' THEN 3 WHEN 'TECSS' THEN 4 ELSE 9 END,POZO");
  $lastCache=$db->scalar("SELECT MAX(FECHA_CACHE) FROM dbo.CLEAR_POZOS_PARADOS_CACHE");

  /* Reutiliza la cache local de la grilla general: una sola consulta pequeña
     para ALM + PANTALLA, nunca escanea FIXALARMS ni consulta RTQP desde la web. */
  $hasGeneral=(int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE',N'U') IS NULL THEN 0 ELSE 1 END")===1;
  if($hasGeneral){
    $hasScreen=(int)$db->scalar("SELECT CASE WHEN COL_LENGTH(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE',N'PANTALLA') IS NULL THEN 0 ELSE 1 END")===1;
    $auxSql=$hasScreen
      ?"SELECT POZO,ALM,PANTALLA FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE WHERE POZO IS NOT NULL"
      :"SELECT POZO,ALM,CAST(NULL AS nvarchar(1000)) AS PANTALLA FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE WHERE POZO IS NOT NULL";
    foreach($db->all($auxSql) as $a){
      $k=pp_key($a['POZO']??''); if($k==='')continue;
      $alm=(int)($a['ALM']??0);
      if(!isset($auxMap[$k])||$alm>(int)($auxMap[$k]['ALM']??0))$auxMap[$k]=['ALM'=>$alm,'PANTALLA'=>trim((string)($a['PANTALLA']??''))];
      elseif(($auxMap[$k]['PANTALLA']??'')===''&&trim((string)($a['PANTALLA']??''))!=='')$auxMap[$k]['PANTALLA']=trim((string)$a['PANTALLA']);
    }
  }

  /* Criterio Zafiro global. Si el script actualizado aun no fue instalado,
     la pantalla conserva el comportamiento anterior sin romper la grilla. */
  $hasZafiroCfg=(int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_POZOS_PARADOS_ZAFIRO_CONFIG',N'U') IS NULL THEN 0 ELSE 1 END")===1;
  if($hasZafiroCfg){
    foreach($db->all("SELECT ESTADO_ZAFIRO,INCLUIR,FECHA_MODIFICACION,USUARIO_MODIFICACION
                      FROM dbo.CLEAR_POZOS_PARADOS_ZAFIRO_CONFIG
                      ORDER BY ESTADO_ZAFIRO") as $zc){
      $name=trim((string)($zc['ESTADO_ZAFIRO']??''));
      if($name!=='')$zafiroConfig[$name]=((int)($zc['INCLUIR']??1))===1;
    }
  }

  $hasCriteriaCfg=(int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_POZOS_PARADOS_CRITERIOS_CONFIG',N'U') IS NULL THEN 0 ELSE 1 END")===1;
  if($hasCriteriaCfg){
    foreach($db->all("SELECT TIPO,SISTEMA,VALOR,INCLUIR,FECHA_MODIFICACION,USUARIO_MODIFICACION
                      FROM dbo.CLEAR_POZOS_PARADOS_CRITERIOS_CONFIG
                      ORDER BY SISTEMA,TIPO,VALOR") as $cc){
      $type=strtoupper(trim((string)($cc['TIPO']??'')));
      $system=strtoupper(trim((string)($cc['SISTEMA']??'')));
      $value=trim((string)($cc['VALOR']??''));
      if($type!==''&&$system!==''&&$value!=='')$criteriaConfig[$system][$type][$value]=((int)($cc['INCLUIR']??1))===1;
    }
  }
  if((int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_AUDIT_LOG',N'U') IS NULL THEN 0 ELSE 1 END")===1){
    $configAudit=$db->all("SELECT TOP 5 FECHA,USUARIO,DETALLE FROM dbo.CLEAR_AUDIT_LOG
                           WHERE MODULO='pozos_parados' AND ACCION='CONFIG_PAROS_GUARDADA'
                           ORDER BY FECHA DESC");
  }

  /* Ultima alarma SCADA de las ultimas 24 h, solo para los pozos que estan
     en el cache actual. Se muestra la descripcion, no solo el contador. */
  if($rows){
    $wellKeys=[];
    foreach($rows as $r){$w=trim((string)($r['POZO']??''));if($w!=='')$wellKeys[$w]=1;}
    foreach(array_chunk(array_keys($wellKeys),120) as $chunk){
      $ph=implode(',',array_fill(0,count($chunk),'?'));
      $sqlLast=";WITH A AS(
        SELECT ALM_ALMEXTFLD2 AS POZO,
               COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(1000),ALM_DESCR))),''),CONVERT(nvarchar(1000),ALM_TAGNAME)) AS DESCRIPCION,
               ALM_NATIVETIMEIN,
               ROW_NUMBER() OVER(PARTITION BY ALM_ALMEXTFLD2 ORDER BY ALM_NATIVETIMEIN DESC) AS RN
        FROM dbo.FIXALARMS
        WHERE ALM_NATIVETIMEIN>=DATEADD(hour,-24,SYSDATETIME())
          AND ALM_ALMEXTFLD2 IN ($ph)
      )
      SELECT POZO,DESCRIPCION,ALM_NATIVETIMEIN FROM A WHERE RN=1";
      foreach($db->all($sqlLast,$chunk) as $la){
        $k=pp_key($la['POZO']??''); if($k==='')continue;
        $lastAlarmMap[$k]=[
          'DESCRIPCION'=>trim((string)($la['DESCRIPCION']??'')),
          'FECHA'=>$la['ALM_NATIVETIMEIN']??null
        ];
      }
    }
  }

  if($canViewComments&&$rows){
    $subjects=[];
    foreach($rows as $r){$w=trim((string)($r['POZO']??''));if($w!=='')$subjects[]=clear_alarm_comment_subject($w,'pozo');}
    foreach(array_chunk(array_values(array_unique($subjects)),1000) as $chunk){
      foreach(clear_alarm_comments_load_subjects_sql($chunk) as $key=>$comment)$commentMap[$key]=$comment;
    }
  }
}
function pp_key($v){
  $s=strtoupper(trim((string)$v));
  $s=strtr($s,['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
  $s=preg_replace('/^YPF[._-]?SC[._-]?/i','',$s);
  return preg_replace('/[^A-Z0-9]/','',$s);
}
function pp_n($v,$d=2){return $v===null||$v===''?'—':number_format((float)$v,$d,',','.');}
function pp_d($v){if(!$v)return '—';try{return (new DateTime((string)$v))->format('d/m/Y H:i');}catch(Throwable $e){return (string)$v;}}
$counts=['MONITOREO'=>0,'PCP'=>0,'BES'=>0,'TECSS'=>0];
$totalOil=0.0;$lossNow=0.0;$loss24=0.0;
foreach($rows as $r){$s=(string)$r['SISTEMA'];if(isset($counts[$s]))$counts[$s]++;if($r['PRODUCCION_PETROLEO']!==null)$totalOil+=(float)$r['PRODUCCION_PETROLEO'];if($r['PERDIDA_INSTANTANEA']!==null)$lossNow+=(float)$r['PERDIDA_INSTANTANEA'];if($r['PERDIDA_24H']!==null)$loss24+=(float)$r['PERDIDA_24H'];}
$total=count($rows);
$telemetryStates=[];$zafiroStates=[];$zafiroMethods=[];
foreach($rows as $r){
  $v=trim((string)($r['ESTADO_TELEMETRIA']??'')); if($v!=='')$telemetryStates[$v]=1;
  $v=trim((string)($r['ESTADO_ZAFIRO']??'')); if($v!=='')$zafiroStates[$v]=1;
  $v=trim((string)($r['METODO_ZAFIRO']??'')); if($v!=='')$zafiroMethods[$v]=1;
}
foreach(array_keys($zafiroConfig) as $v){if($v!=='')$zafiroStates[$v]=1;}
$telemetryStates=array_keys($telemetryStates); sort($telemetryStates,SORT_NATURAL|SORT_FLAG_CASE);
$zafiroStates=array_keys($zafiroStates); sort($zafiroStates,SORT_NATURAL|SORT_FLAG_CASE);
$zafiroMethods=array_keys($zafiroMethods); sort($zafiroMethods,SORT_NATURAL|SORT_FLAG_CASE);
$diagnosticStates=['PARO REAL','PROBABLE PARO','INCONSISTENCIA','VERIFICAR PARO','PARADO','PARO CONTROLADO'];
$paroSystems=['MONITOREO','PCP','BES','TECSS'];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reporte de Pozos Parados · CLEAR</title>
<link rel="stylesheet" href="assets/css/app.css?v=20260929-pp1">
<link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260826-central-1">
<link rel="stylesheet" href="assets/css/telemetry_modal.css?v=3.1.9">
<link rel="stylesheet" href="assets/css/pozos_parados.css?v=20261001-config2">
</head>
<body><div class="app"><?php include __DIR__.'/includes/sidebar.php'; ?><main class="main"><?php include __DIR__.'/includes/topbar.php'; ?>
<div class="pp">
  <header class="ppHead">
    <div><div class="ppEyebrow">Telemetría de Pozos</div><h1>Reporte de Pozos Parados</h1><p>Consolidado de paros detectados por Monitoreo, PCP, BES y TECSS con validación Zafiro y pérdida de producción.</p></div>
    <div class="ppHeadActions">
      <button class="ppBtn is-help" data-help="general">? Help Pozos monitoreo</button>
      <button class="ppBtn" data-help="monitoreo">? Help Monitoreo</button>
      <button class="ppBtn" data-help="pcp">? Help PCP</button>
      <button class="ppBtn" data-help="bes">? Help BES</button>
      <button class="ppBtn" data-help="tecss">? Help TECSS</button>
      <?php if(auth_es_admin()): ?><a class="ppBtn is-primary" href="reportes.php?pantalla=pozos_parados"><?php echo icon('file'); ?> Agregar a reportes automáticos</a><?php endif; ?>
    </div>
  </header>

  <?php if(!$ready): ?><div class="ppNotice"><b>Falta instalar el cache.</b> Ejecutá una sola vez <code>SQL/CLEAR_REPORTE_POZOS_PARADOS.sql</code> en LC_MDB. La pantalla no consulta RTQP directamente.</div>
  <?php else: ?>
  <section class="ppKpis">
    <button class="ppKpi is-red" data-system-card="MONITOREO"><span>Monitoreo Pozos</span><b id="ppCountMonitoreo"><?php echo $counts['MONITOREO']; ?></b><small>Lufkin / Pump-Off</small></button>
    <button class="ppKpi is-blue" data-system-card="PCP"><span>PCP</span><b id="ppCountPcp"><?php echo $counts['PCP']; ?></b><small>YT:POZO</small></button>
    <button class="ppKpi is-cyan" data-system-card="BES"><span>BES</span><b id="ppCountBes"><?php echo $counts['BES']; ?></b><small>ESTADO</small></button>
    <button class="ppKpi is-green" data-system-card="TECSS"><span>TECSS</span><b id="ppCountTecss"><?php echo $counts['TECSS']; ?></b><small>ESTADO</small></button>
    <button class="ppKpi is-total" data-system-card=""><span>Total parados</span><b id="ppCountTotal"><?php echo $total; ?></b><small>Todos los sistemas</small></button>
    <div class="ppKpi is-oil"><span>Producción petróleo</span><b id="ppOilTotal"><?php echo pp_n($totalOil); ?></b><small>m³/d · suma de filas visibles</small></div>
    <div class="ppKpi is-loss"><span>Pérdida instantánea</span><b id="ppLossNow"><?php echo pp_n($lossNow); ?></b><small>m³/d</small></div>
    <div class="ppKpi is-loss"><span>Pérdida últimas 24 h</span><b id="ppLoss24"><?php echo pp_n($loss24); ?></b><small>m³ estimados</small></div>
  </section>

  <div class="ppToolbar">
    <select id="ppSystem"><option value="">Todos los sistemas</option><option>MONITOREO</option><option>PCP</option><option>BES</option><option>TECSS</option></select>
    <button class="ppBtn ppConfigButton" type="button" id="ppConfigOpen" onclick="var m=document.getElementById('ppConfigModal');if(m){m.hidden=false;m.style.display='flex';}"><?php echo icon('tools'); ?> Configuración de paros</button>
    <input id="ppSearch" type="search" placeholder="Buscar pozo, batería o estado Zafiro…">
    <button class="ppBtn" id="ppClear">Limpiar filtros</button>
    <span class="ppUpdated">Actualizado: <b><?php echo h(pp_d($lastCache)); ?></b></span>
  </div>

  <section class="ppGrid"><div class="ppScroll"><table id="ppTable">
    <thead>
      <tr>
        <th>Pozo ↕</th><th>Batería ↕</th><th>Sistema ↕</th><th>ALM ↕</th><th>Comentario</th><th>Pantalla</th>
        <th>Diagnóstico ↕</th><th>Estado telemetría ↕</th><th>Estado Zafiro ↕</th><th>Método Zafiro ↕</th><th>Última alarma SCADA ↕</th>
        <th>Producción petróleo<br><small>m³/d</small></th><th>Pérdida instantánea<br><small>m³/d</small></th><th>Pérdida 24 h<br><small>m³</small></th>
        <th>RPM</th><th>Variador</th><th>Llave</th><th>Último dato</th>
      </tr>
      <tr class="ppFilterRow">
        <th><input data-col-filter="well" placeholder="Filtrar"></th>
        <th><input data-col-filter="battery" placeholder="Filtrar"></th>
        <th><select data-col-filter="system"><option value="">Todos</option><option>MONITOREO</option><option>PCP</option><option>BES</option><option>TECSS</option></select></th>
        <th><select data-col-filter="alarm"><option value="">Todas</option><option value="with">Con alarmas</option><option value="without">Sin alarmas</option></select></th>
        <th></th><th></th>
        <th><select data-col-filter="state"><option value="">Todos</option><?php foreach($diagnosticStates as $v): ?><option><?php echo h($v); ?></option><?php endforeach; ?></select></th>
        <th><select data-col-filter="telemetry"><option value="">Todos</option><?php foreach($telemetryStates as $v): ?><option><?php echo h($v); ?></option><?php endforeach; ?></select></th>
        <th><select data-col-filter="zafiro"><option value="">Todos</option><?php foreach($zafiroStates as $v): ?><option><?php echo h($v); ?></option><?php endforeach; ?></select></th>
        <th><select data-col-filter="method"><option value="">Todos</option><?php foreach($zafiroMethods as $v): ?><option><?php echo h($v); ?></option><?php endforeach; ?></select></th>
        <th><input data-col-filter="lastalarm" placeholder="Filtrar"></th>
        <th></th><th></th><th></th><th></th><th></th><th></th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php if(!$rows): ?><tr><td colspan="18" class="ppEmpty">No hay pozos parados con los criterios actuales.</td></tr>
    <?php else: foreach($rows as $r):
      $state=(string)$r['ESTADO_POZO'];
      $stateClass=$state==='PARO REAL'?'is-danger':($state==='INCONSISTENCIA'?'is-inconsistency':($state==='PROBABLE PARO'||$state==='VERIFICAR PARO'?'is-warning':($state==='PARO CONTROLADO'?'is-control':'is-muted')));
      $well=(string)$r['POZO']; $key=pp_key($well); $aux=$auxMap[$key]??[];
      $alm=(int)($aux['ALM']??0); $screen=trim((string)($aux['PANTALLA']??''));
      $lastAlarm=$lastAlarmMap[$key]??[];
      $lastAlarmDesc=trim((string)($lastAlarm['DESCRIPCION']??''));
      $lastAlarmDate=$lastAlarm['FECHA']??null;
      $isStopAlarm=preg_match('/\b(PARO|PARADA|DETENID[OA]|STOP)\b/ui',$lastAlarmDesc)===1;
      $subject=clear_alarm_comment_subject($well,'pozo');
      $comment=$subject!==''?($commentMap[strtoupper($subject)]??[]):[];
      $commentText=trim((string)($comment['COMENTARIO']??$comment['comentario']??''));
      $hasComment=$commentText!=='';
      $alarmUrl='pozos_alarmas24.php?'.http_build_query(['pozo'=>$well,'desde'=>date('Y-m-d',strtotime('-24 hours')),'hora_desde'=>date('H:i',strtotime('-24 hours')),'hasta'=>date('Y-m-d'),'hora_hasta'=>date('H:i')]);
    ?>
      <tr
        data-system="<?php echo h($r['SISTEMA']); ?>"
        data-state="<?php echo h($state); ?>"
        data-well="<?php echo h(strtolower($well)); ?>"
        data-battery="<?php echo h(strtolower((string)$r['BATERIA'])); ?>"
        data-telemetry="<?php echo h(strtolower((string)$r['ESTADO_TELEMETRIA'])); ?>"
        data-zafiro="<?php echo h(strtolower((string)$r['ESTADO_ZAFIRO'])); ?>"
        data-method="<?php echo h(strtolower((string)$r['METODO_ZAFIRO'])); ?>"
        data-lastalarm="<?php echo h(strtolower($lastAlarmDesc)); ?>"
        data-alarm="<?php echo $alm; ?>"
        data-oil="<?php echo h(number_format((float)($r['PRODUCCION_PETROLEO']??0),6,'.','')); ?>"
        data-loss-now="<?php echo h(number_format((float)($r['PERDIDA_INSTANTANEA']??0),6,'.','')); ?>"
        data-loss24="<?php echo h(number_format((float)($r['PERDIDA_24H']??0),6,'.','')); ?>"
        data-search="<?php echo h(strtolower(implode(' ',[$well,$r['BATERIA'],$r['ESTADO_ZAFIRO'],$r['ESTADO_TELEMETRIA'],$r['METODO_ZAFIRO'],$state,$lastAlarmDesc]))); ?>">
        <td><b><?php echo h($well); ?></b></td>
        <td><?php echo h($r['BATERIA']?:'—'); ?></td>
        <td><span class="ppSystem s-<?php echo h(strtolower($r['SISTEMA'])); ?>"><?php echo h($r['SISTEMA']); ?></span></td>
        <td><a class="ppAlarm <?php echo $alm>0?'has':'none'; ?>"
          href="<?php echo h($alarmUrl); ?>"
          data-telemetry-modal-url="<?php echo h($alarmUrl.'&embed=1'); ?>"
          data-telemetry-modal-open-url="<?php echo h($alarmUrl); ?>"
          data-telemetry-modal-title="<?php echo h('Alarmas 24 h · '.$well); ?>"
          data-telemetry-modal-subtitle="Consulta operativa de las alarmas del pozo"
          data-telemetry-modal-eyebrow="Alarmas de pozo"
          title="<?php echo h($alm.' alarmas en las últimas 24 horas'); ?>"><?php echo icon('bell'); ?><span><?php echo $alm; ?></span></a></td>
        <td class="ppActionCell"><?php echo clear_alarm_actions_cell(['display'=>'','subject'=>$subject,'subject_label'=>'Pozo '.$well,'context'=>'pozos_parados','show_history'=>false,'show_comment'=>true,'has_comment'=>$hasComment,'icon_only'=>true]); ?></td>
        <td class="ppActionCell"><?php if($screen!==''): ?><a class="ppScreen" href="<?php echo h($screen); ?>" target="_blank" rel="noopener" title="Abrir pantalla"><?php echo icon('monitor'); ?></a><?php else: ?>—<?php endif; ?></td>
        <td><span class="ppBadge <?php echo $stateClass; ?>"><?php echo h($state); ?></span></td>
        <td><?php echo h($r['ESTADO_TELEMETRIA']?:'—'); ?></td>
        <td><?php echo h($r['ESTADO_ZAFIRO']?:'Sin dato'); ?></td><td><?php echo h($r['METODO_ZAFIRO']?:'—'); ?></td>
        <td class="ppLastAlarm <?php echo $isStopAlarm?'is-stop':''; ?>" title="<?php echo h($lastAlarmDesc!==''?($lastAlarmDesc.' · '.pp_d($lastAlarmDate)):'Sin alarmas en las últimas 24 h'); ?>">
          <?php if($lastAlarmDesc!==''): ?><span><?php echo h($lastAlarmDesc); ?></span><small><?php echo h(pp_d($lastAlarmDate)); ?></small><?php else: ?>—<?php endif; ?>
        </td>
        <td class="num"><?php echo pp_n($r['PRODUCCION_PETROLEO']); ?></td>
        <td class="num loss"><?php echo pp_n($r['PERDIDA_INSTANTANEA']); ?></td>
        <td class="num loss"><?php echo pp_n($r['PERDIDA_24H']); ?></td>
        <td class="num"><?php echo pp_n($r['RPM'],0); ?></td><td><?php echo h($r['VARIADOR']?:'—'); ?></td><td><?php echo h($r['LLAVE']?:'—'); ?></td>
        <td><?php echo h(pp_d($r['FECHA_DATO'])); ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div><div class="ppFoot">Mostrando <b id="ppVisible"><?php echo $total; ?></b> de <b><?php echo $total; ?></b> pozos parados.</div></section>
  <?php endif; ?>
</div></main></div>
<?php clear_alarm_actions_modal(); ?>
<?php include __DIR__.'/includes/telemetry_modal.php'; ?>

<div class="ppConfigModal" id="ppConfigModal" hidden>
  <div class="ppConfigCard">
    <div class="ppConfigHead">
      <div><div class="ppEyebrow">Criterios globales</div><h2>Configuración de paros</h2><p>Define qué diagnósticos y estados de telemetría se consideran por sistema. La configuración aplica a todos los usuarios y a los reportes automáticos.</p></div>
      <button class="ppModalClose" type="button" id="ppConfigClose" onclick="var m=document.getElementById('ppConfigModal');if(m){m.hidden=true;m.style.display='none';}">×</button>
    </div>
    <div class="ppConfigBody">
      <?php foreach($paroSystems as $system): ?>
      <section class="ppConfigSystem">
        <h3><?php echo h($system); ?></h3>
        <div class="ppConfigColumns">
          <div>
            <h4>Tipos de paro / Diagnóstico</h4>
            <?php $diagCfg=$criteriaConfig[$system]['DIAGNOSTICO']??[]; ?>
            <?php if(!$diagCfg): ?><div class="ppConfigEmpty">Todavía no hay diagnósticos registrados para este sistema.</div>
            <?php else: foreach($diagCfg as $value=>$included): ?>
              <label><input class="ppConfigCriterion" type="checkbox" data-type="DIAGNOSTICO" data-system="<?php echo h($system); ?>" value="<?php echo h($value); ?>" <?php echo $included?'checked':''; ?> <?php echo auth_es_admin()?'':'disabled'; ?>><span><?php echo h($value); ?></span></label>
            <?php endforeach; endif; ?>
          </div>
          <div>
            <h4>Estados de telemetría</h4>
            <?php $telCfg=$criteriaConfig[$system]['TELEMETRIA']??[]; ?>
            <?php if(!$telCfg): ?><div class="ppConfigEmpty">Todavía no hay estados registrados para este sistema.</div>
            <?php else: foreach($telCfg as $value=>$included): ?>
              <label><input class="ppConfigCriterion" type="checkbox" data-type="TELEMETRIA" data-system="<?php echo h($system); ?>" value="<?php echo h($value); ?>" <?php echo $included?'checked':''; ?> <?php echo auth_es_admin()?'':'disabled'; ?>><span><?php echo h($value); ?></span></label>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </section>
      <?php endforeach; ?>

      <section class="ppConfigSystem ppConfigZafiro">
        <h3>Estados Zafiro considerados</h3>
        <div class="ppConfigZafiroGrid">
          <?php if(!$zafiroConfig): ?><div class="ppConfigEmpty">Ejecutá el SQL actualizado para habilitar esta configuración.</div>
          <?php else: foreach($zafiroConfig as $value=>$included): ?>
            <label><input class="ppConfigZafiroCheck" type="checkbox" value="<?php echo h($value); ?>" <?php echo $included?'checked':''; ?> <?php echo auth_es_admin()?'':'disabled'; ?>><span><?php echo h($value); ?></span></label>
          <?php endforeach; endif; ?>
        </div>
      </section>

      <section class="ppConfigLog">
        <h3>Últimos cambios</h3>
        <?php if(!$configAudit): ?><div class="ppConfigEmpty">Todavía no hay cambios registrados.</div>
        <?php else: ?><div class="ppConfigLogTable"><table><thead><tr><th>Fecha</th><th>Usuario</th><th>Detalle</th></tr></thead><tbody>
          <?php foreach($configAudit as $log): $detail=json_decode((string)($log['DETALLE']??''),true); $qty=(int)($detail['cantidad']??0); ?>
          <tr><td><?php echo h(pp_d($log['FECHA']??'')); ?></td><td><b><?php echo h($log['USUARIO']??''); ?></b></td><td><?php echo h($qty.' cambio'.($qty===1?'':'s')); ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
      </section>
    </div>
    <div class="ppConfigFooter">
      <span id="ppConfigStatus"><?php echo auth_es_admin()?'Los cambios se aplican al guardar.':'Modo lectura: solo administradores pueden guardar cambios.'; ?></span>
      <button class="ppBtn" type="button" id="ppConfigCancel">Cancelar</button>
      <?php if(auth_es_admin()): ?><button class="ppBtn is-primary" type="button" id="ppConfigSave">Guardar configuración</button><?php endif; ?>
    </div>
  </div>
</div>

<div class="ppModal" id="ppModal" hidden><div class="ppModalCard"><button class="ppModalClose" type="button">×</button><h2 id="ppModalTitle"></h2><div id="ppModalBody"></div></div></div>
<script>
(function(){
 const help={
  general:['Help · Reporte de Pozos Parados','La página lee exclusivamente una caché SQL refrescada cada 10 minutos. Consolida Monitoreo, PCP, BES y TECSS, cruza Zafiro y asocia la producción disponible en CLEAR. Los estados Zafiro incluidos/excluidos se configuran globalmente y afectan a todos los usuarios y reportes automáticos. También se muestra la última alarma SCADA de las últimas 24 h para aportar contexto operativo.'],
  monitoreo:['Help · Monitoreo Pozos (Lufkin / Pump-Off)','Se consideran únicamente registros con QT:RPM = 0 y RPM informado. HOA Off o funcionamiento defectuoso = PARO REAL. Timed o Setpoint = PARO CONTROLADO. Si el controlador indica Bombeo/Bombeando pero RPM sigue en cero = VERIFICAR PARO. RPM mayor a cero y RPM sin dato no ingresan al reporte.'],
  pcp:['Help · PCP','La señal de paro se toma de YT:POZO. Se incluye cuando YT:POZO indica Parado y Zafiro no tiene estado, o cuando Zafiro todavía indica Produciendo. Los estados en marcha no se muestran.'],
  bes:['Help · BES','BES V2: ESTADO = Parado es el disparador. Si Zafiro indica Produciendo, el caso se clasifica como INCONSISTENCIA y no como PARO REAL. Si Zafiro no tiene estado queda como PROBABLE PARO. Solo se eleva a PARO REAL cuando el estado Zafiro no contradice la detencion y esta habilitado en la configuracion global.'],
  tecss:['Help · TECSS','La señal de paro se toma de ESTADO = Parado. Se excluye cuando Estado Zafiro es Downtime de Producción (Pérdida Localizada). El resto de los paros queda disponible para el análisis de pérdida.']
 };
 const modal=document.getElementById('ppModal');
 document.querySelectorAll('[data-help]').forEach(b=>b.onclick=()=>{const h=help[b.dataset.help];document.getElementById('ppModalTitle').textContent=h[0];document.getElementById('ppModalBody').innerHTML='<p>'+h[1]+'</p>';modal.hidden=false;});
 document.querySelector('.ppModalClose')?.addEventListener('click',()=>modal.hidden=true);
 modal?.addEventListener('click',e=>{if(e.target===modal)modal.hidden=true;});
 const sys=document.getElementById('ppSystem'),search=document.getElementById('ppSearch');
 const colFilters=Array.from(document.querySelectorAll('[data-col-filter]'));
 const configModal=document.getElementById('ppConfigModal');
 const configCriteria=Array.from(document.querySelectorAll('.ppConfigCriterion'));
 const configZafiro=Array.from(document.querySelectorAll('.ppConfigZafiroCheck'));
 const ppNumber=v=>{
   if(v===null||v===undefined||v==='')return 0;
   if(typeof v==='number')return Number.isFinite(v)?v:0;
   let s=String(v).trim().replace(/\s/g,'');
   if(s.includes(',')&&s.includes('.')){
     if(s.lastIndexOf(',')>s.lastIndexOf('.'))s=s.replace(/\./g,'').replace(',','.');
     else s=s.replace(/,/g,'');
   }else if(s.includes(',')){
     s=s.replace(',','.');
   }
   const n=Number(s);
   return Number.isFinite(n)?n:0;
 };
 const fmt=v=>ppNumber(v).toLocaleString('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2});
 // Redimensionado de columnas: arrastrar el borde derecho del encabezado.
 document.querySelectorAll('#ppTable thead tr:first-child th').forEach((th,index)=>{
   th.dataset.colIndex=index;
   const handle=document.createElement('span');
   handle.className='ppResizeHandle';
   handle.title='Arrastrar para redimensionar';
   th.appendChild(handle);
   let startX=0,startWidth=0;
   handle.addEventListener('mousedown',e=>{
     e.preventDefault();e.stopPropagation();
     startX=e.clientX;startWidth=th.getBoundingClientRect().width;
     document.body.classList.add('ppResizing');
     const move=ev=>{
       const width=Math.max(58,Math.min(520,startWidth+(ev.clientX-startX)));
       document.querySelectorAll('#ppTable tr').forEach(row=>{
         const cell=row.children[index];
         if(cell){cell.style.width=width+'px';cell.style.minWidth=width+'px';cell.style.maxWidth=width+'px';}
       });
     };
     const up=()=>{document.body.classList.remove('ppResizing');document.removeEventListener('mousemove',move);document.removeEventListener('mouseup',up);};
     document.addEventListener('mousemove',move);document.addEventListener('mouseup',up);
   });
 });
 function apply(){
   let n=0,oil=0,lossNow=0,loss24=0,counts={MONITOREO:0,PCP:0,BES:0,TECSS:0};
   const q=(search?.value||'').trim().toLowerCase();
   const cf={};colFilters.forEach(x=>cf[x.dataset.colFilter]=(x.value||'').trim().toLowerCase());
   document.querySelectorAll('#ppTable tbody tr[data-system]').forEach(r=>{
     const alarm=Number(r.dataset.alarm||0);
     let ok=(!sys.value||r.dataset.system===sys.value)
       && (!q||r.dataset.search.includes(q));
     if(cf.well&&!r.dataset.well.includes(cf.well))ok=false;
     if(cf.battery&&!r.dataset.battery.includes(cf.battery))ok=false;
     if(cf.system&&r.dataset.system.toLowerCase()!==cf.system)ok=false;
     if(cf.state&&r.dataset.state.toLowerCase()!==cf.state)ok=false;
     if(cf.telemetry&&!r.dataset.telemetry.includes(cf.telemetry))ok=false;
     if(cf.zafiro&&!r.dataset.zafiro.includes(cf.zafiro))ok=false;
     if(cf.method&&!r.dataset.method.includes(cf.method))ok=false;
     if(cf.lastalarm&&!r.dataset.lastalarm.includes(cf.lastalarm))ok=false;
     if(cf.alarm==='with'&&alarm<=0)ok=false;
     if(cf.alarm==='without'&&alarm>0)ok=false;
     r.hidden=!ok;
     if(ok){n++;counts[r.dataset.system]=(counts[r.dataset.system]||0)+1;oil+=ppNumber(r.dataset.oil);lossNow+=ppNumber(r.dataset.lossNow);loss24+=ppNumber(r.dataset.loss24);}
   });
   document.getElementById('ppVisible').textContent=n;
   document.getElementById('ppCountTotal').textContent=n;
   document.getElementById('ppCountMonitoreo').textContent=counts.MONITOREO||0;
   document.getElementById('ppCountPcp').textContent=counts.PCP||0;
   document.getElementById('ppCountBes').textContent=counts.BES||0;
   document.getElementById('ppCountTecss').textContent=counts.TECSS||0;
   document.getElementById('ppOilTotal').textContent=fmt(oil);
   document.getElementById('ppLossNow').textContent=fmt(lossNow);
   document.getElementById('ppLoss24').textContent=fmt(loss24);
 }
 [sys,...colFilters].forEach(x=>x&&x.addEventListener(x.tagName==='INPUT'&&x.type!=='checkbox'?'input':'change',apply));
 search?.addEventListener('input',apply);
 document.querySelectorAll('[data-system-card]').forEach(b=>b.addEventListener('click',()=>{if(sys){sys.value=b.dataset.systemCard;apply();}}));
 const configOpen=document.getElementById('ppConfigOpen');
 const configClose=document.getElementById('ppConfigClose');
 const configCancel=document.getElementById('ppConfigCancel');
 const configSave=document.getElementById('ppConfigSave');
 const configStatus=document.getElementById('ppConfigStatus');
 const closeConfig=()=>{if(configModal){configModal.hidden=true;configModal.style.display='none';}};
 configOpen?.addEventListener('click',()=>{if(configModal){configModal.hidden=false;configModal.style.display='flex';}});
 configClose?.addEventListener('click',closeConfig);
 configCancel?.addEventListener('click',closeConfig);
 configModal?.addEventListener('click',e=>{if(e.target===configModal)closeConfig();});

 configSave?.addEventListener('click',async()=>{
   configSave.disabled=true;
   if(configStatus)configStatus.textContent='Guardando configuración y refrescando el reporte…';
   const payload={
     criterios:configCriteria.map(x=>({type:x.dataset.type,system:x.dataset.system,value:x.value,include:x.checked})),
     zafiro:configZafiro.map(x=>({value:x.value,include:x.checked}))
   };
   try{
     const body=new URLSearchParams({action:'save_paro_config',config:JSON.stringify(payload)});
     const res=await fetch('pozos_parados.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body});
     const data=await res.json();
     if(!res.ok||!data.ok)throw new Error(data.error||'No se pudo guardar la configuración.');
     if(configStatus)configStatus.textContent=data.message||'Configuración guardada.';
     window.setTimeout(()=>window.location.reload(),500);
   }catch(err){
     if(configStatus)configStatus.textContent=err.message||'No se pudo guardar la configuración.';
     configSave.disabled=false;
   }
 });
 document.getElementById('ppClear')?.addEventListener('click',()=>{sys.value='';search.value='';colFilters.forEach(x=>x.value='');apply();});
 apply();
})();
</script>
<script src="assets/js/app.js?v=20260929-pp1"></script>
<script src="assets/js/alarm_actions.js?v=20260901-report-grid-1"></script>
<script src="assets/js/telemetry_modal.js?v=3.1.9"></script>
</body></html>
