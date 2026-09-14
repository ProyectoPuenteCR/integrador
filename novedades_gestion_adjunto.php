<?php
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';require_once __DIR__.'/includes/permissions.php';require_once __DIR__.'/includes/novedades_gestion.php';
auth_require();header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);exit('Método no admitido.');}
try{$type=ng_text($_GET['tipo']??'',20,true);$spec=ng_spec($type);$id=ng_id($_GET['id']??'',false);}catch(Throwable $e){http_response_code(404);exit('Adjunto no disponible.');}
permissions_require_menu($spec['key']);
try{
 $db=clear_db();$rows=$db->all('SELECT ADJUNTO_CLAVE,ADJUNTO_NOMBRE,ADJUNTO_BYTES FROM dbo.CLEAR_NS_GESTIONES WHERE ID=? AND TIPO=?',[$id,$type]);
 if($db->error()||count($rows)!==1)throw new RuntimeException();$row=$rows[0];$path=pfa_path((string)ns_value($row,'ADJUNTO_CLAVE'));$name=pfa_filename(ns_value($row,'ADJUNTO_NOMBRE'));$size=(int)ns_value($row,'ADJUNTO_BYTES');
 if($size<1||$size>pfa_max_bytes()||!is_file($path)||filesize($path)!==$size)throw new RuntimeException();$stream=@fopen($path,'rb');if(!$stream)throw new RuntimeException();
}catch(Throwable $e){http_response_code(404);exit('Adjunto no disponible. Consultá al administrador.');}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
header('Content-Type: application/octet-stream');header("Content-Security-Policy: sandbox; default-src 'none'");
header('Content-Disposition: attachment; filename="adjunto.'.strtolower(pathinfo($name,PATHINFO_EXTENSION)).'"; filename*=UTF-8\'\''.rawurlencode($name));header('Content-Length: '.$size);fpassthru($stream);fclose($stream);exit;
