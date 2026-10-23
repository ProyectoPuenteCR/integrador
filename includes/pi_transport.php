<?php
/* Transporte compartido con SCADA. Conserva la compatibilidad del proxy PI
 * existente con el certificado autofirmado de la instalación. Si se configura
 * una CA, verifica tanto la cadena como el nombre del servidor. */
function clear_pi_transport($path, array $pi, $auth, $timeout = 30)
{
    if (!is_string($path) || $path === '' || $path[0] !== '/' || substr($path, 0, 2) === '//' || preg_match('/[\r\n]/', $path)) {
        throw new InvalidArgumentException('Ruta PI no permitida.');
    }
    $url = rtrim($pi['base'], '/') . $path;
    $ca = trim((string)($pi['ca_bundle'] ?? ''));
    $verify = $ca !== '';
    if ($verify && !is_readable($ca)) throw new RuntimeException('No se puede leer el archivo de certificados configurado para PI.');
    $headers = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'];
    if ($auth !== '') $headers[] = 'Authorization: ' . $auth;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0]);
        if ($verify) curl_setopt($ch, CURLOPT_CAINFO, $ca);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);
        curl_close($ch);
        if (in_array($error, [51, 60, 77], true)) throw new RuntimeException('No se pudo validar el certificado de PI con la CA configurada.');
        if ($body === false) throw new RuntimeException('No se pudo conectar a PI Web API.');
    } else {
        $ssl = ['verify_peer' => $verify, 'verify_peer_name' => $verify];
        if ($verify) $ssl['cafile'] = $ca;
        $ctx = stream_context_create(['ssl' => $ssl, 'http' => ['method' => 'GET',
            'header' => implode("\r\n", $headers), 'timeout' => $timeout,
            'ignore_errors' => true, 'follow_location' => 0]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) $status = (int)$match[1];
        }
        if ($body === false) throw new RuntimeException('No se pudo conectar a PI Web API.');
    }
    return ['body' => $body, 'status' => $status ?: 200];
}
