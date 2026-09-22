<?php
require_once __DIR__ . '/db.php';

function permissions_profiles()
{
    $allMenus = array_keys(permissions_menu_catalog());
    return [
        'admin' => ['label'=>'Administrador','comments'=>[1,1,1,1,1],'menus'=>$allMenus],
        'supervisor' => ['label'=>'Supervisor','comments'=>[1,1,1,1,0],'menus'=>array_values(array_diff($allMenus,['admin_usuarios','admin_supervisores_instalaciones','config_pi','config_menu','config_alarmas','config_scada_realtime','audit_log','versiones','reportes','tags_filtrados']))],
        'operador' => ['label'=>'Operador','comments'=>[1,1,1,0,0],'menus'=>['dashboard','dashboard_inst_sup','dashboard_pozos','pozos_por_baterias','scada_realtime','telemetria_general','monitoreo_pozos','telemetria_pcp','telemetria_bes','telemetria_tecss','tecss_3sigma','tecss_vibraciones','sin_telemetria_zafiro','inyeccion_agua','micros_contables','micros_contables_cierre','pi_historico','alarmas24h','pozos_alarmas24','pozos_todas_alarmas','pozos_alarmas_semanal','top_pozos','pozos_top20','instalaciones_alarmas_semanal','novedades_semanales_panel','novedades_semanales_malos_actores','novedades_semanales_comparativa','novedades_semanales_seguimiento','novedades_semanales_reporte','reportes_guardados','alarmas_activas','suprimidas','reconocidas','reconocidas_usr','top20_all','top20_24h','ranking24h','todas_alarmas','pozos','pozos_tecss','importadas','analisis_ia']],
        'consulta' => ['label'=>'Consulta','comments'=>[1,0,0,0,0],'menus'=>['dashboard','dashboard_inst_sup','dashboard_pozos','pozos_por_baterias','scada_realtime','telemetria_general','monitoreo_pozos','telemetria_pcp','telemetria_bes','telemetria_tecss','tecss_3sigma','tecss_vibraciones','sin_telemetria_zafiro','inyeccion_agua','micros_contables','micros_contables_cierre','pi_historico','alarmas24h','pozos_alarmas24','pozos_todas_alarmas','instalaciones_alarmas_semanal','novedades_semanales_panel','novedades_semanales_malos_actores','novedades_semanales_comparativa','novedades_semanales_seguimiento','novedades_semanales_reporte','reportes_guardados','alarmas_activas','suprimidas','reconocidas','reconocidas_usr','top20_all','top20_24h','ranking24h','todas_alarmas','pozos','pozos_tecss','importadas','analisis_ia']],
        'pi_mantenimiento' => ['label'=>'PI / Mantenimiento','comments'=>[1,1,1,0,0],'menus'=>['dashboard','dashboard_inst_sup','dashboard_pozos','pozos_por_baterias','scada_realtime','telemetria_general','monitoreo_pozos','telemetria_pcp','telemetria_bes','telemetria_tecss','tecss_3sigma','tecss_vibraciones','sin_telemetria_zafiro','inyeccion_agua','micros_contables','micros_contables_cierre','pi_historico','alarmas24h','pozos_alarmas24','pozos_todas_alarmas','instalaciones_alarmas_semanal','novedades_semanales_panel','novedades_semanales_malos_actores','novedades_semanales_comparativa','novedades_semanales_seguimiento','novedades_semanales_reporte','reportes_guardados','alarmas_activas','top20_all','top20_24h','ranking24h','todas_alarmas','analisis_ia']],
    ];
}

function permissions_menu_catalog()
{
    return [
        'dashboard'=>'Dashboard (compatibilidad)','dashboard_inst_sup'=>'Dashboard Inst Sup','dashboard_pozos'=>'Dashboard Pozos','pozos_por_baterias'=>'Pozos por Baterías','scada_realtime'=>'SCADA Real time','telemetria_general'=>'Grilla general de pozos','monitoreo_pozos'=>'Monitoreo Pozos','telemetria_pcp'=>'Telemetría PCP','telemetria_bes'=>'Telemetría BES','telemetria_tecss'=>'Telemetría TECCS','tecss_3sigma'=>'3Sigma TECSS','tecss_vibraciones'=>'Análisis de Vibraciones TECSS','sin_telemetria_zafiro'=>'Sin Telemetría en Zafiro','inyeccion_agua'=>'Inyección de Agua','estado_sistema'=>'Estado del sistema','pi_historico'=>'PI Histórico','alarmas24h'=>'Alarmas 24h','alarmas_activas'=>'Alarmas por históricos',
        'suprimidas'=>'Suprimidas','reconocidas'=>'Reconocidas','reconocidas_usr'=>'Reconocidas por usuario','pozos_alarmas24'=>'Alarmas 24h de pozo','pozos_todas_alarmas'=>'Histórico de alarmas de pozo','pozos_alarmas_semanal'=>'Alarmas semanal de pozos','top_pozos'=>'Top Pozos','pozos_top20'=>'Top 20 alarmas de pozos','instalaciones_alarmas_semanal'=>'Alarmas semanal','novedades_semanales_panel'=>'Novedades semanales · Panel','micros_contables'=>'Novedades semanales · Micros contables','micros_contables_cierre'=>'Novedades semanales · Cierre diario','novedades_semanales_malos_actores'=>'Novedades semanales · Malos actores','novedades_semanales_comparativa'=>'Novedades semanales · Comparativa','novedades_semanales_seguimiento'=>'Novedades semanales · Seguimiento','novedades_semanales_reporte'=>'Novedades semanales · Reporte','reportes_guardados'=>'REPORTES guardados','top20_all'=>'Top 20 alarmas',
        'top20_24h'=>'Top 20 24h','ranking24h'=>'Ranking 24h','tendencia_sem'=>'Tendencia semanal','tend_sem_tags'=>'Tendencia por tag',
        'prioridad'=>'Prioridad','todas_alarmas'=>'Todas las alarmas','pozos'=>'Pozos','pozos_tecss'=>'Pozos · técnico','comentarios'=>'Comentarios',
        'importadas'=>'Alarmas importadas','analisis_ia'=>'Análisis IA','admin_usuarios'=>'Administración de usuarios','admin_supervisores_instalaciones'=>'Supervisores de instalaciones','config_pi'=>'Configuración PI','config_menu'=>'Configuración de menú','config_alarmas'=>'Configuración de alarmas','config_scada_realtime'=>'Configuración SCADA Real time','audit_log'=>'Auditoría','versiones'=>'Control de versiones','reportes'=>'Reportes por correo','tags_filtrados'=>'Tags filtrados'
    ];
}

function permissions_ensure_tables()
{
    static $checked = null;
    if ($checked !== null) return $checked;
    $db=clear_db(); if(!$db->ok()) return false;
    /*
     * Las tablas se instalan exclusivamente mediante los scripts SQL.
     * Nunca ejecutar CREATE/ALTER desde una petición web: esta función es
     * usada por cada elemento del menú y el DDL repetido bloqueaba catálogo,
     * compilaba planes innecesarios y elevaba la CPU de SQL Server.
     */
    $checked=(int)$db->scalar(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_USER_ACCESS',N'U') IS NOT NULL " .
        "AND OBJECT_ID(N'dbo.CLEAR_AUDIT_LOG',N'U') IS NOT NULL " .
        "AND OBJECT_ID(N'dbo.CLEAR_LOGIN_SECURITY',N'U') IS NOT NULL " .
        "AND OBJECT_ID(N'dbo.CLEAR_USER_PASSWORDS',N'U') IS NOT NULL " .
        "AND OBJECT_ID(N'dbo.CLEAR_USER_PREFS',N'U') IS NOT NULL THEN 1 ELSE 0 END"
    )===1;
    return $checked;
}

function permissions_default_for_profile($profile)
{
    $profiles=permissions_profiles(); if(!isset($profiles[$profile])) $profile='operador';
    $p=$profiles[$profile];
    return ['active'=>1,'profile'=>$profile,'comment_view'=>$p['comments'][0],'comment_create'=>$p['comments'][1],'comment_edit_own'=>$p['comments'][2],'comment_edit_all'=>$p['comments'][3],'comment_disable'=>$p['comments'][4],'menus'=>$p['menus'],'session_timeout_min'=>60];
}

function permissions_get_user($usuario)
{
    $usuario=(string)$usuario;
    if(!isset($GLOBALS['CLEAR_PERMISSIONS_USER_CACHE'])||!is_array($GLOBALS['CLEAR_PERMISSIONS_USER_CACHE']))$GLOBALS['CLEAR_PERMISSIONS_USER_CACHE']=[];
    if(array_key_exists($usuario,$GLOBALS['CLEAR_PERMISSIONS_USER_CACHE']))return $GLOBALS['CLEAR_PERMISSIONS_USER_CACHE'][$usuario];
    permissions_ensure_tables();
    $db=clear_db();
    $rows=$db->all("SELECT * FROM dbo.CLEAR_USER_ACCESS WHERE USUARIO=?",[$usuario]);
    if(empty($rows)){
        $profile=function_exists('es_admin')&&es_admin($usuario)?'admin':'operador';
        $result=permissions_default_for_profile($profile);
        $GLOBALS['CLEAR_PERMISSIONS_USER_CACHE'][$usuario]=$result;
        return $result;
    }
    $r=$rows[0];
    $menus=json_decode((string)($r['MENU_JSON']??''),true); if(!is_array($menus)) $menus=permissions_default_for_profile((string)($r['PERFIL']??'operador'))['menus'];

    // Compatibilidad CLEAR v3.0: los usuarios con acceso al dashboard o a pozos reciben las nuevas vistas.
    if (in_array('dashboard',$menus,true)) { foreach(['dashboard_inst_sup','dashboard_pozos','pozos_por_baterias'] as $k) if(!in_array($k,$menus,true))$menus[]=$k; }
    if (in_array('dashboard_pozos',$menus,true) && !in_array('pozos_por_baterias',$menus,true)) $menus[]='pozos_por_baterias';
    if (in_array('pozos',$menus,true) || in_array('pozos_tecss',$menus,true)) { foreach(['telemetria_general','monitoreo_pozos','telemetria_pcp','telemetria_bes','telemetria_tecss','tecss_3sigma','tecss_vibraciones','inyeccion_agua'] as $k) if(!in_array($k,$menus,true))$menus[]=$k; }
    if (in_array('telemetria_tecss',$menus,true)) { foreach(['tecss_3sigma','tecss_vibraciones'] as $k) if(!in_array($k,$menus,true))$menus[]=$k; }
    if (array_intersect(['telemetria_general','monitoreo_pozos','telemetria_pcp','telemetria_bes','telemetria_tecss'],$menus) && !in_array('scada_realtime',$menus,true)) $menus[]='scada_realtime';
    // Sin telemetría en Zafiro se deriva de la grilla general: quien la ve, accede a esta pantalla.
    if (in_array('telemetria_general',$menus,true) && !in_array('sin_telemetria_zafiro',$menus,true)) $menus[]='sin_telemetria_zafiro';
    if (strtolower((string)($r['PERFIL']??''))==='admin' && !in_array('estado_sistema',$menus,true)) $menus[]='estado_sistema';

    // Compatibilidad con usuarios existentes: si ya tenían acceso a Pozos, habilitar las nuevas vistas de pozos.
    if (in_array('alarmas24h',$menus,true) && !in_array('instalaciones_alarmas_semanal',$menus,true)) $menus[]='instalaciones_alarmas_semanal';
    if (array_intersect(['pozos_por_baterias','monitoreo_pozos','telemetria_general','alarmas24h'],$menus)) {
        foreach(['pozos_alarmas24','pozos_todas_alarmas','pozos_alarmas_semanal','top_pozos','pozos_top20'] as $k) if(!in_array($k,$menus,true))$menus[]=$k;
    }
    // Las pantallas operativas recientes de alarmas y el reporte forman un
    // único módulo común para todos los perfiles que ya tenían Alarmas.
    if (array_intersect(['alarmas24h','alarmas_activas','suprimidas','top20_all','top20_24h','instalaciones_alarmas_semanal'],$menus)) {
        foreach(['alarmas24h','alarmas_activas','suprimidas','top20_all','top20_24h','instalaciones_alarmas_semanal','novedades_semanales_reporte','reportes_guardados'] as $k) if(!in_array($k,$menus,true))$menus[]=$k;
    }
    if (in_array('instalaciones_alarmas_semanal',$menus,true)) { foreach(['novedades_semanales_panel','micros_contables','micros_contables_cierre','novedades_semanales_malos_actores','novedades_semanales_comparativa','novedades_semanales_seguimiento','novedades_semanales_reporte','reportes_guardados'] as $k) if(!in_array($k,$menus,true))$menus[]=$k; }
    if (array_intersect(['novedades_semanales_panel','novedades_semanales_malos_actores','novedades_semanales_comparativa','novedades_semanales_seguimiento','novedades_semanales_reporte'],$menus) && !in_array('micros_contables',$menus,true)) $menus[]='micros_contables';
    if (in_array('micros_contables',$menus,true) && !in_array('micros_contables_cierre',$menus,true)) $menus[]='micros_contables_cierre';
    if (array_intersect(['novedades_semanales_panel','novedades_semanales_malos_actores','novedades_semanales_comparativa','novedades_semanales_seguimiento'],$menus) && !in_array('novedades_semanales_reporte',$menus,true)) $menus[]='novedades_semanales_reporte';
    if (in_array('novedades_semanales_reporte',$menus,true) && !in_array('reportes_guardados',$menus,true)) $menus[]='reportes_guardados';
    // REPORTES y su constructor son funciones comunes para todo usuario activo.
    // Esto también actualiza en memoria a quienes conservan un MENU_JSON anterior.
    foreach(['novedades_semanales_reporte','reportes_guardados'] as $k) {
        if(!in_array($k,$menus,true)) $menus[]=$k;
    }
    if(!in_array('analisis_ia',$menus,true)) $menus[]='analisis_ia';
    if(strtolower((string)($r['PERFIL']??''))==='admin'){
        // Compatibilidad v1.1: los administradores existentes pueden abrir y
        // asignar Micros contables aunque su MENU_JSON haya sido guardado
        // antes de que existiera esta pantalla.
        foreach(['micros_contables','micros_contables_cierre','versiones','reportes','tags_filtrados','admin_supervisores_instalaciones','config_alarmas','scada_realtime','config_scada_realtime'] as $k){
            if(!in_array($k,$menus,true))$menus[]=$k;
        }
    }
    $result=['active'=>(int)($r['ACTIVO']??1),'profile'=>strtolower((string)($r['PERFIL']??'operador')),'comment_view'=>(int)($r['COMMENT_VIEW']??1),'comment_create'=>(int)($r['COMMENT_CREATE']??1),'comment_edit_own'=>(int)($r['COMMENT_EDIT_OWN']??1),'comment_edit_all'=>(int)($r['COMMENT_EDIT_ALL']??0),'comment_disable'=>(int)($r['COMMENT_DISABLE']??0),'menus'=>$menus,'session_timeout_min'=>max(5,(int)($r['SESSION_TIMEOUT_MIN']??60))];
    $GLOBALS['CLEAR_PERMISSIONS_USER_CACHE'][$usuario]=$result;
    return $result;
}

function permissions_save_user($usuario,array $p,$updatedBy='')
{
    permissions_ensure_tables(); $db=clear_db();
    $menus=json_encode(array_values(array_unique($p['menus']??[])),JSON_UNESCAPED_UNICODE);
    $exists=$db->scalar("SELECT COUNT(*) FROM dbo.CLEAR_USER_ACCESS WHERE USUARIO=?",[$usuario]);
    $params=[(int)!empty($p['active']),$p['profile']??'operador',(int)!empty($p['comment_view']),(int)!empty($p['comment_create']),(int)!empty($p['comment_edit_own']),(int)!empty($p['comment_edit_all']),(int)!empty($p['comment_disable']),$menus,max(5,(int)($p['session_timeout_min']??60)),$updatedBy,$usuario];
    if((int)$exists>0){
        $ok=$db->execute("UPDATE dbo.CLEAR_USER_ACCESS SET ACTIVO=?,PERFIL=?,COMMENT_VIEW=?,COMMENT_CREATE=?,COMMENT_EDIT_OWN=?,COMMENT_EDIT_ALL=?,COMMENT_DISABLE=?,MENU_JSON=?,SESSION_TIMEOUT_MIN=?,UPDATED_AT=SYSDATETIME(),UPDATED_BY=? WHERE USUARIO=?",$params);
        if($ok&&isset($GLOBALS['CLEAR_PERMISSIONS_USER_CACHE']))unset($GLOBALS['CLEAR_PERMISSIONS_USER_CACHE'][(string)$usuario]);
        return $ok;
    }
    array_pop($params); array_splice($params,0,0,[$usuario]);
    $ok=$db->execute("INSERT INTO dbo.CLEAR_USER_ACCESS(USUARIO,ACTIVO,PERFIL,COMMENT_VIEW,COMMENT_CREATE,COMMENT_EDIT_OWN,COMMENT_EDIT_ALL,COMMENT_DISABLE,MENU_JSON,SESSION_TIMEOUT_MIN,UPDATED_BY) VALUES(?,?,?,?,?,?,?,?,?,?,?)",$params);
    if($ok&&isset($GLOBALS['CLEAR_PERMISSIONS_USER_CACHE']))unset($GLOBALS['CLEAR_PERMISSIONS_USER_CACHE'][(string)$usuario]);
    return $ok;
}

function permissions_can_menu($key,$usuario=null)
{
    /*
     * En render automático el navegador recibe una sesión de pocos minutos.
     * Se limita a la única pantalla que se está imprimiendo para que la
     * navegación lateral y el resto de módulos no queden habilitados.
     */
    if(function_exists('auth_start')){
        auth_start();
        if(!empty($_SESSION['clear_report_mode'])){
            $allowed=trim((string)($_SESSION['clear_report_menu']??''));
            if($allowed!=='' && (string)$key!==$allowed) return false;
        }
    }
    if($usuario===null && function_exists('auth_user')) $usuario=auth_user();
    if(!$usuario) return false;
    $p=permissions_get_user($usuario); if(!$p['active']) return false;
    // La pantalla consolidada hereda el permiso funcional de lectura de comentarios.
    if($key==='comentarios')return !empty($p['comment_view']);
    // Las nuevas gestiones heredan el acceso existente a Novedades semanales.
    if(in_array($key,['novedades_semanales_auditoria','novedades_semanales_requerimientos'],true))return in_array('novedades_semanales_panel',$p['menus'],true);
    if($key==='novedades_semanales_monitoreo_bm')return false;
    // Esta vista hereda ambos permisos; no concede acceso adicional a telemetría.
    if(in_array($key,['novedades_semanales_monitoreo','novedades_semanales_monitoreo_bm'],true)) return in_array('novedades_semanales_panel',$p['menus'],true) && in_array('monitoreo_pozos',$p['menus'],true);
    return in_array($key,$p['menus'],true);
}

function permissions_can($right,$usuario=null)
{
    if($usuario===null && function_exists('auth_user')) $usuario=auth_user();
    if(!$usuario) return false;
    $p=permissions_get_user($usuario);
    $map=['comments.view'=>'comment_view','comments.create'=>'comment_create','comments.edit_own'=>'comment_edit_own','comments.edit_all'=>'comment_edit_all','comments.disable'=>'comment_disable'];
    if(isset($map[$right])) return !empty($p[$map[$right]]);
    if(strpos($right,'menu.')===0) return permissions_can_menu(substr($right,5),$usuario);
    return false;
}

function permissions_require_menu($key)
{
    if(!permissions_can_menu($key)){
        http_response_code(403);
        if(isset($_SERVER['HTTP_X_REQUESTED_WITH'])){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'No tenés permisos para acceder a esta función.']);exit;}
        header('Location: index.php?denied=1'); exit;
    }
}

function audit_log($accion,$modulo='',$detalle='',$usuario=null)
{
    permissions_ensure_tables(); $db=clear_db(); if(!$db->ok())return false;
    if($usuario===null && function_exists('auth_user'))$usuario=auth_user();
    $ip=$_SERVER['REMOTE_ADDR']??'';
    return $db->execute("INSERT INTO dbo.CLEAR_AUDIT_LOG(USUARIO,ACCION,MODULO,DETALLE,IP) VALUES(?,?,?,?,?)",[$usuario,$accion,$modulo,$detalle,$ip]);
}

function user_pref_get($key,$default=null,$usuario=null){ if($usuario===null&&function_exists('auth_user'))$usuario=auth_user(); permissions_ensure_tables(); $v=clear_db()->scalar("SELECT PREF_VALUE FROM dbo.CLEAR_USER_PREFS WHERE USUARIO=? AND PREF_KEY=?",[$usuario,$key]); return $v===null?$default:$v; }
function user_pref_set($key,$value,$usuario=null){ if($usuario===null&&function_exists('auth_user'))$usuario=auth_user(); permissions_ensure_tables(); $db=clear_db(); $exists=(int)$db->scalar("SELECT COUNT(*) FROM dbo.CLEAR_USER_PREFS WHERE USUARIO=? AND PREF_KEY=?",[$usuario,$key]); if($exists)return $db->execute("UPDATE dbo.CLEAR_USER_PREFS SET PREF_VALUE=?,UPDATED_AT=SYSDATETIME() WHERE USUARIO=? AND PREF_KEY=?",[$value,$usuario,$key]); return $db->execute("INSERT INTO dbo.CLEAR_USER_PREFS(USUARIO,PREF_KEY,PREF_VALUE) VALUES(?,?,?)",[$usuario,$key,$value]); }
