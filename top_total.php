<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/zones.php';

auth_require();
permissions_require_menu('top_total');

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'America/Argentina/Buenos_Aires');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = 'top_total';

$db = clear_db();
$dbError = $db->ok() ? '' : $db->error();
$table = 'dbo.FIXALARMS';
$dateColumn = 'ALM_NATIVETIMEIN';
$tagColumn = 'ALM_TAGNAME';
$priorityColumn = 'ALM_ALMPRIORITY';

function tt_valid_date($value) {
    if ($value === '') return '';
    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    return $dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $dt->format('Y-m-d') === $value ? $value : '';
}
function tt_num($value) { return number_format((int)$value, 0, ',', '.'); }
function tt_pct($value) { return number_format((float)$value, 1, ',', '.') . '%'; }
function tt_date_label($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    $ts = strtotime($value);
    return $ts ? date('d/m', $ts) : $value;
}

$today = new DateTime('today');
$defaultFrom = (clone $today)->modify('-6 days')->format('Y-m-d');
$defaultTo = $today->format('Y-m-d');
$dateFrom = tt_valid_date(trim((string)($_GET['fecha_desde'] ?? $defaultFrom))) ?: $defaultFrom;
$dateTo = tt_valid_date(trim((string)($_GET['fecha_hasta'] ?? $defaultTo))) ?: $defaultTo;
$zone = clear_zone_value($_GET['zona'] ?? '');
$installation = trim((string)($_GET['instalacion'] ?? ''));
$tagFilter = trim((string)($_GET['tag'] ?? ''));
$tagFilterLength = function_exists('mb_strlen') ? mb_strlen($tagFilter, 'UTF-8') : strlen($tagFilter);
if ($tagFilterLength > 120) {
    $tagFilter = function_exists('mb_substr') ? mb_substr($tagFilter, 0, 120, 'UTF-8') : substr($tagFilter, 0, 120);
}
if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

$columns = [];
$zones = [];
$installations = [];
$total = 0;
$high = 0;
$medium = 0;
$low = 0;
$other = 0;
$uniqueTags = 0;
$topInstallation = '';
$topInstallationCount = 0;
$dailyLabels = [];
$dailyHigh = [];
$dailyMedium = [];
$dailyLow = [];
$dailyOther = [];
$dailyTotals = [];
$topTags = [];
$hourly = array_fill(0, 24, 0);

$tagText = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$tagColumn])))";
$installationExpr = "CASE WHEN CHARINDEX('_', $tagText) > 0 THEN LEFT($tagText, CHARINDEX('_', $tagText) - 1) ELSE $tagText END";
$priorityText = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100), [$priorityColumn]))))";
$priorityClass = "CASE
    WHEN $priorityText LIKE '%CRIT%' OR $priorityText IN ('HIGH','HI','ALTA') THEN 'Alta'
    WHEN $priorityText IN ('MEDIUM','MED','MEDIA') THEN 'Media'
    WHEN $priorityText IN ('LOW','LO','BAJA','INFO') THEN 'Baja'
    ELSE 'Otra'
END";

if ($db->ok()) {
    $meta = $db->all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS'");
    foreach ($meta as $row) {
        $name = trim((string)($row['COLUMN_NAME'] ?? $row['column_name'] ?? reset($row)));
        if ($name !== '') $columns[strtoupper($name)] = $name;
    }
    if (isset($columns['ALM_NATIVETIMEIN'])) $dateColumn = $columns['ALM_NATIVETIMEIN'];
    if (isset($columns['ALM_TAGNAME'])) $tagColumn = $columns['ALM_TAGNAME'];
    if (isset($columns['ALM_ALMPRIORITY'])) $priorityColumn = $columns['ALM_ALMPRIORITY'];

    $tagText = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$tagColumn])))";
    $installationExpr = "CASE WHEN CHARINDEX('_', $tagText) > 0 THEN LEFT($tagText, CHARINDEX('_', $tagText) - 1) ELSE $tagText END";
    $priorityText = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100), [$priorityColumn]))))";
    $priorityClass = "CASE
        WHEN $priorityText LIKE '%CRIT%' OR $priorityText IN ('HIGH','HI','ALTA') THEN 'Alta'
        WHEN $priorityText IN ('MEDIUM','MED','MEDIA') THEN 'Media'
        WHEN $priorityText IN ('LOW','LO','BAJA','INFO') THEN 'Baja'
        ELSE 'Otra'
    END";

    $zones = clear_zone_catalog($db);
    if ($zone !== '' && !in_array($zone, $zones, true)) $zone = '';
    $installationWhere = ["[$tagColumn] IS NOT NULL", "$tagText <> ''"];
    $installationParams = [];
    if ($zone !== '') {
        $installationWhere[] = clear_zone_exists_condition($installationExpr);
        $installationParams[] = $zone;
    }
    $installationRows = $db->all("SELECT DISTINCT $installationExpr AS instalacion FROM $table WHERE " . implode(' AND ', $installationWhere) . " ORDER BY instalacion", $installationParams);
    foreach ($installationRows as $row) {
        $value = trim((string)($row['instalacion'] ?? $row['INSTALACION'] ?? reset($row)));
        if ($value !== '') $installations[] = $value;
    }

    $conditions = ["[$dateColumn] >= ?", "[$dateColumn] < DATEADD(day, 1, ?)"];
    $params = [$dateFrom . ' 00:00:00', $dateTo . ' 00:00:00'];
    if ($zone !== '') {
        $conditions[] = clear_zone_exists_condition($installationExpr);
        $params[] = $zone;
    }
    if ($installation !== '') {
        $conditions[] = "$installationExpr = ?";
        $params[] = $installation;
    }
    if ($tagFilter !== '') {
        $conditions[] = "$tagText LIKE ?";
        $params[] = '%' . $tagFilter . '%';
    }
    $where = 'WHERE ' . implode(' AND ', $conditions);

    $total = (int)$db->scalar("SELECT COUNT(*) FROM $table $where", $params);
    $uniqueTags = (int)$db->scalar("SELECT COUNT(DISTINCT $tagText) FROM $table $where AND [$tagColumn] IS NOT NULL AND $tagText <> ''", $params);

    $priorityRows = $db->all("SELECT $priorityClass AS clase, COUNT(*) AS cantidad FROM $table $where GROUP BY $priorityClass", $params);
    foreach ($priorityRows as $row) {
        $class = trim((string)($row['clase'] ?? $row['CLASE'] ?? 'Otra'));
        $count = (int)($row['cantidad'] ?? $row['CANTIDAD'] ?? 0);
        if ($class === 'Alta') $high = $count;
        elseif ($class === 'Media') $medium = $count;
        elseif ($class === 'Baja') $low = $count;
        else $other += $count;
    }

    $topInstallationRows = $db->all("SELECT TOP 1 $installationExpr AS instalacion, COUNT(*) AS cantidad FROM $table $where AND [$tagColumn] IS NOT NULL AND $tagText <> '' GROUP BY $installationExpr ORDER BY COUNT(*) DESC", $params);
    if ($topInstallationRows) {
        $r = $topInstallationRows[0];
        $topInstallation = trim((string)($r['instalacion'] ?? $r['INSTALACION'] ?? ''));
        $topInstallationCount = (int)($r['cantidad'] ?? $r['CANTIDAD'] ?? 0);
    }

    $dailyRows = $db->all("SELECT CONVERT(date, [$dateColumn]) AS fecha, $priorityClass AS clase, COUNT(*) AS cantidad FROM $table $where GROUP BY CONVERT(date, [$dateColumn]), $priorityClass ORDER BY fecha", $params);
    $dayMap = [];
    $cursor = new DateTime($dateFrom);
    $endCursor = new DateTime($dateTo);
    while ($cursor <= $endCursor) {
        $key = $cursor->format('Y-m-d');
        $dayMap[$key] = ['Alta'=>0,'Media'=>0,'Baja'=>0,'Otra'=>0];
        $cursor->modify('+1 day');
    }
    foreach ($dailyRows as $row) {
        $rawDate = (string)($row['fecha'] ?? $row['FECHA'] ?? '');
        $dateKey = date('Y-m-d', strtotime($rawDate));
        $class = trim((string)($row['clase'] ?? $row['CLASE'] ?? 'Otra'));
        $count = (int)($row['cantidad'] ?? $row['CANTIDAD'] ?? 0);
        if (isset($dayMap[$dateKey])) $dayMap[$dateKey][$class] = $count;
    }
    foreach ($dayMap as $date => $counts) {
        $dailyLabels[] = tt_date_label($date);
        $dailyHigh[] = $counts['Alta'];
        $dailyMedium[] = $counts['Media'];
        $dailyLow[] = $counts['Baja'];
        $dailyOther[] = $counts['Otra'];
        $dailyTotals[] = array_sum($counts);
    }

    $topTagRows = $db->all("SELECT TOP 10 $tagText AS tag, COUNT(*) AS cantidad FROM $table $where AND [$tagColumn] IS NOT NULL AND $tagText <> '' GROUP BY $tagText ORDER BY COUNT(*) DESC", $params);
    foreach ($topTagRows as $row) {
        $topTags[] = [
            'tag' => trim((string)($row['tag'] ?? $row['TAG'] ?? '')),
            'count' => (int)($row['cantidad'] ?? $row['CANTIDAD'] ?? 0),
        ];
    }

    $hourRows = $db->all("SELECT DATEPART(hour, [$dateColumn]) AS hora, COUNT(*) AS cantidad FROM $table $where GROUP BY DATEPART(hour, [$dateColumn]) ORDER BY hora", $params);
    foreach ($hourRows as $row) {
        $h = (int)($row['hora'] ?? $row['HORA'] ?? 0);
        if ($h >= 0 && $h <= 23) $hourly[$h] = (int)($row['cantidad'] ?? $row['CANTIDAD'] ?? 0);
    }
}

$highPct = $total > 0 ? ($high * 100 / $total) : 0;
$mediumPct = $total > 0 ? ($medium * 100 / $total) : 0;
$lowPct = $total > 0 ? ($low * 100 / $total) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Top Total · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260715-1">
  <script src="assets/js/chart.umd.js"></script>
  <style>
    .topTotalFilters{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}
    .topTotalFilter{display:flex;align-items:center;gap:8px;min-height:42px;padding:0 12px;background:#fff;border:1px solid var(--line-mid);border-radius:10px}
    .topTotalFilter svg{width:15px;height:15px;color:var(--petrol)}
    .topTotalFilter label{display:flex;align-items:center;gap:7px}
    .topTotalFilter span{font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--text-mut)}
    .topTotalFilter select,.topTotalFilter input{border:0;outline:0;background:transparent;color:var(--text);font:inherit;min-width:130px}
    .topTotalFilter--tag{flex:1 1 310px}.topTotalFilter--tag label{width:100%}.topTotalFilter--tag input{width:100%;min-width:190px}
    .topTotalKpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}
    .topTotalCharts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
    .topTotalChart{background:#fff;border:1px solid var(--line-mid);border-top:3px solid var(--petrol);border-radius:14px;padding:16px;min-width:0}
    .topTotalChart__head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
    .topTotalChart__head small{display:block;color:var(--text-mut);font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;margin-bottom:4px}
    .topTotalChart__head h3{font-family:var(--font-head);font-size:20px;color:var(--text);margin:0}
    .topTotalChart__hint{font-size:11px;color:var(--text-mut);border:1px solid var(--line-mid);border-radius:999px;padding:5px 9px;white-space:nowrap}
    .topTotalChart__canvas{height:300px;position:relative}
    .topTotalMeta{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 14px;color:var(--text-soft);font-size:12px}
    .topTotalMeta b{color:var(--petrol)}
    @media(max-width:1100px){.topTotalKpis{grid-template-columns:repeat(2,1fr)}.topTotalCharts{grid-template-columns:1fr}}
    @media(max-width:700px){.topTotalKpis{grid-template-columns:1fr}.topTotalFilter{width:100%}.topTotalFilter label{width:100%}.topTotalFilter select,.topTotalFilter input{flex:1;min-width:0}}
  </style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page__head">
      <div>
        <h1 class="page__title">Top Total</h1>
        <div class="page__sub">Estadísticas de dbo.FIXALARMS · período controlado para evitar sobrecarga</div>
      </div>
      <div class="page__live"><span class="dot"></span><?php echo $dbError ? 'Sin conexión' : 'En vivo'; ?></div>
    </div>

    <?php if ($dbError): ?>
      <div class="tablewrap"><div class="empty"><p><b>Sin conexión:</b> <?php echo h($dbError); ?></p></div></div>
    <?php else: ?>
      <form class="topTotalFilters" method="get" action="top_total.php">
        <div class="topTotalFilter">
          <?php echo icon('map'); ?>
          <label><span>Zona</span>
            <select name="zona" onchange="this.form.submit()">
              <option value="">Todas</option>
              <?php foreach ($zones as $item): ?><option value="<?php echo h($item); ?>" <?php echo $zone === $item ? 'selected' : ''; ?>><?php echo h($item); ?></option><?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="topTotalFilter">
          <?php echo icon('oil'); ?>
          <label><span>Instalación</span>
            <select name="instalacion">
              <option value="">Todas</option>
              <?php foreach ($installations as $item): ?><option value="<?php echo h($item); ?>" <?php echo $installation === $item ? 'selected' : ''; ?>><?php echo h($item); ?></option><?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="topTotalFilter topTotalFilter--tag">
          <?php echo icon('search'); ?>
          <label><span>ALM_TAGNAME</span><input type="search" name="tag" value="<?php echo h($tagFilter); ?>" placeholder="Buscar aproximado por tag…" autocomplete="off"></label>
        </div>
        <div class="topTotalFilter">
          <?php echo icon('calendar'); ?>
          <label><span>Desde</span><input type="date" name="fecha_desde" value="<?php echo h($dateFrom); ?>"></label>
          <span>—</span>
          <label><span>Hasta</span><input type="date" name="fecha_hasta" value="<?php echo h($dateTo); ?>"></label>
        </div>
        <button type="submit" class="toolbar__dateApply">Aplicar</button>
        <a href="top_total.php" class="btn-clear-filters">↺ Últimos 7 días</a>
      </form>

      <div class="topTotalMeta">
        <span>Período: <b><?php echo h(date('d/m/Y', strtotime($dateFrom))); ?> — <?php echo h(date('d/m/Y', strtotime($dateTo))); ?></b></span>
        <span>Zona: <b><?php echo h($zone !== '' ? $zone : 'Todas'); ?></b></span>
        <span>Instalación: <b><?php echo h($installation !== '' ? $installation : 'Todas'); ?></b></span>
        <span>Filtro TAG: <b><?php echo h($tagFilter !== '' ? $tagFilter : 'Todos'); ?></b></span>
        <span>Tags únicos: <b><?php echo tt_num($uniqueTags); ?></b></span>
        <span>Instalación principal: <b><?php echo h($topInstallation !== '' ? $topInstallation : 'Sin datos'); ?></b><?php if ($topInstallationCount): ?> (<?php echo tt_num($topInstallationCount); ?>)<?php endif; ?></span>
      </div>

      <section class="topTotalKpis" aria-label="Resumen de criticidad total">
        <article class="stat acc-blue">
          <div class="stat__label">Total del período</div>
          <div class="stat__value is-blue"><?php echo tt_num($total); ?></div>
          <div class="stat__detail">Registros obtenidos directamente desde <b>dbo.FIXALARMS</b>.</div>
        </article>
        <article class="stat acc-red">
          <div class="stat__label">Prioridad alta</div>
          <div class="stat__value is-red"><?php echo tt_num($high); ?></div>
          <div class="stat__detail"><b><?php echo tt_pct($highPct); ?></b> del total seleccionado.</div>
        </article>
        <article class="stat acc-amber">
          <div class="stat__label">Prioridad media</div>
          <div class="stat__value is-amber"><?php echo tt_num($medium); ?></div>
          <div class="stat__detail"><b><?php echo tt_pct($mediumPct); ?></b> del total seleccionado.</div>
        </article>
        <article class="stat acc-green">
          <div class="stat__label">Prioridad baja</div>
          <div class="stat__value is-green"><?php echo tt_num($low); ?></div>
          <div class="stat__detail"><b><?php echo tt_pct($lowPct); ?></b> del total seleccionado.<?php if ($other): ?> Otros: <b><?php echo tt_num($other); ?></b>.<?php endif; ?></div>
        </article>
      </section>

      <section class="topTotalCharts">
        <article class="topTotalChart">
          <div class="topTotalChart__head"><div><small>Volumen diario</small><h3>Total de alarmas por día</h3></div><span class="topTotalChart__hint">Tendencia del período</span></div>
          <div class="topTotalChart__canvas"><canvas id="ttDailyTotal"></canvas></div>
        </article>
        <article class="topTotalChart">
          <div class="topTotalChart__head"><div><small>Criticidad diaria</small><h3>Alta, media y baja</h3></div><span class="topTotalChart__hint">Comparación apilada</span></div>
          <div class="topTotalChart__canvas"><canvas id="ttDailyPriority"></canvas></div>
        </article>
        <article class="topTotalChart">
          <div class="topTotalChart__head"><div><small>Frecuencia</small><h3>Top 10 TAG</h3></div><span class="topTotalChart__hint">Mayor recurrencia</span></div>
          <div class="topTotalChart__canvas"><canvas id="ttTopTags"></canvas></div>
        </article>
        <article class="topTotalChart">
          <div class="topTotalChart__head"><div><small>Patrón horario</small><h3>Alarmas por hora del día</h3></div><span class="topTotalChart__hint">Picos operativos</span></div>
          <div class="topTotalChart__canvas"><canvas id="ttHourly"></canvas></div>
        </article>
      </section>
    <?php endif; ?>
  </main>
</div>
<script>
(function(){
  if(typeof Chart==='undefined') return;
  var labels=<?php echo json_encode($dailyLabels, JSON_UNESCAPED_UNICODE); ?>;
  var common={responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{usePointStyle:true,boxWidth:8}}},scales:{x:{grid:{color:'rgba(26,77,92,.06)'},ticks:{color:'#607487'}},y:{beginAtZero:true,grid:{color:'rgba(26,77,92,.08)'},ticks:{color:'#607487'}}}};
  new Chart(document.getElementById('ttDailyTotal'),{type:'line',data:{labels:labels,datasets:[{label:'Total diario',data:<?php echo json_encode($dailyTotals); ?>,borderColor:'#1a4d5c',backgroundColor:'rgba(26,77,92,.12)',borderWidth:3,pointRadius:4,tension:.3,fill:true}]},options:common});
  new Chart(document.getElementById('ttDailyPriority'),{type:'bar',data:{labels:labels,datasets:[
    {label:'Alta',data:<?php echo json_encode($dailyHigh); ?>,backgroundColor:'rgba(239,68,68,.78)',borderRadius:5},
    {label:'Media',data:<?php echo json_encode($dailyMedium); ?>,backgroundColor:'rgba(245,158,11,.78)',borderRadius:5},
    {label:'Baja',data:<?php echo json_encode($dailyLow); ?>,backgroundColor:'rgba(16,185,129,.72)',borderRadius:5},
    {label:'Otra',data:<?php echo json_encode($dailyOther); ?>,backgroundColor:'rgba(100,116,139,.55)',borderRadius:5}
  ]},options:Object.assign({},common,{scales:{x:{stacked:true,grid:{display:false}},y:{stacked:true,beginAtZero:true,grid:{color:'rgba(26,77,92,.08)'}}}})});
  var topTags=<?php echo json_encode($topTags, JSON_UNESCAPED_UNICODE); ?>;
  new Chart(document.getElementById('ttTopTags'),{type:'bar',data:{labels:topTags.map(function(x){return x.tag;}),datasets:[{label:'Alarmas',data:topTags.map(function(x){return x.count;}),backgroundColor:'rgba(26,77,92,.82)',borderRadius:5}]},options:Object.assign({},common,{indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,grid:{color:'rgba(26,77,92,.08)'}},y:{grid:{display:false},ticks:{color:'#334e5b',callback:function(value){var s=this.getLabelForValue(value);return s.length>28?s.slice(0,28)+'…':s;}}}}})});
  var hours=Array.from({length:24},function(_,i){return String(i).padStart(2,'0')+':00';});
  new Chart(document.getElementById('ttHourly'),{type:'bar',data:{labels:hours,datasets:[{label:'Alarmas',data:<?php echo json_encode(array_values($hourly)); ?>,backgroundColor:'rgba(14,116,144,.68)',borderRadius:4}]},options:Object.assign({},common,{plugins:{legend:{display:false}},scales:{x:{grid:{display:false},ticks:{maxRotation:0,autoSkip:true,maxTicksLimit:12}},y:{beginAtZero:true,grid:{color:'rgba(26,77,92,.08)'}}}})});
})();
</script>
</body>
</html>
