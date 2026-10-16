<?php
/* Diagnóstico administrativo de solo lectura; no repite RTQP ni Jobs. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sql_storage_status.php';
auth_require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok'=>false,'error'=>'Método no permitido.']);
    exit;
}
$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'America/Argentina/Buenos_Aires');
$requested = $_GET['db'] ?? '';
if (!is_string($requested) || strlen($requested)>512 || strpos($requested,"\0")!==false) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Selección de base no válida.']);
    exit;
}
$requested = $requested !== '' ? $requested : (string)($cfg['db']['database'] ?? '');
$key = hash('sha256',json_encode(['storage-v1',__DIR__,$cfg['db']['host']??'',
    $cfg['db']['user']??'',$cfg['db']['database']??'',$requested]));
$cached = $_SESSION['clear_storage_snapshot'] ?? null;
$age = is_array($cached) ? time()-(int)($cached['time']??0) : -1;
if (is_array($cached) && ($cached['key']??'')===$key && $age>=0 && $age<60) {
    $result=$cached['data'];
    $result['cache_age']=$age;
    session_write_close();
} else {
    $user=$_SESSION['clear_user']??null;
    session_write_close();
    try {
        $result=sqlstorage_collect(clear_db(),$cfg,$requested);
    } catch (Throwable $e) {
        $result=['ok'=>false,'error'=>'No se pudo completar la lectura. Revisá conexión y permisos SQL.'];
    }
    $result['cache_age']=0;
    if (!empty($result['ok'])) {
        auth_start();
        if (($_SESSION['clear_user']??null)===$user) {
            $_SESSION['clear_storage_snapshot']=['key'=>$key,'time'=>time(),'data'=>$result];
        }
        session_write_close();
    }
}
$result['cache_seconds']=60;
echo json_encode($result,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
