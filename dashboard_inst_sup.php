<?php
/* =============================================================
   CLEAR PLATAFORMA — dashboard_inst_sup.php
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/dashboard_data.php';
require_once __DIR__ . '/includes/recon_comments.php';
require_once __DIR__ . '/includes/novedades_semanales_common.php';

auth_require(); permissions_require_menu('dashboard_inst_sup');

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = $cfg['app']['role'] ?? 'Administrador';

$stats = dashboard_stats();
$moduleMetrics = dashboard_module_metrics();
$visualMetrics = dashboard_visual_metrics();
$ACTIVE = 'dashboard_inst_sup';

$dashboardComments = array_values(array_filter(clear_recon_comments_load_all(), function ($item) {
    return is_array($item) && trim((string)($item['comment'] ?? '')) !== '';
}));
usort($dashboardComments, function ($a, $b) {
    $aDate = strtotime((string)($a['updated_at'] ?? $a['created_at'] ?? '')) ?: 0;
    $bDate = strtotime((string)($b['updated_at'] ?? $b['created_at'] ?? '')) ?: 0;
    return $bDate <=> $aDate;
});
$dashboardCommentCount = count($dashboardComments);
$dashboardCanViewComments = permissions_can('comments.view');
$dashboardCanDeleteComments = auth_es_admin() || permissions_can('comments.disable');
$db = clear_db();
$dbStatus = $db->ok();
$reportReady = $dbStatus && ns_report_ready($db) && permissions_can_menu('novedades_semanales_reporte');
$latestAlarm = $dbStatus ? ($stats['latest_alarm'] ?? null) : null;
$latestDelayMin = $latestAlarm ? max(0, round((time() - strtotime((string)$latestAlarm))/60)) : null;
$piCfg = function_exists('pi_config') ? pi_config() : [];
$piConfigured = !empty($piCfg['base']);

function pct($value, $total)
{
    $total = (float)$total;
    if ($total <= 0) return 0;
    return max(0, min(100, round(((float)$value / $total) * 100, 1)));
}

function spark_points($values, $width = 320, $height = 64, $pad = 7)
{
    $values = array_values(array_map('floatval', $values));
    if (count($values) < 2) $values = [0, 0];

    $min = min($values);
    $max = max($values);
    $range = ($max - $min) == 0 ? 1 : ($max - $min);
    $step = ($width - ($pad * 2)) / max(1, count($values) - 1);
    $points = [];

    foreach ($values as $i => $v) {
        $x = $pad + ($i * $step);
        $y = $height - $pad - ((($v - $min) / $range) * ($height - ($pad * 2)));
        $points[] = round($x, 1) . ',' . round($y, 1);
    }
    return implode(' ', $points);
}

function values_from_rows($rows, $fallback)
{
    $values = [];
    foreach ($rows as $r) $values[] = (int)($r['value'] ?? 0);
    return count($values) >= 2 ? $values : $fallback;
}

function max_bar_value($rows)
{
    $max = 0;
    foreach ($rows as $r) $max = max($max, (int)($r['value'] ?? 0));
    return max(1, $max);
}

function compact_label($label, $max = 18)
{
    $label = trim((string)$label);
    if ($label === '') return '—';
    return strlen($label) > $max ? substr($label, 0, $max - 1) . '…' : $label;
}

function render_module_visual($m)
{
    $type = $m['visual']['type'] ?? 'kpis';
    $html = '';

    if ($type === 'spark-kpis') {
        $values = $m['visual']['values'] ?? [0, 0];
        $points = spark_points($values);
        $html .= '<div class="mod__spark mod__spark--' . h($m['accent'] ?? 'petrol') . '">';
        $html .= '<svg viewBox="0 0 320 64" preserveAspectRatio="none" aria-hidden="true">';
        $html .= '<polyline points="' . h($points) . '" />';
        foreach ($values as $i => $v) {
            $count = max(1, count($values) - 1);
            $x = 7 + ($i * ((320 - 14) / $count));
            $html .= '<circle cx="' . round($x, 1) . '" cy="32" r="1.7" />';
        }
        $html .= '</svg></div>';
    }

    if ($type === 'bars') {
        $bars = $m['visual']['bars'] ?? [];
        $max = max_bar_value($bars);
        $html .= '<div class="mod__bars">';
        if (empty($bars)) {
            $html .= '<div class="mod__empty">Sin ranking disponible</div>';
        } else {
            foreach ($bars as $b) {
                $value = (int)($b['value'] ?? 0);
                $w = pct($value, $max);
                $html .= '<div class="mod__bar">';
                $html .= '<span class="mod__barLabel" title="' . h($b['label'] ?? '') . '">' . h(compact_label($b['label'] ?? '—', 15)) . '</span>';
                $html .= '<span class="mod__barTrack"><span class="mod__barFill" style="width:' . $w . '%"></span></span>';
                $html .= '<b>' . fmt_num($value) . '</b>';
                $html .= '</div>';
            }
        }
        $html .= '</div>';
    }

    if ($type === 'trend') {
        $rows = $m['visual']['rows'] ?? [];
        $values = values_from_rows($rows, [12, 20, 16, 22, 25, 18, 21]);
        $points = spark_points($values, 320, 72, 8);
        $html .= '<div class="mod__trend">';
        $html .= '<svg viewBox="0 0 320 72" preserveAspectRatio="none" aria-hidden="true"><polyline points="' . h($points) . '" /></svg>';
        if (!empty($rows)) {
            $html .= '<div class="mod__trendLabels">';
            foreach ($rows as $r) $html .= '<span>' . h($r['label'] ?? '—') . '</span>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }

    if ($type === 'donut') {
        $parts = $m['visual']['parts'] ?? ['alta' => 0, 'media' => 0, 'baja' => 0];
        $total = max(1, array_sum($parts));
        $alta = pct($parts['alta'] ?? 0, $total);
        $media = pct($parts['media'] ?? 0, $total);
        $baja = pct($parts['baja'] ?? 0, $total);
        $html .= '<div class="mod__donutWrap">';
        $html .= '<div class="mod__donut" style="--p1:' . $alta . '%;--p2:' . ($alta + $media) . '%;--p3:' . ($alta + $media + $baja) . '%"></div>';
        $html .= '<div class="mod__legend">';
        $html .= '<span><i class="is-red"></i>Alta <b>' . $alta . '%</b></span>';
        $html .= '<span><i class="is-amber"></i>Media <b>' . $media . '%</b></span>';
        $html .= '<span><i class="is-green"></i>Baja <b>' . $baja . '%</b></span>';
        $html .= '</div></div>';
    }

    if ($type === 'mini-bars') {
        $values = $m['visual']['values'] ?? [1, 3, 2, 5, 4, 6, 3, 2];
        $max = max(1, max($values));
        $html .= '<div class="mod__miniBars">';
        foreach ($values as $v) {
            $h = max(8, pct((int)$v, $max));
            $html .= '<span style="height:' . $h . '%"></span>';
        }
        $html .= '</div>';
    }

    if (!empty($m['kpis'])) {
        $html .= '<div class="mod__kpis">';
        foreach ($m['kpis'] as $k) {
            $html .= '<div class="mod__kpi"><span>' . h($k[0]) . '</span><b>' . h($k[1]) . '</b></div>';
        }
        $html .= '</div>';
    }

    return $html;
}

$ok = !empty($stats['ok']);
$total24h    = $ok ? (int)$stats['total24h'] : 0;
$criticas    = $ok ? (int)$stats['criticas'] : 0;
$activas     = $ok ? (int)$stats['activas'] : 0;
$reconocidas = $ok ? (int)$stats['reconocidas'] : 0;
$suprimidas  = $ok ? (int)$stats['suprimidas'] : 0;

$trendValues = values_from_rows($moduleMetrics['tendencia'] ?? [], [18, 24, 16, 29, 22, 27, 21]);
$priority = $moduleMetrics['prioridad'] ?? ['HIGH'=>0,'MEDIUM'=>0,'LOW'=>0,'INFO'=>0];
$pozosTotal = (int)($moduleMetrics['pozos']['total'] ?? 0);
$importadasTotal = (int)($moduleMetrics['importadas']['total'] ?? 0);
$operators = $moduleMetrics['operadores'] ?? [];
if (empty($operators)) $operators = [['label' => 'Operadores', 'value' => $reconocidas]];

/* Tarjetas de módulos: etiqueta, descripción, icono, imagen, badge, link, mini-resumen */
$modules = [
  [
    'title' => 'Alarmas 24h',
    'desc'  => 'Eventos FIX de las últimas 24 horas en vivo.',
    'icon'  => 'bell',
    'img'   => 'mod_produccion.svg',
    'badge' => 'En vivo',
    'href'  => 'list.php?s=alarmas24h',
    'accent'=> 'petrol',
    'visual'=> ['type' => 'spark-kpis', 'values' => $trendValues],
    'kpis'  => [['24h', fmt_num($total24h)], ['Activas', fmt_num($activas)], ['Críticas', fmt_num($criticas)]],
  ],
  [
    'title' => 'Top de alarmas',
    'desc'  => 'Ranking de tags por frecuencia: top 20 general y 24 horas.',
    'icon'  => 'chart',
    'img'   => 'mod_sala.svg',
    'badge' => 'Ranking',
    'href'  => 'instalaciones_top20.php',
    'accent'=> 'petrol',
    'visual'=> ['type' => 'bars', 'bars' => $moduleMetrics['top_alarmas'] ?? []],
  ],
  [
    'title' => 'Tendencia semanal',
    'desc'  => 'Evolución de alarmas por día y por tag a lo largo de la semana.',
    'icon'  => 'trend',
    'img'   => 'mod_pulling.svg',
    'badge' => 'Semanal',
    'href'  => 'list.php?s=tendencia_sem',
    'accent'=> 'petrol',
    'visual'=> ['type' => 'trend', 'rows' => $moduleMetrics['tendencia'] ?? []],
    'kpis'  => [['Último', fmt_num(end($trendValues))], ['Prom.', fmt_num(array_sum($trendValues) / max(1, count($trendValues)))], ['Pico', fmt_num(max($trendValues))]],
  ],
  [
    'title' => 'Prioridad',
    'desc'  => 'Clasificación de alarmas por criticidad alta, media y baja.',
    'icon'  => 'gauge',
    'img'   => 'mod_intervia.svg',
    'badge' => 'Criticidad',
    'href'  => 'list.php?s=prioridad',
    'accent'=> 'amber',
    'visual'=> ['type' => 'donut', 'parts' => ['alta' => $priority['HIGH'] ?? $criticas, 'media' => $priority['MEDIUM'] ?? 0, 'baja' => $priority['LOW'] ?? 0]],
  ],
  [
    'title' => 'Pozos',
    'desc'  => 'Ficha de pozos: zona, batería, método y producción.',
    'icon'  => 'oil',
    'img'   => 'mod_workover.svg',
    'badge' => 'Producción',
    'href'  => 'list.php?s=pozos',
    'accent'=> 'green',
    'visual'=> ['type' => 'mini-bars', 'values' => [2, 5, 4, 6, 7, 5, 4, 8, 6, 5]],
    'kpis'  => [['Pozos', fmt_num($pozosTotal)], ['Vista', 'Ficha'], ['Estado', 'Operativo']],
  ],
  [
    'title' => 'Reconocimientos',
    'desc'  => 'Alarmas reconocidas por usuario y seguimiento de acciones.',
    'icon'  => 'check',
    'img'   => 'mod_seguridad.svg',
    'badge' => 'Operadores',
    'href'  => 'list.php?s=reconocidas',
    'accent'=> 'green',
    'visual'=> ['type' => 'bars', 'bars' => $operators],
    'kpis'  => [['Total', fmt_num($reconocidas)], ['Usuarios', fmt_num(count($operators))], ['Tasa', pct($reconocidas, max(1, $reconocidas + $activas)) . '%']],
  ],
  [
    'title' => 'Importadas',
    'desc'  => 'Alarmas importadas desde sistemas externos y su estado.',
    'icon'  => 'file',
    'img'   => 'mod_vibracion.svg',
    'badge' => 'Externos',
    'href'  => 'list.php?s=importadas',
    'accent'=> 'blue',
    'visual'=> ['type' => 'mini-bars', 'values' => [5, 9, 7, 11, 8, 10, 7, 6, 9, 8]],
    'kpis'  => [['Total', fmt_num($importadasTotal)], ['Estado', 'Procesando'], ['Origen', 'Externo']],
  ],
  [
    'title' => 'Suprimidas',
    'desc'  => 'Eventos suprimidos en las últimas 24 horas.',
    'icon'  => 'shield',
    'img'   => 'mod_mantenimiento.svg',
    'badge' => '24 horas',
    'href'  => 'list.php?s=suprimidas',
    'accent'=> 'petrol',
    'visual'=> ['type' => 'mini-bars', 'values' => [1, 2, 1, 3, 2, 4, 2, max(1, $suprimidas)]],
    'kpis'  => [['24h', fmt_num($suprimidas)], ['Prom./h', fmt_num($suprimidas / 24)], ['Pico', fmt_num(max(1, $suprimidas))]],
  ],
];

$dashboardReportItems=[];
if($reportReady){
    $reportRow=function($sectionKey,$sectionTitle,$identity,array $columns)use(&$dashboardReportItems){
        $payload=ns_report_row_payload($columns);
        $payload['group']='dashboard_inst_sup_'.$sectionKey;
        $payload['group_title']='Dashboard Instalaciones de Superficie · '.$sectionTitle;
        $dashboardReportItems[]=[
            'key'=>ns_report_key('dashboard-inst-sup',[$sectionKey,(string)$identity]),
            'type'=>'row',
            'title'=>'Dashboard Instalaciones de Superficie · '.$sectionTitle.($identity!==''?' · '.$identity:''),
            'payload'=>$payload,
        ];
    };
    $reportChart=function($sectionKey,$title,array $labels,array $datasets,$type='bar',$unit='alarmas')use(&$dashboardReportItems){
        $dashboardReportItems[]=[
            'key'=>ns_report_key('dashboard-inst-sup-chart',[$sectionKey]),
            'type'=>'chart',
            'title'=>'Dashboard Instalaciones de Superficie · '.$title,
            'payload'=>['kind'=>'chart','labels'=>$labels,'datasets'=>$datasets,'chart_type'=>$type,'unit'=>$unit,'palette'=>'multicolor','context'=>'Resumen completo del Dashboard de Instalaciones de Superficie'],
        ];
    };

    $reportRow('estado_operativo','Estado operativo','',[
        'SQL Server'=>$dbStatus?'Conectado':'Sin conexión','PI Web API'=>$piConfigured?'Configurado':'Revisar configuración','Último dato'=>$latestAlarm?:'Sin datos',
        'Demora'=>$latestDelayMin===null?'—':((int)$latestDelayMin.' min'),'Críticas 24h'=>fmt_num($criticas),'Sin comentario'=>fmt_num($visualMetrics['recognition']['pending']??0),
    ]);
    $reportRow('indicadores','Indicadores principales','',[
        'Alarmas 24h indexadas'=>fmt_num($total24h),'Críticas'=>fmt_num($criticas),'Activas'=>fmt_num($activas),'Reconocidas'=>fmt_num($reconocidas),
        'Suprimidas 24h'=>fmt_num($suprimidas),'Comentarios'=>fmt_num($dashboardCommentCount),'Estado de servicios'=>$dbStatus?'SQL OK':'SQL ERROR',
    ]);
    $reportRow('atencion','Atención requerida','',['Reconocimientos sin comentario'=>fmt_num($visualMetrics['recognition']['pending']??0),'Alarmas críticas 24h'=>fmt_num($criticas)]);

    $hourLabels=[];$high=[];$medium=[];$low=[];$active=[];$normalized=[];
    $hourlyMap=[];foreach((array)($visualMetrics['hourly']??[]) as $row)$hourlyMap[(int)($row['hour']??0)]=$row;
    $flowMap=[];foreach((array)($visualMetrics['flow']??[]) as $row)$flowMap[(int)($row['hour']??0)]=$row;
    for($hour=0;$hour<24;$hour++){
        $hourLabels[]=str_pad((string)$hour,2,'0',STR_PAD_LEFT).'h';$hourlyRow=$hourlyMap[$hour]??[];$flowRow=$flowMap[$hour]??[];
        $high[]=(int)($hourlyRow['high']??0);$medium[]=(int)($hourlyRow['medium']??0);$low[]=(int)($hourlyRow['low']??0);
        $active[]=(int)($flowRow['active']??0);$normalized[]=(int)($flowRow['normalized']??0);
    }
    $reportChart('alarmas_hora','Alarmas por hora',$hourLabels,[['label'=>'Alta','values'=>$high],['label'=>'Media','values'=>$medium],['label'=>'Baja','values'=>$low]],'bar','alarmas');
    $reportChart('flujo','Activadas vs normalizadas',$hourLabels,[['label'=>'Activadas','values'=>$active],['label'=>'Normalizadas','values'=>$normalized]],'line','alarmas');

    $pareto=(array)($visualMetrics['pareto']??[]);
    if($pareto)$reportChart('pareto','Pareto de alarmas',array_map(function($row){return(string)($row['tag']??'—');},$pareto),[
        ['label'=>'Alarmas','values'=>array_map(function($row){return(int)($row['value']??0);},$pareto)],
        ['label'=>'Acumulado %','values'=>array_map(function($row){return(float)($row['cum']??0);},$pareto)],
    ],'bar','valor');

    $heatmap=[];
    foreach((array)($visualMetrics['heatmap']??[]) as $row){$date=(string)($row['date']??'');$hour=(int)($row['hour']??0);if($date!=='')$heatmap[$date][$hour]=(int)($row['value']??0);}
    ksort($heatmap);
    foreach($heatmap as $date=>$hours){$columns=['Fecha'=>$date];for($hour=0;$hour<24;$hour++)$columns[str_pad((string)$hour,2,'0',STR_PAD_LEFT).'h']=$hours[$hour]??0;$reportRow('mapa_calor','Mapa de calor semanal',$date,$columns);}
    if(!$heatmap)$reportRow('mapa_calor','Mapa de calor semanal','sin-datos',['Estado'=>'Sin datos disponibles']);

    $installations=(array)($visualMetrics['installations']??[]);
    if($installations){
        $reportChart('instalaciones','Alarmas por instalación',array_map(function($row){return(string)($row['name']??'—');},$installations),[
            ['label'=>'Total','values'=>array_map(function($row){return(int)($row['total']??0);},$installations)],
            ['label'=>'Críticas','values'=>array_map(function($row){return(int)($row['critical']??0);},$installations)],
        ],'bar','alarmas');
        foreach($installations as $row)$reportRow('instalaciones_detalle','Alarmas por instalación',(string)($row['name']??''),['Instalación'=>$row['name']??'—','Total'=>fmt_num($row['total']??0),'Críticas'=>fmt_num($row['critical']??0)]);
    }else{$reportRow('instalaciones_detalle','Alarmas por instalación','sin-datos',['Estado'=>'Sin datos por instalación']);}

    $recognition=(array)($visualMetrics['recognition']??[]);
    $reportChart('cobertura','Cobertura documental',['Comentados','Pendientes'],[['label'=>'Reconocimientos','values'=>[(int)($recognition['commented']??0),(int)($recognition['pending']??0)]]],'doughnut','reconocimientos');
    $reportRow('cobertura_detalle','Cobertura documental','',['Cobertura'=>($recognition['coverage']??0).'%','Reconocidos'=>fmt_num($recognition['total']??0),'Comentados'=>fmt_num($recognition['commented']??0),'Pendientes'=>fmt_num($recognition['pending']??0)]);

    $pending=(array)($visualMetrics['pending_comments']??[]);
    if($pending){foreach($pending as $index=>$row)$reportRow('pendientes','Reconocimientos pendientes de comentario',(string)($row['tag']??$index),[
        'Fecha'=>$row['date']??'—','TAG'=>$row['tag']??'—','Operador'=>$row['operator']??'—','Prioridad'=>$row['priority']??'—','Descripción'=>$row['description']??'—',
    ]);}else{$reportRow('pendientes','Reconocimientos pendientes de comentario','sin-datos',['Estado'=>'No hay pendientes para mostrar']);}

    foreach($modules as $index=>$module){$columns=['Módulo'=>$module['title']??'—','Descripción'=>$module['desc']??'—','Estado'=>$module['badge']??'—'];foreach((array)($module['kpis']??[]) as $kpi)$columns[(string)($kpi[0]??'Indicador')]=(string)($kpi[1]??'—');$reportRow('modulos','Módulos de la plataforma',(string)($module['title']??$index),$columns);}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard Inst Sup · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260715-visual">
  <?php if($reportReady): ?><link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260904-dashboard-full-1"><?php endif; ?>
  <script src="assets/js/chart.umd.js"></script>
  <style>
.dashboardCommentsDateRange{display:flex;align-items:center;gap:7px;border:1px solid var(--line-mid);background:#fff;border-radius:9px;padding:5px 8px}.dashboardCommentsDateRange label{font-size:10px;font-weight:700;color:var(--text-mut);text-transform:uppercase}.dashboardCommentsDateRange input{border:0;outline:0;font:inherit;color:var(--text);background:transparent;max-width:130px}.dashboardCommentsDateRange__sep{color:var(--text-mut)}@media(max-width:1100px){.dashboardCommentsModal__actions{flex-wrap:wrap}.dashboardCommentsDateRange{order:2}}
        .ops-strip{display:grid;grid-template-columns:repeat(6,minmax(150px,1fr));gap:10px;margin:0 0 18px}.ops-chip{background:#fff;border:1px solid var(--line-mid);border-radius:14px;padding:12px 14px;display:flex;align-items:center;gap:10px;min-height:68px}.ops-chip__dot{width:12px;height:12px;border-radius:50%;background:#94a3b8;box-shadow:0 0 0 5px rgba(148,163,184,.12)}.ops-chip.is-ok .ops-chip__dot{background:#0f9f6e;box-shadow:0 0 0 5px rgba(15,159,110,.12)}.ops-chip.is-warn .ops-chip__dot{background:#e28a00;box-shadow:0 0 0 5px rgba(226,138,0,.12)}.ops-chip.is-danger .ops-chip__dot{background:#ef3b30;box-shadow:0 0 0 5px rgba(239,59,48,.12)}.ops-chip span{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--text-mut)}.ops-chip b{display:block;margin-top:2px;color:var(--petrol);font-size:15px}.analytics-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:18px}.analytics-card{background:#fff;border:1px solid var(--line-mid);border-top:3px solid var(--petrol);border-radius:16px;padding:16px;min-width:0}.analytics-card--wide{grid-column:1/-1}.analytics-card__head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}.analytics-card__head h3{font-family:var(--font-head);font-size:19px;margin:0;color:var(--text)}.analytics-card__head p{font-size:11px;color:var(--text-mut);margin:3px 0 0}.analytics-card__badge{font-size:10px;border:1px solid var(--line-mid);border-radius:999px;padding:5px 9px;color:var(--petrol);white-space:nowrap}.analytics-canvas{height:260px;position:relative}.analytics-canvas--small{height:220px}.heatmap-wrap{overflow:auto}.heatmap{display:grid;grid-template-columns:76px repeat(24,minmax(30px,1fr));gap:4px;min-width:940px}.heatmap__head,.heatmap__day,.heatmap__cell{height:28px;display:flex;align-items:center;justify-content:center;border-radius:6px;font-size:10px}.heatmap__head{color:var(--text-mut)}.heatmap__day{justify-content:flex-start;font-weight:700;color:var(--petrol)}.heatmap__cell{background:rgba(26,77,92,var(--heat,.04));color:transparent;cursor:default}.heatmap__cell:hover{outline:2px solid var(--petrol);color:var(--text);background:#fff}.inst-list{display:flex;flex-direction:column;gap:9px}.inst-row{display:grid;grid-template-columns:90px 1fr 70px 70px;gap:8px;align-items:center;font-size:12px}.inst-row__track{height:9px;border-radius:999px;background:#eaf0f3;overflow:hidden}.inst-row__fill{height:100%;border-radius:999px;background:var(--petrol)}.inst-row b{color:var(--petrol)}.coverage{display:grid;grid-template-columns:170px 1fr;gap:18px;align-items:center}.coverage__ring{width:140px;height:140px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(var(--green) var(--coverage),#e8eef1 0);position:relative}.coverage__ring:after{content:"";position:absolute;inset:20px;background:#fff;border-radius:50%}.coverage__ring strong{position:relative;z-index:1;font-size:28px;color:var(--green-tx)}.coverage__stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.coverage__stat{border:1px solid var(--line);border-radius:12px;padding:12px}.coverage__stat span{display:block;font-size:10px;text-transform:uppercase;color:var(--text-mut)}.coverage__stat b{font-size:21px;color:var(--petrol)}.pending-table{width:100%;border-collapse:collapse}.pending-table th,.pending-table td{padding:9px 10px;border-bottom:1px solid var(--line);text-align:left;font-size:12px}.pending-table th{font-size:10px;text-transform:uppercase;color:var(--text-mut)}.priority-pill{display:inline-flex;padding:4px 8px;border-radius:999px;background:#eef5f7;color:var(--petrol);font-size:10px}.priority-pill.is-high{background:#fff0ef;color:#c72f26}.attention-box{display:flex;align-items:center;justify-content:space-between;gap:14px;background:linear-gradient(135deg,#fff,#f7fbfc);border:1px solid var(--line-mid);border-left:4px solid var(--amber);border-radius:14px;padding:14px 16px;margin-bottom:18px}.attention-box h3{margin:0;font-family:var(--font-head);color:var(--petrol);font-size:18px}.attention-box p{margin:3px 0 0;color:var(--text-soft);font-size:12px}.attention-box a{white-space:nowrap}.view-density-hint{font-size:11px;color:var(--text-mut);margin-top:8px}@media(max-width:1200px){.ops-strip{grid-template-columns:repeat(3,1fr)}}@media(max-width:900px){.analytics-grid{grid-template-columns:1fr}.analytics-card--wide{grid-column:auto}.coverage{grid-template-columns:1fr}.ops-strip{grid-template-columns:repeat(2,1fr)}}
  </style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <!-- Encabezado -->
    <div class="page__head">
      <div>
        <h1 class="page__title">Dashboard Inst Sup</h1>
        <div class="page__sub">Plataforma inteligente · CLEAR</div>
      </div>
      <div class="dashboardReportHead"><div class="page__live"><span class="dot"></span><?php if ($stats['ok']): ?>Sistema operativo · En vivo<?php else: ?>Sin conexión a la base<?php endif; ?></div><?php if($reportReady): ?><div class="dashboardReportTools"><label class="dashboardReportToggle"><input type="checkbox" data-dashboard-report-toggle><span data-dashboard-report-label>Incluir resumen completo</span></label><button type="button" class="nsButton is-secondary" data-ns-report-open>Ver reporte <span data-ns-report-count class="nsReportCount" hidden>0</span></button></div><small class="dashboardReportStatus" data-dashboard-report-status></small><?php endif; ?></div>
    </div>

    <section class="ops-strip" aria-label="Estado operativo">
      <div class="ops-chip <?php echo $dbStatus ? 'is-ok' : 'is-danger'; ?>"><i class="ops-chip__dot"></i><div><span>SQL Server</span><b><?php echo $dbStatus ? 'Conectado' : 'Sin conexión'; ?></b></div></div>
      <div class="ops-chip <?php echo $piConfigured ? 'is-ok' : 'is-warn'; ?>"><i class="ops-chip__dot"></i><div><span>PI Web API</span><b><?php echo $piConfigured ? 'Configurado' : 'Revisar configuración'; ?></b></div></div>
      <div class="ops-chip <?php echo ($latestDelayMin!==null && $latestDelayMin<=5) ? 'is-ok' : 'is-warn'; ?>"><i class="ops-chip__dot"></i><div><span>Último dato</span><b><?php echo h($latestAlarm ?: 'Sin datos'); ?></b></div></div>
      <div class="ops-chip <?php echo ($latestDelayMin!==null && $latestDelayMin<=5) ? 'is-ok' : 'is-warn'; ?>"><i class="ops-chip__dot"></i><div><span>Demora</span><b><?php echo $latestDelayMin===null ? '—' : ((int)$latestDelayMin.' min'); ?></b></div></div>
      <div class="ops-chip <?php echo $criticas>0 ? 'is-danger' : 'is-ok'; ?>"><i class="ops-chip__dot"></i><div><span>Críticas 24h</span><b><?php echo fmt_num($criticas); ?></b></div></div>
      <div class="ops-chip <?php echo ($visualMetrics['recognition']['pending']??0)>0 ? 'is-warn' : 'is-ok'; ?>"><i class="ops-chip__dot"></i><div><span>Sin comentario</span><b><?php echo fmt_num($visualMetrics['recognition']['pending']??0); ?></b></div></div>
    </section>

    <!-- Stat cards -->
    <?php if ($stats['ok']): ?>
    <section class="stats stats--dashboard">
      <div class="stat">
        <div class="stat__label">Alarmas 24h indexadas</div>
        <div class="stat__value"><?php echo fmt_num($stats['total24h']); ?></div>
        <div class="stat__detail">Total de eventos registrados en las últimas 24 horas.</div>
      </div>
      <div class="stat">
        <div class="stat__label">Críticas (alta prioridad)</div>
        <div class="stat__value is-red"><?php echo fmt_num($stats['criticas']); ?></div>
        <div class="stat__detail">Requieren atención prioritaria del operador.</div>
      </div>
      <div class="stat">
        <div class="stat__label">Activas</div>
        <div class="stat__value is-amber"><?php echo fmt_num($stats['activas']); ?></div>
        <div class="stat__detail">Alarmas actualmente vigentes en planta.</div>
      </div>
      <div class="stat">
        <div class="stat__label">Reconocidas</div>
        <div class="stat__value is-green with-icon"><?php echo icon('check'); ?><?php echo fmt_num($stats['reconocidas']); ?></div>
        <div class="stat__detail"><span class="muted">Suprimidas 24h:</span> <b><?php echo fmt_num($stats['suprimidas']); ?></b></div>
      </div>

      <?php if($dashboardCanViewComments): ?><button type="button" class="stat stat--comments" id="dashboardCommentsOpen" aria-haspopup="dialog">
        <div class="stat__label">Comentarios</div>
        <div class="stat__value is-blue with-icon"><?php echo icon('message'); ?><?php echo fmt_num($dashboardCommentCount); ?></div>
        <div class="stat__detail">Ver comentarios cargados, del más reciente al más antiguo.</div>
      </button><?php endif; ?>
      <div class="stat"><div class="stat__label">Estado de servicios</div><div class="stat__value is-green"><?php echo $dbStatus ? 'SQL OK' : 'SQL ERROR'; ?></div><div class="stat__detail">PI: <b><?php echo $piConfigured ? 'Configurado' : 'Sin configurar'; ?></b> · Última alarma: <b><?php echo h($latestAlarm ?: 'Sin datos'); ?></b><?php if($latestDelayMin!==null): ?> · demora <?php echo (int)$latestDelayMin; ?> min<?php endif; ?></div></div>
    </section>
    <?php else: ?>
    <section class="stats">
      <div class="stat" style="grid-column:1/-1">
        <div class="stat__label">Base de datos</div>
        <div class="stat__value is-red">Sin conexión</div>
        <div class="stat__detail">No se pudo conectar a SQL Server. Revisá <b>config.php</b> y el driver.<br>
          <span class="muted">Detalle: <?php echo h($stats['error']); ?></span></div>
      </div>
    </section>
    <?php endif; ?>

    <div class="attention-box">
      <div><h3>Atención requerida</h3><p><?php echo fmt_num($visualMetrics['recognition']['pending']??0); ?> reconocimientos no tienen comentario y <?php echo fmt_num($criticas); ?> alarmas fueron clasificadas como críticas en las últimas 24 horas.</p></div>
      <a class="btn-export" href="list.php?s=reconocidas">Revisar reconocimientos</a>
    </div>

    <div class="sectitle"><span>Análisis operativo</span><div class="rule"></div></div>
    <section class="analytics-grid">
      <article class="analytics-card"><div class="analytics-card__head"><div><h3>Alarmas por hora</h3><p>Distribución de criticidad durante las últimas 24 horas.</p></div><span class="analytics-card__badge">24 horas</span></div><div class="analytics-canvas"><canvas id="hourlyChart"></canvas></div></article>
      <article class="analytics-card"><div class="analytics-card__head"><div><h3>Activadas vs normalizadas</h3><p>Permite detectar acumulación de condiciones anormales.</p></div><span class="analytics-card__badge">Flujo horario</span></div><div class="analytics-canvas"><canvas id="flowChart"></canvas></div></article>
      <article class="analytics-card analytics-card--wide"><div class="analytics-card__head"><div><h3>Pareto de alarmas</h3><p>Barras por TAG y porcentaje acumulado para localizar el 80 % del problema.</p></div><span class="analytics-card__badge">Top 20</span></div><div class="analytics-canvas"><canvas id="paretoChart"></canvas></div></article>
      <article class="analytics-card analytics-card--wide"><div class="analytics-card__head"><div><h3>Mapa de calor semanal</h3><p>Intensidad de alarmas por día y hora.</p></div><span class="analytics-card__badge">7 días × 24 h</span></div><div class="heatmap-wrap"><div class="heatmap" id="alarmHeatmap"></div></div></article>
      <article class="analytics-card"><div class="analytics-card__head"><div><h3>Alarmas por instalación</h3><p>Volumen total y cantidad crítica por prefijo de TAG.</p></div><span class="analytics-card__badge">Ranking</span></div><div class="inst-list" id="installationRanking"></div></article>
      <article class="analytics-card"><div class="analytics-card__head"><div><h3>Cobertura documental</h3><p>Reconocimientos con comentario operativo registrado.</p></div><span class="analytics-card__badge">Comentarios</span></div><div class="coverage"><div class="coverage__ring" style="--coverage:<?php echo (float)($visualMetrics['recognition']['coverage']??0); ?>%"><strong><?php echo h((string)($visualMetrics['recognition']['coverage']??0)); ?>%</strong></div><div class="coverage__stats"><div class="coverage__stat"><span>Reconocidos</span><b><?php echo fmt_num($visualMetrics['recognition']['total']??0); ?></b></div><div class="coverage__stat"><span>Comentados</span><b><?php echo fmt_num($visualMetrics['recognition']['commented']??0); ?></b></div><div class="coverage__stat"><span>Pendientes</span><b><?php echo fmt_num($visualMetrics['recognition']['pending']??0); ?></b></div></div></div></article>
      <article class="analytics-card analytics-card--wide"><div class="analytics-card__head"><div><h3>Reconocimientos pendientes de comentario</h3><p>Priorizados por criticidad y fecha.</p></div><a class="analytics-card__badge" href="list.php?s=reconocidas">Abrir grilla</a></div><div style="overflow:auto"><table class="pending-table"><thead><tr><th>Fecha</th><th>TAG</th><th>Operador</th><th>Prioridad</th><th>Descripción</th></tr></thead><tbody><?php foreach(($visualMetrics['pending_comments']??[]) as $pc): ?><tr><td><?php echo h($pc['date']); ?></td><td><b><?php echo h($pc['tag']); ?></b></td><td><?php echo h($pc['operator']); ?></td><td><span class="priority-pill <?php echo strtoupper($pc['priority'])==='HIGH'?'is-high':''; ?>"><?php echo h($pc['priority']?:'—'); ?></span></td><td><?php echo h($pc['description']?:'—'); ?></td></tr><?php endforeach; ?><?php if(empty($visualMetrics['pending_comments'])): ?><tr><td colspan="5">No hay pendientes para mostrar.</td></tr><?php endif; ?></tbody></table></div></article>
    </section>

    <!-- Módulos -->
    <div class="sectitle"><span>Módulos de la plataforma</span><div class="rule"></div></div>
    <section class="modules modules--insights">
      <?php foreach ($modules as $m): ?>
        <a class="mod mod--<?php echo h($m['accent'] ?? 'petrol'); ?>" href="<?php echo h($m['href']); ?>">
          <div class="mod__img" style="background-image:url('assets/img/<?php echo h($m['img']); ?>')"></div>
          <div class="mod__overlay"></div>
          <span class="mod__badge"><?php echo h($m['badge']); ?></span>
          <div class="mod__body">
            <div class="mod__top">
              <div class="mod__icon"><?php echo icon($m['icon']); ?></div>
              <div>
                <div class="mod__title"><?php echo h($m['title']); ?></div>
                <div class="mod__desc"><?php echo h($m['desc']); ?></div>
              </div>
            </div>
            <?php echo render_module_visual($m); ?>
          </div>
        </a>
      <?php endforeach; ?>
    </section>
  </main>
</div>

<div class="dashboardCommentsModal__overlay" id="dashboardCommentsOverlay" hidden></div>
<section class="dashboardCommentsModal" id="dashboardCommentsModal" aria-hidden="true" aria-labelledby="dashboardCommentsTitle">
  <div class="dashboardCommentsModal__head">
    <div>
      <div class="dashboardCommentsModal__eyebrow">Historial</div>
      <h2 id="dashboardCommentsTitle">Comentarios de reconocimientos</h2>
      <p><span id="dashboardCommentsCount"><?php echo fmt_num($dashboardCommentCount); ?></span> comentarios cargados</p>
    </div>
    <div class="dashboardCommentsModal__actions">
      <label class="dashboardCommentsFilter" for="dashboardCommentsTagFilter">
        <span><?php echo icon('search'); ?></span>
        <input type="search" id="dashboardCommentsTagFilter" placeholder="Filtrar por TAG..." autocomplete="off">
      </label>
      <div class="dashboardCommentsDateRange" aria-label="Filtrar comentarios por fecha">
        <label for="dashboardCommentsDateFrom">Desde</label><input type="date" id="dashboardCommentsDateFrom">
        <span class="dashboardCommentsDateRange__sep">—</span>
        <label for="dashboardCommentsDateTo">Hasta</label><input type="date" id="dashboardCommentsDateTo">
      </div>
      <button type="button" class="btn-export dashboardCommentsExportBtn" id="dashboardCommentsExport"><?php echo icon('download'); ?> <span>Exportar a Excel</span></button>
      <button type="button" class="dashboardCommentsCloseBtn" id="dashboardCommentsClose" aria-label="Cerrar">×</button>
    </div>
  </div>
  <div class="dashboardCommentsModal__body">
    <?php if (empty($dashboardComments)): ?>
      <div class="dashboardCommentsModal__empty">Todavía no hay comentarios cargados.</div>
    <?php else: ?>
      <div class="dashboardCommentsTableWrap">
        <table class="grid js-sortable dashboardCommentsTable" id="dashboardCommentsTable">
          <thead><tr><th>Fecha</th><th>Tag</th><th>Descripción</th><th>Operador</th><th>Motivo</th><th>Usuario</th><th>Comentario</th><?php if ($dashboardCanDeleteComments): ?><th class="no-sort" data-export-ignore="1">Acciones</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($dashboardComments as $comment):
            // Mostrar la fecha de creación del comentario. Si falta, usar modificación como respaldo.
            $commentDateRaw=trim((string)($comment['created_at'] ?? ''));
            if ($commentDateRaw==='') $commentDateRaw=trim((string)($comment['updated_at'] ?? ''));
            $commentDateTs=$commentDateRaw!=='' ? strtotime($commentDateRaw) : false;
            $commentDateLabel=$commentDateTs!==false ? date('d/m/Y H:i', $commentDateTs) : ($commentDateRaw!=='' ? $commentDateRaw : '—');
          ?>
            <tr data-comment-id="<?php echo (int)($comment['id'] ?? 0); ?>" data-comment-tag="<?php echo h((string)($comment['tag'] ?? '')); ?>" data-comment-date="<?php echo h($commentDateTs!==false ? date('Y-m-d',$commentDateTs) : ''); ?>">
              <td data-sort-value="<?php echo h($commentDateRaw); ?>" class="cell-mono"><?php echo h($commentDateLabel); ?></td>
              <td class="cell-text"><?php echo h($comment['tag'] ?? 'Sin TAG'); ?></td>
              <td class="cell-text"><?php echo h($comment['description'] ?? '—'); ?></td>
              <td class="cell-text"><?php echo h($comment['operator'] ?? '—'); ?></td>
              <td class="cell-text"><?php echo h($comment['reason'] ?? '—'); ?></td>
              <td class="cell-text"><?php echo h($comment['user'] ?? '—'); ?></td>
              <td class="cell-text dashboardCommentsTable__comment"><?php echo nl2br(h($comment['comment'] ?? '')); ?></td>
              <?php if ($dashboardCanDeleteComments): ?>
                <td class="dashboardCommentsTable__actions" data-export-ignore="1">
                  <button type="button" class="dashboardCommentDeleteBtn" data-comment-delete="<?php echo (int)($comment['id'] ?? 0); ?>" data-comment-tag-label="<?php echo h((string)($comment['tag'] ?? '')); ?>" title="Eliminar comentario">
                    <?php echo icon('trash'); ?> <span>Eliminar</span>
                  </button>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<script src="assets/js/app.js?v=20260714-7"></script>
<script>
(function(){
  var m=document.getElementById('dashboardCommentsModal');
  var o=document.getElementById('dashboardCommentsOverlay');
  var b=document.getElementById('dashboardCommentsOpen');
  var c=document.getElementById('dashboardCommentsClose');
  var x=document.getElementById('dashboardCommentsExport');
  var t=document.getElementById('dashboardCommentsTable');
  var f=document.getElementById('dashboardCommentsTagFilter');
  var df=document.getElementById('dashboardCommentsDateFrom');
  var dt=document.getElementById('dashboardCommentsDateTo');
  if(!m||!o||!b||!c)return;

  function isoDate(d){var p=function(n){return n<10?'0'+n:n;};return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate());}
  function setDefaultWeek(){if(!df||!dt)return;var today=new Date(),from=new Date(today.getFullYear(),today.getMonth(),today.getDate()-6);df.value=isoDate(from);dt.value=isoDate(today);}
  function open(){setDefaultWeek();filterRows();o.hidden=false;m.classList.add('is-open');m.setAttribute('aria-hidden','false');document.body.classList.add('has-dashboard-comments');c.focus();}
  function close(){m.classList.remove('is-open');m.setAttribute('aria-hidden','true');o.hidden=true;document.body.classList.remove('has-dashboard-comments');}
  function csvEscape(value){var s=String(value==null?'':value).replace(/\r?\n/g,' ').trim();return '"'+s.replace(/"/g,'""')+'"';}
  function filterRows(){if(!t)return;var q=(f&&f.value?f.value:'').trim().toLowerCase();var from=df?df.value:'';var to=dt?dt.value:'';var visible=0;Array.prototype.forEach.call(t.querySelectorAll('tbody tr'),function(tr){var tag=(tr.getAttribute('data-comment-tag')||'').toLowerCase();var date=tr.getAttribute('data-comment-date')||'';var hide=(!!q&&tag.indexOf(q)===-1)||(from&&date&&date<from)||(to&&date&&date>to)||(from&&!date)||(to&&!date);tr.hidden=hide;if(!hide)visible++;});var el=document.getElementById('dashboardCommentsCount');if(el)el.textContent=visible.toLocaleString('es-AR');}
  function exportCsv(){
    if(!t)return;
    var rows=[];
    var header=t.querySelector('thead tr');
    if(header)rows.push(Array.prototype.map.call(header.querySelectorAll('th:not([data-export-ignore])'),function(cell){return csvEscape(cell.textContent);}).join(';'));
    Array.prototype.forEach.call(t.querySelectorAll('tbody tr'),function(tr){
      if(tr.hidden)return;
      rows.push(Array.prototype.map.call(tr.querySelectorAll('td:not([data-export-ignore])'),function(cell){return csvEscape(cell.textContent);}).join(';'));
    });
    var blob=new Blob(['\uFEFF'+rows.join('\r\n')],{type:'text/csv;charset=utf-8;'});
    var url=URL.createObjectURL(blob),a=document.createElement('a'),d=new Date(),pad=function(n){return n<10?'0'+n:n;};
    a.href=url;
    a.download='CLEAR_comentarios_reconocimientos_'+d.getFullYear()+pad(d.getMonth()+1)+pad(d.getDate())+'_'+pad(d.getHours())+pad(d.getMinutes())+'.csv';
    document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(url);
  }
  function updateCount(){
    var count=t?t.querySelectorAll('tbody tr').length:0;
    var el=document.getElementById('dashboardCommentsCount');if(el)el.textContent=count.toLocaleString('es-AR');
    var card=document.querySelector('.stat--comments .stat__value');if(card)card.textContent=count.toLocaleString('es-AR');
  }
  function deleteComment(btn){
    var id=Number(btn.getAttribute('data-comment-delete')||0);
    var tag=btn.getAttribute('data-comment-tag-label')||'';
    if(!id)return;
    if(!window.confirm('¿Eliminar el comentario del TAG '+tag+'?\n\nEl registro quedará desactivado en SQL para conservar la auditoría.'))return;
    var original=btn.innerHTML;
    btn.disabled=true;btn.textContent='Eliminando…';
    var data=new FormData();data.append('action','delete_comment');data.append('id',String(id));
    fetch('comments_admin_api.php',{method:'POST',body:data,headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.json().then(function(payload){if(!r.ok)throw new Error(payload.error||'No se pudo eliminar');return payload;});})
      .then(function(){
        var row=btn.closest('tr');if(row)row.remove();
        updateCount();
        if(t&&!t.querySelector('tbody tr')){
          var wrap=t.closest('.dashboardCommentsTableWrap');
          if(wrap)wrap.innerHTML='<div class="dashboardCommentsModal__empty">Todavía no hay comentarios cargados.</div>';
        }
      })
      .catch(function(error){alert(error.message||'No se pudo eliminar el comentario.');btn.disabled=false;btn.innerHTML=original;});
  }

  b.addEventListener('click',open);
  c.addEventListener('click',close);
  o.addEventListener('click',close);
  if(x)x.addEventListener('click',exportCsv);
  if(f)f.addEventListener('input',filterRows);
  if(df)df.addEventListener('change',filterRows);
  if(dt)dt.addEventListener('change',filterRows);
  document.addEventListener('click',function(e){var btn=e.target.closest&&e.target.closest('[data-comment-delete]');if(btn)deleteComment(btn);});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&m.classList.contains('is-open'))close();});
})();
</script>

<script>
(function(){
  var hourly=<?php echo json_encode($visualMetrics['hourly']??[],JSON_UNESCAPED_UNICODE); ?>;
  var flow=<?php echo json_encode($visualMetrics['flow']??[],JSON_UNESCAPED_UNICODE); ?>;
  var pareto=<?php echo json_encode($visualMetrics['pareto']??[],JSON_UNESCAPED_UNICODE); ?>;
  var heatmap=<?php echo json_encode($visualMetrics['heatmap']??[],JSON_UNESCAPED_UNICODE); ?>;
  var installations=<?php echo json_encode($visualMetrics['installations']??[],JSON_UNESCAPED_UNICODE); ?>;
  function hours(rows){var out=[];for(var h=0;h<24;h++){var f=rows.find(function(r){return Number(r.hour)===h;})||{};out.push(f);}return out;}
  function baseOptions(){return {responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{x:{grid:{display:false},ticks:{maxRotation:0}},y:{beginAtZero:true,grid:{color:'rgba(26,77,92,.08)'}}}};}
  if(window.Chart){
    var h=hours(hourly),hc=document.getElementById('hourlyChart'); if(hc)new Chart(hc,{type:'bar',data:{labels:h.map(function(_,i){return String(i).padStart(2,'0')+'h';}),datasets:[{label:'Alta',data:h.map(function(r){return Number(r.high||0);}),backgroundColor:'rgba(239,59,48,.75)'},{label:'Media',data:h.map(function(r){return Number(r.medium||0);}),backgroundColor:'rgba(226,138,0,.75)'},{label:'Baja',data:h.map(function(r){return Number(r.low||0);}),backgroundColor:'rgba(15,159,110,.72)'}]},options:Object.assign(baseOptions(),{scales:{x:{stacked:true,grid:{display:false},ticks:{maxTicksLimit:12}},y:{stacked:true,beginAtZero:true,grid:{color:'rgba(26,77,92,.08)'}}}})});
    var fl=hours(flow),fc=document.getElementById('flowChart'); if(fc)new Chart(fc,{type:'line',data:{labels:fl.map(function(_,i){return String(i).padStart(2,'0')+'h';}),datasets:[{label:'Activadas',data:fl.map(function(r){return Number(r.active||0);}),borderColor:'#ef3b30',backgroundColor:'rgba(239,59,48,.08)',fill:true,tension:.25},{label:'Normalizadas',data:fl.map(function(r){return Number(r.normalized||0);}),borderColor:'#0f9f6e',backgroundColor:'rgba(15,159,110,.06)',fill:true,tension:.25}]},options:baseOptions()});
    var pc=document.getElementById('paretoChart'); if(pc)new Chart(pc,{data:{labels:pareto.map(function(r){return r.tag;}),datasets:[{type:'bar',label:'Alarmas',data:pareto.map(function(r){return Number(r.value||0);}),backgroundColor:'rgba(26,77,92,.78)',yAxisID:'y'},{type:'line',label:'Acumulado %',data:pareto.map(function(r){return Number(r.cum||0);}),borderColor:'#e28a00',backgroundColor:'#e28a00',tension:.2,yAxisID:'y1'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{x:{ticks:{maxRotation:55,minRotation:35}},y:{beginAtZero:true,grid:{color:'rgba(26,77,92,.08)'}},y1:{position:'right',min:0,max:100,ticks:{callback:function(v){return v+'%';}},grid:{drawOnChartArea:false}}}}});
  }
  var hm=document.getElementById('alarmHeatmap'); if(hm){var grouped={},max=1;heatmap.forEach(function(r){grouped[r.date]=grouped[r.date]||{};grouped[r.date][r.hour]=Number(r.value||0);max=Math.max(max,Number(r.value||0));});hm.innerHTML='<div></div>'+Array.from({length:24},function(_,i){return '<div class="heatmap__head">'+String(i).padStart(2,'0')+'</div>';}).join('');Object.keys(grouped).sort().forEach(function(d){var date=new Date(d+'T00:00:00'),label=isNaN(date)?d:date.toLocaleDateString('es-AR',{weekday:'short',day:'2-digit'});hm.insertAdjacentHTML('beforeend','<div class="heatmap__day">'+label+'</div>');for(var i=0;i<24;i++){var v=grouped[d][i]||0,alpha=.04+(v/max)*.82;hm.insertAdjacentHTML('beforeend','<div class="heatmap__cell" style="--heat:'+alpha.toFixed(2)+'" title="'+d+' '+String(i).padStart(2,'0')+':00 · '+v+' alarmas">'+v+'</div>');}});}
  var ir=document.getElementById('installationRanking'); if(ir){var mx=Math.max.apply(null,installations.map(function(r){return Number(r.total||0);}).concat([1]));ir.innerHTML=installations.map(function(r){return '<div class="inst-row"><b>'+r.name+'</b><div class="inst-row__track"><div class="inst-row__fill" style="width:'+((Number(r.total||0)/mx)*100).toFixed(1)+'%"></div></div><span>'+Number(r.total||0).toLocaleString('es-AR')+'</span><span style="color:#c72f26">'+Number(r.critical||0).toLocaleString('es-AR')+' crít.</span></div>';}).join('')||'<div class="mod__empty">Sin datos por instalación</div>';}
})();
</script>

<?php if($reportReady): ?><script>window.CLEAR_WEEKLY_NEWS={};window.CLEAR_DASHBOARD_REPORT=<?php echo json_encode(['items'=>$dashboardReportItems,'addedMessage'=>'Dashboard de Instalaciones de Superficie completo agregado al reporte.','removedMessage'=>'Dashboard de Instalaciones de Superficie quitado del reporte.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;</script><script src="assets/js/novedades_semanales.js?v=20260904-dashboard-full-1"></script><script src="assets/js/dashboard_report.js?v=20260904-dashboard-full-1"></script><?php endif; ?>

</body>
</html>
