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
echo "OK: catálogo, correspondencias, rango, muestreo horario, calidad, estados y errores parciales.\n";
