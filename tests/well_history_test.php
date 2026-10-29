<?php
require_once __DIR__.'/../includes/well_history.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function rejects($fn){try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('La entrada inválida fue aceptada.');}
class WhTestDb {
    public $rows=[];public $params=[];
    function all($sql,$params){check(strpos($sql,'SELECT TOP (501)')===0,'La consulta debe ser acotada y de lectura.');$this->params=$params;return $this->rows;}
    function error(){return '';}
}
$db=new WhTestDb();$well='YPF.SC.BB-130';$id=str_repeat('A',30);$id2=str_repeat('B',30);
$db->rows=[['Name'=>'"'.$well.'(RPC)_QT:RPM.PV"','WebId'=>'"'.$id.'"','EngineeringUnits'=>'"rpm"','PointType'=>'"Float32"','Step'=>'"false"'],
    ['Name'=>'"'.$well.'(RPC)_ECO.PV"','WebId'=>'"'.$id2.'"','EngineeringUnits'=>'""','PointType'=>'"Digital"','Step'=>'"true"'],
    ['Name'=>'YPF.SC.BB-1300(RPC)_FP.PV','WebId'=>str_repeat('C',30)]];
$catalog=wh_catalog($db,$well);
check($catalog['QT:RPM']['webid']===$id&&$catalog['QT:RPM']['unit']==='rpm','Quitar comillas exteriores del catálogo.');
check($catalog['ECO']['step']===true,'Conservar estados discretos.');
check($catalog['ESTADO_RPM']['derived']==='rpm_state','Identificar estado derivado de RPM, sin inventar un tag.');
check(!$catalog['FP']['available']&&!$catalog['Fecha']['available'],'No inventar correspondencias ni confundir pozos.');
check($db->params===[$well.'(RPC)[_]%','"'.$well.'(RPC)[_]%'],'Escapar el guion bajo del prefijo.');
$db->rows[]=['Name'=>$well.'(RPC)_QT:RPM','WebId'=>str_repeat('D',30)];
check(!wh_catalog($db,$well)['QT:RPM']['available'],'Rechazar correspondencias ambiguas.');
$requestPath='';
$result=wh_history($catalog,['QT:RPM','ECO'],'2026-10-21T00:00','2026-10-22T00:00',function($path)use(&$requestPath,$id,$id2){
    $requestPath=$path;
    return ['Items'=>[
        ['WebId'=>$id,'Items'=>[
            ['Timestamp'=>'2026-10-21T03:00:00Z','Value'=>0,'Good'=>true],
            ['Timestamp'=>'2026-10-21T04:00:00Z','Value'=>750,'Good'=>false],
            ['Timestamp'=>'2026-10-21T05:00:00Z','Value'=>751,'Good'=>true,'Questionable'=>true,'Substituted'=>true]]],
        ['WebId'=>$id2,'Items'=>[['Timestamp'=>'2026-10-21T03:00:00Z','Value'=>['Name'=>'NORMAL','Value'=>0],'Good'=>true]]]
    ]];
});
check(strpos($requestPath,'/streamsets/interpolated?')===0&&strpos($requestPath,'interval=3600s')!==false,'Muestreo horario en PI.');
check(substr_count($requestPath,'webId=')===2,'Consultar todas las variables en una sola petición.');
check(strpos($requestPath,'startTime=2026-10-21T00%3A00%3A00-03%3A00')!==false&&$result['start']==='2026-10-21T00:00:00-03:00','Interpretar calendario en Argentina.');
check($result['series'][0]['samples'][0]['value']===0,'Conservar cero real.');
check(!$result['series'][0]['samples'][1]['good']&&$result['series'][0]['samples'][2]['questionable'],'Conservar calidad de PI.');
check($result['series'][1]['samples'][0]['plot_value']===0.0,'Conservar código digital cero para escala común.');
check($result['series'][1]['samples'][0]['value']==='NORMAL','Leer estados digitales como nombres.');
$derived=wh_history($catalog,['ESTADO_RPM'],'2026-10-21T00:00','2026-10-22T00:00',function()use($id){return ['Items'=>[['WebId'=>$id,'Items'=>[['Timestamp'=>'2026-10-21T03:00:00Z','Value'=>0,'Good'=>true],['Timestamp'=>'2026-10-21T04:00:00Z','Value'=>750,'Good'=>true]]]]];});
check($derived['series'][0]['samples'][0]['value']==='PARADO'&&$derived['series'][0]['samples'][1]['value']==='EN MARCHA','Aplicar la misma regla de estado que la grilla.');
$partial=wh_history($catalog,['QT:RPM','ECO'],'2026-10-21T00:00','2026-10-22T00:00',function()use($id){return ['Items'=>[['WebId'=>$id,'Items'=>[]]]];});
check(isset($partial['series'][0]['error'],$partial['series'][1]['error']),'Marcar series vacías o ausentes sin inventar datos.');
rejects(function(){wh_range('2026-02-30T00:00','2026-03-01T00:00');});
rejects(function(){wh_range('2026-10-22T00:00','2026-10-21T00:00');});
rejects(function(){wh_range('2026-01-01T00:00','2026-10-21T00:00');});
rejects(function(){wh_well("pozo%' OR 1=1--");});
rejects(function()use($catalog){wh_history($catalog,['FP'],'2026-10-21T00:00','2026-10-22T00:00',function(){throw new RuntimeException('No debe llamar PI.');});});
rejects(function()use($catalog){wh_history($catalog,[['malformado']],'2026-10-21T00:00','2026-10-22T00:00',function(){});});
rejects(function()use($catalog){wh_history($catalog,array_fill(0,13,'QT:RPM'),'2026-10-21T00:00','2026-10-22T00:00',function(){});});
$path30='';
$hourly=wh_history($catalog,['QT:RPM'],'2026-10-21T00:00','2026-10-22T00:00',function($path)use(&$path30,$id){$path30=$path;return ['Items'=>[['WebId'=>$id,'Items'=>[]]]];},30);
check(strpos($path30,'interval=30s')!==false&&$hourly['intervalSeconds']===30,'Frecuencia real de 30 segundos en PI.');
foreach([29,0,-30,86401,30.5,true,[],null,'1h','30 OR 1=1'] as $value)rejects(function()use($value){wh_interval($value);});
check(wh_interval('60')===60,'Aceptar segundos enteros.');
rejects(function()use($catalog){wh_history($catalog,['QT:RPM'],'2026-10-01T00:00','2026-10-22T00:00',function(){throw new RuntimeException('No debe consultar PI sobre el límite.');},30);});
check($derived['series'][0]['samples'][0]['plot_value']===0&&$derived['series'][0]['samples'][1]['plot_value']===1,'Estado derivado con código 0/1.');

// El origen lo determina una lista cerrada; un permiso de BM no habilita PCP.
$allowedMenus=[];
function permissions_can_menu($menu){global $allowedMenus;return in_array($menu,$allowedMenus,true);}
check(wh_source()==='monitoreo_pozos','Mantener origen BM cuando el cliente antiguo no envía source.');
check(wh_source('telemetria_pcp')==='telemetria_pcp','Aceptar el identificador real de PCP.');
foreach(['','telemetry_pcp','pi_historico','../telemetria_pcp','monitoreo_pozos ',[],null,true,1] as $source)
    rejects(function()use($source){wh_source($source);});
foreach(['monitoreo_pozos','telemetria_pcp'] as $source){
    $other=$source==='monitoreo_pozos'?'telemetria_pcp':'monitoreo_pozos';
    $allowedMenus=[$source];
    check(!wh_can_history($source)&&!wh_can_activity($source),'El permiso de la grilla no reemplaza los de históricos o alarmas.');
    $allowedMenus=[$source,'pi_historico'];
    check(wh_can_history($source)&&!wh_can_history($other),'Históricos exige permiso de la pantalla exacta.');
    foreach(['pozos_alarmas24','pozos_todas_alarmas'] as $alarmMenu){
        $allowedMenus=[$source,$alarmMenu];
        check(wh_can_activity($source)&&!wh_can_activity($other),'Alarmas exige permiso de la pantalla exacta y de alarmas.');
    }
}
$allowedMenus=['pi_historico','pozos_alarmas24','pozos_todas_alarmas'];
check(!wh_can_history('telemetria_pcp')&&!wh_can_activity('telemetria_pcp'),'Sin acceso PCP no se habilitan sus API.');
check(wh_cards_allowed('monitoreo_pozos')&&!wh_cards_allowed('telemetria_pcp'),'Las cartas dinamométricas son exclusivas de BM.');

// Candidatos de nombres, no afirmaciones sobre tags presentes en producción.
$pcpSource='telemetria_pcp';$pcpWell='YPF.SC.PCP-17';
$stateId=str_repeat('S',30);$pressureId=str_repeat('P',30);$communicationId=str_repeat('C',30);
foreach(['(PC12)_','(PC14)_','(PCP)_','_'] as $family){
    $candidateDb=new WhTestDb();
    $candidateDb->rows=[['Name'=>'"'.$pcpWell.$family.'PT:FONDO.PV"','WebId'=>'"'.$pressureId.'"','EngineeringUnits'=>'"bar"','PointType'=>'Float32','Step'=>'false']];
    $candidate=wh_catalog($candidateDb,$pcpWell,$pcpSource);
    check($candidate['PT:FONDO']['available']&&$candidate['PT:FONDO']['webid']===$pressureId,'Aceptar únicamente el candidato exacto presente en catálogo: '.$family);
    check($candidate['PT:FONDO']['tag']===$pcpWell.$family.'PT:FONDO.PV'&&$candidate['PT:FONDO']['unit']==='bar','Conservar el nombre y la unidad observados, sin conversión.');
}
$pcpDb=new WhTestDb();
$pcpDb->rows=[
    ['Name'=>$pcpWell.'(PC12)_YT:POZO.PV','WebId'=>$stateId,'PointType'=>'Digital','Step'=>'true'],
    ['Name'=>$pcpWell.'(PC12)_PT:FONDO','WebId'=>$pressureId,'EngineeringUnits'=>'bar','PointType'=>'Float32','Step'=>'false'],
    ['Name'=>$pcpWell.'(PC12)_YAT:COM.PV','WebId'=>$communicationId,'PointType'=>'Digital','Step'=>'true'],
    ['Name'=>$pcpWell.'(PC12)_QT:RPM.PV','WebId'=>str_repeat('R',30)],
    ['Name'=>$pcpWell.'0(PC12)_PT:FONDO.PV','WebId'=>str_repeat('N',30)],
    ['Name'=>$pcpWell.'(RPC)_PT:FONDO.PV','WebId'=>str_repeat('B',30)],
    ['Name'=>$pcpWell.'(PC12)_PT:FONDO.PV_EXTRA','WebId'=>str_repeat('E',30)]
];
$pcpCatalog=wh_catalog($pcpDb,$pcpWell,$pcpSource);
check(array_keys($pcpCatalog)===['YT:POZO','PT:FONDO','YAT:COM','__COMUNICACION'],'PCP publica solo sus cuatro variables, sin estado derivado de RPM.');
check($pcpCatalog['YT:POZO']['webid']===$stateId&&!isset($pcpCatalog['YT:POZO']['derived']),'Estado PCP proviene de su propio tag.');
check($pcpCatalog['PT:FONDO']['webid']===$pressureId,'No confundir pozo vecino, sufijo extra o familia RPC.');
check($pcpCatalog['YAT:COM']['webid']===$communicationId&&$pcpCatalog['__COMUNICACION']['webid']===$communicationId,'Alias de comunicación usa el mismo WebId observado.');
$pcpParams=[];
foreach(['(PC12)_','(PC14)_','(PCP)_','_'] as $family){$prefix=wh_like($pcpWell.$family);$pcpParams[]=$prefix.'%';$pcpParams[]='"'.$prefix.'%';}
check($pcpDb->params===$pcpParams,'Consulta PCP acotada a prefijos exactos y parametrizados, con guion bajo escapado.');

$excludedDb=new WhTestDb();
$excludedDb->rows=[
    ['Name'=>$pcpWell.'(RPC)_YT:POZO.PV','WebId'=>$stateId],
    ['Name'=>$pcpWell.'0(PCP)_PT:FONDO.PV','WebId'=>$pressureId],
    ['Name'=>$pcpWell.'(BES)_YAT:COM.PV','WebId'=>$communicationId],
    ['Name'=>$pcpWell.'(PCP)_YT:POZO.ALM','WebId'=>$stateId]
];
$excluded=wh_catalog($excludedDb,$pcpWell,$pcpSource);
foreach($excluded as $point)check(!$point['available']&&strpos($point['reason'],'PCP verificada')!==false,'Sin candidato exacto no inventar una correspondencia PCP.');
$bmFromPcp=wh_catalog($pcpDb,$pcpWell);
check(!$bmFromPcp['YAT:COM']['available']&&!isset($bmFromPcp['YT:POZO']),'El catálogo BM no consume tags PCP ni cambia sus columnas.');

$ambiguousDb=new WhTestDb();
$ambiguousDb->rows=[
    ['Name'=>$pcpWell.'(PC12)_PT:FONDO','WebId'=>$pressureId],
    ['Name'=>$pcpWell.'(PC14)_PT:FONDO.PV','WebId'=>str_repeat('D',30)]
];
check(!wh_catalog($ambiguousDb,$pcpWell,$pcpSource)['PT:FONDO']['available'],'Dos familias con distintos WebId son ambiguas, no se elige una por prioridad.');
$ambiguousDb->rows[1]['Name']=$pcpWell.'(PC12)_PT:FONDO';
check(!wh_catalog($ambiguousDb,$pcpWell,$pcpSource)['PT:FONDO']['available'],'Un mismo nombre con dos WebId distintos también es ambiguo.');

$pcpHistory=wh_history($pcpCatalog,['YT:POZO','PT:FONDO'],'2026-10-21T00:00','2026-10-22T00:00',function()use($stateId,$pressureId){
    return ['Items'=>[
        ['WebId'=>$stateId,'Items'=>[['Timestamp'=>'2026-10-21T03:00:00Z','Value'=>['Name'=>'PARADO','Value'=>4],'Good'=>true]]],
        ['WebId'=>$pressureId,'Items'=>[['Timestamp'=>'2026-10-21T03:00:00Z','Value'=>0,'Good'=>true]]]
    ]];
});
check($pcpHistory['series'][0]['samples'][0]['value']==='PARADO'&&$pcpHistory['series'][0]['samples'][0]['plot_value']===4.0,'Conservar nombre y código de estado PCP sin derivarlo de RPM.');
check($pcpHistory['series'][1]['samples'][0]['value']===0&&$pcpHistory['series'][1]['unit']==='bar','Conservar presión PCP cero y unidad del catálogo.');
rejects(function()use($pcpCatalog){wh_history($pcpCatalog,['ESTADO_RPM'],'2026-10-21T00:00','2026-10-22T00:00',function(){throw new RuntimeException('No debe llamar PI con una variable de BM.');});});
echo "OK: catálogo BM/PCP, origen y permisos, correspondencias exactas, aislamiento, rango, calidad, estados y errores parciales.\n";
