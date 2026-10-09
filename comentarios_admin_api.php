<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/alarm_event_comments.php';
auth_require();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function cc_response($payload,$code=200){http_response_code($code);echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(!permissions_can('comments.view')) cc_response(['ok'=>false,'error'=>'Sin permiso para ver comentarios'],403);
$db=clear_db();
if(!$db->ok()) cc_response(['ok'=>false,'error'=>'No hay conexión a SQL Server'],500);
$origin=trim((string)($_POST['origin']??$_GET['origin']??''));
$id=(int)($_POST['id']??$_GET['id']??0);
$tables=['alarmas'=>'FIXALARMS_COMENTARIOS','novedades'=>'CLEAR_NOVEDADES_SEMANALES_COMENTARIOS',
'instalaciones'=>'FIXALARMS_INSTALACION_COMENTARIOS_SEMANALES','pozos'=>'FIXALARMS_POZO_COMENTARIOS_SEMANALES'];
if(!isset($tables[$origin])||$id<=0) cc_response(['ok'=>false,'error'=>'Registro inválido'],400);
if($_SERVER['REQUEST_METHOD']==='GET'){
 if((int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_COMENTARIOS_HISTORIAL',N'U') IS NULL THEN 0 ELSE 1 END")!==1)
  cc_response(['ok'=>true,'history'=>[],'setup_required'=>true]);
 $items=$db->all("SELECT ACCION,TEXTO_ANTERIOR,TEXTO_NUEVO,USUARIO,CONVERT(varchar(19),FECHA,120) FECHA FROM dbo.CLEAR_COMENTARIOS_HISTORIAL WHERE ORIGEN=? AND COMENTARIO_ID=? ORDER BY ID DESC",[$origin,$id]);
 cc_response(['ok'=>true,'history'=>$items]);
}
if($_SERVER['REQUEST_METHOD']!=='POST')cc_response(['ok'=>false,'error'=>'Método no permitido'],405);
auth_start();
$token=(string)($_POST['csrf']??'');
if(!hash_equals((string)($_SESSION['clear_comments_csrf']??''),$token) || $token==='') cc_response(['ok'=>false,'error'=>'Sesión de seguridad vencida; recargá la pantalla'],403);
$action=trim((string)($_POST['action']??''));
$isAdmin=auth_es_admin();
$isOperator=auth_rol()==='operador';
if($action==='ELIMINAR'&&!$isAdmin) cc_response(['ok'=>false,'error'=>'Solo los administradores pueden eliminar'],403);
if($action==='EDITAR'&&!($isAdmin||$isOperator))cc_response(['ok'=>false,'error'=>'Rol sin permiso de edición'],403);
if(!permissions_can('comments.view') || ($action==='EDITAR'&&!permissions_can('comments.create')) || ($action==='ELIMINAR'&&!permissions_can('comments.disable')))
 cc_response(['ok'=>false,'error'=>'Permiso insuficiente'],403);
if(!in_array($action,['EDITAR','ELIMINAR'],true))cc_response(['ok'=>false,'error'=>'Acción inválida'],400);
$comment=trim((string)($_POST['comment']??''));
$previous=(string)($_POST['previous']??'');
if($action==='EDITAR'&&($comment===''||strlen($comment)>20000))cc_response(['ok'=>false,'error'=>'Comentario obligatorio (máximo 20000 caracteres)'],400);
$result=$db->all("EXEC dbo.SP_CLEAR_COMENTARIO_CAMBIAR @Origen=?,@Id=?,@Accion=?,@Texto=?,@Usuario=?,@Esperado=?",
[$origin,$id,$action,$comment,auth_user(),$previous]);
if(!$result|| (int)($result[0]['OK']??0)!==1){
 $sqlError=$db->error();$database=(string)$db->scalar("SELECT DB_NAME()");
 cc_response(['ok'=>false,'error'=>'No se guardó el cambio en '.($database?:'la base de datos').'. Error SQL: '.($sqlError?:'revisá permisos EXECUTE sobre dbo.SP_CLEAR_COMENTARIO_CAMBIAR')],409);
}
audit_log($action==='EDITAR'?'COMENTARIO_EDITADO':'COMENTARIO_ELIMINADO','comentarios',$origin.' #'.$id);
cc_response(['ok'=>true,'message'=>$action==='EDITAR'?'Comentario actualizado; versión anterior guardada.':'Comentario eliminado del listado; historial conservado.']);
