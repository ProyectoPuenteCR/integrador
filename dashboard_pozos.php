<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';
require_once __DIR__.'/includes/novedades_semanales_common.php';
require_once __DIR__.'/includes/dashboard_pozos_data.php';
require_once __DIR__.'/includes/dashboard_pozos_kpi.php';

auth_require();
permissions_require_menu('dashboard_pozos');
$cfg=require __DIR__.'/config.php';
date_default_timezone_set($cfg['app']['tz']??'America/Argentina/Buenos_Aires');
$APP_USER=auth_user()?:'CLEAR';
$APP_ROLE=auth_es_admin()?'Administrador':'Operador';
$ACTIVE='dashboard_pozos';
$db=clear_db();
$reportReady=$db->ok()&&ns_report_ready($db)&&permissions_can_menu('novedades_semanales_reporte');
$data=dp_data_load($db);
$all=$data['rows'];
$total=count($all);
$last=null;
$errors=$data['errors'];
$commTot=['Comunicando'=>0,'Intermitente'=>0,'Sin comunicación'=>0,'Sin dato'=>0];
$stateTot=['En marcha'=>0,'Parado'=>0,'En falla'=>0,'Desconocido'=>0];
$summary=[];
foreach(['SCADA'=>'telemetria_general.php?tipo=SCADA','TECSS'=>'telemetria_tecss.php'] as $type=>$url){
    $summary[$type]=['count'=>0,'comm'=>$commTot,'state'=>$stateTot,'url'=>$url];
}
foreach($all as $row){
    $comm=isset($commTot[$row['comm']])?$row['comm']:'Sin dato';
    $state=isset($stateTot[$row['state']])?$row['state']:'Desconocido';
    $commTot[$comm]++;$stateTot[$state]++;
    if(!empty($row['date']))$last=max((int)$last,(int)$row['date']);
    foreach(['SCADA'=>'scada','TECSS'=>'tecss'] as $type=>$prefix){
        if(empty($row['en_'.$prefix]))continue;
        $summary[$type]['count']++;
        $sourceComm=$row[$prefix.'_comm']??$comm;
        $sourceState=$row[$prefix.'_state']??$state;
        $summary[$type]['comm'][isset($commTot[$sourceComm])?$sourceComm:'Sin dato']++;
        $summary[$type]['state'][isset($stateTot[$sourceState])?$sourceState:'Desconocido']++;
    }
}
$den=max(1,$total);
$bad=dp_top_without_comm($all,$data['now']);
$comparisons=dp_kpi_comparisons($data['daily'],$data['now'],$data['config']['hora_corte']);
function dp_number($value,$decimals=1){return $value===null?'—':number_format((float)$value,$decimals,',','.');}
function dp_days_label(array $row){
    if($row['dias_sin_com']===null)return 'Sin histórico';
    return (!empty($row['sin_com_estimado'])?'≥ ':'').dp_number($row['dias_sin_com'],2).' días';
}
function dp_delta_label(array $metric){
    if($metric['delta']===null)return 'Sin comparación';
    $d=$metric['delta'];
    $sign=$d>0?'+':'';
    $text=$sign.dp_number($d).' pozos';
    if($metric['percent']!==null)$text.=' ('.($metric['percent']>0?'+':'').dp_number($metric['percent']).'%)';
    elseif($metric['previous']==0)$text.=' · base anterior 0';
    return $text;
}
$dashboardReportItems=[];
if($reportReady){
    $reportRow=function($sectionKey,$sectionTitle,$identity,array $columns)use(&$dashboardReportItems){
        $payload=ns_report_row_payload($columns);
        $payload['group']='dashboard_pozos_'.$sectionKey;
        $payload['group_title']='Dashboard Pozos · '.$sectionTitle;
        $dashboardReportItems[]=['key'=>ns_report_key('dashboard-pozos',[$sectionKey,(string)$identity]),'type'=>'row',
            'title'=>'Dashboard Pozos · '.$sectionTitle.($identity!==''?' · '.$identity:''),'payload'=>$payload];
    };
    $reportChart=function($sectionKey,$title,array $labels,array $datasets,$type='bar')use(&$dashboardReportItems){
        $dashboardReportItems[]=['key'=>ns_report_key('dashboard-pozos-chart',[$sectionKey]),'type'=>'chart','title'=>'Dashboard Pozos · '.$title,
            'payload'=>['kind'=>'chart','labels'=>$labels,'datasets'=>$datasets,'chart_type'=>$type,'unit'=>'pozos','palette'=>'multicolor','context'=>'Resumen del Dashboard Pozos']];
    };
    $main=['Total de pozos'=>$total];
    foreach($summary as $type=>$entry)$main['Pozos '.$type]=$entry['count'];
    $main['Sin comunicación']=$commTot['Sin comunicación'];
    $main['Pozos en falla']=$stateTot['En falla'];
    $reportRow('indicadores','Indicadores principales','',$main);
    $reportChart('comunicacion','Estado de comunicación',array_keys($commTot),[['label'=>'Pozos','values'=>array_values($commTot)]],'doughnut');
    foreach($commTot as $state=>$value)$reportRow('comunicacion_detalle','Estado de comunicación',$state,['Estado'=>$state,'Cantidad'=>$value,'Porcentaje'=>round($value*100/$den,1).'%']);
    $reportChart('estado_operativo','Estado operativo de pozos',array_keys($stateTot),[['label'=>'Pozos','values'=>array_values($stateTot)]],'doughnut');
    foreach($stateTot as $state=>$value)$reportRow('estado_detalle','Estado operativo de pozos',$state,['Estado'=>$state,'Cantidad'=>$value,'Porcentaje'=>round($value*100/$den,1).'%']);
    foreach($summary as $type=>$entry){
        $reportRow('resumen_tipo','Resumen por tipo de telemetría',$type,['Tipo'=>$type,'Pozos'=>$entry['count'],
            'Con comunicación'=>$entry['comm']['Comunicando'],'Intermitente'=>$entry['comm']['Intermitente'],
            'Sin comunicación'=>$entry['comm']['Sin comunicación'],'En marcha'=>$entry['state']['En marcha'],
            'Parados'=>$entry['state']['Parado'],'En falla'=>$entry['state']['En falla']]);
    }
    $failureValues=array_map(static function($entry){return $entry['comm']['Sin comunicación'];},array_values($summary));
    $reportChart('fallas_comunicacion','Fallas de comunicación por fuente',array_keys($summary),[['label'=>'Sin comunicación','values'=>$failureValues]]);
    foreach($bad as $row)$reportRow('top_sin_comunicacion','Top pozos sin comunicación',$row['pozo'],[
        'Pozo'=>$row['pozo'],'Días sin comunicación'=>dp_days_label($row),'Tipo'=>$row['tipo'],'Batería'=>$row['bateria'],
        'Detectado desde'=>$row['sin_com_desde']?date('d/m/Y H:i',$row['sin_com_desde']):'Sin histórico',
        'Última observación'=>$row['date']?date('d/m/Y H:i',$row['date']):'Sin fecha']);
    if(!$bad)$reportRow('top_sin_comunicacion','Top pozos sin comunicación','sin-datos',['Estado'=>'Sin pozos detectados']);
    foreach($comparisons as $comparison){
        foreach($comparison['metrics'] as $metric)$reportRow('kpi_'.$comparison['key'],$comparison['title'],$metric['label'],[
            'Indicador'=>$metric['label'],'Período reciente'=>$comparison['current']['label'],
            'Promedio diario reciente'=>dp_number($metric['current']),'Período anterior'=>$comparison['previous']['label'],
            'Promedio diario anterior'=>dp_number($metric['previous']),'Variación'=>dp_delta_label($metric),
            'Cobertura reciente'=>$comparison['current']['available'].'/'.$comparison['current']['expected'].' días',
            'Cobertura anterior'=>$comparison['previous']['available'].'/'.$comparison['previous']['expected'].' días']);
    }
    $reportRow('estado_fuentes','Actualización y criterios','',[
        'Última actualización'=>$last?date('d/m/Y H:i:s',$last):'Sin fecha',
        'Corte del día operativo'=>substr($data['config']['hora_corte'],0,5),
        'Conteo'=>'Pozos únicos. Los resúmenes por fuente pueden compartir pozos.',
        'Duración sin comunicación'=>'Desde detección; ≥ indica inicio anterior desconocido.']);
    foreach($errors as $i=>$error)$reportRow('errores','Advertencias',(string)$i,['Detalle'=>$error]);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CLEAR · Dashboard Pozos</title>
<link rel="stylesheet" href="assets/css/app.css?v=3.0.0">
<?php if($reportReady): ?><link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260904-dashboard-full-1"><?php endif; ?>
<style>
.dp{padding:0 22px 28px}
.dp-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.dp-card,.dp-panel{background:var(--surface);border:1px solid var(--line);border-radius:14px;box-shadow:0 4px 18px rgba(15,45,58,.05)}
.dp-card{padding:17px;text-decoration:none;color:var(--text);border-top:3px solid var(--accent,var(--petrol));min-height:118px}
.dp-card span{font-size:11px;font-weight:800;color:var(--accent,var(--petrol));text-transform:uppercase;letter-spacing:.4px}
.dp-card b{display:block;font-size:33px;margin:9px 0 2px;line-height:1}
.dp-card small{color:var(--text-mut)}
.dp-grid{display:grid;grid-template-columns:1fr 1fr 1.15fr;gap:14px;margin-top:14px}
.dp-panel{padding:17px}
.dp-panel h2{font-size:14px;color:var(--petrol);margin:0 0 15px;text-transform:uppercase}
.dp-panel-sub{margin:-8px 0 16px;color:var(--text-mut);font-size:11px}
.dp-donutrow{display:flex;gap:22px;align-items:center}
.dp-donut{width:150px;height:150px;border-radius:50%;background:conic-gradient(#35b45a 0 var(--a),#f59e0b var(--a) var(--b),#ef4444 var(--b) var(--c),#aab5c0 var(--c));position:relative}
.dp-donut:after{content:'';position:absolute;inset:35px;background:var(--surface);border-radius:50%}
.dp-legend{flex:1}.dp-legend div{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line)}
.dp-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:7px}
.dp-bars .bar{display:grid;grid-template-columns:55px 1fr 30px;gap:9px;align-items:center;margin:13px 0}
.dp-track{height:14px;background:var(--surface-2);border-radius:8px;overflow:hidden}
.dp-fill{height:100%;background:#ef4444;border-radius:8px}
.dp-lower{display:grid;grid-template-columns:1.35fr 1fr;gap:14px;margin-top:14px}
.dp-table{width:100%;border-collapse:collapse;font-size:12px}
.dp-table th,.dp-table td{padding:9px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}
.dp-table th{color:var(--text-mut);font-size:10px;text-transform:uppercase;letter-spacing:.03em}
.dp-type{padding:3px 8px;border-radius:999px;background:var(--petrol-soft);color:var(--petrol);font-weight:800}
.dp-bottom{margin-top:14px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;color:var(--text-mut);font-size:12px}
.dp-table-wrap{overflow:auto;max-width:100%}.dp-panel{min-width:0}
.dp-comparisons{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}
.dp-comparison h2{margin-bottom:7px}.dp-comparison-sub{color:var(--text-mut);font-size:12px;margin:0 0 14px}
.dp-kpi-table{font-size:12px;table-layout:fixed;width:100%;border-collapse:collapse}
.dp-kpi-table th,.dp-kpi-table td{padding:11px 8px;border-bottom:1px solid var(--line);text-align:right;vertical-align:top}
.dp-kpi-table th:first-child,.dp-kpi-table td:first-child{text-align:left;width:27%}
.dp-kpi-table th{font-size:11px;color:var(--text-mut);font-weight:700}.dp-kpi-table th small{display:block;font-size:10px;font-weight:400;margin-top:4px}
.dp-kpi-table td strong{font-size:19px;display:block;font-variant-numeric:tabular-nums}
.dp-change{font-size:11px;color:var(--text-mut)}.dp-change.good{color:var(--status-green,#168349)}.dp-change.bad{color:var(--status-red,#d22f42)}
.dp-coverage{font-size:11px;margin:12px 0 0;color:var(--text-mut);line-height:1.6}.dp-pending{padding:10px 12px;background:var(--surface-2);border:1px solid var(--line);border-radius:9px;color:var(--text-mut);font-size:12px;margin:12px 0 0}
.dp-helper{font-size:11px;color:var(--text-mut);margin:10px 0 0;line-height:1.6}.dp-strong{font-variant-numeric:tabular-nums;white-space:nowrap}.dp-tools{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.dp-refresh{cursor:pointer;padding:9px 13px;border:1px solid var(--line);border-radius:9px;color:var(--petrol);background:var(--surface);font-weight:700}
.dp-legend a{color:inherit;text-decoration:none}.dp-legend a:hover{text-decoration:underline}
.dp-error{background:#fff4d6;padding:10px;border-radius:9px;margin-bottom:10px;color:#8b5800}
.dp-empty{padding:22px;text-align:center;color:var(--text-mut);background:var(--surface-2);border-radius:10px}
@media(max-width:1300px){.dp-grid{grid-template-columns:1fr 1fr}.dp-grid .wide{grid-column:1/-1}}
@media(max-width:1100px){.dp-lower,.dp-comparisons{grid-template-columns:1fr}}
@media(max-width:800px){.dp-grid,.dp-lower{grid-template-columns:1fr}.dp{padding:0 10px}.dp-donut{width:120px;height:120px;flex-shrink:0}.dp-donut:after{inset:29px}.dp-kpi-table th,.dp-kpi-table td{padding:9px 4px}.dp-kpi-table td strong{font-size:16px}}
@media print{.sidebar,.topbar,.dashboardReportHead,.dp-refresh{display:none!important}.main{margin:0!important}.dp{padding:0}.dp-panel,.dp-card{box-shadow:none;break-inside:avoid}.dp-grid,.dp-comparisons,.dp-lower{display:block}.dp-panel{margin-top:12px}.dp-table-wrap{overflow:visible}.dp-table{font-size:9px}.dp-kpis{grid-template-columns:repeat(5,1fr)}}
</style>
</head>
<body>
<div class="app">
<?php include __DIR__.'/includes/sidebar.php';?>
<main class="main">
<?php include __DIR__.'/includes/topbar.php';?>
<div class="page__head">
    <div><h1 class="page__title">Dashboard Pozos</h1><div class="page__sub">Comunicación, estado operativo y evolución de los pozos</div></div>
    <div class="dashboardReportHead"><div class="dp-tools"><span class="page__live"><?php echo !empty($data['stale'])?'Último estado disponible':'Caché de telemetría'; ?></span><button class="dp-refresh" type="button" onclick="window.location.reload()">Actualizar</button></div><?php if($reportReady): ?><div class="dashboardReportTools"><label class="dashboardReportToggle"><input type="checkbox" data-dashboard-report-toggle><span data-dashboard-report-label>Incluir resumen completo</span></label><button type="button" class="nsButton is-secondary" data-ns-report-open>Ver reporte <span data-ns-report-count class="nsReportCount" hidden>0</span></button></div><small class="dashboardReportStatus" data-dashboard-report-status></small><?php endif; ?></div>
</div>
<section class="dp">
<?php foreach($errors as $e):?><div class="dp-error" role="status"><?php echo h($e);?></div><?php endforeach;?>

<div class="dp-kpis">
    <a class="dp-card" style="--accent:#1683e2" href="telemetria_general.php"><span>Total de pozos</span><b><?php echo $total;?></b><small>Pozos con telemetría</small></a>
    <?php $colors=['SCADA'=>'#1683e2','TECSS'=>'#805ad5'];foreach($summary as $t=>$s):?>
    <a class="dp-card" style="--accent:<?php echo $colors[$t];?>" href="<?php echo h($s['url']);?>"><span>Pozos <?php echo h($t);?></span><b><?php echo $s['count'];?></b><small><?php echo $total?round($s['count']*100/$total,1):0;?>% del total</small></a>
    <?php endforeach;?>
    <a class="dp-card" style="--accent:#ef4444" href="monitoreo_pozos.php?comunicacion=Sin%20comunicaci%C3%B3n"><span>Sin comunicación</span><b><?php echo $commTot['Sin comunicación'];?></b><small><?php echo round($commTot['Sin comunicación']*100/$den,1);?>% de pozos únicos</small></a>
    <a class="dp-card" style="--accent:#d73953" href="pozos_falla.php"><span>Pozos en falla</span><b><?php echo $stateTot['En falla'];?></b><small>Ver reporte BM · BES · PCP</small></a>
</div>

<div class="dp-comparisons" aria-label="Indicadores comparativos">
<?php foreach($comparisons as $comparison): ?>
    <section class="dp-panel dp-comparison">
        <h2><?php echo h($comparison['title']); ?></h2>
        <p class="dp-comparison-sub"><?php echo h($comparison['basis']); ?> · Promedio diario de pozos al cierre</p>
        <table class="dp-kpi-table" data-grid-columns="off"><thead><tr><th>Indicador</th><th>Período reciente<small><?php echo h($comparison['current']['label']); ?></small></th><th>Período anterior<small><?php echo h($comparison['previous']['label']); ?></small></th><th>Variación</th></tr></thead><tbody>
        <?php foreach($comparison['metrics'] as $metric): ?>
        <tr><td><?php echo h($metric['label']); ?></td><td><strong><?php echo dp_number($metric['current']); ?></strong></td><td><strong><?php echo dp_number($metric['previous']); ?></strong></td><td class="dp-change<?php echo $metric['favorable']===null?'':($metric['favorable']?' good':' bad'); ?>"><?php echo h(dp_delta_label($metric)); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php if(!$comparison['ready']): ?><p class="dp-pending">Histórico insuficiente para comparar ambos períodos completos.</p><?php endif; ?>
        <p class="dp-coverage">Días con cobertura completa: reciente <?php echo $comparison['current']['available'].'/'.$comparison['current']['expected']; ?> · anterior <?php echo $comparison['previous']['available'].'/'.$comparison['previous']['expected']; ?>. Se excluye el período en curso.</p>
    </section>
<?php endforeach; ?>
</div>

<div class="dp-grid">
    <div class="dp-panel"><h2>Estado de comunicación</h2><?php $a=round($commTot['Comunicando']*100/$den,1);$b=$a+round($commTot['Intermitente']*100/$den,1);$c=$b+round($commTot['Sin comunicación']*100/$den,1);?><div class="dp-donutrow"><div class="dp-donut" style="--a:<?php echo $a;?>%;--b:<?php echo $b;?>%;--c:<?php echo $c;?>%"></div><div class="dp-legend"><?php foreach($commTot as $k=>$v):?><div><span><?php if($k==='En falla'):?><a href="pozos_falla.php"><?php echo h($k);?></a><?php else:?><?php echo h($k);?><?php endif;?></span><b><?php echo $v;?></b></div><?php endforeach;?></div></div></div>
    <div class="dp-panel"><h2>Estado operativo de pozos</h2><?php $sd=max(1,array_sum($stateTot));$a=round($stateTot['En marcha']*100/$sd,1);$b=$a+round($stateTot['Parado']*100/$sd,1);$c=$b+round($stateTot['En falla']*100/$sd,1);?><div class="dp-donutrow"><div class="dp-donut" style="--a:<?php echo $a;?>%;--b:<?php echo $b;?>%;--c:<?php echo $c;?>%"></div><div class="dp-legend"><?php foreach($stateTot as $k=>$v):?><div><span><?php if($k==='En falla'):?><a href="pozos_falla.php"><?php echo h($k);?></a><?php else:?><?php echo h($k);?><?php endif;?></span><b><?php echo $v;?></b></div><?php endforeach;?></div></div></div>
    <div class="dp-panel wide"><h2>Fallas de comunicación</h2><div class="dp-bars"><?php $mx=max(1,max(array_map(function($s){return $s['comm']['Sin comunicación'];},$summary)));foreach($summary as $t=>$s):$n=$s['comm']['Sin comunicación'];?><div class="bar"><b><?php echo h($t);?></b><div class="dp-track"><div class="dp-fill" style="width:<?php echo round($n*100/$mx);?>%"></div></div><b><?php echo $n;?></b></div><?php endforeach;?></div></div>
</div>

<div class="dp-lower">
    <div class="dp-panel"><h2>Resumen por tipo de telemetría</h2><div class="dp-table-wrap"><table class="dp-table"><thead><tr><th>Tipo</th><th>Pozos</th><th>Con com.</th><th>Intermitente</th><th>Sin com.</th><th>% com.</th><th>En marcha</th><th>Parados</th><th>En falla</th></tr></thead><tbody><?php foreach($summary as $t=>$s):$d=max(1,array_sum($s['comm']));?><tr><td><span class="dp-type"><?php echo h($t);?></span></td><td><b><?php echo $s['count'];?></b></td><td><?php echo $s['comm']['Comunicando'];?></td><td><?php echo $s['comm']['Intermitente'];?></td><td><?php echo $s['comm']['Sin comunicación'];?></td><td><?php echo round($s['comm']['Comunicando']*100/$d,1);?>%</td><td><?php echo $s['state']['En marcha'];?></td><td><?php echo $s['state']['Parado'];?></td><td><?php echo $s['state']['En falla'];?></td></tr><?php endforeach;?></tbody></table></div><p class="dp-helper">Un pozo puede figurar en ambas fuentes. Los indicadores generales lo cuentan una sola vez; comunica si al menos una fuente comunica.</p></div>
    <div class="dp-panel"><h2>Top pozos sin comunicación</h2><div class="dp-table-wrap"><table class="dp-table" id="dp-top-sin-com"><thead><tr><th>Pozo</th><th>Días sin comunicación</th><th>Tipo</th><th>Batería</th><th>Detectado desde</th></tr></thead><tbody>
    <?php foreach($bad as $r):?><tr><td><b><?php echo h($r['pozo']);?></b></td><td class="dp-strong"><?php echo h(dp_days_label($r));?></td><td><span class="dp-type"><?php echo h($r['tipo']);?></span></td><td><?php echo h($r['bateria']?:'Sin dato');?></td><td><?php echo $r['sin_com_desde']?date('d/m/Y H:i',$r['sin_com_desde']):'Sin histórico';?></td></tr><?php endforeach;?>
    <?php if(!$bad):?><tr><td colspan="5">Sin pozos detectados</td></tr><?php endif;?></tbody></table></div>
    <p class="dp-helper">Ordenado de mayor a menor duración observada, hasta la última lectura. <strong>≥</strong> indica que el pozo ya estaba sin comunicación al comenzar el seguimiento; su antigüedad previa no está disponible.</p></div>
</div>

<div class="dp-bottom">
    <span>Última actualización: <strong><?php echo $last?date('d/m/Y H:i:s',$last):'Sin fecha';?></strong><?php echo !empty($data['stale'])?' · Datos pendientes de actualización':''; ?></span>
    <span>Corte del día operativo: <strong><?php echo h(substr($data['config']['hora_corte'],0,5)); ?></strong> · Hora de Argentina</span>
</div>
</section>
</main>
</div>
<script src="assets/js/app.js"></script>
<?php if($reportReady): ?><script>window.CLEAR_WEEKLY_NEWS={};window.CLEAR_DASHBOARD_REPORT=<?php echo json_encode(['items'=>$dashboardReportItems,'addedMessage'=>'Dashboard Pozos completo agregado al reporte.','removedMessage'=>'Dashboard Pozos quitado del reporte.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script><script src="assets/js/novedades_semanales.js?v=20260904-dashboard-full-1"></script><script src="assets/js/dashboard_report.js?v=20260904-dashboard-full-1"></script><?php endif; ?>
<!-- Actualización automática desactivada: el usuario actualiza manualmente para no sobrecargar SQL. -->
</body>
</html>
