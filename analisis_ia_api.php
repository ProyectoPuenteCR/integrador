<?php
ob_start();
ini_set('display_errors','0');
error_reporting(E_ALL);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ai_analysis.php';
function ai_json($payload,$status=200){while(ob_get_level())ob_end_clean();http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
    auth_require();
    if(!auth_es_admin()) throw new RuntimeException('La configuración y ejecución manual de IA están restringidas a administradores.');
    $action=trim((string)($_POST['action']??''));
    if($action==='run_now'){
        [$ok,$message]=ai_analysis_generate(auth_user(),true);
        if(!$ok) throw new RuntimeException($message);
        audit_log('AI_DIAGNOSTIC_RUN','analisis_ia',$message);
        ai_json(['ok'=>true,'message'=>$message]);
    }
    throw new RuntimeException('La configuración de IA fue trasladada al área de administración.');
}catch(Throwable $e){ai_json(['ok'=>false,'error'=>$e->getMessage()],400);}
