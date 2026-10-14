<?php
/* CLEAR - Paleta global de estados de telemetria. PHP 7.4. */
function clear_colors_catalog(){
 return [
 'BASE'=>['titulo'=>'Etiquetas generales','detalle'=>'Estilos comunes de todas las grillas de telemetria.','items'=>[
 'success'=>['Exito / marcha','#DFF4EA','#14754F'],'danger'=>['Alerta / detenido','#FDE5E3','#AA302B'],
 'warning'=>['Advertencia','#FFF0D5','#9A6410'],'info'=>['Informacion','#E5F0FB','#286B9E'],
 'neutral'=>['Sin dato','#EDF0F3','#617080']]],
 'PARO_REMOTO'=>['titulo'=>'Paro remoto','detalle'=>'Estados de paro remoto para pozos.','items'=>[
 'Habilitado'=>['Habilitado','#DFF4EA','#14754F'],'Deshabilitado'=>['Deshabilitado','#FDE5E3','#AA302B'],
 'Sin dato'=>['Sin dato','#EDF0F3','#617080']]],
 'COMUNICACION'=>['titulo'=>'Comunicacion','detalle'=>'Estados de comunicacion del pozo.','items'=>[
 'Comunicando'=>['Comunicando','#E5F0FB','#286B9E'],'Sin comunicación'=>['Sin comunicacion','#FDE5E3','#AA302B'],
 'Demorada'=>['Demorada','#FFF0D5','#9A6410'],'Sin dato'=>['Sin dato','#EDF0F3','#617080']]],
 'ESTADO_POZO'=>['titulo'=>'Estado del pozo','detalle'=>'Estado operativo informado por telemetria.','items'=>[
 'Marcha'=>['Marcha','#DFF4EA','#14754F'],'Parado'=>['Parado','#FDE5E3','#AA302B'],
 'Bombeo Host'=>['Bombeo Host','#DFF4EA','#14754F'],'Bombeo normal'=>['Bombeo normal','#DFF4EA','#14754F'],
 'Inactivo por HOA Off'=>['Inactivo por HOA Off','#FDE5E3','#AA302B'],'Sin dato'=>['Sin dato','#EDF0F3','#617080']]],
 'ESTADO_GENERAL'=>['titulo'=>'Estado general','detalle'=>'Columna ESTADO-GRAL.','items'=>[
 'Marcha'=>['Marcha','#DFF4EA','#14754F'],'Parado'=>['Parado','#FDE5E3','#AA302B'],
 'Normal'=>['Normal','#DFF4EA','#14754F'],'Sin dato'=>['Sin dato','#EDF0F3','#617080']]],
 'YT_POZO'=>['titulo'=>'YT:POZO / estado por RPM','detalle'=>'Marcha o paro de monitoreo de pozos.','items'=>[
 'Marcha'=>['Marcha','#DFF4EA','#14754F'],'MARCHA'=>['MARCHA','#DFF4EA','#14754F'],
 'Parado'=>['Parado','#FDE5E3','#AA302B'],'PARADO'=>['PARADO','#FDE5E3','#AA302B'],
 'Sin dato'=>['Sin dato','#EDF0F3','#617080']]],
 'ESTADO_ZAFIRO'=>['titulo'=>'Estado Zafiro','detalle'=>'Estados publicados por Zafiro.','items'=>[
 'Produciendo'=>['Produciendo','#E5F0FB','#286B9E'],
 'Downtime de Producción (Pérdida Localizada)'=>['Downtime de produccion','#E5F0FB','#286B9E'],
 'Sin dato'=>['Sin dato','#E5F0FB','#286B9E']]],
 'ZAFIRO_ACTIVO'=>['titulo'=>'Zafiro activo','detalle'=>'Actividad registrada en Zafiro.','items'=>[
 'true'=>['true','#E5F0FB','#286B9E'],'false'=>['false','#EDF0F3','#617080'],
 'Sin dato'=>['Sin dato','#E5F0FB','#286B9E']]],
 'ALARMAS'=>['titulo'=>'Alarmas (ALM)','detalle'=>'Solo colores: no cambia rangos ni conteos.','items'=>[
 '0 alarmas'=>['0 alarmas','#DFF4EA','#14754F'],'1-5 alarmas'=>['1 a 5 alarmas','#FFF1D7','#A66B0B'],
 '6-15 alarmas'=>['6 a 15 alarmas','#FFE7D1','#B65E0B'],'16+ alarmas'=>['16 o mas alarmas','#FDE5E3','#B32E28']]],
 'FILAS'=>['titulo'=>'Filas con incidentes','detalle'=>'Colores de fondo sin cambiar el resaltado de seleccion.','items'=>[
 'Sin comunicación'=>['Sin comunicacion','#FDF0F0','#AA302B'],'Demorada'=>['Demorada','#FFF6E5','#9A6410']]]
 ];
}
function clear_colors_rules(){
 $result=[];
 foreach(clear_colors_catalog() as $campo=>$grupo)foreach($grupo['items'] as $estado=>$spec){
  $id=substr(sha1($campo.'|'.$estado),0,16);
  $result[$id]=['campo'=>$campo,'estado'=>$estado,'titulo'=>$spec[0],'fondo'=>$spec[1],'texto'=>$spec[2]];
 }
 return $result;
}
function clear_colors_installed($db){
 if(!$db||!$db->ok())return false;
 return (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_CONFIG_COLORES',N'U') IS NOT NULL AND OBJECT_ID(N'dbo.SP_CLEAR_COLOR_GUARDAR',N'P') IS NOT NULL THEN 1 ELSE 0 END")===1;
}
function clear_colors_load($db=null,$refresh=false){
 static $cache=null;
 if($refresh)$cache=null;
 if($cache!==null)return $cache;
 $cache=[];
 $db=$db?:clear_db();
 if(!clear_colors_installed($db))return $cache;
 $rows=$db->all("SELECT CAMPO,ESTADO,COLOR_FONDO,COLOR_TEXTO FROM dbo.CLEAR_CONFIG_COLORES WHERE MODULO=N'TELEMETRIA'");
 foreach($rows as $r){
  $campo=trim((string)($r['CAMPO']??''));$estado=trim((string)($r['ESTADO']??''));
  $fondo=strtoupper(trim((string)($r['COLOR_FONDO']??'')));$texto=strtoupper(trim((string)($r['COLOR_TEXTO']??'')));
  if(preg_match('/^#[0-9A-F]{6}$/',$fondo)&&preg_match('/^#[0-9A-F]{6}$/',$texto))
   $cache[$campo][$estado]=['fondo'=>$fondo,'texto'=>$texto];
 }
 return $cache;
}
function clear_colors_effective($campo,$estado,array $config,array $defaults){
 if(isset($config[$campo][$estado]))return $config[$campo][$estado];
 return ['fondo'=>$defaults[1],'texto'=>$defaults[2]];
}
function clear_colors_style($campo,$estado,array $config){
 $estado=trim((string)$estado);
 if(!isset($config[$campo][$estado]))return '';
 $item=$config[$campo][$estado];
 return 'background-color:'.$item['fondo'].';color:'.$item['texto'].';';
}
function clear_colors_telemetry_css(array $config){
 $css='';
 foreach(['success','danger','warning','info','neutral'] as $clase){
  if(!isset($config['BASE'][$clase]))continue;
  $item=$config['BASE'][$clase];
  $css.='.tg .tg-badge.'.$clase.',.tg .tg-alarm-link.'.$clase.'{background-color:'.$item['fondo'].';color:'.$item['texto'].';}';
 }
 if(isset($config['FILAS']['Sin comunicación']))
  $css.='.tg .tg-table tr.tg-row-comm-fail td{background-color:'.$config['FILAS']['Sin comunicación']['fondo'].';}';
 if(isset($config['FILAS']['Demorada']))
  $css.='.tg .tg-table tr.tg-row-comm-warn td{background-color:'.$config['FILAS']['Demorada']['fondo'].';}';
 return $css;
}
