<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/recon_comments.php';

auth_require();
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok'=>false,'error'=>'Método no permitido.']);
        exit;
    }

    // Solo administradores o usuarios con permiso explícito para desactivar comentarios.
    if (!auth_es_admin() && !permissions_can('comments.disable')) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'No tenés permisos para eliminar comentarios.']);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    if ($action !== 'delete_comment' || $id <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Solicitud inválida.']);
        exit;
    }

    $db = clear_db();
    if (!$db->ok()) throw new RuntimeException('No hay conexión con SQL Server.');

    $row = $db->all("SELECT TOP 1 ID, TAG_FIX, OPERADOR, COMENTARIO, ACTIVO FROM dbo.FIXALARMS_COMENTARIOS WHERE ID = ?", [$id]);
    if (empty($row)) {
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'El comentario no existe.']);
        exit;
    }
    if ((int)($row[0]['ACTIVO'] ?? 0) !== 1) {
        echo json_encode(['ok'=>true,'message'=>'El comentario ya estaba eliminado.','id'=>$id]);
        exit;
    }

    // Borrado lógico para conservar trazabilidad y auditoría.
    $ok = $db->execute(
        "UPDATE dbo.FIXALARMS_COMENTARIOS SET ACTIVO = 0, FECHA_MODIFICACION = GETDATE() WHERE ID = ? AND ACTIVO = 1",
        [$id]
    );
    if (!$ok) throw new RuntimeException('SQL Server no pudo desactivar el comentario.');

    audit_log(
        'COMENTARIO_ELIMINADO',
        'comentarios',
        'ID='.$id.'; TAG='.(string)($row[0]['TAG_FIX'] ?? '').'; OPERADOR='.(string)($row[0]['OPERADOR'] ?? ''),
        auth_user()
    );

    echo json_encode(['ok'=>true,'message'=>'Comentario eliminado correctamente.','id'=>$id]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
