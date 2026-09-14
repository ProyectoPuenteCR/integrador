<?php
/* diag_tags.php — prueba la búsqueda REAL de tags (lo que importa).
   Abrí: http://10.17.40.37/CLEAR_alarmas/diag_tags.php
   BORRALO cuando termines. */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/appconfig.php';
auth_require_admin();

header('Content-Type: text/plain; charset=utf-8');
echo "=== PRUEBA REAL DE BUSQUEDA DE TAGS ===\n\n";

$pi = pi_config();
$base = rtrim($pi['base'], '/');
$auth = pi_auth_header();
$webid = $pi['ds_webid'];

function piGet($url, $auth) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $h = ['X-Requested-With: XMLHttpRequest'];
    if ($auth) $h[] = 'Authorization: ' . $auth;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $r];
}

// Probar los 3 data servers conocidos buscando un tag genérico
$servers = [
    'F1DSVWPCbuAS9k2dgJqji44OxQMTAuMTcuNDAuMTk' => '10.17.40.19',
    'F1DSrW5oirtBXkatrT2KxUedPwMTAuMTcuNDAuMjI' => '10.17.40.22',
    'F1DSAAAAAAAAAAAAAAAAGPS-rQMTAuMTcuNDAuMzc' => '10.17.40.37',
];

$filtro = isset($_GET['q']) ? $_GET['q'] : 'SINPC';  // tag de prueba; cambialo con ?q=loquesea

foreach ($servers as $wid => $nombre) {
    echo "--- Data Server $nombre ---\n";
    $url = "$base/dataservers/$wid/points?nameFilter=*$filtro*&maxCount=10";
    list($code, $resp) = piGet($url, $auth);
    echo "  HTTP: $code\n";
    if ($code == 200) {
        $data = json_decode($resp, true);
        $n = isset($data['Items']) ? count($data['Items']) : 0;
        echo "  Tags encontrados con '*$filtro*': $n\n";
        if ($n > 0) {
            foreach (array_slice($data['Items'], 0, 5) as $it) {
                echo "     - " . $it['Name'] . "\n";
            }
        }
    } else {
        echo "  Respuesta: " . substr($resp, 0, 200) . "\n";
    }
    echo "\n";
}

echo "=== FIN ===\n";
echo "Si algun server muestra tags, ESE es el que funciona.\n";
echo "Proba con otro filtro: diag_tags.php?q=PARTE_DE_UN_TAG_REAL\n";
