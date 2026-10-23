<?php
require __DIR__.'/../includes/well_activity.php';
class ActivityDb {
 public $calls=[];
 function all($sql,$params=[]){$this->calls[]=[$sql,$params];if(strpos($sql,'INFORMATION_SCHEMA')!==false)return array_map(fn($name)=>['COLUMN_NAME'=>$name],['ALM_ALMEXTFLD2','ALM_NATIVETIMEIN','ALM_TAGDESC','ALM_VALUE']);return [['description'=>'Comunicación','value'=>'FALLA']];}
 function scalar($sql,$params=[]){$this->calls[]=[$sql,$params];return 3;}
 function error(){return '';}
}
$db=new ActivityDb();$result=wa_alarms($db,'YPF.SC.BB-10','2026-10-22T10:00:00','2026-10-23T10:00:00');
assert($result['count24h']===3);assert($result['latest']['value']==='FALLA');assert($db->calls[1][1]===['YPF.SC.BB-10']);assert(strpos($db->calls[1][0],'LIKE')===false);assert(strpos($db->calls[1][0],'TOP (1)')!==false);assert(count($db->calls[2][1])===3);echo "OK actividad de pozo: consulta exacta, fechas y contador\n";
