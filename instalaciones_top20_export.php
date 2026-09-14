<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/zones.php';
require_once __DIR__ . '/includes/instalaciones_top20_week.php';
require_once __DIR__ . '/includes/installation_type.php';
require_once __DIR__ . '/includes/alarm_event_comments.php';

auth_require();
permissions_require_menu('top20_all');
$db = clear_db();
if (!$db->ok()) { http_response_code(500); exit('Sin conexión.'); }

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$selectedWeek = it20_selected_week($_GET, new DateTimeImmutable('now'));
$fromDate = $selectedWeek['start']->format('Y-m-d');
$toDate = $selectedWeek['end']->format('Y-m-d');
$fromSql = $fromDate . 'T00:00:00';
$toSqlExclusive = $selectedWeek['next']->format('Y-m-d') . 'T00:00:00';
$search = trim((string)($_GET['q'] ?? ''));
$zones = clear_zones_all($db);
$zone = clear_zone_valid(trim((string)($_GET['zona'] ?? '')), $zones);
$installationFilter = trim((string)($_GET['instalacion'] ?? ''));
$installationTypeFilter = clear_installation_type_normalize($_GET['tipo_instalacion'] ?? '');

function it20_export_terms($value)
{
    $value = trim((string)$value);
    return $value === '' ? [] : array_values(array_filter(preg_split('/\s+/', $value)));
}

$tag = 'ALM_TAGNAME';
$desc = 'ALM_DESCR';
$external = 'ALM_ALMEXTFLD2';
$date = 'ALM_NATIVETIMEIN';
$tagExpr = "LTRIM(RTRIM(CONVERT(nvarchar(255),[$tag])))";
$descExpr = "LTRIM(RTRIM(CONVERT(nvarchar(1000),[$desc])))";
$externalExpr = "LTRIM(RTRIM(CONVERT(nvarchar(500),[$external])))";
$installationExpr = "LEFT($tagExpr,CHARINDEX('_',$tagExpr+'_')-1)";
$installationTypeExpr = clear_installation_type_sql($tagExpr, $externalExpr);
$tagOnlyInstallationTypeExpr = clear_installation_type_sql($tagExpr, "N''");
$groupedInstallationTypeExpr = "CASE WHEN MAX(CASE WHEN UPPER($externalExpr) LIKE N'YPF.SC%' THEN 1 ELSE 0 END)=1 THEN N'POZO' ELSE ($tagOnlyInstallationTypeExpr) END";
$entityExpr = $installationTypeFilter === 'POZO' ? $externalExpr : $installationExpr;
$conditions = ["[$tag] IS NOT NULL", "$tagExpr<>''", "[$date]>=CONVERT(datetime2,?,126)", "[$date]<CONVERT(datetime2,?,126)"];
$params = [$fromSql, $toSqlExclusive];

if ($zone !== '') {
    $conditions[] = "EXISTS (SELECT 1 FROM [CLEAR].[ZONAS] Z WHERE LTRIM(RTRIM(Z.ZONA)) COLLATE DATABASE_DEFAULT=? COLLATE DATABASE_DEFAULT AND LTRIM(RTRIM(Z.BATERIA)) COLLATE DATABASE_DEFAULT=$installationExpr COLLATE DATABASE_DEFAULT)";
    $params[] = $zone;
}
if ($installationFilter !== '') {
    $conditions[] = "$entityExpr=?";
    $params[] = $installationFilter;
}
if ($installationTypeFilter !== '') {
    $conditions[] = "$installationTypeExpr=?";
    $params[] = $installationTypeFilter;
}
foreach (it20_export_terms($search) as $term) {
    $conditions[] = "($tagExpr LIKE ? OR $descExpr LIKE ? OR $entityExpr LIKE ?)";
    $params[] = '%' . $term . '%';
    $params[] = '%' . $term . '%';
    $params[] = '%' . $term . '%';
}
$where = 'WHERE ' . implode(' AND ', $conditions);
$rows = $db->all(
    "SELECT TOP 20 $groupedInstallationTypeExpr AS TIPO_INSTALACION,$entityExpr AS INSTALACION,$tagExpr AS TAG,MAX($descExpr) AS DESCRIPCION," .
    "COUNT_BIG(*) AS TOTAL_ALARMAS,MAX(NULLIF($externalExpr,'')) AS CAMPO_EXT " .
    "FROM dbo.FIXALARMS $where GROUP BY $entityExpr,$tagExpr " .
    "ORDER BY COUNT_BIG(*) DESC,$entityExpr,$tagExpr",
    $params
);

$comments = [];
$canExportComments = permissions_can('comments.view') || permissions_can('comments.create');
if ($canExportComments) {
    $subjects = [];
    foreach ($rows as $commentRow) {
        $entity = trim((string)($commentRow['INSTALACION'] ?? $commentRow['instalacion'] ?? ''));
        $type = clear_installation_type_normalize($commentRow['TIPO_INSTALACION'] ?? $commentRow['tipo_instalacion'] ?? '');
        if ($entity !== '') $subjects[] = clear_alarm_comment_subject($entity, $type === 'POZO' ? 'pozo' : 'instalacion');
    }
    foreach (clear_alarm_comments_load_subjects_sql($subjects) as $key => $commentRow) {
        $comments[$key] = [
            'comment'=>$commentRow['COMENTARIO'] ?? $commentRow['comentario'] ?? '',
            'user'=>$commentRow['USUARIO_CARGA'] ?? $commentRow['usuario_carga'] ?? '',
        ];
    }
}

audit_log('EXPORTACION', 'export', 'top20_all semanal ' . $fromDate . ' al ' . $toDate);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="CLEAR_Top20_Instalaciones_Semana_' . $fromDate . '_al_' . $toDate . '.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['SEMANA_DESDE','SEMANA_HASTA','TIPO_INSTALACION','ENTIDAD','TAG','DESCRIPCION','TOTAL_ALARMAS','CAMPO_EXT','COMENTARIO','USUARIO_COMENTARIO'], ';');
foreach ($rows as $row) {
    $installation = (string)($row['INSTALACION'] ?? $row['instalacion'] ?? '');
    $rowType = clear_installation_type_normalize($row['TIPO_INSTALACION'] ?? $row['tipo_instalacion'] ?? '');
    $comment = $comments[strtoupper(clear_alarm_comment_subject($installation, $rowType === 'POZO' ? 'pozo' : 'instalacion'))] ?? ['comment'=>'','user'=>''];
    fputcsv($out, [
        $fromDate,
        $toDate,
        $row['TIPO_INSTALACION'] ?? $row['tipo_instalacion'] ?? 'SIN CLASIFICAR',
        $installation,
        $row['TAG'] ?? $row['tag'] ?? '',
        $row['DESCRIPCION'] ?? $row['descripcion'] ?? '',
        $row['TOTAL_ALARMAS'] ?? $row['total_alarmas'] ?? 0,
        $row['CAMPO_EXT'] ?? $row['campo_ext'] ?? '',
        $comment['comment'],
        $comment['user'],
    ], ';');
}
fclose($out);
