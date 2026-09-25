<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

function clear_report_json_response(array $payload, int $status = 200): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        clear_report_json_response([
            'ok' => false,
            'error' => 'Error interno de PHP: ' . $error['message'] . ' en ' . basename($error['file']) . ':' . $error['line']
        ], 500);
    }
});

try {
    require_once __DIR__.'/includes/auth.php';
    require_once __DIR__.'/includes/permissions.php';
    require_once __DIR__.'/includes/reporting.php';

    auth_require();
    permissions_require_menu('reportes');
    report_ensure_tables();
    $db = clear_db();
    $a = $_POST['action'] ?? '';

    if ($a === 'save_settings') {
        foreach (['APP_BASE_URL','CHROME_PATH','SMTP_HOST','SMTP_PORT','SMTP_SECURE','SMTP_USER','SMTP_PASS','SMTP_FROM','SMTP_FROM_NAME'] as $k) {
            report_setting_save($k, trim((string)($_POST[$k] ?? '')), auth_user());
        }
        audit_log('REPORT_SETTINGS_SAVE','reportes','Configuración SMTP/PDF actualizada');
        clear_report_json_response(['ok'=>true,'message'=>'Configuración guardada.']);
    }

    if ($a === 'save_schedule') {
        $id=(int)($_POST['id']??0);
        $days=implode(',',array_map('intval',$_POST['dias']??[]));
        $screens=report_parse_screens($_POST['pantallas']??[]);
        $screensCsv=implode(',',$screens);
        $r=[
            'ZONA_HORARIA'=>trim($_POST['zona_horaria']??'America/Argentina/Buenos_Aires'),
            'FRECUENCIA'=>trim($_POST['frecuencia']??'daily'),
            'DIAS_SEMANA'=>$days,
            'HORARIOS'=>trim($_POST['horarios']??'08:00')
        ];
        $next=report_next_run($r)->format('Y-m-d H:i:s');
        $owner=auth_user();
        $p=[trim($_POST['nombre']??''),$screens[0],$screensCsv,trim($_POST['destinatarios']??''),trim($_POST['cc']??''),trim($_POST['cco']??''),trim($_POST['asunto']??''),trim($_POST['cuerpo']??''),$r['FRECUENCIA'],$days,$r['HORARIOS'],(int)($_POST['activo']??1),$r['ZONA_HORARIA'],$next];
        if(!$p[0]||!$p[3]||!$p[6]) throw new Exception('Completá nombre, destinatarios y asunto.');
        if($id){
            /* El administrador puede mantener programaciones de usuarios, pero
               editar una no debe cambiar su propietario ni ampliar sus permisos. */
            $db->execute("UPDATE dbo.CLEAR_REPORT_SCHEDULES SET NOMBRE=?,TIPO_REPORTE=?,PANTALLAS=?,DESTINATARIOS=?,CC=?,CCO=?,ASUNTO=?,CUERPO=?,FRECUENCIA=?,DIAS_SEMANA=?,HORARIOS=?,ACTIVO=?,ZONA_HORARIA=?,PROXIMA_EJECUCION=?,FECHA_MODIFICACION=SYSDATETIME() WHERE ID=?",array_merge($p,[$id]));
        } else {
            $db->execute("INSERT INTO dbo.CLEAR_REPORT_SCHEDULES(NOMBRE,TIPO_REPORTE,PANTALLAS,DESTINATARIOS,CC,CCO,ASUNTO,CUERPO,FRECUENCIA,DIAS_SEMANA,HORARIOS,ACTIVO,ZONA_HORARIA,PROXIMA_EJECUCION,USUARIO_CARGA) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",array_merge($p,[$owner]));
        }
        audit_log('REPORT_SCHEDULE_SAVE','reportes',$p[0].' | '.$screensCsv);
        clear_report_json_response(['ok'=>true,'message'=>'Programación guardada.']);
    }

    if ($a === 'delete_schedule') {
        $id=(int)($_POST['id']??0);
        $db->execute("DELETE FROM dbo.CLEAR_REPORT_SCHEDULES WHERE ID=?",[$id]);
        audit_log('REPORT_SCHEDULE_DELETE','reportes','ID '.$id);
        clear_report_json_response(['ok'=>true]);
    }

    if ($a === 'send_now') {
        $id=(int)($_POST['id']??0);
        $r=$db->all("SELECT * FROM dbo.CLEAR_REPORT_SCHEDULES WHERE ID=?",[$id]);
        if(!$r) throw new Exception('Reporte no encontrado.');
        [$ok,$msg]=report_execute($r[0],auth_user());
        if(!$ok) throw new Exception($msg);
        clear_report_json_response(['ok'=>true,'message'=>'Reporte enviado correctamente.']);
    }

    throw new Exception('Acción no válida.');
} catch (Throwable $e) {
    clear_report_json_response(['ok'=>false,'error'=>$e->getMessage()],400);
}
