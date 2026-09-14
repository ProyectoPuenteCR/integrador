<?php
require_once '/app/includes/novedades_gestion.php';
function auth_user(){return 'tester';}
function auth_es_admin(){return $GLOBALS['admin']??true;}
function permissions_can($right){return $GLOBALS['rights'][$right]??false;}
function check($ok,$label){if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['passed'][]=$label;}
function rejected(callable $fn,$contains,$label){try{$fn();}catch(RuntimeException $e){check(strpos($e->getMessage(),$contains)!==false,$label.' message: '.$e->getMessage());return;}throw new RuntimeException('FAIL: '.$label.' was accepted');}
class TestDB {
 public $old=[],$queries=[],$failHistory=false,$count=0,$saved=[];
 function ok(){return true;}function error(){return '';}
 function scalar($sql,$params=[]){$this->queries[]=[$sql,$params];return strpos($sql,'COUNT(*)')!==false?$this->count:1;}
 function execute($sql,$params=[]){check(substr_count($sql,'?')===count($params),'SQL execute parameter count');$this->queries[]=[$sql,$params];return !$this->failHistory||strpos($sql,'INSERT INTO dbo.CLEAR_NS_GESTIONES_HISTORIAL')===false;}
 function all($sql,$params=[]){
  check(substr_count($sql,'?')===count($params),'SQL query parameter count');$this->queries[]=[$sql,$params];
  if(strpos($sql,'sys.tables')!==false){$out=[];foreach(['CLEAR_SUPERVISORES_INSTALACIONES'=>['BATERIA','ZONA','SUPERVISOR','JEFE_ZONA','ACTIVO','JEFE_PRODUCCION'],'FIXALARMS_USR'=>['USUARIO'],'CLEAR_USER_ACCESS'=>['USUARIO','ACTIVO'],'CLEAR_NS_RESPONSABLES'=>['NOMBRE','ACTIVO']] as $table=>$cols)foreach($cols as $col)$out[]=['ESQUEMA'=>'dbo','TABLA'=>$table,'COLUMNA'=>$col];return $out;}
  if(strpos($sql,'FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES')!==false)return [
   ['BATERIA'=>'BAT 01','ZONA'=>'LH Zona LHCG','SUPERVISOR'=>'Ana Campo','JEFE_ZONA'=>'Jefa Norte','JEFE_PRODUCCION'=>'','ACTIVO'=>1],
   ['BATERIA'=>'BAT 02','ZONA'=>'LH Zona LHCG','SUPERVISOR'=>'Bruno Campo','JEFE_ZONA'=>'Jefa Norte','JEFE_PRODUCCION'=>'','ACTIVO'=>1],
   ['BATERIA'=>'BAT 03','ZONA'=>'CED Zona I','SUPERVISOR'=>'Ana Campo','JEFE_ZONA'=>'Jefe Sur','JEFE_PRODUCCION'=>'','ACTIVO'=>1],
   ['BATERIA'=>'BAT 04','ZONA'=>'CED Zona I','SUPERVISOR'=>'Carlos Campo','JEFE_ZONA'=>'Antiguo jefe','JEFE_PRODUCCION'=>'Jefe Sur','ACTIVO'=>1]
  ];
  if(strpos($sql,'FROM dbo.FIXALARMS_USR')!==false)return [['USUARIO'=>'operador1'],['USUARIO'=>'tester']];
  if(strpos($sql,'FROM dbo.CLEAR_NS_RESPONSABLES')!==false){if(strpos($sql,'WHERE NOMBRE=?')!==false)return $params[0]==='Técnico Externo'?[['ID'=>1,'NOMBRE'=>'Técnico Externo','ACTIVO'=>1]]:[];return [['NOMBRE'=>'Técnico Externo']];}
  if(strpos($sql,'INSERT INTO dbo.CLEAR_NS_RESPONSABLES')!==false)return [['ID'=>2,'NOMBRE'=>$params[0]]];
  if(strpos($sql,'SELECT ID,USUARIO_CARGA FROM dbo.CLEAR_NS_GESTIONES')!==false)return [];
  if(strpos($sql,'FROM dbo.CLEAR_NS_GESTIONES WITH')!==false)return $this->old?[$this->old]:[];
  if(strpos($sql,'INSERT INTO dbo.CLEAR_NS_GESTIONES(')!==false||strpos($sql,'UPDATE dbo.CLEAR_NS_GESTIONES SET')!==false){$this->saved=$params;return [['ID'=>5,'VERSION'=>($this->old['VERSION']??0)+1]];}
  throw new RuntimeException('Unmocked SQL: '.$sql);
 }
}
$GLOBALS['passed']=[];$now=new DateTimeImmutable('2026-08-28');$db=new TestDB();$catalog=ngr_catalog($db);
check(count($catalog['pairs'])===4,'catalog relations from local master');
check($catalog['batteries'][0]['JEFE_PRODUCCION']==='Jefa Norte','fallback JEFE_ZONA');
check($catalog['batteries'][3]['JEFE_PRODUCCION']==='Jefe Sur','explicit production chief wins');
check($catalog['people']['PLATAFORMA']===['operador1','tester'],'active username projection');
foreach($db->queries as $q)check(!preg_match('/OPENQUERY|LINKEDSERVER|\bPASS\b/i',$q[0]),'no remote sources or passwords');
$base=['ID'=>0,'VERSION'=>0,'SOLICITUD'=>str_repeat('a',32),'FECHA'=>'2026-08-28','ESTADO'=>'PENDIENTE','REQUERIMIENTO'=>'Revisar señal','RESPONSABLE'=>'Jefa Norte','RESPONSABLE_TIPO'=>'JEFE_PRODUCCION','SUPERVISORES'=>['Ana Campo','Bruno Campo']];
$row=ng_validate($base,'REQUERIMIENTO',$now);$row=ngr_resolve($row,[],$catalog);
check($row['SUPERVISORES']===['Ana Campo','Bruno Campo']&&$row['JEFE_PRODUCCION']==='Jefa Norte','chief can assign several supervisors');
$bad=$base;$bad['SUPERVISORES']=['Carlos Campo'];rejected(function()use($bad,$now,$catalog){ngr_resolve(ng_validate($bad,'REQUERIMIENTO',$now),[],$catalog);},'no pertenece','reject other chief supervisor');
$sup=$base;$sup['RESPONSABLE_TIPO']='SUPERVISOR';$sup['RESPONSABLE']='Ana Campo';$sup['JEFE_PRODUCCION']='Jefe Sur';
check(ngr_resolve(ng_validate($sup,'REQUERIMIENTO',$now),[],$catalog)['JEFE_PRODUCCION']==='Jefe Sur','multi chief supervisor selected chief accepted');
$bad=$sup;$bad['JEFE_PRODUCCION']='Inventado';rejected(function()use($bad,$now,$catalog){ngr_resolve(ng_validate($bad,'REQUERIMIENTO',$now),[],$catalog);},'relacionados','reject forged chief');
$bad=$base;$bad['RESPONSABLE_TIPO']='PLATAFORMA';$bad['RESPONSABLE']='inactive';rejected(function()use($bad,$now,$catalog){ngr_resolve(ng_validate($bad,'REQUERIMIENTO',$now),[],$catalog);},'catálogo','reject inactive or fabricated user');
$platform=$base;$platform['RESPONSABLE_TIPO']='PLATAFORMA';$platform['RESPONSABLE']='operador1';$row=ngr_resolve(ng_validate($platform,'REQUERIMIENTO',$now),[],$catalog);check($row['SUPERVISORES']===[]&&$row['JEFE_PRODUCCION']==='','irrelevant hierarchy cleared');
$guard=$base;$guard['RESPONSABLE_TIPO']='GUARDADO';$guard['RESPONSABLE']='Técnico Externo';check(ngr_resolve(ng_validate($guard,'REQUERIMIENTO',$now),[],$catalog)['RESPONSABLE']==='Técnico Externo','saved responsible accepted');
$legacy=$base;$legacy['ID']=5;$legacy['VERSION']=1;$legacy['RESPONSABLE_TIPO']='LEGADO';$legacy['RESPONSABLE']='Persona anterior';$legacy['SUPERVISORES']=[];$row=ng_validate($legacy,'REQUERIMIENTO',$now);$old=$row;$old['RESPONSABLE_TIPO']='';check(ngr_resolve($row,$old,$catalog)['RESPONSABLE']==='Persona anterior','legacy name preserved on edit');
$bad=$row;$bad['RESPONSABLE']='Nombre inventado';rejected(function()use($bad,$old,$catalog){ngr_resolve($bad,$old,$catalog);},'catálogo','cannot assign new legacy name');
$audit=$base;$audit['ZONA']='LHCG';$audit['BATERIA']='BAT 01';$audit['SUPERVISOR']='Ana Campo';$audit['JEFE_PRODUCCION']='Jefa Norte';$row=ng_validate($audit,'AUDITORIA',$now);check(ngr_resolve($row,[],$catalog)['SUPERVISOR']==='Ana Campo','audit hierarchy and zone valid');
$bad=$audit;$bad['JEFE_PRODUCCION']='Jefe Sur';rejected(function()use($bad,$now,$catalog){ngr_resolve(ng_validate($bad,'AUDITORIA',$now),[],$catalog);},'zona','audit cannot cross zone');
$bad=$base;$bad['SUPERVISORES']=array_fill(0,31,'Ana Campo');rejected(function()use($bad,$now){ng_validate($bad,'REQUERIMIENTO',$now);},'30','limit multi supervisors');
$bad=$base;$bad['SUPERVISORES']=['<script>'];rejected(function()use($bad,$now,$catalog){ngr_resolve(ng_validate($bad,'REQUERIMIENTO',$now),[],$catalog);},'no pertenece','reject injected assignment');
check(ngr_add(new TestDB(),['NOMBRE'=>'Técnico Externo'],'tester')['existing'],'duplicate saved without insert');
$added=ngr_add(new TestDB(),['NOMBRE'=>'Nuevo Técnico'],'tester');check(!$added['existing']&&$added['nombre']==='Nuevo Técnico','new responsible persisted');
$db=new TestDB();$saved=ng_store($db,'REQUERIMIENTO',$base,$now,'tester');check($saved['id']===5&&$db->saved[9]==='JEFE_PRODUCCION'&&json_decode($db->saved[10],true)===['Ana Campo','Bruno Campo'],'store assignment and multi list');
$pub=ng_public(array_merge(ng_validate($base,'REQUERIMIENTO',$now),['ID'=>5,'USUARIO_CARGA'=>'tester']));check($pub['SUPERVISORES_TEXTO']==='Ana Campo; Bruno Campo'&&$pub['RESPONSABLE_CLASE']==='Jefe de producción','public report columns include hierarchy');
$db=new TestDB();$db->old=array_merge(ng_validate($base,'REQUERIMIENTO',$now),['ID'=>5,'VERSION'=>1,'USUARIO_CARGA'=>'tester']);$update=$base;$update['ID']=5;$update['VERSION']=1;ng_store($db,'REQUERIMIENTO',$update,$now,'tester');check($db->saved[9]==='JEFE_PRODUCCION','update placeholders and assignment');
$db=new TestDB();$db->failHistory=true;rejected(function()use($db,$base,$now){ng_store($db,'REQUERIMIENTO',$base,$now,'tester');},'historial','history failure rolls back');check(strpos(end($db->queries)[0],'ROLLBACK')!==false,'rollback executed after history failure');
$db=new TestDB();$db->old=['ID'=>5,'VERSION'=>2,'USUARIO_CARGA'=>'tester'];rejected(function()use($db,$update,$now){ng_store($db,'REQUERIMIENTO',$update,$now,'tester');},'Otro usuario','optimistic lock');
$GLOBALS['admin']=false;$GLOBALS['rights']=[];rejected(function(){ngr_add(new TestDB(),['NOMBRE'=>'Unauthorized'],'viewer');},'permiso','read only cannot add contact');
rejected(function()use($base,$now){ng_store(new TestDB(),'REQUERIMIENTO',$base,$now,'viewer');},'permiso','read only cannot create');
$GLOBALS['rights']=['comments.edit_own'=>true];$db=new TestDB();$db->old=['ID'=>5,'VERSION'=>1,'USUARIO_CARGA'=>'someone'];rejected(function()use($db,$update,$now){ng_store($db,'REQUERIMIENTO',$update,$now,'tester');},'permiso','cannot edit someone else record');
$GLOBALS['admin']=true;
$poz=['SEMANA_DESDE'=>'2026-08-26','ZONA'=>'LHCG','SUPERVISOR'=>'Ana Campo','JEFE_PRODUCCION'=>'Jefa Norte','BATERIA'=>'BAT 01','POZO'=>'DEMO01','ESTADO'=>'MANUAL','TAG'=>'','ID'=>0,'VERSION'=>0];check(pfp_validate($poz,$now)['TAG']==='','well TAG is optional');check(pfa_max_bytes()===5242880,'attachment limit 5 MiB');
check(pfa_filename('informe.pdf')==='informe.pdf','allowed attachment');rejected(function(){pfa_filename('../x.php');},'nombre','path traversal file rejected');rejected(function(){pfa_filename('script.php');},'Formato','executable extension rejected');
echo json_encode(['passed'=>count($GLOBALS['passed']),'checks'=>$GLOBALS['passed']],JSON_UNESCAPED_UNICODE);
