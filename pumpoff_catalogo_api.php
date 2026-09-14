<?php
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';require_once __DIR__.'/includes/pumpoff_catalogo.php';
auth_require();permissions_require_menu('novedades_semanales_monitoreo');
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){http_response_code(405);echo nm_json(['ok'=>false,'error'=>'Método no permitido.']);exit;}
try{echo nm_json(['ok'=>true,'catalog'=>pfc_catalog(clear_db())]);}
catch(Throwable $e){http_response_code(503);echo nm_json(['ok'=>false,'error'=>'No se pudieron cargar los catálogos locales. Podés completar el parte manualmente y reintentar.']);}
