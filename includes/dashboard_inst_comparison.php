<?php
// Comparar exactamente las 24 posiciones que representan los gráficos.
function dsi_chart_summary(array $rows, array $fields, $expected, $criticalExpected = null)
{
    $hours=[];
    foreach($rows as $row){
        $hour=$row['hour']??null;
        if(!is_numeric($hour)||(float)$hour!=(int)$hour||$hour<0||$hour>23)continue;
        if(!array_key_exists((int)$hour,$hours))$hours[(int)$hour]=$row;
    }
    $totals=array_fill_keys($fields,0);
    foreach($hours as $row)foreach($fields as $field)$totals[$field]+=(int)($row[$field]??0);
    $total=array_sum($totals);
    return ['available'=>count($hours)>0,'series'=>$totals,'total'=>$total,'expected'=>(int)$expected,
        'critical_expected'=>$criticalExpected===null?null:(int)$criticalExpected,
        'matches'=>count($hours)>0&&$total===(int)$expected&&($criticalExpected===null||($totals['high']??0)===(int)$criticalExpected)];
}

function dsi_chart_summary_html(array $summary, $kind)
{
    if(!$summary['available'])return '<p class="dashboard-chart-check">Sin distribución horaria disponible para comparar con las tarjetas.</p>';
    $labels=$kind==='hourly'?['high'=>'Alta','medium'=>'Media','low'=>'Baja y otras']:['active'=>'Activadas 24 h','normalized'=>'Normalizadas 24 h'];
    $html='<div class="dashboard-chart-totals" aria-label="Totales del gráfico">';
    foreach($labels as $key=>$label)$html.='<span>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').' <b>'.number_format($summary['series'][$key]??0,0,',','.').'</b></span>';
    $html.='<span class="dashboard-chart-total">Total 24 h <b>'.number_format($summary['total'],0,',','.').'</b></span></div>';
    if($summary['matches'])$text=$kind==='hourly'?'La suma coincide con Alarmas 24 h y Alta coincide con Críticas 24 h.':'La suma coincide con Alarmas 24 h. Estas activaciones no son el total de alarmas vigentes.';
    else{
        $text='La distribución no coincide con las tarjetas: Alarmas 24 h = '.number_format($summary['expected'],0,',','.');
        if($kind==='hourly')$text.='; Críticas 24 h = '.number_format($summary['critical_expected'],0,',','.');
        $text.='. Revisar la actualización de la caché.';
    }
    return $html.'<p class="dashboard-chart-check'.($summary['matches']?'':' is-warning').'"'.($summary['matches']?'':' role="status"').'>'.htmlspecialchars($text,ENT_QUOTES,'UTF-8').'</p>';
}
