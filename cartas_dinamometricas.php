<?php
/* Diagnostico exclusivo de esta pagina; no modifica php.ini ni web.config. */
$cartasErrorLog = rtrim((string)(getenv('SystemRoot') ?: 'C:/Windows'), '/\\').'/Temp/CLEAR_cartas_reporte_php.log';
ini_set('log_errors', '1');
ini_set('error_log', $cartasErrorLog);
error_reporting(E_ALL);
$cartasStage = 'inicio';
$cartasStarted = microtime(true);
$cartasRequestId = uniqid('req_', false);
$cartasTrace = function($stage) use (&$cartasStage, $cartasStarted, $cartasRequestId) {
    $cartasStage = $stage;
    error_log('[CLEAR_CARTAS '.$cartasRequestId.'] '.round(microtime(true)-$cartasStarted, 3).'s; '.$stage.'; memoria='.round(memory_get_usage(true)/1048576, 1).' MB');
};
register_shutdown_function(function() use (&$cartasStage, $cartasStarted, $cartasRequestId) {
    $last = error_get_last();
    $fatal = $last && in_array($last['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR], true);
    error_log('[CLEAR_CARTAS '.$cartasRequestId.'] FIN; etapa='.$cartasStage.'; HTTP='.(http_response_code() ?: 200).'; segundos='.round(microtime(true)-$cartasStarted, 3).'; pico_memoria='.round(memory_get_peak_usage(true)/1048576, 1).' MB');
    if($fatal)error_log('[CLEAR_CARTAS '.$cartasRequestId.'] FATAL: '.$last['message'].'; archivo='.$last['file'].'; linea='.$last['line']);
});
$cartasTrace('Inicio; PHP '.PHP_VERSION.'; SAPI '.PHP_SAPI.'; memory_limit='.ini_get('memory_limit'));
$cartasTrace('Cargando includes/db.php');
require_once __DIR__.'/includes/db.php';
$cartasTrace('Cargado includes/db.php');
$cartasTrace('Cargando includes/auth.php');
require_once __DIR__.'/includes/auth.php';
$cartasTrace('Cargado includes/auth.php');
$cartasTrace('Cargando includes/permissions.php');
require_once __DIR__.'/includes/permissions.php';
$cartasTrace('Cargado includes/permissions.php');
$cartasTrace('Cargando includes/cartas_diarias.php');
require_once __DIR__.'/includes/cartas_diarias.php';
$cartasTrace('Cargado includes/cartas_diarias.php');
/* Una carta invalida no debe impedir mostrar las demas. */
function cd_page_cached_item($stored,$kind) {
    $raw=(string)($stored['json']??'');
    $warning=(string)($stored['warning']??'');
    if($raw==='')return ['card'=>null,'warning'=>$warning];
    try{
        if(strlen($raw)>2097152)throw new RuntimeException('La carta supera el limite de lectura.');
        $card=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($card)||!isset($card['points'])||!is_array($card['points'])||count($card['points'])>2000)
            throw new RuntimeException('Formato de carta no valido.');
        if(!cd_has_card($card))throw new RuntimeException('Sin referencia o pares validos.');
        $card['kind']=$kind;
        foreach(['xUnit','yUnit'] as $unit)if(!isset($card[$unit])||!is_string($card[$unit]))$card[$unit]='';
        return ['card'=>$card,'warning'=>$warning];
    }catch(Throwable $e){
        error_log('[CLEAR_CARTAS] Carta '.$kind.' no se pudo preparar: '.$e->getMessage());
        return ['card'=>null,'warning'=>trim($warning.' Carta guardada no valida; revisar el registro del reporte.')];
    }
}

/* Misma prioridad de estados que tg_comm_label en Monitoreo. */
function cd_page_communication($value) {
    $text=strtoupper(trim((string)$value));
    if($text==='')return 'Sin dato';
    if(preg_match('/FALL|ERROR|BAD|SIN|NO DATA|OFF|0/',$text))return 'Falla';
    if(preg_match('/OK|NORMAL|GOOD|COMUNIC|ON|1/',$text))return 'Normal';
    return 'Sin dato';
}

$cartasTrace('Verificando sesion');
auth_require();
$cartasTrace('Verificando permiso monitoreo_pozos');
permissions_require_menu('monitoreo_pozos');
$cartasTrace('Sesion y permisos OK');
$cartasTrace('Abriendo conexion SQL');
$db=clear_db();$error='';$rows=[];$load=null;
try{
    if(!$db->ok())throw new RuntimeException('Sin conexión a SQL Server.');
    $cartasTrace('SQL: buscando ultima carga completa de BM_RTQP');
    $loads=cd_query($db,"SELECT TOP (1) CONVERT(varchar(36),ID) ID,CONVERT(varchar(19),CORTE,120) CORTE,CONVERT(varchar(19),FIN,120) FIN,ADVERTENCIAS FROM dbo.CLEAR_CARTAS_CARGAS WHERE ESTADO='COMPLETA' AND DETALLE LIKE 'Fuente: BM_RTQP;%' ORDER BY CORTE DESC,FIN DESC");
    $cartasTrace('SQL: cargas encontradas='.count($loads));
    if($loads){$load=$loads[0];$cartasTrace('SQL: leyendo cartas guardadas');$rows=cd_query($db,'SELECT POZO,BATERIA,TIPO,CARTA_JSON,OBSERVACION FROM dbo.CLEAR_CARTAS_DIARIAS WHERE CARGA_ID=? ORDER BY BATERIA,POZO,TIPO',[$load['ID']]);}
}catch(Throwable $e){error_log('[CLEAR_CARTAS] '.get_class($e).': '.$e->getMessage());$error='No se pudo leer la carga de cartas. Verificar conexión y la instalación de SQL/CLEAR_CARTAS_DIARIAS.sql.';}
$cartasTrace('Preparando cartas; filas='.count($rows));
$groups=[];
// Conservar JSON compacto; decodificar solamente las dos cartas del pozo visible.
foreach($rows as $row){
    $kind=(string)($row['TIPO']??'');
    if(!in_array($kind,['superficie','fondo'],true))continue;
    $battery=trim((string)($row['BATERIA']??''));if($battery==='')$battery='SIN BATERÍA ASIGNADA';
    $well=(string)($row['POZO']??'');
    $groups[$battery][$well][$kind]=['json'=>(string)($row['CARTA_JSON']??''),'warning'=>(string)($row['OBSERVACION']??'')];
}
unset($rows,$row);

$communications=[];$communicationError=false;
if($load){
    try{
        $cartasTrace('SQL: leyendo comunicacion actual de BM_RTQP');
        $communicationRows=cd_query($db,'SELECT POZO,[YAT:COM] AS COMUNICACION FROM dbo.BM_RTQP');
        foreach($communicationRows as $communicationRow){
            $key=strtoupper(trim((string)($communicationRow['POZO']??'')));
            if($key==='')continue;
            $value=cd_page_communication($communicationRow['COMUNICACION']??null);
            // Ante duplicados, una falla prevalece; nunca ocultar una lectura desconocida.
            if(!isset($communications[$key])||$value==='Falla'||($value==='Sin dato'&&$communications[$key]!=='Falla'))$communications[$key]=$value;
        }
        unset($communicationRows,$communicationRow);
    }catch(Throwable $e){$communicationError=true;error_log('[CLEAR_CARTAS] Lectura de comunicacion: '.$e->getMessage());}
}
$cartasTrace('Cartas preparadas; baterias='.count($groups));
$cut=$load?new DateTimeImmutable($load['CORTE'],cd_tz()):null;
$expected=cd_cutoff(new DateTimeImmutable('now',cd_tz()));
$reportAllowed=permissions_can_menu('novedades_semanales_reporte')||permissions_can_menu('novedades_semanales_panel');
$cartasTrace('Generando HTML del reporte');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CLEAR · Cartas por batería</title><link rel="stylesheet" href="assets/css/cartas_diarias.css?v=20261105-resaltado-rojo"></head><body data-cd-report-enabled="<?=$reportAllowed?'1':'0'?>" data-cd-cut="<?=cd_escape($cut?$cut->format('d/m/Y H:i'):'Sin corte')?>">
<div class="cd-tools"><a href="monitoreo_pozos.php">Volver a Monitoreo de Pozos</a><div class="cd-tool-actions"><a class="cd-report-link" href="novedades_semanales_reporte.php" id="cdOpenReport">Ver reporte <span id="cdReportCount" hidden>0</span></a><button type="button" onclick="window.print()">Imprimir / Guardar PDF</button></div></div>
<header class="cd-report-head"><strong class="cd-brand">CLEAR</strong><h1>Reporte de cartas dinamométricas</h1><p>Monitoreo de Pozos · Agrupado por batería · Carga diaria a las 03:00 h (Argentina)</p>
<?php if($cut): ?><p>Corte: <?=cd_escape($cut->format('d/m/Y H:i'))?> · Carga finalizada: <?=cd_escape($load['FIN'])?></p><?php endif; ?></header>
<?php if($error): ?><p class="cd-alert" role="alert"><?=cd_escape($error)?></p><?php elseif(!$load): ?><p class="cd-alert">Todavía no hay una carga completa de cartas.</p><?php elseif($cut<$expected): ?><p class="cd-alert">Carga desactualizada: todavía no está disponible el corte de hoy. Se muestra la última carga completa.</p><?php endif; ?>

<?php if($communicationError): ?><p class="cd-alert">No se pudo consultar la comunicación actual. Las cartas guardadas siguen disponibles.</p><?php endif; ?>
<section class="cd-controls" aria-label="Filtros y estados de cartas">
  <div class="cd-summary" aria-label="Filtrar pozos por estado de sus cartas">
    <button type="button" class="cd-stat is-active" data-cd-state="all" aria-pressed="true"><span class="cd-stat-icon" aria-hidden="true">&#9638;</span><span><strong data-cd-count="all">—</strong><span>Pozos únicos</span><small>Todos los estados</small></span></button>
    <button type="button" class="cd-stat cd-stat--fresh" data-cd-state="fresh" aria-pressed="false"><span class="cd-stat-icon" aria-hidden="true">&#10003;</span><span><strong data-cd-count="fresh">—</strong><span>Actualizadas</span><small>Ambas cartas ≤24 h</small></span></button>
    <button type="button" class="cd-stat cd-stat--stale" data-cd-state="stale" aria-pressed="false"><span class="cd-stat-icon" aria-hidden="true">&#9719;</span><span><strong data-cd-count="stale">—</strong><span>Desactualizadas</span><small>Alguna carta &gt;24 h</small></span></button>
    <button type="button" class="cd-stat cd-stat--missing" data-cd-state="missing" aria-pressed="false"><span class="cd-stat-icon" aria-hidden="true">—</span><span><strong data-cd-count="missing">—</strong><span>Sin datos</span><small>Sin cartas válidas</small></span></button>
    <button type="button" class="cd-stat cd-stat--partial" data-cd-state="partial" aria-pressed="false"><span class="cd-stat-icon" aria-hidden="true">&#9680;</span><span><strong data-cd-count="partial">—</strong><span>Parciales</span><small>Falta una carta</small></span></button>
  </div>
  <form id="cdFilters" class="cd-filter-form" role="search">
    <label for="cdWellFilter">Pozo<input id="cdWellFilter" type="search" placeholder="Buscar pozo…" list="cdWellOptions" autocomplete="off"><datalist id="cdWellOptions"></datalist></label>
    <label for="cdBatteryFilter">Batería<select id="cdBatteryFilter"><option value="">Todas</option><?php foreach(array_keys($groups) as $batteryOption): ?><option value="<?=cd_escape($batteryOption)?>"><?=cd_escape($batteryOption)?></option><?php endforeach; ?></select></label>
    <button type="button" id="cdClearFilters">Limpiar filtros</button>
    <span class="cd-filter-count" id="cdVisibleCount" role="status" aria-live="polite"></span>
  </form>
  <p class="cd-filter-help">Estados de cartas evaluados al corte de la carga. Comunicación consultada al abrir la página desde Monitoreo de Pozos (BM_RTQP). Cada pozo se cuenta una sola vez; si tiene alguna carta antigua, se incluye en Desactualizadas.</p>
</section>
<p class="cd-filter-caption" id="cdFilterCaption">Todos los pozos</p>
<p class="cd-report-status" id="cdReportStatus" role="status" aria-live="polite"><?=$reportAllowed?'Consultando selección del reporte…':'Tu usuario no tiene permiso para agregar elementos al reporte.'?></p>
<p class="cd-alert" id="cdNoMatches" hidden>No hay pozos que coincidan con estos filtros.</p>

<?php foreach($groups as $battery=>$wells): ?>
<section class="cd-battery"><h2>Batería: <?=cd_escape($battery)?></h2>
<?php foreach($wells as $well=>$storedCards):
    $cards=[];
    foreach(['superficie','fondo'] as $kind)$cards[$kind]=cd_page_cached_item($storedCards[$kind]??[],$kind);
    $statuses=[];$hasWarning=false;
    foreach(['superficie','fondo'] as $kind){$item=$cards[$kind]??['card'=>null,'warning'=>''];$statuses[]=cd_status($item['card']??[],$cut);if($item['warning']!=='')$hasWarning=true;}
    $same=$statuses[0]===$statuses[1];$status=$same?$statuses[0]:($statuses[0]==='Con datos'||$statuses[1]==='Con datos'?'Datos parciales':'Sin datos en las últimas 24 h');
    $freshCount=count(array_filter($statuses,function($s){return $s==='Con datos';}));
    $filterStatus=in_array('Sin datos en las últimas 24 h',$statuses,true)?'stale':($freshCount===2?'fresh':($freshCount===1?'partial':'missing'));
    $communication=$communications[strtoupper(trim($well))]??'Sin dato';
    $needsAttention=$communication==='Falla'||$filterStatus==='stale';
    $reportKey='cartas_pozo:'.sha1(json_encode([$load['ID'],$battery,$well],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

?><article class="cd-well" data-cd-well="<?=cd_escape($well)?>" data-cd-battery="<?=cd_escape($battery)?>" data-cd-status="<?=cd_escape($filterStatus)?>" data-cd-communication="<?=cd_escape($communication)?>" data-cd-report-key="<?=cd_escape($reportKey)?>"><h3><?=cd_escape($well)?> <span class="cd-status <?=$status==='Con datos'?'cd-ok':'cd-warning'?>"><?=cd_escape($status)?></span><?php if($hasWarning): ?><span class="cd-status cd-warning">Consulta con advertencias</span><?php endif; ?></h3>
<div class="cd-well-content<?=$needsAttention?' cd-needs-attention':''?>">
<div class="cd-well-actions"><span class="cd-status cd-communication <?=$communication==='Normal'?'cd-ok':($communication==='Falla'?'cd-fail':'cd-neutral')?>">Comunicación: <?=cd_escape($communication)?></span><label class="cd-pick"><input type="checkbox" data-cd-report-pick aria-label="<?=cd_escape('Agregar cartas de '.$well.' al reporte')?>" <?=$reportAllowed?'':'disabled'?>><span>Agregar al reporte</span></label></div>
<div class="cd-plots"><?php foreach(['superficie','fondo'] as $kind): $item=$cards[$kind]??['card'=>null,'warning'=>''];$card=$item['card']; ?>
<div class="cd-plot" data-cd-kind="<?=cd_escape($kind)?>"><h4>Carta de <?=$kind==='superficie'?'superficie':'fondo'?></h4>
<?php if($card): ?><p class="cd-stamp">Última válida: <?=cd_escape((new DateTimeImmutable($card['reference']))->setTimezone(cd_tz())->format('d/m/Y H:i:s'))?><?php if(!$same): ?> · <?=cd_escape(cd_status($card,$cut))?><?php endif; ?></p><?php else: ?><p class="cd-stamp">Sin carta válida registrada<?php if(!$same): ?> · Sin datos<?php endif; ?></p><?php endif; ?>
<?php if($item['warning']!==''): ?><p class="cd-note"><?=cd_escape($item['warning'])?></p><?php endif; ?>
<?=cd_svg($card??[])?>
</div><?php endforeach; ?></div></div></article>
<?php endforeach; ?></section><?php endforeach; ?>
<footer>Las fechas corresponden a la carta, no a la consulta. Estado evaluado al corte de las 03:00 h.</footer>
<script src="assets/js/cartas_diarias.js?v=20261105-resaltado-rojo" defer></script>
</body></html>

<?php $cartasTrace('HTML completo'); ?>
