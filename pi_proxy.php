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
require_once __DIR__ . '/includes/pi_transport.php';

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

// La misma conexión se utiliza en SCADA e históricos de pozos.
$app = require __DIR__ . '/config.php';
$pi['ca_bundle'] = config_get('pi_ca_bundle', $app['pi']['ca_bundle'] ?? '');
try {
    $response = clear_pi_transport($path, $pi, $auth);
    http_response_code($response['status']);
    echo $response['body'];
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['Errors' => [$e->getMessage()]], JSON_UNESCAPED_UNICODE);
}