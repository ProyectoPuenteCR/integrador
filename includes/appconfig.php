<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/appconfig.php
   Configuración editable desde la web, en tabla dbo.CLEAR_CONFIG.
   Blindado: si la tabla no existe o no se puede crear, NO rompe
   la página; cae a los valores de config.php.
============================================================= */

require_once __DIR__ . '/db.php';

function config_ensure_table()
{
    $db = clear_db();
    if (!$db->ok()) return false;
    try {
        $sql = "IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='CLEAR_CONFIG' AND xtype='U')
                CREATE TABLE dbo.CLEAR_CONFIG (
                  CLAVE NVARCHAR(64)   NOT NULL PRIMARY KEY,
                  VALOR NVARCHAR(512)  NULL
                )";
        $db->all($sql);
        return true;
    } catch (Throwable $e) {
        return false; // sin permiso CREATE TABLE: seguimos sin romper
    }
}

function config_get($clave, $default = '')
{
    $db = clear_db();
    if (!$db->ok()) return $default;
    try {
        $rows = $db->all("SELECT VALOR FROM dbo.CLEAR_CONFIG WHERE CLAVE = ?", [$clave]);
        if (empty($rows)) return $default;
        $v = $rows[0]['VALOR'];
        return ($v === null || $v === '') ? $default : $v;
    } catch (Throwable $e) {
        return $default; // tabla inexistente u otro error: usar default
    }
}

function config_set($clave, $valor)
{
    $db = clear_db();
    if (!$db->ok()) return false;
    if (!config_ensure_table()) return false;
    try {
        $existe = $db->all("SELECT CLAVE FROM dbo.CLEAR_CONFIG WHERE CLAVE = ?", [$clave]);
        if (empty($existe)) {
            $db->all("INSERT INTO dbo.CLEAR_CONFIG (CLAVE, VALOR) VALUES (?, ?)", [$clave, $valor]);
        } else {
            $db->all("UPDATE dbo.CLEAR_CONFIG SET VALOR = ? WHERE CLAVE = ?", [$valor, $clave]);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function pi_config()
{
    $cfg = require dirname(__DIR__) . '/config.php';
    $fallback = $cfg['pi'] ?? [];
    config_ensure_table();
    return [
        'base'     => config_get('pi_base',     $fallback['base']     ?? 'https://10.17.40.37/piwebapi'),
        'ds_webid' => config_get('pi_ds_webid', $fallback['ds_webid'] ?? ''),
        'user'     => config_get('pi_user',     ''),
        'pass'     => config_get('pi_pass',     ''),
        'auth_fallback' => $fallback['auth'] ?? '',
    ];
}

function pi_auth_header()
{
    $pi = pi_config();
    if ($pi['user'] !== '' || $pi['pass'] !== '') {
        return 'Basic ' . base64_encode($pi['user'] . ':' . $pi['pass']);
    }
    return $pi['auth_fallback'];
}

/* --- Visibilidad del menú ---
   Guarda en CLEAR_CONFIG (clave 'menu_oculto') la lista de keys ocultas,
   separadas por coma. Si está vacío, se muestran todas. */

function menu_ocultos()
{
    $val = config_get('menu_oculto', '');
    if ($val === '') return [];
    $arr = array_map('trim', explode(',', $val));
    return array_filter($arr, function ($x) { return $x !== ''; });
}

function menu_set_ocultos(array $keys)
{
    // Normalizar: sin duplicados, sin vacíos
    $keys = array_values(array_unique(array_filter(array_map('trim', $keys))));
    return config_set('menu_oculto', implode(',', $keys));
}

/* ¿Esta key del menú está oculta? */
function menu_esta_oculto($key)
{
    return in_array($key, menu_ocultos(), true);
}
