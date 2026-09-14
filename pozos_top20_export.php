<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/pozos_top20_week.php';
require_once __DIR__ . '/includes/alarm_event_comments.php';
auth_require(); permissions_require_menu('pozos_top20');
$db=clear_db(); if(!$db->ok()){http_response_code(500);exit('Sin conexión.');}

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$today = new DateTimeImmutable('now');
$selectedWeek=pt20_selected_week($_GET,$today);
$prefix=trim((string)($_GET['prefijo_pozo']??'YPF.SC'));
$search=trim((string)($_GET['q']??''));
$fromDate=$selectedWeek['start']->format('Y-m-d');
$toDate=$selectedWeek['end']->format('Y-m-d');
$fromSql=$fromDate.'T00:00:00';$toSqlExclusive=$selectedWeek['next']->format('Y-m-d').'T00:00:00';
function pe_terms($v){return trim((string)$v)===''?[]:array_values(array_filter(preg_split('/\s+/',trim((string)$v))));}
$table='dbo.FIXALARMS';$tag='ALM_TAGNAME';$desc='ALM_DESCR';$well='ALM_ALMEXTFLD2';$date='ALM_NATIVETIMEIN';
$tagExpr="LTRIM(RTRIM(CONVERT(nvarchar(255),[$tag])))";$descExpr="LTRIM(RTRIM(CONVERT(nvarchar(1000),[$desc])))";$wellExpr="LTRIM(RTRIM(CONVERT(nvarchar(255),[$well])))";
$conditions=["[$tag] IS NOT NULL","$tagExpr<>''","[$well] IS NOT NULL","$wellExpr<>''","[$date]>=CONVERT(datetime2, ?, 126)","[$date]<CONVERT(datetime2, ?, 126)"];$params=[$fromSql,$toSqlExclusive];
if($prefix!==''){$conditions[]="UPPER($wellExpr) LIKE ?";$params[]=strtoupper($prefix).'%';}
foreach(pe_terms($search) as $term){$conditions[]="($tagExpr LIKE ? OR $descExpr LIKE ? OR $wellExpr LIKE ?)";$params[]='%'.$term.'%';$params[]='%'.$term.'%';$params[]='%'.$term.'%';}
$where='WHERE '.implode(' AND ',$conditions);
$rows=$db->all("SELECT TOP 20 $wellExpr AS POZO,$tagExpr AS TAG,MAX($descExpr) AS DESCRIPCION,COUNT(*) AS TOTAL_ALARMAS FROM $table $where GROUP BY $wellExpr,$tagExpr ORDER BY COUNT(*) DESC,$wellExpr,$tagExpr",$params);
$comments=[];
$canExportComments=permissions_can('comments.view')||permissions_can('comments.create');
if($canExportComments){
    $subjects=[];
    foreach($rows as $commentRow){$value=trim((string)($commentRow['POZO']??''));if($value!=='')$subjects[]=clear_alarm_comment_subject($value,'pozo');}
    foreach(clear_alarm_comments_load_subjects_sql($subjects) as $subject=>$c){
        $comments[$subject]=['comment'=>$c['COMENTARIO']??'','user'=>$c['USUARIO_CARGA']??''];
    }
}
header('Content-Type:text/csv; charset=UTF-8');
header('Content-Disposition:attachment; filename="CLEAR_Top20_Pozos_Semana_'.$fromDate.'_al_'.$toDate.'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out,['SEMANA_DESDE','SEMANA_HASTA','POZO','TAG','DESCRIPCION','TOTAL_ALARMAS','COMENTARIO','USUARIO_COMENTARIO'],';');
foreach($rows as $r){
    $wellValue=(string)($r['POZO']??'');$comment=$comments[strtoupper(clear_alarm_comment_subject($wellValue,'pozo'))]??['comment'=>'','user'=>''];
    fputcsv($out,[$fromDate,$toDate,$wellValue,$r['TAG']??'',$r['DESCRIPCION']??'',$r['TOTAL_ALARMAS']??0,$comment['comment'],$comment['user']],';');
}
fclose($out);
