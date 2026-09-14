<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';

auth_require_admin();
permissions_require_menu('audit_log');

$APP_USER=auth_user();
$APP_ROLE='Administrador';
$ACTIVE='audit_log';
$db=clear_db();

auth_start();
$csrf=(string)($_SESSION['clear_csrf_audit_log']??'');
if($csrf===''){
    $csrf=bin2hex(random_bytes(24));
    $_SESSION['clear_csrf_audit_log']=$csrf;
}

$flash=is_array($_SESSION['clear_audit_flash']??null)?$_SESSION['clear_audit_flash']:null;
unset($_SESSION['clear_audit_flash']);

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $returnQuery=trim((string)($_POST['q']??''));
    if(!hash_equals($csrf,(string)($_POST['csrf']??''))){
        $_SESSION['clear_audit_flash']=['type'=>'err','text'=>'La sesión del formulario venció. Recargá la pantalla e intentá nuevamente.'];
    }else{
        $ids=[];
        foreach((array)($_POST['ids']??[]) as $value){
            $value=trim((string)$value);
            if($value!==''&&ctype_digit($value)&&(int)$value>0)$ids[(int)$value]=(int)$value;
            if(count($ids)>=1000)break;
        }
        $ids=array_values($ids);
        if(!$ids){
            $_SESSION['clear_audit_flash']=['type'=>'err','text'=>'Seleccioná al menos un registro para eliminar.'];
        }else{
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $selected=(int)$db->scalar("SELECT COUNT_BIG(*) FROM dbo.CLEAR_AUDIT_LOG WHERE ID IN ($marks)",$ids);
            $ok=$selected>0&&$db->execute("DELETE FROM dbo.CLEAR_AUDIT_LOG WHERE ID IN ($marks)",$ids);
            if($ok){
                audit_log('AUDITORIA_LOGS_ELIMINADOS','auditoria',json_encode(['cantidad'=>$selected],JSON_UNESCAPED_UNICODE),$APP_USER);
                $_SESSION['clear_audit_flash']=['type'=>'ok','text'=>'Se eliminaron '.$selected.' registro'.($selected===1?'':'s').'. La acción quedó registrada en la auditoría.'];
            }else{
                $_SESSION['clear_audit_flash']=['type'=>'err','text'=>'No se pudieron eliminar los registros seleccionados. '.$db->error()];
            }
        }
    }
    header('Location: audit_log.php'.($returnQuery!==''?'?q='.urlencode($returnQuery):''));
    exit;
}

$q=trim((string)($_GET['q']??''));
$where='';
$params=[];
if($q!==''){
    $where='WHERE USUARIO LIKE ? OR ACCION LIKE ? OR MODULO LIKE ? OR DETALLE LIKE ?';
    $like='%'.$q.'%';
    $params=[$like,$like,$like,$like];
}
$rows=$db->all("SELECT TOP 1000 ID,FECHA,USUARIO,ACCION,MODULO,DETALLE,IP FROM dbo.CLEAR_AUDIT_LOG $where ORDER BY FECHA DESC",$params);

if(isset($_GET['export'])){
    header('Content-Type:text/csv; charset=utf-8');
    header('Content-Disposition:attachment; filename=CLEAR_Auditoria_'.date('Ymd_His').'.csv');
    echo "\xEF\xBB\xBFFecha;Usuario;Accion;Modulo;Detalle;IP\r\n";
    foreach($rows as $r){
        echo implode(';',array_map(static function($v){return '"'.str_replace('"','""',(string)$v).'"';},[$r['FECHA'],$r['USUARIO'],$r['ACCION'],$r['MODULO'],$r['DETALLE'],$r['IP']]))."\r\n";
    }
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Auditoría</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260902-cambio1">
  <style>
    .auditbar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}.auditbar input{min-width:280px;flex:1;padding:11px;border:1px solid var(--line-mid);border-radius:9px;background:var(--bg-card,#fff);color:var(--text)}
    .auditActions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 10px;flex-wrap:wrap}.auditActions__selection{color:var(--text-mut);font-size:11px}.auditDelete{display:inline-flex;align-items:center;gap:7px;min-height:39px;padding:0 12px;border:1px solid #e7ada8;border-radius:9px;background:#fff1ef;color:#a43c35;font:inherit;font-size:12px;font-weight:750;cursor:pointer}.auditDelete svg{width:15px;height:15px}.auditDelete:disabled{opacity:.45;cursor:not-allowed}
    .auditCheck{width:76px;min-width:76px;text-align:center!important}.auditSelectAll{display:inline-flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap}.auditSelectAll input,.auditRowCheck{width:16px;height:16px;accent-color:var(--petrol);cursor:pointer}.cell-detail{max-width:520px;white-space:normal}.auditFlash{margin-bottom:12px;padding:10px 12px;border-radius:9px;font-size:12px}.auditFlash.ok{background:var(--green-soft);color:var(--green-tx)}.auditFlash.err{background:var(--red-soft);color:var(--red-tx)}.auditEmpty{text-align:center!important;padding:28px!important;color:var(--text-mut)}
    html[data-theme="dark"] .auditDelete{background:#442320;color:#ffaaa4;border-color:#76413d}@media(max-width:760px){.auditbar>*{width:100%}.auditbar input{min-width:100%}.auditActions{align-items:flex-start;flex-direction:column}}
  </style>
</head>
<body><div class="app"><?php include __DIR__.'/includes/sidebar.php'; ?><main class="main"><?php include __DIR__.'/includes/topbar.php'; ?>
  <div class="page__head"><div><h1 class="page__title">Auditoría del sistema</h1><div class="page__sub">Inicios de sesión, comentarios, exportaciones y cambios de permisos</div></div></div>
  <?php if($flash): ?><div class="auditFlash <?php echo h($flash['type']??'ok'); ?>"><?php echo h($flash['text']??''); ?></div><?php endif; ?>
  <form class="auditbar" method="get">
    <input name="q" value="<?php echo h($q); ?>" placeholder="Buscar usuario, acción, módulo o detalle">
    <button class="btn-export" type="submit">Buscar</button>
    <a class="btn-export" href="?q=<?php echo urlencode($q); ?>&amp;export=1"><?php echo icon('download'); ?> Exportar CSV</a>
  </form>
  <form method="post" id="auditDeleteForm">
    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="q" value="<?php echo h($q); ?>">
    <div class="auditActions"><div class="auditActions__selection"><b id="auditSelectedCount">0</b> seleccionados · se muestran hasta 1.000 registros</div><button class="auditDelete" id="auditDelete" type="submit" disabled><?php echo icon('trash'); ?> Eliminar seleccionados</button></div>
    <div class="tablewrap"><div class="tablescroll"><table class="grid">
      <thead><tr><th class="auditCheck"><label class="auditSelectAll"><input type="checkbox" id="auditSelectAll"> Todas</label></th><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Módulo</th><th>Detalle</th><th>IP</th></tr></thead>
      <tbody><?php if(!$rows): ?><tr><td colspan="7" class="auditEmpty">No hay registros para mostrar.</td></tr><?php else:foreach($rows as $r): ?><tr><td class="auditCheck"><input class="auditRowCheck" type="checkbox" name="ids[]" value="<?php echo (int)$r['ID']; ?>" aria-label="Seleccionar registro <?php echo (int)$r['ID']; ?>"></td><td><?php echo h($r['FECHA']); ?></td><td><?php echo h($r['USUARIO']); ?></td><td><b><?php echo h($r['ACCION']); ?></b></td><td><?php echo h($r['MODULO']); ?></td><td class="cell-detail"><?php echo h($r['DETALLE']); ?></td><td><?php echo h($r['IP']); ?></td></tr><?php endforeach;endif; ?></tbody>
    </table></div></div>
  </form>
</main></div>
<script src="assets/js/app.js"></script>
<script>
(function(){
  var form=document.getElementById('auditDeleteForm'),master=document.getElementById('auditSelectAll'),button=document.getElementById('auditDelete'),count=document.getElementById('auditSelectedCount');
  if(!form||!master||!button||!count)return;
  var checks=Array.prototype.slice.call(form.querySelectorAll('.auditRowCheck'));
  function sync(){var selected=checks.filter(function(item){return item.checked;}).length;count.textContent=String(selected);button.disabled=selected===0;master.checked=checks.length>0&&selected===checks.length;master.indeterminate=selected>0&&selected<checks.length;}
  master.addEventListener('change',function(){checks.forEach(function(item){item.checked=master.checked;});sync();});
  checks.forEach(function(item){item.addEventListener('change',sync);});
  form.addEventListener('submit',function(event){var selected=checks.filter(function(item){return item.checked;}).length;if(!selected||!window.confirm('¿Eliminar definitivamente '+selected+' registro'+(selected===1?'':'s')+' de auditoría?'))event.preventDefault();});
  sync();
})();
</script>
</body></html>
