<?php
// Pruebas sin PI ni SQL: ejecutar con php -n tests/tecss_pi_reader_test.php.
require __DIR__.'/../includes/tecss_pi_reader.php';
require __DIR__.'/../includes/tecss_vibraciones_query.php';
require __DIR__.'/../includes/tecss_vibraciones_pi.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function rejected($fn) { try { $fn(); } catch (RuntimeException $e) { return; } throw new RuntimeException('Debió rechazar datos incompletos.'); }
check(tecss_pi_date('2026-10-26T06:46:00.192Z') === '2026-10-26 03:46:00.192', 'Conversión UTC/Argentina');
check(tecss_pi_date('9999-12-31T23:59:59Z', true) === null, 'Evento abierto');
rejected(function(){tecss_pi_date('2026-10-26 03:46:00');});
check(tecss_pi_scaled(0.067215) === 67.215, 'Umbral por 1000 una sola vez');
check(tecss_pi_scaled(['Name'=>'No Data']) === null, 'No convertir estados digitales en cero');
check(tecss_pi_well_key('YPF.SC.LH-945') === 'LH-945', 'Correspondencia con catálogo CLEAR');
check(tecss_pi_well_key('BB-945') !== tecss_pi_well_key('LH-945'), 'No cruzar pozos de igual número');

$scenario = 'closed';
$requests = [];
$reader = new TecssPiReader(function($url) use (&$scenario, &$requests) {
    $requests[] = $url;
    $path = parse_url($url, PHP_URL_PATH);
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
    if ($path === '/assetdatabases') return ['WebId'=>'DB'];
    if ($path === '/assetdatabases/DB/eventframes') {
        check($q['searchMode'] === 'Overlapped', 'Incluir eventos iniciados antes del período');
        return ['Items'=>[['WebId'=>'EF','Name'=>'LH-945 evento','TemplateName'=>'3Sigma_Superado',
            'StartTime'=>'2026-10-26T06:46:00.192Z','EndTime'=>$scenario==='open'?'9999-12-31T23:59:59Z':'2026-10-26T10:16:15.142Z']]];
    }
    if ($path === '/eventframes/EF/attributes') return ['Items'=>[['Name'=>'POZO','WebId'=>'WELL']]];
    if ($path === '/streams/WELL/value') return ['Good'=>true,'Value'=>'YPF.SC.LH-945'];
    if ($path === '/eventframes/EF/referencedelements') return ['Items'=>[['WebId'=>'ELEMENT', 'Path'=>$scenario==='wrong'?'\\\\AF\\DB\\BB-945\\TECSS':'\\\\AF\\DB\\YPF.SC.LH-945\\TECSS']]];
    if ($path === '/elements/ELEMENT/attributes') return ['Items'=>[['Name'=>'VI-001-V','WebId'=>'VIB'],['Name'=>'3SIGMA','WebId'=>'LIMIT']]];
    if (in_array($path, ['/streams/VIB/value','/streams/LIMIT/value'], true)) {
        check($q['time'] === '2026-10-26T06:46:00.192Z', 'Consultar inicio, no valor actual ni final del evento');
        return ['Good'=>$scenario!=='bad', 'Value'=>$path==='/streams/VIB/value'?0.114:0.067215];
    }
    if ($path === '/eventframes/EF/annotations') {
        if ($scenario==='denied') throw new RuntimeException('HTTP 403');
        return ['Items'=>[
            ['Creator'=>'otro usuario','Value'=>'Notification sent to 1 endpoint','CreationDate'=>'2026-10-26T09:46:00Z'],
            ['Creator'=>'NT SERVICE\\PINotificationsService','Value'=>'Notification sent to 1 endpoint','CreationDate'=>'2026-10-26T09:46:21Z']]];
    }
    throw new RuntimeException('Ruta inesperada: '.$path);
});
$map = ['LH-945'=>'LH-945'];
$collect = function() use ($reader,$map){return $reader->collect('DB','3Sigma_Superado','2026-10-25T00:00:00-03:00','2026-10-26T14:00:00-03:00',$map);};
check($reader->database('\\\\AF\\IUP') === 'DB', 'Resolver base AF');
$events = $collect();
check(count($events)===1 && $events[0]['vibration']===114.0 && $events[0]['threshold']===67.215, 'LH-945 reproducido con valores de inicio');
check($events[0]['notification']==='2026-10-26 06:46:21.000', 'Sólo evidencia del servicio de notificaciones');
check($events[0]['end']==='2026-10-26 07:16:15.142', 'Cierre real');
$scenario='open'; check($collect()[0]['end']===null, 'Mantener evento abierto');
$scenario='bad'; check($collect()[0]['vibration']===null, 'Calidad mala no convertida a un dato válido');
$scenario='denied'; $event=$collect()[0]; check($event['annotations_read']===0 && $event['notification']===null, 'Permiso faltante no significa correo no enviado');
$scenario='wrong'; rejected($collect);

$pageRequests=0;
$paged = new TecssPiReader(function($url) use (&$pageRequests){
    $pageRequests++;parse_str(parse_url($url,PHP_URL_QUERY),$q);
    return ['Items'=>array_fill(0, $q['startIndex']==0?500:1, ['WebId'=>'X'])];
});
check(count($paged->items('/collection'))===501 && $pageRequests===2, 'Paginación completa');
$broken = new TecssPiReader(function(){return ['Error'=>'incomplete'];});
rejected(function()use($broken){$broken->items('/collection');});
$missing = new TecssPiReader(function(){throw new TecssPiNotFound('404');});
check($missing->frame('REMOVED')===null, 'Un abierto borrado en PI puede retirarse de la copia');
$denied = new TecssPiReader(function(){throw new RuntimeException('403');});
rejected(function()use($denied){$denied->frame('DENIED');});
// Una relectura de abierto antiguo reemplaza su versión obtenida por la búsqueda.
$scenario='closed';
$extra=[['WebId'=>'EF','Name'=>'LH-945 cierre actualizado','TemplateName'=>'3Sigma_Superado','StartTime'=>'2026-10-26T06:46:00.192Z','EndTime'=>'2026-10-26T10:16:15.142Z']];
$reconciled=$reader->collect('DB','3Sigma_Superado','2026-10-25T00:00:00-03:00','2026-10-26T14:00:00-03:00',$map,$extra);
check(count($reconciled)===1 && $reconciled[0]['name']==='LH-945 cierre actualizado', 'Reconciliar abiertos sin duplicar eventos');

$sync=['available'=>true,'stale'=>false,'from'=>date('Y-m-d H:i:s',time()-90*86400)];
check(tecss_vib_pi_label(['available'=>false])==='Sin sincronizar PI', 'No mostrar normal sin fuente');
check(tecss_vib_pi_label($sync,['ABIERTOS'=>1])==='Evento PI abierto', 'Estado PI independiente de CLEAR');
$sync['stale']=true;
check(tecss_vib_pi_label($sync,['ABIERTOS'=>1])==='PI pendiente de actualizar', 'No afirmar estado actual con copia vieja');

class FixtureDb {
    public $queries=[];
    public function ok(){return true;}
    public function scalar($sql){return 1;}
    public function error(){return '';}
    public function all($sql,$params=[]){
        $this->queries[]=[$sql,$params];
        if (strpos($sql,'EDAD_SEG')!==false) return [['EDAD_SEG'=>2,'ULTIMO_ERROR'=>0,'ULTIMO_OK'=>date('Y-m-d H:i:s'),'DESDE'=>'2026-10-01 00:00:00','HASTA'=>date('Y-m-d H:i:s')]];
        return [['WEBID'=>'EF','NOMBRE'=>'evento','INICIO'=>'2026-10-26 03:46:00','FIN'=>'2026-10-26 07:16:15','VIBR_INICIO'=>114,'UMBRAL_INICIO'=>67.215,'ENVIO_REGISTRADO'=>null,'ANOTACIONES_LEIDAS'=>0]];
    }
}
$db=new FixtureDb();$detail=tecss_vib_pi_detail($db,'LH-945','2026-10-25 00:00:00','2026-10-27 00:00:00');
check($detail['events'][0]['threshold_start']===67.215, 'API no escala por segunda vez');
$query=end($db->queries);check($query[1]===['LH-945','2026-10-27 00:00:00','2026-10-25 00:00:00'] && strpos($query[0],'FIN IS NULL OR FIN>=?')!==false,'Rango solapado y pozo parametrizado');
echo "OK: lector PI, escalas, fechas, identidad, paginación, calidad, anotaciones y caché.\n";
