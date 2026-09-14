<?php
require_once __DIR__.'/includes/auth.php';require_once __DIR__.'/includes/permissions.php';auth_require();header('Content-Type:application/json; charset=utf-8');
$key=trim($_POST['key']??$_GET['key']??'');if($key===''||strlen($key)>100){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Clave inválida']);exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){$value=(string)($_POST['value']??'');$ok=user_pref_set($key,$value);audit_log('PREFERENCIA_ACTUALIZADA','preferencias',$key);echo json_encode(['ok'=>$ok]);}else{echo json_encode(['ok'=>true,'value'=>user_pref_get($key,'')]);}
