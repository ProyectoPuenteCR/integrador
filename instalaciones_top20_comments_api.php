<?php
ob_start();
ini_set('display_errors', '0');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/instalaciones_top20_week.php';

auth_require();
permissions_require_menu('top20_all');
header('Content-Type: application/json; charset=utf-8');

function it20_comment_out(array $data, $status = 200)
{
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function it20_comment_clean($value, $maxLength = 255)
{
    $value = trim((string)$value);
    return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
}

function it20_comment_row_value(array $row, $name, $default = '')
{
    if (array_key_exists($name, $row)) return $row[$name];
    foreach ($row as $key => $value) {
        if (strtoupper((string)$key) === strtoupper((string)$name)) return $value;
    }
    return $default;
}

$db = clear_db();
if (!$db->ok()) it20_comment_out(['ok'=>false, 'error'=>$db->error()], 500);

$tableExists = (int)$db->scalar(
    "SELECT CASE WHEN OBJECT_ID(N'dbo.FIXALARMS_INSTALACION_COMENTARIOS_SEMANALES', N'U') IS NULL THEN 0 ELSE 1 END"
) === 1;
if (!$tableExists) {
    it20_comment_out([
        'ok'=>false,
        'error'=>'Falta instalar la tabla de comentarios. Ejecutá SQL/CLEAR_TOP20_INSTALACIONES_SEMANAL_COMENTARIOS.sql.',
    ], 503);
}

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? 'get'));
$installation = it20_comment_clean($_POST['instalacion'] ?? $_GET['instalacion'] ?? '');
$weekStart = it20_comment_clean($_POST['semana_desde'] ?? $_GET['semana_desde'] ?? '', 10);
$weekEnd = it20_comment_clean($_POST['semana_hasta'] ?? $_GET['semana_hasta'] ?? '', 10);

if ($installation === '' || !it20_is_canonical_week($weekStart, $weekEnd)) {
    it20_comment_out(['ok'=>false, 'error'=>'La instalación y una semana válida de miércoles a martes son obligatorias.'], 400);
}

if ($action === 'get') {
    if (!permissions_can('comments.view') && !permissions_can('comments.create')) {
        it20_comment_out(['ok'=>false, 'error'=>'No tenés permiso para ver comentarios.'], 403);
    }
    $rows = $db->all(
        "SELECT TOP 1 ID, INSTALACION, SEMANA_DESDE, SEMANA_HASTA, COMENTARIO, USUARIO_CARGA, FECHA_CARGA, " .
        "USUARIO_MODIFICACION, FECHA_MODIFICACION " .
        "FROM dbo.FIXALARMS_INSTALACION_COMENTARIOS_SEMANALES " .
        "WHERE INSTALACION = ? AND SEMANA_DESDE = CONVERT(date, ?, 23) AND ACTIVO = 1 ORDER BY ID DESC",
        [$installation, $weekStart]
    );
    $row = $rows ? $rows[0] : [];
    $updatedBy = (string)it20_comment_row_value($row, 'USUARIO_MODIFICACION');
    $createdBy = (string)it20_comment_row_value($row, 'USUARIO_CARGA');
    $updatedAt = (string)it20_comment_row_value($row, 'FECHA_MODIFICACION');
    $createdAt = (string)it20_comment_row_value($row, 'FECHA_CARGA');
    it20_comment_out([
        'ok'=>true,
        'found'=>!empty($row),
        'comment'=>(string)it20_comment_row_value($row, 'COMENTARIO'),
        'user'=>$updatedBy !== '' ? $updatedBy : $createdBy,
        'created_at'=>$createdAt,
        'updated_at'=>$updatedAt !== '' ? $updatedAt : $createdAt,
        'id'=>!empty($row) ? (int)it20_comment_row_value($row, 'ID', 0) : null,
    ]);
}

if ($action !== 'save') it20_comment_out(['ok'=>false, 'error'=>'Acción no válida.'], 400);
if (!permissions_can('comments.create')) {
    it20_comment_out(['ok'=>false, 'error'=>'No tenés permiso para crear o editar comentarios.'], 403);
}

$comment = trim((string)($_POST['comment'] ?? ''));
if ($comment === '') it20_comment_out(['ok'=>false, 'error'=>'El comentario es obligatorio.'], 400);
if (strlen($comment) > 2000) it20_comment_out(['ok'=>false, 'error'=>'El comentario no puede superar los 2.000 caracteres.'], 400);

$user = auth_user() ?: 'CLEAR';
$existingId = $db->scalar(
    "SELECT TOP 1 ID FROM dbo.FIXALARMS_INSTALACION_COMENTARIOS_SEMANALES " .
    "WHERE INSTALACION = ? AND SEMANA_DESDE = CONVERT(date, ?, 23) ORDER BY ID DESC",
    [$installation, $weekStart]
);

if ($existingId !== null && $existingId !== '') {
    $saved = $db->execute(
        "UPDATE dbo.FIXALARMS_INSTALACION_COMENTARIOS_SEMANALES SET " .
        "SEMANA_HASTA = CONVERT(date, ?, 23), COMENTARIO = ?, USUARIO_MODIFICACION = ?, " .
        "FECHA_MODIFICACION = SYSDATETIME(), ACTIVO = 1 WHERE ID = ?",
        [$weekEnd, $comment, $user, (int)$existingId]
    );
    $id = (int)$existingId;
    $auditAction = 'COMENTARIO_INSTALACION_SEMANAL_EDITADO';
} else {
    $saved = $db->execute(
        "INSERT INTO dbo.FIXALARMS_INSTALACION_COMENTARIOS_SEMANALES " .
        "(INSTALACION, SEMANA_DESDE, SEMANA_HASTA, COMENTARIO, USUARIO_CARGA, FECHA_CARGA, ACTIVO) " .
        "VALUES (?, CONVERT(date, ?, 23), CONVERT(date, ?, 23), ?, ?, SYSDATETIME(), 1)",
        [$installation, $weekStart, $weekEnd, $comment, $user]
    );
    $id = $saved ? (int)$db->scalar(
        "SELECT TOP 1 ID FROM dbo.FIXALARMS_INSTALACION_COMENTARIOS_SEMANALES " .
        "WHERE INSTALACION = ? AND SEMANA_DESDE = CONVERT(date, ?, 23) ORDER BY ID DESC",
        [$installation, $weekStart]
    ) : null;
    $auditAction = 'COMENTARIO_INSTALACION_SEMANAL_CREADO';
}

if (!$saved) {
    it20_comment_out(['ok'=>false, 'error'=>'No se pudo guardar el comentario semanal. ' . $db->error()], 500);
}

audit_log($auditAction, 'top20_all', $installation . ' | ' . $weekStart . ' al ' . $weekEnd, $user);
it20_comment_out([
    'ok'=>true,
    'message'=>'Comentario semanal de la instalación guardado en SQL Server.',
    'id'=>$id,
    'user'=>$user,
    'saved_at'=>date('Y-m-d H:i:s'),
]);
