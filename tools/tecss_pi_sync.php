<?php
// Sólo CLI. Por defecto lee y valida; --apply publica la copia en SQL local.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/tecss_pi_reader.php';
require_once __DIR__.'/../includes/tecss_pi_connection.php';
require_once __DIR__.'/../includes/tecss_vibraciones_query.php';
require_once __DIR__.'/../includes/tecss_vibraciones_pi.php';

$options = getopt('', ['config:', 'apply', 'full', 'well:', 'help']);
if (isset($options['help']) || empty($options['config'])) {
    echo "php tools/tecss_pi_sync.php --config=RUTA_EXTERNA.json [--apply] [--full] [--well=LH-945 (sólo validación)]\n";
    exit(isset($options['help']) ? 0 : 1);
}
$db = null;
$locked = false;
try {
    $config = json_decode(file_get_contents($options['config']), true, 512, JSON_THROW_ON_ERROR);
    $days = (int)($config['days'] ?? 90);
    if (isset($options['apply'], $options['well'])) throw new RuntimeException('--well sólo se admite sin --apply; no publica coberturas parciales.');
    if ($days < 2 || $days > 120 || empty($config['af_path']) || ($config['template'] ?? '') !== '3Sigma_Superado') {
        throw new RuntimeException('Revisar days (2..120), af_path y template en la configuración.');
    }
    require_once __DIR__.'/../includes/db.php';
    $db = clear_db();
    if (!$db->ok()) throw new RuntimeException('SQL local no disponible.');
    list($config, $auth) = tecss_pi_connection($config, function() use ($db) {
        $app = require __DIR__.'/../config.php';
        return tecss_pi_load_clear_connection($db, $app);
    }, 'getenv');
    $wellRows = $db->all('SELECT POZO FROM dbo.CLEAR_TECSS_VIBRACIONES_ESTADO');
    if (!$wellRows || $db->error()) throw new RuntimeException('No se pudo obtener el catálogo de pozos CLEAR.');
    $wellMap = tecss_pi_well_map($wellRows, $config['well_aliases'] ?? []);
    if (isset($options['apply'])) {
        if (!tecss_vib_pi_ready($db)) throw new RuntimeException('Instalar primero SQL/CLEAR_TECSS_VIBRACIONES_PI.sql.');
        $lock = $db->scalar("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=N'CLEAR_TECSS_PI_SYNC',@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0; SELECT @r");
        if (!is_numeric($lock) || (int)$lock < 0 || $db->error()) throw new RuntimeException('Otra sincronización PI está en curso.');
        $locked = true;
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
    $retention = $now->modify('-'.$days.' days');
    $from = $retention;
    $coverage = $retention;
    $old = [];
    $source = $config['base'].'|'.$config['af_path'].'|'.$config['template'];
    if (tecss_vib_pi_ready($db)) {
        $metadata = $db->all('SELECT FUENTE,DESDE,HASTA FROM dbo.CLEAR_TECSS_VIBRACIONES_PI_SYNC WHERE ID=1');
        if ($db->error()) throw new RuntimeException('No se pudo consultar la cobertura PI.');
        $old = $metadata[0] ?? [];
        if ($old && $old['FUENTE'] !== $source) throw new RuntimeException('La caché pertenece a otra fuente PI; revisar antes de reemplazarla.');
        if ($old && !isset($options['full']) && !isset($options['well'])) {
            $oldThrough = new DateTimeImmutable(tecss_vib_text($old['HASTA']), $now->getTimezone());
            if ($oldThrough > $now) throw new RuntimeException('La fecha de sincronización es futura; revisar el reloj.');
            $from = max($retention, $oldThrough->modify('-6 hours'));
            $coverage = max($retention, new DateTimeImmutable(tecss_vib_text($old['DESDE']), $now->getTimezone()));
        }
    }
    $reader = new TecssPiReader(function($path) use ($config, $auth) {
        $response = tecss_pi_secure_transport($path, $config, $auth, 45);
        if ($response['status'] === 404) throw new TecssPiNotFound('Objeto PI no encontrado.');
        if ($response['status'] !== 200) throw new RuntimeException('Consulta PI falló con HTTP '.$response['status']);
        return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    });
    $database = $reader->database($config['af_path']);
    $extra = [];
    if ($old && !isset($options['full']) && !isset($options['well'])) {
        $opened = $db->all('SELECT WEBID FROM dbo.CLEAR_TECSS_VIBRACIONES_PI_EVENTO WHERE FIN IS NULL AND INICIO<?', [$from->format('Y-m-d H:i:s.v')]);
        if ($db->error()) throw new RuntimeException('No se pudieron consultar los eventos abiertos PI.');
        foreach ($opened as $row) { $frame = $reader->frame($row['WEBID']); if ($frame) $extra[] = $frame; }
    }
    $nameFilter = null;
    if (isset($options['well'])) {
        $key = tecss_pi_well_key($options['well']);
        if (!isset($wellMap[$key]) || !preg_match('/^[A-Z0-9.-]+$/', $key)) throw new RuntimeException('Pozo de validación inválido.');
        $nameFilter = '*'.$key.'-*';
    }
    $events = $reader->collect($database, $config['template'], $from->format(DATE_ATOM), $now->format(DATE_ATOM), $wellMap, $extra, $nameFilter);
    $start = $from->format('Y-m-d H:i:s.v');
    $through = $now->format('Y-m-d H:i:s.v');
    if (!isset($options['apply'])) {
        echo json_encode(['mode'=>'validacion_sin_escrituras','from'=>$start,'through'=>$through,'events'=>$events], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
        exit(0);
    }
    $exec = function($sql, $params=[]) use ($db) {
        if (!$db->execute($sql, $params)) throw new RuntimeException('No se pudo publicar la copia local PI.');
    };
    $exec('SET XACT_ABORT ON; BEGIN TRANSACTION');
    // Reconciliar sólo la ventana leída íntegramente; refresca también eventos abiertos antiguos.
    $exec('DELETE FROM dbo.CLEAR_TECSS_VIBRACIONES_PI_EVENTO WHERE INICIO<=? AND (FIN IS NULL OR FIN>=?)', [$through,$start]);
    foreach ($events as $event) {
        // Una corrección en PI puede mover un evento fuera de la ventana anterior.
        $exec('DELETE FROM dbo.CLEAR_TECSS_VIBRACIONES_PI_EVENTO WHERE WEBID=?', [$event['id']]);
        $exec('INSERT INTO dbo.CLEAR_TECSS_VIBRACIONES_PI_EVENTO (WEBID,POZO,NOMBRE,INICIO,FIN,VIBR_INICIO,UMBRAL_INICIO,ENVIO_REGISTRADO,ANOTACIONES_LEIDAS,FECHA_SYNC) VALUES (?,?,?,?,?,?,?,?,?,SYSDATETIME())',
            [$event['id'],$event['well'],$event['name'],$event['start'],$event['end'],$event['vibration'],$event['threshold'],$event['notification'],$event['annotations_read']]);
    }
    $exec('DELETE FROM dbo.CLEAR_TECSS_VIBRACIONES_PI_EVENTO WHERE FIN<?', [$coverage->format('Y-m-d H:i:s.v')]);
    $exec('DELETE FROM dbo.CLEAR_TECSS_VIBRACIONES_PI_SYNC WHERE ID=1');
    $exec('INSERT INTO dbo.CLEAR_TECSS_VIBRACIONES_PI_SYNC (ID,FUENTE,DESDE,HASTA,ULTIMO_OK,ULTIMO_INTENTO,ULTIMO_ERROR) VALUES (1,?,?,?,SYSDATETIME(),SYSDATETIME(),0)', [$source,$coverage->format('Y-m-d H:i:s.v'),$through]);
    $exec('COMMIT TRANSACTION');
    echo count($events)." eventos PI publicados.\n";
} catch (Throwable $error) {
    if ($db && $locked) {
        $db->execute('IF @@TRANCOUNT>0 ROLLBACK TRANSACTION');
        $db->execute('UPDATE dbo.CLEAR_TECSS_VIBRACIONES_PI_SYNC SET ULTIMO_INTENTO=SYSDATETIME(),ULTIMO_ERROR=1 WHERE ID=1');
    }
    // No imprimir respuestas HTTP, configuración ni credenciales.
    fwrite(STDERR, "No se completó la sincronización PI: ".$error->getMessage()."\n");
    exit(1);
} finally {
    if ($db && $locked) $db->execute("EXEC sys.sp_releaseapplock @Resource=N'CLEAR_TECSS_PI_SYNC',@LockOwner='Session'");
}
