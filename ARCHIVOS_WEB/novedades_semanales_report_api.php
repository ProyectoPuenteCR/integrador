<?php
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';require_once __DIR__.'/includes/permissions.php';require_once __DIR__.'/includes/novedades_semanales_common.php';require_once __DIR__.'/includes/reporting.php';
auth_require();header('Content-Type:application/json; charset=UTF-8');
function ns_rep_out(array $data,$status=200){http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(!permissions_can_menu('novedades_semanales_reporte')&&!permissions_can_menu('novedades_semanales_panel'))ns_rep_out(['ok'=>false,'error'=>'No tenés permisos para usar el reporte operativo.'],403);
$db=clear_db();if(!$db->ok())ns_rep_out(['ok'=>false,'error'=>'Sin conexión a SQL Server.'],500);if(!ns_report_ready($db))ns_rep_out(['ok'=>false,'error'=>'Volvé a ejecutar SQL/CLEAR_NOVEDADES_SEMANALES.sql para instalar el constructor de reportes.'],503);if(!report_ensure_tables())ns_rep_out(['ok'=>false,'error'=>'No se pudo preparar el guardado de reportes.'],503);
$raw=file_get_contents('php://input');$json=json_decode((string)$raw,true);$input=is_array($json)?$json:array_merge($_GET,$_POST);$action=strtolower(ns_clean($input['action']??'list',20));$user=(string)auth_user();
function ns_rep_payload_prepare(array $payload,$user){
    if(($payload['kind']??'')!=='chart'||empty($payload['image_data']))return $payload;
    $data=(string)$payload['image_data'];unset($payload['image_data']);
    if(strpos($data,'data:image/png;base64,')!==0)return $payload;
    $binary=base64_decode(substr($data,22),true);if($binary===false||strlen($binary)>3000000||substr($binary,0,8)!=="\x89PNG\r\n\x1a\n")return $payload;
    $relative='data/report_images/'.sha1($user.'|'.microtime(true).'|'.random_int(1,PHP_INT_MAX)).'.png';$absolute=__DIR__.'/'.$relative;$dir=dirname($absolute);
    if(!is_dir($dir)&&!@mkdir($dir,0775,true))return $payload;
    if(@file_put_contents($absolute,$binary)!==false)$payload['image_ref']=$relative;
    return $payload;
}
function ns_rep_snapshot(array $items){
    $snapshot=[];
    foreach($items as $row)$snapshot[]=[
        'item_key'=>(string)ns_value($row,'ITEM_KEY'),
        'item_type'=>(string)ns_value($row,'ITEM_TIPO'),
        'title'=>(string)ns_value($row,'TITULO'),
        'payload_json'=>(string)ns_value($row,'PAYLOAD_JSON'),
    ];
    return json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function ns_rep_saved_owned($db,$id,$user){
    if((int)$id<1)return [];
    $rows=$db->all("SELECT TOP 1 ID,NOMBRE,DESTINATARIOS,ASUNTO,CUERPO,ITEMS_JSON FROM dbo.CLEAR_REPORTES_GUARDADOS WHERE ID=? AND USUARIO=? AND ACTIVO=1",[(int)$id,(string)$user]);
    return $rows?$rows[0]:[];
}
if($action==='list'){$items=ns_report_items($db,$user);ns_rep_out(['ok'=>true,'keys'=>array_values(array_map(function($r){return (string)ns_value($r,'ITEM_KEY');},$items)),'count'=>count($items)]);}
if($action==='toggle'){
    $key=ns_clean($input['key']??'',120);$type=strtolower(ns_clean($input['type']??'',30));$title=ns_clean($input['title']??'',255);$active=!empty($input['active'])?1:0;$payload=$input['payload']??[];
    if(!preg_match('/^[a-z0-9_-]+:[a-f0-9]{40}$/',$key)||!in_array($type,['row','chart','kpi','table'],true)||$title==='')ns_rep_out(['ok'=>false,'error'=>'La selección recibida no es válida.'],422);
    if(!is_array($payload))$payload=[];$payload=ns_rep_payload_prepare($payload,$user);$payloadJson=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(strlen((string)$payloadJson)>100000)ns_rep_out(['ok'=>false,'error'=>'El elemento seleccionado es demasiado grande.'],422);
    $id=$db->scalar("SELECT TOP 1 ID FROM dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS WHERE USUARIO=? AND ITEM_KEY=?",[$user,$key]);
    if($id!==null&&$id!=='')$ok=$db->execute("UPDATE dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS SET ITEM_TIPO=?,TITULO=?,PAYLOAD_JSON=?,ACTIVO=?,FECHA_CAMBIO=SYSDATETIME() WHERE ID=?",[$type,$title,$payloadJson,$active,(int)$id]);
    elseif($active)$ok=$db->execute("INSERT INTO dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS(USUARIO,ITEM_KEY,ITEM_TIPO,TITULO,PAYLOAD_JSON,ACTIVO) VALUES(?,?,?,?,?,1)",[$user,$key,$type,$title,$payloadJson]);
    else $ok=true;
    if(!$ok)ns_rep_out(['ok'=>false,'error'=>'No se pudo actualizar la selección. '.$db->error()],500);$count=(int)$db->scalar("SELECT COUNT_BIG(*) FROM dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS WHERE USUARIO=? AND ACTIVO=1",[$user]);audit_log($active?'NS_REPORTE_AGREGAR':'NS_REPORTE_QUITAR','novedades_semanales',$key,$user);ns_rep_out(['ok'=>true,'count'=>$count,'message'=>$active?'Agregado al reporte.':'Quitado del reporte.']);
}
if($action==='batch'){
    $items=$input['items']??[];$active=!empty($input['active'])?1:0;
    if(!is_array($items)||!$items||count($items)>200)ns_rep_out(['ok'=>false,'error'=>'Seleccioná entre 1 y 200 elementos por operación.'],422);
    $processed=0;
    foreach($items as $item){
        if(!is_array($item))ns_rep_out(['ok'=>false,'error'=>'La selección múltiple no es válida.'],422);
        $key=ns_clean($item['key']??'',120);$type=strtolower(ns_clean($item['type']??'',30));$title=ns_clean($item['title']??'',255);$payload=$item['payload']??[];
        if(!preg_match('/^[a-z0-9_-]+:[a-f0-9]{40}$/',$key)||!in_array($type,['row','chart','kpi','table'],true)||$title===''||!is_array($payload))ns_rep_out(['ok'=>false,'error'=>'Uno de los elementos seleccionados no es válido.'],422);
        $payload=ns_rep_payload_prepare($payload,$user);$payloadJson=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(strlen((string)$payloadJson)>100000)ns_rep_out(['ok'=>false,'error'=>'Uno de los elementos seleccionados es demasiado grande.'],422);
        $id=$db->scalar("SELECT TOP 1 ID FROM dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS WHERE USUARIO=? AND ITEM_KEY=?",[$user,$key]);
        if($id!==null&&$id!=='')$ok=$db->execute("UPDATE dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS SET ITEM_TIPO=?,TITULO=?,PAYLOAD_JSON=?,ACTIVO=?,FECHA_CAMBIO=SYSDATETIME() WHERE ID=?",[$type,$title,$payloadJson,$active,(int)$id]);
        elseif($active)$ok=$db->execute("INSERT INTO dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS(USUARIO,ITEM_KEY,ITEM_TIPO,TITULO,PAYLOAD_JSON,ACTIVO) VALUES(?,?,?,?,?,1)",[$user,$key,$type,$title,$payloadJson]);
        else $ok=true;
        if(!$ok)ns_rep_out(['ok'=>false,'error'=>'No se pudo completar la selección múltiple. '.$db->error(),'processed'=>$processed],500);
        $processed++;
    }
    $count=(int)$db->scalar("SELECT COUNT_BIG(*) FROM dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS WHERE USUARIO=? AND ACTIVO=1",[$user]);
    audit_log($active?'NS_REPORTE_AGREGAR_MULTIPLE':'NS_REPORTE_QUITAR_MULTIPLE','novedades_semanales',(string)$processed,$user);
    ns_rep_out(['ok'=>true,'count'=>$count,'processed'=>$processed,'message'=>$processed.' elemento'.($processed===1?'':'s').' agregado'.($processed===1?'':'s').' al reporte.']);
}
if($action==='clear'){$ok=$db->execute("UPDATE dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS SET ACTIVO=0,FECHA_CAMBIO=SYSDATETIME() WHERE USUARIO=? AND ACTIVO=1",[$user]);if(!$ok)ns_rep_out(['ok'=>false,'error'=>'No se pudo vaciar el reporte.'],500);audit_log('NS_REPORTE_VACIAR','novedades_semanales','',$user);ns_rep_out(['ok'=>true,'count'=>0,'message'=>'Reporte vaciado.']);}
if($action==='delete_saved'){
    $savedId=(int)($input['id']??0);$saved=ns_rep_saved_owned($db,$savedId,$user);if(!$saved)ns_rep_out(['ok'=>false,'error'=>'El reporte guardado no existe o no pertenece a tu usuario.'],404);
    $ok=$db->execute("UPDATE dbo.CLEAR_REPORTES_GUARDADOS SET ACTIVO=0,FECHA_MODIFICACION=SYSDATETIME() WHERE ID=? AND USUARIO=?",[$savedId,$user]);if(!$ok)ns_rep_out(['ok'=>false,'error'=>'No se pudo eliminar el reporte.'],500);
    audit_log('NS_REPORTE_GUARDADO_ELIMINAR','reportes','ID '.$savedId,$user);ns_rep_out(['ok'=>true,'message'=>'Reporte eliminado.']);
}
if($action==='load_saved'){
    $saved=ns_rep_saved_owned($db,(int)($input['id']??0),$user);if(!$saved)ns_rep_out(['ok'=>false,'error'=>'El reporte guardado no existe o no pertenece a tu usuario.'],404);
    $snapshot=json_decode((string)ns_value($saved,'ITEMS_JSON'),true);if(!is_array($snapshot))ns_rep_out(['ok'=>false,'error'=>'El contenido guardado no se puede recuperar.'],422);
    if(!$db->execute("UPDATE dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS SET ACTIVO=0,FECHA_CAMBIO=SYSDATETIME() WHERE USUARIO=?",[$user]))ns_rep_out(['ok'=>false,'error'=>'No se pudo preparar el reporte para modificar.'],500);
    $loaded=0;
    foreach(array_slice($snapshot,0,300) as $item){
        if(!is_array($item))continue;$key=ns_clean($item['item_key']??'',120);$type=strtolower(ns_clean($item['item_type']??'',30));$title=ns_clean($item['title']??'',255);$payload=(string)($item['payload_json']??'{}');
        if(!preg_match('/^[a-z0-9_-]+:[a-f0-9]{40}$/',$key)||!in_array($type,['row','chart','kpi','table'],true)||$title===''||strlen($payload)>100000)continue;
        $id=$db->scalar("SELECT TOP 1 ID FROM dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS WHERE USUARIO=? AND ITEM_KEY=?",[$user,$key]);
        if($id!==null&&$id!=='')$ok=$db->execute("UPDATE dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS SET ITEM_TIPO=?,TITULO=?,PAYLOAD_JSON=?,ACTIVO=1,FECHA_CAMBIO=SYSDATETIME() WHERE ID=?",[$type,$title,$payload,(int)$id]);
        else $ok=$db->execute("INSERT INTO dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS(USUARIO,ITEM_KEY,ITEM_TIPO,TITULO,PAYLOAD_JSON,ACTIVO) VALUES(?,?,?,?,?,1)",[$user,$key,$type,$title,$payload]);
        if($ok)$loaded++;
    }
    audit_log('NS_REPORTE_GUARDADO_ABRIR','reportes','ID '.(int)ns_value($saved,'ID'),$user);
    ns_rep_out(['ok'=>true,'count'=>$loaded,'saved_id'=>(int)ns_value($saved,'ID'),'name'=>(string)ns_value($saved,'NOMBRE'),'emailto'=>(string)ns_value($saved,'DESTINATARIOS'),'subject'=>(string)ns_value($saved,'ASUNTO'),'body'=>(string)ns_value($saved,'CUERPO')]);
}
if($action==='save_report'){
    $items=ns_report_items($db,$user);if(!$items)ns_rep_out(['ok'=>false,'error'=>'El reporte no tiene elementos para guardar.'],422);
    $name=ns_clean($input['name']??'',200);if($name==='')ns_rep_out(['ok'=>false,'error'=>'Ingresá un nombre para el reporte.'],422);
    $emailto=ns_clean($input['emailto']??'',4000);$subject=ns_clean($input['subject']??'',250);$body=(string)($input['body']??'');if(strlen($body)>20000)$body=substr($body,0,20000);
    $snapshot=ns_rep_snapshot($items);if($snapshot===false||strlen((string)$snapshot)>4000000)ns_rep_out(['ok'=>false,'error'=>'El reporte es demasiado grande para guardarlo.'],422);
    $savedId=(int)($input['id']??0);$saved=$savedId?ns_rep_saved_owned($db,$savedId,$user):[];$sentNow=!empty($input['sent_now'])?1:0;
    if($savedId&& !$saved)ns_rep_out(['ok'=>false,'error'=>'No podés modificar un reporte de otro usuario.'],403);
    if($saved)$ok=$db->execute("UPDATE dbo.CLEAR_REPORTES_GUARDADOS SET NOMBRE=?,DESTINATARIOS=?,ASUNTO=?,CUERPO=?,ITEMS_JSON=?,FECHA_MODIFICACION=SYSDATETIME(),ULTIMO_ENVIO=CASE WHEN ?=1 THEN SYSDATETIME() ELSE ULTIMO_ENVIO END WHERE ID=? AND USUARIO=?",[$name,$emailto,$subject,$body,$snapshot,$sentNow,$savedId,$user]);
    else{
        $created=$db->all("INSERT INTO dbo.CLEAR_REPORTES_GUARDADOS(USUARIO,NOMBRE,DESTINATARIOS,ASUNTO,CUERPO,ITEMS_JSON,ULTIMO_ENVIO) OUTPUT INSERTED.ID AS ID VALUES(?,?,?,?,?,?,CASE WHEN ?=1 THEN SYSDATETIME() ELSE NULL END)",[$user,$name,$emailto,$subject,$body,$snapshot,$sentNow]);
        $savedId=$created?(int)ns_value($created[0],'ID'):0;$ok=$savedId>0;
    }
    if(!$ok||$savedId<1)ns_rep_out(['ok'=>false,'error'=>'No se pudo guardar el reporte. '.$db->error()],500);
    audit_log($saved?'NS_REPORTE_GUARDADO_MODIFICAR':'NS_REPORTE_GUARDADO_CREAR','reportes','ID '.$savedId,$user);
    ns_rep_out(['ok'=>true,'saved_id'=>$savedId,'message'=>$saved?'Cambios guardados correctamente.':'Reporte guardado correctamente.']);
}
function ns_rep_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function ns_rep_image_path($ref){$ref=str_replace('\\','/',trim((string)$ref));if(!preg_match('#^data/report_images/[a-f0-9]{40}\.png$#',$ref))return '';return is_file(__DIR__.'/'.$ref)?__DIR__.'/'.$ref:'';}
function ns_rep_email_html(array $items,$intro='',array &$inlineImages=[]){
    $html='<div style="font-family:Arial,sans-serif;color:#183746"><h1 style="color:#1a596b">CLEAR · Reporte operativo</h1>';
    if($intro!=='')$html.='<p>'.nl2br(ns_rep_html($intro)).'</p>';
    foreach($items as $row){$payload=json_decode((string)ns_value($row,'PAYLOAD_JSON'),true);if(!is_array($payload))$payload=[];$html.='<section style="margin:18px 0"><h2 style="font-size:17px;border-bottom:2px solid #1a596b;padding-bottom:5px">'.ns_rep_html(ns_value($row,'TITULO')).'</h2>';
      if(!empty($payload['context']))$html.='<p style="font-size:12px;color:#546a77">'.ns_rep_html((string)$payload['context']).'</p>';
      if(($payload['kind']??'')==='chart'){$path=ns_rep_image_path($payload['image_ref']??'');if($path!==''){$cid='clear_chart_'.count($inlineImages).'@clear';$inlineImages[]=['path'=>$path,'cid'=>$cid];$html.='<img src="cid:'.ns_rep_html($cid).'" alt="'.ns_rep_html(ns_value($row,'TITULO')).'" style="display:block;max-width:100%;height:auto;border:1px solid #dbe5e9;border-radius:8px">';}else{$html.='<p style="color:#718697">El gráfico no tiene una captura disponible. Volvé a seleccionarlo desde su pantalla.</p>';}}
      else{$columns=$payload['columns']??[];$html.='<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:12px">';foreach($columns as $k=>$v)$html.='<tr><th style="text-align:left;background:#eef4f6;border-bottom:1px solid #ddd">'.ns_rep_html($k).'</th><td style="border-bottom:1px solid #ddd">'.ns_rep_html($v).'</td></tr>';$html.='</table>';}
      $html.='</section>';}
    return $html.'<p style="font-size:10px;color:#718697">Generado por CLEAR Plataforma · '.date('d/m/Y H:i:s').'</p></div>';
}
if($action==='email'){
    $to=report_split_emails($input['emailto']??'');if(!$to)ns_rep_out(['ok'=>false,'error'=>'Ingresá al menos un destinatario válido.'],422);foreach($to as $email)if(!filter_var($email,FILTER_VALIDATE_EMAIL))ns_rep_out(['ok'=>false,'error'=>'Correo inválido: '.$email],422);if(count($to)>10)ns_rep_out(['ok'=>false,'error'=>'Se permiten hasta 10 destinatarios.'],422);
    $items=ns_report_items($db,$user);if(!$items)ns_rep_out(['ok'=>false,'error'=>'El reporte no tiene elementos seleccionados.'],422);$subject=ns_clean($input['subject']??'CLEAR · Reporte operativo',250);$body=(string)($input['body']??'');$smtp=report_smtp_config();if(!$smtp['host']||!$smtp['from'])ns_rep_out(['ok'=>false,'error'=>'La configuración SMTP está incompleta en Reportes por correo.'],503);
    try{$inline=[];$html=ns_rep_email_html($items,$body,$inline);$client=new ClearSmtpClient();$client->send($smtp,$to,[],[],$subject,$html,[],$inline);}catch(Throwable $e){ns_rep_out(['ok'=>false,'error'=>'No se pudo enviar el correo: '.$e->getMessage()],500);}
    $savedId=(int)($input['saved_id']??0);if($savedId>0)$db->execute("UPDATE dbo.CLEAR_REPORTES_GUARDADOS SET ULTIMO_ENVIO=SYSDATETIME() WHERE ID=? AND USUARIO=? AND ACTIVO=1",[$savedId,$user]);
    audit_log('NS_REPORTE_EMAIL','reportes',implode(';',$to),$user);ns_rep_out(['ok'=>true,'message'=>'Reporte enviado correctamente a '.implode(', ',$to).'.','offer_save'=>true,'saved_id'=>$savedId]);
}
ns_rep_out(['ok'=>false,'error'=>'Acción no válida.'],405);
