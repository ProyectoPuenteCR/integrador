<?php
/* Reporte reutilizable de fallas. Trabaja sobre la lectura del dashboard; no consulta SQL. */
// Prefijo propio: pf_* pertenece al módulo PUMP OFF.

function dpf_input(array $query,$name,$default=''){
    $value=$query[$name]??$default;
    return is_scalar($value)?trim(substr((string)$value,0,300)):(string)$default;
}
function dpf_normalize($value){
    return strtr(strtolower(trim((string)$value)),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n']);
}
function dpf_contains($value,$needle){return $needle===''||strpos(dpf_normalize($value),dpf_normalize($needle))!==false;}
function dpf_method($value){
    $value=trim((string)$value);
    if(in_array($value,['BM','BES','PCP'],true))return $value;
    if($value===''||preg_match('/sin (identificar|dato|clasificar)/i',$value))return 'Sin identificar';
    return 'Otros';
}
function dpf_csv_value($value){
    $value=(string)$value;
    // Evitar fórmulas al abrir un CSV con textos de SCADA en Excel.
    return preg_match('/^[\s\x00-\x1f]*[=+\-@]/u',$value)||preg_match('/^[\t\r\n]/',$value)?"'".$value:$value;
}
function dpf_columns(array $row){
    return [
        'Zona'=>(string)($row['zona']??'Sin identificar'),
        'Instalación'=>(string)$row['pozo'],
        'Supervisor'=>(string)($row['supervisor']??'Sin asignar'),
        'Detalle de falla'=>(string)($row['detalle_falla']??'En falla'),
        'Cant. de fallas acumuladas durante el día operativo'=>$row['fallas_dia']===null?'Sin dato':(int)$row['fallas_dia'],
        'Método de extracción'=>(string)($row['metodo']??'Sin identificar'),
    ];
}

function dpf_build_report(array $data,array $query=[],bool $embedded=false)
{
    $filters=['q'=>dpf_input($query,'q'),'zona'=>dpf_input($query,'zona'),'instalacion'=>dpf_input($query,'instalacion'),'supervisor'=>dpf_input($query,'supervisor'),'detalle'=>dpf_input($query,'detalle'),'cantidad'=>dpf_input($query,'cantidad')];
    $method=dpf_input($query,'metodo');
    if(!in_array($method,['','BM','BES','PCP','Otros','Sin identificar'],true))$method='';
    $sort=dpf_input($query,'orden','fallas_dia');
    if(!in_array($sort,['zona','pozo','supervisor','detalle_falla','fallas_dia','metodo'],true))$sort='fallas_dia';
    $direction=dpf_input($query,'sentido','desc')==='asc'?'asc':'desc';
    $size=dpf_input($query,'cantidad_pagina','50');
    if(!in_array($size,['50','100','todas'],true))$size='50';
    $print=!$embedded&&dpf_input($query,'imprimir')==='1';
    $params=array_merge($filters,['metodo'=>$method,'orden'=>$sort,'sentido'=>$direction,'cantidad_pagina'=>$size]);
    $formAction=$embedded?'dashboard_pozos.php#pozos-en-falla':'pozos_falla.php';
    $clearUrl=$formAction;
    $gridKey=$embedded?'dashboard_pozos_fallas':'pozos_falla';
    $url=static function(array $changes=[])use($params,$embedded){
        $query=array_merge($params,$changes);
        $query=array_filter($query,static function($value){return $value!==''&&$value!==null;});
        // Las acciones de archivo van al reporte independiente, antes de emitir HTML.
        $inDashboard=$embedded&&!isset($query['exportar'])&&!isset($query['imprimir']);
        $path=$inDashboard?'dashboard_pozos.php':'pozos_falla.php';
        return $path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986).($inDashboard?'#pozos-en-falla':'');
    };

    $now=(int)($data['now']??time());
    $cut=(string)($data['config']['hora_corte']??'00:00:00');
    if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',$cut))$cut='00:00:00';
    $cutParts=explode(':',$cut);
    $dayStart=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime((int)$cutParts[0],(int)$cutParts[1],(int)($cutParts[2]??0));
    if($dayStart->getTimestamp()>$now)$dayStart=$dayStart->modify('-1 day');
    $dayEnd=$dayStart->modify('+1 day');
    $dayKey=$dayStart->format('Y-m-d');
    $dayFormat=(int)($cutParts[2]??0)!==0?'d/m/Y H:i:s':'d/m/Y H:i';
    $dayLabel=$dayStart->format($dayFormat).' a '.$dayEnd->format($dayFormat);

    $faults=[];$seen=[];$last=0;
    $hasTelemetry=!empty($data['rows']);
    foreach(($data['rows']??[]) as $row){
        $last=max($last,(int)($row['date']??0));
        if(($row['state']??'')!=='En falla')continue;
        $key=(string)($row['pozo_clave']??strtoupper(trim((string)($row['pozo']??''))));
        if($key===''||isset($seen[$key]))continue;
        $seen[$key]=true;
        $row['pozo_clave']=$key;
        $row['fallas_dia']=isset($row['fallas_dia'])&&is_numeric($row['fallas_dia'])?max(0,(int)$row['fallas_dia']):null;
        $row['metodo']=trim((string)($row['metodo']??''))?:'Sin identificar';
        $row['zona']=trim((string)($row['zona']??''))?:'Sin identificar';
        $row['supervisor']=trim((string)($row['supervisor']??''))?:'Sin asignar';
        $row['detalle_falla']=trim((string)($row['detalle_falla']??''))?:'En falla según telemetría';
        $faults[]=$row;
    }
    $methodCounts=['BM'=>0,'BES'=>0,'PCP'=>0,'Otros'=>0,'Sin identificar'=>0];
    $base=[];
    foreach($faults as $row){
        if(!dpf_contains(implode(' ',dpf_columns($row)).' '.($row['bateria']??''),$filters['q']))continue;
        if(!dpf_contains($row['zona'],$filters['zona'])||!dpf_contains($row['pozo'].' '.($row['bateria']??''),$filters['instalacion'])||!dpf_contains($row['supervisor'],$filters['supervisor'])||!dpf_contains($row['detalle_falla'],$filters['detalle']))continue;
        if($filters['cantidad']!==''&&($row['fallas_dia']===null||!preg_match('/^[0-9]+$/D',$filters['cantidad'])||$row['fallas_dia']!==(int)$filters['cantidad']))continue;
        $methodCounts[dpf_method($row['metodo'])]++;
        $base[]=$row;
    }
    $filtered=array_values(array_filter($base,static function($row)use($method){return $method===''||dpf_method($row['metodo'])===$method;}));
    usort($filtered,static function($a,$b)use($sort,$direction){
        if($sort==='fallas_dia'){
            // Los conteos desconocidos siempre quedan al final.
            if($a[$sort]===null&&$b[$sort]!==null)return 1;
            if($a[$sort]!==null&&$b[$sort]===null)return -1;
            $cmp=($a[$sort]??0)<=>($b[$sort]??0);
        }else{$cmp=strnatcmp(dpf_normalize($a[$sort]??''),dpf_normalize($b[$sort]??''));}
        if($cmp!==0)return $direction==='asc'?$cmp:-$cmp;
        return strnatcmp(dpf_normalize($a['pozo']),dpf_normalize($b['pozo']));
    });
    $total=count($filtered);
    $pageSize=$size==='todas'||$print?max(1,$total):(int)$size;
    $pages=max(1,(int)ceil($total/$pageSize));
    $page=max(1,min($pages,(int)dpf_input($query,'pagina','1')));
    if($print)$page=1;
    $offset=($page-1)*$pageSize;
    $pageRows=array_slice($filtered,$offset,$pageSize);

    $columns=['zona'=>'Zona','pozo'=>'Instalación','supervisor'=>'Supervisor','detalle_falla'=>'Detalle de falla','fallas_dia'=>'Cant. de fallas acumuladas durante el día operativo','metodo'=>'Método de extracción'];
    return compact('filters','method','sort','direction','size','print','params','url','formAction','clearUrl','gridKey','dayStart','dayEnd','dayKey','dayLabel','faults','hasTelemetry','base','methodCounts','filtered','total','pageSize','pages','page','offset','pageRows','last','columns');
}

function dpf_report_items(array $model)
{
    extract($model,EXTR_SKIP);
    $reportItems=[];
    if(!$hasTelemetry)return $reportItems;
    $counts=['BM'=>0,'BES'=>0,'PCP'=>0,'Otros'=>0,'Sin identificar'=>0];
    foreach($filtered as $row)$counts[dpf_method($row['metodo'])]++;
    $summary=ns_report_row_payload(array_merge(['Día operativo'=>$dayLabel,'Pozos en falla'=>$total],$counts));
    $summary['group']='pozos_falla_resumen_'.$dayKey;
    $summary['group_title']='Pozos en falla · Resumen';
    $reportItems[]=['key'=>ns_report_key('pozos-falla-resumen',[$dayKey,$params]),'type'=>'row','title'=>'Pozos en falla · Resumen del '.$dayStart->format('d/m/Y'),'payload'=>$summary];
    foreach($filtered as $row){
        $payload=ns_report_row_payload(dpf_columns($row));
        $payload['group']='pozos_falla_'.$dayKey;
        $payload['group_title']='Pozos en falla · Día operativo '.$dayLabel;
        $reportItems[]=['key'=>ns_report_key('pozos-falla',[$dayKey,(string)$row['pozo_clave']]),'type'=>'row','title'=>'Pozos en falla · '.$row['pozo'],'payload'=>$payload];
    }
    return $reportItems;
}

function dpf_render_styles()
{
    // La copia incorporada conserva el formato si el despliegue omite el CSS.
    $css=@file_get_contents(__DIR__.'/../assets/css/pozos_falla.css');
    if($css===false||trim($css)==='')$css=<<<'CLEAR_FALLAS_CSS'
.pf{color:var(--text);padding-bottom:18px}.pf-heading{align-items:center;gap:12px;flex-wrap:wrap}.pf-heading .page__sub{text-transform:none;letter-spacing:.02em}.pf-explanation,.pf-footnote{font-size:12px;line-height:1.55;color:var(--text-mut);margin:0 0 14px}.pf-footnote{margin:12px 2px 0}.pf-notice{padding:12px 14px;margin-bottom:12px;background:var(--surface);border:1px solid #d3a75a;border-left:4px solid #d3a75a;border-radius:10px;font-size:12px;line-height:1.5}
.pf-kpis{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:12px;margin-bottom:12px}.pf-kpi{padding:16px 18px;background:var(--surface);border:1px solid var(--line);border-top:3px solid #dc5555;border-radius:13px;text-decoration:none;color:var(--text);box-shadow:0 4px 16px rgba(15,45,58,.04);transition:border-color .15s,background .15s}.pf-kpi span{display:block;font-size:11px;text-transform:uppercase;font-weight:800;letter-spacing:.04em;color:var(--petrol)}.pf-kpi strong{display:block;font-size:31px;line-height:1.2;margin:7px 0 3px}.pf-kpi small{font-size:11px;color:var(--text-mut)}.pf-kpi:hover,.pf-kpi:focus-visible,.pf-kpi.is-active{border-color:var(--petrol);background:var(--petrol-soft)}.pf-other{display:flex;align-items:center;gap:9px;flex-wrap:wrap;font-size:12px;color:var(--text-mut);margin-bottom:12px}.pf-method-link{display:inline-flex;gap:5px;align-items:center;padding:5px 9px;border-radius:99px;border:1px solid var(--line);color:var(--text);background:var(--surface);text-decoration:none}.pf-method-link.is-active{border-color:var(--petrol);background:var(--petrol-soft)}
.pf-toolbar{display:flex;gap:9px;align-items:flex-end;flex-wrap:wrap;padding:13px;background:var(--surface);border:1px solid var(--line);border-radius:13px;margin:14px 0}.pf-toolbar label{font-size:11px;font-weight:700;color:var(--text-mut);display:flex;flex-direction:column;gap:5px}.pf-search{flex:1;min-width:230px}.pf-toolbar input,.pf-toolbar select,.pf-filter-row input,.pf-filter-row select{box-sizing:border-box;padding:9px 10px;border:1px solid var(--line-mid);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;font-size:12px;min-height:36px}.pf-toolbar input{width:100%}.pf-button{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:36px;box-sizing:border-box;padding:8px 11px;border:1px solid var(--line-mid);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;font-size:12px;font-weight:700;line-height:18px;text-decoration:none;cursor:pointer}.pf-button:hover,.pf-button:focus-visible{background:var(--petrol-soft);border-color:var(--petrol)}.pf-button.primary{background:var(--petrol);border-color:var(--petrol);color:white}.pf-button.clear{color:#a23d2d;border-color:#d9a298;background:var(--surface)}.pf-report-tools{margin:0 0 13px;align-items:center;justify-content:flex-start;gap:10px;flex-wrap:wrap}.pf-report-note{font-size:11px;color:var(--text-mut)}
.pf-grid-panel{background:var(--surface);border:1px solid var(--line);border-radius:13px;padding:14px;min-width:0}.pf-grid-summary{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:12px;margin-bottom:12px}.pf-grid-summary span{color:var(--text-mut);font-size:11px}.pf-table-wrap{overflow:auto;max-height:65vh;border:1px solid var(--line);border-radius:10px}.pf-table{width:100%;min-width:1020px;border-spacing:0;border-collapse:separate;font-size:12px;table-layout:fixed}.pf-table thead th{position:sticky;top:0;z-index:2;background:var(--surface-2);padding:10px 9px;border-bottom:1px solid var(--line);color:var(--petrol);font-size:10px;text-transform:uppercase;letter-spacing:.025em;vertical-align:middle;white-space:normal;line-height:1.35}.pf-table thead th:nth-child(1){width:130px}.pf-table thead th:nth-child(2){width:190px}.pf-table thead th:nth-child(3){width:150px}.pf-table thead th:nth-child(4){width:300px}.pf-table thead th:nth-child(5){width:170px}.pf-table thead th:nth-child(6){width:140px}.pf-table thead a{color:inherit;text-decoration:none;display:block}.pf-table .pf-filter-row th{top:var(--pf-header-height,60px);padding:7px;background:var(--surface-2);z-index:1}.pf-filter-row input,.pf-filter-row select{width:100%;min-width:0;font-weight:400;text-transform:none;letter-spacing:normal}.pf-table tbody td{padding:11px 9px;border-bottom:1px solid var(--line);white-space:normal;vertical-align:top;overflow-wrap:anywhere;line-height:1.45}.pf-table tbody td small{display:block;font-size:10px;color:var(--text-mut);margin-top:3px}.pf-table tbody tr:hover td{background:var(--petrol-soft)}.pf-table .pf-count{text-align:right;font-weight:800;font-variant-numeric:tabular-nums}.pf-method{display:inline-flex;max-width:100%;background:var(--petrol-soft);color:var(--petrol);border-radius:99px;padding:3px 8px;font-size:11px;font-weight:700}.pf-unknown{font-size:11px;font-weight:500;color:var(--text-mut)}.pf-table .pf-empty{text-align:center;padding:28px 15px;color:var(--text-mut)}.pf-pagination{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text-mut);padding-top:13px}.pf-pagination>div{display:flex;align-items:center;gap:10px}
.pf{min-width:0}.pf-kpi{display:block;box-sizing:border-box;min-width:0}.pf-kpi strong{font-variant-numeric:tabular-nums}.pf-kpi small{display:block;line-height:1.4}.pf-toolbar{box-sizing:border-box}.pf .pf-report-tools{justify-content:flex-start}
table.grid.pf-table thead th,table.grid.pf-table thead th a{white-space:normal;overflow-wrap:anywhere}table.grid.pf-table thead th a{display:block}table.grid.pf-table tbody td{white-space:normal;vertical-align:top}
@media print{table.grid.pf-table thead th{color:var(--petrol)!important}}
@media(max-width:900px){.pf-kpis{grid-template-columns:repeat(2,minmax(130px,1fr))}.pf-toolbar{gap:8px}.pf-search{min-width:100%}.pf-grid-panel{padding:10px}.pf-table-wrap{max-height:62vh}.pf-heading .page__sub{line-height:1.6}}
@media(max-width:480px){.pf-kpis{gap:8px}.pf-kpi{padding:13px}.pf-kpi strong{font-size:27px}.pf-toolbar .pf-button{flex:1;white-space:nowrap}.pf-pagination{justify-content:center}.pf-grid-summary{align-items:flex-start;flex-direction:column}.pf-explanation{font-size:11px}}
@media print{.pf-noprint,.clearGridToolbar,.clearGridLocalTools,.clearGridTools,.clearGridToolsToolbar,.clearGridToolsBtn,.dashboardReportHead{display:none!important}.pf{padding:0!important}.pf-kpis{grid-template-columns:repeat(4,1fr)!important;gap:3mm!important;break-inside:avoid}.pf-kpi{padding:3mm!important;box-shadow:none!important;border-color:#b9c6cc!important;background:#fff!important}.pf-kpi strong{font-size:19pt!important}.pf-kpi span,.pf-kpi small{font-size:8pt!important}.pf-grid-panel{padding:0!important;border:0!important;box-shadow:none!important}.pf-table-wrap{overflow:visible!important;max-height:none!important;border:0!important}.pf-table{width:100%!important;min-width:0!important;table-layout:fixed!important;font-size:8pt!important}.pf-table thead{display:table-header-group!important}.pf-table thead th{position:static!important;font-size:7pt!important;width:auto!important;background:#e8eef1!important;padding:5px!important}.pf-table thead th:nth-child(4){width:28%!important}.pf-table tbody td{font-size:8pt!important;padding:5px!important;white-space:normal!important}.pf-table tbody tr{break-inside:avoid}.pf-explanation,.pf-footnote{font-size:8pt!important}.pf-notice{font-size:8pt!important}.pf-other{font-size:8pt!important}.column-resizer,.column-drag-handle,.sort-indicator{display:none!important}}
CLEAR_FALLAS_CSS;
    echo '<style id="pozos-falla-styles">'.$css.'</style>';
}

function dpf_render_panel(array $model,bool $reportReady=false,bool $embedded=false)
{
    extract($model,EXTR_SKIP);
?>
<p class="pf-explanation">Se muestran los pozos que están en falla en la última lectura. Las fallas acumuladas cuentan los eventos FALLA/FAULT registrados durante el día operativo; un pozo puede seguir en falla desde un día anterior.</p>
<div class="pf-kpis" aria-label="Pozos en falla por método de extracción">
<a class="pf-kpi <?php echo $method===''?'is-active':''; ?>" href="<?php echo h($url(['metodo'=>'','pagina'=>1])); ?>" <?php if($method==='')echo 'aria-current="true"'; ?>><span>Total de pozos en falla</span><strong><?php echo $hasTelemetry?count($base):'—'; ?></strong><small><?php echo $hasTelemetry?'Con los filtros de la grilla':'Sin lectura disponible'; ?></small></a>
<?php foreach(['BM','BES','PCP'] as $m): ?><a class="pf-kpi <?php echo $method===$m?'is-active':''; ?>" href="<?php echo h($url(['metodo'=>$method===$m?'':$m,'pagina'=>1])); ?>" <?php if($method===$m)echo 'aria-current="true"'; ?>><span><?php echo h($m); ?></span><strong><?php echo $hasTelemetry?$methodCounts[$m]:'—'; ?></strong><small>Pozos en falla · <?php echo $method===$m?'Quitar filtro':'Ver pozos'; ?></small></a><?php endforeach; ?>
</div>
<?php if($methodCounts['Otros']||$methodCounts['Sin identificar']): ?><div class="pf-other">También incluidos:
<?php foreach(['Otros','Sin identificar'] as $m):if(!$methodCounts[$m])continue; ?><a class="pf-method-link <?php echo $method===$m?'is-active':''; ?>" href="<?php echo h($url(['metodo'=>$method===$m?'':$m,'pagina'=>1])); ?>"><?php echo h($m); ?>: <b><?php echo $methodCounts[$m]; ?></b></a><?php endforeach; ?></div><?php endif; ?>
<form id="pfFilters" class="pf-toolbar pf-noprint" action="<?php echo h($formAction); ?>" method="get">
<label class="pf-search">Buscar <input type="search" name="q" value="<?php echo h($filters['q']); ?>" placeholder="Pozo, batería o detalle de falla…"></label>
<label>Filas <select name="cantidad_pagina"><option value="50" <?php if($size==='50')echo 'selected'; ?>>50</option><option value="100" <?php if($size==='100')echo 'selected'; ?>>100</option><option value="todas" <?php if($size==='todas')echo 'selected'; ?>>Todas</option></select></label>
<input type="hidden" name="orden" value="<?php echo h($sort); ?>"><input type="hidden" name="sentido" value="<?php echo h($direction); ?>">
<button class="pf-button primary" type="submit">Aplicar filtros</button><a class="pf-button clear" href="<?php echo h($clearUrl); ?>">Limpiar filtros</a>
<a class="pf-button" href="<?php echo h($url(['exportar'=>'csv'])); ?>">Exportar Excel (CSV)</a><a class="pf-button" href="<?php echo h($url(['imprimir'=>'1'])); ?>" target="_blank" rel="noopener">Imprimir / PDF</a>
</form>
<?php if($reportReady&&!$embedded): ?><div class="dashboardReportHead pf-report-tools pf-noprint"><div class="dashboardReportTools"><label class="dashboardReportToggle"><input type="checkbox" data-dashboard-report-toggle><span data-dashboard-report-label>Incluir resumen completo</span></label><button type="button" class="nsButton is-secondary" data-ns-report-open>Ver reporte <span data-ns-report-count class="nsReportCount" hidden>0</span></button></div><small class="pf-report-note">Incluye los <?php echo $total; ?> pozos filtrados de todas las páginas.</small><small class="dashboardReportStatus" data-dashboard-report-status></small></div><?php elseif($reportReady): ?><p class="pf-report-note pf-noprint">Al incluir el resumen completo del dashboard, se agregan los <?php echo $total; ?> pozos filtrados de todas las páginas.</p><?php endif; ?>
<div class="pf-grid-panel"><div class="pf-grid-summary"><b><?php echo $total; ?> pozos en falla<?php echo $method!==''?' · '.h($method):''; ?></b><span>Última lectura: <?php echo $last?date('d/m/Y H:i',$last):'Sin fecha'; ?></span></div>
<div class="pf-table-wrap"><table id="pozosFallaGrid" class="grid pf-table" data-grid-key="<?php echo h($gridKey); ?>" aria-label="Reporte de pozos en falla"><thead><tr>
<?php foreach($columns as $key=>$label): ?><th data-column-key="<?php echo h($key); ?>" data-column-label="<?php echo h($label); ?>" <?php if($sort===$key)echo 'aria-sort="'.($direction==='asc'?'ascending':'descending').'"'; ?>><a href="<?php echo h($url(['orden'=>$key,'sentido'=>$sort===$key&&$direction==='asc'?'desc':'asc','pagina'=>1])); ?>"><?php echo h($label); ?><?php if($sort===$key)echo $direction==='asc'?' ▲':' ▼'; ?></a></th><?php endforeach; ?>
</tr><tr class="pf-filter-row pf-noprint">
<th data-sortable="false"><input form="pfFilters" name="zona" aria-label="Filtrar zona" value="<?php echo h($filters['zona']); ?>" placeholder="Filtrar zona"></th>
<th data-sortable="false"><input form="pfFilters" name="instalacion" aria-label="Filtrar instalación o batería" value="<?php echo h($filters['instalacion']); ?>" placeholder="Pozo o batería"></th>
<th data-sortable="false"><input form="pfFilters" name="supervisor" aria-label="Filtrar supervisor" value="<?php echo h($filters['supervisor']); ?>" placeholder="Filtrar supervisor"></th>
<th data-sortable="false"><input form="pfFilters" name="detalle" aria-label="Filtrar detalle de falla" value="<?php echo h($filters['detalle']); ?>" placeholder="Filtrar detalle"></th>
<th data-sortable="false"><input form="pfFilters" name="cantidad" type="number" min="0" step="1" aria-label="Filtrar cantidad exacta de fallas" value="<?php echo h($filters['cantidad']); ?>" placeholder="Cantidad exacta"></th>
<th data-sortable="false"><select form="pfFilters" name="metodo" aria-label="Filtrar método de extracción"><option value="">Todos</option><?php foreach(['BM','BES','PCP','Otros','Sin identificar'] as $m): ?><option value="<?php echo h($m); ?>" <?php if($method===$m)echo 'selected'; ?>><?php echo h($m); ?></option><?php endforeach; ?></select></th>
</tr></thead><tbody>
<?php foreach($pageRows as $row): ?><tr data-pozo="<?php echo h($row['pozo']); ?>"><td><?php echo h($row['zona']); ?></td><td><strong><?php echo h($row['pozo']); ?></strong><?php if(!empty($row['bateria'])): ?><small>Batería: <?php echo h($row['bateria']); ?></small><?php endif; ?></td><td><?php echo h($row['supervisor']); ?></td><td class="pf-detail"><?php echo h($row['detalle_falla']); ?></td><td class="pf-count"><?php echo $row['fallas_dia']===null?'<span class="pf-unknown">Sin dato</span>':number_format($row['fallas_dia'],0,',','.'); ?></td><td><span class="pf-method"><?php echo h($row['metodo']); ?></span></td></tr><?php endforeach; ?>
<?php if(!$pageRows): ?><tr><td colspan="6" class="pf-empty"><?php echo !$hasTelemetry?'No hay una lectura de pozos disponible para evaluar fallas.':($faults?'No hay pozos en falla que coincidan con los filtros.':'No se detectaron pozos en falla en la lectura disponible.'); ?></td></tr><?php endif; ?>
</tbody></table></div>
<div class="pf-pagination pf-noprint"><span><?php echo $total?($offset+1).'–'.min($total,$offset+$pageSize):'0'; ?> de <?php echo $total; ?> pozos</span><div><?php if($page>1): ?><a class="pf-button" href="<?php echo h($url(['pagina'=>$page-1])); ?>">Anterior</a><?php endif; ?><span>Página <?php echo $page; ?> de <?php echo $pages; ?></span><?php if($page<$pages): ?><a class="pf-button" href="<?php echo h($url(['pagina'=>$page+1])); ?>">Siguiente</a><?php endif; ?></div></div>
</div>
<p class="pf-footnote">Cada pozo se cuenta una sola vez. «Sin dato» indica que no está disponible el conteo de eventos para el día operativo; no equivale a cero fallas. El método de extracción se obtiene del dato del pozo y puede quedar sin identificar.</p>
<?php
}
