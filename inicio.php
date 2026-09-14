<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';

auth_require();

$APP_USER = auth_user() ?: 'CLEAR';
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = 'inicio';

$favKeys = json_decode((string) user_pref_get('favorite_pages', '[]'), true);
if (!is_array($favKeys)) $favKeys = [];

$catalog = [
    'dashboard_inst_sup' => ['Dashboard Inst Sup', 'Resumen ejecutivo de instalaciones', 'grid', 'dashboard_inst_sup.php'],
    'dashboard_pozos'    => ['Dashboard de Pozos', 'Resumen de telemetría y comunicación', 'chart', 'dashboard_pozos.php'],
    'monitoreo_pozos'    => ['Monitoreo Pozos', 'Estado general y variables operativas', 'monitor', 'monitoreo_pozos.php'],
    'telemetria_pcp'     => ['Telemetría PCP', 'Consulta de pozos PCP', 'oil', 'telemetria_pcp.php'],
    'telemetria_bes'     => ['Telemetría BES', 'Consulta de pozos BES', 'gauge', 'telemetria_bes.php'],
    'telemetria_tecss'   => ['Telemetría TECCS', 'Consulta de pozos TECCS', 'trend', 'telemetria_tecss.php'],
    'tecss_3sigma'       => ['3Sigma TECSS', 'Último análisis diario por pozo', 'chart', 'tecss_3sigma.php'],
    'alarmas24h'         => ['Alarmas 24h', 'Monitoreo unificado de pozos e instalaciones', 'bell', 'list.php?s=alarmas24h'],
    'instalaciones_alarmas_semanal' => ['Alarmas semanal', 'Análisis unificado de miércoles a martes', 'chart', 'instalaciones_alarmas_semanal.php'],
    'alarmas_activas'    => ['Alarmas por Históricos', 'Consulta por fechas y criterios', 'wave', 'list.php?s=alarmas_activas'],
    'pi_historico'       => ['PI Histórico', 'Tendencias y comparación con alarmas', 'trend', 'pi_historico.php'],
    'reconocidas'        => ['Reconocidas', 'Seguimiento y comentarios operativos', 'check', 'list.php?s=reconocidas'],
    'reconocidas_usr'    => ['Reconocidas por usuario', 'Ranking de reconocimientos', 'check', 'list.php?s=reconocidas_usr'],
    'top20_all'          => ['Top 20 alarmas', 'Ranking semanal de miércoles a martes', 'chart', 'instalaciones_top20.php'],
    'top20_24h'          => ['Top 20 24h', 'Recurrencia de las últimas 24 horas', 'chart', 'list.php?s=top20_24h'],
    'todas_alarmas'      => ['Todas las alarmas', 'Histórico completo y exportación', 'file', 'todas_alarmas.php'],
    'analisis_ia'        => ['Análisis IA', 'Diagnóstico y alertas predictivas', 'cpu', 'analisis_ia.php'],
    'reportes'           => ['Reportes por correo', 'PDF automáticos y programaciones', 'file', 'reportes.php'],
];

$favoriteCards = [];
foreach ($favKeys as $key) {
    if (isset($catalog[$key]) && permissions_can_menu($key)) {
        $favoriteCards[] = array_merge([$key], $catalog[$key]);
    }
    if (count($favoriteCards) >= 3) break;
}

if (!$favoriteCards) {
    foreach (['alarmas24h', 'instalaciones_alarmas_semanal', 'pi_historico'] as $key) {
        if (isset($catalog[$key]) && permissions_can_menu($key)) {
            $favoriteCards[] = array_merge([$key], $catalog[$key]);
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CLEAR · Centro de Operaciones</title>
  <link rel="stylesheet" href="assets/css/app.css?v=3.0.1">
  <style>
    .welcome{max-width:1460px;margin:0 auto;padding:4px 18px 28px}
    .welcome__hero{margin:6px 0 22px}
    .welcome__title{font-family:var(--font-head);font-size:40px;line-height:1;color:var(--text);letter-spacing:.2px}
    .welcome__lead{font-size:20px;font-weight:700;color:var(--petrol);margin-top:12px}
    .welcome__copy{font-size:14px;color:var(--text-soft);margin-top:8px;max-width:900px}
    .welcome__grid{display:grid;grid-template-columns:repeat(3,minmax(260px,1fr));gap:16px}
    .welcome-card{background:var(--bg-card);border:1px solid var(--line-mid);border-radius:15px;padding:22px;min-height:176px;display:flex;align-items:flex-start;gap:18px;box-shadow:0 6px 22px rgba(15,45,58,.045);transition:transform .18s,box-shadow .18s,border-color .18s}
    .welcome-card:hover{transform:translateY(-3px);box-shadow:0 12px 30px rgba(15,45,58,.09);border-color:var(--petrol-line)}
    .welcome-card__icon{width:66px;height:66px;flex:0 0 66px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:var(--icon-bg,#edf5ff);color:var(--icon-color,#1673d1)}
    .welcome-card__icon svg{width:36px;height:36px;stroke-width:1.65}
    .welcome-card__body{display:flex;flex-direction:column;min-height:130px;flex:1}
    .welcome-card h2{font-size:17px;line-height:1.2;margin:3px 0 8px;color:var(--text)}
    .welcome-card p{font-size:13px;color:var(--text-soft);line-height:1.55;margin-bottom:18px}
    .welcome-card__btn{margin-top:auto;align-self:flex-start;display:inline-flex;align-items:center;gap:9px;padding:9px 15px;background:var(--petrol);color:#fff;border-radius:8px;font-size:12px;font-weight:700;box-shadow:0 4px 10px rgba(26,77,92,.16)}
    .welcome-card__btn svg{width:15px;height:15px}
    .welcome-card--green{--icon-bg:#e8f7ef;--icon-color:#35a66f}
    .welcome-card--orange{--icon-bg:#fff0e4;--icon-color:#f06b00}
    .welcome-card--violet{--icon-bg:#f0ebff;--icon-color:#6c42c5}
    .welcome-card--cyan{--icon-bg:#e8f7fa;--icon-color:#1da2b8}
    .welcome-card--rose{--icon-bg:#fdeaf0;--icon-color:#e8446d}
    .welcome__lower{display:grid;grid-template-columns:repeat(3,minmax(260px,1fr));gap:16px;margin-top:18px}
    .welcome-panel{background:var(--bg-card);border:1px solid var(--line-mid);border-radius:15px;padding:18px 20px;min-height:242px;box-shadow:0 6px 22px rgba(15,45,58,.04)}
    .welcome-panel__title{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:800;margin-bottom:14px;color:var(--text)}
    .welcome-panel__title svg{width:18px;height:18px;color:var(--petrol)}
    .quick-list{display:flex;flex-direction:column;gap:8px}
    .quick-item{display:flex;align-items:center;gap:11px;border:1px solid var(--line-mid);border-radius:9px;padding:10px 11px;color:var(--text-soft);font-size:12px;background:var(--bg-card);transition:background .15s,border-color .15s}
    .quick-item:hover{background:var(--petrol-soft);border-color:var(--petrol-line);color:var(--petrol)}
    .quick-item svg{width:17px;height:17px;color:var(--item-color,var(--petrol));flex:0 0 17px}
    .quick-item span:first-of-type{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .quick-item small{font-size:10px;color:var(--text-mut);white-space:nowrap}
    .quick-item__arrow{font-size:17px;color:var(--text-mut)}
    .welcome-panel__foot{display:inline-flex;align-items:center;gap:8px;margin-top:13px;color:#2e7da0;font-size:12px}
    .status-list{border:1px solid var(--line-mid);border-radius:9px;overflow:hidden}
    .status-row{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--line);font-size:12px}
    .status-row:last-child{border-bottom:0}
    .status-pill{min-width:83px;text-align:center;padding:4px 9px;border-radius:999px;background:var(--green-soft);color:var(--green);font-size:10px;font-weight:800}
    .welcome-empty{padding:18px;border:1px dashed var(--line-strong);border-radius:9px;text-align:center;color:var(--text-mut);font-size:12px}
    @media(max-width:1180px){.welcome__grid,.welcome__lower{grid-template-columns:repeat(2,1fr)}.welcome__lower .welcome-panel:last-child{grid-column:1/-1}}
    @media(max-width:760px){.welcome{padding:0 4px 20px}.welcome__grid,.welcome__lower{grid-template-columns:1fr}.welcome__lower .welcome-panel:last-child{grid-column:auto}.welcome__title{font-size:34px}.welcome-card{min-height:160px}}
  </style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <section class="welcome">
      <div class="welcome__hero">
        <h1 class="welcome__title">Centro de Operaciones</h1>
        <div class="welcome__lead">Bienvenido a la Plataforma Operativa CLEAR</div>
        <p class="welcome__copy">Tu punto central para el monitoreo de alarmas, telemetría de pozos, tendencias históricas, reportes y análisis con inteligencia artificial.</p>
      </div>

      <div class="welcome__grid">
        <article class="welcome-card">
          <div class="welcome-card__icon"><?php echo icon('chart'); ?></div>
          <div class="welcome-card__body"><h2>Dashboard de Pozos</h2><p>Resumen general de telemetría, comunicación y estado operativo de los pozos.</p><a class="welcome-card__btn" href="dashboard_pozos.php">Ir al Dashboard <?php echo icon('chevron-r'); ?></a></div>
        </article>
        <article class="welcome-card welcome-card--green">
          <div class="welcome-card__icon"><?php echo icon('trend'); ?></div>
          <div class="welcome-card__body"><h2>Telemetría de Pozos</h2><p>Acceso directo a las vistas BM, PCP, BES y TECCS con sus variables operativas.</p><a class="welcome-card__btn" href="monitoreo_pozos.php">Ver Telemetría <?php echo icon('chevron-r'); ?></a></div>
        </article>
        <article class="welcome-card welcome-card--orange">
          <div class="welcome-card__icon"><?php echo icon('bell'); ?></div>
          <div class="welcome-card__body"><h2>Alarmas</h2><p>Eventos de pozos e instalaciones con filtro por tipo, entidad, estado y prioridad.</p><a class="welcome-card__btn" href="list.php?s=alarmas24h">Ver Alarmas <?php echo icon('chevron-r'); ?></a></div>
        </article>
        <article class="welcome-card welcome-card--violet">
          <div class="welcome-card__icon"><?php echo icon('oil'); ?></div>
          <div class="welcome-card__body"><h2>Alarmas semanal</h2><p>Gráficos y detalle semanal unificado, con agrupación real por pozo cuando corresponde.</p><a class="welcome-card__btn" href="instalaciones_alarmas_semanal.php">Ver análisis semanal <?php echo icon('chevron-r'); ?></a></div>
        </article>
        <article class="welcome-card welcome-card--cyan">
          <div class="welcome-card__icon"><?php echo icon('trend'); ?></div>
          <div class="welcome-card__body"><h2>PI Histórico</h2><p>Tendencias de proceso y comparación visual con eventos de alarma registrados.</p><a class="welcome-card__btn" href="pi_historico.php">Ver Tendencias <?php echo icon('chevron-r'); ?></a></div>
        </article>
        <article class="welcome-card welcome-card--rose">
          <div class="welcome-card__icon"><?php echo icon('file'); ?></div>
          <div class="welcome-card__body"><h2>Reportes y Análisis</h2><p>PDF automáticos, estadísticas operativas y diagnóstico asistido por IA.</p><a class="welcome-card__btn" href="<?php echo auth_es_admin() ? 'reportes.php' : 'analisis_ia.php'; ?>">Ver Reportes <?php echo icon('chevron-r'); ?></a></div>
        </article>
      </div>

      <div class="welcome__lower">
        <section class="welcome-panel">
          <div class="welcome-panel__title"><?php echo icon('check'); ?><span>Favoritos</span></div>
          <div class="quick-list">
            <?php if ($favoriteCards): foreach ($favoriteCards as $fav): ?>
              <a class="quick-item" href="<?php echo h($fav[4]); ?>">
                <?php echo icon($fav[3]); ?><span><?php echo h($fav[1]); ?></span><span class="quick-item__arrow">›</span>
              </a>
            <?php endforeach; else: ?>
              <div class="welcome-empty">Todavía no hay pantallas favoritas.</div>
            <?php endif; ?>
          </div>
          <a class="welcome-panel__foot" href="config_menu.php"><?php echo icon('gauge'); ?> Gestionar favoritos</a>
        </section>

        <section class="welcome-panel">
          <div class="welcome-panel__title"><?php echo icon('history'); ?><span>Accesos recientes</span></div>
          <div class="quick-list" id="recentAccessList"><div class="welcome-empty">Se mostrarán las últimas pantallas visitadas.</div></div>
          <a class="welcome-panel__foot" href="#" id="clearRecentHistory">Limpiar historial ›</a>
        </section>

        <section class="welcome-panel">
          <div class="welcome-panel__title"><?php echo icon('shield'); ?><span>Estado del sistema</span></div>
          <div class="status-list">
            <div class="status-row"><span>SQL Server</span><span class="status-pill">Conectado</span></div>
            <div class="status-row"><span>PI Web API</span><span class="status-pill">Disponible</span></div>
            <div class="status-row"><span>Reportes</span><span class="status-pill">Activos</span></div>
            <div class="status-row"><span>Plataforma</span><span class="status-pill">En vivo</span></div>
          </div>
          <a class="welcome-panel__foot" href="estado_sistema.php">Ver más detalles ›</a>
        </section>
      </div>
    </section>
  </main>
</div>
<script src="assets/js/app.js?v=3.0.1"></script>
<script>
(function(){
  var container=document.getElementById('recentAccessList');
  var clear=document.getElementById('clearRecentHistory');
  var records=[];
  try{records=JSON.parse(localStorage.getItem('clear_recent_pages')||'[]');}catch(e){records=[];}
  records=Array.isArray(records)?records.filter(function(r){return r&&r.url&&r.title&&r.url.indexOf('inicio.php')===-1&&r.url.indexOf('index.php')===-1;}).slice(0,3):[];
  function elapsed(ts){
    var sec=Math.max(0,Math.round((Date.now()-Number(ts||0))/1000));
    if(sec<60)return 'Ahora';
    var min=Math.floor(sec/60);if(min<60)return 'Hace '+min+' min';
    var h=Math.floor(min/60);if(h<24)return 'Hace '+h+' h';
    return 'Hace '+Math.floor(h/24)+' d';
  }
  function safe(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function render(){
    if(!records.length){container.innerHTML='<div class="welcome-empty">Se mostrarán las últimas pantallas visitadas.</div>';return;}
    container.innerHTML=records.map(function(r){return '<a class="quick-item" href="'+safe(r.url)+'"><span style="color:var(--petrol)">▥</span><span>'+safe(r.title)+'</span><small>'+elapsed(r.time)+'</small></a>';}).join('');
  }
  render();
  if(clear)clear.addEventListener('click',function(e){e.preventDefault();records=[];try{localStorage.removeItem('clear_recent_pages');}catch(x){}render();});
})();
</script>
</body>
</html>
