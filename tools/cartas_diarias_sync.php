<?php
/* Ejecutar con PHP CLI en el servidor IIS. Nunca exponer como endpoint web. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/db.php';
require_once dirname(__DIR__).'/includes/appconfig.php';
require_once dirname(__DIR__).'/includes/well_history.php';
require_once dirname(__DIR__).'/includes/pi_transport.php';
require_once dirname(__DIR__).'/includes/cartas_diarias.php';

$db=clear_db();$run=null;$locked=false;
try{
    if(!$db->ok())throw new RuntimeException('Sin conexión SQL.');
    $tables=cd_query($db,"SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_CARTAS_CARGAS',N'U') IS NOT NULL AND OBJECT_ID(N'dbo.CLEAR_CARTAS_DIARIAS',N'U') IS NOT NULL THEN 1 ELSE 0 END READY");
    if((int)$tables[0]['READY']!==1)throw new RuntimeException('Primero ejecutar SQL/CLEAR_CARTAS_DIARIAS.sql en la base de la plataforma.');
    echo "[1/3] Conexión SQL y tablas del reporte: OK.\n";flush();
    echo "[2/3] Leyendo pozos y baterías de Monitoreo de Pozos (dbo.BM_RTQP)...\n";flush();
    $sourceRows=cd_query($db,'SELECT CONVERT(nvarchar(180),POZO) POZO,CONVERT(nvarchar(255),BATERIA) BATERIA FROM dbo.BM_RTQP ORDER BY POZO');
    $roster=cd_roster($sourceRows);
    if(!$roster||count($roster)>3000)throw new RuntimeException('El listado de Monitoreo de Pozos está vacío o supera el límite de 3000 pozos.');
    echo '[2/3] Pozos de Monitoreo: '.count($roster).".\n";flush();
    $checkTags=in_array('--check-tags',$argv,true);
    if(in_array('--check',$argv,true)||$checkTags){
        if(!$checkTags){
            echo "[3/3] Diagnóstico básico: OK. Fuente exclusiva: dbo.BM_RTQP. Se conservarán todos los pozos en el reporte, tengan o no cartas.\n";
            echo "No se consultó PI ni se modificaron datos. Para revisar tags locales usar --check-tags.\n";
            exit(0);
        }
        echo '[3/3] Verificando tags locales de '.count($roster)." pozos. No se consulta PI.\n";flush();
        $eligible=0;$checked=0;$checkStarted=microtime(true);
        foreach($roster as $well){
            echo '  Revisando '.($checked+1).'/'.count($roster).' · '.$well['well']."...\n";flush();
            $catalog=wh_catalog($db,$well['well']);
            foreach(['TIME:SUP','TIME:FONDO','ZT:SUP-POS','WT:SUP-LOAD','ZT:FONDO-POS','WT:FONDO-LOAD'] as $col)if(!empty($catalog[$col]['available'])){$eligible++;break;}
            $checked++;
        }
        echo 'Conexión SQL y tablas: OK. Pozos de Monitoreo: '.count($roster).'. Con tags de cartas: '.$eligible.'. Sin tags: '.(count($roster)-$eligible).'. Verificación de tags: '.round(microtime(true)-$checkStarted)." segundos. No se consultó PI ni se modificaron datos.\n";exit(0);
    }
    $lock=cd_query($db,"SET NOCOUNT ON; DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=N'CLEAR_CARTAS_DIARIAS_SYNC',@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0; SELECT @r RESULT");
    if((int)$lock[0]['RESULT']<0)throw new RuntimeException('Ya existe una carga de cartas en ejecución.');$locked=true;
    $cut=cd_cutoff(new DateTimeImmutable('now',cd_tz()));
    $last=cd_query($db,"SELECT TOP (1) CONVERT(varchar(36),ID) ID,CONVERT(varchar(19),CORTE,120) CORTE,DETALLE FROM dbo.CLEAR_CARTAS_CARGAS WHERE ESTADO='COMPLETA' ORDER BY CORTE DESC,FIN DESC");
    if($last&&strpos((string)$last[0]['DETALLE'],'Fuente: BM_RTQP;')===0&&$last[0]['CORTE']>=cd_sql_date($cut)&&!in_array('--force',$argv,true)){echo "El corte de las 06:00 ya está completo.\n";exit(0);}
    $previous=[];
    if($last){foreach(cd_query($db,'SELECT POZO,TIPO,CARTA_JSON FROM dbo.CLEAR_CARTAS_DIARIAS WHERE CARGA_ID=?',[$last[0]['ID']]) as $row){$card=json_decode((string)$row['CARTA_JSON'],true);if(cd_has_card($card))$previous[strtoupper($row['POZO'])][$row['TIPO']]=$card;}}
    $cfg=require dirname(__DIR__).'/config.php';$fallback=$cfg['pi']??[];
    $pi=['base'=>config_get('pi_base',$fallback['base']??'https://10.17.40.37/piwebapi'),'ca_bundle'=>config_get('pi_ca_bundle',$fallback['ca_bundle']??'')];
    $user=config_get('pi_user','');$pass=config_get('pi_pass','');
    $auth=($user!==''||$pass!=='')?'Basic '.base64_encode($user.':'.$pass):($fallback['auth']??'');
    $requests=0;$failures=0;$started=microtime(true);
    $request=function($path)use($pi,$auth,&$requests,&$failures,$started){
        if(++$requests>6000||microtime(true)-$started>7200)throw new Exception('La carga superó el presupuesto de consultas o las dos horas de ejecución.');
        usleep(150000); // Una consulta por vez, sin ráfagas paralelas contra PI.
        try{$response=clear_pi_transport($path,$pi,$auth,20);}catch(RuntimeException $e){if(++$failures>=5)throw new Exception('PI no responde: se conserva la carga publicada anterior.');throw $e;}
        if(in_array($response['status'],[401,403],true))throw new Exception('PI rechazó las credenciales. Se conserva la carga anterior.');
        if($response['status']<200||$response['status']>=300){if(++$failures>=5)throw new Exception('PI devolvió errores reiterados. Se conserva la carga anterior.');throw new RuntimeException('Consulta PI fallida (HTTP '.$response['status'].').');}
        $data=json_decode($response['body'],true);if(!is_array($data)){if(++$failures>=5)throw new Exception('PI devolvió respuestas inválidas reiteradas.');throw new RuntimeException('Respuesta PI inválida.');}
        $failures=0;return $data;
    };
    $id=cd_query($db,'SELECT CONVERT(varchar(36),NEWID()) ID');$run=$id[0]['ID'];
    cd_execute($db,"INSERT INTO dbo.CLEAR_CARTAS_CARGAS(ID,CORTE,ESTADO) VALUES(?,CONVERT(datetime2,?,120),'CARGANDO')",[$run,cd_sql_date($cut)]);
    $wells=0;$warnings=0;$valid=0;
    foreach($roster as $well){
        echo 'Consultando cartas de '.$well['well']."...\n";flush();
        $catalog=wh_catalog($db,$well['well']);
        foreach(['superficie','fondo'] as $kind){
            $result=cd_collect_kind($catalog,$kind,$cut,$request,$previous[strtoupper($well['well'])][$kind]??null);
            $card=$result['card'];$warning=trim($well['warning'].' '.$result['warning']);
            $has=cd_has_card($card);if($has)$valid++;if($warning!=='')$warnings++;
            $json=$has?json_encode($card,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null;
            $date=$has?cd_sql_date(new DateTimeImmutable($card['reference'])):null;
            cd_execute($db,'INSERT INTO dbo.CLEAR_CARTAS_DIARIAS(CARGA_ID,POZO,BATERIA,TIPO,REFERENCIA,CARTA_JSON,OBSERVACION) VALUES(?,?,?,?,CONVERT(datetime2,?,120),?,?)',[$run,$well['well'],$well['battery'],$kind,$date,$json,$warning]);
        }
        $wells++;echo $wells.' · '.$well['well']."\n";
    }
    if(!$wells||(!$valid&&$warnings))throw new RuntimeException('No se obtuvo ninguna carta válida. Revisar tags, calidad y conectividad; no se publica esta carga.');
    // Único punto de publicación: el lector jamás ve una carga parcial.
    cd_execute($db,"UPDATE dbo.CLEAR_CARTAS_CARGAS SET ESTADO='COMPLETA',FIN=SYSDATETIME(),POZOS=?,ADVERTENCIAS=?,DETALLE=? WHERE ID=?",[$wells,$warnings,'Fuente: BM_RTQP; Consultas PI: '.$requests,$run]);
    echo 'Carga completa: '.$wells.' pozos; '.$warnings.' advertencias; '.$requests." consultas PI.\n";
    // Mantener las dos cargas completas más recientes y diagnósticos por siete días.
    try{
        cd_execute($db,"SET XACT_ABORT ON; BEGIN TRAN; DELETE D FROM dbo.CLEAR_CARTAS_DIARIAS D INNER JOIN dbo.CLEAR_CARTAS_CARGAS C ON C.ID=D.CARGA_ID WHERE C.ID NOT IN (SELECT TOP (2) ID FROM dbo.CLEAR_CARTAS_CARGAS WHERE ESTADO='COMPLETA' ORDER BY CORTE DESC,FIN DESC) AND (C.ESTADO='COMPLETA' OR C.INICIO<DATEADD(day,-7,SYSDATETIME())); DELETE C FROM dbo.CLEAR_CARTAS_CARGAS C WHERE NOT EXISTS (SELECT 1 FROM dbo.CLEAR_CARTAS_DIARIAS D WHERE D.CARGA_ID=C.ID) AND C.ID<>? AND C.INICIO<DATEADD(day,-7,SYSDATETIME()); COMMIT;",[$run]);
    }catch(Throwable $cleanupError){fwrite(STDERR,"ADVERTENCIA: carga publicada; no se pudo limpiar el historial de cargas.\n");}
}catch(Throwable $e){
    if($run)$db->execute("UPDATE dbo.CLEAR_CARTAS_CARGAS SET ESTADO='ERROR',FIN=SYSDATETIME(),DETALLE=? WHERE ID=? AND ESTADO='CARGANDO'",[substr($e->getMessage(),0,950),$run]);
    fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");
    if($db->error())fwrite(STDERR,'Detalle SQL: '.$db->error()."\n");
    exit(1);
}finally{
    if($locked)$db->execute("EXEC sys.sp_releaseapplock @Resource=N'CLEAR_CARTAS_DIARIAS_SYNC',@LockOwner='Session'");
}
