<?php
require_once __DIR__.'/../includes/cartas_diarias.php';
function verify($condition,$message){if(!$condition)throw new RuntimeException($message);}
$cut=new DateTimeImmutable('2026-11-05T06:00:00-03:00');
verify(cd_cutoff(new DateTimeImmutable('2026-11-05T05:59:59-03:00'))->format('Y-m-d H:i')==='2026-11-04 06:00','Antes de las 06 usar corte anterior.');
verify(cd_cutoff($cut)->format('Y-m-d H:i')==='2026-11-05 06:00','A las 06 usar corte de hoy.');
$card=['kind'=>'superficie','reference'=>'2026-11-04T06:00:00-03:00','xUnit'=>'in','yUnit'=>'lbf','points'=>[['x'=>0,'y'=>10],['x'=>100,'y'=>20],['x'=>0,'y'=>15]]];
verify(cd_status($card,$cut)==='Con datos','Exactamente 24 horas todavía válido.');
$old=$card;$old['reference']='2026-11-04T05:59:59-03:00';
verify(cd_status($old,$cut)==='Sin datos en las últimas 24 h','Más de 24 horas marcar sin datos.');
verify(cd_status([],$cut)==='Sin datos','Sin carta no dibujar datos.');
$roster=cd_roster([['POZO'=>'P1','BATERIA'=>'BAT 02'],['POZO'=>'P2','BATERIA'=>'BAT 01'],['POZO'=>'P1','BATERIA'=>'bat 02'],['POZO'=>'P3','BATERIA'=>null],['POZO'=>'P4','BATERIA'=>'BAT 01'],['POZO'=>'P4','BATERIA'=>'BAT 02']]);
verify(count($roster)===4,'Eliminar duplicados de pozo.');
verify($roster[0]['well']==='P2','Ordenar por batería.');
$conflict=array_values(array_filter($roster,function($w){return $w['well']==='P4';}))[0];
verify($conflict['battery']==='BATERÍA POR VERIFICAR'&&$conflict['warning']!=='','No adivinar batería ante conflicto.');
$catalog=[];foreach(['TIME:SUP','ZT:SUP-POS','WT:SUP-LOAD'] as $i=>$name)$catalog[$name]=['available'=>true,'webid'=>str_repeat(chr(65+$i),25),'tag'=>$name,'unit'=>$i===1?'in':'lbf','step'=>false];
$calls=0;
$request=function($path)use(&$calls){
    $calls++;
    if(strpos($path,'maxCount=65')!==false)return ['Items'=>[
        ['Timestamp'=>'2026-11-05T08:50:00Z','Good'=>true,'Value'=>'05/11/2026 05:50:00'],
        ['Timestamp'=>'2026-11-04T08:30:00Z','Good'=>true,'Value'=>'04/11/2026 05:30:00']
    ]];
    // La referencia más reciente no tiene datos: buscar la carta previa.
    if(strpos($path,'2026-11-05')!==false)return ['Items'=>[]];
    return ['Items'=>[
        ['Timestamp'=>'2026-11-04T08:29:30Z','Good'=>true,'Value'=>0],
        ['Timestamp'=>'2026-11-04T08:30:00Z','Good'=>true,'Value'=>100],
        ['Timestamp'=>'2026-11-04T08:30:30Z','Good'=>true,'Value'=>0]
    ]];
};
$result=cd_collect_kind($catalog,'superficie',$cut,$request);
verify(cd_has_card($result['card']),'Buscar carta anterior si la última referencia no tiene pares.');
verify($result['card']['reference']==='2026-11-04T05:30:00-03:00','Usar fecha de carta válida, no de captura.');
verify($calls===5,'Una búsqueda de referencias y dos pares de consultas acotadas.');
$sameCalls=0;$saved=$result['card'];
$same=cd_collect_kind($catalog,'superficie',$cut,function($path)use(&$sameCalls){$sameCalls++;return ['Items'=>[['Timestamp'=>'2026-11-04T08:30:00Z','Good'=>true,'Value'=>'04/11/2026 05:30:00']]];},$saved);
verify($sameCalls===1&&$same['card']===$saved,'Reutilizar carta archivada sin releer puntos XY.');
$failed=cd_collect_kind($catalog,'superficie',$cut,function(){throw new RuntimeException('Sin conexión PI');},$saved);
verify($failed['card']===$saved&&$failed['warning']==='Sin conexión PI','Una falla no borra la última carta válida.');
$missing=cd_collect_kind([],'fondo',$cut,function(){throw new Exception('No llamar PI sin catálogo');});
verify(!cd_has_card($missing['card'])&&strpos($missing['warning'],'Falta tag')!==false,'Falta de mapeo se distingue de falta de datos.');
$tooLate=cd_collect_kind($catalog,'superficie',$cut,function($path){return ['Items'=>[['Timestamp'=>'2026-11-05T09:00:00Z','Good'=>true,'Value'=>'05/11/2026 06:00:00']]];});
verify(!cd_has_card($tooLate['card']),'No construir una ventana con muestras posteriores al corte.');
$bounded=cd_collect_kind($catalog,'superficie',$cut,function(){return ['Items'=>[],'Links'=>['Next'=>'more']];},$saved);
verify(strpos($bounded['warning'],'limitada')!==false&&$bounded['card']===$saved,'No confundir límites de búsqueda con ausencia de datos.');
try{cd_collect_kind($catalog,'superficie',$cut,function(){throw new Exception('Falla global PI');},$saved);throw new RuntimeException('Se ocultó una falla global.');}catch(Exception $fatal){verify($fatal->getMessage()==='Falla global PI','Una falla global debe abortar la publicación.');}
$gap=$card;$gap['points']=[['x'=>0,'y'=>10],['x'=>1,'y'=>11],['x'=>null,'y'=>null],['x'=>2,'y'=>12],['x'=>3,'y'=>13]];
$svg=cd_svg($gap);
verify(strpos($svg,'M')!==false&&strpos($svg,'L')!==false,'Dibujar carta XY.');
preg_match('/class="cd-curve [^"]*" d="([^"]+)"/',$svg,$match);
verify(substr_count($match[1],'M')===2,'Cortar curva ante huecos; no unir muestras sin calidad.');
$unsafe=$card;$unsafe['xUnit']='<script>alert(1)</script>';
verify(strpos(cd_svg($unsafe),'<script>')===false,'Escapar unidades antes de escribir SVG.');
echo "OK: cortes 06:00, umbral 24 h, baterías, última válida, caché, fallas PI, límites y SVG.\n";
