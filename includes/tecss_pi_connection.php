<?php
// Resuelve la conexión sin crear tablas ni modificar CLEAR_CONFIG.
function tecss_pi_connection(array $config, callable $loadClear, callable $env)
{
    $mode = $config['connection'] ?? 'environment';
    if ($mode === 'clear') {
        $stored = $loadClear();
        $base = rtrim((string)($stored['base'] ?? ''), '/');
        if (isset($config['base']) && rtrim($config['base'], '/') !== $base) {
            throw new RuntimeException('La base externa no coincide con la conexión PI de CLEAR.');
        }
        $config['base'] = $base;
        if (empty($config['ca_bundle'])) $config['ca_bundle'] = $stored['ca_bundle'] ?? '';
        $user = (string)($stored['user'] ?? '');
        $pass = (string)($stored['pass'] ?? '');
        $auth = ($user !== '' || $pass !== '') ? 'Basic '.base64_encode($user.':'.$pass) : ($stored['auth'] ?? '');
    } elseif ($mode === 'environment') {
        $auth = $env($config['auth_env'] ?? 'CLEAR_PI_AUTH');
    } else {
        throw new RuntimeException('connection debe ser clear o environment.');
    }
    if (!preg_match('#^https://[^/?\#]+/piwebapi/?$#i', $config['base'] ?? '') || empty($config['ca_bundle']) || !is_readable($config['ca_bundle'])) {
        throw new RuntimeException('Se requiere base HTTPS PI Web API y un ca_bundle legible que valide su certificado.');
    }
    if (!is_string($auth) || $auth === '' || preg_match('/[\r\n]/', $auth)) {
        throw new RuntimeException('Falta autenticación válida para la conexión PI seleccionada.');
    }
    if (!empty($config['tls_server_name'])) tecss_pi_tls_target($config);
    return [$config, $auth];
}

// La URL de CLEAR puede usar IP mientras el certificado identifica un nombre DNS.
// Conectar a la MISMA IP, verificando CA y nombre; no depender de resolver ese DNS.
function tecss_pi_tls_target(array $config)
{
    $parts = parse_url($config['base']);
    $name = $config['tls_server_name'];
    if (!filter_var($parts['host'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
        !is_string($name) || strlen($name) > 253 ||
        !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/i', $name) ||
        isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('tls_server_name requiere una base IPv4 y un nombre de certificado DNS válido.');
    }
    $port = $parts['port'] ?? 443;
    return ['base'=>'https://'.$name.':'.$port.($parts['path'] ?? ''), 'resolve'=>$name.':'.$port.':'.$parts['host']];
}

function tecss_pi_secure_transport($path, array $config, $auth, $timeout = 45)
{
    if (empty($config['ca_bundle']) || !is_readable($config['ca_bundle'])) throw new RuntimeException('Falta certificado CA legible para PI.');
    if (empty($config['tls_server_name'])) return clear_pi_transport($path,$config,$auth,$timeout);
    if (!function_exists('curl_init')) throw new RuntimeException('tls_server_name requiere la extensión cURL en PHP CLI.');
    if (!is_string($path) || $path === '' || $path[0] !== '/' || substr($path,0,2) === '//' || preg_match('/[\r\n]/',$path)) throw new RuntimeException('Ruta PI no permitida.');
    $target = tecss_pi_tls_target($config);
    $ch = curl_init(rtrim($target['base'],'/').$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Accept: application/json','X-Requested-With: XMLHttpRequest','Authorization: '.$auth],
        CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>$timeout,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CAINFO=>$config['ca_bundle'],
        CURLOPT_RESOLVE=>[$target['resolve']]]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $error = curl_errno($ch);
    curl_close($ch);
    if (in_array($error,[51,60,77],true)) throw new RuntimeException('No se pudo validar el certificado o nombre DNS de PI.');
    if ($body === false) throw new RuntimeException('No se pudo conectar a PI Web API.');
    return ['body'=>$body,'status'=>$status];
}

function tecss_pi_load_clear_connection($db, array $app)
{
    $pi = $app['pi'] ?? [];
    // Sólo SELECT; no llamar a pi_config(), porque puede crear CLEAR_CONFIG.
    $exists = $db->scalar("SELECT OBJECT_ID(N'dbo.CLEAR_CONFIG', N'U')");
    if ($db->error()) throw new RuntimeException('No se pudo comprobar la configuración PI de CLEAR.');
    $rows = $exists ? $db->all("SELECT CLAVE,VALOR FROM dbo.CLEAR_CONFIG WHERE CLAVE IN ('pi_base','pi_user','pi_pass','pi_ca_bundle')") : [];
    if ($db->error()) throw new RuntimeException('No se pudo leer la configuración PI de CLEAR.');
    $keys = ['pi_base'=>'base','pi_user'=>'user','pi_pass'=>'pass','pi_ca_bundle'=>'ca_bundle'];
    // El sitio utiliza usuario/contraseña de CLEAR_CONFIG o auth de config.php.
    $pi['user'] = '';
    $pi['pass'] = '';
    foreach ($rows as $row) {
        if (isset($keys[$row['CLAVE']]) && $row['VALOR'] !== null && $row['VALOR'] !== '') {
            $pi[$keys[$row['CLAVE']]] = $row['VALOR'];
        }
    }
    return $pi;
}

function tecss_pi_well_map(array $rows, array $aliases = [])
{
    $map = [];
    foreach ($rows as $row) {
        $key = tecss_pi_well_key($row['POZO']);
        if ($key === '' || isset($map[$key])) throw new RuntimeException('Catálogo de pozos ambiguo.');
        $map[$key] = $row['POZO'];
    }
    $catalog = $map;
    foreach ($aliases as $source=>$destination) {
        if (!is_string($source) || !is_string($destination)) throw new RuntimeException('Correspondencia de pozo inválida.');
        $sourceKey = tecss_pi_well_key($source);
        $destinationKey = tecss_pi_well_key($destination);
        if ($sourceKey === '' || !isset($catalog[$destinationKey]) || $catalog[$destinationKey] !== $destination) {
            throw new RuntimeException('El destino de una correspondencia no existe exactamente en CLEAR.');
        }
        if (isset($map[$sourceKey]) && $map[$sourceKey] !== $destination) throw new RuntimeException('Una correspondencia reemplaza la identidad de otro pozo.');
        $map[$sourceKey] = $destination;
    }
    return $map;
}
