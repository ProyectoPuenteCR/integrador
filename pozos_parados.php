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

$ready=$db->ok() && (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_POZOS_PARADOS_CACHE',N'U') IS NULL THEN 0 ELSE 1 END")===1;
$rows=[];$lastCache=null;
$auxMap=[];$commentMap=[];$canViewComments=permissions_can('comments.view');
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
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reporte de Pozos Parados · CLEAR</title>
<link rel="stylesheet" href="assets/css/app.css?v=20260929-pp1">
<link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260826-central-1">
<link rel="stylesheet" href="assets/css/pozos_parados.css?v=20260929-pp3">
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
    <div class="ppKpi is-oil"><span>Producción petróleo</span><b id="ppOilTotal"><?php echo pp_n($totalOil); ?></b><small>m³/d · pozos considerados</small></div>
    <div class="ppKpi is-loss"><span>Pérdida instantánea</span><b id="ppLossNow"><?php echo pp_n($lossNow); ?></b><small>m³/d</small></div>
    <div class="ppKpi is-loss"><span>Pérdida últimas 24 h</span><b id="ppLoss24"><?php echo pp_n($loss24); ?></b><small>m³ estimados</small></div>
  </section>

  <div class="ppToolbar">
    <select id="ppSystem"><option value="">Todos los sistemas</option><option>MONITOREO</option><option>PCP</option><option>BES</option><option>TECSS</option></select>
    <div class="ppStatePicker" id="ppStatePicker">
      <button class="ppStatePicker__button" type="button" id="ppStatePickerButton">Estados considerados ▾</button>
      <div class="ppStatePicker__menu" id="ppStatePickerMenu" hidden>
        <?php foreach(['PARO REAL','VERIFICAR PARO','PARADO','PARO CONTROLADO'] as $stateOption): ?>
          <label><input type="checkbox" class="ppStateCheck" value="<?php echo h($stateOption); ?>" checked> <span><?php echo h($stateOption); ?></span></label>
        <?php endforeach; ?>
        <div class="ppStatePicker__actions"><button type="button" data-state-all="1">Todos</button><button type="button" data-state-all="0">Ninguno</button></div>
      </div>
    </div>
    <input id="ppSearch" type="search" placeholder="Buscar pozo, batería o estado Zafiro…">
    <button class="ppBtn" id="ppClear">Limpiar filtros</button>
    <span class="ppUpdated">Actualizado: <b><?php echo h(pp_d($lastCache)); ?></b></span>
  </div>

  <section class="ppGrid"><div class="ppScroll"><table id="ppTable">
    <thead>
      <tr>
        <th>Pozo ↕</th><th>Batería ↕</th><th>Sistema ↕</th><th>ALM ↕</th><th>Comentario</th><th>Pantalla</th>
        <th>Estado pozo ↕</th><th>Estado telemetría ↕</th><th>Estado Zafiro ↕</th><th>Método Zafiro ↕</th>
        <th>Producción petróleo<br><small>m³/d</small></th><th>Pérdida instantánea<br><small>m³/d</small></th><th>Pérdida 24 h<br><small>m³</small></th>
        <th>RPM</th><th>Variador</th><th>Llave</th><th>Último dato</th>
      </tr>
      <tr class="ppFilterRow">
        <th><input data-col-filter="well" placeholder="Filtrar"></th>
        <th><input data-col-filter="battery" placeholder="Filtrar"></th>
        <th><select data-col-filter="system"><option value="">Todos</option><option>MONITOREO</option><option>PCP</option><option>BES</option><option>TECSS</option></select></th>
        <th><select data-col-filter="alarm"><option value="">Todas</option><option value="with">Con alarmas</option><option value="without">Sin alarmas</option></select></th>
        <th></th><th></th>
        <th></th>
        <th><input data-col-filter="telemetry" placeholder="Filtrar"></th>
        <th><input data-col-filter="zafiro" placeholder="Filtrar"></th>
        <th><input data-col-filter="method" placeholder="Filtrar"></th>
        <th></th><th></th><th></th><th></th><th></th><th></th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php if(!$rows): ?><tr><td colspan="17" class="ppEmpty">No hay pozos parados con los criterios actuales.</td></tr>
    <?php else: foreach($rows as $r):
      $state=(string)$r['ESTADO_POZO'];
      $stateClass=$state==='PARO REAL'?'is-danger':($state==='VERIFICAR PARO'?'is-warning':($state==='PARO CONTROLADO'?'is-control':'is-muted'));
      $well=(string)$r['POZO']; $key=pp_key($well); $aux=$auxMap[$key]??[];
      $alm=(int)($aux['ALM']??0); $screen=trim((string)($aux['PANTALLA']??''));
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
        data-alarm="<?php echo $alm; ?>"
        data-oil="<?php echo h((string)($r['PRODUCCION_PETROLEO']??0)); ?>"
        data-loss-now="<?php echo h((string)($r['PERDIDA_INSTANTANEA']??0)); ?>"
        data-loss24="<?php echo h((string)($r['PERDIDA_24H']??0)); ?>"
        data-search="<?php echo h(strtolower(implode(' ',[$well,$r['BATERIA'],$r['ESTADO_ZAFIRO'],$r['ESTADO_TELEMETRIA'],$r['METODO_ZAFIRO'],$state]))); ?>">
        <td><b><?php echo h($well); ?></b></td>
        <td><?php echo h($r['BATERIA']?:'—'); ?></td>
        <td><span class="ppSystem s-<?php echo h(strtolower($r['SISTEMA'])); ?>"><?php echo h($r['SISTEMA']); ?></span></td>
        <td><a class="ppAlarm <?php echo $alm>0?'has':'none'; ?>" href="<?php echo h($alarmUrl); ?>" title="<?php echo h($alm.' alarmas en las últimas 24 horas'); ?>"><?php echo icon('bell'); ?><span><?php echo $alm; ?></span></a></td>
        <td class="ppActionCell"><?php echo clear_alarm_actions_cell(['display'=>'','subject'=>$subject,'subject_label'=>'Pozo '.$well,'context'=>'pozos_parados','show_history'=>false,'show_comment'=>true,'has_comment'=>$hasComment,'icon_only'=>true]); ?></td>
        <td class="ppActionCell"><?php if($screen!==''): ?><a class="ppScreen" href="<?php echo h($screen); ?>" target="_blank" rel="noopener" title="Abrir pantalla"><?php echo icon('monitor'); ?></a><?php else: ?>—<?php endif; ?></td>
        <td><span class="ppBadge <?php echo $stateClass; ?>"><?php echo h($state); ?></span></td>
        <td><?php echo h($r['ESTADO_TELEMETRIA']?:'—'); ?></td>
        <td><?php echo h($r['ESTADO_ZAFIRO']?:'Sin dato'); ?></td><td><?php echo h($r['METODO_ZAFIRO']?:'—'); ?></td>
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

<div class="ppModal" id="ppModal" hidden><div class="ppModalCard"><button class="ppModalClose" type="button">×</button><h2 id="ppModalTitle"></h2><div id="ppModalBody"></div></div></div>
<script>
(function(){
 const help={
  general:['Help · Reporte de Pozos Parados','La página lee exclusivamente una caché SQL refrescada cada 10 minutos. Consolida cuatro sistemas, cruza el último estado de Zafiro y asocia la producción de petróleo disponible en CLEAR. La pérdida instantánea es la producción diaria asociada a los pozos actualmente parados. La pérdida 24 h se estima con snapshots de 10 minutos.'],
  monitoreo:['Help · Monitoreo Pozos (Lufkin / Pump-Off)','Se consideran únicamente registros con QT:RPM = 0 y RPM informado. HOA Off o funcionamiento defectuoso = PARO REAL. Timed o Setpoint = PARO CONTROLADO. Si el controlador indica Bombeo/Bombeando pero RPM sigue en cero = VERIFICAR PARO. RPM mayor a cero y RPM sin dato no ingresan al reporte.'],
  pcp:['Help · PCP','La señal de paro se toma de YT:POZO. Se incluye cuando YT:POZO indica Parado y Zafiro no tiene estado, o cuando Zafiro todavía indica Produciendo. Los estados en marcha no se muestran.'],
  bes:['Help · BES','La señal de paro se toma de ESTADO. Se incluye cuando ESTADO indica Parado y Zafiro no tiene estado, o cuando Zafiro todavía indica Produciendo. Los estados en marcha no se muestran.'],
  tecss:['Help · TECSS','La señal de paro se toma de ESTADO = Parado. Se excluye cuando Estado Zafiro es Downtime de Producción (Pérdida Localizada). El resto de los paros queda disponible para el análisis de pérdida.']
 };
 const modal=document.getElementById('ppModal');
 document.querySelectorAll('[data-help]').forEach(b=>b.onclick=()=>{const h=help[b.dataset.help];document.getElementById('ppModalTitle').textContent=h[0];document.getElementById('ppModalBody').textContent=h[1];modal.hidden=false;});
 document.querySelector('.ppModalClose')?.addEventListener('click',()=>modal.hidden=true);
 modal?.addEventListener('click',e=>{if(e.target===modal)modal.hidden=true;});
 const sys=document.getElementById('ppSystem'),search=document.getElementById('ppSearch');
 const stateChecks=Array.from(document.querySelectorAll('.ppStateCheck'));
 const colFilters=Array.from(document.querySelectorAll('[data-col-filter]'));
 const fmt=v=>Number(v||0).toLocaleString('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2});
 function selectedStates(){return new Set(stateChecks.filter(x=>x.checked).map(x=>x.value));}
 function apply(){
   let n=0,oil=0,lossNow=0,loss24=0,counts={MONITOREO:0,PCP:0,BES:0,TECSS:0};
   const q=(search?.value||'').trim().toLowerCase(),states=selectedStates();
   const cf={};colFilters.forEach(x=>cf[x.dataset.colFilter]=(x.value||'').trim().toLowerCase());
   document.querySelectorAll('#ppTable tbody tr[data-system]').forEach(r=>{
     const alarm=Number(r.dataset.alarm||0);
     let ok=states.has(r.dataset.state)&&(!sys.value||r.dataset.system===sys.value)&&(!q||r.dataset.search.includes(q));
     if(cf.well&&!r.dataset.well.includes(cf.well))ok=false;
     if(cf.battery&&!r.dataset.battery.includes(cf.battery))ok=false;
     if(cf.system&&r.dataset.system.toLowerCase()!==cf.system)ok=false;
     if(cf.telemetry&&!r.dataset.telemetry.includes(cf.telemetry))ok=false;
     if(cf.zafiro&&!r.dataset.zafiro.includes(cf.zafiro))ok=false;
     if(cf.method&&!r.dataset.method.includes(cf.method))ok=false;
     if(cf.alarm==='with'&&alarm<=0)ok=false;
     if(cf.alarm==='without'&&alarm>0)ok=false;
     r.hidden=!ok;
     if(ok){n++;counts[r.dataset.system]=(counts[r.dataset.system]||0)+1;oil+=Number(r.dataset.oil||0);lossNow+=Number(r.dataset.lossNow||0);loss24+=Number(r.dataset.loss24||0);}
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
 [sys,...stateChecks,...colFilters].forEach(x=>x&&x.addEventListener(x.tagName==='INPUT'&&x.type!=='checkbox'?'input':'change',apply));
 search?.addEventListener('input',apply);
 document.querySelectorAll('[data-system-card]').forEach(b=>b.addEventListener('click',()=>{if(sys){sys.value=b.dataset.systemCard;apply();}}));
 const picker=document.getElementById('ppStatePicker'),pickerBtn=document.getElementById('ppStatePickerButton'),pickerMenu=document.getElementById('ppStatePickerMenu');
 pickerBtn?.addEventListener('click',()=>pickerMenu.hidden=!pickerMenu.hidden);
 document.addEventListener('click',e=>{if(picker&&!picker.contains(e.target))pickerMenu.hidden=true;});
 document.querySelectorAll('[data-state-all]').forEach(b=>b.addEventListener('click',()=>{stateChecks.forEach(x=>x.checked=b.dataset.stateAll==='1');apply();}));
 document.getElementById('ppClear')?.addEventListener('click',()=>{sys.value='';search.value='';stateChecks.forEach(x=>x.checked=true);colFilters.forEach(x=>x.value='');apply();});
 apply();
})();
</script>
<script src="assets/js/app.js?v=20260929-pp1"></script>
<script src="assets/js/alarm_actions.js?v=20260901-report-grid-1"></script>
</body></html>
