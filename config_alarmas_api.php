<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';

header('Content-Type: application/json; charset=utf-8');
auth_require_admin();
permissions_require_menu('config_alarmas');

$db = clear_db();
if (!$db->ok()) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'No hay conexión con SQL Server.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function alarm_policy_api_row($db)
{
    $rows = $db->all(
        "SELECT MODO_ACTIVO,ESTADO,SOLICITADO_POR,CONVERT(nvarchar(19),SOLICITADO_EN,120) SOLICITADO_EN," .
        "CONVERT(nvarchar(19),INICIADO_EN,120) INICIADO_EN,CONVERT(nvarchar(19),FINALIZADO_EN,120) FINALIZADO_EN," .
        "DETALLE,ULTIMO_ERROR,RECALCULO_COMPLETO FROM dbo.CLEAR_ALARM_POLICY WHERE ID=1"
    );
    return $rows ? $rows[0] : null;
}

$installed = (int)$db->scalar(
    "SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_ALARM_POLICY',N'U') IS NOT NULL " .
    "AND OBJECT_ID(N'dbo.SP_CLEAR_SOLICITAR_POLITICA_CFN',N'P') IS NOT NULL THEN 1 ELSE 0 END"
) === 1;
if (!$installed) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Primero ejecute SQL/CLEAR_CONFIGURACION_GLOBAL_CFN_20260908.sql.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok'=>true,'policy'=>alarm_policy_api_row($db)], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

auth_start();
$sentToken = (string)($_POST['csrf'] ?? '');
$sessionToken = (string)($_SESSION['clear_alarm_policy_csrf'] ?? '');
if ($sessionToken === '' || !hash_equals($sessionToken, $sentToken)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'La sesión venció. Recargue la página.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));
$mode = strtoupper(trim((string)($_POST['mode'] ?? '')));
$allowed = ['OCULTAR_ESTADO','MOSTRAR','EXCLUIR'];
if (!in_array($action, ['set','rebuild'], true) || !in_array($mode, $allowed, true)) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Solicitud inválida.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($mode === 'EXCLUIR' && (string)($_POST['confirm_exclude'] ?? '') !== '1') {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Debe confirmar el impacto de excluir CFN.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$previous = clear_alarm_cfn_mode($db);
$force = $action === 'rebuild' ? 1 : 0;
$ok = $db->execute(
    "EXEC dbo.SP_CLEAR_SOLICITAR_POLITICA_CFN @Modo=?,@Usuario=?,@ForzarCompleto=?",
    [$mode, (string)auth_user(), $force]
);
if (!$ok) {
    http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>$db->error() ?: 'No se pudo registrar la solicitud.'], JSON_UNESCAPED_UNICODE);
    exit;
}

clear_alarm_cfn_mode($db, true);
audit_log(
    $action === 'rebuild' ? 'RECALCULAR_POLITICA_CFN' : 'CAMBIAR_POLITICA_CFN',
    'config_alarmas',
    'Modo anterior: '.$previous.'; modo solicitado: '.$mode.'; completo: '.$force,
    auth_user()
);

echo json_encode([
    'ok'=>true,
    'message'=>$force ? 'Recálculo completo encolado.' : 'Configuración guardada y actualización encolada.',
    'policy'=>alarm_policy_api_row($db),
], JSON_UNESCAPED_UNICODE);
