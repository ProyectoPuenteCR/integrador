<?php
/* Historial de pozos parados: misma ruta, sin RTQP desde navegador. */
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/permissions.php';
require_once __DIR__.'/icons.php';
auth_require();
permissions_require_menu('monitoreo_pozos');
$cfg=require dirname(__DIR__).'/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'America/Argentina/Buenos_Aires');
$APP_USER=auth_user();
$APP_ROLE=auth_es_admin()?'Administrador':'Operador';
$ACTIVE='monitoreo_pozos';
$db=clear_db();
$hoy=new DateTimeImmutable('today');
$ready=$db->ok() && (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_HIST_PAROS_EVENTOS',N'U') IS NOT NULL AND OBJECT_ID(N'dbo.CLEAR_HIST_PAROS_CONFIG',N'U') IS NOT NULL THEN 1 ELSE 0 END")===1;
$dias=30;
if($ready){
 $d=(int)$db->scalar('SELECT RETENCION_DIAS FROM dbo.CLEAR_HIST_PAROS_CONFIG WHERE ID=1');
 if($d>=7 && $d<=365)$dias=$d;
}
auth_start();
if(empty($_SESSION['clear_hist_paros_csrf']))$_SESSION['clear_hist_paros_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['clear_hist_paros_csrf'];
$mensaje='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 if(!auth_es_admin()){
  http_response_code(403);$mensaje='Solo un administrador puede modificar la retención.';
 }elseif(!hash_equals($csrf,(string)($_POST['csrf']??''))){
  http_response_code(400);$mensaje='La sesión venció. Recargá la página para guardar.';
 }elseif(!$ready){
  http_response_code(409);$mensaje='Falta instalar el script SQL del historial.';
 }else{
  $valor=filter_var($_POST['retencion_dias']??null,FILTER_VALIDATE_INT);
  if($valor===false || $valor<7 || $valor>365){
   http_response_code(400);$mensaje='Elegí un plazo entre 7 y 365 días.';
  }elseif(!$db->execute('EXEC dbo.SP_CLEAR_HIST_PAROS_CONFIGURAR @RetencionDias=?, @Usuario=?',[(int)$valor,(string)$APP_USER])){
   http_response_code(500);$mensaje='No se pudo guardar la configuración: '.$db->error();
  }else{
   header('Location: monitoreo_pozos.php?modo=historico&config=ok');exit;
  }
 }
}
function clear_hist_dia($value,$fallback){
 if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))return $fallback;
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
 return $d && $d->format('Y-m-d')===$value ? $d : $fallback;
}
function clear_hist_fecha($value){
 if($value===null||$value==='')return '—';
 try{$dt=$value instanceof DateTimeInterface?$value:new DateTimeImmutable((string)$value);return $dt->format('d/m/Y H:i');}
 catch(Throwable $e){return '—';}
}
function clear_hist_duracion($inicio,$fin){
 try{
  $a=$inicio instanceof DateTimeInterface?$inicio:new DateTimeImmutable((string)$inicio);
  $b=$fin instanceof DateTimeInterface?$fin:new DateTimeImmutable((string)($fin?:'now'));
  $min=max(0,(int)floor(($b->getTimestamp()-$a->getTimestamp())/60));
  $d=(int)floor($min/1440);$h=(int)floor(($min%1440)/60);$m=$min%60;
  return ($d?$d.' d ':'').($h?$h.' h ':'').$m.' min';
 }catch(Throwable $e){return '—';}
}
function clear_hist_num($value){return ($value===null||$value==='')?'Sin dato':number_format((float)$value,1,',','.');}
$fechaMin=$hoy->modify('-'.($dias-1).' days');
$fechaDesde=clear_hist_dia($_GET['desde']??null,$hoy->modify('-6 days'));
$fecha7=$hoy->modify('-6 days');if($fecha7<$fechaMin)$fecha7=$fechaMin;
$fechaHasta=clear_hist_dia($_GET['hasta']??null,$hoy);
if($fechaDesde<$fechaMin)$fechaDesde=$fechaMin;
if($fechaHasta>$hoy)$fechaHasta=$hoy;
if($fechaHasta<$fechaMin)$fechaHasta=$fechaMin;
if($fechaDesde>$fechaHasta)$fechaDesde=$fechaHasta;
$desde=$fechaDesde->format('Y-m-d');$hasta=$fechaHasta->format('Y-m-d');
$filSistema=strtoupper(trim((string)($_GET['sistema']??'')));
if(!in_array($filSistema,['MONITOREO','PCP','BES','TECSS'],true))$filSistema='';
$filBateria=trim((string)($_GET['bateria']??''));
if(strlen($filBateria)>255)$filBateria='';
$filBuscar=trim((string)($_GET['buscar']??''));
if(strlen($filBuscar)>100)$filBuscar=substr($filBuscar,0,100);
$pagina=max(1,min(100000,(int)($_GET['pagina']??1)));
$tamano=100;
$filBaterias=[];$datos=[];$totales=[];
if($ready){
 $batRows=$db->all('SELECT DISTINCT TOP(250) BATERIA FROM dbo.CLEAR_HIST_PAROS_EVENTOS WHERE BATERIA IS NOT NULL AND (FECHA_FIN IS NULL OR FECHA_FIN>=?) ORDER BY BATERIA',[$fechaMin->format('Y-m-d')]);
 foreach($batRows as $b){if(isset($b['BATERIA']))$filBaterias[]=(string)$b['BATERIA'];}
 if($filBateria!==''&&!in_array($filBateria,$filBaterias,true))$filBateria='';
 $where="E.FECHA_INICIO < DATEADD(day,1,CONVERT(date,?)) AND (E.FECHA_FIN IS NULL OR E.FECHA_FIN>=CONVERT(datetime2(0),?))";
 $params=[$hasta,$desde];
 if($filSistema!==''){$where.=' AND E.SISTEMA=?';$params[]=$filSistema;}
 if($filBateria!==''){$where.=' AND E.BATERIA=?';$params[]=$filBateria;}
 if($filBuscar!==''){
  $where.=' AND (E.POZO LIKE ? OR E.BATERIA LIKE ?)';
  $needle='%'.str_replace(['[','%','_'],['[[]','[%]','[_]'],$filBuscar).'%';
  $params[]=$needle;$params[]=$needle;
 }
 /* Agregados sobre todo el rango. Un solo test por pozo, no por evento. */
 $sqlTot="WITH E AS (
  SELECT E.POZO_CLAVE,E.FECHA_FIN,E.PRODUCCION_PETROLEO,E.PRODUCCION_AGUA,
   ROW_NUMBER() OVER(PARTITION BY E.POZO_CLAVE ORDER BY E.FECHA_INICIO DESC,E.ID DESC) RN
  FROM dbo.CLEAR_HIST_PAROS_EVENTOS E WHERE $where
 )
 SELECT COUNT_BIG(*) EVENTOS,
  SUM(CASE WHEN FECHA_FIN IS NULL THEN CONVERT(bigint,1) ELSE CONVERT(bigint,0) END) ABIERTOS,
  SUM(CASE WHEN RN=1 THEN ISNULL(PRODUCCION_PETROLEO,0) ELSE 0 END) PETROLEO,
  SUM(CASE WHEN RN=1 THEN ISNULL(PRODUCCION_AGUA,0) ELSE 0 END) AGUA,
  SUM(CASE WHEN RN=1 AND PRODUCCION_PETROLEO IS NOT NULL THEN 1 ELSE 0 END) TESTS_PETROLEO,
  SUM(CASE WHEN RN=1 AND PRODUCCION_AGUA IS NOT NULL THEN 1 ELSE 0 END) TESTS_AGUA
 FROM E";
 $r=$db->all($sqlTot,$params);
 $totales=$r[0]??[];
 $total=(int)($totales['EVENTOS']??0);
 $maxPagina=max(1,(int)ceil($total/$tamano));
 $pagina=min($pagina,$maxPagina);
 $offset=($pagina-1)*$tamano;
 $sqlDatos="SELECT E.ID,E.SISTEMA,E.POZO_CLAVE,E.POZO,E.BATERIA,E.ESTADO_POZO,E.ESTADO_TELEMETRIA,
 E.ESTADO_ZAFIRO,E.METODO_ZAFIRO,E.FECHA_INICIO,E.FECHA_FIN,E.FECHA_DATO_ORIGEN,
 E.PRODUCCION_PETROLEO,E.PRODUCCION_AGUA,E.FECHA_TEST
 FROM dbo.CLEAR_HIST_PAROS_EVENTOS E WHERE $where
 ORDER BY E.FECHA_INICIO DESC,E.ID DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
 $datos=$db->all($sqlDatos,array_merge($params,[$offset,$tamano]));
}
$enlace=function($numero)use($desde,$hasta,$filSistema,$filBateria,$filBuscar){
 return 'monitoreo_pozos.php?'.http_build_query(['modo'=>'historico','desde'=>$desde,'hasta'=>$hasta,'sistema'=>$filSistema,'bateria'=>$filBateria,'buscar'=>$filBuscar,'pagina'=>$numero]);
};
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Historial de Pozos Parados · CLEAR</title>
<link rel="stylesheet" href="assets/css/app.css?v=20261015-history-1">
<style>
.ch{padding:0 20px 28px;color:var(--text)}.ch-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:10px 0 12px}.ch-head h1{font:800 21px/1.15 var(--font-head);margin:0}.ch-head p{margin:5px 0 0;font-size:12px;color:var(--text-mut)}
.ch-tabs{display:flex;gap:7px;align-items:center;flex-wrap:wrap;margin:10px 0 14px}.ch-tab{display:inline-flex;align-items:center;gap:6px;padding:10px 13px;border:1px solid var(--line-mid);border-radius:10px;color:var(--text);text-decoration:none;font-weight:700;font-size:12px;background:var(--surface)}.ch-tab.active{background:var(--petrol);border-color:var(--petrol);color:white}.ch-tab svg{height:15px;width:15px}
.ch-bar,.ch-table-panel{background:var(--surface);border:1px solid var(--line-mid);border-radius:14px;box-shadow:0 5px 16px rgba(15,23,42,.045)}.ch-bar{padding:12px;display:flex;align-items:flex-end;gap:9px;flex-wrap:wrap}.ch-field{display:flex;flex-direction:column;gap:5px;font-size:11px;font-weight:700;color:var(--text-mut)}.ch-field input,.ch-field select{min-width:150px;max-width:100%;border:1px solid var(--line-mid);border-radius:9px;padding:9px 10px;background:var(--surface);color:var(--text);font-size:12px}.ch-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid var(--line-mid);border-radius:9px;padding:10px 14px;font-size:12px;font-weight:750;background:var(--surface);color:var(--text);cursor:pointer;text-decoration:none}.ch-btn.main{background:var(--petrol);border-color:var(--petrol);color:white}.ch-btn svg{width:16px;height:16px}.ch-retention{font-size:11px;color:var(--text-mut);margin-left:auto;align-self:center}
.ch-kpis{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:9px;margin:12px 0}.ch-kpi{background:var(--surface);border:1px solid var(--line-mid);border-radius:12px;padding:12px;min-width:0}.ch-kpi small{display:block;font-size:11px;color:var(--text-mut);margin-bottom:6px}.ch-kpi strong{font:800 22px/1.15 var(--font-head);display:block}.ch-kpi span{font-size:11px;color:var(--text-mut);display:block;margin-top:4px}
.ch-table-panel{padding:12px}.ch-filter-details{margin-bottom:10px}.ch-filter-details summary{cursor:pointer;list-style:none;display:inline-flex;gap:8px;align-items:center;border:1px solid var(--line-mid);border-radius:9px;padding:10px 14px;font-size:12px;font-weight:700}.ch-filter-details summary::-webkit-details-marker{display:none}.ch-filter-details[open] summary{color:var(--petrol);background:var(--petrol-soft)}.ch-filter-form{display:flex;align-items:flex-end;gap:9px;flex-wrap:wrap;padding:12px 2px}.ch-filter-form input{min-width:205px}
.ch-table-wrap{overflow:auto;max-height:62vh;border:1px solid var(--line-mid);border-radius:10px}.ch-table{border-spacing:0;border-collapse:separate;width:100%;min-width:1200px;font-size:11px}.ch-table th{position:sticky;top:0;background:var(--surface-2,#f6f8fa);z-index:2;text-align:left;white-space:nowrap;padding:9px 10px;border-bottom:1px solid var(--line-mid)}.ch-table td{padding:8px 10px;border-bottom:1px solid var(--line);white-space:nowrap}.ch-table tr:hover td{background:var(--petrol-soft)}.ch-table td.num{text-align:right;font-variant-numeric:tabular-nums}.ch-pill{display:inline-flex;border-radius:99px;padding:5px 8px;background:#fde8e7;color:#a92c2c;font-weight:700}.ch-pill.closed{background:#e2f3e9;color:#236b48}.ch-muted{color:var(--text-mut)}.ch-message{padding:12px;border:1px solid #f0cf91;border-radius:10px;background:rgba(236,177,65,.08);font-size:12px;margin-bottom:12px}.ch-pagination{display:flex;gap:8px;justify-content:space-between;align-items:center;padding:12px 0 0;font-size:12px;color:var(--text-mut)}.ch-pages{display:flex;gap:8px}
.ch-dialog{border:1px solid var(--line-mid);border-radius:16px;background:var(--surface);color:var(--text);width:min(520px,calc(100vw - 28px));max-height:calc(100vh - 40px);padding:0;box-shadow:0 24px 70px rgba(0,0,0,.2)}.ch-dialog::backdrop{background:rgba(5,23,38,.48)}.ch-dialog-in{padding:22px}.ch-dialog h2{margin:0 0 8px;font-size:18px}.ch-dialog p{font-size:12px;color:var(--text-mut)}.ch-options{display:flex;gap:10px;flex-wrap:wrap;margin:15px 0}.ch-options label{display:flex;gap:5px;align-items:center;font-size:12px}.ch-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px}
@media(max-width:960px){.ch-kpis{grid-template-columns:repeat(2,minmax(120px,1fr))}.ch-retention{margin-left:0}.ch{padding:0 10px 18px}}@media(max-width:500px){.ch-kpis{grid-template-columns:1fr 1fr}.ch-kpi strong{font-size:18px}.ch-field{width:100%}.ch-field input{width:100%}}
</style></head><body><div class="app"><?php include __DIR__.'/sidebar.php'; ?><main class="main"><?php include __DIR__.'/topbar.php'; ?>
<div class="ch">
<div class="ch-head"><div><h1>Monitoreo Pozos · Historial de Pozos Parados</h1><p>Solo eventos de paro detectados, con inicio, recuperación estimada y producción asociada del último test.</p></div></div>
<div class="ch-tabs"><a class="ch-tab" href="monitoreo_pozos.php"><?php echo icon('monitor'); ?> Monitoreo actual</a><a class="ch-tab active" href="monitoreo_pozos.php?modo=historico"><?php echo icon('history'); ?> Historial de paros</a></div>
<?php if(!$ready): ?><div class="ch-message">Primero ejecutá <strong>SQL/CLEAR_HISTORIAL_POZOS_PARADOS_20261015.sql</strong> en LC_MDB. La vista de monitoreo actual sigue disponible.</div><?php endif; ?>
<?php if($mensaje!==''): ?><div class="ch-message"><?php echo h($mensaje); ?></div><?php endif; ?>
<?php if(isset($_GET['config'])&&$_GET['config']==='ok'): ?><div class="ch-message">Retención guardada. La limpieza automática se aplicará en el próximo ciclo del job.</div><?php endif; ?>
<form class="ch-bar" method="get" action="monitoreo_pozos.php">
<input type="hidden" name="modo" value="historico">
<label class="ch-field">Desde <input name="desde" type="date" value="<?php echo h($desde); ?>" min="<?php echo h($fechaMin->format('Y-m-d')); ?>" max="<?php echo h($hoy->format('Y-m-d')); ?>"></label>
<label class="ch-field">Hasta <input name="hasta" type="date" value="<?php echo h($hasta); ?>" min="<?php echo h($fechaMin->format('Y-m-d')); ?>" max="<?php echo h($hoy->format('Y-m-d')); ?>"></label>
<input type="hidden" name="sistema" value="<?php echo h($filSistema); ?>"><input type="hidden" name="bateria" value="<?php echo h($filBateria); ?>"><input type="hidden" name="buscar" value="<?php echo h($filBuscar); ?>">
<button class="ch-btn main" type="submit"><?php echo icon('search'); ?> Consultar</button>
<a class="ch-btn" href="monitoreo_pozos.php?modo=historico&desde=<?php echo h($hoy->format('Y-m-d')); ?>&hasta=<?php echo h($hoy->format('Y-m-d')); ?>">Hoy</a>
<a class="ch-btn" href="monitoreo_pozos.php?modo=historico&desde=<?php echo h($fecha7->format('Y-m-d')); ?>&hasta=<?php echo h($hoy->format('Y-m-d')); ?>">Últimos 7 días</a>
<?php if(auth_es_admin()): ?><button type="button" id="chConfigOpen" class="ch-btn"><?php echo icon('tools'); ?> Configuración historial</button><?php endif; ?>
<span class="ch-retention">Retención: <?php echo (int)$dias; ?> días</span>
</form>
<div class="ch-kpis">
<div class="ch-kpi"><small>Eventos de paro</small><strong><?php echo number_format((int)($totales['EVENTOS']??0),0,',','.'); ?></strong><span>Registrados en el período</span></div>
<div class="ch-kpi"><small>Paros aún abiertos</small><strong><?php echo number_format((int)($totales['ABIERTOS']??0),0,',','.'); ?></strong><span>Pendientes de recuperación detectada</span></div>
<div class="ch-kpi"><small>Producción petróleo asociada</small><strong><?php echo ((int)($totales['TESTS_PETROLEO']??0)>0)?clear_hist_num($totales['PETROLEO']??null):'Sin dato'; ?></strong><span>m³/d · un test por pozo</span></div>
<div class="ch-kpi"><small>Producción agua asociada</small><strong><?php echo ((int)($totales['TESTS_AGUA']??0)>0)?clear_hist_num($totales['AGUA']??null):'Sin dato'; ?></strong><span>m³/d · líquido menos petróleo</span></div>
</div>
<div class="ch-table-panel">
<details class="ch-filter-details"><summary><?php echo icon('grid'); ?> Filtros <?php echo ($filSistema!==''||$filBateria!==''||$filBuscar!=='')?'●':'▾'; ?></summary>
<form class="ch-filter-form" method="get" action="monitoreo_pozos.php"><input type="hidden" name="modo" value="historico"><input type="hidden" name="desde" value="<?php echo h($desde); ?>"><input type="hidden" name="hasta" value="<?php echo h($hasta); ?>">
<label class="ch-field">Sistema<select name="sistema"><option value="">Todos</option><?php foreach(['MONITOREO','PCP','BES','TECSS'] as $s): ?><option value="<?php echo h($s); ?>"<?php echo $filSistema===$s?' selected':''; ?>><?php echo h($s); ?></option><?php endforeach; ?></select></label>
<label class="ch-field">Batería<select name="bateria"><option value="">Todas</option><?php foreach($filBaterias as $bat): ?><option value="<?php echo h($bat); ?>"<?php echo $filBateria===$bat?' selected':''; ?>><?php echo h($bat); ?></option><?php endforeach; ?></select></label>
<label class="ch-field">Buscar pozo <input type="search" name="buscar" value="<?php echo h($filBuscar); ?>" placeholder="Nombre del pozo..."></label>
<button class="ch-btn main" type="submit">Aplicar filtros</button><a class="ch-btn" href="monitoreo_pozos.php?modo=historico&desde=<?php echo h($desde); ?>&hasta=<?php echo h($hasta); ?>">Limpiar filtros</a>
</form></details>
<div class="ch-table-wrap"><table class="ch-table"><thead><tr>
<th>Pozo</th><th>Batería</th><th>Sistema</th><th>Estado de paro</th><th>Inicio detectado</th><th>Fin detectado</th><th>Duración estimada</th><th>Prod. petróleo (m³/d)</th><th>Prod. agua (m³/d)</th><th>Estado Zafiro</th><th>Test producción</th>
</tr></thead><tbody>
<?php foreach($datos as $row): ?>
<tr><td><strong><?php echo h($row['POZO']??''); ?></strong></td><td><?php echo h($row['BATERIA']??'—'); ?></td><td><?php echo h($row['SISTEMA']??''); ?></td>
<td><span class="ch-pill<?php echo !empty($row['FECHA_FIN'])?' closed':''; ?>"><?php echo h($row['ESTADO_POZO']??'PARADO'); ?></span></td>
<td><?php echo h(clear_hist_fecha($row['FECHA_INICIO']??null)); ?></td>
<td><?php echo empty($row['FECHA_FIN'])?'<span class="ch-muted">En curso</span>':h(clear_hist_fecha($row['FECHA_FIN'])); ?></td>
<td><?php echo h(clear_hist_duracion($row['FECHA_INICIO']??null,$row['FECHA_FIN']??null)); ?></td>
<td class="num"><?php echo h(clear_hist_num($row['PRODUCCION_PETROLEO']??null)); ?></td>
<td class="num"><?php echo h(clear_hist_num($row['PRODUCCION_AGUA']??null)); ?></td>
<td><?php echo h($row['ESTADO_ZAFIRO']??'Sin dato'); ?></td>
<td><?php echo h(clear_hist_fecha($row['FECHA_TEST']??null)); ?></td></tr>
<?php endforeach; ?>
<?php if(!$datos): ?><tr><td colspan="11" class="ch-muted" style="padding:24px;text-align:center">No hay eventos de paro registrados para el período y los filtros seleccionados. Los registros comienzan a generarse desde la instalación.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="ch-pagination"><span>Mostrando <?php echo count($datos); ?> de <?php echo (int)($totales['EVENTOS']??0); ?> eventos · página <?php echo $pagina; ?> de <?php echo isset($maxPagina)?$maxPagina:1; ?></span><div class="ch-pages"><?php if($pagina>1): ?><a class="ch-btn" href="<?php echo h($enlace($pagina-1)); ?>">Anterior</a><?php endif; ?><?php if(isset($maxPagina)&&$pagina<$maxPagina): ?><a class="ch-btn" href="<?php echo h($enlace($pagina+1)); ?>">Siguiente</a><?php endif; ?></div></div>
</div>
<p class="ch-muted" style="font-size:11px;margin:12px 0">Los paros se detectan desde la CACHE (job cada 10 minutos). El fin requiere dos lecturas consecutivas sin paro y es estimado. Las producciones son caudales del último test al inicio del paro, no pérdidas acumuladas ni valores históricos instantáneos.</p>
</div></main></div>
<?php if(auth_es_admin()): ?><dialog class="ch-dialog" id="chConfigDialog"><div class="ch-dialog-in"><form method="post" action="monitoreo_pozos.php?modo=historico"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><h2>Configuración de historial</h2><p>El sistema registra un evento al detectarse un paro y conserva paros finalizados durante el período elegido. Los abiertos no se eliminan.</p><label class="ch-field">Tiempo de guarda de historia <input id="chRetention" name="retencion_dias" type="number" min="7" max="365" step="1" required value="<?php echo (int)$dias; ?>"></label><div class="ch-options"><?php foreach([30,60,90] as $v): ?><label><input type="radio" name="chPreset" value="<?php echo $v; ?>" <?php echo $dias===$v?'checked':''; ?>> <?php echo $v; ?> días</label><?php endforeach; ?></div><p>Se recomienda 30 días para minimizar el crecimiento de SQL Server. La nueva retención se aplica en la siguiente purga del job.</p><div class="ch-actions"><button class="ch-btn" type="button" id="chConfigClose">Cancelar</button><button class="ch-btn main" type="submit">Guardar configuración</button></div></form></div></dialog><?php endif; ?>
<script src="assets/js/app.js?v=3.1.7"></script>
<script>
(function(){
 var dlg=document.getElementById('chConfigDialog'),open=document.getElementById('chConfigOpen'),
 close=document.getElementById('chConfigClose'),days=document.getElementById('chRetention');
 if(open&&dlg)open.addEventListener('click',function(){dlg.showModal();});
 if(close&&dlg)close.addEventListener('click',function(){dlg.close();});
 if(days)document.querySelectorAll('input[name="chPreset"]').forEach(function(r){
  r.addEventListener('change',function(){if(this.checked)days.value=this.value;});
 });
})();
</script></body></html>
