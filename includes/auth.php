<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/auth.php
   Autenticación contra la tabla FIXALARMS_USR (USUARIO / PASS).
   Contraseña en texto plano (igual que el PHPRunner original).
   Sesión PHP estándar.
============================================================= */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/permissions.php';

function auth_start()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/* Verifica usuario/contraseña contra FIXALARMS_USR.
   Devuelve true si las credenciales son válidas. */
function auth_login($usuario, $pass)
{
    permissions_ensure_tables();
    $db = clear_db();
    if (!$db->ok()) return ['ok'=>false,'error'=>'Sin conexión a la base: '.$db->error()];
    $usuario=trim((string)$usuario);
    $sec=$db->all("SELECT INTENTOS,BLOQUEADO_HASTA FROM dbo.CLEAR_LOGIN_SECURITY WHERE USUARIO=?",[$usuario]);
    if(!empty($sec) && !empty($sec[0]['BLOQUEADO_HASTA']) && strtotime((string)$sec[0]['BLOQUEADO_HASTA'])>time()){
        audit_log('LOGIN_BLOQUEADO','seguridad','Intento durante bloqueo',$usuario);
        return ['ok'=>false,'error'=>'Usuario temporalmente bloqueado. Intentá nuevamente más tarde.'];
    }
    $rows=$db->all("SELECT USUARIO, PASS FROM dbo.FIXALARMS_USR WHERE USUARIO = ?",[$usuario]);
    $valid=false;
    if(!empty($rows)){
        $hash=$db->scalar("SELECT PASSWORD_HASH FROM dbo.CLEAR_USER_PASSWORDS WHERE USUARIO=?",[$usuario]);
        if($hash){
            $valid=password_verify((string)$pass,(string)$hash);
        }else{
            $stored=rtrim((string)$rows[0]['PASS']);
            $valid=hash_equals($stored,(string)$pass);
            if($valid){
                $db->execute("INSERT INTO dbo.CLEAR_USER_PASSWORDS(USUARIO,PASSWORD_HASH) VALUES(?,?)",[$usuario,password_hash((string)$pass,PASSWORD_DEFAULT)]);
                $db->execute("UPDATE dbo.FIXALARMS_USR SET PASS='!MIGRATED!' WHERE USUARIO=?",[$usuario]);
            }
        }
    }
    if(!$valid){
        $count=!empty($sec)?((int)$sec[0]['INTENTOS']+1):1;
        $lock=$count>=5?date('Y-m-d H:i:s',time()+900):null;
        $exists=(int)$db->scalar("SELECT COUNT(*) FROM dbo.CLEAR_LOGIN_SECURITY WHERE USUARIO=?",[$usuario]);
        if($exists)$db->execute("UPDATE dbo.CLEAR_LOGIN_SECURITY SET INTENTOS=?,BLOQUEADO_HASTA=?,ULTIMO_INTENTO=SYSDATETIME() WHERE USUARIO=?",[$count,$lock,$usuario]);
        else $db->execute("INSERT INTO dbo.CLEAR_LOGIN_SECURITY(USUARIO,INTENTOS,BLOQUEADO_HASTA,ULTIMO_INTENTO) VALUES(?,?,?,SYSDATETIME())",[$usuario,$count,$lock]);
        audit_log('LOGIN_FALLIDO','seguridad','Credenciales inválidas',$usuario);
        return ['ok'=>false,'error'=>$count>=5?'Usuario bloqueado por 15 minutos.':'Usuario o contraseña incorrectos.'];
    }
    $access=permissions_get_user($usuario);
    if(empty($access['active'])) return ['ok'=>false,'error'=>'Usuario desactivado. Contactá al administrador.'];
    $db->execute("DELETE FROM dbo.CLEAR_LOGIN_SECURITY WHERE USUARIO=?",[$usuario]);
    auth_start(); session_regenerate_id(true);
    $_SESSION['clear_user']=$rows[0]['USUARIO']; $_SESSION['clear_login_time']=time(); $_SESSION['clear_last_activity']=time();
    $_SESSION['clear_rol']=function_exists('rol_de')?rol_de($rows[0]['USUARIO']):'operador';
    audit_log('LOGIN_OK','seguridad','Inicio de sesión',$usuario);
    return ['ok'=>true];
}

/* ¿Hay un usuario logueado? */
function auth_check()
{
    auth_start();
    if(!isset($_SESSION['clear_user']) || $_SESSION['clear_user']==='') return false;

    /* Sesión efímera usada únicamente por el generador de PDF automático. */
    if(!empty($_SESSION['clear_report_mode'])){
        $expires=(int)($_SESSION['clear_report_exp']??0);
        if($expires<=time()){ auth_logout(); return false; }
    }

    $p=permissions_get_user($_SESSION['clear_user']);
    $timeout=max(5,(int)($p['session_timeout_min']??60))*60;
    $last=(int)($_SESSION['clear_last_activity']??$_SESSION['clear_login_time']??time());
    if(time()-$last>$timeout){ auth_logout(); return false; }
    $_SESSION['clear_last_activity']=time();
    return !empty($p['active']);
}

/* Nombre del usuario logueado (o null). */
function auth_user()
{
    auth_start();
    return $_SESSION['clear_user'] ?? null;
}

/* Cierra la sesión. */
function auth_logout()
{
    auth_start();
    if(!empty($_SESSION['clear_user'])) audit_log('LOGOUT','seguridad','Cierre de sesión',$_SESSION['clear_user']);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* Protege una página: si no hay login, redirige al login.
   Llamar al principio de cada página protegida. */
function auth_require()
{
    if (!auth_check()) {
        header('Location: login.php');
        exit;
    }
}

/* Rol del usuario logueado ('admin' u 'operador'). */
function auth_rol()
{
    auth_start();
    if(!empty($_SESSION['clear_report_mode'])) return 'operador';
    return $_SESSION['clear_rol'] ?? 'operador';
}

/* ¿El usuario logueado es administrador? */
function auth_es_admin()
{
    return auth_rol() === 'admin';
}

/* Protege una página solo para administradores. */
function auth_require_admin()
{
    auth_require();
    if (!auth_es_admin()) {
        header('Location: index.php');
        exit;
    }
}
