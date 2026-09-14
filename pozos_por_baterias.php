<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';
require_once __DIR__.'/includes/production_q164.php';

auth_require();
permissions_require_menu('pozos_por_baterias');

$cfg = require __DIR__.'/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'America/Argentina/Buenos_Aires');
$APP_USER = auth_user() ?: 'CLEAR';
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = 'pozos_por_baterias';
$db = clear_db();

function pbb_text($value)
{
    if ($value === null) return '';
    if ($value instanceof DateTimeInterface) return $value->format('d/m/Y H:i:s');
    return trim((string)$value);
}

function pbb_num($value)
{
    return is_numeric($value) ? (float)$value : 0.0;
}

function pbb_fmt($value)
{
    return number_format((float)$value, 2, ',', '.');
}

function pbb_clean_error($value)
{
    $value = str_ireplace(['<br>', '<br/>', '<br />'], ' ', (string)$value);
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $value));
}

function pbb_is_communicating($value)
{
    $raw = strtoupper(trim((string)$value));
    if ($raw === '' || $raw === '0') return false;
    if (preg_match('/FALL|ERROR|BAD|SIN COM|NO DATA|OFF|DESC|DEMOR|INTERMIT|DEFECT|TIMEOUT/', $raw)) return false;
    return true;
}

function pbb_fallback_rows($db, &$lastUpdate)
{
    if (!$db || !$db->ok()) return [];

    $telemetryRows = $db->all("SELECT POZO, BATERIA, TIPO, COMUNICACION, FECHA_CACHE FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE WHERE POZO IS NOT NULL AND LTRIM(RTRIM(POZO))<>'' AND BATERIA IS NOT NULL AND LTRIM(RTRIM(BATERIA))<>''");
    if (!$telemetryRows) return [];

    $productionMap = clear_q164_latest_map($db);
    $batteries = [];

    foreach ($telemetryRows as $row) {
        $well = pbb_text($row['POZO'] ?? '');
        $battery = pbb_text($row['BATERIA'] ?? '');
        $type = strtoupper(pbb_text($row['TIPO'] ?? ''));
        if ($well === '' || $battery === '') continue;

        $batteryKey = strtoupper($battery);
        $wellKey = clear_q164_well_key($well);
        if (!isset($batteries[$batteryKey])) {
            $batteries[$batteryKey] = ['BATERIA'=>$battery, 'wells'=>[]];
        }
        if (!isset($batteries[$batteryKey]['wells'][$wellKey])) {
            $batteries[$batteryKey]['wells'][$wellKey] = [
                'PCP'=>false, 'BES'=>false, 'TECCS'=>false, 'comm'=>false
            ];
        }
        if (isset($batteries[$batteryKey]['wells'][$wellKey][$type])) {
            $batteries[$batteryKey]['wells'][$wellKey][$type] = true;
        }
        if (pbb_is_communicating($row['COMUNICACION'] ?? '')) {
            $batteries[$batteryKey]['wells'][$wellKey]['comm'] = true;
        }

        $cacheDate = pbb_text($row['FECHA_CACHE'] ?? '');
        if ($cacheDate !== '') {
            $ts = strtotime($cacheDate);
            if ($ts && (!$lastUpdate || $ts > $lastUpdate)) $lastUpdate = $ts;
        }
    }

    $out = [];
    foreach ($batteries as $batteryData) {
        $pcp = $bes = $tecss = $comm = $noComm = 0;
        $oil = 0.0;
        foreach ($batteryData['wells'] as $wellKey=>$wellData) {
            if ($wellData['PCP']) $pcp++;
            if ($wellData['BES']) $bes++;
            if ($wellData['TECCS']) $tecss++;
            if ($wellData['comm']) $comm++; else $noComm++;
            if (isset($productionMap[$wellKey]['PRODUCCION_PETROLEO']) && $productionMap[$wellKey]['PRODUCCION_PETROLEO'] !== null) {
                $oil += (float)$productionMap[$wellKey]['PRODUCCION_PETROLEO'];
            }
        }
        $out[] = [
            'BATERIA'=>$batteryData['BATERIA'],
            'POZOS_MONITOREO'=>count($batteryData['wells']),
            'PCP'=>$pcp,
            'BES'=>$bes,
            'TECSS'=>$tecss,
            'POZOS_COMUNICANDO'=>$comm,
            'SIN_COMUNICAR'=>$noComm,
            'PRODUCCION_PETROLEO'=>$oil,
            'FECHA_CACHE'=>$lastUpdate ? date('Y-m-d H:i:s', $lastUpdate) : null,
        ];
    }
    usort($out, function($a,$b){ return strnatcasecmp($a['BATERIA'], $b['BATERIA']); });
    return $out;
}

$rows = [];
$error = '';
$source = 'cache';
$lastUpdate = null;
$telemetryLinks = [
    'PCP'   => 'telemetria_pcp.php',
    'BES'   => 'telemetria_bes.php',
    'TECSS' => 'telemetria_tecss.php',
];

if ($db->ok()) {
    $cacheExists = (int)$db->scalar("SELECT COUNT(*) FROM sys.objects WHERE object_id=OBJECT_ID(N'dbo.POZOS_POR_BATERIA_CACHE') AND type=N'U'") > 0;
    if ($cacheExists) {
        $rows = $db->all("SELECT BATERIA, POZOS_MONITOREO, PCP, BES, TECSS, POZOS_COMUNICANDO, SIN_COMUNICAR, CONVERT(varchar(64),PRODUCCION_PETROLEO) AS PRODUCCION_PETROLEO, FECHA_CACHE FROM dbo.POZOS_POR_BATERIA_CACHE ORDER BY BATERIA");
        foreach ($rows as $r) {
            $d = pbb_text($r['FECHA_CACHE'] ?? '');
            $ts = $d !== '' ? strtotime($d) : 0;
            if ($ts && (!$lastUpdate || $ts > $lastUpdate)) $lastUpdate = $ts;
        }
    }

    if (!$rows) {
        $source = 'fallback';
        $fallbackLast = null;
        $rows = pbb_fallback_rows($db, $fallbackLast);
        if ($fallbackLast) $lastUpdate = $fallbackLast;
    }

    if (!$rows && $db->error()) $error = pbb_clean_error($db->error());
} else {
    $error = pbb_clean_error($db->error());
}

$totals = [
    'BATERIAS'=>count($rows),
    'POZOS_MONITOREO'=>0,
    'PCP'=>0,
    'BES'=>0,
    'TECSS'=>0,
    'POZOS_COMUNICANDO'=>0,
    'SIN_COMUNICAR'=>0,
    'PRODUCCION_PETROLEO'=>0.0,
];
foreach ($rows as &$row) {
    foreach (['POZOS_MONITOREO','PCP','BES','TECSS','POZOS_COMUNICANDO','SIN_COMUNICAR'] as $field) {
        $row[$field] = (int)($row[$field] ?? 0);
        $totals[$field] += $row[$field];
    }
    $row['PRODUCCION_PETROLEO'] = pbb_num($row['PRODUCCION_PETROLEO'] ?? 0);
    $totals['PRODUCCION_PETROLEO'] += $row['PRODUCCION_PETROLEO'];
}
unset($row);

$commPct = $totals['POZOS_MONITOREO'] > 0 ? round($totals['POZOS_COMUNICANDO'] * 100 / $totals['POZOS_MONITOREO'], 1) : 0;
$noCommPct = $totals['POZOS_MONITOREO'] > 0 ? round($totals['SIN_COMUNICAR'] * 100 / $totals['POZOS_MONITOREO'], 1) : 0;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CLEAR · Pozos por Baterías</title>
<link rel="stylesheet" href="assets/css/app.css?v=20260804-pbb1">
<style>
.pbb{padding:0 22px 28px}
.pbb-kpis{display:grid;grid-template-columns:repeat(8,minmax(145px,1fr));gap:10px;margin-bottom:14px}
.pbb-card,.pbb-panel{background:var(--surface);border:1px solid var(--line-mid);border-radius:16px;box-shadow:0 6px 20px rgba(15,45,58,.05)}
.pbb-card{padding:14px 14px 12px;min-height:102px;display:flex;gap:12px;align-items:center;position:relative;overflow:hidden}
.pbb-card:before{content:"";position:absolute;inset:0 auto 0 0;width:3px;background:var(--accent,var(--petrol));opacity:.95}
.pbb-card__icon{width:44px;height:44px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:var(--tone,#eef5fb);color:var(--accent,var(--petrol));flex:0 0 auto}
.pbb-card__icon svg{width:22px;height:22px}
.pbb-card span{display:block;font-size:10px;font-weight:800;color:var(--text-mut);text-transform:uppercase;letter-spacing:.06em}
.pbb-card b{display:block;font:800 22px/1.05 var(--font-head);margin:5px 0 3px;color:var(--text)}
.pbb-card small{display:block;color:var(--text-mut);font-size:11px;line-height:1.2}
.pbb-card .pct-good{color:#15925b;font-weight:800}.pbb-card .pct-bad{color:#d73b34;font-weight:800}
.pbb-card.blue{--accent:#2d7ff0;--tone:#e8f1ff}.pbb-card.purple{--accent:#8b5cf6;--tone:#f1eafe}.pbb-card.orange{--accent:#f59e0b;--tone:#fff3e0}.pbb-card.cyan{--accent:#0ea5b7;--tone:#e6f8fa}.pbb-card.green{--accent:#19a463;--tone:#e6f6ec}.pbb-card.red{--accent:#ef4444;--tone:#fde9e7}.pbb-card.prod{--accent:#1f86dc;--tone:#e6f1fb}
.pbb-value-inline{display:flex;align-items:center;gap:8px}.pbb-value-inline .mini{width:26px;height:26px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;background:#fff;border:1px solid rgba(31,134,220,.16);color:#1f86dc}.pbb-value-inline .mini svg{width:15px;height:15px}
.pbb-panel{overflow:hidden}
.pbb-toolbar{padding:10px 12px;display:flex;gap:9px;align-items:center;flex-wrap:wrap;border-bottom:1px solid var(--line)}
.pbb-search{flex:1;min-width:220px;max-width:430px;padding:10px 12px;border:1px solid var(--line-mid);border-radius:11px;background:var(--surface);color:var(--text)}
.pbb-select,.pbb-btn{padding:10px 12px;border:1px solid var(--line-mid);border-radius:11px;background:var(--surface);color:var(--text)}
.pbb-btn{cursor:pointer}.pbb-btn:hover{background:var(--petrol-soft)}.pbb-source{margin-left:auto;color:var(--text-mut);font-size:11px}
.pbb-wrap{overflow:auto;max-height:68vh}
.pbb-table{width:100%;border-collapse:separate;border-spacing:0 0;font-size:12px;min-width:1180px}
.pbb-table th{position:sticky;top:0;z-index:2;background:var(--surface-2,#f7f9fb);padding:10px 10px;border-bottom:1px solid var(--line-mid);white-space:nowrap;text-align:left;cursor:pointer;font-size:10px;text-transform:uppercase;letter-spacing:.03em}
.pbb-table td{padding:7px 10px;border-bottom:1px solid #edf2f7;white-space:nowrap;vertical-align:middle}
.pbb-table tbody tr:hover td{background:#fafcff}
.pbb-table td.num,.pbb-table th.num{text-align:right}
.pbb-table tfoot td{position:sticky;bottom:0;background:#eef6ff;font-weight:800;border-top:2px solid #c9dff0;border-bottom:0}
.pbb-colhead{display:inline-flex;align-items:center;gap:4px}.pbb-colhead--link{color:#1d4ed8;text-decoration:none;font-weight:900}.pbb-colhead--link:hover{text-decoration:underline}.pbb-colhint{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#eef2ff;color:#3553b4;font-size:10px;font-weight:900}
.pbb-battery{display:inline-flex;align-items:center;gap:9px;font-weight:900;color:#0d4b79}.pbb-battery__icon{width:22px;height:22px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:#e8f1ff;color:#2d7ff0}.pbb-battery__icon svg{width:14px;height:14px}
.pbb-chip{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-width:54px;padding:4px 10px;border-radius:999px;font-weight:800;line-height:1;border:1px solid transparent}
.pbb-chip svg{width:13px;height:13px}
.pbb-chip.blue{background:#edf5ff;border-color:#d8e8ff;color:#2064c8}.pbb-chip.purple{background:#f4efff;border-color:#e4d8ff;color:#7a49d4}.pbb-chip.orange{background:#fff3e4;border-color:#ffe0b5;color:#c47000}.pbb-chip.cyan{background:#eaf9fb;border-color:#caeef2;color:#0c7f8e}.pbb-chip.green{background:#e7f7ed;border-color:#d0efd9;color:#14834d}.pbb-chip.red{background:#fdeaea;border-color:#f6cfcf;color:#cf312a}.pbb-chip.prod{background:#eff7ff;border-color:#d7e9ff;color:#1f5d97}
.pbb-duo{display:inline-flex;align-items:center;justify-content:flex-end;gap:8px;min-width:118px}.pbb-duo .pct{font-size:11px;font-weight:800;color:#54738b}.pbb-duo.red .pct{color:#cd2d2d}.pbb-duo.green .pct{color:#14834d}
.pbb-error{margin:0 0 12px;padding:12px 14px;border:1px solid #efb4af;border-radius:10px;background:#fde9e7;color:#9f302a}
.pbb-foot{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;color:var(--text-mut);font-size:11px;border-top:1px solid var(--line)}.pbb-empty{text-align:center!important;padding:28px!important;color:var(--text-mut)}
@media(max-width:1450px){.pbb-kpis{grid-template-columns:repeat(4,1fr)}}@media(max-width:980px){.pbb-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:760px){.pbb{padding:0 10px 20px}.pbb-source{width:100%;margin-left:0}}@media(max-width:520px){.pbb-kpis{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="app">
<?php include __DIR__.'/includes/sidebar.php'; ?>
<main class="main">
<?php include __DIR__.'/includes/topbar.php'; ?>
<div class="page__head">
  <div><h1 class="page__title">Pozos por Baterías</h1><div class="page__sub">Resumen de pozos monitoreados y producción por batería</div></div>
  <div class="page__live"><span class="dot"></span><?php echo $lastUpdate ? 'Actualizado '.date('d/m/Y H:i',$lastUpdate) : 'Sin actualización'; ?></div>
</div>
<div class="pbb">
<?php if($error): ?><div class="pbb-error"><?php echo h($error); ?></div><?php endif; ?>
<div class="pbb-kpis">
  <div class="pbb-card blue"><div class="pbb-card__icon"><?php echo icon('grid'); ?></div><div><span>Baterías</span><b><?php echo (int)$totals['BATERIAS']; ?></b><small>Baterías con pozos monitoreados</small></div></div>
  <div class="pbb-card blue"><div class="pbb-card__icon"><?php echo icon('monitor'); ?></div><div><span>Pozos en Monitoreo</span><b><?php echo (int)$totals['POZOS_MONITOREO']; ?></b><small>Total activos</small></div></div>
  <div class="pbb-card purple"><div class="pbb-card__icon"><?php echo icon('wave'); ?></div><div><span>PCP</span><b><?php echo (int)$totals['PCP']; ?></b><small>Total equipos</small></div></div>
  <div class="pbb-card orange"><div class="pbb-card__icon"><?php echo icon('gauge'); ?></div><div><span>BES</span><b><?php echo (int)$totals['BES']; ?></b><small>Total equipos</small></div></div>
  <div class="pbb-card cyan"><div class="pbb-card__icon"><?php echo icon('chart'); ?></div><div><span>TECSS</span><b><?php echo (int)$totals['TECSS']; ?></b><small>Total equipos</small></div></div>
  <div class="pbb-card green"><div class="pbb-card__icon"><?php echo icon('message'); ?></div><div><span>Pozos Comunicando</span><b><?php echo (int)$totals['POZOS_COMUNICANDO']; ?></b><small><span class="pct-good"><?php echo number_format($commPct,1,',','.'); ?>% del total</span></small></div></div>
  <div class="pbb-card red"><div class="pbb-card__icon"><?php echo icon('shield'); ?></div><div><span>Sin Comunicar</span><b><?php echo (int)$totals['SIN_COMUNICAR']; ?></b><small><span class="pct-bad"><?php echo number_format($noCommPct,1,',','.'); ?>% del total</span></small></div></div>
  <div class="pbb-card prod"><div class="pbb-card__icon"><?php echo icon('oil'); ?></div><div><span>Producción Petróleo</span><div class="pbb-value-inline"><span class="mini"><?php echo icon('oil'); ?></span><b><?php echo pbb_fmt($totals['PRODUCCION_PETROLEO']); ?></b></div><small>m³ · último test aprobado</small></div></div>
</div>

<div class="pbb-panel">
  <div class="pbb-toolbar">
    <select id="pbbState" class="pbb-select" aria-label="Estado de comunicación"><option value="all">Estado: Todos</option><option value="good">100% comunicando</option><option value="bad">Con pozos sin comunicar</option></select>
    <input id="pbbSearch" class="pbb-search" placeholder="Buscar batería...">
    <button type="button" class="pbb-btn" id="pbbClear">Limpiar filtros</button>
    <button type="button" class="pbb-btn" id="pbbExport">Exportar Excel</button>
    <button type="button" class="pbb-btn" id="pbbRefresh">Actualizar</button>
    <select id="pbbPageSize" class="pbb-select" aria-label="Filas"><option value="25" selected>Filas 25</option><option value="50">Filas 50</option><option value="all">Todas</option></select>
    <span class="pbb-source"><?php echo $source==='cache'?'Caché optimizada':'Modo directo sobre caché general'; ?></span>
  </div>
  <div class="pbb-wrap">
    <table class="pbb-table" id="pbbTable">
      <thead><tr>
        <th data-key="battery"><span class="pbb-colhead">Batería ↕</span></th>
        <th class="num" data-key="wells"><span class="pbb-colhead">Pozos Monitoreo ↕</span></th>
        <th class="num" data-key="pcp"><span class="pbb-colhead"><a class="pbb-colhead--link" href="<?php echo h($telemetryLinks['PCP']); ?>" title="Ir a Telemetría PCP">PCP</a><span class="pbb-colhint">i</span>↕</span></th>
        <th class="num" data-key="bes"><span class="pbb-colhead"><a class="pbb-colhead--link" href="<?php echo h($telemetryLinks['BES']); ?>" title="Ir a Telemetría BES">BES</a><span class="pbb-colhint">i</span>↕</span></th>
        <th class="num" data-key="tecss"><span class="pbb-colhead"><a class="pbb-colhead--link" href="<?php echo h($telemetryLinks['TECSS']); ?>" title="Ir a Telemetría TECSS">TECSS</a><span class="pbb-colhint">i</span>↕</span></th>
        <th class="num" data-key="comm"><span class="pbb-colhead">Pozos Comunicando ↕</span></th>
        <th class="num" data-key="nocomm"><span class="pbb-colhead">Sin Comunicar ↕</span></th>
        <th class="num" data-key="oil"><span class="pbb-colhead">Producción Petróleo ↕</span></th>
      </tr></thead>
      <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="8" class="pbb-empty">No hay datos de baterías disponibles.</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr data-battery="<?php echo h(strtolower(pbb_text($r['BATERIA']))); ?>" data-state="<?php echo (int)$r['SIN_COMUNICAR']>0?'bad':'good'; ?>"
            data-battery-sort="<?php echo h(pbb_text($r['BATERIA'])); ?>" data-wells="<?php echo (int)$r['POZOS_MONITOREO']; ?>" data-pcp="<?php echo (int)$r['PCP']; ?>" data-bes="<?php echo (int)$r['BES']; ?>" data-tecss="<?php echo (int)$r['TECSS']; ?>" data-comm="<?php echo (int)$r['POZOS_COMUNICANDO']; ?>" data-nocomm="<?php echo (int)$r['SIN_COMUNICAR']; ?>" data-oil="<?php echo (float)$r['PRODUCCION_PETROLEO']; ?>">
          <?php $rowTotal=max(1,(int)$r['POZOS_MONITOREO']); $rowCommPct=round(((int)$r['POZOS_COMUNICANDO']*100)/$rowTotal,1); $rowNoCommPct=round(((int)$r['SIN_COMUNICAR']*100)/$rowTotal,1); ?>
          <td><span class="pbb-battery"><span class="pbb-battery__icon"><?php echo icon('grid'); ?></span><?php echo h(pbb_text($r['BATERIA'])); ?></span></td>
          <td class="num"><span class="pbb-chip blue"><?php echo (int)$r['POZOS_MONITOREO']; ?></span></td>
          <td class="num"><span class="pbb-chip purple"><?php echo (int)$r['PCP']; ?></span></td>
          <td class="num"><span class="pbb-chip orange"><?php echo (int)$r['BES']; ?></span></td>
          <td class="num"><span class="pbb-chip cyan"><?php echo (int)$r['TECSS']; ?></span></td>
          <td class="num"><span class="pbb-duo green"><span class="pbb-chip green"><?php echo (int)$r['POZOS_COMUNICANDO']; ?></span><span class="pct"><?php echo number_format($rowCommPct,1,',','.'); ?>%</span></span></td>
          <td class="num"><span class="pbb-duo red"><span class="pbb-chip red"><?php echo (int)$r['SIN_COMUNICAR']; ?></span><span class="pct"><?php echo number_format($rowNoCommPct,1,',','.'); ?>%</span></span></td>
          <td class="num" data-export="<?php echo number_format((float)$r['PRODUCCION_PETROLEO'],2,',',''); ?>"><span class="pbb-chip prod"><?php echo pbb_fmt($r['PRODUCCION_PETROLEO']); ?> <?php echo icon('oil'); ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr><td>TOTAL</td><td class="num" id="tfWells"><span class="pbb-chip blue"><?php echo (int)$totals['POZOS_MONITOREO']; ?></span></td><td class="num" id="tfPcp"><span class="pbb-chip purple"><?php echo (int)$totals['PCP']; ?></span></td><td class="num" id="tfBes"><span class="pbb-chip orange"><?php echo (int)$totals['BES']; ?></span></td><td class="num" id="tfTecss"><span class="pbb-chip cyan"><?php echo (int)$totals['TECSS']; ?></span></td><td class="num" id="tfComm"><span class="pbb-chip green"><?php echo (int)$totals['POZOS_COMUNICANDO']; ?></span></td><td class="num" id="tfNoComm"><span class="pbb-chip red"><?php echo (int)$totals['SIN_COMUNICAR']; ?></span></td><td class="num" id="tfOil"><span class="pbb-chip prod"><?php echo pbb_fmt($totals['PRODUCCION_PETROLEO']); ?> <?php echo icon('oil'); ?></span></td></tr></tfoot>
    </table>
  </div>
  <div class="pbb-foot"><span>Mostrando <b id="pbbShown">0</b> de <b id="pbbVisible">0</b> baterías</span><span>Producción de petróleo: último test aprobado Query 164 por pozo</span></div>
</div>
</div>
</main></div>
<script>
(function(){
  var table=document.getElementById('pbbTable');
  var body=table.querySelector('tbody');
  var dataRows=Array.prototype.slice.call(body.querySelectorAll('tr[data-battery]'));
  var search=document.getElementById('pbbSearch');
  var state=document.getElementById('pbbState');
  var pageSize=document.getElementById('pbbPageSize');
  var sortKey='battery', sortDir=1;

  function fmt(n){return Number(n||0).toLocaleString('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2});}
  function visibleFiltered(){
    var q=(search.value||'').trim().toLowerCase();
    var s=state.value;
    return dataRows.filter(function(row){
      return (!q || row.dataset.battery.indexOf(q)!==-1) && (s==='all' || row.dataset.state===s);
    });
  }
  function compare(a,b){
    var av,bv;
    if(sortKey==='battery'){av=a.dataset.batterySort.toLowerCase();bv=b.dataset.batterySort.toLowerCase();return av.localeCompare(bv,'es',{numeric:true})*sortDir;}
    av=parseFloat(a.dataset[sortKey]||0);bv=parseFloat(b.dataset[sortKey]||0);return (av-bv)*sortDir;
  }
  function apply(){
    var filtered=visibleFiltered().sort(compare);
    dataRows.forEach(function(row){row.style.display='none';});
    filtered.forEach(function(row){body.appendChild(row);});
    var lim=pageSize.value==='all'?filtered.length:parseInt(pageSize.value,10);
    filtered.forEach(function(row,i){row.style.display=i<lim?'':'none';});
    document.getElementById('pbbShown').textContent=Math.min(lim,filtered.length);
    document.getElementById('pbbVisible').textContent=filtered.length;
    var totals={wells:0,pcp:0,bes:0,tecss:0,comm:0,nocomm:0,oil:0};
    filtered.forEach(function(row){Object.keys(totals).forEach(function(k){totals[k]+=parseFloat(row.dataset[k]||0);});});
    document.getElementById('tfWells').innerHTML='<span class="pbb-chip blue">'+totals.wells+'</span>';
    document.getElementById('tfPcp').innerHTML='<span class="pbb-chip purple">'+totals.pcp+'</span>';
    document.getElementById('tfBes').innerHTML='<span class="pbb-chip orange">'+totals.bes+'</span>';
    document.getElementById('tfTecss').innerHTML='<span class="pbb-chip cyan">'+totals.tecss+'</span>';
    document.getElementById('tfComm').innerHTML='<span class="pbb-chip green">'+totals.comm+'</span>';
    document.getElementById('tfNoComm').innerHTML='<span class="pbb-chip red">'+totals.nocomm+'</span>';
    document.getElementById('tfOil').innerHTML='<span class="pbb-chip prod">'+fmt(totals.oil)+' '+<?php echo json_encode(icon('oil')); ?>+'</span>';
  }
  search.addEventListener('input',apply);state.addEventListener('change',apply);pageSize.addEventListener('change',apply);
  document.getElementById('pbbClear').addEventListener('click',function(){search.value='';state.value='all';apply();});
  document.getElementById('pbbRefresh').addEventListener('click',function(){window.location.reload();});
  table.querySelectorAll('thead th[data-key]').forEach(function(th){th.addEventListener('click',function(){var key=th.dataset.key;if(sortKey===key)sortDir*=-1;else{sortKey=key;sortDir=1;}apply();});});

  table.querySelectorAll('.pbb-colhead--link').forEach(function(a){a.addEventListener('click',function(ev){ev.stopPropagation();});});
  document.getElementById('pbbExport').addEventListener('click',function(){
    var filtered=visibleFiltered().sort(compare);
    var lines=['BATERIA;POZOS MONITOREO;PCP;BES;TECSS;POZOS COMUNICANDO;SIN COMUNICAR;PRODUCCION PETROLEO'];
    filtered.forEach(function(row){lines.push([row.dataset.batterySort,row.dataset.wells,row.dataset.pcp,row.dataset.bes,row.dataset.tecss,row.dataset.comm,row.dataset.nocomm,Number(row.dataset.oil||0).toFixed(2).replace('.',',')].map(function(v){return '"'+String(v).replace(/"/g,'""')+'"';}).join(';'));});
    var blob=new Blob(['\ufeff'+lines.join('\r\n')],{type:'text/csv;charset=utf-8;'});var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='pozos_por_baterias_'+new Date().toISOString().slice(0,10)+'.csv';document.body.appendChild(a);a.click();setTimeout(function(){URL.revokeObjectURL(a.href);a.remove();},100);
  });
  apply();
})();
</script>
<script src="assets/js/app.js"></script>
</body></html>
