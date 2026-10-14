<?php
/* CLEAR - Pantalla de configuracion de colores. Administradores. */
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/color_config.php';
require_once __DIR__.'/includes/icons.php';
auth_require_admin();
permissions_require_menu('config_colores');
$APP_USER=auth_user();
$APP_ROLE='Administrador';
$ACTIVE='config_colores';
$db=clear_db();
$installed=clear_colors_installed($db);
$catalog=clear_colors_catalog();
$rules=clear_colors_rules();
$overrides=clear_colors_load($db);
auth_start();
if(empty($_SESSION['clear_colors_csrf']))$_SESSION['clear_colors_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['clear_colors_csrf'];
$msg='';$msgType='ok';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($csrf,(string)($_POST['csrf']??''))){
  $msg='Sesion vencida. Recargue la pagina.';$msgType='err';
 }elseif(!$installed){
  $msg='Primero instale SQL/CLEAR_CONFIG_COLORES_20261014.sql';$msgType='err';
 }else{
  $action=(string)($_POST['action']??'');
  $changes=[];$invalid=false;
  if($action==='save'){
   $bgs=$_POST['fondo']??[];$fgs=$_POST['texto']??[];
   if(!is_array($bgs)||!is_array($fgs))$invalid=true;
   else foreach($rules as $id=>$rule){
    if(!isset($bgs[$id],$fgs[$id])||!is_string($bgs[$id])||!is_string($fgs[$id])){$invalid=true;break;}
    $fondo=strtoupper(trim($bgs[$id]));$texto=strtoupper(trim($fgs[$id]));
    if(!preg_match('/^#[0-9A-F]{6}$/',$fondo)||!preg_match('/^#[0-9A-F]{6}$/',$texto)){$invalid=true;break;}
    $actual=clear_colors_effective($rule['campo'],$rule['estado'],$overrides,[$rule['titulo'],$rule['fondo'],$rule['texto']]);
    if($actual['fondo']===$fondo&&$actual['texto']===$texto)continue;
    $reset=($fondo===$rule['fondo']&&$texto===$rule['texto'])?1:0;
    $changes[]=[$rule['campo'],$rule['estado'],$fondo,$texto,$reset];
   }
  }elseif($action==='reset_all'){
   foreach($rules as $rule)if(isset($overrides[$rule['campo']][$rule['estado']]))
    $changes[]=[$rule['campo'],$rule['estado'],$rule['fondo'],$rule['texto'],1];
  }else $invalid=true;
  if($invalid){$msg='Colores invalidos. No se aplicaron cambios.';$msgType='err';}
  else{
   $saved=0;
   foreach($changes as $change){
    if(!$db->execute('EXEC dbo.SP_CLEAR_COLOR_GUARDAR @Campo=?,@Estado=?,@Fondo=?,@Texto=?,@Usuario=?,@Restablecer=?',
      [$change[0],$change[1],$change[2],$change[3],(string)$APP_USER,$change[4]])){
     $msg='Se guardaron '.$saved.' cambios, pero ocurrio un error: '.$db->error();$msgType='err';break;
    }
    $saved++;
   }
   if($msg==='')$msg=$saved?'Guardados '.$saved.' cambios. Recargue las grillas para verlos.':'No habia cambios por guardar.';
   $overrides=clear_colors_load($db,true);
  }
 }
}
?>
<!doctype html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Colores y estados - CLEAR</title>
<link rel="stylesheet" href="assets/css/app.css?v=20261014-colores">
<style>
.cc{max-width:1220px;padding:0 20px 35px}.cc-msg{padding:14px 16px;margin-bottom:14px;border:1px solid var(--line-mid);border-radius:12px;background:var(--surface);color:var(--text-soft);font-size:13px;line-height:1.55}
.cc-msg.err{background:#fff1f0;border-color:#eba9a2;color:#a72e28}.cc-msg.ok{background:#e8f7ee;border-color:#a5dcbf;color:#13663f}
.cc-group{margin-bottom:16px;padding:17px 17px 7px;background:var(--surface);border:1px solid var(--line-mid);border-radius:13px}
.cc-group h2{margin:0 0 4px;font-size:17px;color:var(--text)}.cc-group p{margin:0 0 13px;font-size:12px;color:var(--text-mut)}
.cc-head,.cc-row{display:grid;grid-template-columns:minmax(140px,1.5fr) minmax(120px,1fr) minmax(160px,1fr) minmax(160px,1fr);align-items:center;gap:13px}
.cc-head{padding:0 8px 10px;font-size:10px;letter-spacing:.04em;color:var(--text-mut);text-transform:uppercase;font-weight:750}
.cc-row{padding:10px 8px;border-top:1px solid var(--line)}
.cc-label{font-size:12px;font-weight:650;color:var(--text)}.cc-preview{display:inline-block;padding:5px 10px;border-radius:99px;font-size:11px;font-weight:750}
.cc-pair{display:flex;align-items:center;gap:7px}.cc-pair input[type=color]{width:40px;height:34px;border:1px solid var(--line-mid);border-radius:8px;padding:3px;background:var(--surface);cursor:pointer}
.cc-pair input[type=text]{width:100px;max-width:100%;min-width:0;border:1px solid var(--line-mid);border-radius:8px;height:34px;padding:5px 7px;font-family:monospace;color:var(--text);background:var(--surface)}
.cc-actions{position:sticky;bottom:0;z-index:20;display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:14px;background:var(--bg-card,var(--surface));border:1px solid var(--line-mid);border-radius:13px;box-shadow:0 8px 25px rgba(0,0,0,.1)}
.cc-actions button{padding:10px 16px;border:1px solid var(--line-mid);border-radius:9px;background:var(--surface);color:var(--text);font-weight:700;cursor:pointer}
.cc-actions .cc-primary{background:var(--petrol);border-color:var(--petrol);color:white}.cc-actions button:disabled{opacity:.45;cursor:not-allowed}
.cc-actions small{color:var(--text-mut)}@media(max-width:850px){.cc{padding:0 8px 20px}.cc-head{display:none}.cc-row{grid-template-columns:1fr 1fr}.cc-label,.cc-sample{grid-column:1/-1}}
</style>
</head><body><div class="app"><?php include __DIR__.'/includes/sidebar.php'; ?>
<main class="main"><?php include __DIR__.'/includes/topbar.php'; ?>
<div class="page__head"><div><h1 class="page__title">Colores y estados</h1><div class="page__sub">Configuracion global de grillas de telemetria</div></div><div class="page__live"><span class="dot"></span>Solo administradores</div></div>
<div class="cc">
<?php if(!$installed): ?><div class="cc-msg err"><b>Falta instalar SQL.</b> Ejecute <code>SQL/CLEAR_CONFIG_COLORES_20261014.sql</code> en la base <b>LC_MDB</b>. Mientras tanto las grillas conservaran sus colores actuales.</div><?php endif; ?>
<?php if($msg!==''): ?><div class="cc-msg <?php echo h($msgType); ?>"><?php echo h($msg); ?></div><?php endif; ?>
<div class="cc-msg">La paleta es global para todos los usuarios. Solo cambia la presentacion de Monitoreo Pozos, PCP, BES, TECSS y las pantallas que comparten la grilla de telemetria. No cambia valores, calculos, filtros, alarmas ni selecciones.</div>
<form id="ccForm" method="post"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
<?php foreach($catalog as $campo=>$grupo): ?>
<section class="cc-group"><h2><?php echo h($grupo['titulo']); ?></h2><p><?php echo h($grupo['detalle']); ?></p>
<div class="cc-head"><div>Estado</div><div>Vista previa</div><div>Fondo</div><div>Texto</div></div>
<?php foreach($grupo['items'] as $estado=>$spec):
 $id=substr(sha1($campo.'|'.$estado),0,16);$color=clear_colors_effective($campo,$estado,$overrides,$spec);
?>
<div class="cc-row" data-color-row>
<div class="cc-label"><?php echo h($spec[0]); ?></div>
<div class="cc-sample"><span class="cc-preview" data-color-preview style="background-color:<?php echo h($color['fondo']); ?>;color:<?php echo h($color['texto']); ?>"><?php echo h($estado); ?></span></div>
<div class="cc-pair"><input type="color" data-pick="fondo" value="<?php echo h($color['fondo']); ?>" aria-label="Color fondo <?php echo h($spec[0]); ?>" <?php echo !$installed?'disabled':''; ?>><input type="text" name="fondo[<?php echo h($id); ?>]" value="<?php echo h($color['fondo']); ?>" data-hex="fondo" maxlength="7" pattern="#[a-fA-F0-9]{6}" aria-label="HEX fondo <?php echo h($spec[0]); ?>" required <?php echo !$installed?'disabled':''; ?>></div>
<div class="cc-pair"><input type="color" data-pick="texto" value="<?php echo h($color['texto']); ?>" aria-label="Color texto <?php echo h($spec[0]); ?>" <?php echo !$installed?'disabled':''; ?>><input type="text" name="texto[<?php echo h($id); ?>]" value="<?php echo h($color['texto']); ?>" data-hex="texto" maxlength="7" pattern="#[a-fA-F0-9]{6}" aria-label="HEX texto <?php echo h($spec[0]); ?>" required <?php echo !$installed?'disabled':''; ?>></div>
</div>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
<div class="cc-actions"><button class="cc-primary" name="action" value="save" type="submit" <?php echo !$installed?'disabled':''; ?>>Guardar colores</button><button name="action" value="reset_all" type="submit" formnovalidate <?php echo !$installed?'disabled':''; ?> onclick="return confirm('Restablecer TODOS los colores de telemetria?');">Restablecer colores originales</button><small>Aplicar cambios: recargar las grillas.</small></div>
</form></div></main></div>
<script src="assets/js/app.js?v=3.1.7"></script>
<script>
(function(){
 document.querySelectorAll('[data-color-row]').forEach(function(row){
  function preview(){
   var p=row.querySelector('[data-color-preview]'),bg=row.querySelector('[data-hex="fondo"]').value.trim(),fg=row.querySelector('[data-hex="texto"]').value.trim();
   if(/^#[a-fA-F0-9]{6}$/.test(bg))p.style.backgroundColor=bg;
   if(/^#[a-fA-F0-9]{6}$/.test(fg))p.style.color=fg;
  }
  ['fondo','texto'].forEach(function(kind){
   var pick=row.querySelector('[data-pick="'+kind+'"]'),text=row.querySelector('[data-hex="'+kind+'"]');
   pick.addEventListener('input',function(){text.value=pick.value.toUpperCase();preview();});
   text.addEventListener('input',function(){if(/^#[a-fA-F0-9]{6}$/.test(text.value.trim()))pick.value=text.value.trim();preview();});
  });
 });
})();
</script></body></html>
