<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';

auth_require();
permissions_require_menu('tecss_3sigma');
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
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$startText)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$endText))sigma_history_reply(false,['error'=>'Rango de fechas inválido.'],400);

try{
    $start=new DateTimeImmutable($startText.' 00:00:00');
    $end=new DateTimeImmutable($endText.' 00:00:00');
}catch(Throwable $error){sigma_history_reply(false,['error'=>'Rango de fechas inválido.'],400);}
if($start>$end)sigma_history_reply(false,['error'=>'La fecha Desde no puede ser posterior a Hasta.'],400);
if((int)$start->diff($end)->format('%a')>366)sigma_history_reply(false,['error'=>'El rango máximo permitido es de 366 días.'],400);

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
$rows=$db->all($sql,[$well,$start->format('Y-m-d H:i:s'),$end->modify('+1 day')->format('Y-m-d H:i:s')]);
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

sigma_history_reply(true,['pozo'=>$well,'count'=>count($items),'limit'=>2000,'items'=>$items]);
