<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/roles.php
   Gestión de usuarios y roles.
   - Usuarios viven en dbo.FIXALARMS_USR (USUARIO, PASS) — NO se toca su estructura.
   - Roles viven en una tabla nueva e independiente: dbo.CLEAR_ROLES (USUARIO, ROL).
   - Si un usuario no tiene fila en CLEAR_ROLES, su rol por defecto es 'operador'.
============================================================= */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions.php';

define('ROL_ADMIN', 'admin');
define('ROL_OPER',  'operador');

/* Crea la tabla CLEAR_ROLES si no existe. Seguro de llamar siempre. */
function roles_ensure_table()
{
    $db = clear_db();
    if (!$db->ok()) return false;
    try {
        $sql = "IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='CLEAR_ROLES' AND xtype='U')
                CREATE TABLE dbo.CLEAR_ROLES (
                  USUARIO NVARCHAR(128) NOT NULL PRIMARY KEY,
                  ROL     NVARCHAR(32)  NOT NULL DEFAULT 'operador'
                )";
        $db->all($sql);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* Devuelve el rol de un usuario ('admin' u 'operador'). */
function rol_de($usuario)
{
    $db = clear_db();
    if (!$db->ok()) return ROL_OPER;

    try {
        $rows = $db->all("SELECT ROL FROM dbo.CLEAR_ROLES WHERE USUARIO = ?", [$usuario]);
    } catch (Throwable $e) {
        $rows = [];
    }
    if (!empty($rows)) {
        $r = strtolower(trim($rows[0]['ROL']));
        return ($r === ROL_ADMIN) ? ROL_ADMIN : ROL_OPER;
    }

    // No tiene rol asignado en la tabla: ¿está en la lista de admins iniciales?
    $cfg = require dirname(__DIR__) . '/config.php';
    $iniciales = $cfg['app']['admins_iniciales'] ?? [];
    foreach ($iniciales as $a) {
        if (strcasecmp(trim($a), trim($usuario)) === 0) {
            return ROL_ADMIN;
        }
    }
    return ROL_OPER;
}

function es_admin($usuario) { return rol_de($usuario) === ROL_ADMIN; }

/* Lista todos los usuarios con su rol.
   Sin JOIN (más robusto con COM): trae usuarios y roles por
   separado y los cruza en PHP. */
function usuarios_listar()
{
    $db = clear_db();
    if (!$db->ok()) return [];

    // 1) Usuarios (esta tabla siempre existe)
    try {
        $usuarios = $db->all("SELECT USUARIO FROM dbo.FIXALARMS_USR ORDER BY USUARIO");
    } catch (Throwable $e) {
        return [];
    }

    // 2) Roles (puede no existir la tabla; si falla, mapa vacío)
    $mapaRoles = [];
    try {
        $roles = $db->all("SELECT USUARIO, ROL FROM dbo.CLEAR_ROLES");
        foreach ($roles as $r) {
            $u = isset($r['USUARIO']) ? trim($r['USUARIO']) : '';
            if ($u !== '') $mapaRoles[strtolower($u)] = $r['ROL'];
        }
    } catch (Throwable $e) {
        $mapaRoles = [];
    }

    // 3) Admins iniciales del config (fallback)
    $cfg = require dirname(__DIR__) . '/config.php';
    $iniciales = array_map('strtolower', array_map('trim', $cfg['app']['admins_iniciales'] ?? []));

    // 4) Cruzar
    $out = [];
    foreach ($usuarios as $u) {
        $nombre = isset($u['USUARIO']) ? $u['USUARIO'] : '';
        $key = strtolower(trim($nombre));
        if (isset($mapaRoles[$key]) && $mapaRoles[$key] !== '') {
            $rol = (strtolower(trim($mapaRoles[$key])) === 'admin') ? 'admin' : 'operador';
        } elseif (in_array($key, $iniciales, true)) {
            $rol = 'admin';
        } else {
            $rol = 'operador';
        }
        $out[] = ['USUARIO' => $nombre, 'ROL' => $rol];
    }
    return $out;
}

/* Crea un usuario nuevo (en FIXALARMS_USR) y su rol (en CLEAR_ROLES). */
function usuario_crear($usuario, $pass, $rol)
{
    $usuario = trim($usuario);
    $rol = ($rol === ROL_ADMIN) ? ROL_ADMIN : ROL_OPER;

    if ($usuario === '' || $pass === '') {
        return ['ok' => false, 'error' => 'Usuario y contraseña son obligatorios.'];
    }

    $db = clear_db();
    if (!$db->ok()) return ['ok' => false, 'error' => 'Sin conexión a la base.'];

    // ¿Ya existe?
    $ya = $db->all("SELECT USUARIO FROM dbo.FIXALARMS_USR WHERE USUARIO = ?", [$usuario]);
    if (!empty($ya)) {
        return ['ok' => false, 'error' => 'Ya existe un usuario con ese nombre.'];
    }

    // Insertar en FIXALARMS_USR
    $db->execute("INSERT INTO dbo.FIXALARMS_USR (USUARIO, PASS) VALUES (?, '!HASHED!')", [$usuario]);
    permissions_ensure_tables();
    $db->execute("INSERT INTO dbo.CLEAR_USER_PASSWORDS(USUARIO,PASSWORD_HASH) VALUES(?,?)",[$usuario,password_hash((string)$pass,PASSWORD_DEFAULT)]);

    // Registrar el rol
    roles_ensure_table();
    $db->all("DELETE FROM dbo.CLEAR_ROLES WHERE USUARIO = ?", [$usuario]);
    $db->all("INSERT INTO dbo.CLEAR_ROLES (USUARIO, ROL) VALUES (?, ?)", [$usuario, $rol]);

    permissions_save_user($usuario, permissions_default_for_profile($rol === ROL_ADMIN ? 'admin' : 'operador'), function_exists('auth_user') ? auth_user() : '');
    audit_log('USUARIO_CREADO','usuarios',$usuario);
    return ['ok' => true];
}

/* Actualiza contraseña y/o rol de un usuario existente.
   Si $pass es '' no cambia la contraseña. */
function usuario_actualizar($usuario, $pass, $rol)
{
    $usuario = trim($usuario);
    $rol = ($rol === ROL_ADMIN) ? ROL_ADMIN : ROL_OPER;

    $db = clear_db();
    if (!$db->ok()) return ['ok' => false, 'error' => 'Sin conexión a la base.'];

    if ($pass !== '') {
        permissions_ensure_tables();
        $existsHash=(int)$db->scalar("SELECT COUNT(*) FROM dbo.CLEAR_USER_PASSWORDS WHERE USUARIO=?",[$usuario]);
        if($existsHash)$db->execute("UPDATE dbo.CLEAR_USER_PASSWORDS SET PASSWORD_HASH=?,UPDATED_AT=SYSDATETIME() WHERE USUARIO=?",[password_hash((string)$pass,PASSWORD_DEFAULT),$usuario]);
        else $db->execute("INSERT INTO dbo.CLEAR_USER_PASSWORDS(USUARIO,PASSWORD_HASH) VALUES(?,?)",[$usuario,password_hash((string)$pass,PASSWORD_DEFAULT)]);
        $db->execute("UPDATE dbo.FIXALARMS_USR SET PASS='!HASHED!' WHERE USUARIO=?",[$usuario]);
    }

    roles_ensure_table();
    // Upsert del rol
    $existe = $db->all("SELECT USUARIO FROM dbo.CLEAR_ROLES WHERE USUARIO = ?", [$usuario]);
    if (empty($existe)) {
        $db->all("INSERT INTO dbo.CLEAR_ROLES (USUARIO, ROL) VALUES (?, ?)", [$usuario, $rol]);
    } else {
        $db->all("UPDATE dbo.CLEAR_ROLES SET ROL = ? WHERE USUARIO = ?", [$rol, $usuario]);
    }

    return ['ok' => true];
}

/* Borra un usuario de ambas tablas. */
function usuario_borrar($usuario)
{
    $db = clear_db();
    if (!$db->ok()) return ['ok' => false, 'error' => 'Sin conexión a la base.'];

    $db->all("DELETE FROM dbo.FIXALARMS_USR WHERE USUARIO = ?", [$usuario]);
    $db->execute("DELETE FROM dbo.CLEAR_ROLES WHERE USUARIO = ?", [$usuario]);
    $db->execute("DELETE FROM dbo.CLEAR_USER_ACCESS WHERE USUARIO = ?", [$usuario]);
    $db->execute("DELETE FROM dbo.CLEAR_USER_PREFS WHERE USUARIO = ?", [$usuario]);
    $db->execute("DELETE FROM dbo.CLEAR_USER_PASSWORDS WHERE USUARIO = ?", [$usuario]);
    audit_log('USUARIO_ELIMINADO','usuarios',$usuario);

    return ['ok' => true];
}
