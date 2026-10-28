<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/tecss_3sigma_events.php';
require_once __DIR__ . '/includes/tecss_3sigma_trend.php';
require_once __DIR__ . '/includes/tecss_3sigma_range.php';

auth_require();
permissions_require_menu('tecss_3sigma');
$app=require __DIR__ . '/config.php';
date_default_timezone_set($app['app']['tz']??'America/Argentina/Buenos_Aires');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function sigma_history_reply($ok,$payload=[],$status=200)
{
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok],$payload),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$well=trim((string)($_GET['pozo']??''));
$startText=trim((string)($_GET['desde']??''));
$endText=trim((string)($_GET['hasta']??''));
if($well===''||strlen($well)>255)sigma_history_reply(false,['error'=>'Pozo inválido.'],400);
try{
    list($start,$end)=sigma_history_dates($startText,$endText);
}catch(InvalidArgumentException $error){sigma_history_reply(false,['error'=>$error->getMessage()],400);}

$db=clear_db();
if(!$db->ok())sigma_history_reply(false,['error'=>$db->error()],503);
if((int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.TECSS_TECSSAIB_HISTORICO',N'U') IS NULL THEN 0 ELSE 1 END")!==1)sigma_history_reply(false,['error'=>'No existe la tabla histórica TECSS.'],503);
if((int)$db->scalar("SELECT CASE WHEN COL_LENGTH(N'dbo.TECSS_TECSSAIB_HISTORICO',N'POZO_BUSQUEDA') IS NULL THEN 0 ELSE 1 END")!==1)sigma_history_reply(false,['error'=>'Falta ejecutar la actualización SQL de 3Sigma TECSS.'],503);
if((int)$db->scalar("SELECT COUNT(*) FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.TECSS_TECSSAIB_HISTORICO') AND name=N'IX_TECSS_HIST_POZO_FECHA'")!==1)sigma_history_reply(false,['error'=>'Falta el índice de histórico 3Sigma. Ejecutá nuevamente el script SQL de actualización.'],503);

$sql="SELECT TOP (2000)
            [FECHA_CAPTURA],[HOY],[POZO],[BATERIA],[3SIGMA],[CONT_EXCESOS],[METODO]
      FROM dbo.TECSS_TECSSAIB_HISTORICO WITH (INDEX(IX_TECSS_HIST_POZO_FECHA))
      WHERE [POZO_BUSQUEDA]=?
        AND [FECHA_CAPTURA]>=?
        AND [FECHA_CAPTURA]<?
      ORDER BY [FECHA_CAPTURA] ASC";
$rows=$db->all($sql,[$well,$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]);
if(!$rows&&$db->error())sigma_history_reply(false,['error'=>$db->error()],500);

$items=[];
foreach($rows as $row){
    $sigmaRaw=trim((string)($row['3SIGMA']??''));
    $excessRaw=trim((string)($row['CONT_EXCESOS']??''));
    $sigmaNormalized=str_replace(',','.',$sigmaRaw);
    $excessNormalized=str_replace(',','.',$excessRaw);
    $capture=$row['FECHA_CAPTURA']??'';
    if($capture instanceof DateTimeInterface)$capture=$capture->format('Y-m-d H:i:s');
    $today=$row['HOY']??'';
    if($today instanceof DateTimeInterface)$today=$today->format('Y-m-d H:i:s');
    $items[]=[
        'capture'=>(string)$capture,
        'capture_iso'=>$capture!==''?(new DateTimeImmutable((string)$capture))->format(DATE_ATOM):null,
        'today'=>(string)$today,
        'well'=>(string)($row['POZO']??$well),
        'battery'=>(string)($row['BATERIA']??''),
        'sigma'=>$sigmaRaw,
        'sigma_numeric'=>is_numeric($sigmaNormalized)?(float)$sigmaNormalized:null,
        'excess'=>$excessRaw,
        'excess_numeric'=>is_numeric($excessNormalized)?(float)$excessNormalized:null,
        'method'=>(string)($row['METODO']??''),
    ];
}

$events=[];$warnings=[];$eventsAvailable=false;$trend=['available'=>false];$get=null;
try{
    $connection=sigma_af_connection($db,$app);
    $get=sigma_af_getter($connection);
    $audit=sigma_af_collect($get,$well,$start,$end);
    $events=$audit['events'];$warnings=$audit['warnings'];$eventsAvailable=true;
}catch(Throwable $error){
    $warnings[]='Eventos y auditoría AF no disponibles. Los valores SQL se muestran sin presumir alertas ni envíos. '.$error->getMessage();
}
try{
    if(!$get)throw new RuntimeException('Sin conexión AF.');
    $trend=sigma_af_trend($get,$well,$start,$end);
    $warnings=array_merge($warnings,$trend['warnings']);
}catch(Throwable $error){$warnings[]='Duraciones y vibración PI no disponibles. '.$error->getMessage();}
if(count($items)===2000)$warnings[]='Se alcanzó el límite de 2.000 valores SQL; reducí el rango para consultar todos.';
sigma_history_reply(true,['pozo'=>$well,'count'=>count($items),'limit'=>2000,'items'=>$items,
    'events'=>$events,'events_available'=>$eventsAvailable,'warnings'=>$warnings,'trend'=>$trend]);
