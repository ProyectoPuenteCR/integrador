<?php
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';require_once __DIR__.'/includes/reporte_pozos_adjuntos.php';
auth_require();permissions_require_menu('novedades_semanales_monitoreo');
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);exit('Método no admitido.');}
$id=$_GET['id']??'';
if(!is_string($id)||!ctype_digit($id)||(float)$id<1||(float)$id>2147483647){http_response_code(404);exit('Adjunto no disponible.');}
try{
    $db=clear_db();$rows=$db->all('SELECT ADJUNTO_CLAVE,ADJUNTO_NOMBRE,ADJUNTO_BYTES FROM dbo.CLEAR_PUMPOFF_PARTES WHERE ID=?',[(int)$id]);
    if($db->error()||count($rows)!==1)throw new RuntimeException();
    $row=$rows[0];$path=pfa_path((string)($row['ADJUNTO_CLAVE']??''));$name=pfa_filename($row['ADJUNTO_NOMBRE']??'');$size=(int)($row['ADJUNTO_BYTES']??0);
    if($size<1||$size>pfa_max_bytes()||!is_file($path)||!is_readable($path)||filesize($path)!==$size)throw new RuntimeException();
    $stream=@fopen($path,'rb');if(!$stream)throw new RuntimeException();
}catch(Throwable $e){http_response_code(404);exit('Adjunto no disponible. Consultá al administrador si el archivo fue movido.');}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
header('Content-Type: application/octet-stream');header("Content-Security-Policy: sandbox; default-src 'none'");
header('Content-Disposition: attachment; filename="adjunto.'.strtolower(pathinfo($name,PATHINFO_EXTENSION)).'"; filename*=UTF-8\'\''.rawurlencode($name));
header('Content-Length: '.$size);fpassthru($stream);fclose($stream);exit;
