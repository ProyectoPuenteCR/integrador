<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/sidebar.php
   Barra lateral. $ACTIVE marca el ítem activo.
============================================================= */
if (!isset($ACTIVE)) $ACTIVE = '';
require_once __DIR__ . '/permissions.php';

// Cargar config de visibilidad del menú (si está disponible)
if (file_exists(__DIR__ . '/appconfig.php')) {
    require_once __DIR__ . '/appconfig.php';
}
$ocultos = function_exists('menu_ocultos') ? menu_ocultos() : [];

$telemetriaPozos = [
    ['key'=>'telemetria_general','label'=>'Grilla general de pozos','icon'=>'grid','url'=>'telemetria_general.php'],
    ['key'=>'monitoreo_pozos','label'=>'Monitoreo Pozos','icon'=>'monitor','url'=>'monitoreo_pozos.php'],
    ['key'=>'telemetria_pcp','label'=>'Telemetría PCP','icon'=>'oil','url'=>'telemetria_pcp.php'],
    ['key'=>'telemetria_bes','label'=>'Telemetría BES','icon'=>'gauge','url'=>'telemetria_bes.php'],
    ['key'=>'telemetria_tecss','label'=>'Telemetría TECCS','icon'=>'trend','url'=>'telemetria_tecss.php'],
    ['key'=>'tecss_3sigma','label'=>'3Sigma TECSS','icon'=>'chart','url'=>'tecss_3sigma.php'],
    ['key'=>'tecss_vibraciones','label'=>'Análisis de Vibraciones','icon'=>'wave','url'=>'tecss_vibraciones.php'],
    ['key'=>'gestion_telemetria','label'=>'Gestión de Telemetría','icon'=>'chart','url'=>'gestion_telemetria.php'],
    ['key'=>'sin_telemetria_zafiro','label'=>'Sin Telemetría en Zafiro','icon'=>'signal-off','url'=>'sin_telemetria_zafiro.php'],
    ['key'=>'pozos','label'=>'Ficha de pozos','icon'=>'oil','url'=>'list.php?s=pozos'],
    ['key'=>'pozos_tecss','label'=>'Pozos · técnico','icon'=>'gauge','url'=>'list.php?s=pozos_tecss'],
];

$inyeccionAgua = [
    'key' => 'inyeccion_agua',
    'label' => 'Inyección de Agua',
    'icon' => 'droplet',
    'url' => 'inyeccion_agua.php',
];

$alarmasInstalaciones = [
    ['key' => 'alarmas24h',      'label' => 'Alarmas 24h',            'icon' => 'bell',  'url' => 'list.php?s=alarmas24h'],
    ['key' => 'instalaciones_alarmas_semanal', 'label' => 'Alarmas semanal', 'icon' => 'chart', 'url' => 'instalaciones_alarmas_semanal.php'],
    ['key' => 'alarmas_activas', 'label' => 'Alarmas por Históricos', 'icon' => 'wave',  'url' => 'list.php?s=alarmas_activas'],
    ['key' => 'pi_historico',    'label' => 'PI Histórico',            'icon' => 'trend', 'url' => 'pi_historico.php'],
    ['key' => 'suprimidas',      'label' => 'Suprimidas',              'icon' => 'shield','url' => 'list.php?s=suprimidas'],
    ['key' => 'reconocidas',     'label' => 'Reconocidas',             'icon' => 'check', 'url' => 'list.php?s=reconocidas'],
    ['key' => 'reconocidas_usr', 'label' => 'Reconocidas usuario',     'icon' => 'check', 'url' => 'list.php?s=reconocidas_usr'],
    ['key' => 'top20_all',       'label' => 'Top 20 alarmas',          'icon' => 'chart', 'url' => 'instalaciones_top20.php'],
    ['key' => 'top20_24h',       'label' => 'Top 20 24h',              'icon' => 'chart', 'url' => 'list.php?s=top20_24h'],
    ['key' => 'ranking24h',      'label' => 'Ranking 24h',             'icon' => 'chart', 'url' => 'list.php?s=ranking24h'],
    ['key' => 'tendencia_sem',   'label' => 'Tendencia semanal',       'icon' => 'trend', 'url' => 'list.php?s=tendencia_sem'],
    ['key' => 'tend_sem_tags',   'label' => 'Tendencia por tag',       'icon' => 'trend', 'url' => 'list.php?s=tend_sem_tags'],
    ['key' => 'prioridad',       'label' => 'Prioridad',               'icon' => 'gauge', 'url' => 'list.php?s=prioridad'],
    ['key' => 'todas_alarmas',   'label' => 'Todas las alarmas',       'icon' => 'file',  'url' => 'todas_alarmas.php'],
    ['key' => 'analisis_ia',     'label' => 'Análisis IA',             'icon' => 'trend', 'url' => 'analisis_ia.php'],
    ['key' => 'importadas',      'label' => 'Alarmas importadas',      'icon' => 'file',  'url' => 'list.php?s=importadas'],
];

/* Módulo aditivo. Las rutas operativas existentes no se reemplazan. */
$novedadesSemanales = [
    ['key'=>'novedades_semanales_panel','label'=>'Panel semanal','icon'=>'grid','url'=>'novedades_semanales.php'],
    ['key'=>'novedades_semanales_monitoreo','label'=>'Reporte de Pozos','icon'=>'monitor','url'=>'novedades_semanales_monitoreo.php'],
    ['key'=>'novedades_semanales_auditoria','label'=>'Auditoría de instalaciones','icon'=>'check','url'=>'novedades_semanales_auditoria.php'],
    ['key'=>'novedades_semanales_requerimientos','label'=>'Requerimientos de sala de control','icon'=>'message','url'=>'novedades_semanales_requerimientos.php'],
    ['key'=>'micros_contables','label'=>'Micros contables','icon'=>'chart','url'=>'micros_contables.php'],
    ['key'=>'micros_contables_cierre','label'=>'Cierre diario','icon'=>'trend','url'=>'micros_contables_cierre.php'],
    ['key'=>'novedades_semanales_malos_actores','label'=>'Malos actores','icon'=>'chart','url'=>'novedades_semanales_malos_actores.php'],
    ['key'=>'novedades_semanales_comparativa','label'=>'Comparativa semanal','icon'=>'trend','url'=>'novedades_semanales_comparativa.php'],
    ['key'=>'novedades_semanales_seguimiento','label'=>'Seguimiento','icon'=>'message','url'=>'novedades_semanales_seguimiento.php'],
];

// Dashboard es la única pantalla fuera de los dos grupos operativos.
$menuItems = [
    ['key'=>'dashboard_inst_sup','label'=>'Dashboard Inst Sup','icon'=>'grid','url'=>'dashboard_inst_sup.php'],
    ['key'=>'dashboard_pozos','label'=>'Dashboard Pozos','icon'=>'oil','url'=>'dashboard_pozos.php'],
    ['key'=>'pozos_por_baterias','label'=>'Pozos por Baterías','icon'=>'grid','url'=>'pozos_por_baterias.php'],
    ['key'=>'scada_realtime','label'=>'SCADA Real time','icon'=>'monitor','url'=>'scada_realtime.php'],
    ['key'=>'grupo_telemetria','label'=>'Telemetría de Pozos','icon'=>'monitor','children'=>$telemetriaPozos],
    $inyeccionAgua,
    ['key'=>'grupo_novedades_semanales','label'=>'Novedades semanales','icon'=>'calendar','children'=>$novedadesSemanales],
    ['key'=>'reportes_guardados','label'=>'REPORTES','icon'=>'file','url'=>'reportes_guardados.php'],
    ['key'=>'comentarios','label'=>'Comentarios','icon'=>'message','url'=>'comentarios.php'],
    ['key'=>'grupo_instalaciones','label'=>'Alarmas','icon'=>'bell','children'=>$alarmasInstalaciones],
];

$favKeys = json_decode((string)user_pref_get('favorite_pages','[]'), true);
if (!is_array($favKeys)) $favKeys=[];
$favCatalog = [
    'dashboard_inst_sup'=>['Dashboard Inst Sup','grid','dashboard_inst_sup.php'],
    'dashboard_pozos'=>['Dashboard Pozos','oil','dashboard_pozos.php'],
    'pozos_por_baterias'=>['Pozos por Baterías','grid','pozos_por_baterias.php'],
    'scada_realtime'=>['SCADA Real time','monitor','scada_realtime.php'],
    'reportes'=>['Reportes por correo','file','reportes.php'],
    'reportes_guardados'=>['REPORTES','file','reportes_guardados.php'],
    'comentarios'=>['Comentarios','message','comentarios.php']
];
foreach (array_merge($telemetriaPozos, $novedadesSemanales, $alarmasInstalaciones) as $n) {
    $favCatalog[$n['key']] = [$n['label'], $n['icon'], $n['url']];
}
$favCatalog[$inyeccionAgua['key']] = [$inyeccionAgua['label'], $inyeccionAgua['icon'], $inyeccionAgua['url']];
$savedTheme = strtolower(trim((string)user_pref_get('appearance_theme', 'light')));
if (!in_array($savedTheme, ['light','dark'], true)) $savedTheme = 'light';
?>
<script>
(function(){
  var serverTheme=<?php echo json_encode($savedTheme); ?>;
  var userKey=<?php echo json_encode('clear_theme_' . strtolower((string)($APP_USER ?? auth_user() ?? 'usuario'))); ?>;
  var stored='';
  try{stored=localStorage.getItem(userKey)||'';}catch(e){}
  var theme=(stored==='dark'||stored==='light')?stored:serverTheme;
  document.documentElement.setAttribute('data-theme',theme);
  document.documentElement.style.colorScheme=theme;
  window.CLEAR_THEME_USER_KEY=userKey;
})();
</script>
<aside class="side" id="sidebar">
  <div class="side__brand">
    <img src="assets/img/logo-clear.jpg" alt="CLEAR Petroleum">
  </div>

  <div class="side__user">
    <div class="side__avatar">CL</div>
    <div>
      <b><?php echo h($APP_USER ?? 'CLEAR01'); ?></b>
      <span><?php echo h($APP_ROLE ?? 'Administrador'); ?></span>
    </div>
  </div>



  <nav class="side__nav" id="sideNav">
    <a class="side__link side__standalone <?php echo $ACTIVE==='inicio'?'is-active':''; ?>" href="inicio.php">
      <?php echo icon('home'); ?> <span class="side__linkLabel">Inicio</span>
    </a>
    <div style="border-top:1px solid var(--line);margin:5px 10px"></div>
    <?php if(!empty($favKeys)): ?>
      <div style="padding:7px 14px 5px;color:var(--text-mut);font-size:10px;text-transform:uppercase;letter-spacing:1px">Favoritos</div>
      <?php foreach($favKeys as $fk): if(empty($favCatalog[$fk])||!permissions_can_menu($fk))continue;$fc=$favCatalog[$fk]; ?>
        <a class="side__link <?php echo $ACTIVE===$fk?'is-active':''; ?>" href="<?php echo h($fc[2]); ?>"><?php echo icon($fc[1]); ?> ★ <?php echo h($fc[0]); ?></a>
      <?php endforeach; ?>
      <div style="border-top:1px solid var(--line);margin:5px 10px"></div>
    <?php endif; ?>
    <div id="sortableMenuScreens">
      <?php foreach ($menuItems as $item): ?>
        <?php
          $children = $item['children'] ?? [];
          if ($children) {
              $visibleChildren = [];
              foreach ($children as $child) {
                  if (!in_array($child['key'], $ocultos, true) && permissions_can_menu($child['key'])) $visibleChildren[] = $child;
              }
              if (!$visibleChildren) continue;
              $groupActive = false;
              foreach ($visibleChildren as $child) if ($ACTIVE === $child['key']) $groupActive = true;
        ?>
          <div class="side__menuGroup <?php echo $groupActive ? 'is-open' : ''; ?>" data-menu-key="<?php echo h($item['key']); ?>">
            <button type="button" class="side__link side__link--group <?php echo $groupActive ? 'is-active' : ''; ?>" aria-expanded="<?php echo $groupActive ? 'true' : 'false'; ?>">
              <?php echo icon($item['icon']); ?>
              <span class="side__linkLabel"><?php echo h($item['label']); ?></span>
              <span class="side__groupChevron">⌄</span>
            </button>
            <div class="side__submenu">
              <?php foreach ($visibleChildren as $child): ?>
                <a class="side__sublink <?php echo $ACTIVE === $child['key'] ? 'is-active' : ''; ?>" href="<?php echo h($child['url']); ?>">
                  <?php echo icon($child['icon']); ?><span><?php echo h($child['label']); ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php } else { ?>
          <?php if (in_array($item['key'], $ocultos, true) || !permissions_can_menu($item['key'])) continue; ?>
          <a class="side__link side__standalone <?php echo $ACTIVE === $item['key'] ? 'is-active' : ''; ?>"
             href="<?php echo h($item['url'] ?? ('list.php?s=' . $item['key'])); ?>">
            <?php echo icon($item['icon']); ?> <span class="side__linkLabel"><?php echo h($item['label']); ?></span>
          </a>
        <?php } ?>
      <?php endforeach; ?>
    </div>
  </nav>

  <?php if (auth_es_admin()): ?><div style="margin:8px 6px"><a class="side__link side__standalone <?php echo $ACTIVE==='estado_sistema'?'is-active':''; ?>" href="estado_sistema.php"><?php echo icon('gauge'); ?> <span class="side__linkLabel">Estado del sistema</span></a></div><?php endif; ?>

  <div class="side__foot">
    <div class="side__status">Plataforma operativa · <b>En vivo</b></div>
    <a class="side__logout" href="logout.php"><?php echo icon('logout'); ?> Cerrar sesión</a>
  </div>

<style>
  .side__menuGroup{position:relative;margin:2px 6px}
  .side__link--group{width:100%;border:0;background:transparent;text-align:left;font:inherit;cursor:pointer}
  .side__link--group .side__linkLabel{flex:1}
  .side__groupChevron{margin-left:auto;font-size:16px;transition:transform .18s;color:var(--text-mut)}
  .side__menuGroup.is-open .side__groupChevron{transform:rotate(180deg)}
  .side__submenu{display:none;padding:3px 0 7px 22px}
  .side__menuGroup.is-open .side__submenu{display:block}
  .side__sublink{display:flex;align-items:center;gap:9px;padding:8px 12px;border-radius:8px;color:var(--text-mut);font-size:12.5px;text-decoration:none;margin:1px 5px 1px 0}
  .side__sublink svg{width:15px;height:15px;flex:0 0 15px}
  .side__sublink:hover{background:var(--petrol-soft);color:var(--petrol)}
  .side__sublink.is-active{background:var(--petrol-soft);color:var(--petrol);font-weight:700}
  .side__standalone{margin:2px 6px}
  @media(max-width:900px){.side__submenu{padding-left:10px}}
</style>
<script>
(function(){
  Array.prototype.slice.call(document.querySelectorAll('.side__link--group')).forEach(function(button){
    button.addEventListener('click',function(){
      var group=button.closest('.side__menuGroup');
      if(!group)return;
      var open=group.classList.toggle('is-open');
      button.setAttribute('aria-expanded',open?'true':'false');
    });
  });
})();
</script>

</aside>
