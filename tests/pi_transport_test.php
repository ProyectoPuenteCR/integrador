<?php
// Ejecutar con php -n: sustituye únicamente la red cURL, sin consultar PI real.
if (extension_loaded('curl')) { fwrite(STDERR, "Ejecutar con php -n sin la extensión cURL.\n"); exit(1); }
foreach (['CURLOPT_RETURNTRANSFER','CURLOPT_HTTPHEADER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT',
    'CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_CAINFO','CURLINFO_HTTP_CODE'] as $i => $name) define($name, $i + 1);
$network = ['status'=>200, 'body'=>'{"Items":[]}', 'error'=>0];
function curl_init($url) { $GLOBALS['url'] = $url; return new stdClass(); }
function curl_setopt_array($ch, $options) { $GLOBALS['options'] = $options; return true; }
function curl_setopt($ch, $key, $value) { $GLOBALS['options'][$key] = $value; return true; }
function curl_exec($ch) { return $GLOBALS['network']['body']; }
function curl_getinfo($ch, $key) { return $GLOBALS['network']['status']; }
function curl_errno($ch) { return $GLOBALS['network']['error']; }
function curl_close($ch) {}
require __DIR__.'/../includes/pi_transport.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function fails($fn, $type) {
    try { $fn(); } catch (Throwable $e) { check($e instanceof $type, 'Tipo de error inesperado.'); return; }
    throw new RuntimeException('Se aceptó una operación inválida.');
}
$pi = ['base'=>'https://pi.internal/piwebapi/'];
$response = clear_pi_transport('/streamsets/interpolated?webId=ABC&interval=1h', $pi, 'Basic TEST', 45);
check($response === ['body'=>'{"Items":[]}', 'status'=>200], 'Conservar respuesta PI.');
check($url === 'https://pi.internal/piwebapi/streamsets/interpolated?webId=ABC&interval=1h', 'Usar exclusivamente la base configurada.');
check(in_array('Authorization: Basic TEST', $options[CURLOPT_HTTPHEADER], true), 'Autenticación del servidor.');
check(in_array('X-Requested-With: XMLHttpRequest', $options[CURLOPT_HTTPHEADER], true), 'Compatibilidad SCADA.');
check($options[CURLOPT_TIMEOUT] === 45, 'Timeout de históricos.');
check($options[CURLOPT_SSL_VERIFYPEER] === false && $options[CURLOPT_SSL_VERIFYHOST] === 0, 'Modo SCADA autorizado.');
$pi['ca_bundle'] = __FILE__;
clear_pi_transport('/streams/ABC/value', $pi, '');
check($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'Una CA configurada debe activar validación completa.');
check($options[CURLOPT_CAINFO] === __FILE__, 'Enviar CA a cURL.');
check(!in_array('Authorization: ', $options[CURLOPT_HTTPHEADER], true), 'No enviar autenticación vacía.');
$pi['ca_bundle'] = __DIR__.'/missing-ca.pem';
fails(function() use ($pi) { clear_pi_transport('/streams/ABC/value', $pi, ''); }, RuntimeException::class);
$pi['ca_bundle'] = '';
foreach (['https://other.invalid/', '//other.invalid/', '', "/streams/ABC\r\nInjected: true"] as $path) {
    fails(function() use ($pi, $path) { clear_pi_transport($path, $pi, ''); }, InvalidArgumentException::class);
}
$network['status'] = 401;
check(clear_pi_transport('/streams/ABC/value', $pi, '')['status'] === 401, 'Conservar estado HTTP del upstream.');
$network['body'] = false; $network['error'] = 7;
fails(function() use ($pi) { clear_pi_transport('/streams/ABC/value', $pi, ''); }, RuntimeException::class);
$network['error'] = 60; $pi['ca_bundle'] = __FILE__;
fails(function() use ($pi) { clear_pi_transport('/streams/ABC/value', $pi, ''); }, RuntimeException::class);
echo "PI transport: OK\n";
