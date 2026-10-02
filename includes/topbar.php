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
<link rel="stylesheet" href="assets/css/grid_tools.css?v=20260924-columns-1" media="all">
<style>
/* Layout del menú: se incluye acá para evitar depender del caché de app.css. */
.menuLayout{position:relative;display:inline-flex}
.menuLayout__drop[hidden]{display:none!important}
.menuLayout__drop{position:absolute;right:0;top:calc(100% + 9px);z-index:120;width:300px;padding:10px;background:var(--bg-card);border:1px solid var(--line-mid);border-radius:12px;box-shadow:0 16px 38px rgba(8,31,41,.18)}
.menuLayout__title{padding:4px 7px 9px;font-size:12px;font-weight:700;color:var(--text)}
.menuLayout__option{width:100%;display:grid;grid-template-columns:38px minmax(0,1fr) 20px;align-items:center;gap:10px;padding:10px;border:1px solid transparent;border-radius:9px;background:transparent;color:var(--text);text-align:left}
.menuLayout__option:hover,.menuLayout__option.is-active{background:var(--petrol-soft);border-color:var(--petrol-line)}
.menuLayout__option.is-active{border-color:var(--petrol)}
.menuLayout__option b{display:block;font-size:13px}.menuLayout__option small{display:block;margin-top:2px;color:var(--text-mut);font-size:10.5px;font-weight:400}.menuLayout__check{visibility:hidden;color:var(--petrol);font-weight:800}.menuLayout__option.is-active .menuLayout__check{visibility:visible}
.menuLayout__preview{width:30px;height:23px;border:2px solid currentColor;border-radius:4px;position:relative;color:var(--petrol)}.menuLayout__preview--left:before{content:"";position:absolute;left:0;top:0;bottom:0;width:8px;background:currentColor}.menuLayout__preview--top:before{content:"";position:absolute;left:0;right:0;top:0;height:7px;background:currentColor}
html[data-menu-layout="top"] .app{display:block}
html[data-menu-layout="top"] .side{width:100%;height:auto;min-height:64px;max-height:none;position:sticky;top:0;z-index:90;display:flex;flex-direction:row;align-items:stretch;overflow:visible;border-right:0;border-bottom:1px solid var(--line-mid);box-shadow:0 3px 14px rgba(8,31,41,.06)}
html[data-menu-layout="top"] .side__brand{width:145px;min-width:145px;padding:7px 13px;border-bottom:0;border-right:1px solid var(--line-mid)}
html[data-menu-layout="top"] .side__brand img{max-width:112px;max-height:48px;object-fit:contain}
html[data-menu-layout="top"] .side__user,html[data-menu-layout="top"] .side__foot,html[data-menu-layout="top"] .side > div[style*="margin:8px 6px"]{display:none!important}
html[data-menu-layout="top"] .side__nav{flex:1;display:flex;align-items:center;min-width:0;padding:5px 8px;overflow:visible}
html[data-menu-layout="top"] .side__nav > div:not(#sortableMenuScreens){display:none!important}
html[data-menu-layout="top"] .side__nav > .side__link:first-child{margin:0 3px;padding:9px 10px;width:auto;flex:0 0 auto}
html[data-menu-layout="top"] #sortableMenuScreens{flex:1;display:flex;align-items:center;gap:2px;min-width:0;overflow-x:auto;overflow-y:visible;scrollbar-width:thin}
html[data-menu-layout="top"] #sortableMenuScreens > .side__link,html[data-menu-layout="top"] #sortableMenuScreens > .side__menuGroup{flex:0 0 auto;margin:0 2px}
html[data-menu-layout="top"] .side__link{min-height:42px;padding:8px 10px;border-radius:8px;gap:7px;white-space:nowrap;font-size:11.5px}
html[data-menu-layout="top"] .side__link svg{width:16px;height:16px}
html[data-menu-layout="top"] .side__menuGroup{position:relative}
html[data-menu-layout="top"] .side__submenu{position:absolute;left:0;top:calc(100% + 5px);z-index:110;display:none;width:270px;max-height:65vh;overflow:auto;padding:7px;background:var(--bg-card);border:1px solid var(--line-mid);border-radius:10px;box-shadow:0 14px 30px rgba(8,31,41,.18)}
html[data-menu-layout="top"] .side__menuGroup.is-open .side__submenu{display:block}
html[data-menu-layout="top"] .side__sublink{margin:1px;padding:8px 10px;white-space:normal}
html[data-menu-layout="top"] .main{width:100%;padding:18px 30px 40px}
html[data-theme="dark"] .menuLayout__drop{background:var(--bg-card);border-color:var(--line-mid)}
@media(max-width:900px){html[data-menu-layout="top"] .app{display:flex}html[data-menu-layout="top"] .side{width:var(--sidebar-w);height:100vh;position:fixed;left:-250px;top:0;z-index:50;display:flex;flex-direction:column;overflow-y:auto;border-right:1px solid var(--line-mid);border-bottom:0}html[data-menu-layout="top"] .side.is-open{left:0}html[data-menu-layout="top"] .side__brand{width:auto;min-width:0;padding:18px 16px 16px;border-right:0;border-bottom:3px solid var(--petrol)}html[data-menu-layout="top"] .side__brand img{max-width:160px;max-height:none}html[data-menu-layout="top"] .side__user{display:flex!important}html[data-menu-layout="top"] .side__nav{display:block;padding:0;overflow-y:auto}html[data-menu-layout="top"] #sortableMenuScreens{display:block;overflow:visible}html[data-menu-layout="top"] .side__submenu{position:static;width:auto;max-height:none;box-shadow:none;border:0;background:transparent}html[data-menu-layout="top"] .main{padding:18px}.menuLayout__drop{position:fixed;right:12px;top:58px;width:min(300px,calc(100vw - 24px))}}
</style>
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
  <div class="menuLayout" id="menuLayoutControl" style="margin-right:8px">
    <button type="button" class="topmenu__btn" id="menuLayoutBtn" title="Cambiar posición del menú" aria-haspopup="true" aria-expanded="false">
      <?php echo icon('grid'); ?><span>Menú</span>
    </button>
    <div class="menuLayout__drop" id="menuLayoutDrop" hidden>
      <div class="menuLayout__title">Posición del menú principal</div>
      <button type="button" class="menuLayout__option" data-menu-layout-value="left">
        <span class="menuLayout__preview menuLayout__preview--left"></span>
        <span><b>Izquierda</b><small>Menú vertical en el lateral</small></span>
        <span class="menuLayout__check">✓</span>
      </button>
      <button type="button" class="menuLayout__option" data-menu-layout-value="top">
        <span class="menuLayout__preview menuLayout__preview--top"></span>
        <span><b>Superior</b><small>Menú horizontal en la parte superior</small></span>
        <span class="menuLayout__check">✓</span>
      </button>
    </div>
  </div>
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
<script src="assets/js/grid_tools.js?v=20260924-columns-1" defer></script>
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

<script>
(function(){
  var root=document.documentElement,wrap=document.getElementById('menuLayoutControl'),btn=document.getElementById('menuLayoutBtn'),drop=document.getElementById('menuLayoutDrop');
  if(!wrap||!btn||!drop)return;
  var options=Array.prototype.slice.call(drop.querySelectorAll('[data-menu-layout-value]'));
  function current(){return root.getAttribute('data-menu-layout')==='top'?'top':'left';}
  function render(){
    var value=current();
    options.forEach(function(option){option.classList.toggle('is-active',option.getAttribute('data-menu-layout-value')===value);});
  }
  function close(){drop.hidden=true;btn.setAttribute('aria-expanded','false');}
  function open(){drop.hidden=false;btn.setAttribute('aria-expanded','true');render();}
  btn.addEventListener('click',function(e){e.stopPropagation();drop.hidden?open():close();});
  drop.addEventListener('click',function(e){e.stopPropagation();});
  document.addEventListener('click',close);
  document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
  options.forEach(function(option){
    option.addEventListener('click',function(){
      var value=option.getAttribute('data-menu-layout-value')==='top'?'top':'left';
      root.setAttribute('data-menu-layout',value);
      window.CLEAR_MENU_LAYOUT=value;
      render();close();
      var fd=new FormData();fd.append('key','menu_layout');fd.append('value',value);
      fetch('user_prefs_api.php',{method:'POST',body:fd,credentials:'same-origin'}).catch(function(){});
      window.dispatchEvent(new CustomEvent('clear-menu-layout-change',{detail:{layout:value}}));
    });
  });
  render();
})();
</script>
