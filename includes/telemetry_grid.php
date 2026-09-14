<?php
if (!isset($TELEMETRY) || !is_array($TELEMETRY)) { http_response_code(500); exit('Configuración de telemetría ausente.'); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/zones.php';
require_once __DIR__ . '/production_q164.php';
require_once __DIR__ . '/alarm_actions.php';
require_once __DIR__ . '/novedades_semanales_common.php';
auth_require();
permissions_require_menu($TELEMETRY['key']);
$cfg = require dirname(__DIR__) . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = $TELEMETRY['key'];
$db = clear_db();
$reportEnabled=$db->ok()&&ns_report_ready($db)&&permissions_can_menu('novedades_semanales_reporte');
$zoneOptions=clear_zones_all($db);
$selectedZone=clear_zone_valid((string)($_GET['zona']??''),$zoneOptions);
$productionQ164=!empty($TELEMETRY['production_q164']);
$productionColumns=['PRODUCCION_PETROLEO','PRODUCCION_LIQUIDO','PRODUCCION_GAS'];
$remoteStopEnabled=!empty($TELEMETRY['remote_stop']);
$remoteStopColumn='AF-ESTADO-PARO-REMOTO';
$remoteStopAllowedColumns=[$remoteStopColumn,'AF-TIPO-DESC','AF-ESTADO-DESC','LINEA_ELECTRICA'];
$remoteStopExtraColumns=array_values(array_intersect(
  array_map('strval',$TELEMETRY['remote_stop_extra_columns'] ?? []),
  $remoteStopAllowedColumns
));
$remoteStopValueColumns=array_values(array_unique(array_merge([$remoteStopColumn],$remoteStopExtraColumns)));
$persistFiltersEnabled=!empty($TELEMETRY['persist_filters']);
$zafiroEnabled=!array_key_exists('zafiro_telemetry',$TELEMETRY)||!empty($TELEMETRY['zafiro_telemetry']);
$zafiroView='VW_CLEAR_ZAFIRO_TELEMETRIA';
$zafiroStateColumn='Estado Zafiro';
$zafiroMethodColumn='Método Zafiro';
$zafiroColumns=[$zafiroStateColumn,$zafiroMethodColumn];

function tg_q($v){ return '[' . str_replace(']', ']]', $v) . ']'; }
function tg_text($v){ if($v===null)return ''; if($v instanceof DateTimeInterface)return $v->format('d/m/Y H:i:s'); return trim((string)$v); }
function tg_link($v){ return preg_match('~^https?://~i',trim((string)$v))===1; }
function tg_number($v){ if($v===null||$v==='')return ''; return is_numeric($v)?number_format((float)$v,2,',','.') : tg_text($v); }
function tg_status_class($v){ $u=strtoupper(trim((string)$v)); if($u==='')return 'neutral'; if(preg_match('/FALL|ERROR|ALAR|PARAD|STOP|OFF|TRIP|NO DATA|BAD|SIN COM|DEFECT/', $u))return 'danger'; if(preg_match('/WARN|DEMOR|INACT|MANUAL|ESPERA|HOA/', $u))return 'warning'; if(preg_match('/OK|NORMAL|RUN|MARCHA|AUTO|ACTIV|HABIL|HOST|OPER/', $u))return 'success'; return 'info'; }
function tg_comm_label($v){ $s=tg_text($v); if($s==='')return 'Sin dato'; $u=strtoupper($s); if(preg_match('/FALL|ERROR|BAD|SIN|NO DATA|OFF|0/', $u))return 'Sin comunicación'; if(preg_match('/OK|NORMAL|GOOD|COMUNIC|ON|1/', $u))return 'Comunicando'; return $s; }
function tg_comm_from_date($v){ if($v===null||tg_text($v)==='')return 'Sin dato'; try{$d=$v instanceof DateTimeInterface?$v:new DateTime((string)$v);}catch(Throwable $e){return 'Sin dato';}$m=max(0,(time()-$d->getTimestamp())/60);if($m<=10)return 'Comunicando';if($m<=30)return 'Demorada';return 'Sin comunicación'; }
function tg_alarm_class($count){ $count=(int)$count; if($count<=0)return 'success'; if($count<=5)return 'warning'; if($count<=15)return 'info'; return 'danger'; }
function tg_remote_stop_label($value){
  $text=tg_text($value);
  if($text==='')return 'Sin dato';
  $upper=strtoupper($text);
  if($upper==='HABILITADO')return 'Habilitado';
  if($upper==='DESHABILITADO')return 'Deshabilitado';
  return $text;
}
function tg_remote_stop_class($value){
  $label=tg_remote_stop_label($value);
  if($label==='Habilitado')return 'danger';
  if($label==='Deshabilitado')return 'success';
  return 'neutral';
}
function tg_clean_error($value){
  $text=html_entity_decode((string)$value,ENT_QUOTES|ENT_HTML5,'UTF-8');
  $text=preg_replace('~<br\s*/?>~i',' · ',$text);
  $text=strip_tags($text);
  $text=preg_replace('/\s+/u',' ',$text);
  return trim($text);
}

function tg_zafiro_column_key($value){
  $text=strtr(trim((string)$value),[
    'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
    'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ü'=>'U','ñ'=>'N'
  ]);
  $text=strtoupper($text);
  return (string)preg_replace('/[^A-Z0-9]/','',$text);
}
function tg_zafiro_well_keys($value){
  $text=strtoupper(trim((string)$value));
  if($text==='')return [];
  $text=(string)preg_replace('/\s+/u','',$text);
  if($text==='')return [];
  $keys=[$text];
  $withoutPrefix=(string)preg_replace('/^YPF[._-]?SC[._-]?/i','',$text);
  if($withoutPrefix!==''&&$withoutPrefix!==$text)$keys[]=$withoutPrefix;
  return array_values(array_unique($keys));
}

$requestedColumns=$TELEMETRY['columns'];
$virtualColumns=array_values(array_unique(array_filter(array_map('strval',$TELEMETRY['virtual_columns'] ?? []),static function($value){return trim($value)!=='';})));
$baseRequestedColumns=array_values(array_filter($requestedColumns,static function($column)use($virtualColumns){return !in_array((string)$column,$virtualColumns,true);}));
$table=(string)($TELEMETRY['table'] ?? '');
$sourceSql=trim((string)($TELEMETRY['source_sql'] ?? ''));
$sourceSql=rtrim($sourceSql,"; \t\n\r\0\x0B");
$sourceColumns=$TELEMETRY['source_columns'] ?? [];
$availableColumns=[];
$availableColumnsUpper=[];

if($sourceSql!==''){
  /*
   * Fuente especial de pantalla. Permite consultar directamente un linked
   * server sin alterar vistas ni procedimientos persistentes de SQL Server.
   */
  foreach($sourceColumns as $sourceColumn){
    $n=trim((string)$sourceColumn);
    if($n==='')continue;
    $availableColumns[$n]=true;
    $availableColumnsUpper[strtoupper($n)]=$n;
  }
}else if($db->ok()){
  /*
   * sys.columns es más confiable que INFORMATION_SCHEMA para vistas RTQP con
   * nombres industriales que contienen dos puntos, guiones o puntos.
   */
  $meta=$db->all("SELECT c.name AS COLUMN_NAME FROM sys.columns c INNER JOIN sys.objects o ON o.object_id=c.object_id INNER JOIN sys.schemas s ON s.schema_id=o.schema_id WHERE s.name='dbo' AND o.name=? ORDER BY c.column_id",[$table]);
  foreach($meta as $m){
    $n=trim((string)($m['COLUMN_NAME']??''));
    if($n==='')continue;
    $availableColumns[$n]=true;
    $availableColumnsUpper[strtoupper($n)]=$n;
  }
  /* Registrar también el nombre configurado para tolerar diferencias de mayúsculas/minúsculas. */
  foreach($baseRequestedColumns as $requested){
    $requested=trim((string)$requested);
    if($requested!==''&&isset($availableColumnsUpper[strtoupper($requested)]))$availableColumns[$requested]=true;
  }
}
// Solo se consultan campos realmente existentes. Los accesos opcionales aparecen automáticamente.
$columns=array_values(array_filter($baseRequestedColumns,static function($c)use($availableColumns,$availableColumnsUpper){return isset($availableColumns[$c])||isset($availableColumnsUpper[strtoupper(trim((string)$c))]);}));
/* Columnas confirmadas por configuración: se fuerzan cuando la vista RTQP las expone pero el metadato no las informa correctamente. */
foreach(($TELEMETRY['force_columns'] ?? []) as $forcedColumn){
  $forcedColumn=trim((string)$forcedColumn);
  if($forcedColumn!==''&&in_array($forcedColumn,$baseRequestedColumns,true)&&!in_array($forcedColumn,$columns,true)){
    $columns[]=$forcedColumn;
    $availableColumns[$forcedColumn]=true;
  }
}
foreach(['PANTALLA','CARTAS'] as $optional){if(isset($availableColumns[$optional])&&!in_array($optional,$columns,true))$columns[]=$optional;}
$table=$TELEMETRY['table'];
$order=$TELEMETRY['order'] ?? ($columns[0] ?? '');
if($order!==''&&!isset($availableColumns[$order]))$order=$columns[0]??'';
$rows=[];$error='';$queryMs=0;
$serverSelectFilters=$TELEMETRY['server_select_filters'] ?? [];
$activeServerFilters=[];
$whereParts=[];
$whereParams=[];
foreach($serverSelectFilters as $filterColumn=>$filterConfig){
  if(!isset($availableColumns[$filterColumn]) || !is_array($filterConfig))continue;
  $param=(string)($filterConfig['param'] ?? strtolower($filterColumn));
  $allowed=array_values(array_map('strtoupper',$filterConfig['values'] ?? []));
  $default=strtoupper((string)($filterConfig['default'] ?? 'TODOS'));
  $selected=strtoupper(trim((string)($_GET[$param] ?? $default)));
  if($selected==='' || $selected==='TODOS' || !in_array($selected,$allowed,true))$selected='TODOS';
  $activeServerFilters[$filterColumn]=['param'=>$param,'selected'=>$selected,'label'=>(string)($filterConfig['label'] ?? $filterColumn),'values'=>$allowed];
  if($selected!=='TODOS'){
    $whereParts[]='UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100),'.tg_q($filterColumn).')))) = ?';
    $whereParams[]=$selected;
  }
}
if($selectedZone!=='' && isset($availableColumns[$TELEMETRY['battery_column'] ?? 'BATERIA'])){
  /*
   * El combo BATERIA se genera desde la columna real de cada vista (BM_RTQP,
   * PCP_RTQP, BES_RTQP, TECSS_RTQP o la tabla cache). Por eso el filtro Zona
   * debe usar exactamente los valores BATERIA_POZOS del catálogo y compararlos
   * contra esa misma columna, sin intentar derivar la batería desde el POZO.
   */
  $zoneBatteries=clear_zone_batteries($db,$selectedZone,'pozos');
  $zoneBatteries=array_values(array_unique(array_filter(array_map('clear_zone_compact_value',$zoneBatteries))));

  if($zoneBatteries){
    $batteryFilterCol=tg_q($TELEMETRY['battery_column'] ?? 'BATERIA');
    $leftZoneExpr=clear_zone_normalized_battery_expr($batteryFilterCol);
    $placeholders=implode(',',array_fill(0,count($zoneBatteries),'?'));
    $whereParts[]="$leftZoneExpr COLLATE DATABASE_DEFAULT IN ($placeholders)";
    foreach($zoneBatteries as $zoneBattery)$whereParams[]=$zoneBattery;
  }else{
    /* Zona válida sin baterías de pozos configuradas: no devuelve datos. */
    $whereParts[]='1=0';
  }
}
if($db->ok()&&$columns){
  $select=implode(',',array_map('tg_q',$columns));
  $whereSql=$whereParts ? (' WHERE '.implode(' AND ',$whereParts)) : '';
  $fromSql=$sourceSql!==''?'('.$sourceSql.') AS [CLEAR_SOURCE]':'[dbo].'.tg_q($table);
  $sql='SELECT TOP (1000) '.$select.' FROM '.$fromSql.$whereSql.($order?' ORDER BY '.tg_q($order):'');
  $t=microtime(true); $rows=$db->all($sql,$whereParams); $queryMs=round((microtime(true)-$t)*1000,1);
  if(!$rows&&$db->error())$error=tg_clean_error($db->error());
}elseif(!$columns){$error='La fuente no contiene ninguna de las columnas configuradas.';}else{$error=tg_clean_error($db->error());}

/*
 * Zafiro: enriquecimiento en memoria por POZO. Se evita un JOIN directo para
 * que filas repetidas en dbo.VW_CLEAR_ZAFIRO_TELEMETRIA nunca multipliquen la
 * telemetría original. Si el origen repite un pozo, se conserva un único mapa
 * y se completan solamente los valores no vacíos.
 */
$zafiroMap=[];
if($zafiroEnabled){
  foreach($zafiroColumns as $zafiroColumn){
    if(!in_array($zafiroColumn,$columns,true))$columns[]=$zafiroColumn;
  }

  if($db->ok()){
    $zafiroObjectName='dbo.'.$zafiroView;
    $zafiroMetadata=$db->all(
      "SELECT c.name AS COLUMN_NAME
       FROM sys.columns c
       WHERE c.object_id=OBJECT_ID(?)
       ORDER BY c.column_id",
      [$zafiroObjectName]
    );
    $zafiroSourceWell='';
    $zafiroSourceState='';
    $zafiroSourceMethod='';

    foreach($zafiroMetadata as $zafiroMeta){
      $zafiroSourceName=trim((string)($zafiroMeta['COLUMN_NAME']??''));
      $zafiroSourceKey=tg_zafiro_column_key($zafiroSourceName);
      if($zafiroSourceWell===''&&in_array($zafiroSourceKey,['POZO','WELL','WELLNAME'],true)){
        $zafiroSourceWell=$zafiroSourceName;
      }
      if($zafiroSourceState===''&&(
        strpos($zafiroSourceKey,'ESTADOZAFIRO')!==false
        || strpos($zafiroSourceKey,'ZAFIROESTADO')!==false
        || strpos($zafiroSourceKey,'CAMBIODEESTADOESTADO')!==false
      )){
        $zafiroSourceState=$zafiroSourceName;
      }
      if($zafiroSourceMethod===''&&(
        strpos($zafiroSourceKey,'METODOZAFIRO')!==false
        || strpos($zafiroSourceKey,'ZAFIROMETODO')!==false
        || strpos($zafiroSourceKey,'SISTEMADEEXTRACCION')!==false
      )){
        $zafiroSourceMethod=$zafiroSourceName;
      }
    }

    if($zafiroSourceWell!==''&&$zafiroSourceState!==''&&$zafiroSourceMethod!==''){
      $zafiroSql='SELECT '
        .tg_q($zafiroSourceWell).' AS [__ZAFIRO_POZO],'
        .tg_q($zafiroSourceState).' AS [__ZAFIRO_ESTADO],'
        .tg_q($zafiroSourceMethod).' AS [__ZAFIRO_METODO]'
        .' FROM [dbo].'.tg_q($zafiroView)
        .' WHERE '.tg_q($zafiroSourceWell).' IS NOT NULL';
      $zafiroRows=$db->all($zafiroSql);

      foreach($zafiroRows as $zafiroRow){
        $zafiroData=[
          $zafiroStateColumn=>tg_text($zafiroRow['__ZAFIRO_ESTADO']??''),
          $zafiroMethodColumn=>tg_text($zafiroRow['__ZAFIRO_METODO']??'')
        ];
        foreach(tg_zafiro_well_keys($zafiroRow['__ZAFIRO_POZO']??'') as $zafiroKey){
          if(!isset($zafiroMap[$zafiroKey])){
            $zafiroMap[$zafiroKey]=$zafiroData;
            continue;
          }
          foreach($zafiroColumns as $zafiroColumn){
            if(($zafiroMap[$zafiroKey][$zafiroColumn]??'')===''&&($zafiroData[$zafiroColumn]??'')!==''){
              $zafiroMap[$zafiroKey][$zafiroColumn]=$zafiroData[$zafiroColumn];
            }
          }
        }
      }
    }
  }

  foreach($rows as &$zafiroTelemetryRow){
    $zafiroTelemetryData=null;
    $zafiroTelemetryWell=$zafiroTelemetryRow[$TELEMETRY['well_column'] ?? 'POZO']??'';
    foreach(tg_zafiro_well_keys($zafiroTelemetryWell) as $zafiroTelemetryKey){
      if(isset($zafiroMap[$zafiroTelemetryKey])){
        $zafiroTelemetryData=$zafiroMap[$zafiroTelemetryKey];
        break;
      }
    }
    foreach($zafiroColumns as $zafiroColumn){
      $zafiroTelemetryRow[$zafiroColumn]=tg_text($zafiroTelemetryData[$zafiroColumn]??'');
      if($zafiroTelemetryRow[$zafiroColumn]==='')$zafiroTelemetryRow[$zafiroColumn]='Sin dato';
    }
  }
  unset($zafiroTelemetryRow);
}

/*
 * Columnas virtuales de presentación. No forman parte de la consulta principal
 * y por eso nunca pueden romper la carga de la grilla. Se completan mediante
 * consultas auxiliares y se enlazan por una clave (normalmente POZO).
 */
foreach($virtualColumns as $virtualColumn){
  if(!in_array($virtualColumn,$columns,true))$columns[]=$virtualColumn;
}
$supplementalQueries=$TELEMETRY['supplemental_queries'] ?? [];
if($db->ok() && $rows && is_array($supplementalQueries)){
  foreach($supplementalQueries as $supplementalQuery){
    if(!is_array($supplementalQuery))continue;
    $supplementalKeyColumn=trim((string)($supplementalQuery['key_column'] ?? ''));
    $supplementalValueColumns=array_values(array_filter(array_map('strval',$supplementalQuery['value_columns'] ?? []),static function($value){return trim($value)!=='';}));
    $supplementalSql=trim((string)($supplementalQuery['sql'] ?? ''));
    if($supplementalKeyColumn==='' || !$supplementalValueColumns || $supplementalSql==='')continue;

    /*
     * Si una consulta auxiliar falla, la telemetría principal sigue visible.
     * Esto es intencional: PANTALLA/CARTAS son accesos opcionales.
     */
    $supplementalRows=$db->all($supplementalSql);
    if(!$supplementalRows)continue;

    $supplementalMap=[];
    foreach($supplementalRows as $supplementalRow){
      $supplementalKey=strtoupper(trim((string)($supplementalRow[$supplementalKeyColumn] ?? '')));
      if($supplementalKey==='')continue;
      foreach($supplementalValueColumns as $supplementalValueColumn){
        $supplementalValue=$supplementalRow[$supplementalValueColumn] ?? null;
        if($supplementalValue!==null && trim((string)$supplementalValue)!==''){
          $supplementalMap[$supplementalKey][$supplementalValueColumn]=$supplementalValue;
        }
      }
    }

    if(!$supplementalMap)continue;
    foreach($rows as &$supplementedRow){
      $rowKey=strtoupper(trim((string)($supplementedRow[$supplementalKeyColumn] ?? '')));
      if($rowKey==='' || !isset($supplementalMap[$rowKey]))continue;
      foreach($supplementalValueColumns as $supplementalValueColumn){
        if(isset($supplementalMap[$rowKey][$supplementalValueColumn])){
          $supplementedRow[$supplementalValueColumn]=$supplementalMap[$rowKey][$supplementalValueColumn];
        }
      }
    }
    unset($supplementedRow);
  }
}

/*
 * Query 164 - Ultimo test aprobado por pozo. Son columnas sinteticas: no
 * pertenecen a las vistas RTQP, por eso se agregan despues de consultar RTQP.
 */
$productionMap=[];
if($productionQ164){
  /* Las columnas son sinteticas y deben verse aunque Query 164 no tenga coincidencia para un pozo. */
  foreach($productionColumns as $productionColumn){
    if(!in_array($productionColumn,$columns,true))$columns[]=$productionColumn;
  }

  if($db->ok()){
    $productionMap=clear_q164_latest_map($db);
    foreach($rows as &$productionRow){
      $productionWell=clear_q164_well_key($productionRow[$TELEMETRY['well_column'] ?? 'POZO'] ?? '');
      $productionData=$productionWell!==''?($productionMap[$productionWell]??null):null;
      foreach($productionColumns as $productionColumn){
        $productionRow[$productionColumn]=$productionData[$productionColumn]??null;
      }
    }
    unset($productionRow);
  }
}

/*
 * Paro remoto: dato local actualizado dos veces por día desde el linked
 * server. La consulta es pequeña, usa el índice por AF-POZO y nunca obliga a
 * las vistas RTQP a incorporar joins nuevos.
 */
$remoteStopMap=[];
if($remoteStopEnabled){
  foreach($remoteStopValueColumns as $remoteStopValueColumn){
    if(!in_array($remoteStopValueColumn,$columns,true))$columns[]=$remoteStopValueColumn;
  }

  if($db->ok()){
    $remoteStopExists=$db->all("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_PARO_REMOTO',N'U') IS NULL THEN 0 ELSE 1 END AS EXISTE");
    if((int)($remoteStopExists[0]['EXISTE']??0)===1){
      $remoteStopSelect=implode(',',array_map('tg_q',array_merge(['AF-POZO'],$remoteStopValueColumns)));
      $remoteStopRows=$db->all('SELECT '.$remoteStopSelect.' FROM [dbo].[CLEAR_PARO_REMOTO] WHERE [AF-POZO] IS NOT NULL');
      foreach($remoteStopRows as $remoteStopRow){
        $remoteStopKey=strtoupper(trim((string)($remoteStopRow['AF-POZO']??'')));
        if($remoteStopKey==='')continue;
        $remoteStopValue=tg_remote_stop_label($remoteStopRow['AF-ESTADO-PARO-REMOTO']??'');
        $remoteStopData=[];
        foreach($remoteStopValueColumns as $remoteStopValueColumn){
          $remoteStopData[$remoteStopValueColumn]=$remoteStopValueColumn===$remoteStopColumn
            ?$remoteStopValue
            :tg_text($remoteStopRow[$remoteStopValueColumn]??'');
        }

        /* Si el origen repite un pozo, Habilitado tiene prioridad operativa. */
        $replaceRemoteStop=!isset($remoteStopMap[$remoteStopKey])
          || ($remoteStopValue==='Habilitado' && ($remoteStopMap[$remoteStopKey][$remoteStopColumn]??'')!=='Habilitado');
        if($replaceRemoteStop){
          $remoteStopMap[$remoteStopKey]=$remoteStopData;
        }else{
          foreach($remoteStopValueColumns as $remoteStopValueColumn){
            if(($remoteStopMap[$remoteStopKey][$remoteStopValueColumn]??'')==='' && ($remoteStopData[$remoteStopValueColumn]??'')!==''){
              $remoteStopMap[$remoteStopKey][$remoteStopValueColumn]=$remoteStopData[$remoteStopValueColumn];
            }
          }
        }
      }
    }
  }

  foreach($rows as &$remoteStopRow){
    $remoteStopWell=strtoupper(trim((string)($remoteStopRow[$TELEMETRY['well_column'] ?? 'POZO']??'')));
    foreach($remoteStopValueColumns as $remoteStopValueColumn){
      $remoteStopDefault=$remoteStopValueColumn===$remoteStopColumn?'Sin dato':'';
      $remoteStopRow[$remoteStopValueColumn]=$remoteStopWell!==''
        ?($remoteStopMap[$remoteStopWell][$remoteStopValueColumn]??$remoteStopDefault)
        :$remoteStopDefault;
    }
  }
  unset($remoteStopRow);
}

/*
 * Alarmas de 24 h:
 * - La grilla general ya trae ALM desde la caché.
 * - Las demás telemetrías reutilizan esa misma caché pequeña.
 * Nunca agrupar FIXALARMS desde una petición web: con varias pantallas y la
 * actualización automática esa consulta producía escaneos masivos repetidos.
 */
$alarmMap=[];
$precomputedAlarmColumn=(string)($TELEMETRY['alarm_column'] ?? '');
$usePrecomputedAlarms=$precomputedAlarmColumn!=='' && isset($availableColumns[$precomputedAlarmColumn]);
if(!$usePrecomputedAlarms && $db->ok()){
  $alarmCacheExists=(int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE',N'U') IS NULL THEN 0 ELSE 1 END");
  if($alarmCacheExists===1){
    foreach($db->all("SELECT POZO,ALM FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE WHERE POZO IS NOT NULL") as $alarmRow){
      $key=strtoupper(trim((string)($alarmRow['POZO']??'')));
      $value=(int)($alarmRow['ALM']??0);
      if($key!==''&&(!isset($alarmMap[$key])||$value>$alarmMap[$key]))$alarmMap[$key]=$value;
    }
  }
}

$batteryCounts=[];$stateCounts=[];$zafiroStateCounts=[];$zafiroMethodCounts=[];$generalCounts=[];$commCounts=[];$remoteStopCounts=[];$remoteStopWells=[];$wellCounts=[];$wellsWithAlarms=0;$totalAlarms24h=0;$commFailWells=[];$commWarnWells=[];
$wellCol=$TELEMETRY['well_column'] ?? 'POZO'; $batteryCol=$TELEMETRY['battery_column'] ?? 'BATERIA';
$stateCol=$TELEMETRY['state_column'] ?? 'ESTADO'; $generalCol=$TELEMETRY['general_column'] ?? '';
$commCol=$TELEMETRY['communication_column'] ?? ''; $commDateCol=$TELEMETRY['communication_date_column'] ?? '';
if($commCol!==''&&!isset($availableColumns[$commCol]))$commCol=''; if($commDateCol!==''&&!isset($availableColumns[$commDateCol]))$commDateCol='';
foreach($rows as &$r){
  $w=tg_text($r[$wellCol]??''); if($w!=='')$wellCounts[$w]=1;
  $alarmCount=$usePrecomputedAlarms?(int)($r[$precomputedAlarmColumn]??0):($w!==''?($alarmMap[strtoupper($w)]??0):0); $r['ALM']=(int)$alarmCount; if($alarmCount>0)$wellsWithAlarms++; $totalAlarms24h+=$alarmCount;
  $b=tg_text($r[$batteryCol]??''); if($b!=='')$batteryCounts[$b]=($batteryCounts[$b]??0)+1;
  $s=tg_text($r[$stateCol]??''); if($s!=='')$stateCounts[$s]=($stateCounts[$s]??0)+1;
  if($zafiroEnabled){
    $zs=tg_text($r[$zafiroStateColumn]??''); if($zs!=='')$zafiroStateCounts[$zs]=($zafiroStateCounts[$zs]??0)+1;
    $zm=tg_text($r[$zafiroMethodColumn]??''); if($zm!=='')$zafiroMethodCounts[$zm]=($zafiroMethodCounts[$zm]??0)+1;
  }
  if($generalCol!==''){ $g=tg_text($r[$generalCol]??''); if($g!=='')$generalCounts[$g]=($generalCounts[$g]??0)+1; }
  if($remoteStopEnabled){
    $remoteStopState=tg_remote_stop_label($r[$remoteStopColumn]??'');
    $r[$remoteStopColumn]=$remoteStopState;
    $remoteStopCounts[$remoteStopState]=($remoteStopCounts[$remoteStopState]??0)+1;
    if($w!==''&&$remoteStopState==='Habilitado')$remoteStopWells[strtoupper($w)]=1;
  }
  if($commCol!==''){ $c=tg_comm_label($r[$commCol]??''); $r['__COMUNICACION']=$c; $commCounts[$c]=($commCounts[$c]??0)+1; if($w!==''&&$c==='Sin comunicación')$commFailWells[$w]=1; if($w!==''&&$c==='Demorada')$commWarnWells[$w]=1; }
  elseif($commDateCol!==''){ $c=tg_comm_from_date($r[$commDateCol]??null); $r['__COMUNICACION']=$c; $commCounts[$c]=($commCounts[$c]??0)+1; if($w!==''&&$c==='Sin comunicación')$commFailWells[$w]=1; if($w!==''&&$c==='Demorada')$commWarnWells[$w]=1; }
}
unset($r); foreach([$batteryCounts,$stateCounts,$zafiroStateCounts,$zafiroMethodCounts,$generalCounts,$commCounts,$remoteStopCounts] as &$a)ksort($a,SORT_NATURAL|SORT_FLAG_CASE); unset($a);

/* Comentarios centralizados: una sola consulta para toda la página. De este
   modo la grilla, el reporte y Excel comparten el mismo valor sin consultas
   por fila y sin escanear FIXALARMS. */
$canViewTelemetryComments=permissions_can('comments.view');
$telemetryCommentMap=[];
if($canViewTelemetryComments&&$rows){
  $commentSubjects=[];
  foreach($rows as $commentSourceRow){
    $commentWell=tg_text($commentSourceRow[$wellCol]??'');
    if($commentWell!=='')$commentSubjects[]=clear_alarm_comment_subject($commentWell,'pozo');
  }
  foreach(array_chunk(array_values(array_unique($commentSubjects)),1000) as $commentSubjectChunk){
    foreach(clear_alarm_comments_load_subjects_sql($commentSubjectChunk) as $commentKey=>$commentRow)$telemetryCommentMap[$commentKey]=$commentRow;
  }
}
foreach($rows as &$commentRow){
  $commentWell=tg_text($commentRow[$wellCol]??'');
  $commentSubject=clear_alarm_comment_subject($commentWell,'pozo');
  $commentData=$commentSubject!==''?($telemetryCommentMap[strtoupper($commentSubject)]??[]):[];
  $commentText=trim((string)($commentData['COMENTARIO']??$commentData['comentario']??''));
  $commentRow['COMENTARIO']=$canViewTelemetryComments?$commentText:'';
  $commentRow['__COMENTARIO_ASUNTO']=$commentSubject;
  $commentRow['__TIENE_COMENTARIO']=$commentText!==''?1:0;
}
unset($commentRow);
if(!in_array('COMENTARIO',$columns,true))$columns[]='COMENTARIO';
// Estándar visual común: POZO, BATERIA, ALM, COM y ESTADO.
$standard=[];
foreach([$wellCol,$batteryCol] as $c){if($c!==''&&in_array($c,$columns,true)&&!in_array($c,$standard,true))$standard[]=$c;}
$standard[]='ALM';
if($commCol!==''||$commDateCol!=='')$standard[]='__COMUNICACION';
if($stateCol!==''&&in_array($stateCol,$columns,true))$standard[]=$stateCol;
if($productionQ164){
  foreach(['PRODUCCION_PETROLEO','PRODUCCION_LIQUIDO'] as $productionColumn){
    if(in_array($productionColumn,$columns,true)&&!in_array($productionColumn,$standard,true))$standard[]=$productionColumn;
  }
}
if($generalCol!==''&&in_array($generalCol,$columns,true)&&!in_array($generalCol,$standard,true))$standard[]=$generalCol;
foreach(['PANTALLA','CARTAS'] as $c){if(in_array($c,$columns,true))$standard[]=$c;}
$configuredDisplayOrder=$TELEMETRY['display_order'] ?? [];
if(is_array($configuredDisplayOrder) && $configuredDisplayOrder){
  $displayColumns=[];
  foreach($configuredDisplayOrder as $c){
    if($c==='ALM' || $c==='__COMUNICACION' || in_array($c,$columns,true)){
      if(!in_array($c,$displayColumns,true))$displayColumns[]=$c;
    }
  }
  foreach($columns as $c){if(!in_array($c,$displayColumns,true))$displayColumns[]=$c;}
}else{
  $displayColumns=$standard;
  foreach($columns as $c){if(!in_array($c,$displayColumns,true))$displayColumns[]=$c;}
}
if($commCol!=='')$displayColumns=array_values(array_filter($displayColumns,static function($c)use($commCol){return $c!==$commCol;}));
if($productionQ164){
  // La produccion de petroleo y liquido va inmediatamente a la derecha de ESTADO.
  // La produccion de gas se mantiene siempre como ultima columna inicial.
  $displayColumns=array_values(array_filter($displayColumns,static function($c)use($productionColumns){return !in_array($c,$productionColumns,true);}));
  $insertAt=array_search($stateCol,$displayColumns,true);
  if($insertAt===false)$insertAt=count($displayColumns)-1;
  array_splice($displayColumns,$insertAt+1,0,['PRODUCCION_PETROLEO','PRODUCCION_LIQUIDO']);
  $displayColumns[]='PRODUCCION_GAS';
}
if($remoteStopEnabled){
  /* Posición solicitada por pantalla: a la derecha de ESTADO o YT:POZO. */
  $displayColumns=array_values(array_filter($displayColumns,static function($column)use($remoteStopColumn){return $column!==$remoteStopColumn;}));
  $remoteStopAfter=(string)($TELEMETRY['remote_stop_after'] ?? $stateCol);
  $remoteStopAt=array_search($remoteStopAfter,$displayColumns,true);
  if($remoteStopAt===false)$displayColumns[]=$remoteStopColumn;
  else array_splice($displayColumns,$remoteStopAt+1,0,[$remoteStopColumn]);

  /* Las columnas descriptivas del paro remoto permanecen juntas. */
  if($remoteStopExtraColumns){
    $displayColumns=array_values(array_filter($displayColumns,static function($column)use($remoteStopExtraColumns){return !in_array($column,$remoteStopExtraColumns,true);}));
    $remoteStopAt=array_search($remoteStopColumn,$displayColumns,true);
    array_splice($displayColumns,$remoteStopAt===false?count($displayColumns):$remoteStopAt+1,0,$remoteStopExtraColumns);
  }
}
if($zafiroEnabled){
  /* Estado Zafiro y Método Zafiro quedan inmediatamente después del estado operativo. */
  $displayColumns=array_values(array_filter($displayColumns,static function($column)use($zafiroColumns){return !in_array($column,$zafiroColumns,true);}));
  $zafiroAt=array_search($stateCol,$displayColumns,true);
  array_splice($displayColumns,$zafiroAt===false?count($displayColumns):$zafiroAt+1,0,$zafiroColumns);
}
$heroImage='assets/img/telemetry/'.($TELEMETRY['hero_image'] ?? ($TELEMETRY['key'].'.png'));
$heroImageFs=dirname(__DIR__).'/'.$heroImage;
if(!is_file($heroImageFs))$heroImage='';
$nonNumeric=$TELEMETRY['non_numeric'] ?? [$wellCol,$batteryCol,$stateCol,$generalCol,$commCol,$commDateCol,'PANTALLA','CARTAS','Name','Description','Comment','PLANTILLA','TIPO','METODO','FEHA','Fecha','HOY','ALM'];
if($remoteStopEnabled&&!in_array($remoteStopColumn,$nonNumeric,true))$nonNumeric[]=$remoteStopColumn;
foreach($zafiroColumns as $zafiroColumn){if(!in_array($zafiroColumn,$nonNumeric,true))$nonNumeric[]=$zafiroColumn;}
if(!in_array('COMENTARIO',$nonNumeric,true))$nonNumeric[]='COMENTARIO';
$configuredSelectFilterColumns=array_values(array_unique(array_filter(array_map('strval',$TELEMETRY['select_filter_columns'] ?? []),static function($column){return trim($column)!=='';})));
$selectFilterColumns=array_values(array_unique(array_merge($zafiroEnabled?$zafiroColumns:[],$configuredSelectFilterColumns)));
$selectFilterValues=[];
foreach($selectFilterColumns as $selectFilterColumn){
  if(!in_array($selectFilterColumn,$displayColumns,true))continue;
  $selectFilterValues[$selectFilterColumn]=[];
  $values=[];
  foreach($rows as $selectFilterRow){
    $value=tg_text($selectFilterRow[$selectFilterColumn]??'');
    if($value!=='')$values[$value]=1;
  }
  if($values){
    ksort($values,SORT_NATURAL|SORT_FLAG_CASE);
    $selectFilterValues[$selectFilterColumn]=array_keys($values);
  }
}
$filterGroups=[];
if($commCounts)$filterGroups[]=['column'=>'__COMUNICACION','label'=>'Comunicación','values'=>$commCounts];
if($generalCounts)$filterGroups[]=['column'=>$generalCol,'label'=>'Estado general','values'=>$generalCounts];
if($stateCounts)$filterGroups[]=['column'=>$stateCol,'label'=>'Estado','values'=>$stateCounts];
if($zafiroStateCounts)$filterGroups[]=['column'=>$zafiroStateColumn,'label'=>$zafiroStateColumn,'values'=>$zafiroStateCounts];
if($zafiroMethodCounts)$filterGroups[]=['column'=>$zafiroMethodColumn,'label'=>$zafiroMethodColumn,'values'=>$zafiroMethodCounts];
if($batteryCounts)$filterGroups[]=['column'=>$batteryCol,'label'=>'Baterías','values'=>$batteryCounts];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h($TELEMETRY['title']); ?> · CLEAR</title><link rel="stylesheet" href="assets/css/app.css?v=20260902-column-menu-1"><link rel="stylesheet" href="assets/css/telemetry_modal.css?v=3.1.9"><link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260826-central-1"><link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260904-dashboard-full-1">
<style>
.tg{padding:0 20px 24px}
.tg-hero{display:flex;align-items:stretch;gap:8px;flex-wrap:wrap;margin:8px 0 10px}
.tg-hero__title{flex:0 0 390px;max-width:450px;min-width:310px;border:1px solid var(--line-mid);background:linear-gradient(180deg,var(--surface),var(--surface-2,#f8fafc));border-radius:16px;padding:10px 14px;box-shadow:0 8px 22px rgba(15,23,42,.05);display:flex;justify-content:space-between;gap:12px;align-items:center}
.tg-hero__eyebrow{font-size:10px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:var(--petrol);margin-bottom:4px}
.tg-hero__title h1{margin:0;font:800 18px/1.05 var(--font-head);letter-spacing:-.02em}
.tg-hero__title p{margin:3px 0 0;font-size:10px;line-height:1.2;color:var(--text-mut);text-transform:uppercase;letter-spacing:.12em}
.tg-live{margin-top:8px;display:inline-flex;align-items:center;gap:8px;align-self:flex-start;padding:5px 10px;border:1px solid #cfe5d9;border-radius:999px;background:#f6fbf8;color:#0b7d58;font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.tg-hero__copy{flex:1;min-width:0}.tg-hero__visual{flex:0 0 104px;display:flex;align-items:center;justify-content:center}.tg-hero__visual img{display:block;max-width:96px;max-height:88px;width:auto;height:auto;object-fit:contain;mix-blend-mode:multiply;filter:drop-shadow(0 2px 4px rgba(15,23,42,.10))}
.tg-live .dot{width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block}
.tg-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(128px,1fr));gap:6px;flex:1;min-width:min(100%,560px);margin:0}
.tg-card{border:1px solid var(--line-mid);background:linear-gradient(180deg,var(--surface),var(--surface-2,#f8fafc));border-radius:14px;padding:8px 10px;display:flex;align-items:center;gap:8px;min-height:42px;box-shadow:0 6px 18px rgba(15,23,42,.05);transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease;text-align:left}
.tg-card strong{display:block;font:800 24px/1 var(--font-head);letter-spacing:-.02em}
.tg-card span{display:block;font-size:10px;color:var(--text-mut);margin-top:2px;line-height:1.15}.tg-card__icon{width:24px;height:24px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:#e9f3f7;color:var(--petrol);flex:0 0 auto}.tg-card__icon svg{width:12px;height:12px}.tg-card__body{display:flex;flex-direction:column;min-width:0}.tg-card__body span,.tg-card__body small{max-width:100%}
.tg-card.total{background:linear-gradient(135deg,var(--petrol),#123f50);color:#fff;border-color:transparent}.tg-card.total span{color:#d8e9f0}.tg-card.total .tg-card__icon{background:rgba(255,255,255,.14);color:#fff}
.tg-card.alarms{border-color:#f4d6aa}.tg-card.alarms .tg-card__icon{background:#fff3df;color:#af6a12}
.tg-card.accum{border-color:#f2dcc2}.tg-card.accum .tg-card__icon{background:#fff0e2;color:#b5651d}
.tg-card.regs{border-color:#d9e1ea}.tg-card.regs .tg-card__icon{background:#eff5fa;color:#3f6b88}
.tg-card.comm-fail{border-color:#f3c3bf}.tg-card.comm-fail .tg-card__icon{background:#fde8e7;color:#b8322b}
.tg-card.comm-warn{border-color:#f7d8a4}.tg-card.comm-warn .tg-card__icon{background:#fff3de;color:#9e670e}
.tg-card.remote-stop{border-color:#e7b4b0}.tg-card.remote-stop .tg-card__icon{background:#fde5e3;color:#a92e28}
button.tg-card{cursor:pointer;width:100%}
button.tg-card:hover{transform:translateY(-1px);box-shadow:0 12px 24px rgba(15,23,42,.10)}
button.tg-card.is-active{outline:2px solid rgba(184,50,43,.18);border-color:#e3a5a0}
.tg-card small{display:inline-flex;align-self:flex-start;margin-top:3px;padding:1px 6px;border-radius:999px;background:rgba(184,50,43,.10);color:#9d3029;font-size:10px;font-weight:700}.tg-card.comm-warn small{background:rgba(166,107,11,.11);color:#94620d}
.tg-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:8px 0}.tg-panel{border:1px solid var(--line-mid);border-radius:12px;background:var(--surface);overflow:hidden;box-shadow:0 4px 14px rgba(15,23,42,.04)}.tg-panel summary{cursor:pointer;padding:10px 12px;font-weight:700;list-style:none}.tg-panel summary small{color:var(--text-mut);font-weight:600}.tg-panel summary:after{content:'▾';float:right}.tg-panel[open] summary:after{transform:rotate(180deg)}.tg-panel__body{border-top:1px solid var(--line);padding:9px;max-height:230px;overflow:auto}.tg-check{display:flex;gap:7px;align-items:center;padding:6px 5px;font-size:12px;border-radius:8px}.tg-check:hover{background:var(--surface-2,#f8fafc)}.tg-check b{margin-left:auto}.tg-actions{display:flex;gap:6px;margin-bottom:7px}.tg-actions button{border:1px solid var(--line-mid);background:var(--surface);border-radius:7px;padding:5px 8px;cursor:pointer}
.tg-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0}.tg-page-size{display:inline-flex;align-items:center;gap:6px;padding:5px 8px;border:1px solid var(--line-mid);border-radius:10px;background:var(--surface);font-size:10px;font-weight:800;color:var(--text-mut);text-transform:uppercase;letter-spacing:.05em}.tg-page-size select{border:0;background:transparent;color:var(--text);font-weight:700;outline:0;cursor:pointer}.tg-server-filter{display:flex;align-items:center;gap:7px;padding:5px 8px;border:1px solid var(--line-mid);border-radius:10px;background:var(--surface)}.tg-server-filter label{font-size:10px;font-weight:800;color:var(--text-mut);text-transform:uppercase;letter-spacing:.05em}.tg-server-filter select{min-width:125px;border:0;background:transparent;color:var(--text);font-weight:700;outline:0}.tg-search{min-width:250px;flex:1;max-width:520px;padding:8px 11px;border:1px solid var(--line-mid);border-radius:10px;background:var(--surface);color:var(--text)}.tg-btn,.tg-select{border:1px solid var(--line-mid);background:var(--surface);color:var(--text);padding:8px 11px;border-radius:10px;cursor:pointer}.tg-count{margin-left:auto;font-size:12px;color:var(--text-mut)}
.tg-columns{position:relative;z-index:210}.tg-columns-menu{display:none;position:absolute;right:0;top:42px;z-index:220;width:270px;max-height:420px;overflow:auto;padding:8px;background:var(--bg-card,#fff);color:var(--text);border:1px solid var(--line-mid);border-radius:12px;box-shadow:0 12px 30px rgba(0,0,0,.20);pointer-events:auto;isolation:isolate}.tg-columns-menu.show{display:block}.tg-col-item{display:flex;gap:8px;align-items:center;padding:6px;border-radius:8px;background:var(--bg-card,#fff)}.tg-col-item:hover{background:var(--petrol-soft)}.tg-col-item label{display:flex;gap:7px;align-items:center;flex:1;cursor:pointer}.tg-col-item input{width:16px;height:16px;accent-color:var(--petrol);cursor:pointer}.tg-col-item .drag{cursor:grab;color:var(--text-mut)}
.tg-wrap{border:1px solid var(--line-mid);border-radius:12px;overflow:auto;max-height:65vh;background:var(--surface);position:relative;-webkit-overflow-scrolling:touch}.tg-table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%;font-size:11px;transform:translateZ(0)}.tg-table th{position:sticky;top:0;z-index:3;background:var(--surface-2,#f7f9fb);padding:6px 7px;border-bottom:1px solid var(--line-mid);border-right:1px solid var(--line);white-space:nowrap;cursor:pointer;backface-visibility:hidden}.tg-table td{padding:5px 7px;height:29px;border-bottom:1px solid rgba(120,140,160,.32);border-right:1px solid var(--line);white-space:nowrap;transition:background-color .12s ease}.tg-page-hidden{display:none!important}
.tg-table tr:hover td{background:var(--petrol-soft)}.tg-table tr.tg-row-comm-fail td{background:rgba(253,232,231,.55)}.tg-table tr.tg-row-comm-warn td{background:rgba(255,243,222,.62)}.tg-table tr.tg-row-comm-fail:hover td{background:rgba(252,218,216,.82)}.tg-table tr.tg-row-comm-warn:hover td{background:rgba(255,236,201,.85)}.tg-table tr.tg-row-comm-fail td:first-child{box-shadow:inset 4px 0 0 #c53a31}.tg-table tr.tg-row-comm-warn td:first-child{box-shadow:inset 4px 0 0 #c98513}
.tg-filter{display:block;margin-top:4px;width:100%;min-width:80px;height:24px;padding:2px 5px;border:1px solid var(--line-mid);border-radius:6px;background:var(--surface);color:var(--text);font-size:10px}
.tg-badge{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;font-weight:800;font-size:10px;letter-spacing:.01em;box-shadow:inset 0 0 0 1px rgba(15,23,42,.05)}.tg-badge.success{background:#dff4ea;color:#14754f}.tg-badge.danger{background:#fde5e3;color:#aa302b}.tg-badge.warning{background:#fff0d5;color:#9a6410}.tg-badge.info{background:#e5f0fb;color:#286b9e}.tg-badge.neutral{background:#edf0f3;color:#617080}
.tg-link{display:inline-flex;width:25px;height:25px;align-items:center;justify-content:center;border-radius:7px;background:var(--petrol-soft);color:var(--petrol)}.tg-link svg{width:15px}.tg-alarm-link{display:inline-flex;min-width:38px;height:25px;padding:0 7px;gap:5px;align-items:center;justify-content:center;border-radius:8px;text-decoration:none;font-weight:800;font-size:11px;font-variant-numeric:tabular-nums}.tg-alarm-link svg{width:14px;height:14px}.tg-alarm-link.success{background:#dff4ea;color:#14754f}.tg-alarm-link.warning{background:#fff1d7;color:#a66b0b}.tg-alarm-link.info{background:#ffe7d1;color:#b65e0b}.tg-alarm-link.danger{background:#fde5e3;color:#b32e28}.tg-alarm-link:hover{filter:brightness(.96);transform:translateY(-1px)}.tg-error{padding:12px;background:#fde9e7;color:#9f302a;border:1px solid #efb4af;border-radius:10px}.num{text-align:right;font-variant-numeric:tabular-nums}
.tgAlarmActions{display:inline-flex;align-items:center;gap:5px}.tgAlarmActions .alarmCell{display:inline-flex}.tgAlarmActions .alarmCell__actions{margin:0}.tgAlarmActions .alarmAction{width:25px;height:25px}
.tg-report-cell{width:62px;min-width:62px;text-align:center}.tg-report-cell .nsReportPick span{display:none}.tg-comment{display:block;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-soft)}
@media(max-width:1180px){.tg-hero__title{flex:1 1 100%;max-width:none}.tg-cards{width:100%}}
@media(max-width:1000px){.tg-filters{grid-template-columns:1fr 1fr}}
@media(max-width:650px){.tg-filters{grid-template-columns:1fr}.tg{padding:0 10px 20px}.tg-cards{grid-template-columns:1fr 1fr}.tg-hero__title{min-width:0}.tg-hero__title h1{font-size:16px}.tg-hero__visual{flex-basis:72px}.tg-hero__visual img{max-width:68px;max-height:64px}}
@media(max-width:480px){.tg-cards{grid-template-columns:1fr}}
</style></head><body><div class="app"><?php include __DIR__.'/sidebar.php'; ?><main class="main"><?php include __DIR__.'/topbar.php'; ?>
<div class="tg">
<?php if($error): ?><div class="tg-error"><?php echo h($error); ?></div><?php endif; ?>
<div class="tg-hero"><div class="tg-hero__title"><div class="tg-hero__copy"><div class="tg-hero__eyebrow">Telemetría</div><h1><?php echo h($TELEMETRY['title']); ?></h1><p><?php echo h($TELEMETRY['subtitle'] ?? ('Datos de dbo.'.$table)); ?></p><div class="tg-live"><span class="dot"></span><?php echo count($rows); ?> registros</div></div><?php if($heroImage!==''): ?><div class="tg-hero__visual"><img src="<?php echo h($heroImage); ?>?v=3.1.9" alt="<?php echo h($TELEMETRY['title']); ?>"></div><?php endif; ?></div><div class="tg-cards">
  <div class="tg-card total">
    <span class="tg-card__icon"><?php echo icon('monitor'); ?></span>
    <div class="tg-card__body"><strong><?php echo count($wellCounts); ?></strong><span>Pozos</span></div>
  </div>
  <div class="tg-card alarms">
    <span class="tg-card__icon"><?php echo icon('bell'); ?></span>
    <div class="tg-card__body"><strong><?php echo $wellsWithAlarms; ?></strong><span>Pozos con alarmas 24 h</span></div>
  </div>
  <div class="tg-card accum">
    <span class="tg-card__icon"><?php echo icon('chart'); ?></span>
    <div class="tg-card__body"><strong><?php echo number_format($totalAlarms24h,0,',','.'); ?></strong><span>Alarmas acumuladas 24 h</span></div>
  </div>
  <div class="tg-card regs">
    <span class="tg-card__icon"><?php echo icon('grid'); ?></span>
    <div class="tg-card__body"><strong><?php echo count($rows); ?></strong><span>Registros</span></div>
  </div>
  <?php if($remoteStopEnabled): ?>
  <button type="button" class="tg-card remote-stop" id="tgRemoteStopCard" data-quick-filter="remote-stop">
    <span class="tg-card__icon"><?php echo icon('shield'); ?></span>
    <div class="tg-card__body"><strong><?php echo count($remoteStopWells); ?></strong><span>Paro remoto habilitado</span><small>Click para filtrar</small></div>
  </button>
  <?php endif; ?>
  <?php if(($commCounts['Sin comunicación'] ?? 0) > 0): ?>
  <button type="button" class="tg-card comm-fail" id="tgCommFailCard" data-quick-filter="comm-fail">
    <span class="tg-card__icon"><?php echo icon('shield'); ?></span>
    <div class="tg-card__body"><strong><?php echo count($commFailWells); ?></strong><span>Pozos con falla de comunicación</span><small>Click para filtrar</small></div>
  </button>
  <?php endif; ?>
  <?php if(($commCounts['Demorada'] ?? 0) > 0): ?>
  <button type="button" class="tg-card comm-warn" id="tgCommWarnCard" data-quick-filter="comm-warn">
    <span class="tg-card__icon"><?php echo icon('history'); ?></span>
    <div class="tg-card__body"><strong><?php echo count($commWarnWells); ?></strong><span>Comunicación demorada</span><small>Click para filtrar</small></div>
  </button>
  <?php endif; ?>
</div></div>
<div class="tg-filters"><?php foreach($filterGroups as $fg): ?><details class="tg-panel" data-group="<?php echo h($fg['column']); ?>"><summary><?php echo h($fg['label']); ?> <small>Todos</small></summary><div class="tg-panel__body"><div class="tg-actions"><button type="button" data-all>Seleccionar todo</button><button type="button" data-clear>Limpiar</button></div><?php foreach($fg['values'] as $v=>$cnt): ?><label class="tg-check"><input class="tg-multi" type="checkbox" value="<?php echo h($v); ?>"><span><?php echo h($v); ?></span><b><?php echo (int)$cnt; ?></b></label><?php endforeach; ?></div></details><?php endforeach; ?></div>
<div class="tg-toolbar"><div class="tg-server-filter"><label>Zona</label><select data-server-filter data-param="zona"><option value="">Todas</option><?php foreach($zoneOptions as $zoneValue): ?><option value="<?php echo h($zoneValue); ?>"<?php echo $selectedZone===$zoneValue?' selected':''; ?>><?php echo h($zoneValue); ?></option><?php endforeach; ?></select></div><?php foreach($activeServerFilters as $filterColumn=>$serverFilter): ?><div class="tg-server-filter"><label><?php echo h($serverFilter['label']); ?></label><select data-server-filter data-param="<?php echo h($serverFilter['param']); ?>"><option value="TODOS">Todos</option><?php foreach($serverFilter['values'] as $filterValue): ?><option value="<?php echo h($filterValue); ?>"<?php echo $serverFilter['selected']===$filterValue?' selected':''; ?>><?php echo h($filterValue); ?></option><?php endforeach; ?></select></div><?php endforeach; ?><input id="tgSearch" class="tg-search" placeholder="Buscar en toda la grilla..."><button class="tg-btn" id="tgClear" type="button">Limpiar filtros</button><?php if($persistFiltersEnabled): ?><button class="tg-btn" id="tgSaveFilters" type="button">Guardar filtros</button><?php endif; ?><button class="tg-btn" id="tgExport" type="button">Exportar Excel</button><?php if($reportEnabled): ?><button class="tg-btn" type="button" data-ns-report-open>Ver reporte <span data-ns-report-count class="nsReportCount" hidden>0</span></button><?php endif; ?><button class="tg-btn" id="tgRefresh" type="button">Actualizar</button><label class="tg-page-size" for="tgPageSize"><span>Filas</span><select id="tgPageSize" aria-label="Cantidad de filas visibles"><option value="50" selected>50</option><option value="100">100</option><option value="all">Todas</option></select></label><select class="tg-select" id="tgInterval"><option value="0" selected>Automática desactivada</option><option value="300">Cada 5 minutos</option><option value="600">Cada 10 minutos</option><option value="1800">Cada 30 minutos</option></select><div class="tg-columns"><button class="tg-btn" id="tgColumnsBtn" type="button" aria-haspopup="true" aria-expanded="false">Columnas ▾</button><div class="tg-columns-menu" id="tgColumnsMenu"><div id="tgColumnsList"></div><button class="tg-btn" id="tgResetCols" type="button" style="width:100%;margin-top:6px">Restablecer</button></div></div><span class="tg-count">Mostrando <b id="tgShown"><?php echo min(50,count($rows)); ?></b> de <b id="tgVisible"><?php echo count($rows); ?></b> visibles</span></div>
<div class="tg-wrap"><table class="tg-table" id="tgTable"><thead><tr><?php if($reportEnabled): ?><th class="tg-report-cell" data-column="__REPORTE" data-type="text" data-report-label="REPORTE">REPORTE</th><?php endif; ?><?php foreach($displayColumns as $c): $isNum=!in_array($c,$nonNumeric,true)&&$c!=='__COMUNICACION'; $label=$c==='__COMUNICACION'?'COM':($TELEMETRY['labels'][$c]??($c===$remoteStopColumn?'PARO REMOTO':$c)); ?><th draggable="true" data-column="<?php echo h($c); ?>" data-type="<?php echo $isNum?'number':'text'; ?>" data-report-label="<?php echo h($label); ?>"<?php echo in_array($c,['PANTALLA','CARTAS'],true)?' data-report-exclude="1"':''; ?>><?php echo h($label); ?><span> ↕</span><?php if($c==='ALM'): ?><select class="tg-filter" data-column="ALM"><option value="">Todas</option><option value="__WITH__">Con alarmas</option><option value="__WITHOUT__">Sin alarmas</option></select><?php elseif(isset($activeServerFilters[$c])): $sf=$activeServerFilters[$c]; ?><select class="tg-filter tg-server-column-filter" data-column="<?php echo h($c); ?>" data-server-filter data-param="<?php echo h($sf['param']); ?>"><option value="TODOS">Todos</option><?php foreach($sf['values'] as $fv): ?><option value="<?php echo h($fv); ?>"<?php echo $sf['selected']===$fv?' selected':''; ?>><?php echo h($fv); ?></option><?php endforeach; ?></select><?php elseif(isset($selectFilterValues[$c])): ?><select class="tg-filter" data-column="<?php echo h($c); ?>"><option value="">Todos</option><?php foreach($selectFilterValues[$c] as $v): ?><option><?php echo h($v); ?></option><?php endforeach; ?></select><?php elseif($c===$batteryCol||$c===$stateCol||$c===$generalCol||$c==='__COMUNICACION'||$c===$remoteStopColumn): $vals=$c==='__COMUNICACION'?$commCounts:($c===$remoteStopColumn?$remoteStopCounts:($c===$batteryCol?$batteryCounts:($c===$stateCol?$stateCounts:$generalCounts))); ?><select class="tg-filter" data-column="<?php echo h($c); ?>"><option value="">Todos</option><?php foreach(array_keys($vals) as $v): ?><option><?php echo h($v); ?></option><?php endforeach; ?></select><?php else: ?><input class="tg-filter" data-column="<?php echo h($c); ?>" placeholder="Filtrar"><?php endif; ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($rows as $r):
  $commRow=tg_text($r['__COMUNICACION']??'');
  $rowClass=$commRow==='Sin comunicación'?'tg-row-comm-fail':($commRow==='Demorada'?'tg-row-comm-warn':'');
  $wellName=tg_text($r[$wellCol]??'');
  $wellCommentSubject=tg_text($r['__COMENTARIO_ASUNTO']??clear_alarm_comment_subject($wellName,'pozo'));
  $wellComment=tg_text($r['COMENTARIO']??'');
  $wellHasComment=(int)($r['__TIENE_COMENTARIO']??0)===1;
?>
<tr class="<?php echo h($rowClass); ?>" data-comm-status="<?php echo h($commRow); ?>">
<?php if($reportEnabled):$reportKey=ns_report_key('telemetry_'.$TELEMETRY['key'],[$wellName,tg_text($r[$batteryCol]??'')]); ?>
<td class="tg-report-cell" data-column="__REPORTE" data-raw=""><label class="nsReportPick" title="Agregar el estado del pozo al reporte"><input type="checkbox" data-ns-report-add data-report-row data-report-key="<?php echo h($reportKey); ?>" data-report-type="row" data-report-title="<?php echo h($TELEMETRY['title'].' · '.$wellName); ?>" data-report-group="<?php echo h('telemetry_'.$TELEMETRY['key']); ?>" data-report-group-title="<?php echo h($TELEMETRY['title']); ?>"><span>Incluir</span></label></td>
<?php endif; ?>
<?php foreach($displayColumns as $c):
  $raw=$c==='__COMUNICACION'?($r['__COMUNICACION']??''):tg_text($r[$c]??'');
  $isNum=!in_array($c,$nonNumeric,true)&&$c!=='__COMUNICACION';
?>
<td data-column="<?php echo h($c); ?>" data-raw="<?php echo h($raw); ?>" class="<?php echo $isNum?'num':''; ?>">
<?php if($c==='ALM'):
  $alarmCount=(int)$raw;
  $alarmClass=tg_alarm_class($alarmCount);
  $alarmUrl='pozos_alarmas24.php?'.http_build_query(['pozo'=>$wellName,'desde'=>date('Y-m-d',strtotime('-24 hours')),'hora_desde'=>date('H:i',strtotime('-24 hours')),'hasta'=>date('Y-m-d'),'hora_hasta'=>date('H:i')]);
  $alarmEmbedUrl=$alarmUrl.'&embed=1';
  $alarmCommentSubject=clear_alarm_comment_subject($wellName,'pozo');
?>
<span class="tgAlarmActions">
<a class="tg-alarm-link <?php echo h($alarmClass); ?>" href="<?php echo h($alarmUrl); ?>"
   data-telemetry-modal-url="<?php echo h($alarmEmbedUrl); ?>"
   data-telemetry-modal-open-url="<?php echo h($alarmUrl); ?>"
   data-telemetry-modal-title="<?php echo h('Alarmas 24 h · '.($wellName!==''?$wellName:'Pozo')); ?>"
   data-telemetry-modal-subtitle="Consulta operativa de las alarmas del pozo"
   data-telemetry-modal-eyebrow="Alarmas de pozo"
   title="<?php echo h($alarmCount.' alarmas en las últimas 24 horas'); ?>"><?php echo icon('bell'); ?><span><?php echo $alarmCount; ?></span></a>
<?php echo clear_alarm_actions_cell(['display'=>'','subject'=>$alarmCommentSubject,'subject_label'=>'Pozo '.$wellName,'context'=>$TELEMETRY['key'],'show_history'=>false,'show_comment'=>true,'has_comment'=>$wellHasComment,'icon_only'=>true]); ?>
</span>
<?php elseif($c==='COMENTARIO'): ?>
<span class="tg-comment" data-comment-subject="<?php echo h($wellCommentSubject); ?>" data-comment-preview title="<?php echo h($wellComment); ?>"><?php echo h($canViewTelemetryComments?($wellComment!==''?$wellComment:'Sin comentario'):'Sin permiso'); ?></span>
<?php elseif($c==='PANTALLA'&&tg_link($raw)): ?>
<a class="tg-link" href="<?php echo h($raw); ?>" target="_blank" rel="noopener"
   data-telemetry-popup-url="<?php echo h($raw); ?>"
   data-telemetry-popup-name="CLEAR_PI_VISION"
   title="Abrir pantalla en ventana emergente"><?php echo icon('monitor'); ?></a>
<?php elseif($c==='CARTAS'&&tg_link($raw)): ?>
<a class="tg-link" href="<?php echo h($raw); ?>" target="_blank" rel="noopener"
   data-telemetry-popup-url="<?php echo h($raw); ?>"
   data-telemetry-popup-name="CLEAR_PI_CARTAS"
   title="Abrir carta en ventana emergente"><?php echo icon('chart'); ?></a>
<?php elseif(($c==='PANTALLA'||$c==='CARTAS')&&!tg_link($raw)): ?>
<?php elseif($c==='__COMUNICACION'||$c===$stateCol||$c===$zafiroStateColumn||$c===$remoteStopColumn||($generalCol!==''&&$c===$generalCol)): ?>
<span class="tg-badge <?php echo h($c===$remoteStopColumn?tg_remote_stop_class($raw):tg_status_class($raw)); ?>"><?php echo h($raw); ?></span>
<?php else: echo h($isNum?tg_number($r[$c]??''):$raw); endif; ?>
</td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody></table></div></div></main></div>
<?php clear_alarm_actions_modal(); ?>
<?php include __DIR__.'/telemetry_modal.php'; ?>
<script>
document.querySelectorAll('[data-server-filter]').forEach(function(select){
  select.addEventListener('change',function(){
    var url=new URL(window.location.href);
    var param=this.getAttribute('data-param');
    var value=this.value;

    if(param==='zona'){
      /* Al cambiar de zona se elimina cualquier filtro de batería anterior. */
      url.searchParams.delete('bateria');
      sessionStorage.setItem('clear_reset_zone_battery_filter','1');
    }

    if(value && value!=='TODOS')url.searchParams.set(param,value);else url.searchParams.delete(param);
    window.location.href=url.toString();
  });
});
</script>
<script src="assets/js/app.js?v=3.1.7"></script>
<script>window.CLEAR_TELEMETRY_GRID=<?php echo json_encode(['key'=>$TELEMETRY['key'],'title'=>$TELEMETRY['title'],'batteryColumn'=>$batteryCol,'remoteStopColumn'=>$remoteStopEnabled?$remoteStopColumn:'','persistFilters'=>$persistFiltersEnabled,'columnStateVersion'=>(string)($TELEMETRY['column_state_version'] ?? '317').'-zafiro-1'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="assets/js/telemetry_grid.js?v=20260902-column-menu-1"></script>
<script src="assets/js/telemetry_modal.js?v=3.1.9"></script>
<script src="assets/js/alarm_actions.js?v=20260901-report-grid-1"></script>
<?php if($reportEnabled): ?><script>window.CLEAR_WEEKLY_NEWS={};</script><script src="assets/js/novedades_semanales.js?v=20260904-dashboard-full-1"></script><?php endif; ?>
</body></html>
