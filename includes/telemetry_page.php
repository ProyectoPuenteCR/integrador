<?php
if (!isset($telemetry) || !is_array($telemetry)) {
    http_response_code(500);
    exit('Configuración de telemetría no disponible.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/icons.php';

auth_require();
permissions_require_menu($telemetry['key']);

$cfg = require dirname(__DIR__) . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'America/Argentina/Buenos_Aires');
$APP_USER = auth_user() ?: 'CLEAR';
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = $telemetry['key'];

$db = clear_db();
$rows = [];
$error = '';
$queryCols = $telemetry['columns'];
$commColumn = $telemetry['comm_column'] ?? '';
$wellColumn = $telemetry['well_column'] ?? 'POZO';

function tel_q($name)
{
    return '[' . str_replace(']', ']]', $name) . ']';
}

function tel_text($value)
{
    if ($value === null) {
        return '';
    }
    if ($value instanceof DateTimeInterface) {
        return $value->format('d/m/Y H:i:s');
    }
    return trim((string)$value);
}

function tel_num($value)
{
    if ($value === null || trim((string)$value) === '') {
        return '';
    }
    $number = str_replace(',', '.', trim((string)$value));
    return is_numeric($number) ? number_format((float)$number, 2, ',', '.') : (string)$value;
}

function tel_is_num($column)
{
    return (bool)preg_match('/^(PT:|TT:|VT-|N-|PID:|PAL:|PI-|SI-|QT:|FT:|WT:|ZT:|FP$)/i', $column);
}

function tel_link($value)
{
    return preg_match('~^https?://~i', trim((string)$value)) === 1;
}

function tel_comm($value)
{
    $text = strtoupper(tel_text($value));
    if ($text === '') {
        return 'Sin dato';
    }
    if (preg_match('/BAD|FALL|NO DATA|DESC|SIN COM|ERROR|OFF|DEFECT|^0$/', $text)) {
        return 'Sin comunicación';
    }
    if (preg_match('/INTER|DEMOR|LENTO|DELAY/', $text)) {
        return 'Intermitente';
    }
    return 'Comunicando';
}

function tel_status_class($value)
{
    $text = strtoupper(tel_text($value));
    if ($text === '') {
        return 'neutral';
    }
    if (preg_match('/FALL|ALAR|PARO|OFF|INACT|ERROR|EMER|SIN COM|NO DATA|BAD/', $text)) {
        return 'bad';
    }
    if (preg_match('/INTER|MANUAL|HOA|AVISO|WARN|DEMOR|ESPERA/', $text)) {
        return 'warn';
    }
    if (preg_match('/MARCH|NORMAL|OPER|OK|RUN|HABIL|COMUNIC|AUTO|ACTIV/', $text)) {
        return 'good';
    }
    return 'neutral';
}

$select = implode(',', array_map('tel_q', $queryCols));
if ($db->ok()) {
    $rows = $db->all('SELECT TOP (1000) ' . $select . ' FROM dbo.' . tel_q($telemetry['view']));
    if (!$rows && $db->error()) {
        $error = $db->error();
    }
} else {
    $error = $db->error();
}

$filterColumns = $telemetry['filter_columns'] ?? [];
if ($commColumn !== '') {
    $filterColumns = array_values(array_filter($filterColumns, static function ($column) use ($commColumn) {
        return $column !== $commColumn;
    }));
}

$counts = [];
foreach ($filterColumns as $column) {
    $counts[$column] = [];
}

$uniqueWells = [];
$failWells = [];
$warnWells = [];
foreach ($rows as &$row) {
    $well = tel_text($row[$wellColumn] ?? '');
    if ($well !== '') {
        $uniqueWells[$well] = true;
    }

    if ($commColumn !== '') {
        $communication = tel_comm($row[$commColumn] ?? '');
        $row['COMUNICACION'] = $communication;
        if ($well !== '' && $communication === 'Sin comunicación') {
            $failWells[$well] = true;
        }
        if ($well !== '' && $communication === 'Intermitente') {
            $warnWells[$well] = true;
        }
    }

    foreach ($counts as $column => $_unused) {
        $value = tel_text($row[$column] ?? '');
        if ($value !== '') {
            $counts[$column][$value] = ($counts[$column][$value] ?? 0) + 1;
        }
    }
}
unset($row);

if ($commColumn !== '') {
    $counts = ['COMUNICACION' => []] + $counts;
    foreach ($rows as $row) {
        $value = tel_text($row['COMUNICACION'] ?? 'Sin dato');
        $counts['COMUNICACION'][$value] = ($counts['COMUNICACION'][$value] ?? 0) + 1;
    }
}

foreach ($counts as &$values) {
    arsort($values, SORT_NATURAL);
}
unset($values);

/* Orden visual común para todas las telemetrías. */
$displayCols = [];
foreach ([$wellColumn, 'BATERIA'] as $column) {
    if (in_array($column, $queryCols, true) && !in_array($column, $displayCols, true)) {
        $displayCols[] = $column;
    }
}
if ($commColumn !== '') {
    $displayCols[] = 'COMUNICACION';
}
foreach ($queryCols as $column) {
    if ($column === $commColumn || in_array($column, $displayCols, true)) {
        continue;
    }
    $displayCols[] = $column;
}

$failCount = count($failWells);
$warnCount = count($warnWells);
$heroImage = 'assets/img/telemetry/' . ($telemetry['hero_image'] ?? ($telemetry['key'] . '.png'));
$heroImageFs = dirname(__DIR__) . '/' . $heroImage;
if (!is_file($heroImageFs)) { $heroImage = ''; }
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CLEAR · <?php echo h($telemetry['title']); ?></title>
    <link rel="stylesheet" href="assets/css/app.css?v=20260803-grid1">
    <link rel="stylesheet" href="assets/css/telemetry_modal.css?v=3.1.9">
    <style>
        .tel{padding:0 20px 24px}
        .tel-hero{display:flex;align-items:stretch;gap:8px;flex-wrap:wrap;margin:8px 0 10px}
        .tel-hero__title{flex:0 0 390px;max-width:450px;min-width:310px;border:1px solid var(--line-mid);background:linear-gradient(180deg,var(--surface),var(--surface-2,#f8fafc));border-radius:16px;padding:10px 14px;box-shadow:0 8px 22px rgba(15,23,42,.05);display:flex;justify-content:space-between;gap:12px;align-items:center}
        .tel-hero__eyebrow{font-size:10px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:var(--petrol);margin-bottom:4px}
        .tel-hero__title h1{margin:0;font:800 18px/1.05 var(--font-head);letter-spacing:-.02em}
        .tel-hero__title p{margin:3px 0 0;font-size:10px;line-height:1.2;color:var(--text-mut);text-transform:uppercase;letter-spacing:.12em}
        .tel-live{margin-top:8px;display:inline-flex;align-items:center;gap:8px;align-self:flex-start;padding:5px 10px;border:1px solid #cfe5d9;border-radius:999px;background:#f6fbf8;color:#0b7d58;font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.tel-hero__copy{flex:1;min-width:0}.tel-hero__visual{flex:0 0 104px;display:flex;align-items:center;justify-content:center}.tel-hero__visual img{display:block;max-width:96px;max-height:88px;width:auto;height:auto;object-fit:contain;mix-blend-mode:multiply;filter:drop-shadow(0 2px 4px rgba(15,23,42,.10))}
        .tel-live .dot{width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block}
        .tel-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(128px,1fr));gap:6px;flex:1;min-width:min(100%,560px);margin:0}
        .tel-kpi{border:1px solid var(--line-mid);background:linear-gradient(180deg,var(--surface),var(--surface-2,#f8fafc));padding:8px 10px;border-radius:12px;display:flex;align-items:center;gap:8px;min-height:42px;box-shadow:0 6px 18px rgba(15,23,42,.05);text-align:left;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
        .tel-kpi__icon{width:24px;height:24px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:#e9f3f7;color:var(--petrol);flex:0 0 auto}
        .tel-kpi__icon svg{width:12px;height:12px}.tel-kpi__body{display:flex;flex-direction:column;min-width:0}
        .tel-kpi b{font:800 24px/1 var(--font-head);letter-spacing:-.02em}
        .tel-kpi span{font-size:10px;color:var(--text-mut);margin-top:2px;line-height:1.15}
        .tel-kpi.total{background:linear-gradient(135deg,var(--petrol),#123f50);color:#fff;border-color:transparent}
        .tel-kpi.total span{color:#d8e9f0}.tel-kpi.total .tel-kpi__icon{background:rgba(255,255,255,.14);color:#fff}
        .tel-kpi.records{border-color:#d9e1ea}.tel-kpi.records .tel-kpi__icon{background:#eff5fa;color:#3f6b88}
        .tel-kpi.comm-fail{border-color:#f3c3bf}.tel-kpi.comm-fail .tel-kpi__icon{background:#fde8e7;color:#b8322b}
        .tel-kpi.comm-warn{border-color:#f7d8a4}.tel-kpi.comm-warn .tel-kpi__icon{background:#fff3de;color:#9e670e}
        button.tel-kpi{cursor:pointer;width:100%}
        button.tel-kpi:hover{transform:translateY(-1px);box-shadow:0 12px 24px rgba(15,23,42,.10)}
        button.tel-kpi.is-active{outline:2px solid rgba(184,50,43,.18);border-color:#e3a5a0}
        .tel-kpi small{display:inline-flex;align-self:flex-start;margin-top:3px;padding:1px 6px;border-radius:999px;background:rgba(184,50,43,.10);color:#9d3029;font-size:10px;font-weight:700}
        .tel-kpi.comm-warn small{background:rgba(166,107,11,.11);color:#94620d}
        .tel-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:8px 0}
        .tel details{border:1px solid var(--line-mid);border-radius:12px;background:var(--surface);overflow:hidden;box-shadow:0 4px 14px rgba(15,23,42,.04)}
        .tel summary{padding:10px 12px;font-weight:700;cursor:pointer}
        .tel-fbody{padding:0 13px 13px;display:flex;gap:7px;flex-wrap:wrap;max-height:230px;overflow:auto}
        .tel-chip{border:1px solid var(--line);border-left:4px solid var(--petrol);border-radius:9px;padding:7px 9px;background:var(--surface-2);font-size:12px;display:flex;gap:6px;align-items:center}
        .tel-chip input{accent-color:var(--petrol)}
        .tel-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0}
        .tel-search,.tel-btn,.tel-select,.tel-filter{border:1px solid var(--line-mid);border-radius:10px;background:var(--surface);color:var(--text);padding:8px 10px}.tel-page-size{display:inline-flex;align-items:center;gap:6px;padding:5px 8px;border:1px solid var(--line-mid);border-radius:10px;background:var(--surface);font-size:10px;font-weight:800;color:var(--text-mut);text-transform:uppercase;letter-spacing:.05em}.tel-page-size select{border:0;background:transparent;color:var(--text);font-weight:700;outline:0;cursor:pointer}
        .tel-search{flex:1;min-width:250px}.tel-btn{font-weight:650;cursor:pointer}.tel-count{margin-left:auto;color:var(--text-mut);font-size:12px}
        .tel-wrap{overflow:auto;max-height:65vh;border:1px solid var(--line-mid);border-radius:12px;background:var(--surface)}
        table.tel-table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%;font-size:12px}
        table.tel-table th{position:sticky;top:0;background:var(--petrol);color:#fff;padding:7px 8px;text-align:left;z-index:2;white-space:nowrap}
        table.tel-table th .tel-filter{display:block;margin-top:5px;width:130px;padding:5px 6px;font-size:11px}
        table.tel-table td{padding:6px 8px;border-bottom:1px solid var(--line);white-space:nowrap;max-width:330px;overflow:hidden;text-overflow:ellipsis;transition:background-color .12s ease}.tel-page-hidden{display:none!important}
        table.tel-table tr:hover td{background:var(--petrol-soft)}
        table.tel-table tr.tel-row-fail td{background:rgba(253,232,231,.55)}
        table.tel-table tr.tel-row-warn td{background:rgba(255,243,222,.62)}
        table.tel-table tr.tel-row-fail:hover td{background:rgba(252,218,216,.82)}
        table.tel-table tr.tel-row-warn:hover td{background:rgba(255,236,201,.85)}
        table.tel-table tr.tel-row-fail td:first-child{box-shadow:inset 4px 0 0 #c53a31}
        table.tel-table tr.tel-row-warn td:first-child{box-shadow:inset 4px 0 0 #c98513}
        td.num{text-align:right;font-variant-numeric:tabular-nums}.tel-badge{padding:4px 9px;border-radius:999px;font-weight:800;font-size:10px;display:inline-flex;align-items:center;box-shadow:inset 0 0 0 1px rgba(15,23,42,.05)}.tel-badge.good{background:#e7f7ed;color:#15803d}.tel-badge.warn{background:#fff4d6;color:#a45b00}.tel-badge.bad{background:#fde8e7;color:#c52d26}.tel-badge.neutral{background:#eef2f6;color:#64748b}.tel-icon svg{width:18px;height:18px}.tel-columns{position:relative}.tel-menu{display:none;position:absolute;right:0;top:40px;background:var(--surface);border:1px solid var(--line);box-shadow:var(--shadow);padding:10px;z-index:20;min-width:230px;max-height:380px;overflow:auto}.tel-menu.open{display:block}.tel-menu label{display:block;padding:5px}.tel-error{padding:12px;background:#fde8e7;color:#b42318;border-radius:10px}
        @media(max-width:1180px){.tel-hero__title{flex:1 1 100%;max-width:none}.tel-kpis{width:100%}}
        @media(max-width:1000px){.tel-grid{grid-template-columns:1fr 1fr}}
        @media(max-width:700px){.tel-grid{grid-template-columns:1fr}.tel{padding:0 10px}.tel-kpis{grid-template-columns:1fr 1fr}.tel-hero__title{min-width:0}.tel-hero__title h1{font-size:16px}.tel-hero__visual{flex-basis:72px}.tel-hero__visual img{max-width:68px;max-height:64px}}
        @media(max-width:480px){.tel-kpis{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/topbar.php'; ?>

        <section class="tel">
            <?php if ($error): ?>
                <div class="tel-error"><?php echo h($error); ?></div>
            <?php endif; ?>

            <div class="tel-hero">
                <div class="tel-hero__title">
                    <div class="tel-hero__copy">
                        <div class="tel-hero__eyebrow">Telemetría</div>
                        <h1><?php echo h($telemetry['title']); ?></h1>
                        <p><?php echo h($telemetry['subtitle']); ?></p>
                        <div class="tel-live"><span class="dot"></span><?php echo count($rows); ?> registros</div>
                    </div>
                    <?php if ($heroImage !== ''): ?>
                        <div class="tel-hero__visual"><img src="<?php echo h($heroImage); ?>?v=3.1.9" alt="<?php echo h($telemetry['title']); ?>"></div>
                    <?php endif; ?>
                </div>

                <div class="tel-kpis">
                <div class="tel-kpi total">
                    <span class="tel-kpi__icon"><?php echo icon('monitor'); ?></span>
                    <div class="tel-kpi__body"><b><?php echo count($uniqueWells); ?></b><span>Pozos</span></div>
                </div>
                <div class="tel-kpi records">
                    <span class="tel-kpi__icon"><?php echo icon('grid'); ?></span>
                    <div class="tel-kpi__body"><b><?php echo count($rows); ?></b><span>Registros</span></div>
                </div>
                <?php if ($failCount > 0): ?>
                    <button type="button" class="tel-kpi comm-fail" data-quick-filter="comm-fail">
                        <span class="tel-kpi__icon"><?php echo icon('shield'); ?></span>
                        <div class="tel-kpi__body"><b><?php echo $failCount; ?></b><span>Pozos con falla de comunicación</span><small>Click para filtrar</small></div>
                    </button>
                <?php endif; ?>
                <?php if ($warnCount > 0): ?>
                    <button type="button" class="tel-kpi comm-warn" data-quick-filter="comm-warn">
                        <span class="tel-kpi__icon"><?php echo icon('history'); ?></span>
                        <div class="tel-kpi__body"><b><?php echo $warnCount; ?></b><span>Comunicación intermitente</span><small>Click para filtrar</small></div>
                    </button>
                <?php endif; ?>
            </div>
            </div>

            <div class="tel-grid">
                <?php foreach ($counts as $column => $values): ?>
                    <details data-group="<?php echo h($column); ?>">
                        <summary><?php echo h($column === 'COMUNICACION' ? 'Comunicación' : $column); ?> · selección múltiple</summary>
                        <div class="tel-fbody">
                            <?php foreach ($values as $value => $count): ?>
                                <label class="tel-chip">
                                    <input type="checkbox" class="multi" data-col="<?php echo h($column); ?>" value="<?php echo h($value); ?>">
                                    <b><?php echo (int)$count; ?></b> <?php echo h($value); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>

            <div class="tel-toolbar">
                <input id="q" class="tel-search" type="search" placeholder="Buscar en toda la grilla…">
                <button class="tel-btn" id="clear">Limpiar filtros</button>
                <button class="tel-btn" id="export">Exportar Excel</button>
                <button class="tel-btn" id="refresh">Actualizar</button>
                <label class="tel-page-size" for="telPageSize"><span>Filas</span><select id="telPageSize" aria-label="Cantidad de filas visibles"><option value="50" selected>50</option><option value="100">100</option><option value="all">Todas</option></select></label>
                <select class="tel-select" id="interval">
                    <option value="60" selected>Cada 1 minuto</option>
                    <option value="300">Cada 5 minutos</option>
                    <option value="600">Cada 10 minutos</option>
                    <option value="1800">Cada 30 minutos</option>
                </select>
                <div class="tel-columns">
                    <button class="tel-btn" id="colbtn">Columnas ▾</button>
                    <div class="tel-menu" id="colmenu"></div>
                </div>
                <span class="tel-count">Mostrando <b id="shown"><?php echo min(50,count($rows)); ?></b> de <b id="visible"><?php echo count($rows); ?></b> visibles</span>
            </div>

            <div class="tel-wrap">
                <table class="tel-table" id="table">
                    <thead>
                    <tr>
                        <?php foreach ($displayCols as $column): ?>
                            <th draggable="true" data-col="<?php echo h($column); ?>">
                                <?php echo h($column === 'COMUNICACION' ? 'COM' : $column); ?>
                                <input class="tel-filter" data-col="<?php echo h($column); ?>" placeholder="Filtrar">
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $communication = tel_text($row['COMUNICACION'] ?? '');
                        $rowClass = $communication === 'Sin comunicación' ? 'tel-row-comm-fail' : ($communication === 'Intermitente' ? 'tel-row-comm-warn' : '');
                        $wellName = tel_text($row[$wellColumn] ?? '');
                        ?>
                        <tr class="<?php echo h($rowClass); ?>" data-comm-status="<?php echo h($communication); ?>">
                            <?php foreach ($displayCols as $column):
                                $value = $row[$column] ?? null;
                                $raw = tel_text($value);
                                ?>
                                <td data-col="<?php echo h($column); ?>" data-raw="<?php echo h($raw); ?>" class="<?php echo tel_is_num($column) ? 'num' : ''; ?>">
                                    <?php if ($raw === ''): ?>
                                    <?php elseif (($column === 'PANTALLA' || $column === 'CARTAS') && tel_link($raw)): ?>
                                        <a class="tel-icon" href="<?php echo h($raw); ?>" target="_blank" rel="noopener"
                                           data-telemetry-popup-url="<?php echo h($raw); ?>"
                                           data-telemetry-popup-name="<?php echo h($column === 'PANTALLA' ? 'CLEAR_PI_VISION' : 'CLEAR_PI_CARTAS'); ?>"
                                           title="<?php echo h($column === 'PANTALLA' ? 'Abrir pantalla en ventana emergente' : 'Abrir carta en ventana emergente'); ?>"><?php echo icon($column === 'PANTALLA' ? 'monitor' : 'chart'); ?></a>
                                    <?php elseif ($column === 'COMUNICACION' || preg_match('/ESTADO|YT:SISTEMA|YT:POZO/i', $column)): ?>
                                        <span class="tel-badge <?php echo h(tel_status_class($raw)); ?>"><?php echo h($raw); ?></span>
                                    <?php elseif (tel_is_num($column)): ?>
                                        <?php echo h(tel_num($value)); ?>
                                    <?php else: ?>
                                        <?php echo h($raw); ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
<?php include __DIR__ . '/telemetry_modal.php'; ?>
<script src="assets/js/app.js?v=3.1.7"></script>
<script>window.CLEAR_TELEMETRY_PAGE=<?php echo json_encode(['key'=>$telemetry['key'],'title'=>$telemetry['title']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="assets/js/telemetry_page.js?v=3.1.10"></script>
<script src="assets/js/telemetry_modal.js?v=3.1.9"></script>
</body>
</html>
