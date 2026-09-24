<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';
require_once __DIR__.'/includes/production_q164.php';
require_once __DIR__.'/includes/novedades_semanales_common.php';

auth_require();
permissions_require_menu('dashboard_pozos');
$cfg=require __DIR__.'/config.php';
date_default_timezone_set($cfg['app']['tz']??'America/Argentina/Buenos_Aires');
$APP_USER=auth_user()?:'CLEAR';
$APP_ROLE=auth_es_admin()?'Administrador':'Operador';
$ACTIVE='dashboard_pozos';
$db=clear_db();
$reportReady=$db->ok()&&ns_report_ready($db)&&permissions_can_menu('novedades_semanales_reporte');

$defs=[
    'SCADA'=>['url'=>'telemetria_general.php?tipo=SCADA'],
    'TECSS'=>['url'=>'telemetria_tecss.php']
];

function dp_comm($v){
    $x=strtoupper(trim((string)$v));
    if($x==='')return 'Sin dato';
    if(preg_match('/BAD|FALL|NO DATA|DESC|ERROR|SIN COM|NO COM/',$x)||$x==='0')return 'Sin comunicación';
    if(preg_match('/INTER|DEMOR/',$x))return 'Intermitente';
    return 'Comunicando';
}
function dp_state($v){
    $x=strtoupper(trim((string)$v));
    if($x==='')return 'Desconocido';
    if(preg_match('/FALL|ALAR|ERROR|EMER/',$x))return 'En falla';
    if(preg_match('/PARO|OFF|INACT|DETEN/',$x))return 'Parado';
    if(preg_match('/MARCH|NORMAL|OPER|RUN|BOMB|OK/',$x))return 'En marcha';
    return 'Desconocido';
}
function dp_prod($value){
    return $value===null||$value===''||!is_numeric($value)?'—':number_format((float)$value,2,',','.');
}
function dp_clean_error($value){
    $text=html_entity_decode((string)$value,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $text=preg_replace('~<br\\s*/?>~i',' · ',$text);
    $text=strip_tags($text);
    $text=preg_replace('/\\s+/u',' ',$text);
    return trim($text);
}

$summary=[];
$all=[];
$bats=[];
$last=null;
$errors=[];
foreach($defs as $type=>$d){
    $summary[$type]=[
        'count'=>0,
        'comm'=>['Comunicando'=>0,'Intermitente'=>0,'Sin comunicación'=>0,'Sin dato'=>0],
        'state'=>['En marcha'=>0,'Parado'=>0,'En falla'=>0,'Desconocido'=>0],
        'url'=>$d['url']
    ];
}

$cacheReady=$db->ok() && (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE',N'U') IS NULL THEN 0 ELSE 1 END")===1;
$telemetryRows=[];
if($cacheReady){
    $telemetryRows=$db->all(
        "SELECT TOP (5000) POZO,BATERIA,TIPO,COMUNICACION,ESTADO,FECHA_CACHE " .
        "FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE WHERE POZO IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255),POZO)))<>'' " .
        "ORDER BY POZO"
    );
}else{
    $errors['CACHE']='No está disponible TELEMETRIA_POZOS_GENERAL_CACHE. Se evitó consultar RTQP/OPENQUERY desde el dashboard para no bloquear la página.';
}

$seenByType=[];
$seenAll=[];
foreach($telemetryRows as $r){
    $pozo=trim((string)($r['POZO']??''));
    if($pozo==='')continue;
    $rawType=strtoupper(trim((string)($r['TIPO']??'')));
    $type=in_array($rawType,['TECSS','TECCS'],true)?'TECSS':'SCADA';
    $key=strtoupper($pozo);
    if(isset($seenByType[$type][$key]))continue;
    $seenByType[$type][$key]=1;
    $seenAll[$key]=1;

    $bat=trim((string)($r['BATERIA']??''));
    if($bat!=='')$bats[$bat]=1;
    $cv=dp_comm($r['COMUNICACION']??'');
    $sv=dp_state($r['ESTADO']??'');
    $summary[$type]['count']++;
    $summary[$type]['comm'][$cv]++;
    $summary[$type]['state'][$sv]++;
    $dt=strtotime((string)($r['FECHA_CACHE']??''))?:0;
    if($dt&&(!$last||$dt>$last))$last=$dt;
    $all[]=['pozo'=>$pozo,'tipo'=>$type,'bateria'=>$bat,'comm'=>$cv,'state'=>$sv,'date'=>$dt];
}

$total=count($seenAll);
$commTot=['Comunicando'=>0,'Intermitente'=>0,'Sin comunicación'=>0,'Sin dato'=>0];
$stateTot=['En marcha'=>0,'Parado'=>0,'En falla'=>0,'Desconocido'=>0];
foreach($summary as $s){
    foreach($commTot as $k=>$_)$commTot[$k]+=$s['comm'][$k];
    foreach($stateTot as $k=>$_)$stateTot[$k]+=$s['state'][$k];
}
$den=max(1,array_sum($commTot));
$pComm=round($commTot['Comunicando']*100/$den,1);

/* =============================================================
   INFOIL Query 164 - ultimo test aprobado por pozo
   Se cruza por nombre de POZO y se evita duplicar pozos que aparezcan en
   mas de un tipo de telemetria.
============================================================= */
$productionMap=$all ? clear_q164_latest_map($db) : [];
$telemetryWells=[];
foreach($all as $row){
    $key=clear_q164_well_key($row['pozo']);
    if($key!=='')$telemetryWells[$key]=$row['pozo'];
}

$productionTotals=['PRODUCCION_PETROLEO'=>0.0,'PRODUCCION_LIQUIDO'=>0.0,'PRODUCCION_GAS'=>0.0];
$productionWellCount=0;
$topProduction=[];
foreach($telemetryWells as $key=>$wellName){
    if(!isset($productionMap[$key]))continue;
    $p=$productionMap[$key];
    $hasProduction=false;
    foreach(array_keys($productionTotals) as $field){
        if($p[$field]!==null){
            $productionTotals[$field]+=(float)$p[$field];
            $hasProduction=true;
        }
    }
    if($hasProduction)$productionWellCount++;
    $oil=$p['PRODUCCION_PETROLEO'];
    if($oil!==null && (float)$oil>0){
        $topProduction[]=[
            'pozo'=>$wellName,
            'petroleo'=>(float)$oil,
            'liquido'=>$p['PRODUCCION_LIQUIDO'],
            'gas'=>$p['PRODUCCION_GAS']
        ];
    }
}
usort($topProduction,function($a,$b){return $b['petroleo']<=>$a['petroleo'];});
$topProduction=array_slice($topProduction,0,10);
$maxProduction=1.0;
foreach($topProduction as $p){if($p['petroleo']>$maxProduction)$maxProduction=$p['petroleo'];}

$bad=array_values(array_filter($all,function($r){return $r['comm']==='Sin comunicación';}));
usort($bad,function($a,$b){return ($a['date']?:0)<=>($b['date']?:0);});
$bad=array_slice($bad,0,10);
foreach($bad as &$badRow){
    $key=clear_q164_well_key($badRow['pozo']);
    $badRow['produccion_petroleo']=$productionMap[$key]['PRODUCCION_PETROLEO']??null;
}
unset($badRow);

$dashboardReportItems=[];
if($reportReady){
    $reportRow=function($sectionKey,$sectionTitle,$identity,array $columns)use(&$dashboardReportItems){
        $payload=ns_report_row_payload($columns);
        $payload['group']='dashboard_pozos_'.$sectionKey;
        $payload['group_title']='Dashboard Pozos · '.$sectionTitle;
        $dashboardReportItems[]=[
            'key'=>ns_report_key('dashboard-pozos',[$sectionKey,(string)$identity]),
            'type'=>'row',
            'title'=>'Dashboard Pozos · '.$sectionTitle.($identity!==''?' · '.$identity:''),
            'payload'=>$payload,
        ];
    };
    $reportChart=function($sectionKey,$title,array $labels,array $datasets,$type='bar',$unit='pozos')use(&$dashboardReportItems){
        $dashboardReportItems[]=[
            'key'=>ns_report_key('dashboard-pozos-chart',[$sectionKey]),
            'type'=>'chart',
            'title'=>'Dashboard Pozos · '.$title,
            'payload'=>['kind'=>'chart','labels'=>$labels,'datasets'=>$datasets,'chart_type'=>$type,'unit'=>$unit,'palette'=>'multicolor','context'=>'Resumen completo del Dashboard Pozos'],
        ];
    };

    $main=['Total de pozos'=>$total];
    foreach($summary as $type=>$data)$main['Pozos '.$type]=$data['count'];
    $main['Sin comunicación']=$commTot['Sin comunicación'];
    $main['Producción petróleo total']=dp_prod($productionTotals['PRODUCCION_PETROLEO']).' m³';
    $main['Producción líquido total']=dp_prod($productionTotals['PRODUCCION_LIQUIDO']).' m³';
    $main['Producción gas total']=dp_prod($productionTotals['PRODUCCION_GAS']).' m³';
    $reportRow('indicadores','Indicadores principales','',$main);

    $reportChart('comunicacion','Estado de comunicación',array_keys($commTot),[['label'=>'Pozos','values'=>array_values($commTot)]],'doughnut','pozos');
    foreach($commTot as $state=>$value)$reportRow('comunicacion_detalle','Estado de comunicación',$state,['Estado'=>$state,'Cantidad'=>$value,'Porcentaje'=>round($value*100/$den,1).'%']);

    $stateDen=max(1,array_sum($stateTot));
    $reportChart('estado_operativo','Estado operativo de pozos',array_keys($stateTot),[['label'=>'Pozos','values'=>array_values($stateTot)]],'doughnut','pozos');
    foreach($stateTot as $state=>$value)$reportRow('estado_detalle','Estado operativo de pozos',$state,['Estado'=>$state,'Cantidad'=>$value,'Porcentaje'=>round($value*100/$stateDen,1).'%']);

    $failureLabels=[];$failureValues=[];
    foreach($summary as $type=>$data){$failureLabels[]=$type;$failureValues[]=$data['comm']['Sin comunicación'];}
    $reportChart('fallas_comunicacion','Fallas de comunicación',$failureLabels,[['label'=>'Sin comunicación','values'=>$failureValues]],'bar','pozos');

    foreach($summary as $type=>$data){
        $typeDen=max(1,array_sum($data['comm']));
        $reportRow('resumen_tipo','Resumen por tipo de telemetría',$type,[
            'Tipo'=>$type,'Pozos'=>$data['count'],'Con comunicación'=>$data['comm']['Comunicando'],'Intermitente'=>$data['comm']['Intermitente'],
            'Sin comunicación'=>$data['comm']['Sin comunicación'],'% comunicación'=>round($data['comm']['Comunicando']*100/$typeDen,1).'%',
            'En marcha'=>$data['state']['En marcha'],'Parados'=>$data['state']['Parado'],'En falla'=>$data['state']['En falla'],
        ]);
    }

    if($bad){foreach($bad as $row)$reportRow('top_sin_comunicacion','Top pozos sin comunicación',$row['pozo'],[
        'Pozo'=>$row['pozo'],'Producción petróleo'=>dp_prod($row['produccion_petroleo']).($row['produccion_petroleo']!==null?' m³':''),'Tipo'=>$row['tipo'],
        'Batería'=>$row['bateria'],'Último dato'=>$row['date']?date('d/m/Y H:i',$row['date']):'Sin fecha',
    ]);}else{$reportRow('top_sin_comunicacion','Top pozos sin comunicación','sin-datos',['Estado'=>'Sin pozos detectados']);}

    if($topProduction){
        $reportChart('top_produccion','Top 10 pozos con mayor producción de petróleo',array_column($topProduction,'pozo'),[['label'=>'Petróleo','values'=>array_map(function($row){return(float)$row['petroleo'];},$topProduction)]],'bar','m³');
        foreach($topProduction as $index=>$row)$reportRow('top_produccion_detalle','Top 10 pozos con mayor producción de petróleo',$row['pozo'],[
            'Posición'=>$index+1,'Pozo'=>$row['pozo'],'Petróleo'=>dp_prod($row['petroleo']).' m³','Líquido'=>dp_prod($row['liquido']).' m³','Gas'=>dp_prod($row['gas']).' m³',
        ]);
    }else{$reportRow('top_produccion_detalle','Top 10 pozos con mayor producción de petróleo','sin-datos',['Estado'=>'Sin datos disponibles en Query 164']);}

    $reportRow('estado_fuentes','Estado y actualización','',[
        'Baterías activas'=>count($bats),'Comunicación promedio'=>$pComm.'%','Última actualización'=>$last?date('d/m/Y H:i:s',$last):'Sin fecha',
        'Registros procesados'=>count($all),'Pozos con último test aprobado'=>$productionWellCount,
    ]);
    foreach($errors as $type=>$error)$reportRow('errores','Advertencias de fuentes',$type,['Fuente'=>$type,'Detalle'=>$error]);
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
.dp-prod-card b{font-size:27px}
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
.dp-production{margin-top:14px}
.dp-prod-chart{display:flex;flex-direction:column;gap:8px}
.dp-prod-row{display:grid;grid-template-columns:28px minmax(125px,220px) minmax(180px,1fr) 92px;gap:10px;align-items:center;font-size:12px}
.dp-prod-rank{width:24px;height:24px;border-radius:8px;background:var(--surface-2);display:flex;align-items:center;justify-content:center;font-weight:800;color:var(--text-mut)}
.dp-prod-well{font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dp-prod-track{height:20px;background:var(--surface-2);border-radius:999px;overflow:hidden;border:1px solid var(--line)}
.dp-prod-fill{height:100%;min-width:3px;border-radius:999px;background:linear-gradient(90deg,#0f7a64,#32ad7c)}
.dp-prod-value{text-align:right;font-weight:800;color:#0f6f5c}
.dp-bottom{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:14px}
.dp-mini{padding:18px}.dp-mini b{font-size:28px}
.dp-error{background:#fff4d6;padding:10px;border-radius:9px;margin-bottom:10px;color:#8b5800}
.dp-empty{padding:22px;text-align:center;color:var(--text-mut);background:var(--surface-2);border-radius:10px}
@media(max-width:1300px){.dp-grid{grid-template-columns:1fr 1fr}.dp-grid .wide{grid-column:1/-1}}
@media(max-width:900px){.dp-prod-row{grid-template-columns:28px minmax(115px,170px) 1fr 82px}.dp-lower{grid-template-columns:1fr}}
@media(max-width:800px){.dp-grid,.dp-lower,.dp-bottom{grid-template-columns:1fr}.dp{padding:0 10px}.dp-prod-row{grid-template-columns:28px 1fr 80px}.dp-prod-track{grid-column:2/4}}
</style>
</head>
<body>
<div class="app">
<?php include __DIR__.'/includes/sidebar.php';?>
<main class="main">
<?php include __DIR__.'/includes/topbar.php';?>
<div class="page__head">
    <div><h1 class="page__title">Dashboard Pozos</h1><div class="page__sub">Resumen general desde caché local de telemetría · sin consultas RTQP en línea</div></div>
    <div class="dashboardReportHead"><div class="page__live"><span class="dot"></span>Actualización automática · 30 s</div><?php if($reportReady): ?><div class="dashboardReportTools"><label class="dashboardReportToggle"><input type="checkbox" data-dashboard-report-toggle><span data-dashboard-report-label>Incluir resumen completo</span></label><button type="button" class="nsButton is-secondary" data-ns-report-open>Ver reporte <span data-ns-report-count class="nsReportCount" hidden>0</span></button></div><small class="dashboardReportStatus" data-dashboard-report-status></small><?php endif; ?></div>
</div>
<section class="dp">
<?php foreach($errors as $t=>$e):?><div class="dp-error"><?php echo h($t.': '.$e);?></div><?php endforeach;?>

<div class="dp-kpis">
    <a class="dp-card" style="--accent:#1683e2" href="monitoreo_pozos.php"><span>Total de pozos</span><b><?php echo $total;?></b><small>Pozos con telemetría</small></a>
    <?php $colors=['SCADA'=>'#1683e2','TECSS'=>'#805ad5'];foreach($summary as $t=>$s):?>
    <a class="dp-card" style="--accent:<?php echo $colors[$t];?>" href="<?php echo h($s['url']);?>"><span>Pozos <?php echo h($t);?></span><b><?php echo $s['count'];?></b><small><?php echo $total?round($s['count']*100/$total,1):0;?>% del total</small></a>
    <?php endforeach;?>
    <a class="dp-card" style="--accent:#ef4444" href="monitoreo_pozos.php?comunicacion=Sin%20comunicaci%C3%B3n"><span>Sin comunicación</span><b><?php echo $commTot['Sin comunicación'];?></b><small><?php echo round($commTot['Sin comunicación']*100/$den,1);?>% de registros</small></a>
    <div class="dp-card dp-prod-card" style="--accent:#0f7a64"><span>Producción petróleo total</span><b><?php echo number_format($productionTotals['PRODUCCION_PETROLEO'],2,',','.');?></b><small>m³ · <?php echo $productionWellCount;?> pozos con último test aprobado</small></div>
    <div class="dp-card dp-prod-card" style="--accent:#1683e2"><span>Producción líquido total</span><b><?php echo number_format($productionTotals['PRODUCCION_LIQUIDO'],2,',','.');?></b><small>m³ · Query 164</small></div>
    <div class="dp-card dp-prod-card" style="--accent:#805ad5"><span>Producción gas total</span><b><?php echo number_format($productionTotals['PRODUCCION_GAS'],2,',','.');?></b><small>m³ · Query 164</small></div>
</div>

<div class="dp-grid">
    <div class="dp-panel"><h2>Estado de comunicación</h2><?php $a=round($commTot['Comunicando']*100/$den,1);$b=$a+round($commTot['Intermitente']*100/$den,1);$c=$b+round($commTot['Sin comunicación']*100/$den,1);?><div class="dp-donutrow"><div class="dp-donut" style="--a:<?php echo $a;?>%;--b:<?php echo $b;?>%;--c:<?php echo $c;?>%"></div><div class="dp-legend"><?php foreach($commTot as $k=>$v):?><div><span><?php echo h($k);?></span><b><?php echo $v;?></b></div><?php endforeach;?></div></div></div>
    <div class="dp-panel"><h2>Estado operativo de pozos</h2><?php $sd=max(1,array_sum($stateTot));$a=round($stateTot['En marcha']*100/$sd,1);$b=$a+round($stateTot['Parado']*100/$sd,1);$c=$b+round($stateTot['En falla']*100/$sd,1);?><div class="dp-donutrow"><div class="dp-donut" style="--a:<?php echo $a;?>%;--b:<?php echo $b;?>%;--c:<?php echo $c;?>%"></div><div class="dp-legend"><?php foreach($stateTot as $k=>$v):?><div><span><?php echo h($k);?></span><b><?php echo $v;?></b></div><?php endforeach;?></div></div></div>
    <div class="dp-panel wide"><h2>Fallas de comunicación</h2><div class="dp-bars"><?php $mx=max(1,max(array_map(function($s){return $s['comm']['Sin comunicación'];},$summary)));foreach($summary as $t=>$s):$n=$s['comm']['Sin comunicación'];?><div class="bar"><b><?php echo h($t);?></b><div class="dp-track"><div class="dp-fill" style="width:<?php echo round($n*100/$mx);?>%"></div></div><b><?php echo $n;?></b></div><?php endforeach;?></div></div>
</div>

<div class="dp-lower">
    <div class="dp-panel"><h2>Resumen por tipo de telemetría</h2><table class="dp-table"><thead><tr><th>Tipo</th><th>Pozos</th><th>Con com.</th><th>Intermitente</th><th>Sin com.</th><th>% com.</th><th>En marcha</th><th>Parados</th><th>En falla</th></tr></thead><tbody><?php foreach($summary as $t=>$s):$d=max(1,array_sum($s['comm']));?><tr><td><span class="dp-type"><?php echo h($t);?></span></td><td><b><?php echo $s['count'];?></b></td><td><?php echo $s['comm']['Comunicando'];?></td><td><?php echo $s['comm']['Intermitente'];?></td><td><?php echo $s['comm']['Sin comunicación'];?></td><td><?php echo round($s['comm']['Comunicando']*100/$d,1);?>%</td><td><?php echo $s['state']['En marcha'];?></td><td><?php echo $s['state']['Parado'];?></td><td><?php echo $s['state']['En falla'];?></td></tr><?php endforeach;?></tbody></table></div>
    <div class="dp-panel"><h2>Top pozos sin comunicación</h2><table class="dp-table"><thead><tr><th>Pozo</th><th>Prod. petróleo</th><th>Tipo</th><th>Batería</th><th>Último dato</th></tr></thead><tbody><?php foreach($bad as $r):?><tr><td><b><?php echo h($r['pozo']);?></b></td><td><b><?php echo dp_prod($r['produccion_petroleo']);?></b><?php echo $r['produccion_petroleo']!==null?' m³':'';?></td><td><span class="dp-type"><?php echo h($r['tipo']);?></span></td><td><?php echo h($r['bateria']);?></td><td><?php echo $r['date']?date('d/m/Y H:i',$r['date']):'Sin fecha';?></td></tr><?php endforeach;?><?php if(!$bad):?><tr><td colspan="5">Sin pozos detectados</td></tr><?php endif;?></tbody></table></div>
</div>

<div class="dp-panel dp-production">
    <h2>Top 10 pozos con mayor producción de petróleo</h2>
    <div class="dp-panel-sub">Último Test de Pozos Productores Aprobados · Query 164 · valores en m³</div>
    <?php if($topProduction): ?>
    <div class="dp-prod-chart">
        <?php foreach($topProduction as $idx=>$p): $width=max(1,round($p['petroleo']*100/$maxProduction,1)); ?>
        <div class="dp-prod-row" title="Líquido: <?php echo h(dp_prod($p['liquido'])); ?> m³ · Gas: <?php echo h(dp_prod($p['gas'])); ?> m³">
            <div class="dp-prod-rank"><?php echo $idx+1;?></div>
            <div class="dp-prod-well"><?php echo h($p['pozo']);?></div>
            <div class="dp-prod-track"><div class="dp-prod-fill" style="width:<?php echo $width;?>%"></div></div>
            <div class="dp-prod-value"><?php echo number_format($p['petroleo'],2,',','.');?> m³</div>
        </div>
        <?php endforeach;?>
    </div>
    <?php else: ?><div class="dp-empty">No hay datos de producción disponibles en Query 164 para los pozos de telemetría.</div><?php endif;?>
</div>

<div class="dp-bottom">
    <div class="dp-card dp-mini"><span>Baterías activas</span><b><?php echo count($bats);?></b><small>Con al menos un pozo</small></div>
    <div class="dp-card dp-mini" style="--accent:#35b45a"><span>Comunicación promedio</span><b><?php echo $pComm;?>%</b><small>Disponibilidad general</small></div>
    <div class="dp-card dp-mini"><span>Última actualización</span><b style="font-size:20px"><?php echo $last?date('d/m H:i:s',$last):'Sin fecha';?></b><small>Caché SQL local</small></div>
    <div class="dp-card dp-mini" style="--accent:#805ad5"><span>Registros procesados</span><b><?php echo count($all);?></b><small>Caché de telemetría</small></div>
</div>
</section>
</main>
</div>
<script src="assets/js/app.js"></script>
<?php if($reportReady): ?><script>window.CLEAR_WEEKLY_NEWS={};window.CLEAR_DASHBOARD_REPORT=<?php echo json_encode(['items'=>$dashboardReportItems,'addedMessage'=>'Dashboard Pozos completo agregado al reporte.','removedMessage'=>'Dashboard Pozos quitado del reporte.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;</script><script src="assets/js/novedades_semanales.js?v=20260904-dashboard-full-1"></script><script src="assets/js/dashboard_report.js?v=20260904-dashboard-full-1"></script><?php endif; ?>
<!-- Actualización automática desactivada: el usuario actualiza manualmente para no sobrecargar SQL. -->
</body>
</html>
