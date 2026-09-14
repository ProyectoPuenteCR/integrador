<?php
/* =============================================================
   CLEAR PLATAFORMA — pi_proxy.php
   Proxy entre el navegador y la PI Web API.
   El navegador llama acá; este PHP llama a PI con la credencial
   (que NUNCA sale del servidor) e ignora el certificado autofirmado.

   Uso desde JS:
     pi_proxy.php?path=/dataservers/XXX/points?nameFilter=*ABC*
   El parámetro 'path' es la ruta DESPUÉS de la base de PI.
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/appconfig.php';

auth_require(); // solo usuarios logueados

header('Content-Type: application/json; charset=utf-8');

$pi   = pi_config();
$base = rtrim($pi['base'], '/');
$auth = pi_auth_header();

// La ruta pedida (todo lo que va después de la base de PI)
$path = $_GET['path'] ?? '';
if ($path === '') {
    http_response_code(400);
    echo json_encode(['Errors' => ['Falta el parámetro path']]);
    exit;
}

// Seguridad: solo permitimos rutas que empiecen con / y sean del propio PI.
// No permitimos URLs absolutas hacia otros hosts.
if (preg_match('#^https?://#i', $path)) {
    http_response_code(400);
    echo json_encode(['Errors' => ['Ruta no permitida']]);
    exit;
}
if ($path[0] !== '/') $path = '/' . $path;

$url = $base . $path;

// --- Llamada a PI usando cURL (ignora el certificado autofirmado) ---
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // certificado autofirmado
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $headers = ['X-Requested-With: XMLHttpRequest'];
    if ($auth) $headers[] = 'Authorization: ' . $auth;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        http_response_code(502);
        echo json_encode(['Errors' => ['No se pudo conectar a PI: ' . $err]]);
        exit;
    }
    http_response_code($httpCode ?: 200);
    echo $resp;
    exit;
}

// --- Fallback con file_get_contents si no hay cURL ---
$ctx = stream_context_create([
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    'http' => [
        'method' => 'GET',
        'header' => ($auth ? "Authorization: $auth\r\n" : '') . "X-Requested-With: XMLHttpRequest\r\n",
        'timeout' => 30,
        'ignore_errors' => true,
    ],
]);
$resp = @file_get_contents($url, false, $ctx);
if ($resp === false) {
    http_response_code(502);
    echo json_encode(['Errors' => ['No se pudo conectar a PI (sin cURL).']]);
    exit;
}
echo $resp;
