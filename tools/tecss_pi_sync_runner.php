<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Lanzador local de la tarea: argumentos sin credenciales y salida operativa en logs.
$mode = $argv[1] ?? 'validate';
if (!in_array($mode,['validate','publish'],true)) exit(2);
$root = __DIR__;
$args = [PHP_BINARY,'-c',$root.'/php.ini',$root.'/app/tools/tecss_pi_sync.php','--config='.$root.'/connection.json'];
$args[] = $mode === 'publish' ? '--apply' : '--well=LH-945';
$started = date(DATE_ATOM);
$output = $root.'/logs/'.($mode === 'validate' ? 'last-validation.json' : 'last-publication.log');
$errors = $root.'/logs/last-error.log';
$proc = proc_open($args,[0=>['file','NUL','r'],1=>['file',$output,'w'],2=>['file',$errors,'w']],$pipes,$root,null,['bypass_shell'=>true]);
$code = is_resource($proc) ? proc_close($proc) : 1;
file_put_contents($root.'/logs/last-run.json',json_encode(['mode'=>$mode,'started'=>$started,'finished'=>date(DATE_ATOM),'exit_code'=>$code],JSON_PRETTY_PRINT));
exit($code);
