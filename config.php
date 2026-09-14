<?php
/* =============================================================
   CLEAR PLATAFORMA — config.php
   Datos de conexión a SQL Server.
   EDITÁ ESTOS VALORES con los de tu servidor.
   (Son los mismos que ya usa tu PHPRunner.)
============================================================= */

return [
    'db' => [
        'host'     => '10.17.40.37',   // servidor SQL
        'database' => 'LC_MDB',        // base de datos
        'user'     => 'fix',           // usuario
        'password' => 'fix1234',       // contraseña
        // driver: 'sqlsrv' (recomendado PHP 7.4) o 'pdo_sqlsrv'
        'driver'   => 'com',     // 'com' (PHPRunner) | 'sqlsrv' | 'pdo_sqlsrv'
    ],

    'app' => [
        'name'    => 'CLEAR PLATAFORMA',
        'user'    => 'CLEAR01',
        'role'    => 'Administrador',
        // zona horaria para fechas
        'tz'      => 'America/Argentina/Buenos_Aires',
        // Usuarios que son administradores por defecto si la tabla CLEAR_ROLES
        // todavía no tiene un rol asignado para ellos. Poné acá tu/s usuario/s
        // admin inicial/es (tal como figuran en FIXALARMS_USR). Ej: 'CLEAR'.
        'admins_iniciales' => ['CLEAR'],
    ],

    // PI Web API (para la pantalla PI Histórico)
    'pi' => [
        'base'     => 'https://10.17.40.37/piwebapi',
        'ds_webid' => 'F1DSVWPCbuAS9k2dgJqji44OxQMTAuMTcuNDAuMTk',
        // Credencial Basic para PI Web API (base64 de usuario:contraseña).
        // OJO: esto viaja al navegador. Ver nota de seguridad en LEEME.
        'auth'     => 'Basic YWRtaW46JFByb3llY3RvQW5kZXMyMDI1JA==',
    ],
];
