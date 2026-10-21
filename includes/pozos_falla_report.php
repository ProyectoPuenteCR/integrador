<?php
/* Reporte reutilizable de fallas. Trabaja sobre la lectura del dashboard; no consulta SQL. */

function pf_input(array $query,$name,$default=''){
    $value=$query[$name]??$default;
    return is_scalar($value)?trim(substr((string)$value,0,300)):(string)$default;
}
function pf_normalize($value){
    return strtr(strtolower(trim((string)$value)),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n']);
}
function pf_contains($value,$needle){return $needle===''||strpos(pf_normalize($value),pf_normalize($needle))!==false;}
function pf_method($value){
    $value=trim((string)$value);
    if(in_array($value,['BM','BES','PCP'],true))return $value;
    if($value===''||preg_match('/sin (identificar|dato|clasificar)/i',$value))return 'Sin identificar';
    return 'Otros';
}
function pf_csv_value($value){
    $value=(string)$value;
    // Evitar fórmulas al abrir un CSV con textos de SCADA en Excel.
    return preg_match('/^[\s\x00-\x1f]*[=+\-@]/u',$value)||preg_match('/^[\t\r\n]/',$value)?"'".$value:$value;
}
function pf_columns(array $row){
    return [
        'Zona'=>(string)($row['zona']??'Sin identificar'),
        'Instalación'=>(string)$row['pozo'],
        'Supervisor'=>(string)($row['supervisor']??'Sin asignar'),
        'Detalle de falla'=>(string)($row['detalle_falla']??'En falla'),
        'Cant. de fallas acumuladas durante el día operativo'=>$row['fallas_dia']===null?'Sin dato':(int)$row['fallas_dia'],
        'Método de extracción'=>(string)($row['metodo']??'Sin identificar'),
    ];
}

function pf_build_report(array $data,array $query=[],bool $embedded=false)
{
    $filters=['q'=>pf_input($query,'q'),'zona'=>pf_input($query,'zona'),'instalacion'=>pf_input($query,'instalacion'),'supervisor'=>pf_input($query,'supervisor'),'detalle'=>pf_input($query,'detalle'),'cantidad'=>pf_input($query,'cantidad')];
    $method=pf_input($query,'metodo');
    if(!in_array($method,['','BM','BES','PCP','Otros','Sin identificar'],true))$method='';
    $sort=pf_input($query,'orden','fallas_dia');
    if(!in_array($sort,['zona','pozo','supervisor','detalle_falla','fallas_dia','metodo'],true))$sort='fallas_dia';
    $direction=pf_input($query,'sentido','desc')==='asc'?'asc':'desc';
    $size=pf_input($query,'cantidad_pagina','50');
    if(!in_array($size,['50','100','todas'],true))$size='50';
    $print=!$embedded&&pf_input($query,'imprimir')==='1';
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
        if(!pf_contains(implode(' ',pf_columns($row)).' '.($row['bateria']??''),$filters['q']))continue;
        if(!pf_contains($row['zona'],$filters['zona'])||!pf_contains($row['pozo'].' '.($row['bateria']??''),$filters['instalacion'])||!pf_contains($row['supervisor'],$filters['supervisor'])||!pf_contains($row['detalle_falla'],$filters['detalle']))continue;
        if($filters['cantidad']!==''&&($row['fallas_dia']===null||!preg_match('/^[0-9]+$/D',$filters['cantidad'])||$row['fallas_dia']!==(int)$filters['cantidad']))continue;
        $methodCounts[pf_method($row['metodo'])]++;
        $base[]=$row;
    }
    $filtered=array_values(array_filter($base,static function($row)use($method){return $method===''||pf_method($row['metodo'])===$method;}));
    usort($filtered,static function($a,$b)use($sort,$direction){
        if($sort==='fallas_dia'){
            // Los conteos desconocidos siempre quedan al final.
            if($a[$sort]===null&&$b[$sort]!==null)return 1;
            if($a[$sort]!==null&&$b[$sort]===null)return -1;
            $cmp=($a[$sort]??0)<=>($b[$sort]??0);
        }else{$cmp=strnatcmp(pf_normalize($a[$sort]??''),pf_normalize($b[$sort]??''));}
        if($cmp!==0)return $direction==='asc'?$cmp:-$cmp;
        return strnatcmp(pf_normalize($a['pozo']),pf_normalize($b['pozo']));
    });
    $total=count($filtered);
    $pageSize=$size==='todas'||$print?max(1,$total):(int)$size;
    $pages=max(1,(int)ceil($total/$pageSize));
    $page=max(1,min($pages,(int)pf_input($query,'pagina','1')));
    if($print)$page=1;
    $offset=($page-1)*$pageSize;
    $pageRows=array_slice($filtered,$offset,$pageSize);

    $columns=['zona'=>'Zona','pozo'=>'Instalación','supervisor'=>'Supervisor','detalle_falla'=>'Detalle de falla','fallas_dia'=>'Cant. de fallas acumuladas durante el día operativo','metodo'=>'Método de extracción'];
    return compact('filters','method','sort','direction','size','print','params','url','formAction','clearUrl','gridKey','dayStart','dayEnd','dayKey','dayLabel','faults','hasTelemetry','base','methodCounts','filtered','total','pageSize','pages','page','offset','pageRows','last','columns');
}

function pf_report_items(array $model)
{
    extract($model,EXTR_SKIP);
    $reportItems=[];
    if(!$hasTelemetry)return $reportItems;
    $counts=['BM'=>0,'BES'=>0,'PCP'=>0,'Otros'=>0,'Sin identificar'=>0];
    foreach($filtered as $row)$counts[pf_method($row['metodo'])]++;
    $summary=ns_report_row_payload(array_merge(['Día operativo'=>$dayLabel,'Pozos en falla'=>$total],$counts));
    $summary['group']='pozos_falla_resumen_'.$dayKey;
    $summary['group_title']='Pozos en falla · Resumen';
    $reportItems[]=['key'=>ns_report_key('pozos-falla-resumen',[$dayKey,$params]),'type'=>'row','title'=>'Pozos en falla · Resumen del '.$dayStart->format('d/m/Y'),'payload'=>$summary];
    foreach($filtered as $row){
        $payload=ns_report_row_payload(pf_columns($row));
        $payload['group']='pozos_falla_'.$dayKey;
        $payload['group_title']='Pozos en falla · Día operativo '.$dayLabel;
        $reportItems[]=['key'=>ns_report_key('pozos-falla',[$dayKey,(string)$row['pozo_clave']]),'type'=>'row','title'=>'Pozos en falla · '.$row['pozo'],'payload'=>$payload];
    }
    return $reportItems;
}

function pf_render_styles()
{
    // Servir los estilos con la página también funciona si IIS no publica el CSS por HTTP.
    echo '<style id="pozos-falla-styles">';
    readfile(__DIR__.'/../assets/css/pozos_falla.css');
    echo '</style>';
}

function pf_render_panel(array $model,bool $reportReady=false,bool $embedded=false)
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
