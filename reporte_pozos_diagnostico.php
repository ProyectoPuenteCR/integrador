<?php
// Diagnóstico de configuración, solo administradores; no escribe ni sube archivos.
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';require_once __DIR__.'/includes/pumpoff_partes.php';
auth_require();
if(!auth_es_admin()){http_response_code(403);exit('Solo administradores.');}
header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: no-store');
echo "REPORTE DE POZOS — DIAGNÓSTICO (SOLO LECTURA)\n\n";
echo 'Columnas SQL: '.(pfp_details_ready(clear_db())?'OK':'FALTA migración SQL o permiso de lectura')."\n";
echo 'file_uploads: '.(ini_get('file_uploads')?'habilitado':'DESHABILITADO')."\n";
echo 'upload_max_filesize: '.ini_get('upload_max_filesize')." (necesario 5M o más)\n";
echo 'post_max_size: '.ini_get('post_max_size')." (recomendado 8M o más)\n";
try{$dir=pfa_storage_dir();echo 'Carpeta privada: configurada; '.(is_writable($dir)?'escritura permitida':'SIN permiso de escritura')."\n";}
catch(Throwable $e){echo 'Carpeta privada: '.$e->getMessage()."\n";}
echo "\nIIS: verificar manualmente que maxAllowedContentLength admita al menos 8388608 bytes.\n";
echo "El diagnóstico no comprueba las reglas de IIS ni reemplaza una prueba de subir y descargar un archivo.\n";
