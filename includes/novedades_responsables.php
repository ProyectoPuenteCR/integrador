<?php
require_once __DIR__.'/pumpoff_catalogo.php';

function ngr_types(): array {
    return ['JEFE_PRODUCCION'=>'Jefe de producción','SUPERVISOR'=>'Supervisor','PLATAFORMA'=>'Usuario de plataforma','GUARDADO'=>'Responsable guardado','LEGADO'=>'Responsable anterior'];
}
function ngr_names(array $values): array {
    $out=[];foreach($values as $value){$value=trim((string)$value);if($value!=='')$out[pf_key($value)]=$value;}
    $out=array_values($out);natcasesort($out);return array_values($out);
}
function ngr_catalog($db): array {
    $out=pfc_catalog($db,true);$pairs=[];
    foreach($out['batteries'] as &$row){
        // El maestro instalado originalmente denomina JEFE_ZONA a este vínculo.
        $row['JEFE_ORIGEN']=$row['JEFE_PRODUCCION']!==''?'JEFE_PRODUCCION':'JEFE_ZONA';
        if($row['JEFE_PRODUCCION']==='')$row['JEFE_PRODUCCION']=$row['JEFE_ZONA'];
        if($row['SUPERVISOR']!==''&&$row['JEFE_PRODUCCION']!=='')$pairs[]=['supervisor'=>$row['SUPERVISOR'],'jefe'=>$row['JEFE_PRODUCCION'],'zona'=>$row['ZONA']];
    }unset($row);
    $out['pairs']=$pairs;$out['people']=['JEFE_PRODUCCION'=>ngr_names(array_column($pairs,'jefe')),'SUPERVISOR'=>ngr_names(array_column($pairs,'supervisor')),'PLATAFORMA'=>[],'GUARDADO'=>[]];
    $meta=$db->all("SELECT s.name ESQUEMA,t.name TABLA,c.name COLUMNA FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id JOIN sys.columns c ON c.object_id=t.object_id WHERE t.object_id IN (OBJECT_ID(N'dbo.FIXALARMS_USR'),OBJECT_ID(N'dbo.CLEAR_USER_ACCESS'),OBJECT_ID(N'dbo.CLEAR_NS_RESPONSABLES'))");
    if($db->error())throw new RuntimeException('No se pudo leer el catálogo de responsables. Revisá los permisos SQL.');
    $cols=[];foreach($meta as $m)$cols[ns_value($m,'TABLA')][strtoupper(ns_value($m,'COLUMNA'))]=true;
    if(isset($cols['FIXALARMS_USR']['USUARIO'],$cols['CLEAR_USER_ACCESS']['USUARIO'],$cols['CLEAR_USER_ACCESS']['ACTIVO'])){
        // Una sola lectura: nunca contraseñas, perfiles ni una consulta por usuario.
        $users=$db->all("SELECT DISTINCT TOP (2001) U.USUARIO FROM dbo.FIXALARMS_USR U LEFT JOIN dbo.CLEAR_USER_ACCESS A ON A.USUARIO COLLATE DATABASE_DEFAULT=U.USUARIO COLLATE DATABASE_DEFAULT WHERE COALESCE(A.ACTIVO,1)=1 ORDER BY U.USUARIO");
        if($db->error())throw new RuntimeException('No se pudieron leer los usuarios activos.');
        if(count($users)>2000)$out['warnings'][]='Hay más de 2.000 usuarios. No se utiliza una lista incompleta.';
        else $out['people']['PLATAFORMA']=ngr_names(array_column($users,'USUARIO'));
    }else $out['warnings'][]='No está disponible el listado de usuarios activos; revisá los permisos sobre FIXALARMS_USR y CLEAR_USER_ACCESS.';
    $out['saved_ready']=isset($cols['CLEAR_NS_RESPONSABLES']['NOMBRE'],$cols['CLEAR_NS_RESPONSABLES']['ACTIVO']);
    if($out['saved_ready']){
        $saved=$db->all('SELECT TOP (2001) NOMBRE FROM dbo.CLEAR_NS_RESPONSABLES WHERE ACTIVO=1 ORDER BY NOMBRE');
        if($db->error())throw new RuntimeException('No se pudieron leer los responsables guardados.');
        if(count($saved)>2000){$out['saved_ready']=false;$out['warnings'][]='El catálogo excede 2.000 responsables.';}
        else $out['people']['GUARDADO']=ngr_names(array_column($saved,'NOMBRE'));
    }else $out['warnings'][]='Ejecutá SQL/CLEAR_NOVEDADES_GESTION.sql para guardar nuevos responsables.';
    return $out;
}
function ngr_add($db,array $input,string $user): array {
    if(!pfp_can_create())throw new RuntimeException('No tenés permiso para agregar responsables.');
    $name=ng_text($input['NOMBRE']??'',200,true);
    if(!ng_ready($db))throw new RuntimeException('Ejecutá SQL/CLEAR_NOVEDADES_GESTION.sql antes de agregar responsables.');
    if(!$db->execute("SET XACT_ABORT ON; BEGIN TRANSACTION; DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=N'CLEAR_NS_RESPONSABLES_GUARDAR',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=0; IF @r<0 BEGIN ROLLBACK TRANSACTION; THROW 51000,'Otro guardado en curso.',1; END;")){$db->execute('IF @@TRANCOUNT>0 ROLLBACK TRANSACTION');throw new RuntimeException('Hay otro guardado en curso. Reintentá.');}
    try{
        $existing=$db->all('SELECT ID,NOMBRE,ACTIVO FROM dbo.CLEAR_NS_RESPONSABLES WITH (UPDLOCK,HOLDLOCK) WHERE NOMBRE=?',[$name]);
        if($db->error())throw new RuntimeException('No se pudo comprobar el responsable.');
        if($existing){
            if(!(int)ns_value($existing[0],'ACTIVO'))throw new RuntimeException('Ese responsable está desactivado. Consultá al administrador.');
            $result=['id'=>(int)ns_value($existing[0],'ID'),'nombre'=>(string)ns_value($existing[0],'NOMBRE'),'existing'=>true];
        }else{
            $count=$db->scalar('SELECT COUNT(*) FROM dbo.CLEAR_NS_RESPONSABLES WHERE ACTIVO=1');
            if($db->error()||$count===null||(int)$count>=2000)throw new RuntimeException('No se puede agregar: revisá el catálogo y su límite de 2.000 responsables.');
            $rows=$db->all('INSERT INTO dbo.CLEAR_NS_RESPONSABLES(NOMBRE,USUARIO_ALTA) OUTPUT INSERTED.ID,INSERTED.NOMBRE VALUES(?,?)',[$name,$user]);
            if($db->error()||count($rows)!==1)throw new RuntimeException('No se pudo guardar el responsable.');
            $result=['id'=>(int)ns_value($rows[0],'ID'),'nombre'=>(string)ns_value($rows[0],'NOMBRE'),'existing'=>false];
        }
        if(!$db->execute('COMMIT TRANSACTION'))throw new RuntimeException('No se confirmó el guardado. Actualizá las listas antes de reintentar.');
        return $result;
    }catch(Throwable $e){$db->execute('IF @@TRANCOUNT>0 ROLLBACK TRANSACTION');throw $e;}
}
function ngr_supervisors($value): array {
    if(!is_array($value)||count($value)>30)throw new RuntimeException('Elegí hasta 30 supervisores.');
    $out=[];foreach($value as $v)$out[]=ng_text($v,200,true);return ngr_names($out);
}
function ngr_assignment_unchanged(array $row,array $old): bool {
    $same=true;
    foreach(['ZONA','BATERIA','SUPERVISOR','JEFE_PRODUCCION','RESPONSABLE','RESPONSABLE_TIPO'] as $f){$before=(string)(ns_value($old,$f)??'');if($f==='RESPONSABLE_TIPO'&&$row['TIPO']==='REQUERIMIENTO'&&$before==='')$before='LEGADO';if((string)($row[$f]??'')!==$before)$same=false;}
    $previous=json_decode((string)(ns_value($old,'SUPERVISORES_JSON')??'[]'),true);
    return $old&&$same&&ngr_names(is_array($previous)?$previous:[])===$row['SUPERVISORES'];
}
function ngr_resolve(array $row,array $old,array $catalog): array {
    if(ngr_assignment_unchanged($row,$old))return $row;
    // Los datos anteriores se conservan al editar observaciones; asignaciones nuevas se validan.
    $pairs=$catalog['pairs'];
    if($row['TIPO']==='AUDITORIA'){
        foreach($pairs as $pair)if($pair['zona']===$row['ZONA']&&$pair['supervisor']===$row['SUPERVISOR']&&$pair['jefe']===$row['JEFE_PRODUCCION'])return $row;
        throw new RuntimeException('El supervisor y el jefe no corresponden a la zona elegida. Actualizá las listas o el maestro de Supervisores de instalaciones.');
    }
    $kind=$row['RESPONSABLE_TIPO'];
    if(!in_array($row['RESPONSABLE'],$catalog['people'][$kind]??[],true))throw new RuntimeException('Elegí un responsable del catálogo o agregalo con Guardar responsable.');
    if($kind==='SUPERVISOR'){
        foreach($pairs as $pair)if($pair['supervisor']===$row['RESPONSABLE']&&$pair['jefe']===$row['JEFE_PRODUCCION'])return $row;
        throw new RuntimeException('Elegí uno de los jefes relacionados con ese supervisor.');
    }
    if($kind==='JEFE_PRODUCCION')foreach($row['SUPERVISORES'] as $supervisor){
        $valid=false;foreach($pairs as $pair)if($pair['jefe']===$row['RESPONSABLE']&&$pair['supervisor']===$supervisor)$valid=true;
        if(!$valid)throw new RuntimeException('Uno de los supervisores no pertenece al jefe de producción elegido.');
    }
    return $row;
}
