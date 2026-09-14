<?php
require_once __DIR__.'/pumpoff_partes.php';

function rp_store_batch($db,array $inputs,DateTimeImmutable $now,string $user): array {
    if(!pfp_can_create())throw new RuntimeException('No tenés permiso para agregar partes.');
    if(!$inputs||count($inputs)>100)throw new RuntimeException('Seleccioná entre 1 y 100 pozos por lote.');
    $rows=[];$seen=[];$week='';
    foreach($inputs as $input){
        if(!is_array($input))throw new RuntimeException('Hay un registro de pozo no válido.');
        $row=pfp_validate($input,$now);
        if($row['ID']||$row['VERSION'])throw new RuntimeException('El lote agrega partes nuevos. Usá Editar para los existentes.');
        if($week!==''&&$week!==$row['SEMANA_DESDE'])throw new RuntimeException('Todos los pozos deben pertenecer a la misma semana.');
        $week=$row['SEMANA_DESDE'];$key=pf_key($row['POZO']);
        if(isset($seen[$key]))throw new RuntimeException('El pozo '.$row['POZO'].' está repetido en el lote.');
        $seen[$key]=true;$rows[]=$row;
    }
    if(!pfp_ready($db))throw new RuntimeException('Falta instalar la tabla de partes.');
    if(!pfp_details_ready($db))throw new RuntimeException('Ejecutá SQL/CLEAR_REPORTE_POZOS_OBSERVACIONES_ADJUNTO.sql antes de guardar.');
    if(!$db->execute("SET XACT_ABORT ON; BEGIN TRANSACTION; DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=N'CLEAR_PUMPOFF_PARTES_GUARDAR',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=0; IF @r<0 BEGIN ROLLBACK TRANSACTION; THROW 51000,'Otro usuario esta guardando un parte.',1; END;")){
        $db->execute('IF @@TRANCOUNT>0 ROLLBACK TRANSACTION');throw new RuntimeException('Hay otro guardado en curso. Reintentá sin cerrar el lote.');
    }
    try{
        $existing=$db->all('SELECT POZO FROM dbo.CLEAR_PUMPOFF_PARTES WITH (UPDLOCK,HOLDLOCK) WHERE SEMANA_DESDE=CONVERT(date,?,23)',[$week]);
        if($db->error())throw new RuntimeException('No se pudieron comprobar los partes de la semana.');
        $duplicates=[];foreach($existing as $row)if(isset($seen[pf_key(ns_value($row,'POZO'))]))$duplicates[]=nm_text(ns_value($row,'POZO'));
        if($duplicates)throw new RuntimeException('Ya tienen parte en esa semana: '.implode(', ',array_slice($duplicates,0,5)).'. Quitalos del lote o editá los existentes. No se guardó ningún pozo.');
        if(count($existing)+count($rows)>1000)throw new RuntimeException('El lote supera el límite de 1.000 pozos en la semana.');
        $values=[];$params=[];
        foreach($rows as $row){
            $values[]='(CONVERT(date,?,23),?,?,?,?,?,?,?,?,?,?)';
            foreach(['SEMANA_DESDE','ZONA','SUPERVISOR','JEFE_PRODUCCION','BATERIA','POZO','TAG','ESTADO','OBSERVACIONES'] as $field)$params[]=$row[$field];
            $params[]=$user;$params[]=$user;
        }
        $saved=$db->all('INSERT INTO dbo.CLEAR_PUMPOFF_PARTES(SEMANA_DESDE,ZONA,SUPERVISOR,JEFE_PRODUCCION,BATERIA,POZO,TAG,ESTADO,OBSERVACIONES,USUARIO_CARGA,USUARIO_MODIFICACION) OUTPUT INSERTED.ID,INSERTED.POZO,INSERTED.VERSION VALUES '.implode(',',$values),$params);
        if($db->error()||count($saved)!==count($rows))throw new RuntimeException('No se pudo guardar el lote completo. No se aplicaron cambios.');
        if(!$db->execute('COMMIT TRANSACTION'))throw new RuntimeException('No se pudo confirmar el guardado del lote.');
        return ['week'=>$week,'count'=>count($rows)];
    }catch(Throwable $e){$db->execute('IF @@TRANCOUNT>0 ROLLBACK TRANSACTION');throw $e;}
}
