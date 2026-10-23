<?php
require_once __DIR__.'/../includes/dashboard_inst_comparison.php';
function dsi_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$hourly=[['hour'=>0,'high'=>100,'medium'=>0,'low'=>200],['hour'=>14,'high'=>45,'medium'=>0,'low'=>13]];
$flow=[['hour'=>0,'active'=>300,'normalized'=>10],['hour'=>14,'active'=>45,'normalized'=>3]];
$summary=dsi_chart_summary($hourly,['high','medium','low'],358,145);
dsi_check($summary['matches']&&$summary['total']===358,'Totales reales de la captura SQL');
dsi_check(dsi_chart_summary($flow,['active','normalized'],358)['matches'],'345 activadas + 13 normalizadas = 358');
dsi_check(!dsi_chart_summary($flow,['active','normalized'],969)['matches'],'No equiparar eventos con stock vigente');
dsi_check(!dsi_chart_summary($hourly,['high','medium','low'],358,146)['matches'],'Detectar diferencia en Críticas aunque coincida total');
dsi_check(!dsi_chart_summary([],['active','normalized'],0)['available'],'Sin series no equivale a cero confirmado');
dsi_check(dsi_chart_summary([['hour'=>0,'active'=>0,'normalized'=>0]],['active','normalized'],0)['matches'],'Cero real con distribución disponible');
$duplicates=array_merge($hourly,[['hour'=>0,'high'=>999],['hour'=>25,'high'=>999]]);
dsi_check(dsi_chart_summary($duplicates,['high','medium','low'],358,145)['matches'],'Usar primera coincidencia por hora como Chart.js');
$html=dsi_chart_summary_html($summary,'hourly');
dsi_check(strpos($html,'145')!==false&&strpos($html,'213')!==false&&strpos($html,'358')!==false,'Resumen con cantidades comparables');
dsi_check(strpos(dsi_chart_summary_html(dsi_chart_summary($flow,['active','normalized'],999),'flow'),'is-warning')!==false,'Advertir caché inconsistente');
echo "OK: totales SQL, prioridades, stock distinto, ausencia de datos y discrepancias.\n";
