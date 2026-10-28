<?php
require_once __DIR__.'/../includes/tecss_pi_connection.php';
require_once __DIR__.'/../includes/tecss_pi_reader.php';
function check_connection($ok) { if (!$ok) throw new RuntimeException('Falló una verificación de conexión.'); }
function rejects_connection(callable $run) {
    try { $run(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Se aceptó una conexión inválida.');
}
$ca = tempnam(sys_get_temp_dir(), 'pi-ca-test-');
try {
    $stored = ['base'=>'https://pi.example/piwebapi','ca_bundle'=>$ca,'auth'=>'Basic fixture'];
    $load = function() use (&$stored) { return $stored; };
    $noEnv = function() { throw new RuntimeException('No debe pedir una credencial nueva.'); };
    list($config,$auth) = tecss_pi_connection(['connection'=>'clear'], $load, $noEnv);
    check_connection($config['base'] === $stored['base'] && $auth === 'Basic fixture');
    $stored['user'] = 'fixture-user'; $stored['pass'] = 'fixture-pass';
    list($config,$auth) = tecss_pi_connection(['connection'=>'clear'], $load, $noEnv);
    check_connection($auth === 'Basic '.base64_encode('fixture-user:fixture-pass'));
    rejects_connection(function() use ($load,$noEnv) { tecss_pi_connection(['connection'=>'clear','base'=>'https://other.example/piwebapi'], $load, $noEnv); });
    $stored['ca_bundle'] = '';
    rejects_connection(function() use ($load,$noEnv) { tecss_pi_connection(['connection'=>'clear'], $load, $noEnv); });
    $legacy = ['base'=>'https://pi.example/piwebapi','ca_bundle'=>$ca];
    list($config,$auth) = tecss_pi_connection($legacy, $noEnv, function($key) { check_connection($key==='CLEAR_PI_AUTH'); return 'Basic environment'; });
    check_connection($auth==='Basic environment');
    $target = tecss_pi_tls_target(['base'=>'https://10.17.40.37/piwebapi','tls_server_name'=>'SWPCRDLHSCD51']);
    check_connection($target['base']==='https://SWPCRDLHSCD51:443/piwebapi' && $target['resolve']==='SWPCRDLHSCD51:443:10.17.40.37');
    rejects_connection(function() { tecss_pi_tls_target(['base'=>'https://other.example/piwebapi','tls_server_name'=>'SWPCRDLHSCD51']); });
    rejects_connection(function() { tecss_pi_tls_target(['base'=>'https://10.17.40.37/piwebapi','tls_server_name'=>'wrong/name']); });
    rejects_connection(function() { tecss_pi_tls_target(['base'=>'https://user:pass@10.17.40.37/piwebapi','tls_server_name'=>'SWPCRDLHSCD51']); });
    rejects_connection(function() use ($legacy,$noEnv) { tecss_pi_connection($legacy,$noEnv,function() { return "Basic fixture\r\nX: injected"; }); });
    $fakeDb = new class {
        public $queries = [];
        public $failed = false;
        public function scalar($sql) { $this->queries[]=$sql; return 1; }
        public function all($sql) { $this->queries[]=$sql; return [['CLAVE'=>'pi_base','VALOR'=>'https://configured.example/piwebapi'],['CLAVE'=>'pi_user','VALOR'=>'configured-user'],['CLAVE'=>'pi_pass','VALOR'=>'']]; }
        public function error() { return $this->failed; }
    };
    $pi = tecss_pi_load_clear_connection($fakeDb,['pi'=>['base'=>'https://fallback.example/piwebapi','auth'=>'Basic fallback','user'=>'unused','pass'=>'unused']]);
    check_connection($pi['base']==='https://configured.example/piwebapi' && $pi['user']==='configured-user' && $pi['pass']==='' && $pi['auth']==='Basic fallback');
    foreach ($fakeDb->queries as $query) check_connection(strpos($query,'SELECT ')===0);
    $fakeDb->failed = true;
    rejects_connection(function() use ($fakeDb) { tecss_pi_load_clear_connection($fakeDb,[]); });
    $catalog = [['POZO'=>'CG-457[00]'],['POZO'=>'LH-945']];
    $map = tecss_pi_well_map($catalog,['CG-457'=>'CG-457[00]']);
    check_connection($map['CG-457']==='CG-457[00]' && $map['LH-945']==='LH-945');
    check_connection(!isset(tecss_pi_well_map($catalog)['CG-457']));
    rejects_connection(function() use ($catalog) { tecss_pi_well_map($catalog,['CG-457'=>'missing']); });
    rejects_connection(function() use ($catalog) { tecss_pi_well_map($catalog,['LH-945'=>'CG-457[00]']); });
    rejects_connection(function() { tecss_pi_well_map([['POZO'=>'LH-945'],['POZO'=>'YPF.SC.LH-945']]); });
    echo "Conexión PI: OK (configuración existente, aislamiento y fallos seguros).\n";
} finally {
    unlink($ca);
}
