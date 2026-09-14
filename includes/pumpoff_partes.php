<?php
require_once __DIR__.'/pumpoff.php';
require_once __DIR__.'/reporte_pozos_adjuntos.php';

function pfp_zones(): array { return ['LHCG','CED I','CED II']; }
function pfp_can_create(): bool { return auth_es_admin() || permissions_can('comments.create'); }
function pfp_can_edit(array $row,string $user): bool {
    return auth_es_admin() || permissions_can('comments.edit_all') ||
        (permissions_can('comments.edit_own') && strcasecmp(nm_text(ns_value($row,'USUARIO_CARGA')),$user)===0);
}
function pfp_ready($db): bool {
    return $db && $db->ok() && (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_PUMPOFF_PARTES',N'U') IS NULL THEN 0 ELSE 1 END")===1;
}
function pfp_details_ready($db): bool {
    return $db && $db->ok() && (int)$db->scalar("SELECT CASE WHEN COL_LENGTH(N'dbo.CLEAR_PUMPOFF_PARTES',N'OBSERVACIONES') IS NOT NULL AND COL_LENGTH(N'dbo.CLEAR_PUMPOFF_PARTES',N'ADJUNTO_CLAVE') IS NOT NULL AND COL_LENGTH(N'dbo.CLEAR_PUMPOFF_PARTES',N'ADJUNTO_NOMBRE') IS NOT NULL AND COL_LENGTH(N'dbo.CLEAR_PUMPOFF_PARTES',N'ADJUNTO_BYTES') IS NOT NULL THEN 1 ELSE 0 END")===1;
}
function pfp_validate(array $input,DateTimeImmutable $now): array {
    $raw=$input['SEMANA_DESDE']??'';
    if(!is_string($raw)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$raw))throw new RuntimeException('Indicá una fecha válida para la semana.');
    $date=ns_parse_date($raw);
    if(!$date||$date->format('Y-m-d')!==$raw||$date->format('Y')<'2000')throw new RuntimeException('La fecha de la semana no es válida.');
    $week=ns_week_for_date($date)['start'];
    if($week>ns_week_for_date($now)['start'])throw new RuntimeException('No se pueden cargar estados de semanas futuras.');
    $row=['SEMANA_DESDE'=>$week->format('Y-m-d')];
    foreach(['ZONA'=>150,'SUPERVISOR'=>200,'JEFE_PRODUCCION'=>200,'BATERIA'=>255,'POZO'=>180,'TAG'=>255,'ESTADO'=>20] as $field=>$limit){
        $value=$input[$field]??($field==='TAG'?'':null);
        if(!is_string($value)||!preg_match('//u',$value))throw new RuntimeException('Completá el campo '.$field.'.');
        $value=trim($value);
        $length=preg_match_all('/[\s\S]/u',$value)+preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$value);
        if(($value===''&&$field!=='TAG')||$length>$limit||preg_match('/[\x00-\x1f\x7f]/',$value))throw new RuntimeException('Revisá '.$field.': obligatorio, sin saltos de línea y hasta '.$limit.' caracteres.');
        $row[$field]=$value;
    }
    $notes=$input['OBSERVACIONES']??'';
    if(!is_string($notes)||!preg_match('//u',$notes))throw new RuntimeException('Las observaciones no son válidas.');
    $notes=str_replace(["\r\n","\r"],"\n",trim($notes));
    $length=preg_match_all('/[\s\S]/u',$notes)+preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$notes);
    if($length>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$notes))throw new RuntimeException('Las observaciones admiten hasta 2.000 caracteres.');
    $row['OBSERVACIONES']=$notes;
    $remove=$input['QUITAR_ADJUNTO']??'0';
    if(!in_array($remove,[0,1,'0','1',false,true],true))throw new RuntimeException('La opción de quitar adjunto no es válida.');
    $row['QUITAR_ADJUNTO']=(bool)$remove;
    $row['ESTADO']=pf_key($row['ESTADO']);$row['POZO']=pf_key($row['POZO']);
    if(!in_array($row['ESTADO'],['MANUAL','AUTOMATICO','HOA'],true))throw new RuntimeException('Elegí MANUAL, AUTOMÁTICO o HOA.');
    if(!in_array($row['ZONA'],pfp_zones(),true))throw new RuntimeException('Elegí una de las tres zonas.');
    foreach(['ID','VERSION'] as $key){
        $value=$input[$key]??0;
        if(!is_int($value)&&!(is_string($value)&&ctype_digit($value)))throw new RuntimeException('Identificador del parte no válido.');
        if((float)$value<0||(float)$value>2147483647)throw new RuntimeException('Identificador fuera de rango.');
        $row[$key]=(int)$value;
    }
    if(($row['ID']===0)!==($row['VERSION']===0))throw new RuntimeException('La versión del parte no es válida. Actualizá la pantalla.');
    return $row;
}
function pfp_history($db,array $period): array {
    $out=['ready'=>pfp_ready($db),'rows'=>[],'error'=>''];
    if(!$out['ready'])return $out;
    $details=pfp_details_ready($db);$out['details_ready']=$details;
    $extra=$details?"CASE WHEN SEMANA_DESDE=CONVERT(date,?,23) THEN OBSERVACIONES ELSE NULL END OBSERVACIONES,ADJUNTO_NOMBRE,ADJUNTO_BYTES":"CAST(NULL AS nvarchar(2000)) OBSERVACIONES,CAST(NULL AS nvarchar(180)) ADJUNTO_NOMBRE,CAST(NULL AS int) ADJUNTO_BYTES";
    $params=$details?[$period['selected_value']??$period['to_value']]:[];$params[]=$period['from_value'];$params[]=$period['to_value'];
    $rows=$db->all("SELECT TOP (12001) ID,VERSION,CONVERT(varchar(10),SEMANA_DESDE,23) SEMANA_DESDE,ZONA,SUPERVISOR,JEFE_PRODUCCION,BATERIA,POZO,TAG,ESTADO,$extra,USUARIO_CARGA,USUARIO_MODIFICACION,CONVERT(varchar(19),FECHA_MODIFICACION,120) CAPTURADO_EN FROM dbo.CLEAR_PUMPOFF_PARTES WHERE SEMANA_DESDE>=CONVERT(date,?,23) AND SEMANA_DESDE<=CONVERT(date,?,23) ORDER BY SEMANA_DESDE,POZO",$params);
    if($db->error()){$out['error']='No se pudieron leer los partes semanales.';return $out;}
    if(count($rows)>12000){$out['error']='Acortá el período: se superó el límite de 12.000 partes.';return $out;}
    foreach($rows as $raw){
        $row=[];foreach(['SEMANA_DESDE','ZONA','SUPERVISOR','JEFE_PRODUCCION','BATERIA','POZO','TAG','ESTADO','USUARIO_CARGA','USUARIO_MODIFICACION','CAPTURADO_EN'] as $field)$row[$field]=nm_text(ns_value($raw,$field));
        $row['OBSERVACIONES']=(string)(ns_value($raw,'OBSERVACIONES')??'');$row['ADJUNTO_NOMBRE']=nm_text(ns_value($raw,'ADJUNTO_NOMBRE'));$row['ADJUNTO_BYTES']=(int)ns_value($raw,'ADJUNTO_BYTES');
        $row['ID']=(int)ns_value($raw,'ID');$row['VERSION']=(int)ns_value($raw,'VERSION');
        $row['ADJUNTO']=$row['ADJUNTO_NOMBRE'];
        $start=ns_parse_date($row['SEMANA_DESDE']);if(!$start)continue;
        $row['SEMANA']=ns_week_label($start,false);$row['FECHA_FUENTE']='Carga manual de parte';
        $row['CAN_EDIT']=pfp_can_edit($row,(string)auth_user());$out['rows'][]=$row;
    }
    return $out;
}
function pfp_store($db,array $input,DateTimeImmutable $now,string $user,?array $upload=null): array {
    $row=pfp_validate($input,$now);
    if(!$row['ID']&&!pfp_can_create())throw new RuntimeException('No tenés permiso para agregar partes.');
    if(!pfp_ready($db))throw new RuntimeException('Falta instalar SQL/CLEAR_PUMPOFF_PARTES.sql.');
    if(!pfp_details_ready($db))throw new RuntimeException('Ejecutá SQL/CLEAR_REPORTE_POZOS_OBSERVACIONES_ADJUNTO.sql antes de guardar.');
    $file=pfa_validate_upload($upload);
    if($file&&$row['QUITAR_ADJUNTO'])throw new RuntimeException('Elegí reemplazar el archivo o quitarlo, no ambas opciones.');
    $staged=null;$oldKey='';$commitAttempted=false;
    if(!$db->execute("SET XACT_ABORT ON; BEGIN TRANSACTION; DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=N'CLEAR_PUMPOFF_PARTES_GUARDAR',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=0; IF @r<0 BEGIN ROLLBACK TRANSACTION; THROW 51000,'Otro usuario esta guardando un parte.',1; END;")){
        $db->execute('IF @@TRANCOUNT>0 ROLLBACK TRANSACTION');throw new RuntimeException('Otro guardado está en curso. Reintentá sin cerrar el formulario.');
    }
    try {
        $attachment=['key'=>'','name'=>'','bytes'=>0];
        if($row['ID']){
            $existing=$db->all('SELECT ID,VERSION,USUARIO_CARGA,OBSERVACIONES,ADJUNTO_CLAVE,ADJUNTO_NOMBRE,ADJUNTO_BYTES FROM dbo.CLEAR_PUMPOFF_PARTES WITH (UPDLOCK,HOLDLOCK) WHERE ID=?',[$row['ID']]);
            if($db->error()||!$existing)throw new RuntimeException('El parte ya no está disponible. Actualizá la pantalla.');
            if(!pfp_can_edit($existing[0],$user))throw new RuntimeException('No tenés permiso para editar este parte.');
            if((int)ns_value($existing[0],'VERSION')!==$row['VERSION'])throw new RuntimeException('Otro usuario modificó el parte. Actualizá la pantalla antes de editarlo.');
        }
        if($row['ID']){
            if(!array_key_exists('OBSERVACIONES',$input))$row['OBSERVACIONES']=(string)(ns_value($existing[0],'OBSERVACIONES')??'');
            $oldKey=nm_text(ns_value($existing[0],'ADJUNTO_CLAVE'));
            $attachment=['key'=>$oldKey,'name'=>nm_text(ns_value($existing[0],'ADJUNTO_NOMBRE')),'bytes'=>(int)ns_value($existing[0],'ADJUNTO_BYTES')];
        }
        if($row['QUITAR_ADJUNTO'])$attachment=['key'=>'','name'=>'','bytes'=>0];
        $duplicate=$db->all('SELECT ID FROM dbo.CLEAR_PUMPOFF_PARTES WITH (UPDLOCK,HOLDLOCK) WHERE SEMANA_DESDE=CONVERT(date,?,23) AND POZO=? AND ID<>?',[$row['SEMANA_DESDE'],$row['POZO'],$row['ID']]);
        if($db->error())throw new RuntimeException('No se pudo comprobar si el parte existe.');
        if($duplicate)throw new RuntimeException('Ese pozo ya tiene un parte para la semana. Editá el existente para no contarlo dos veces.');
        $total=$db->scalar('SELECT COUNT(*) FROM dbo.CLEAR_PUMPOFF_PARTES WHERE SEMANA_DESDE=CONVERT(date,?,23) AND ID<>?',[$row['SEMANA_DESDE'],$row['ID']]);
        if($db->error()||$total===null)throw new RuntimeException('No se pudo comprobar el tamaño de la semana.');
        if((int)$total>=1000)throw new RuntimeException('La semana alcanzó el límite de 1.000 pozos.');
        if($file){$staged=pfa_stage($file);$attachment=$staged;}
        $params=[];foreach(['SEMANA_DESDE','ZONA','SUPERVISOR','JEFE_PRODUCCION','BATERIA','POZO','TAG','ESTADO'] as $key)$params[]=$row[$key];
        $params[]=$row['OBSERVACIONES'];$params[]=$attachment['key'];$params[]=$attachment['name'];$params[]=$attachment['bytes'];
        if($row['ID']){
            $params[]=$user;$params[]=$row['ID'];$params[]=$row['VERSION'];
            $sql='UPDATE dbo.CLEAR_PUMPOFF_PARTES SET SEMANA_DESDE=CONVERT(date,?,23),ZONA=?,SUPERVISOR=?,JEFE_PRODUCCION=?,BATERIA=?,POZO=?,TAG=?,ESTADO=?,OBSERVACIONES=?,ADJUNTO_CLAVE=?,ADJUNTO_NOMBRE=?,ADJUNTO_BYTES=?,USUARIO_MODIFICACION=?,FECHA_MODIFICACION=SYSDATETIME(),VERSION=VERSION+1 OUTPUT INSERTED.ID,INSERTED.VERSION WHERE ID=? AND VERSION=?';
        }else{
            $params[]=$user;$params[]=$user;
            $sql='INSERT INTO dbo.CLEAR_PUMPOFF_PARTES(SEMANA_DESDE,ZONA,SUPERVISOR,JEFE_PRODUCCION,BATERIA,POZO,TAG,ESTADO,OBSERVACIONES,ADJUNTO_CLAVE,ADJUNTO_NOMBRE,ADJUNTO_BYTES,USUARIO_CARGA,USUARIO_MODIFICACION) OUTPUT INSERTED.ID,INSERTED.VERSION VALUES(CONVERT(date,?,23),?,?,?,?,?,?,?,?,?,?,?,?,?)';
        }
        $saved=$db->all($sql,$params);
        if($db->error()||count($saved)!==1)throw new RuntimeException('No se pudo guardar el parte. No se aplicaron cambios.');
        $commitAttempted=true;
        if(!$db->execute('COMMIT TRANSACTION'))throw new RuntimeException('No se pudo confirmar el guardado.');
        if($oldKey!==''&&$oldKey!==$attachment['key'])pfa_remove_old($oldKey);
        return ['id'=>(int)ns_value($saved[0],'ID'),'version'=>(int)ns_value($saved[0],'VERSION'),'week'=>$row['SEMANA_DESDE']];
    }catch(Throwable $e){$db->execute('IF @@TRANCOUNT>0 ROLLBACK TRANSACTION');if($staged&&!$commitAttempted)@unlink($staged['path']);if($staged&&$commitAttempted)error_log('CLEAR Reporte de Pozos: confirmar estado SQL antes de limpiar un adjunto tras fallo de COMMIT.');throw $e;}
}
