<?php
require_once __DIR__.'/pumpoff_partes.php';
require_once __DIR__.'/novedades_responsables.php';
function ng_spec(string $type): array {
    if($type==='AUDITORIA')return ['key'=>'novedades_semanales_auditoria','title'=>'Auditoría de instalaciones','single'=>'auditoría','states'=>['PENDIENTE','EN PROCESO','FINALIZADO'],'columns'=>['ID'=>'N°','FECHA'=>'FECHA','ZONA'=>'ZONA','BATERIA'=>'BATERÍA','SUPERVISOR'=>'SUPERVISOR','JEFE_PRODUCCION'=>'JEFE DE PRODUCCIÓN','ESTADO'=>'ESTADO','FECHA_CIERRE'=>'CIERRE','OBSERVACIONES'=>'OBSERVACIONES','ADJUNTO_NOMBRE'=>'ADJUNTO']];
    if($type==='REQUERIMIENTO')return ['key'=>'novedades_semanales_requerimientos','title'=>'Requerimientos de sala de control','single'=>'requerimiento','states'=>['PENDIENTE','INICIADO','FINALIZADO'],'columns'=>['ID'=>'N°','FECHA'=>'FECHA','REQUERIMIENTO'=>'REQUERIMIENTO','RESPONSABLE_CLASE'=>'TIPO DE RESPONSABLE','RESPONSABLE'=>'RESPONSABLE','JEFE_PRODUCCION'=>'JEFE DE PRODUCCIÓN','SUPERVISORES_TEXTO'=>'SUPERVISORES','ESTADO'=>'ESTADO','FECHA_CIERRE'=>'CIERRE','OBSERVACIONES'=>'OBSERVACIÓN','ADJUNTO_NOMBRE'=>'ADJUNTO']];
    throw new RuntimeException('Pantalla no válida.');
}
function ng_ready($db): bool {
    return $db&&$db->ok()&&(int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_NS_GESTIONES',N'U') IS NOT NULL AND OBJECT_ID(N'dbo.CLEAR_NS_GESTIONES_HISTORIAL',N'U') IS NOT NULL AND OBJECT_ID(N'dbo.CLEAR_NS_RESPONSABLES',N'U') IS NOT NULL AND COL_LENGTH(N'dbo.CLEAR_NS_GESTIONES',N'RESPONSABLE_TIPO') IS NOT NULL AND COL_LENGTH(N'dbo.CLEAR_NS_GESTIONES',N'SUPERVISORES_JSON') IS NOT NULL THEN 1 ELSE 0 END")===1;
}
function ng_text($value,int $max,bool $required=false,bool $lines=false): string {
    if(!is_string($value)||!preg_match('//u',$value))throw new RuntimeException('Hay un campo de texto inválido.');
    $value=trim(str_replace(["\r\n","\r"],"\n",$value));
    $length=preg_match_all('/[\s\S]/u',$value)+preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$value);
    if(($required&&$value==='')||$length>$max||preg_match($lines?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$value))throw new RuntimeException('Revisá los campos: faltan datos obligatorios o se superó la longitud permitida.');
    return $value;
}
function ng_date($value,string $label): string {
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))throw new RuntimeException('Revisá '.$label.'.');
    $date=ns_parse_date($value);if(!$date||$date->format('Y-m-d')!==$value||$value<'2000-01-01')throw new RuntimeException('Revisá '.$label.'.');return $value;
}
function ng_id($value,bool $zero=true): int {
    if((!is_int($value)&&!(is_string($value)&&ctype_digit($value)))||(float)$value<($zero?0:1)||(float)$value>2147483647)throw new RuntimeException('Identificador inválido.');return (int)$value;
}
function ng_validate(array $input,string $type,DateTimeImmutable $now): array {
    $spec=ng_spec($type);$row=['TIPO'=>$type,'ID'=>ng_id($input['ID']??0),'VERSION'=>ng_id($input['VERSION']??0)];
    if(($row['ID']===0)!==($row['VERSION']===0))throw new RuntimeException('Versión inválida. Recargá la pantalla.');
    $row['FECHA']=ng_date($input['FECHA']??'','la fecha');if($row['FECHA']>$now->format('Y-m-d'))throw new RuntimeException('La fecha no puede ser futura.');
    foreach(['ZONA'=>150,'BATERIA'=>255,'SUPERVISOR'=>200,'JEFE_PRODUCCION'=>200,'REQUERIMIENTO'=>1000,'RESPONSABLE'=>200,'ESTADO'=>20,'OBSERVACIONES'=>2000,'MOTIVO_REAPERTURA'=>500] as $field=>$max){
        $required=$field==='ESTADO'||($type==='AUDITORIA'&&in_array($field,['ZONA','BATERIA','SUPERVISOR','JEFE_PRODUCCION'],true))||($type==='REQUERIMIENTO'&&in_array($field,['REQUERIMIENTO','RESPONSABLE'],true));
        $row[$field]=ng_text($input[$field]??'',$max,$required,in_array($field,['OBSERVACIONES','REQUERIMIENTO','MOTIVO_REAPERTURA'],true));
    }
    if(!in_array($row['ESTADO'],$spec['states'],true))throw new RuntimeException('Estado no admitido en esta pantalla.');
    if($type==='AUDITORIA'&&!in_array($row['ZONA'],pfp_zones(),true))throw new RuntimeException('Elegí una zona válida.');
    $row['RESPONSABLE_TIPO']='';$row['SUPERVISORES']=[];
    if($type==='AUDITORIA'){$row['REQUERIMIENTO']='';$row['RESPONSABLE']='';}
    else{
        $row['ZONA']='';$row['BATERIA']='';
        $row['RESPONSABLE_TIPO']=ng_text($input['RESPONSABLE_TIPO']??'LEGADO',30,true);
        if(!isset(ngr_types()[$row['RESPONSABLE_TIPO']]))throw new RuntimeException('Elegí un tipo de responsable válido.');
        $row['SUPERVISORES']=ngr_supervisors($input['SUPERVISORES']??[]);
        if($row['RESPONSABLE_TIPO']==='SUPERVISOR'){$row['SUPERVISOR']=$row['RESPONSABLE'];$row['SUPERVISORES']=[$row['RESPONSABLE']];}
        elseif($row['RESPONSABLE_TIPO']==='JEFE_PRODUCCION'){$row['JEFE_PRODUCCION']=$row['RESPONSABLE'];$row['SUPERVISOR']=$row['SUPERVISORES'][0]??'';}
        else{$row['SUPERVISOR']='';$row['JEFE_PRODUCCION']='';$row['SUPERVISORES']=[];}
    }
    $row['SUPERVISORES_JSON']=nm_json($row['SUPERVISORES']);
    $row['FECHA_CIERRE']='';
    if($row['ESTADO']==='FINALIZADO'){
        $row['FECHA_CIERRE']=ng_date($input['FECHA_CIERRE']??'','la fecha de cierre');
        if($row['FECHA_CIERRE']<$row['FECHA']||$row['FECHA_CIERRE']>$now->format('Y-m-d'))throw new RuntimeException('El cierre debe estar entre la fecha de alta y hoy.');
    }
    $remove=$input['QUITAR_ADJUNTO']??'0';if(!in_array($remove,[0,1,'0','1',true,false],true))throw new RuntimeException('Opción de adjunto inválida.');$row['QUITAR_ADJUNTO']=(bool)$remove;
    $row['SOLICITUD']=ng_text($input['SOLICITUD']??'',32,true);if(!preg_match('/^[a-f0-9]{32}$/D',$row['SOLICITUD']))throw new RuntimeException('Identificador de guardado inválido. Recargá la pantalla.');
    return $row;
}
function ng_select(): string {
    return "ID,TIPO,VERSION,SOLICITUD,CONVERT(varchar(10),FECHA,23) FECHA,ZONA,BATERIA,SUPERVISOR,JEFE_PRODUCCION,REQUERIMIENTO,RESPONSABLE,RESPONSABLE_TIPO,SUPERVISORES_JSON,ESTADO,CONVERT(varchar(10),FECHA_CIERRE,23) FECHA_CIERRE,CONVERT(varchar(10),FECHA_PRIMER_CIERRE,23) FECHA_PRIMER_CIERRE,OBSERVACIONES,ADJUNTO_CLAVE,ADJUNTO_NOMBRE,ADJUNTO_BYTES,USUARIO_CARGA,USUARIO_MODIFICACION,CONVERT(varchar(19),FECHA_MODIFICACION,120) FECHA_MODIFICACION";
}
function ng_public(array $raw): array {
    $row=[];foreach(['TIPO','FECHA','ZONA','BATERIA','SUPERVISOR','JEFE_PRODUCCION','REQUERIMIENTO','RESPONSABLE','ESTADO','FECHA_CIERRE','FECHA_PRIMER_CIERRE','OBSERVACIONES','ADJUNTO_NOMBRE','USUARIO_CARGA','USUARIO_MODIFICACION','FECHA_MODIFICACION'] as $key)$row[$key]=(string)(ns_value($raw,$key)??'');
    foreach(['ID','VERSION','ADJUNTO_BYTES'] as $key)$row[$key]=(int)ns_value($raw,$key);
    $row['RESPONSABLE_TIPO']=(string)(ns_value($raw,'RESPONSABLE_TIPO')?:($row['TIPO']==='REQUERIMIENTO'?'LEGADO':''));
    $row['RESPONSABLE_CLASE']=ngr_types()[$row['RESPONSABLE_TIPO']]??'';
    $list=json_decode((string)(ns_value($raw,'SUPERVISORES_JSON')??'[]'),true);$row['SUPERVISORES']=is_array($list)?$list:[];
    $row['SUPERVISORES_TEXTO']=implode('; ',$row['SUPERVISORES']);
    $row['CAN_EDIT']=pfp_can_edit($row,(string)auth_user());return $row;
}
function ng_list($db,string $type,string $from,string $to): array {
    if(!ng_ready($db))return ['ready'=>false,'rows'=>[],'error'=>''];
    // Incluir abiertos y registros con alta o cierre en el período; nunca RTQP.
    $rows=$db->all('SELECT TOP (2001) '.ng_select()." FROM dbo.CLEAR_NS_GESTIONES WHERE TIPO=? AND FECHA<=CONVERT(date,?,23) AND (ESTADO<>'FINALIZADO' OR FECHA>=CONVERT(date,?,23) OR FECHA_CIERRE>=CONVERT(date,?,23) OR FECHA_PRIMER_CIERRE>=CONVERT(date,?,23)) ORDER BY FECHA DESC,ID DESC",[$type,$to,$from,$from,$from]);
    if($db->error())return ['ready'=>true,'rows'=>[],'error'=>'No se pudieron leer los registros. Revisá la instalación y los permisos SQL.'];
    if(count($rows)>2000)return ['ready'=>true,'rows'=>[],'error'=>'Más de 2.000 registros en el período. Acortá las fechas. No se muestran totales incompletos.'];
    return ['ready'=>true,'rows'=>array_map('ng_public',$rows),'error'=>''];
}
function ng_history($db,string $type,int $id): array {
    $rows=$db->all("SELECT TOP (101) H.VERSION,H.ESTADO_ANTERIOR,H.ESTADO_NUEVO,H.USUARIO,CONVERT(varchar(19),H.FECHA_EVENTO,120) FECHA_EVENTO,H.MOTIVO,H.ANTES_JSON,H.DESPUES_JSON FROM dbo.CLEAR_NS_GESTIONES_HISTORIAL H JOIN dbo.CLEAR_NS_GESTIONES G ON G.ID=H.GESTION_ID WHERE G.TIPO=? AND G.ID=? ORDER BY H.VERSION DESC",[$type,$id]);
    if($db->error())throw new RuntimeException('No se pudo leer el historial.');return ['rows'=>array_slice($rows,0,100),'more'=>count($rows)>100];
}
function ng_store($db,string $type,array $input,DateTimeImmutable $now,string $user,?array $upload=null): array {
    $row=ng_validate($input,$type,$now);if(!$row['ID']&&!pfp_can_create())throw new RuntimeException('No tenés permiso para agregar registros.');
    if(!ng_ready($db))throw new RuntimeException('Ejecutá SQL/CLEAR_NOVEDADES_GESTION.sql antes de guardar.');
    $file=pfa_validate_upload($upload);if($file&&$row['QUITAR_ADJUNTO'])throw new RuntimeException('Elegí reemplazar o quitar el archivo, no ambas opciones.');
    $staged=null;$oldKey='';$commitAttempted=false;
    if(!$db->execute("SET XACT_ABORT ON; BEGIN TRANSACTION; DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=N'CLEAR_NS_GESTIONES_GUARDAR',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=0; IF @r<0 BEGIN ROLLBACK TRANSACTION; THROW 51000,'Otro guardado en curso.',1; END;")){$db->execute('IF @@TRANCOUNT>0 ROLLBACK TRANSACTION');throw new RuntimeException('Hay otro guardado en curso. Reintentá sin cerrar el formulario.');}
    try{
        $old=[];$row['FECHA_PRIMER_CIERRE']='';$attachment=['key'=>'','name'=>'','bytes'=>0];
        if($row['ID']){
            $found=$db->all('SELECT '.ng_select().' FROM dbo.CLEAR_NS_GESTIONES WITH (UPDLOCK,HOLDLOCK) WHERE ID=? AND TIPO=?',[$row['ID'],$type]);
            if($db->error()||count($found)!==1)throw new RuntimeException('El registro no está disponible.');$old=$found[0];
            if(!pfp_can_edit($old,$user))throw new RuntimeException('No tenés permiso para editar este registro.');
            if((int)ns_value($old,'VERSION')!==$row['VERSION'])throw new RuntimeException('Otro usuario modificó este registro. Recargá antes de editar.');
            $row['FECHA_PRIMER_CIERRE']=(string)(ns_value($old,'FECHA_PRIMER_CIERRE')??'');
            if($row['FECHA_PRIMER_CIERRE']!==''&&$row['FECHA']>$row['FECHA_PRIMER_CIERRE'])throw new RuntimeException('La fecha de alta no puede ser posterior al primer cierre registrado.');
            if(ns_value($old,'ESTADO')==='FINALIZADO'){
                if($row['ESTADO']!=='FINALIZADO'&&$row['MOTIVO_REAPERTURA']==='')throw new RuntimeException('Indicá el motivo de la reapertura.');
                if($row['ESTADO']==='FINALIZADO'&&$row['FECHA_CIERRE']!==ns_value($old,'FECHA_CIERRE'))throw new RuntimeException('El cierre registrado no cambia al editar observaciones.');
            }
            $oldKey=(string)(ns_value($old,'ADJUNTO_CLAVE')??'');$attachment=['key'=>$oldKey,'name'=>(string)(ns_value($old,'ADJUNTO_NOMBRE')??''),'bytes'=>(int)ns_value($old,'ADJUNTO_BYTES')];
        }else{
            $same=$db->all('SELECT ID,USUARIO_CARGA FROM dbo.CLEAR_NS_GESTIONES WITH (UPDLOCK,HOLDLOCK) WHERE SOLICITUD=? AND TIPO=?',[$row['SOLICITUD'],$type]);
            if($db->error())throw new RuntimeException('No se pudo comprobar el guardado anterior.');
            if($same)throw new RuntimeException('Este formulario ya fue guardado. Recargá la grilla para verlo; no se duplicó.');
        }
        if(!ngr_assignment_unchanged($row,$old))$row=ngr_resolve($row,$old,ngr_catalog($db));
        if($row['ESTADO']==='FINALIZADO'&&$row['FECHA_PRIMER_CIERRE']!==''&&$row['FECHA_CIERRE']<$row['FECHA_PRIMER_CIERRE'])throw new RuntimeException('El nuevo cierre no puede ser anterior al primero.');
        if($row['ESTADO']==='FINALIZADO'&&$row['FECHA_PRIMER_CIERRE']==='')$row['FECHA_PRIMER_CIERRE']=$row['FECHA_CIERRE'];
        if($row['QUITAR_ADJUNTO'])$attachment=['key'=>'','name'=>'','bytes'=>0];
        if($file){$staged=pfa_stage($file);$attachment=$staged;}
        $row['ADJUNTO_NOMBRE']=$attachment['name'];$row['ADJUNTO_BYTES']=$attachment['bytes'];
        $fields=['FECHA','ZONA','BATERIA','SUPERVISOR','JEFE_PRODUCCION','REQUERIMIENTO','RESPONSABLE','ESTADO','OBSERVACIONES'];$params=[];foreach($fields as $field)$params[]=$row[$field];
        $params[]=$row['RESPONSABLE_TIPO'];$params[]=$row['SUPERVISORES_JSON'];$params[]=$row['FECHA_CIERRE'];$params[]=$row['FECHA_PRIMER_CIERRE'];$params[]=$attachment['key'];$params[]=$attachment['name'];$params[]=$attachment['bytes'];$params[]=$user;
        if($row['ID']){
            $params[]=$row['ID'];$params[]=$type;$params[]=$row['VERSION'];
            $sql="UPDATE dbo.CLEAR_NS_GESTIONES SET FECHA=CONVERT(date,?,23),ZONA=?,BATERIA=?,SUPERVISOR=?,JEFE_PRODUCCION=?,REQUERIMIENTO=?,RESPONSABLE=?,ESTADO=?,OBSERVACIONES=?,RESPONSABLE_TIPO=?,SUPERVISORES_JSON=?,FECHA_CIERRE=CONVERT(date,NULLIF(?,''),23),FECHA_PRIMER_CIERRE=CONVERT(date,NULLIF(?,''),23),ADJUNTO_CLAVE=?,ADJUNTO_NOMBRE=?,ADJUNTO_BYTES=?,USUARIO_MODIFICACION=?,FECHA_MODIFICACION=SYSDATETIME(),VERSION=VERSION+1 OUTPUT INSERTED.ID,INSERTED.VERSION WHERE ID=? AND TIPO=? AND VERSION=?";
        }else{
            $params[]=$user;$params[]=$type;$params[]=$row['SOLICITUD'];
            $sql="INSERT INTO dbo.CLEAR_NS_GESTIONES(FECHA,ZONA,BATERIA,SUPERVISOR,JEFE_PRODUCCION,REQUERIMIENTO,RESPONSABLE,ESTADO,OBSERVACIONES,RESPONSABLE_TIPO,SUPERVISORES_JSON,FECHA_CIERRE,FECHA_PRIMER_CIERRE,ADJUNTO_CLAVE,ADJUNTO_NOMBRE,ADJUNTO_BYTES,USUARIO_MODIFICACION,USUARIO_CARGA,TIPO,SOLICITUD) OUTPUT INSERTED.ID,INSERTED.VERSION VALUES(CONVERT(date,?,23),?,?,?,?,?,?,?,?,?,?,CONVERT(date,NULLIF(?,''),23),CONVERT(date,NULLIF(?,''),23),?,?,?,?,?,?,?)";
        }
        $saved=$db->all($sql,$params);if($db->error()||count($saved)!==1)throw new RuntimeException('No se pudo guardar el registro. No se confirmaron cambios.');
        $id=(int)ns_value($saved[0],'ID');$version=(int)ns_value($saved[0],'VERSION');
        $snapshot=function(array $r){$out=[];foreach(['FECHA','ZONA','BATERIA','SUPERVISOR','JEFE_PRODUCCION','REQUERIMIENTO','RESPONSABLE','RESPONSABLE_TIPO','SUPERVISORES_JSON','ESTADO','OBSERVACIONES','FECHA_CIERRE','FECHA_PRIMER_CIERRE','ADJUNTO_NOMBRE'] as $key)$out[$key]=(string)(ns_value($r,$key)??'');return $out;};
        if(!$db->execute('INSERT INTO dbo.CLEAR_NS_GESTIONES_HISTORIAL(GESTION_ID,VERSION,ESTADO_ANTERIOR,ESTADO_NUEVO,USUARIO,MOTIVO,ANTES_JSON,DESPUES_JSON) VALUES(?,?,?,?,?,?,?,?)',[$id,$version,(string)(ns_value($old,'ESTADO')??''),$row['ESTADO'],$user,$row['MOTIVO_REAPERTURA'],nm_json($snapshot($old)),nm_json($snapshot($row))]))throw new RuntimeException('No se pudo guardar el historial. Se revierte el cambio completo.');
        $commitAttempted=true;if(!$db->execute('COMMIT TRANSACTION'))throw new RuntimeException('No se pudo confirmar el guardado. Recargá la grilla antes de reintentar.');
        if($oldKey!==''&&$oldKey!==$attachment['key'])pfa_remove_old($oldKey);
        return ['id'=>$id,'version'=>$version];
    }catch(Throwable $e){$db->execute('IF @@TRANCOUNT>0 ROLLBACK TRANSACTION');if($staged&&!$commitAttempted)@unlink($staged['path']);if($staged&&$commitAttempted)error_log('CLEAR Novedades: revisar referencia SQL antes de limpiar archivo tras COMMIT incierto.');throw $e;}
}
