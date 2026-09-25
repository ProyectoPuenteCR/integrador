<?php
ob_start();
ini_set('display_errors','0');
error_reporting(E_ALL);

function mr_json(array $payload,$status=200)
{
    while(ob_get_level()>0) ob_end_clean();
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
register_shutdown_function(function(){
    $error=error_get_last();
    if($error && in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)){
        mr_json(['ok'=>false,'error'=>'Error interno de PHP: '.$error['message']],500);
    }
});

try {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/permissions.php';
    require_once __DIR__ . '/includes/reporting.php';

    auth_require();
    permissions_require_menu('mis_reportes');
    if(!report_ensure_tables()) throw new Exception('El módulo de reportes no está instalado completamente.');

    $db=clear_db();
    if(!$db->ok()) throw new Exception('Sin conexión a SQL Server: '.$db->error());

    $user=(string)auth_user();
    $action=trim((string)($_POST['action']??''));

    $owned=function($id) use($db,$user){
        $rows=$db->all("SELECT * FROM dbo.CLEAR_REPORT_SCHEDULES WHERE ID=? AND USUARIO_CARGA=?",[(int)$id,$user]);
        return $rows?$rows[0]:null;
    };
    $validateEmails=function($text,$required=false){
        $items=report_split_emails($text);
        if($required && !$items) throw new Exception('Ingresá al menos un destinatario.');
        foreach($items as $email){
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new Exception('Correo inválido: '.$email);
        }
        return implode('; ',array_values(array_unique($items)));
    };
    $normalizeTimes=function($text){
        $items=array_values(array_filter(array_map('trim',preg_split('/[;,]+/',(string)$text))));
        if(!$items) throw new Exception('Ingresá al menos un horario.');
        $out=[];
        foreach($items as $time){
            if(!preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/',$time)) throw new Exception('Horario inválido: '.$time.'. Usá HH:MM.');
            list($h,$m)=array_map('intval',explode(':',$time));
            $norm=sprintf('%02d:%02d',$h,$m);
            if(!in_array($norm,$out,true))$out[]=$norm;
        }
        sort($out);
        return implode(',',$out);
    };

    if($action==='save_schedule'){
        $id=(int)($_POST['id']??0);
        if($id>0 && !$owned($id)) throw new Exception('No podés modificar una programación que no te pertenece.');

        $name=trim((string)($_POST['nombre']??''));
        $subject=trim((string)($_POST['asunto']??''));
        $body=trim((string)($_POST['cuerpo']??''));
        if($name===''||$subject==='') throw new Exception('Completá nombre y asunto.');
        if(strlen($name)>180||strlen($subject)>250) throw new Exception('Nombre o asunto demasiado largo.');

        $to=$validateEmails($_POST['destinatarios']??'',true);
        $cc=$validateEmails($_POST['cc']??'',false);
        $cco=$validateEmails($_POST['cco']??'',false);

        $frequency=strtolower(trim((string)($_POST['frecuencia']??'daily')));
        if(!in_array($frequency,['daily','weekdays','weekly','custom','monthly'],true)) throw new Exception('Frecuencia inválida.');

        $days=array_values(array_unique(array_filter(array_map('intval',$_POST['dias']??[]),function($v){return $v>=1&&$v<=7;})));
        sort($days);
        if(in_array($frequency,['weekly','custom'],true) && !$days) throw new Exception('Seleccioná al menos un día de semana.');
        $daysCsv=implode(',',$days);

        $times=$normalizeTimes($_POST['horarios']??'08:00');
        $timezone=trim((string)($_POST['zona_horaria']??'America/Argentina/Buenos_Aires'));
        try { new DateTimeZone($timezone); } catch(Throwable $e) { throw new Exception('Zona horaria inválida.'); }

        $allowed=report_screen_picker_definitions_for_user($user);
        $requested=is_array($_POST['pantallas']??null)?$_POST['pantallas']:preg_split('/[;,]+/',(string)($_POST['pantallas']??''));
        $screens=[];
        foreach($requested as $screen){
            $screen=trim((string)$screen);
            if(isset($allowed[$screen])&&!in_array($screen,$screens,true))$screens[]=$screen;
        }
        if(!$screens) throw new Exception('Seleccioná al menos una pantalla habilitada.');
        $screensCsv=implode(',',$screens);

        $active=(int)!empty($_POST['activo']);
        $runData=['ZONA_HORARIA'=>$timezone,'FRECUENCIA'=>$frequency,'DIAS_SEMANA'=>$daysCsv,'HORARIOS'=>$times];
        $next=report_next_run($runData)->format('Y-m-d H:i:s');

        $params=[$name,$screens[0],$screensCsv,$to,$cc,$cco,$subject,$body,$frequency,$daysCsv,$times,$active,$timezone,$next];
        if($id>0){
            $ok=$db->execute(
                "UPDATE dbo.CLEAR_REPORT_SCHEDULES SET NOMBRE=?,TIPO_REPORTE=?,PANTALLAS=?,DESTINATARIOS=?,CC=?,CCO=?,ASUNTO=?,CUERPO=?,FRECUENCIA=?,DIAS_SEMANA=?,HORARIOS=?,ACTIVO=?,ZONA_HORARIA=?,PROXIMA_EJECUCION=?,FECHA_MODIFICACION=SYSDATETIME() WHERE ID=? AND USUARIO_CARGA=?",
                array_merge($params,[$id,$user])
            );
        } else {
            $ok=$db->execute(
                "INSERT INTO dbo.CLEAR_REPORT_SCHEDULES(NOMBRE,TIPO_REPORTE,PANTALLAS,DESTINATARIOS,CC,CCO,ASUNTO,CUERPO,FRECUENCIA,DIAS_SEMANA,HORARIOS,ACTIVO,ZONA_HORARIA,PROXIMA_EJECUCION,USUARIO_CARGA) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                array_merge($params,[$user])
            );
        }
        if(!$ok) throw new Exception('No se pudo guardar la programación. '.$db->error());
        audit_log('MY_REPORT_SCHEDULE_SAVE','mis_reportes',$name.' | '.$screensCsv,$user);
        mr_json(['ok'=>true,'message'=>$id>0?'Programación actualizada.':'Programación creada.']);
    }

    if($action==='delete_schedule'){
        $id=(int)($_POST['id']??0);
        $row=$owned($id);
        if(!$row) throw new Exception('Reporte no encontrado o sin permisos.');
        if(!$db->execute("DELETE FROM dbo.CLEAR_REPORT_SCHEDULES WHERE ID=? AND USUARIO_CARGA=?",[$id,$user])) throw new Exception('No se pudo eliminar. '.$db->error());
        audit_log('MY_REPORT_SCHEDULE_DELETE','mis_reportes','ID '.$id.' · '.$row['NOMBRE'],$user);
        mr_json(['ok'=>true]);
    }

    if($action==='duplicate_schedule'){
        $id=(int)($_POST['id']??0);
        $row=$owned($id);
        if(!$row) throw new Exception('Reporte no encontrado o sin permisos.');

        $allowed=report_screen_picker_definitions_for_user($user);
        $screens=[];
        foreach(report_parse_screens($row['PANTALLAS']??$row['TIPO_REPORTE']??'') as $screen){
            if(isset($allowed[$screen]))$screens[]=$screen;
        }
        if(!$screens) throw new Exception('La programación original ya no contiene pantallas habilitadas.');

        $copyName='Copia de '.trim((string)$row['NOMBRE']);
        if(strlen($copyName)>180)$copyName=substr($copyName,0,180);
        $runData=['ZONA_HORARIA'=>$row['ZONA_HORARIA'],'FRECUENCIA'=>$row['FRECUENCIA'],'DIAS_SEMANA'=>$row['DIAS_SEMANA'],'HORARIOS'=>$row['HORARIOS']];
        $next=report_next_run($runData)->format('Y-m-d H:i:s');
        $screensCsv=implode(',',$screens);
        $ok=$db->execute(
            "INSERT INTO dbo.CLEAR_REPORT_SCHEDULES(NOMBRE,TIPO_REPORTE,PANTALLAS,DESTINATARIOS,CC,CCO,ASUNTO,CUERPO,FRECUENCIA,DIAS_SEMANA,HORARIOS,ACTIVO,ZONA_HORARIA,PROXIMA_EJECUCION,USUARIO_CARGA) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$copyName,$screens[0],$screensCsv,$row['DESTINATARIOS'],$row['CC']??'',$row['CCO']??'',$row['ASUNTO'],$row['CUERPO']??'',$row['FRECUENCIA'],$row['DIAS_SEMANA']??'',$row['HORARIOS'],0,$row['ZONA_HORARIA'],$next,$user]
        );
        if(!$ok) throw new Exception('No se pudo duplicar. '.$db->error());
        audit_log('MY_REPORT_SCHEDULE_DUPLICATE','mis_reportes','Origen ID '.$id,$user);
        mr_json(['ok'=>true,'message'=>'Programación duplicada y pausada.']);
    }

    if($action==='send_now'){
        $id=(int)($_POST['id']??0);
        $row=$owned($id);
        if(!$row) throw new Exception('Reporte no encontrado o sin permisos.');

        list($ok,$message)=report_execute($row,$user);
        $db->execute(
            "UPDATE dbo.CLEAR_REPORT_SCHEDULES SET ULTIMA_EJECUCION=SYSDATETIME(),ULTIMO_ESTADO=?,ULTIMO_ERROR=?,FECHA_MODIFICACION=SYSDATETIME() WHERE ID=? AND USUARIO_CARGA=?",
            [$ok?'ENVIADO':'ERROR',$ok?'':(string)$message,$id,$user]
        );
        if(!$ok) throw new Exception($message);
        audit_log('MY_REPORT_SEND_NOW','mis_reportes','ID '.$id.' · '.$row['NOMBRE'],$user);
        mr_json(['ok'=>true,'message'=>'Informe generado y enviado correctamente.']);
    }

    throw new Exception('Acción no válida.');
} catch(Throwable $e) {
    mr_json(['ok'=>false,'error'=>$e->getMessage()],400);
}
