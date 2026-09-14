<?php
require_once __DIR__ . '/permissions.php';
/* =============================================================
   CLEAR PLATAFORMA — includes/topbar.php
   Barra superior con menú de administrador (desplegable).
   Solo muestra el menú admin si el usuario es administrador.
   Incluir al principio de <main>, antes de page__head.
============================================================= */
?>
<link rel="stylesheet" href="assets/css/print.css?v=20260824-pdf1" media="all">
<link rel="stylesheet" href="assets/css/chart_preferences.css?v=20260826-chartprefs-2" media="all">
<link rel="stylesheet" href="assets/css/grid_tools.css?v=20260826-gridtools-1" media="all">
<div class="topbar">
  <div class="topbar__spacer"></div>
  <a class="topmenu__btn topmenu__btn--link" href="inicio.php" title="Ir a la pantalla principal" style="margin-right:8px"><?php echo icon('home'); ?><span>Inicio</span></a>
  <?php
    $favList=json_decode((string)user_pref_get('favorite_pages','[]'),true); if(!is_array($favList))$favList=[];
    $currentFav=isset($ACTIVE)&&in_array($ACTIVE,$favList,true);
  ?>
  <?php if(!empty($ACTIVE)): ?><button type="button" class="topmenu__btn" id="favoritePageBtn" title="Agregar o quitar de favoritos" style="margin-right:8px"><span id="favoritePageIcon"><?php echo $currentFav?'★':'☆'; ?></span><span>Favorito</span></button><?php endif; ?>
  <button type="button" class="topmenu__btn" id="densityBtn" title="Cambiar densidad de las tablas" style="margin-right:8px"><span>↕</span><span id="densityLabel">Vista</span></button>
  <button type="button" class="topmenu__btn clearGridToolsBtn" id="gridToolsBtn" title="Mostrar, ocultar y ordenar columnas" style="margin-right:8px" hidden><?php echo icon('grid'); ?><span>Columnas</span></button>
  <button type="button" class="topmenu__btn" id="globalPdfBtn" title="Abrir la impresión para guardar esta pantalla como PDF" style="margin-right:8px"><?php echo icon('file'); ?><span>Guardar PDF</span></button>
  <button type="button" class="topmenu__btn clearChartPrefsBtn" id="chartPreferencesBtn" title="Personalizar los gráficos" style="margin-right:8px" hidden><span>◒</span><span>Gráficos</span></button>
  <button type="button" class="topmenu__btn themeToggle" id="themeToggleBtn" title="Cambiar entre modo claro y oscuro" aria-pressed="false" style="margin-right:8px">
    <span class="themeToggle__icon" id="themeToggleIcon" aria-hidden="true">☾</span><span id="themeToggleLabel">Oscuro</span>
  </button>
  <?php if (function_exists('auth_es_admin') && auth_es_admin()): ?>
  <div class="topmenu" id="topmenu">
    <button type="button" class="topmenu__btn" id="topmenuBtn">
      <span class="topmenu__avatar">CL</span>
      <span><?php echo h(auth_user() ?? 'CLEAR'); ?></span>
      <?php echo icon('chevron', 'topmenu__chev'); ?>
    </button>
    <div class="topmenu__drop" id="topmenuDrop">
      <div class="topmenu__head">Administración</div>
      <?php if(permissions_can_menu('admin_usuarios')): ?><a href="admin_usuarios.php"><?php echo icon('check'); ?> Área Admin · Usuarios</a><?php endif; ?>
      <?php if(permissions_can_menu('admin_supervisores_instalaciones')): ?><a href="admin_supervisores_instalaciones.php"><?php echo icon('grid'); ?> Supervisores de instalaciones</a><?php endif; ?>
      <?php if(permissions_can_menu('config_pi')): ?><a href="config_pi.php"><?php echo icon('trend'); ?> Conexión PI Web API</a><?php endif; ?>
      <?php if(permissions_can_menu('config_menu')): ?><a href="config_menu.php"><?php echo icon('grid'); ?> Configurar menú</a><?php endif; ?>
      <?php if(permissions_can_menu('config_alarmas')): ?><a href="config_alarmas.php"><?php echo icon('bell'); ?> Configuración de alarmas</a><?php endif; ?>
      <?php if(permissions_can_menu('config_scada_realtime')): ?><a href="scada_realtime_config.php"><?php echo icon('monitor'); ?> Configuración SCADA Real time</a><?php endif; ?>
      <?php if(permissions_can_menu('tags_filtrados')): ?><a href="admin_tags_filtrados.php"><?php echo icon('shield'); ?> Tags filtrados</a><?php endif; ?>
      <?php if(permissions_can_menu('estado_sistema')): ?><a href="estado_sistema.php"><?php echo icon('gauge'); ?> Estado del sistema</a><?php endif; ?>
      <?php if(permissions_can_menu('audit_log')): ?><a href="audit_log.php"><?php echo icon('file'); ?> Auditoría</a><?php endif; ?>
      <?php if(permissions_can_menu('versiones')): ?><a href="versiones.php"><?php echo icon('versions'); ?> Control de versiones</a><?php endif; ?>
      <?php if(permissions_can_menu('reportes')): ?><a href="reportes.php"><?php echo icon('file'); ?> Reportes por correo</a><?php endif; ?>
      <a href="config_ia.php"><?php echo icon('gauge'); ?> Configuración de IA</a>
      <div class="topmenu__sep"></div>
      <a href="logout.php" class="topmenu__danger"><?php echo icon('logout'); ?> Cerrar sesión</a>
    </div>
  </div>
  <?php else: ?>
  <div class="topmenu" id="topmenu">
    <button type="button" class="topmenu__btn" id="topmenuBtn">
      <span class="topmenu__avatar">OP</span>
      <span><?php echo h(auth_user() ?? 'Usuario'); ?></span>
      <?php echo icon('chevron', 'topmenu__chev'); ?>
    </button>
    <div class="topmenu__drop" id="topmenuDrop">
      <a href="logout.php" class="topmenu__danger"><?php echo icon('logout'); ?> Cerrar sesión</a>
    </div>
  </div>
  <?php endif; ?>
</div>
<script>
(function(){
  var btn=document.getElementById('topmenuBtn'), drop=document.getElementById('topmenuDrop');
  if(btn&&drop){
    btn.onclick=function(e){ e.stopPropagation(); drop.classList.toggle('open'); };
    document.addEventListener('click', function(){ drop.classList.remove('open'); });
  }
})();
(function(){var b=document.getElementById('favoritePageBtn');if(!b)return;var active=<?php echo json_encode($ACTIVE ?? ''); ?>;var favs=<?php echo json_encode(array_values($favList)); ?>;b.onclick=function(){var i=favs.indexOf(active);if(i>=0)favs.splice(i,1);else favs.push(active);var fd=new FormData();fd.append('key','favorite_pages');fd.append('value',JSON.stringify(favs));fetch('user_prefs_api.php',{method:'POST',body:fd}).then(function(){document.getElementById('favoritePageIcon').textContent=favs.indexOf(active)>=0?'★':'☆';});};})();
</script>
<script src="assets/js/chart_preferences.js?v=20260826-chartprefs-perf-safe-3"></script>
<script src="assets/js/grid_tools.js?v=20260826-gridtools-perf-safe-2" defer></script>
<script>
(function(){var b=document.getElementById('densityBtn'),l=document.getElementById('densityLabel');if(!b)return;var modes=['normal','compact','comfortable'];var names={normal:'Normal',compact:'Compacta',comfortable:'Ampliada'};var mode=localStorage.getItem('clear_table_density')||'normal';function apply(){document.documentElement.setAttribute('data-density',mode);if(l)l.textContent=names[mode]||'Vista';}apply();b.addEventListener('click',function(){mode=modes[(modes.indexOf(mode)+1)%modes.length];localStorage.setItem('clear_table_density',mode);apply();});})();
</script>

<script>
(function(){
  var button=document.getElementById('globalPdfBtn');
  if(!button)return;
  function before(){document.body.classList.add('clear-printing');}
  function after(){document.body.classList.remove('clear-printing');}
  window.addEventListener('beforeprint',before);
  window.addEventListener('afterprint',after);
  button.addEventListener('click',function(){before();window.setTimeout(function(){window.print();},60);});
})();
</script>

<script>
(function(){
  var button=document.getElementById('themeToggleBtn');
  var label=document.getElementById('themeToggleLabel');
  var icon=document.getElementById('themeToggleIcon');
  if(!button)return;
  var key=window.CLEAR_THEME_USER_KEY||'clear_theme';
  function current(){return document.documentElement.getAttribute('data-theme')==='dark'?'dark':'light';}
  function render(){
    var dark=current()==='dark';
    button.setAttribute('aria-pressed',dark?'true':'false');
    if(label)label.textContent=dark?'Claro':'Oscuro';
    if(icon)icon.textContent=dark?'☀':'☾';
    button.title=dark?'Activar modo claro':'Activar modo oscuro';
  }
  function apply(theme,persist){
    document.documentElement.setAttribute('data-theme',theme);
    document.documentElement.style.colorScheme=theme;
    try{localStorage.setItem(key,theme);}catch(e){}
    render();
    if(persist){
      var fd=new FormData();fd.append('key','appearance_theme');fd.append('value',theme);
      fetch('user_prefs_api.php',{method:'POST',body:fd,credentials:'same-origin'}).catch(function(){});
    }
    window.dispatchEvent(new CustomEvent('clear-theme-change',{detail:{theme:theme}}));
  }
  render();
  button.addEventListener('click',function(){apply(current()==='dark'?'light':'dark',true);});
})();
</script>
