<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/version_history.php';

auth_require_admin();
permissions_require_menu('versiones');

$APP_USER = auth_user();
$APP_ROLE = 'Administrador';
$ACTIVE = 'versiones';
$history = clear_version_history();
$currentVersion = clear_current_version();

$q = trim((string)($_GET['q'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$module = trim((string)($_GET['module'] ?? ''));

$allTypes = [];
$allModules = [];
foreach ($history as $release) {
    $allTypes[$release['type']] = true;
    foreach ($release['modules'] as $m) $allModules[$m] = true;
}
ksort($allTypes);
ksort($allModules);

$filtered = array_values(array_filter($history, function ($release) use ($q, $type, $module) {
    if ($type !== '' && strcasecmp($release['type'], $type) !== 0) return false;
    if ($module !== '' && !in_array($module, $release['modules'], true)) return false;
    if ($q === '') return true;
    $haystack = implode(' ', [
        $release['version'], $release['title'], $release['summary'], $release['author'],
        implode(' ', $release['modules']), implode(' ', $release['changes'])
    ]);
    return mb_stripos($haystack, $q, 0, 'UTF-8') !== false;
}));

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    audit_log('EXPORTACION', 'versiones', 'Historial de versiones');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="CLEAR_historial_versiones_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Versión', 'Fecha', 'Tipo', 'Estado', 'Título', 'Módulos', 'Resumen', 'Cambios', 'Autor'], ';');
    foreach ($filtered as $r) {
        fputcsv($out, [
            $r['version'], $r['date'], $r['type'], $r['status'], $r['title'],
            implode(', ', $r['modules']), $r['summary'], implode(' | ', $r['changes']), $r['author']
        ], ';');
    }
    fclose($out);
    exit;
}

$totalChanges = array_sum(array_map(fn($r) => count($r['changes']), $history));
$latestDate = $history[0]['date'] ?? '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Control de versiones · CLEAR</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260714-versions">
  <style>
    .versions-hero{display:grid;grid-template-columns:1.4fr repeat(3,minmax(170px,.55fr));gap:14px;margin-bottom:18px}
    .version-card{background:#fff;border:1px solid var(--line-mid);border-top:3px solid var(--petrol);border-radius:14px;padding:18px;min-height:118px}
    .version-card--main{background:linear-gradient(135deg,var(--petrol),#123a46);color:#fff;border:0}
    .version-card__label{font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--text-mut);font-weight:700}
    .version-card--main .version-card__label{color:rgba(255,255,255,.68)}
    .version-card__value{font-family:var(--font-head);font-size:30px;color:var(--petrol);margin-top:10px;line-height:1}
    .version-card--main .version-card__value{color:#fff;font-size:38px}
    .version-card__note{font-size:12px;color:var(--text-soft);margin-top:10px;line-height:1.45}
    .version-card--main .version-card__note{color:rgba(255,255,255,.78)}
    .versions-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid var(--line-mid);border-radius:14px;padding:12px;margin-bottom:18px}
    .versions-toolbar input,.versions-toolbar select{height:42px;border:1px solid var(--line-mid);border-radius:9px;background:#f8fbfc;padding:0 12px;color:var(--text)}
    .versions-toolbar input{flex:1;min-width:260px}
    .versions-toolbar select{min-width:175px}
    .versions-toolbar .btn-export{height:42px}
    .versions-count{font-size:12px;color:var(--text-mut);margin-left:auto}
    .version-list{display:flex;flex-direction:column;gap:14px;position:relative}
    .release{background:#fff;border:1px solid var(--line-mid);border-radius:15px;overflow:hidden;box-shadow:0 4px 14px rgba(26,77,92,.04)}
    .release.is-current{border-color:rgba(21,182,126,.38);box-shadow:0 7px 24px rgba(21,182,126,.10)}
    .release__head{display:grid;grid-template-columns:120px minmax(240px,1fr) auto;gap:18px;align-items:center;padding:18px 20px;cursor:pointer}
    .release__head:hover{background:#fafcfd}
    .release__version{font-family:var(--font-head);font-size:27px;color:var(--petrol);line-height:1}
    .release__date{font-size:11px;color:var(--text-mut);margin-top:6px}
    .release__title{font-family:var(--font-head);font-size:21px;color:var(--text);margin-bottom:5px}
    .release__summary{font-size:13px;color:var(--text-soft);line-height:1.55}
    .release__meta{display:flex;align-items:center;justify-content:flex-end;gap:7px;flex-wrap:wrap}
    .release__badge{display:inline-flex;align-items:center;padding:5px 9px;border:1px solid var(--line-mid);border-radius:999px;background:#f8fbfc;color:var(--text-soft);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.45px}
    .release__badge.current{background:var(--green-soft);border-color:rgba(21,182,126,.22);color:var(--green-tx)}
    .release__toggle{display:inline-flex;width:34px;height:34px;align-items:center;justify-content:center;border:1px solid var(--line-mid);border-radius:9px;color:var(--petrol);transition:transform .2s}
    .release.open .release__toggle{transform:rotate(180deg)}
    .release__body{display:none;border-top:1px solid var(--line);padding:18px 20px 20px;background:#fbfdfe}
    .release.open .release__body{display:block}
    .release__modules{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px}
    .release__module{padding:5px 9px;border-radius:8px;background:var(--petrol-soft);color:var(--petrol);font-size:11px;font-weight:700}
    .release__changes{display:grid;grid-template-columns:repeat(2,minmax(260px,1fr));gap:9px 18px;margin:0;padding:0;list-style:none}
    .release__changes li{position:relative;padding:10px 12px 10px 34px;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--text-soft);font-size:12.5px;line-height:1.5}
    .release__changes li:before{content:'✓';position:absolute;left:12px;top:10px;color:var(--green);font-weight:800}
    .release__footer{display:flex;justify-content:space-between;gap:12px;margin-top:14px;padding-top:12px;border-top:1px solid var(--line);font-size:11px;color:var(--text-mut)}
    .empty-state{text-align:center;padding:70px 20px;background:#fff;border:1px dashed var(--line-mid);border-radius:14px;color:var(--text-mut)}
    @media(max-width:1100px){.versions-hero{grid-template-columns:1fr 1fr}.release__head{grid-template-columns:100px 1fr}.release__meta{grid-column:1/-1;justify-content:flex-start}.release__changes{grid-template-columns:1fr}}
    @media(max-width:680px){.versions-hero{grid-template-columns:1fr}.release__head{grid-template-columns:1fr}.release__meta{grid-column:auto}.versions-toolbar>*{width:100%;min-width:0!important}.versions-count{margin-left:0}.release__changes{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page__head">
      <div>
        <h1 class="page__title">Control de versiones</h1>
        <div class="page__sub">Historial de cambios, mejoras y publicaciones de la plataforma</div>
      </div>
      <div class="page__live"><span class="dot"></span>Versión <?= h($currentVersion) ?></div>
    </div>

    <section class="versions-hero">
      <div class="version-card version-card--main">
        <div class="version-card__label">Versión actualmente publicada</div>
        <div class="version-card__value">v<?= h($currentVersion) ?></div>
        <div class="version-card__note"><?= h($history[0]['title'] ?? '') ?></div>
      </div>
      <div class="version-card">
        <div class="version-card__label">Publicaciones registradas</div>
        <div class="version-card__value"><?= count($history) ?></div>
        <div class="version-card__note">Desde la primera versión operativa.</div>
      </div>
      <div class="version-card">
        <div class="version-card__label">Cambios documentados</div>
        <div class="version-card__value"><?= $totalChanges ?></div>
        <div class="version-card__note">Mejoras funcionales, visuales y de seguridad.</div>
      </div>
      <div class="version-card">
        <div class="version-card__label">Última actualización</div>
        <div class="version-card__value" style="font-size:21px"><?= h(date('d/m/Y', strtotime($latestDate))) ?></div>
        <div class="version-card__note"><?= h(date('H:i', strtotime($latestDate))) ?> hs · Patagonia Control Systems</div>
      </div>
    </section>

    <form class="versions-toolbar" method="get">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Buscar versión, módulo o cambio..." aria-label="Buscar en versiones">
      <select name="type" aria-label="Filtrar por tipo">
        <option value="">Todos los tipos</option>
        <?php foreach (array_keys($allTypes) as $option): ?>
          <option value="<?= h($option) ?>" <?= $type === $option ? 'selected' : '' ?>><?= h($option) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="module" aria-label="Filtrar por módulo">
        <option value="">Todos los módulos</option>
        <?php foreach (array_keys($allModules) as $option): ?>
          <option value="<?= h($option) ?>" <?= $module === $option ? 'selected' : '' ?>><?= h($option) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn-export" type="submit"><?= icon('search') ?> Filtrar</button>
      <a class="btn-export" href="?<?= h(http_build_query(['q'=>$q,'type'=>$type,'module'=>$module,'export'=>'csv'])) ?>"><?= icon('download') ?> Exportar CSV</a>
      <span class="versions-count"><?= count($filtered) ?> versión(es)</span>
    </form>

    <?php if (empty($filtered)): ?>
      <div class="empty-state">
        <div style="font-family:var(--font-head);font-size:22px;color:var(--petrol);margin-bottom:8px">No se encontraron versiones</div>
        Modificá los filtros o limpiá la búsqueda.
      </div>
    <?php else: ?>
      <section class="version-list" id="versionList">
        <?php foreach ($filtered as $index => $release): $isCurrent = $release['version'] === $currentVersion; ?>
          <article class="release <?= $isCurrent ? 'is-current open' : '' ?>">
            <div class="release__head" role="button" tabindex="0" aria-expanded="<?= $isCurrent ? 'true' : 'false' ?>">
              <div>
                <div class="release__version">v<?= h($release['version']) ?></div>
                <div class="release__date"><?= h(date('d/m/Y H:i', strtotime($release['date']))) ?></div>
              </div>
              <div>
                <div class="release__title"><?= h($release['title']) ?></div>
                <div class="release__summary"><?= h($release['summary']) ?></div>
              </div>
              <div class="release__meta">
                <?php if ($isCurrent): ?><span class="release__badge current">Actual</span><?php endif; ?>
                <span class="release__badge"><?= h($release['type']) ?></span>
                <span class="release__badge"><?= h($release['status']) ?></span>
                <span class="release__toggle"><?= icon('chevron') ?></span>
              </div>
            </div>
            <div class="release__body">
              <div class="release__modules">
                <?php foreach ($release['modules'] as $m): ?><span class="release__module"><?= h($m) ?></span><?php endforeach; ?>
              </div>
              <ul class="release__changes">
                <?php foreach ($release['changes'] as $change): ?><li><?= h($change) ?></li><?php endforeach; ?>
              </ul>
              <div class="release__footer">
                <span>Responsable: <b><?= h($release['author']) ?></b></span>
                <span><?= count($release['changes']) ?> cambio(s) documentado(s)</span>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </main>
</div>
<script src="assets/js/app.js"></script>
<script>
(function(){
  function toggleRelease(release){
    var open=release.classList.toggle('open');
    var head=release.querySelector('.release__head');
    if(head)head.setAttribute('aria-expanded',open?'true':'false');
  }
  document.querySelectorAll('.release__head').forEach(function(head){
    head.addEventListener('click',function(){toggleRelease(head.closest('.release'));});
    head.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();toggleRelease(head.closest('.release'));}});
  });
})();
</script>
</body>
</html>
