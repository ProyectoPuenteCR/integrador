<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';

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
if($ready){
  $rows=$db->all("SELECT SISTEMA,POZO,BATERIA,ESTADO_TELEMETRIA,ESTADO_POZO,ESTADO_ZAFIRO,METODO_ZAFIRO,RPM,VARIADOR,LLAVE,LLAVE_AUTO,PRODUCCION_PETROLEO,PERDIDA_INSTANTANEA,PERDIDA_24H,FECHA_DATO,FECHA_CACHE FROM dbo.CLEAR_POZOS_PARADOS_CACHE ORDER BY CASE SISTEMA WHEN 'MONITOREO' THEN 1 WHEN 'PCP' THEN 2 WHEN 'BES' THEN 3 WHEN 'TECSS' THEN 4 ELSE 9 END,POZO");
  $lastCache=$db->scalar("SELECT MAX(FECHA_CACHE) FROM dbo.CLEAR_POZOS_PARADOS_CACHE");
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
<link rel="stylesheet" href="assets/css/pozos_parados.css?v=20260929-pp2">
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
    <button class="ppKpi is-red" data-system="MONITOREO"><span>Monitoreo Pozos</span><b><?php echo $counts['MONITOREO']; ?></b><small>Lufkin / Pump-Off</small></button>
    <button class="ppKpi is-blue" data-system="PCP"><span>PCP</span><b><?php echo $counts['PCP']; ?></b><small>YT:POZO</small></button>
    <button class="ppKpi is-cyan" data-system="BES"><span>BES</span><b><?php echo $counts['BES']; ?></b><small>ESTADO</small></button>
    <button class="ppKpi is-green" data-system="TECSS"><span>TECSS</span><b><?php echo $counts['TECSS']; ?></b><small>ESTADO</small></button>
    <button class="ppKpi is-total" data-system=""><span>Total parados</span><b><?php echo $total; ?></b><small>Todos los sistemas</small></button>
    <div class="ppKpi is-oil"><span>Producción petróleo</span><b><?php echo pp_n($totalOil); ?></b><small>m³/d · pozos mostrados</small></div>
    <div class="ppKpi is-loss"><span>Pérdida instantánea</span><b><?php echo pp_n($lossNow); ?></b><small>m³/d</small></div>
    <div class="ppKpi is-loss"><span>Pérdida últimas 24 h</span><b><?php echo pp_n($loss24); ?></b><small>m³ estimados</small></div>
  </section>

  <div class="ppToolbar">
    <select id="ppSystem"><option value="">Todos los sistemas</option><option>MONITOREO</option><option>PCP</option><option>BES</option><option>TECSS</option></select>
    <select id="ppState"><option value="">Todos los estados</option><option>PARO REAL</option><option>PARO CONTROLADO</option><option>VERIFICAR PARO</option><option>PARADO</option></select>
    <input id="ppSearch" type="search" placeholder="Buscar pozo, batería o estado Zafiro…">
    <button class="ppBtn" id="ppClear">Limpiar filtros</button>
    <span class="ppUpdated">Actualizado: <b><?php echo h(pp_d($lastCache)); ?></b></span>
  </div>

  <section class="ppGrid"><div class="ppScroll"><table id="ppTable">
    <thead><tr>
      <th>Sistema</th><th>Pozo</th><th>Batería</th><th>Estado pozo</th><th>Estado telemetría</th><th>Estado Zafiro</th><th>Método Zafiro</th>
      <th>Producción petróleo<br><small>m³/d</small></th><th>Pérdida instantánea<br><small>m³/d</small></th><th>Pérdida 24 h<br><small>m³</small></th>
      <th>RPM</th><th>Variador</th><th>Llave</th><th>Último dato</th>
    </tr></thead>
    <tbody>
    <?php if(!$rows): ?><tr><td colspan="14" class="ppEmpty">No hay pozos parados con los criterios actuales.</td></tr>
    <?php else: foreach($rows as $r):
      $state=(string)$r['ESTADO_POZO'];
      $stateClass=$state==='PARO REAL'?'is-danger':($state==='VERIFICAR PARO'?'is-warning':($state==='PARO CONTROLADO'?'is-control':'is-muted'));
    ?>
      <tr data-system="<?php echo h($r['SISTEMA']); ?>" data-state="<?php echo h($state); ?>" data-search="<?php echo h(strtolower(implode(' ',[$r['POZO'],$r['BATERIA'],$r['ESTADO_ZAFIRO'],$r['ESTADO_TELEMETRIA']]))); ?>">
        <td><span class="ppSystem s-<?php echo h(strtolower($r['SISTEMA'])); ?>"><?php echo h($r['SISTEMA']); ?></span></td>
        <td><b><?php echo h($r['POZO']); ?></b></td><td><?php echo h($r['BATERIA']?:'—'); ?></td>
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
 const sys=document.getElementById('ppSystem'),state=document.getElementById('ppState'),search=document.getElementById('ppSearch');
 function apply(){let n=0,q=(search?.value||'').trim().toLowerCase();document.querySelectorAll('#ppTable tbody tr[data-system]').forEach(r=>{let ok=(!sys.value||r.dataset.system===sys.value)&&(!state.value||r.dataset.state===state.value)&&(!q||r.dataset.search.includes(q));r.hidden=!ok;if(ok)n++;});const v=document.getElementById('ppVisible');if(v)v.textContent=n;}
 [sys,state,search].forEach(x=>x&&x.addEventListener(x===search?'input':'change',apply));
 document.querySelectorAll('[data-system]').forEach(b=>b.addEventListener('click',()=>{if(sys){sys.value=b.dataset.system;apply();}}));
 document.getElementById('ppClear')?.addEventListener('click',()=>{sys.value='';state.value='';search.value='';apply();});
})();
</script>
<script src="assets/js/app.js?v=20260929-pp1"></script>
</body></html>
