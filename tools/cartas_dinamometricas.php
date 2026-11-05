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
foreach($rows as $row){$card=json_decode((string)$row['CARTA_JSON'],true);$groups[$row['BATERIA']][$row['POZO']][$row['TIPO']]=['card'=>cd_has_card($card)?$card:null,'warning'=>$row['OBSERVACION']];}
$cartasTrace('Cartas preparadas; baterias='.count($groups));
$cut=$load?new DateTimeImmutable($load['CORTE'],cd_tz()):null;
$expected=cd_cutoff(new DateTimeImmutable('now',cd_tz()));
$cartasTrace('Generando HTML del reporte');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CLEAR · Cartas por batería</title><link rel="stylesheet" href="assets/css/cartas_diarias.css"></head><body>
<div class="cd-tools"><a href="monitoreo_pozos.php">Volver a Monitoreo de Pozos</a><button type="button" onclick="window.print()">Imprimir / Guardar PDF</button></div>
<header class="cd-report-head"><strong class="cd-brand">CLEAR</strong><h1>Reporte de cartas dinamométricas</h1><p>Monitoreo de Pozos · Agrupado por batería · Carga diaria a las 03:00 h (Argentina)</p>
<?php if($cut): ?><p>Corte: <?=cd_escape($cut->format('d/m/Y H:i'))?> · Carga finalizada: <?=cd_escape($load['FIN'])?></p><?php endif; ?></header>
<?php if($error): ?><p class="cd-alert" role="alert"><?=cd_escape($error)?></p><?php elseif(!$load): ?><p class="cd-alert">Todavía no hay una carga completa de cartas.</p><?php elseif($cut<$expected): ?><p class="cd-alert">Carga desactualizada: todavía no está disponible el corte de hoy. Se muestra la última carga completa.</p><?php endif; ?>
<?php foreach($groups as $battery=>$wells): ?>
<section class="cd-battery"><h2>Batería: <?=cd_escape($battery)?></h2>
<?php foreach($wells as $well=>$cards):
    $statuses=[];$hasWarning=false;
    foreach(['superficie','fondo'] as $kind){$item=$cards[$kind]??['card'=>null,'warning'=>''];$statuses[]=cd_status($item['card']??[],$cut);if($item['warning']!=='')$hasWarning=true;}
    $same=$statuses[0]===$statuses[1];$status=$same?$statuses[0]:($statuses[0]==='Con datos'||$statuses[1]==='Con datos'?'Datos parciales':'Sin datos en las últimas 24 h');
?><article class="cd-well"><h3><?=cd_escape($well)?> <span class="cd-status <?=$status==='Con datos'?'cd-ok':'cd-warning'?>"><?=cd_escape($status)?></span><?php if($hasWarning): ?><span class="cd-status cd-warning">Consulta con advertencias</span><?php endif; ?></h3>
<div class="cd-plots"><?php foreach(['superficie','fondo'] as $kind): $item=$cards[$kind]??['card'=>null,'warning'=>''];$card=$item['card']; ?>
<div class="cd-plot"><h4>Carta de <?=$kind==='superficie'?'superficie':'fondo'?></h4>
<?php if($card): ?><p>Última válida: <?=cd_escape((new DateTimeImmutable($card['reference']))->setTimezone(cd_tz())->format('d/m/Y H:i:s'))?><?php if(!$same): ?> · <?=cd_escape(cd_status($card,$cut))?><?php endif; ?></p><?php else: ?><p>Sin carta válida registrada<?php if(!$same): ?> · Sin datos<?php endif; ?></p><?php endif; ?>
<?php if($item['warning']!==''): ?><p class="cd-note"><?=cd_escape($item['warning'])?></p><?php endif; ?>
<?=cd_svg($card??[])?>
</div><?php endforeach; ?></div></article>
<?php endforeach; ?></section><?php endforeach; ?>
<footer>Las fechas corresponden a la carta, no a la consulta. Estado evaluado al corte de las 03:00 h.</footer>
</body></html>

<?php $cartasTrace('HTML completo'); ?>
