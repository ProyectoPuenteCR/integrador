<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';

auth_require();
permissions_require_menu('micros_contables');
auth_start();

$cfg=require __DIR__.'/config.php';
date_default_timezone_set($cfg['app']['tz']??'UTC');
$db=clear_db();
$APP_USER=auth_user();
$APP_ROLE=auth_es_admin()?'Administrador':'Operador';

function mcsel_ids($value,$limit=500){
    $ids=[];
    foreach(explode(',',(string)$value) as $raw){$id=(int)trim($raw);if($id>0)$ids[$id]=$id;if(count($ids)>=$limit)break;}
    return array_values($ids);
}
function mcsel_value($row,$key,$default=''){foreach($row as $k=>$v)if(strcasecmp((string)$k,(string)$key)===0)return $v;return $default;}

$ids=mcsel_ids($_GET['ids']??'');
$rows=[];
if($db->ok()&&$ids){
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $rows=$db->all("SELECT ID,CONVERT(varchar(10),FECHA_DATO,23) FECHA_DATO,ZONA,BATERIA,SUPERVISOR,JEFE_ZONA,OBSERVACION,CONVERT(varchar(19),FECHA_MODIFICACION,120) FECHA_MODIFICACION,USUARIO_MODIFICACION FROM dbo.CLEAR_VW_MICROS_CONTABLES WHERE ACTIVO=1 AND ID IN ($marks) ORDER BY FECHA_DATO DESC,BATERIA",$ids);
}

$body=["CLEAR - Micros contables","","Partes seleccionados: ".count($rows),""];
foreach($rows as $i=>$row){
    $body[]=($i+1).'. '.mcsel_value($row,'FECHA_DATO').' | '.(mcsel_value($row,'ZONA')?:'Sin zona').' | '.(mcsel_value($row,'BATERIA')?:'Sin batería');
    $body[]='Supervisor: '.(mcsel_value($row,'SUPERVISOR')?:'Sin asignar').' | Jefe de producción: '.(mcsel_value($row,'JEFE_ZONA')?:'Sin asignar');
    $body[]='Observaciones: '.(mcsel_value($row,'OBSERVACION')?:'Sin observaciones');
    $body[]='';
}
$emailBody=implode("\r\n",$body);
if(strlen($emailBody)>12000)$emailBody=substr($emailBody,0,11800)."\r\n\r\n[Contenido recortado por el límite del cliente de correo. Adjunte el PDF o Excel para enviar el detalle completo.]";
$mailto='mailto:?subject='.rawurlencode('CLEAR - Micros contables - '.count($rows).' parte'.(count($rows)===1?'':'s')).'&body='.rawurlencode($emailBody);
$excel='micros_contables.php?export=partes&ids='.rawurlencode(implode(',',$ids));
if($rows)audit_log('REPORTE_SELECCION','micros_contables','Reporte de '.count($rows).' partes seleccionados');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Partes seleccionados · Micros contables</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260825-mc14">
  <style>
    body{background:#eef3f6;color:#17343f}.mcReport{max-width:1250px;margin:24px auto;padding:0 18px}.mcReportBar{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:15px}.mcReportBar__actions{display:flex;gap:8px;flex-wrap:wrap}.mcReportBtn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:39px;padding:0 14px;border:1px solid var(--line-mid);border-radius:9px;background:#fff;color:var(--petrol);font-size:12px;font-weight:700;text-decoration:none;cursor:pointer}.mcReportBtn.is-primary{background:var(--petrol);border-color:var(--petrol);color:#fff}.mcReportBtn svg{width:16px;height:16px}.mcReportSheet{background:#fff;border:1px solid var(--line-mid);border-radius:14px;padding:24px;box-shadow:0 9px 30px rgba(11,74,90,.08)}.mcReportHead{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding-bottom:17px;border-bottom:3px solid var(--petrol)}.mcReportHead img{width:145px;height:auto}.mcReportHead h1{margin:0;color:var(--petrol);font-family:var(--font-head);font-size:28px}.mcReportHead p{margin-top:5px;color:var(--text-mut);font-size:12px}.mcReportMeta{text-align:right;color:var(--text-soft);font-size:11px}.mcReportTable{width:100%;margin-top:20px;border-collapse:collapse}.mcReportTable th{padding:10px;background:var(--petrol);color:#fff;text-align:left;font-size:11px}.mcReportTable td{padding:10px;border:1px solid var(--line);font-size:11px;vertical-align:top}.mcReportTable tbody tr:nth-child(even){background:#f5f9fb}.mcReportEmpty{padding:40px;text-align:center;color:var(--text-mut)}.mcReportFoot{margin-top:18px;padding-top:12px;border-top:1px solid var(--line);color:var(--text-mut);font-size:10px}@media print{@page{size:A4 landscape;margin:13mm}body{background:#fff}.mcReport{max-width:none;margin:0;padding:0}.mcReportBar{display:none}.mcReportSheet{border:0;border-radius:0;padding:0;box-shadow:none}.mcReportTable{page-break-inside:auto}.mcReportTable tr{page-break-inside:avoid}.mcReportTable thead{display:table-header-group}}
  </style>
</head>
<body>
<main class="mcReport">
  <div class="mcReportBar">
    <a class="mcReportBtn" href="micros_contables.php">← Volver a Micros contables</a>
    <div class="mcReportBar__actions">
      <button class="mcReportBtn is-primary" type="button" onclick="window.print()"><?php echo icon('file');?> Guardar PDF</button>
      <a class="mcReportBtn" href="<?php echo h($excel);?>"><?php echo icon('download');?> Exportar Excel</a>
      <a class="mcReportBtn" href="<?php echo h($mailto);?>"><?php echo icon('message');?> Email</a>
    </div>
  </div>
  <section class="mcReportSheet">
    <header class="mcReportHead">
      <div><img src="assets/img/logo-clear.jpg" alt="CLEAR Petroleum"><h1>Micros contables</h1><p>Partes seleccionados para reporte</p></div>
      <div class="mcReportMeta"><b><?php echo count($rows);?> parte<?php echo count($rows)===1?'':'s';?></b><br>Generado: <?php echo h(date('d/m/Y H:i'));?><br>Usuario: <?php echo h($APP_USER);?></div>
    </header>
    <?php if(!$rows):?>
      <div class="mcReportEmpty">No se recibieron partes válidos. Volvé a la pantalla y seleccioná al menos una fila.</div>
    <?php else:?>
      <table class="mcReportTable">
        <thead><tr><th>Fecha</th><th>Zona</th><th>Batería</th><th>Supervisor</th><th>Jefe de producción</th><th>Observaciones</th></tr></thead>
        <tbody><?php foreach($rows as $row):$fd=mcsel_value($row,'FECHA_DATO');?><tr><td><?php echo h($fd?date('d/m/Y',strtotime($fd)):'—');?></td><td><?php echo h(mcsel_value($row,'ZONA')?:'Sin vincular');?></td><td><b><?php echo h(mcsel_value($row,'BATERIA'));?></b></td><td><?php echo h(mcsel_value($row,'SUPERVISOR')?:'Sin vincular');?></td><td><?php echo h(mcsel_value($row,'JEFE_ZONA')?:'Sin vincular');?></td><td><?php echo h(mcsel_value($row,'OBSERVACION')?:'—');?></td></tr><?php endforeach;?></tbody>
      </table>
    <?php endif;?>
    <footer class="mcReportFoot">CLEAR Petroleum · Micros contables · Fuente local LC_MDB</footer>
  </section>
</main>
</body>
</html>
