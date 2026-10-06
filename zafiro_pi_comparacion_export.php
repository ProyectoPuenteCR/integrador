<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/zafiro_pi_comparacion.php';
auth_require();
permissions_require_menu('zafiro_pi_comparacion');
$db=clear_db();
if(!$db->ok() || !zpc_ready($db)){http_response_code(503);exit('Datos no disponibles');}
$data=zpc_load($db);
$selected=zpc_selected_methods($data['methods']);
$selectedStates=zpc_selected_states($data['states']);
$selectedZones=zpc_selected_zones($data['rows']);
$excluded=zpc_excluded_wells();
$rows=zpc_apply_filters($data['rows'],$selected,$selectedStates,$selectedZones,$excluded,$_GET);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="zafiro_vs_pi_'.date('Ymd_His').'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out,['Pozo','Instalacion','Zona','Metodo Zafiro','Estado Zafiro','Activo','En PI','Fuente PI','Ultima telemetria','Petroleo 24','Liquido 24'],';');
foreach($rows as $r){
  fputcsv($out,[$r['pozo'],$r['instalacion'],$r['zona'],$r['metodo'],$r['estado'],$r['activo']?'Si':'No',$r['enPi']?'Si':'No',$r['piSource'],$r['piLast'],$r['petroleo'],$r['liquido']],';');
}
fclose($out);
exit;
