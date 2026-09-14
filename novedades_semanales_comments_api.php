<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/novedades_semanales_common.php';

auth_require();
header('Content-Type: application/json; charset=UTF-8');

function ns_comment_response(array $data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if (!permissions_can_menu('novedades_semanales_malos_actores') && !permissions_can_menu('novedades_semanales_seguimiento')) {
    ns_comment_response(['ok'=>false,'error'=>'No tenés permisos para acceder a este módulo.'],403);
}

$db=clear_db();
if (!$db->ok()) ns_comment_response(['ok'=>false,'error'=>'Sin conexión a SQL Server.'],500);
if (!ns_comments_ready($db)) ns_comment_response(['ok'=>false,'error'=>'Falta instalar la tabla de comentarios de Novedades semanales.'],503);

$payload=$_GET;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $raw=file_get_contents('php://input');
    $json=json_decode((string)$raw,true);
    $payload=is_array($json)?$json:$_POST;
}

$action=strtolower(ns_clean($payload['action']??($_SERVER['REQUEST_METHOD']==='POST'?'save':'get'),20));
$weekStart=ns_parse_date($payload['week_start']??'');
$weekEnd=ns_parse_date($payload['week_end']??'');
$type=ns_clean($payload['type']??'',30);
$installation=ns_clean($payload['installation']??'',255);
$tag=ns_clean($payload['tag']??'',255);

if (!$weekStart || !$weekEnd) ns_comment_response(['ok'=>false,'error'=>'La semana indicada no es válida.'],422);
$canonical=ns_week_for_date($weekStart);
if ($canonical['start']->format('Y-m-d')!==$weekStart->format('Y-m-d') || $canonical['end']->format('Y-m-d')!==$weekEnd->format('Y-m-d')) {
    ns_comment_response(['ok'=>false,'error'=>'La semana debe respetar el rango operativo de miércoles a martes.'],422);
}
if (!array_key_exists($type,ns_type_options()) || $installation==='' || $tag==='') {
    ns_comment_response(['ok'=>false,'error'=>'Tipo, instalación o TAG inválidos.'],422);
}

$rows=$db->all(
    "SELECT TOP 1 ID,COMENTARIO,USUARIO_CARGA,USUARIO_MODIFICACION,CONVERT(varchar(19),FECHA_CARGA,120) AS FECHA_CARGA,CONVERT(varchar(19),FECHA_MODIFICACION,120) AS FECHA_MODIFICACION,ACTIVO " .
    "FROM dbo.CLEAR_NOVEDADES_SEMANALES_COMENTARIOS WHERE SEMANA_DESDE=CONVERT(date,?,23) AND TIPO_INSTALACION=? AND INSTALACION=? AND TAG=?",
    [$weekStart->format('Y-m-d'),$type,$installation,$tag]
);
$existing=$rows?$rows[0]:null;

if ($action==='get') {
    if (!permissions_can('comments.view') && !permissions_can('comments.create')) ns_comment_response(['ok'=>false,'error'=>'No tenés permisos para ver comentarios.'],403);
    $active=$existing && (int)ns_value($existing,'ACTIVO',0)===1;
    ns_comment_response([
        'ok'=>true,
        'comment'=>$active?(string)ns_value($existing,'COMENTARIO'):'',
        'user'=>$active?(string)(ns_value($existing,'USUARIO_MODIFICACION')?:ns_value($existing,'USUARIO_CARGA')):'',
        'saved_at'=>$active?(string)(ns_value($existing,'FECHA_MODIFICACION')?:ns_value($existing,'FECHA_CARGA')):'',
    ]);
}

if ($action!=='save' || $_SERVER['REQUEST_METHOD']!=='POST') ns_comment_response(['ok'=>false,'error'=>'Acción no válida.'],405);
if (!permissions_can('comments.create')) ns_comment_response(['ok'=>false,'error'=>'No tenés permisos para guardar comentarios.'],403);

$user=(string)auth_user();
if ($existing) {
    $owner=(string)ns_value($existing,'USUARIO_CARGA');
    $canEdit=permissions_can('comments.edit_all') || ($owner===$user && permissions_can('comments.edit_own'));
    if (!$canEdit) ns_comment_response(['ok'=>false,'error'=>'No tenés permisos para editar este comentario.'],403);
}

$comment=ns_clean($payload['comment']??'',2000);
if (function_exists('mb_strlen') ? mb_strlen($comment,'UTF-8')>2000 : strlen($comment)>2000) ns_comment_response(['ok'=>false,'error'=>'El comentario supera los 2.000 caracteres.'],422);
$active=$comment!==''?1:0;

if ($existing) {
    $saved=$db->execute(
        "UPDATE dbo.CLEAR_NOVEDADES_SEMANALES_COMENTARIOS SET SEMANA_HASTA=CONVERT(date,?,23),COMENTARIO=?,USUARIO_MODIFICACION=?,FECHA_MODIFICACION=SYSDATETIME(),ACTIVO=? WHERE ID=?",
        [$weekEnd->format('Y-m-d'),$comment,$user,$active,(int)ns_value($existing,'ID')]
    );
    $auditAction=$active?'COMENTARIO_NS_EDITADO':'COMENTARIO_NS_DESACTIVADO';
} elseif ($active) {
    $saved=$db->execute(
        "INSERT INTO dbo.CLEAR_NOVEDADES_SEMANALES_COMENTARIOS(SEMANA_DESDE,SEMANA_HASTA,TIPO_INSTALACION,INSTALACION,TAG,COMENTARIO,USUARIO_CARGA,FECHA_CARGA,ACTIVO) VALUES(CONVERT(date,?,23),CONVERT(date,?,23),?,?,?,?,?,SYSDATETIME(),1)",
        [$weekStart->format('Y-m-d'),$weekEnd->format('Y-m-d'),$type,$installation,$tag,$comment,$user]
    );
    $auditAction='COMENTARIO_NS_CREADO';
} else {
    $saved=true;
    $auditAction='COMENTARIO_NS_SIN_CAMBIOS';
}

if (!$saved) ns_comment_response(['ok'=>false,'error'=>'No se pudo guardar el comentario. '.$db->error()],500);
audit_log($auditAction,'novedades_semanales',$weekStart->format('Y-m-d').' | '.$installation.' | '.$tag,$user);
ns_comment_response(['ok'=>true,'message'=>$active?'Comentario semanal guardado.':'Comentario semanal quitado.','user'=>$user,'saved_at'=>date('Y-m-d H:i:s')]);
