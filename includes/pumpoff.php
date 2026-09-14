<?php
require_once __DIR__.'/novedades_monitoreo.php';

function pf_columns(): array {
    return ['SEMANA'=>'SEMANA','ZONA'=>'ZONA','SUPERVISOR'=>'SUPERVISOR',
        'JEFE_PRODUCCION'=>'JEFE DE PRODUCCIÓN','BATERIA'=>'BATERÍA','POZO'=>'POZO','TAG'=>'TAG','ESTADO'=>'ESTADO'];
}
function pf_key($value): string {
    return strtoupper(strtr(nm_text($value),['á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']));
}
function pf_config(): array {
    $config=require dirname(__DIR__).'/pumpoff_config.example.php';
    $path=dirname(__DIR__).'/pumpoff_config.php';
    if(is_file($path)) {
        $local=require $path;
        if(!is_array($local))throw new RuntimeException('pumpoff_config.php debe devolver una configuración válida.');
        $config=array_replace($config,$local);
    }
    if(!in_array($config['scope'],['pending','all_bm','list'],true))throw new RuntimeException('El alcance PUMP OFF no es válido.');
    if(!is_array($config['wells'])||!is_array($config['rules'])||!is_array($config['zones'])||count($config['zones'])!==3)throw new RuntimeException('Revisá el listado, las reglas y las tres zonas de PUMP OFF.');
    foreach($config['rules'] as $rule) {
        if(!is_array($rule)||!in_array($rule['state']??'',['MANUAL','AUTOMATICO','HOA'],true)||empty($rule['when'])||!is_array($rule['when']))throw new RuntimeException('Hay una regla de estado incompleta.');
        foreach($rule['when'] as $field=>$value)if(!in_array($field,['YT:LLAVE','YT:CONTROL','YT:LLAVE-AUTO','YT:DEVICE'],true)||!is_scalar($value)||nm_text($value)==='')throw new RuntimeException('Una regla contiene una señal o un valor no admitido.');
    }
    return $config;
}
function pf_state(array $row,array $rules): string {
    $matches=[];
    foreach($rules as $rule) {
        $match=true;
        foreach($rule['when'] as $field=>$value)if(pf_key($row[$field]??'')!==pf_key($value)){$match=false;break;}
        if($match)$matches[$rule['state']]=true;
    }
    return count($matches)===1?(string)array_key_first($matches):'SIN CLASIFICAR';
}
function pf_project(array $source,array $config,DateTimeImmutable $now): array {
    $out=['rows'=>[],'configured'=>$config['scope']!=='pending','missing'=>[],'warnings'=>[],'can_save'=>false];
    if(!$out['configured'])return $out;
    $listed=[];foreach($config['wells'] as $well=>$info) {
        if(!is_array($info)||pf_key($well)==='')throw new RuntimeException('El listado PUMP OFF contiene un pozo no válido.');
        $listed[pf_key($well)]=$info;
    }
    if($config['scope']==='list'&&!$listed){$out['warnings'][]='El listado confirmado de PUMP OFF está vacío.';return $out;}
    $week=ns_week_for_date($now)['start'];$seen=[];
    foreach($source['rows'] as $raw) {
        $key=pf_key($raw['POZO']);if($config['scope']==='list'&&!isset($listed[$key]))continue;
        $seen[$key]=true;$info=$listed[$key]??[];$zone=nm_text($raw['ZONA']??'');$mapped=[];
        foreach($config['zones'] as $name=>$aliases)foreach((array)$aliases as $alias)if(pf_key($zone)===pf_key($alias))$mapped[$name]=true;
        if(count($mapped)===1)$zone=(string)array_key_first($mapped);
        elseif(count($mapped)>1)$zone='Zona ambigua';
        $out['rows'][]=['SEMANA_DESDE'=>$week->format('Y-m-d'),'SEMANA'=>ns_week_label($week,false),
            'ZONA'=>$zone?:'Sin zona','SUPERVISOR'=>nm_text($raw['SUPERVISOR']??''),
            'JEFE_PRODUCCION'=>nm_text($info['jefe_produccion']??(!empty($config['use_zone_chief_as_production_chief'])?($raw['JEFE_ZONA']??''):'')),
            'BATERIA'=>$raw['BATERIA'],'POZO'=>$raw['POZO'],'TAG'=>nm_text($info['tag']??($raw['TAG']??'')),
            'ESTADO'=>pf_state($raw,$config['rules']),'FECHA_FUENTE'=>nm_text($raw['Fecha']??''),
            'CAPTURADO_EN'=>$source['queried_at']];
    }
    if($config['scope']==='list')$out['missing']=array_values(array_diff(array_keys($listed),array_keys($seen)));
    if($out['missing'])$out['warnings'][]=count($out['missing']).' pozos del listado no tienen una lectura única disponible. No se puede guardar una semana incompleta.';
    $out['can_save']=!empty($out['rows'])&&empty($out['missing'])&&empty($source['error'])&&empty($source['duplicates'])&&empty($source['unnamed']);
    return $out;
}
function pf_history($db,array $period): array {
    $out=['ready'=>false,'rows'=>[],'error'=>''];
    if(!$db||!$db->ok())return $out;
    $out['ready']=(int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_PUMPOFF_SEMANAL',N'U') IS NULL THEN 0 ELSE 1 END")===1;
    if(!$out['ready'])return $out;
    $rows=$db->all("SELECT TOP (12001) CONVERT(varchar(10),SEMANA_DESDE,23) SEMANA_DESDE,ZONA,SUPERVISOR,JEFE_PRODUCCION,BATERIA,POZO,TAG,ESTADO,FECHA_FUENTE,CONVERT(varchar(19),CAPTURADO_EN,120) CAPTURADO_EN FROM dbo.CLEAR_PUMPOFF_SEMANAL WHERE SEMANA_DESDE>=CONVERT(date,?,23) AND SEMANA_DESDE<=CONVERT(date,?,23) ORDER BY SEMANA_DESDE,POZO",[$period['from_value'],$period['to_value']]);
    if(!$rows&&$db->error()){$out['error']='No se pudo leer el histórico semanal.';return $out;}
    if(count($rows)>12000){$out['error']='El histórico excede el límite de esta vista; acortá el período.';return $out;}
    foreach($rows as $raw) {
        $row=[];foreach(['SEMANA_DESDE','ZONA','SUPERVISOR','JEFE_PRODUCCION','BATERIA','POZO','TAG','ESTADO','FECHA_FUENTE','CAPTURADO_EN'] as $field)$row[$field]=nm_text(ns_value($raw,$field));
        $start=ns_parse_date($row['SEMANA_DESDE']);if(!$start)continue;
        $row['SEMANA']=ns_week_label($start,false);$out['rows'][]=$row;
    }
    return $out;
}
function pf_store_week($db,array $snapshot,DateTimeImmutable $now,string $user): void {
    $week=ns_week_for_date($now)['start']->format('Y-m-d');$rows=$snapshot['rows']??[];
    if(($snapshot['week']??'')!==$week||!$rows||count($rows)>1000)throw new RuntimeException('La lectura no corresponde a esta semana o está vacía. Actualizá la pantalla.');
    $limits=['ZONA'=>150,'SUPERVISOR'=>200,'JEFE_PRODUCCION'=>200,'BATERIA'=>255,'POZO'=>180,'TAG'=>255,'ESTADO'=>20,'FECHA_FUENTE'=>4000];
    $seen=[];
    foreach($rows as $row) {
        foreach($limits as $field=>$max)if(!isset($row[$field])||!is_string($row[$field])||preg_match_all('/./us',$row[$field])>$max)throw new RuntimeException('Un dato del cierre excede el tamaño admitido. No se guardó una lectura parcial.');
        $key=pf_key($row['POZO']);if($key===''||isset($seen[$key])||!in_array($row['ESTADO'],['MANUAL','AUTOMATICO','HOA','SIN CLASIFICAR'],true))throw new RuntimeException('El cierre contiene pozos o estados no válidos.');$seen[$key]=true;
    }
    // Solo se reemplaza la lectura de la semana ACTUAL en esta tabla nueva.
    // Nunca se escriben ni limpian BM_RTQP_diarios, TECCS o Jobs.
    if(!$db->execute("SET XACT_ABORT ON; BEGIN TRANSACTION; DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=N'CLEAR_PUMPOFF_GUARDAR',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=0; IF @r<0 BEGIN ROLLBACK TRANSACTION; THROW 51000,'Otro usuario esta guardando PUMP OFF.',1; END;")){$db->execute('IF @@TRANCOUNT>0 ROLLBACK TRANSACTION');throw new RuntimeException('No se pudo iniciar el guardado. Reintentá cuando no haya otra captura.');}
    try {
        // No permitir que una pestaña vieja reemplace una lectura más reciente.
        $latest=$db->scalar('SELECT CONVERT(varchar(19),MAX(CAPTURADO_EN),120) FROM dbo.CLEAR_PUMPOFF_SEMANAL WHERE SEMANA_DESDE=CONVERT(date,?,23)',[$week]);
        if($db->error())throw new RuntimeException('No se pudo verificar la captura anterior.');
        if($latest && (string)$latest>(string)$snapshot['captured_at'])throw new RuntimeException('Ya existe una lectura más reciente. Actualizá la pantalla antes de guardar.');
        if(!$db->execute('DELETE FROM dbo.CLEAR_PUMPOFF_SEMANAL WHERE SEMANA_DESDE=CONVERT(date,?,23)',[$week]))throw new RuntimeException('No se pudo preparar la semana.');
        foreach(array_chunk($rows,50) as $chunk) {
            $values=[];$params=[];
            foreach($chunk as $row) {
                $values[]='(CONVERT(date,?,23),?,?,?,?,?,?,?,?,CONVERT(datetime2(0),?,120),?)';
                $params[]=$week;foreach(array_keys($limits) as $field)$params[]=$row[$field];$params[]=$snapshot['captured_at'];$params[]=$user;
            }
            if(!$db->execute('INSERT INTO dbo.CLEAR_PUMPOFF_SEMANAL(SEMANA_DESDE,ZONA,SUPERVISOR,JEFE_PRODUCCION,BATERIA,POZO,TAG,ESTADO,FECHA_FUENTE,CAPTURADO_EN,USUARIO) VALUES '.implode(',',$values),$params))throw new RuntimeException('No se pudo guardar el lote.');
        }
        if(!$db->execute('COMMIT TRANSACTION'))throw new RuntimeException('No se pudo confirmar el guardado.');
    }catch(Throwable $e){$db->execute('IF @@TRANCOUNT>0 ROLLBACK TRANSACTION');throw $e;}
}
