<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/zones.php';
$historyMode=defined('POZOS_ALARMAS_HISTORY_MODE')&&POZOS_ALARMAS_HISTORY_MODE===true;
$screenKey=$historyMode?'pozos_todas_alarmas':'pozos_alarmas24';
auth_require(); permissions_require_menu($screenKey); audit_log('EXPORTACION',$screenKey,$historyMode?'Exportación Excel histórico de alarmas de pozos':'Exportación Excel alarmas de pozos');

$db = clear_db();
if(!$db->ok()){http_response_code(500);exit('Sin conexión a la base.');}
$table='dbo.FIXALARMS';
$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$now = new DateTimeImmutable('now');
$defaultTo = $now;
$defaultFrom = $now->sub(new DateInterval('PT24H'));

function pex_date($v,$fallback){$v=trim((string)$v);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);return ($d&&$d->format('Y-m-d')===$v)?$v:$fallback;}
function pex_time($v,$fallback){$v=trim((string)$v);return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$v)?$v:$fallback;}
function pex_terms($v){$v=trim((string)$v);return $v===''?[]:array_values(array_filter(preg_split('/\s+/', $v)));}
function pex_clean($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function pex_value_bucket_sql($column){
  $expr="UPPER(LTRIM(RTRIM(CONVERT(nvarchar(4000),[$column]))))";
  return "CASE WHEN $expr IN ('FALLA','FAULT') THEN 'FALLA' WHEN $expr IN ('MARCHA','RUNNING','RUN') THEN 'MARCHA' WHEN $expr IN ('PARADO','PARADA','STOP','DETENIDO') THEN 'PARADO' WHEN $expr IN ('OPEN','ABIERTO') THEN 'OPEN' WHEN $expr IN ('CLOSE','CLOSED','CERRADO') THEN 'CLOSE' WHEN $expr IN ('NORMAL','OK') THEN 'NORMAL' ELSE '' END";
}
function pex_priority_group_sql($column){
  $expr="UPPER(LTRIM(RTRIM(CONVERT(nvarchar(4000),[$column]))))";
  return "CASE WHEN $expr IN ('HIGH','ALTA','CRITICAL','CRITICA','CRÍTICA') THEN 'HIGH' WHEN $expr IN ('MED','MEDIUM','MEDIA') THEN 'MEDIUM' WHEN $expr IN ('LOW','BAJA') THEN 'LOW' WHEN $expr='INFO' THEN 'INFO' ELSE $expr END";
}
function pex_status_group_sql($column){return "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(4000),[$column]))))";}

$fromDate=pex_date($_GET['desde']??($historyMode?'':$defaultFrom->format('Y-m-d')),$historyMode?'':$defaultFrom->format('Y-m-d'));
$toDate=pex_date($_GET['hasta']??($historyMode?'':$defaultTo->format('Y-m-d')),$historyMode?'':$defaultTo->format('Y-m-d'));
$fromTime=pex_time($_GET['hora_desde']??($historyMode?'':$defaultFrom->format('H:i')),$historyMode?'':'00:00');
$toTime=pex_time($_GET['hora_hasta']??($historyMode?'':$defaultTo->format('H:i')),$historyMode?'':'23:59');
if($fromDate!==''&&$fromTime==='')$fromTime='00:00';
if($toDate!==''&&$toTime==='')$toTime='23:59';
$fromSql=$fromDate!==''?$fromDate.'T'.$fromTime.':00':'';
$toSql=$toDate!==''?$toDate.'T'.$toTime.':59':'';
if($fromSql!==''&&$toSql!==''&&strtotime($fromSql)>strtotime($toSql)){[$fromSql,$toSql]=[$toSql,$fromSql];}
$hasExplicitDateInputs=isset($_GET['desde'])||isset($_GET['hasta'])||isset($_GET['hora_desde'])||isset($_GET['hora_hasta']);
$prefix=trim((string)($_GET['prefijo_pozo'] ?? 'YPF.SC'));
$wellSearch=trim((string)($_GET['pozo'] ?? ''));
$descSearch=trim((string)($_GET['descripcion'] ?? ''));
$statusFilter=trim((string)($_GET['f_estado'] ?? ''));
$priorityFilter=trim((string)($_GET['f_prioridad'] ?? ''));
$valueBucketFilter=trim((string)($_GET['f_valorcat'] ?? ''));
$columnFilterInput=isset($_GET['cf'])&&is_array($_GET['cf'])?$_GET['cf']:[];

$metaRows=$db->all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS' ORDER BY ORDINAL_POSITION");
$all=[];foreach($metaRows as $r){$c=trim((string)($r['COLUMN_NAME']??$r['column_name']??reset($r)));if($c!=='')$all[]=$c;}
if(!$all){$sample=$db->all("SELECT TOP 1 * FROM $table");if($sample)$all=array_keys($sample[0]);}
$lookup=[];foreach($all as $c)$lookup[strtoupper($c)]=$c;
$well=$lookup['ALM_ALMEXTFLD2']??'ALM_ALMEXTFLD2';
$desc=$lookup['ALM_DESCR']??($lookup['ALM_TAGDESC']??'ALM_DESCR');
$date=$lookup['ALM_NATIVETIMEIN']??'ALM_NATIVETIMEIN';
$last=$lookup['ALM_NATIVETIMELAST']??'ALM_NATIVETIMELAST';
$value=$lookup['ALM_VALUE']??'ALM_VALUE';
$tag=$lookup['ALM_TAGNAME']??'ALM_TAGNAME';
$unit=$lookup['ALM_UNIT']??'ALM_UNIT';
$status=$lookup['ALM_ALMSTATUS']??'ALM_ALMSTATUS';
$priority=$lookup['ALM_ALMPRIORITY']??'ALM_ALMPRIORITY';

$preferred=[$well,$desc,$date,$last,$value,$tag,$unit,$status,$priority];
$ordered=[];foreach($preferred as $c)if(in_array($c,$all,true)&&!in_array($c,$ordered,true))$ordered[]=$c;foreach($all as $c)if(!in_array($c,$ordered,true))$ordered[]=$c;
$columnFilters=[];foreach($ordered as $index=>$column){$filterValue=trim((string)($columnFilterInput[$index]??''));if($filterValue!=='')$columnFilters[$index]=$filterValue;}
$requested=isset($_GET['columns'])?explode(',',(string)$_GET['columns']):[];
$columns=array_values(array_filter($requested,function($c)use($all){return in_array($c,$all,true);}));
if(!$columns)$columns=$ordered;

$baseConditions=["[$well] IS NOT NULL","LTRIM(RTRIM(CONVERT(nvarchar(4000),[$well])))<>''"];
$params=[];
$zones=clear_zones_all($db);$zoneFilter=clear_zone_valid((string)($_GET['zona']??''),$zones);
if($zoneFilter!==''){$tagNorm="LTRIM(RTRIM(CONVERT(nvarchar(255),[$tag])))";$tagInst="LEFT($tagNorm,CHARINDEX('_',$tagNorm+'_')-1)";$baseConditions[]="EXISTS (SELECT 1 FROM [CLEAR].[ZONAS] Z WHERE LTRIM(RTRIM(Z.ZONA)) COLLATE DATABASE_DEFAULT = ? COLLATE DATABASE_DEFAULT AND LTRIM(RTRIM(Z.BATERIA)) COLLATE DATABASE_DEFAULT = $tagInst COLLATE DATABASE_DEFAULT)";$params[]=$zoneFilter;}
if($prefix!==''){$baseConditions[]="UPPER(LTRIM(RTRIM(CONVERT(nvarchar(4000),[$well])))) LIKE ?";$params[]=strtoupper($prefix).'%';}
foreach(pex_terms($wellSearch) as $term){$baseConditions[]="(CONVERT(nvarchar(4000),[$well]) LIKE ? OR REPLACE(REPLACE(REPLACE(CONVERT(nvarchar(4000),[$well]),'_',''),'-',''),' ','') LIKE ?)";$params[]='%'.$term.'%';$params[]='%'.str_replace(['_','-',' '],'',$term).'%';}
foreach(pex_terms($descSearch) as $term){$baseConditions[]="CONVERT(nvarchar(4000),[$desc]) LIKE ?";$params[]='%'.$term.'%';}
$rangeConditions=[];$rangeParams=[];
if($fromSql!==''){$rangeConditions[]="[$date]>=CONVERT(datetime2, ?, 126)";$rangeParams[]=$fromSql;}
if($toSql!==''){$rangeConditions[]="[$date]<=CONVERT(datetime2, ?, 126)";$rangeParams[]=$toSql;}
$effectiveConditions=array_merge($baseConditions,$rangeConditions);
$effectiveParams=array_merge($params,$rangeParams);
$preTotal=(int)$db->scalar('SELECT COUNT(*) FROM '.$table.' WHERE '.implode(' AND ',$effectiveConditions),$effectiveParams);
if(!$historyMode&&$preTotal===0&&!$hasExplicitDateInputs){$effectiveConditions=$baseConditions;$effectiveParams=$params;}
if($statusFilter!==''){$effectiveConditions[]=pex_status_group_sql($status).' = ?';$effectiveParams[]=strtoupper($statusFilter);} 
if($priorityFilter!==''){$effectiveConditions[]=pex_priority_group_sql($priority).' = ?';$effectiveParams[]=strtoupper($priorityFilter);} 
if($valueBucketFilter!==''){$effectiveConditions[]=pex_value_bucket_sql($value).' = ?';$effectiveParams[]=strtoupper($valueBucketFilter);} 
foreach($columnFilters as $index=>$filterValue){if(!isset($ordered[$index]))continue;$filterColumn=$ordered[$index];foreach(pex_terms($filterValue) as $term){$effectiveConditions[]="CONVERT(nvarchar(4000),[$filterColumn]) LIKE ?";$effectiveParams[]='%'.$term.'%';}}
$where='WHERE '.implode(' AND ',$effectiveConditions);
$select=implode(', ',array_map(function($c){return '['.str_replace(']',']]', $c).']';},$columns));
$rows=$db->all("SELECT $select FROM $table $where ORDER BY [$date] DESC",$effectiveParams);

$filename=($historyMode?'CLEAR_Pozos_Historico_Alarmas_':'CLEAR_Pozos_Alarmas_').date('Ymd_His').'.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>table{border-collapse:collapse;font-family:Arial;font-size:10pt}th{background:#1a4d5c;color:#fff;font-weight:bold}th,td{border:1px solid #cbd7de;padding:5px;vertical-align:top}td{mso-number-format:'\@'}</style></head><body>
<table><thead><tr><?php foreach($columns as $column):?><th><?php echo pex_clean($column);?></th><?php endforeach;?></tr></thead><tbody>
<?php foreach($rows as $row):?><tr><?php foreach($columns as $column):?><td><?php echo pex_clean($row[$column]??'');?></td><?php endforeach;?></tr><?php endforeach;?>
</tbody></table></body></html>
