<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "=== INFORMACIÓN PHP ===\r\n";
echo "PHP: " . PHP_VERSION . "\r\n";
echo "php.ini: " . (php_ini_loaded_file() ?: 'No detectado') . "\r\n";
echo "OpenSSL cargado: " . (extension_loaded('openssl') ? 'SÍ' : 'NO') . "\r\n";
echo "Versión OpenSSL: " . (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'No disponible') . "\r\n\r\n";

echo "=== TRANSPORTES DISPONIBLES ===\r\n";
$transports = stream_get_transports();
print_r($transports);

echo "\r\n";

if (!in_array('tcp', $transports, true)) {
    exit("ERROR: El transporte TCP no está disponible.\r\n");
}

if (!in_array('ssl', $transports, true) && !in_array('tls', $transports, true)) {
    exit(
        "ERROR: OpenSSL aparece cargado, pero PHP no registró los transportes ssl/tls.\r\n" .
        "Revisar la instalación de PHP y reiniciar IIS.\r\n"
    );
}

echo "=== CONEXIÓN SMTP GMAIL 587 ===\r\n";

$errno = 0;
$errstr = '';

$socket = @stream_socket_client(
    'tcp://smtp.gmail.com:587',
    $errno,
    $errstr,
    20,
    STREAM_CLIENT_CONNECT
);

if (!$socket) {
    exit("ERROR DE CONEXIÓN: [$errno] $errstr\r\n");
}

stream_set_timeout($socket, 20);

function leerRespuestaSMTP($socket)
{
    $respuesta = '';

    while (!feof($socket)) {
        $linea = fgets($socket, 515);

        if ($linea === false) {
            break;
        }

        $respuesta .= $linea;

        // La última línea SMTP tiene un espacio después del código.
        if (strlen($linea) >= 4 && $linea[3] === ' ') {
            break;
        }
    }

    return $respuesta;
}

echo "Servidor:\r\n";
echo leerRespuestaSMTP($socket);

fwrite($socket, "EHLO clear.local\r\n");

echo "\r\nRespuesta EHLO:\r\n";
$ehlo = leerRespuestaSMTP($socket);
echo $ehlo;

if (stripos($ehlo, 'STARTTLS') === false) {
    fclose($socket);
    exit("\r\nERROR: Gmail no ofreció STARTTLS en esta conexión.\r\n");
}

fwrite($socket, "STARTTLS\r\n");

echo "\r\nRespuesta STARTTLS:\r\n";
$starttls = leerRespuestaSMTP($socket);
echo $starttls;

if (substr($starttls, 0, 3) !== '220') {
    fclose($socket);
    exit("\r\nERROR: El servidor SMTP no aceptó STARTTLS.\r\n");
}

/*
 * Seleccionar un método disponible según la versión de PHP.
 */
if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    echo "\r\nMétodo seleccionado: TLS 1.2\r\n";
} elseif (defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')) {
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
    echo "\r\nMétodo seleccionado: TLS cliente\r\n";
} elseif (defined('STREAM_CRYPTO_METHOD_SSLv23_CLIENT')) {
    $cryptoMethod = STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
    echo "\r\nMétodo seleccionado: SSLv23/TLS compatible\r\n";
} else {
    fclose($socket);
    exit("\r\nERROR: Esta versión de PHP no dispone de un método TLS cliente compatible.\r\n");
}

$resultado = @stream_socket_enable_crypto(
    $socket,
    true,
    $cryptoMethod
);

echo "Resultado de negociación TLS: ";
var_dump($resultado);

if ($resultado === true) {
    echo "\r\nCORRECTO: TLS se inició correctamente con Gmail.\r\n";
} elseif ($resultado === 0) {
    echo "\r\nTLS todavía necesita más datos. La negociación no finalizó.\r\n";
} else {
    echo "\r\nERROR: No se pudo iniciar TLS.\r\n";

    while ($error = openssl_error_string()) {
        echo "OpenSSL: " . $error . "\r\n";
    }

    $meta = stream_get_meta_data($socket);

    echo "\r\nMetadatos del socket:\r\n";
    print_r($meta);
}

fclose($socket);