<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/tecss_3sigma_query.php';
require_once __DIR__ . '/includes/alarm_event_comments.php';

auth_require();
permissions_require_menu('tecss_3sigma');
$db = clear_db();
if (!sigma_cache_exists($db)) { http_response_code(503); exit('La caché de 3Sigma TECSS no está disponible.'); }

$filters = sigma_filters_from_request();
$globalSearch = trim((string)($_GET['q'] ?? ''));
$zoneOptions = clear_zones_all($db);
list($whereSql,$params) = sigma_build_where($db,$filters,$globalSearch,trim((string)($_GET['zona']??'')),$zoneOptions);
$columns = sigma_columns();
$select = implode(',',array_map('sigma_q',$columns));
$sql = "SELECT TOP (5000) $select FROM dbo.CLEAR_CACHE_TECSS_3SIGMA$whereSql
        ORDER BY CASE WHEN [CONT_EXCESOS_NUM] IS NULL THEN 1 ELSE 0 END,[CONT_EXCESOS_NUM] DESC,
                 TRY_CONVERT(datetime2,[HOY]) DESC,[POZO_BUSQUEDA]";
$rows = $db->all($sql,$params);

$commentMap=[];
if(permissions_can('comments.view')&&$rows){
    $subjects=[];
    foreach($rows as $row){$well=sigma_text($row['POZO']??'');if($well!=='')$subjects[]=clear_alarm_comment_subject($well,'pozo');}
    foreach(array_chunk(array_values(array_unique($subjects)),1000) as $subjectChunk){
        foreach(clear_alarm_comments_load_subjects_sql($subjectChunk) as $key=>$commentRow)$commentMap[$key]=$commentRow;
    }
}
$exportColumns=array_merge($columns,['COMENTARIO']);
function sigma_export_html($value){
    $value=(string)$value;
    if(preg_match('/^[=+\-@]/',$value))$value="'".$value;
    return htmlspecialchars($value,ENT_QUOTES,'UTF-8');
}
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="3Sigma_TECSS_' . date('Ymd_His') . '.xls"');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo "\xEF\xBB\xBF";
echo '<html><head><meta charset="UTF-8"><style>table{border-collapse:collapse;font-family:Arial;font-size:10pt}th{background:#1a596b;color:#fff}th,td{border:1px solid #b9c8ce;padding:5px;text-align:left;vertical-align:top}</style></head><body><h2>CLEAR · 3Sigma TECSS</h2><table><thead><tr>';
foreach($exportColumns as $column)echo '<th>'.sigma_export_html($column).'</th>';
echo '</tr></thead><tbody>';
foreach($rows as $row){
    echo '<tr>';
    foreach($columns as $column){
        $value=$row[$column]??'';
        if($column==='3SIGMA' && sigma_text($value)!=='')$value=number_format((float)str_replace(',','.',sigma_text($value)),4,',','');
        echo '<td style="mso-number-format:\'\\@\'">'.sigma_export_html($value).'</td>';
    }
    $subject=clear_alarm_comment_subject(sigma_text($row['POZO']??''),'pozo');
    $commentRow=$subject!==''?($commentMap[strtoupper($subject)]??[]):[];
    $comment=trim((string)($commentRow['COMENTARIO']??$commentRow['comentario']??''));
    echo '<td>'.sigma_export_html($comment).'</td></tr>';
}
echo '</tbody></table></body></html>';
exit;
