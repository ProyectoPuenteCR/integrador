<?php
/* diag_proxy.php — diagnostica por qué el proxy no llega a PI.
   Abrí: http://10.17.40.37/CLEAR_alarmas/diag_proxy.php
   BORRALO cuando termines. */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/appconfig.php';
auth_require_admin();

header('Content-Type: text/plain; charset=utf-8');
echo "=== DIAGNOSTICO DEL PROXY PI ===\n\n";

$pi = pi_config();
$base = rtrim($pi['base'], '/');
$auth = pi_auth_header();

echo "Config actual:\n";
echo "  base: $base\n";
echo "  user: " . ($pi['user'] !== '' ? $pi['user'] : '(vacio)') . "\n";
echo "  pass: " . ($pi['pass'] !== '' ? '(hay contraseña guardada)' : '(vacia)') . "\n";
echo "  auth header: " . ($auth ? 'presente' : 'AUSENTE') . "\n";
echo "  ds_webid: " . ($pi['ds_webid'] !== '' ? $pi['ds_webid'] : '(vacio)') . "\n\n";

echo "Extensiones PHP:\n";
echo "  cURL: " . (function_exists('curl_init') ? 'SI disponible' : 'NO disponible') . "\n";
echo "  allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'activado' : 'DESACTIVADO') . "\n\n";

$url = $base . '/dataservers';
echo "Probando conexion a: $url\n\n";

if (function_exists('curl_init')) {
    echo "--- Con cURL ---\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $headers = ['X-Requested-With: XMLHttpRequest'];
    if ($auth) $headers[] = 'Authorization: ' . $auth;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_VERBOSE, false);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    echo "  HTTP code: $code\n";
    echo "  cURL errno: $errno\n";
    echo "  cURL error: " . ($err ?: '(ninguno)') . "\n";
    if ($resp !== false) {
        echo "  Respuesta (primeros 400 chars):\n";
        echo "  " . substr($resp, 0, 400) . "\n";
    } else {
        echo "  La respuesta fue FALSE (no conecto)\n";
    }
}

echo "\n=== FIN ===\n";
echo "\nPISTA: si arriba ves 'IsConnected: false' en los servidores,\n";
echo "el problema es que PI Web API no esta conectado a los Data Archive\n";
echo "(eso lo arregla el administrador del servidor PI, no la plataforma).\n";
