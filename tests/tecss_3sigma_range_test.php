<?php
require_once dirname(__DIR__).'/includes/tecss_3sigma_range.php';
$n=0;function range_check($ok,$label){global $n;$n++;if(!$ok)throw new RuntimeException($label);}
list($from,$to)=sigma_history_dates('2026-10-27T14:30','2026-10-28T14:30');
range_check($to->getTimestamp()-$from->getTimestamp()===86400,'Últimas 24 horas exactas, sin agregar un día');
range_check($from->format(DATE_ATOM)==='2026-10-27T14:30:00-03:00','Usar zona horaria de operación');
list($from,$to)=sigma_history_dates('2026-10-28','2026-10-28');range_check($to->getTimestamp()-$from->getTimestamp()===86400,'Mantener compatibilidad con consultas por día');
foreach([['2026-02-30T10:00','2026-03-01T10:00'],['2026-10-28T25:00','2026-10-29T10:00'],['2026-10-28T10:00','2026-10-28T10:00'],['2026-10-29T10:00','2026-10-28T10:00'],['2026-10-28','2026-10-29T10:00'],['2025-01-01T00:00','2026-10-28T00:00']] as $pair){$bad=false;try{sigma_history_dates($pair[0],$pair[1]);}catch(InvalidArgumentException $e){$bad=true;}range_check($bad,'Rechazar rango inválido: '.implode(' / ',$pair));}
echo "OK: $n verificaciones de rango y últimas 24 horas.\n";
