<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';
auth_require_admin();
permissions_require_menu('tags_filtrados');
$db=clear_db();
$APP_USER=auth_user();$APP_ROLE='Administrador';$ACTIVE='tags_filtrados';
$objectsReady=(int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_ALARM_FILTERS',N'U') IS NULL THEN 0 ELSE 1 END")===1;
$msg=$objectsReady?'':'Falta instalar los objetos SQL. Ejecutá SQL/10_OPTIMIZACION_GLOBAL_CLEAR8.sql una sola vez.';$msgType=$objectsReady?'ok':'err';
if($_SERVER['REQUEST_METHOD']==='POST' && $objectsReady){
  $accion=$_POST['accion']??'';
  if($accion==='agregar'){
    $campo=strtoupper(trim($_POST['campo']??'AMBOS'));
    $modo=strtoupper(trim($_POST['modo']??'CONTIENE'));
    $valor=trim($_POST['valor']??'');
    if(!in_array($campo,['TAG','DESCRIPCION','AMBOS'],true))$campo='AMBOS';
    if(!in_array($modo,['EXACTO','COMIENZA','CONTIENE'],true))$modo='CONTIENE';
    if($valor===''){$msg='Ingresá un tag o una descripción para filtrar.';$msgType='err';}
    else{
      $ok=$db->execute("INSERT INTO dbo.CLEAR_ALARM_FILTERS(CAMPO,MODO,VALOR,ACTIVO,USUARIO_ALTA) VALUES(?,?,?,?,?)",[$campo,$modo,$valor,1,$APP_USER]);
      $msg=$ok?'Filtro agregado correctamente.':'No se pudo agregar el filtro. '.$db->error();$msgType=$ok?'ok':'err';
      if($ok)audit_log('FILTRO_TAG_AGREGADO','tags_filtrados',json_encode(['campo'=>$campo,'modo'=>$modo,'valor'=>$valor],JSON_UNESCAPED_UNICODE));
    }
  }elseif($accion==='estado'){
    $id=(int)($_POST['id']??0);$activo=(int)($_POST['activo']??0);
    $ok=$db->execute("UPDATE dbo.CLEAR_ALARM_FILTERS SET ACTIVO=? WHERE ID=?",[$activo,$id]);
    $msg=$ok?($activo?'Filtro activado.':'Filtro desactivado.'):'No se pudo actualizar el filtro.';$msgType=$ok?'ok':'err';
    if($ok)audit_log('FILTRO_TAG_ESTADO','tags_filtrados',json_encode(['id'=>$id,'activo'=>$activo]));
  }elseif($accion==='borrar'){
    $id=(int)($_POST['id']??0);
    $ok=$db->execute("DELETE FROM dbo.CLEAR_ALARM_FILTERS WHERE ID=?",[$id]);
    $msg=$ok?'Filtro eliminado.':'No se pudo eliminar el filtro.';$msgType=$ok?'ok':'err';
    if($ok)audit_log('FILTRO_TAG_ELIMINADO','tags_filtrados',json_encode(['id'=>$id]));
  }
}
$rows=$objectsReady?$db->all("SELECT ID,CAMPO,MODO,VALOR,ACTIVO,CONVERT(varchar(19),FECHA_ALTA,120) FECHA_ALTA,USUARIO_ALTA FROM dbo.CLEAR_ALARM_FILTERS ORDER BY ACTIVO DESC,ID DESC"):[];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tags filtrados</title><link rel="stylesheet" href="assets/css/app.css?v=20260721-tags"><style>
.filter-layout{display:grid;grid-template-columns:minmax(650px,1fr) 390px;gap:18px;align-items:start}.panel{background:var(--surface,#fff);border:1px solid var(--line);border-radius:14px;padding:18px}.panel h3{margin:0 0 6px}.muted{color:var(--text-mut);font-size:12px}.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.ff{margin:12px 0}.ff label{display:block;font-size:12px;font-weight:700;margin-bottom:5px}.ff input,.ff select{width:100%;padding:10px;border:1px solid var(--line-mid);border-radius:9px;background:var(--surface,#fff);color:var(--text)}.btn{border:0;border-radius:9px;padding:10px 13px;font-weight:700;cursor:pointer}.btn-main{background:var(--petrol);color:#fff;width:100%}.btn-soft{background:var(--petrol-soft);color:var(--petrol)}.btn-danger{background:var(--red-soft);color:var(--red-tx)}.inline{display:inline}.msg{padding:11px 13px;border-radius:9px;margin-bottom:14px}.msg.ok{background:var(--green-soft);color:var(--green-tx)}.msg.err{background:var(--red-soft);color:var(--red-tx)}.pill{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700}.on{background:var(--green-soft);color:var(--green-tx)}.off{background:var(--red-soft);color:var(--red-tx)}.value{max-width:430px;white-space:normal;word-break:break-word}.actions{display:flex;gap:6px;flex-wrap:wrap}@media(max-width:1050px){.filter-layout{grid-template-columns:1fr}}
</style></head><body><div class="app"><?php include __DIR__.'/includes/sidebar.php';?><main class="main"><?php include __DIR__.'/includes/topbar.php';?><div class="page__head"><div><h1 class="page__title">Tags filtrados</h1><div class="page__sub">Exclusiones globales para pantallas, rankings, métricas, reportes y análisis IA</div></div><div class="page__live"><span class="dot"></span>Solo administrador</div></div>
<?php if($msg):?><div class="msg <?=$msgType?>"><?=h($msg)?></div><?php endif;?>
<div class="filter-layout"><div class="tablewrap"><div class="tablescroll"><table class="grid"><thead><tr><th>Campo</th><th>Coincidencia</th><th>Valor excluido</th><th>Estado</th><th>Alta</th><th>Acciones</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="6">No hay filtros configurados.</td></tr><?php endif;?><?php foreach($rows as $r):?><tr><td><?=h($r['CAMPO'])?></td><td><?=h($r['MODO'])?></td><td class="value"><b><?=h($r['VALOR'])?></b></td><td><span class="pill <?=$r['ACTIVO']?'on':'off'?>"><?=$r['ACTIVO']?'Activo':'Inactivo'?></span></td><td><?=h($r['FECHA_ALTA'])?><br><span class="muted"><?=h($r['USUARIO_ALTA'])?></span></td><td><div class="actions"><form method="post" class="inline"><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?=h($r['ID'])?>"><input type="hidden" name="activo" value="<?=$r['ACTIVO']?0:1?>"><button class="btn btn-soft"><?=$r['ACTIVO']?'Desactivar':'Activar'?></button></form><form method="post" class="inline" onsubmit="return confirm('¿Eliminar este filtro?')"><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?=h($r['ID'])?>"><button class="btn btn-danger">Eliminar</button></form></div></td></tr><?php endforeach;?></tbody></table></div></div>
<section class="panel"><h3>Agregar exclusión</h3><p class="muted">El filtro se aplica automáticamente a toda la solución. Podés excluir por tag, descripción o ambos campos.</p><form method="post"><input type="hidden" name="accion" value="agregar"><div class="form-row"><div class="ff"><label>Campo</label><select name="campo"><option value="TAG">Tag</option><option value="DESCRIPCION">Descripción</option><option value="AMBOS" selected>Tag o descripción</option></select></div><div class="ff"><label>Coincidencia</label><select name="modo"><option value="EXACTO">Exacta</option><option value="COMIENZA">Comienza con</option><option value="CONTIENE" selected>Contiene</option></select></div></div><div class="ff"><label>Valor que no debe aparecer</label><input name="valor" required placeholder="Ej.: PTALH03 o Shutdown attempt..."></div><button class="btn btn-main">Agregar filtro global</button></form><p class="muted" style="margin-top:14px"><b>Importante:</b> los registros excluidos no se muestran ni se contabilizan en totales, rankings, tendencias, reportes o diagnósticos de IA.</p></section></div></main></div><script src="assets/js/app.js"></script></body></html>
