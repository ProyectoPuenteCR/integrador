<?php
require __DIR__.'/../includes/well_cards.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function rejects($fn){try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Se aceptó entrada inválida.');}
$x=wh_card_samples([
    ['Timestamp'=>'2026-10-22T17:59:00.100000Z','Value'=>0,'Good'=>true],
    ['Timestamp'=>'2026-10-22T17:59:00.500000Z','Value'=>100,'Good'=>true],
    ['Timestamp'=>'2026-10-22T17:59:00.900000Z','Value'=>0,'Good'=>true]
]);
$y=wh_card_samples([
    ['Timestamp'=>'2026-10-22T17:59:00.000000Z','Value'=>30000,'Good'=>true],
    ['Timestamp'=>'2026-10-22T17:59:01.000000Z','Value'=>60000,'Good'=>true]
]);
$pairs=wh_card_pairs($x,$y,false,$y[0]['time'],$y[1]['time']);
check(array_column($pairs,'x')===[0.0,100.0,0.0],'Conservar orden temporal y regreso de la carrera: no ordenar por X.');
check(abs($pairs[1]['y']-45000)<0.1,'Interpolar carga al timestamp de posición, conservando fracciones de segundo.');
$stepped=wh_card_pairs($x,$y,true,$y[0]['time'],$y[1]['time']);
check($stepped[1]['y']===30000.0,'Respetar Step del punto Y.');
$y[1]['value']=null;$bad=wh_card_pairs($x,$y,false,$y[0]['time'],$y[1]['time']);
check($bad[1]['y']===null,'No interpolar a través de un valor con mala calidad.');
$outside=wh_card_pairs($x,[],false,$x[0]['time'],$x[2]['time']);
check($outside[0]['y']===null,'No extrapolar cuando faltan muestras de carga.');
$point=['available'=>true,'webid'=>str_repeat('A',30),'tag'=>'REAL_X','unit'=>'in','step'=>false];
$catalog=['ZT:SUP'=>$point,'WT:SUP'=>array_merge($point,['webid'=>str_repeat('B',30),'tag'=>'REAL_Y','unit'=>'lb'])];
$paths=[];
$result=wh_cards($catalog,'2026-10-22T15:00',60,function($path)use(&$paths,$x){$paths[]=$path;return ['Items'=>array_map(function($sample){return ['Timestamp'=>$sample['timestamp'],'Value'=>$sample['value'],'Good'=>true];},$x)];});
check(count($paths)===2,'Consultar solamente tags verificados; fondo sin catálogo no debe llamar a PI.');
check(strpos($paths[0],'/recorded?')!==false&&strpos($paths[0],'boundaryType=Outside')!==false,'Usar archivo comprimido con límites para emparejar.');
check(count($result['cards'][0]['points'])===3,'Devolver pares válidos de superficie.');
check(isset($result['cards'][1]['error'])&&!$result['cards'][1]['points'],'No dibujar cartas ficticias cuando falta el tag.');
rejects(function(){wh_cards([],'2026-02-30T00:00',60,function(){});});
rejects(function(){wh_cards([],'2026-10-22T15:00',301,function(){});});
rejects(function(){wh_cards([],'2026-10-22T15:00','60',function(){});});
$truncated=wh_cards($catalog,'2026-10-22T15:00',60,function(){return ['Items'=>[],'Links'=>['Next'=>'/next']];});
check(isset($truncated['cards'][0]['error']),'No aceptar páginas truncadas como carta completa.');
echo "Cartas XY: OK (timestamp, calidad, límites y fuentes verificadas).\n";
