<?php
/* =============================================================
   CLEAR PLATAFORMA — Inyección de Agua
   La pantalla consulta exclusivamente la tabla caché:
   dbo.CLEAR_INYECCION_AGUA_CACHE
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/alarm_actions.php';

auth_require();
permissions_require_menu('inyeccion_agua');

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = 'inyeccion_agua';
$db = clear_db();

function ia_q($value)
{
    return '[' . str_replace(']', ']]', (string)$value) . ']';
}

function ia_text($value)
{
    if ($value === null) return '';
    if ($value instanceof DateTimeInterface) return $value->format('d/m/Y H:i:s');
    return trim((string)$value);
}

function ia_is_link($value)
{
    return preg_match('~^https?://~i', trim((string)$value)) === 1;
}

function ia_number_value($value)
{
    if ($value === null || $value === '') return 0.0;
    if (is_numeric($value)) return (float)$value;

    $text = preg_replace('/\s+/', '', trim((string)$value));
    if ($text === '') return 0.0;
    if (strpos($text, ',') !== false && strpos($text, '.') !== false) {
        if (strrpos($text, ',') > strrpos($text, '.')) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        } else {
            $text = str_replace(',', '', $text);
        }
    } elseif (strpos($text, ',') !== false) {
        $text = str_replace(',', '.', $text);
    }
    return is_numeric($text) ? (float)$text : 0.0;
}

function ia_format_number($value)
{
    $number = ia_number_value($value);
    if (abs($number) < 0.005) $number = 0.0;
    return number_format($number, 2, ',', '.');
}

function ia_alarm_class($count)
{
    $count = (int)$count;
    if ($count <= 0) return 'success';
    if ($count <= 5) return 'warning';
    if ($count <= 15) return 'info';
    return 'danger';
}

function ia_alarm_urls($well)
{
    $well = trim((string)$well);
    $to = new DateTimeImmutable('now');
    $from = $to->sub(new DateInterval('PT24H'));
    $params = [
        'pozo' => $well,
        'desde' => $from->format('Y-m-d'),
        'hora_desde' => $from->format('H:i'),
        'hasta' => $to->format('Y-m-d'),
        'hora_hasta' => $to->format('H:i')
    ];
    $url = 'pozos_alarmas24.php?' . http_build_query($params);
    return [$url, $url . '&embed=1'];
}

function ia_screen_filter_label($value)
{
    $value = trim((string)$value);
    if ($value === '') return 'Sin pantalla';

    if (preg_match('/(?:PBDisplayName|displayname)=([^&#]+)/i', $value, $match)) {
        $decoded = urldecode($match[1]);
        if ($decoded !== '') return $decoded;
    }

    $fragment = parse_url($value, PHP_URL_FRAGMENT);
    if (is_string($fragment) && $fragment !== '') {
        $fragment = trim(urldecode($fragment), '/');
        if ($fragment !== '') return substr($fragment, 0, 80);
    }

    $path = parse_url($value, PHP_URL_PATH);
    if (is_string($path) && $path !== '') {
        $base = basename($path);
        if ($base !== '' && $base !== '/') return substr(urldecode($base), 0, 80);
    }

    return strlen($value) > 80 ? substr($value, 0, 77) . '…' : $value;
}

$table = 'CLEAR_INYECCION_AGUA_CACHE';
$columns = [
    'PLANTA',
    'SATELITE',
    'ZONA',
    'POZO',
    'PANTALLA',
    'Presión Inyeccion',
    'Caudal Inst',
    'Acumulado Hoy',
    'Proyectado',
    'Cierre',
    'FEHA',
    'Promedio dia',
    'Promedio Ayer'
];
$numericColumns = [
    'Presión Inyeccion',
    'Caudal Inst',
    'Acumulado Hoy',
    'Proyectado',
    'Cierre',
    'Promedio dia',
    'Promedio Ayer'
];
$sumColumns = ['Caudal Inst', 'Acumulado Hoy', 'Cierre'];
$filterColumns = [
    'PLANTA' => 'Planta',
    'SATELITE' => 'Satélite',
    'ZONA' => 'Zona',
    'PANTALLA' => 'Pantalla'
];

$rows = [];
$error = '';
$queryMs = 0;
$lastRefresh = '';
$availableColumns = [];

if (!$db->ok()) {
    $error = $db->error();
} else {
    $exists = (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_INYECCION_AGUA_CACHE', N'U') IS NULL THEN 0 ELSE 1 END");
    if (!$exists) {
        $error = 'La tabla caché dbo.CLEAR_INYECCION_AGUA_CACHE no existe. Ejecutá SQL/CLEAR_INYECCION_AGUA_CACHE_JOB.sql.';
    } else {
        $meta = $db->all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=? ORDER BY ORDINAL_POSITION", [$table]);
        foreach ($meta as $item) {
            $name = trim((string)($item['COLUMN_NAME'] ?? ''));
            if ($name !== '') $availableColumns[$name] = true;
        }

        $missing = array_values(array_filter($columns, static function ($column) use ($availableColumns) {
            return !isset($availableColumns[$column]);
        }));

        if ($missing) {
            $error = 'Faltan columnas en la tabla caché: ' . implode(', ', $missing) . '. Volvé a ejecutar el script SQL de instalación.';
        } else {
            $select = implode(',', array_map('ia_q', $columns));
            $sql = 'SELECT ' . $select . ' FROM [dbo].' . ia_q($table) . ' ORDER BY [PLANTA], [SATELITE], [ZONA], [POZO]';
            $started = microtime(true);
            $rows = $db->all($sql);
            $queryMs = round((microtime(true) - $started) * 1000, 1);
            if (!$rows && $db->error()) $error = $db->error();

            $lastRefresh = ia_text($db->scalar("SELECT CONVERT(varchar(19), MAX([ACTUALIZADO_EN]), 120) FROM dbo.CLEAR_INYECCION_AGUA_CACHE"));
        }
    }
}

/* Alarmas de pozos de las últimas 24 horas.
   Reutiliza la caché común: nunca agrega FIXALARMS desde una petición web. */
$alarmMap = [];
if ($db->ok() && $rows) {
    $alarmCacheExists = (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE',N'U') IS NULL THEN 0 ELSE 1 END");
    if ($alarmCacheExists === 1) {
        foreach ($db->all("SELECT POZO,ALM FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE WHERE POZO IS NOT NULL") as $alarmRow) {
            $key = strtoupper(trim((string)($alarmRow['POZO'] ?? '')));
            $value = (int)($alarmRow['ALM'] ?? 0);
            if ($key !== '' && (!isset($alarmMap[$key]) || $value > $alarmMap[$key])) $alarmMap[$key] = $value;
        }
    }
}

foreach ($rows as &$row) {
    $well = ia_text($row['POZO'] ?? '');
    $row['__ALARM_COUNT'] = $well !== '' ? ($alarmMap[strtoupper($well)] ?? 0) : 0;
}
unset($row);
/* Los comentarios se consultan al abrir el popup, no en bloque al cargar. */

$filterCounts = [];
foreach ($filterColumns as $column => $label) $filterCounts[$column] = [];
$totals = array_fill_keys($sumColumns, 0.0);
$negativePressureCount = 0;

foreach ($rows as $row) {
    foreach ($filterColumns as $column => $label) {
        $value = ia_text($row[$column] ?? '');
        $filterCounts[$column][$value] = ($filterCounts[$column][$value] ?? 0) + 1;
    }
    foreach ($sumColumns as $column) $totals[$column] += ia_number_value($row[$column] ?? 0);
    if (ia_number_value($row['Presión Inyeccion'] ?? 0) < 0) $negativePressureCount++;
}
foreach ($filterCounts as &$counts) ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
unset($counts);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Inyección de Agua · CLEAR</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260803-grid1">
  <link rel="stylesheet" href="assets/css/telemetry_modal.css?v=3.1.9">
  <link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260826-central-1">
  <link rel="stylesheet" href="assets/css/inyeccion_agua.css?v=3.2.6">
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <section class="ia-page">
      <?php if ($error): ?>
        <div class="ia-error"><?php echo h($error); ?></div>
      <?php endif; ?>

      <div class="ia-hero">
        <div class="ia-hero__title">
          <div class="ia-hero__copy">
            <div class="ia-hero__eyebrow">Operación de pozos</div>
            <h1>Inyección de Agua</h1>
            <p>Información materializada desde dbo.INY_RTQP · actualización SQL cada 20 minutos</p>
            <div class="ia-live">
              <span class="dot"></span>
              <span><?php echo count($rows); ?> registros</span>
              <?php if ($lastRefresh !== ''): ?><span>· caché <?php echo h($lastRefresh); ?></span><?php endif; ?>
              <span>· consulta <?php echo h((string)$queryMs); ?> ms</span>
            </div>
          </div>
          <div class="ia-hero__visual" aria-hidden="true"><?php echo icon('droplet'); ?></div>
        </div>

        <div class="ia-kpis">
          <div class="ia-kpi caudal">
            <span class="ia-kpi__icon"><?php echo icon('wave'); ?></span>
            <div><strong id="iaKpiCaudal"><?php echo h(ia_format_number($totals['Caudal Inst'])); ?></strong><span>Suma Caudal Inst</span></div>
          </div>
          <div class="ia-kpi acumulado">
            <span class="ia-kpi__icon"><?php echo icon('chart'); ?></span>
            <div><strong id="iaKpiAcumulado"><?php echo h(ia_format_number($totals['Acumulado Hoy'])); ?></strong><span>Suma Acumulado Hoy</span></div>
          </div>
          <div class="ia-kpi cierre">
            <span class="ia-kpi__icon"><?php echo icon('gauge'); ?></span>
            <div><strong id="iaKpiCierre"><?php echo h(ia_format_number($totals['Cierre'])); ?></strong><span>Suma Cierre</span></div>
          </div>
          <button type="button" class="ia-kpi negative" id="iaNegativeCard" data-quick-filter="negative-pressure">
            <span class="ia-kpi__icon"><?php echo icon('loss'); ?></span>
            <div><strong id="iaKpiNegative"><?php echo (int)$negativePressureCount; ?></strong><span>Presiones negativas</span><small>Click para filtrar y ver resumen</small></div>
          </button>
          <div class="ia-kpi records">
            <span class="ia-kpi__icon"><?php echo icon('grid'); ?></span>
            <div><strong id="iaKpiRecords"><?php echo count($rows); ?></strong><span>Registros visibles</span></div>
          </div>
        </div>
      </div>

      <div class="ia-filter-grid">
        <?php foreach ($filterColumns as $column => $label): ?>
          <details class="ia-panel" data-group="<?php echo h($column); ?>">
            <summary data-base-label="<?php echo h($label); ?>"><?php echo h($label); ?> <small>Todos</small></summary>
            <div class="ia-panel__body">
              <div class="ia-panel__actions">
                <button type="button" data-all>Seleccionar todo</button>
                <button type="button" data-clear>Limpiar</button>
              </div>
              <?php foreach ($filterCounts[$column] as $value => $count): ?>
                <label class="ia-check" title="<?php echo h($value); ?>">
                  <input class="ia-multi" type="checkbox" data-column="<?php echo h($column); ?>" value="<?php echo h($value); ?>">
                  <span><?php echo h($column === 'PANTALLA' ? ia_screen_filter_label($value) : ($value === '' ? 'Sin dato' : $value)); ?></span>
                  <b><?php echo (int)$count; ?></b>
                </label>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endforeach; ?>
      </div>

      <div class="ia-toolbar">
        <input id="iaSearch" class="ia-search" type="search" placeholder="Buscar en toda la grilla…">
        <button type="button" class="ia-btn" id="iaClear">Limpiar filtros</button>
        <button type="button" class="ia-btn" id="iaExport">Exportar Excel</button>
        <button type="button" class="ia-btn" id="iaRefresh">Actualizar</button>
        <label class="ia-page-size" for="iaPageSize"><span>Filas</span><select id="iaPageSize" aria-label="Cantidad de filas visibles"><option value="50" selected>50</option><option value="100">100</option><option value="all">Todas</option></select></label>
        <select class="ia-select" id="iaInterval" aria-label="Intervalo de actualización">
          <option value="0" selected>Automática desactivada</option>
          <option value="300">Cada 5 minutos</option>
          <option value="600">Cada 10 minutos</option>
          <option value="1200">Cada 20 minutos</option>
          <option value="1800">Cada 30 minutos</option>
        </select>
        <div class="ia-columns">
          <button type="button" class="ia-btn" id="iaColumnsBtn">Columnas ▾</button>
          <div class="ia-columns-menu" id="iaColumnsMenu">
            <div id="iaColumnsList"></div>
            <button type="button" class="ia-btn" id="iaResetCols">Restablecer</button>
          </div>
        </div>
        <span class="ia-count">Mostrando <b id="iaShown"><?php echo min(50,count($rows)); ?></b> de <b id="iaVisible"><?php echo count($rows); ?></b> visibles</span>
      </div>

      <div class="ia-grid-anchor" id="iaGridSection"></div>
      <div class="ia-table-wrap">
        <table class="ia-table" id="iaTable">
          <thead>
            <tr>
              <?php foreach ($columns as $column):
                $isNumeric = in_array($column, $numericColumns, true);
                $isComboFilter = in_array($column, ['PLANTA', 'SATELITE', 'ZONA'], true);
              ?>
                <th draggable="true" data-column="<?php echo h($column); ?>" data-label="<?php echo h($column); ?>" data-type="<?php echo $isNumeric ? 'number' : 'text'; ?>">
                  <?php echo h($column); ?><span> ↕</span>
                  <?php if ($isComboFilter): ?>
                    <select class="ia-column-filter ia-column-select" data-column="<?php echo h($column); ?>" data-filter-mode="exact" aria-label="Filtrar por <?php echo h($column); ?>">
                      <option value="">Todos</option>
                      <?php foreach (array_keys($filterCounts[$column] ?? []) as $filterValue): ?>
                        <option value="<?php echo h($filterValue); ?>"><?php echo h($filterValue === '' ? 'Sin dato' : $filterValue); ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <input class="ia-column-filter" data-column="<?php echo h($column); ?>" placeholder="Filtrar">
                  <?php endif; ?>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row):
              $pressure = ia_number_value($row['Presión Inyeccion'] ?? 0);
              $negative = $pressure < 0;
            ?>
              <?php
                $alarmCount = (int)($row['__ALARM_COUNT'] ?? 0);
                $rowWell = ia_text($row['POZO'] ?? '');
                [$alarmUrl, $alarmEmbedUrl] = ia_alarm_urls($rowWell);
                $alarmCommentSubject = clear_alarm_comment_subject($rowWell, 'pozo');
              ?>
              <tr class="<?php echo $negative ? 'ia-row-negative' : ''; ?>"
                  data-negative-pressure="<?php echo $negative ? '1' : '0'; ?>"
                  data-alarm-count="<?php echo $alarmCount; ?>"
                  data-alarm-url="<?php echo h($alarmUrl); ?>"
                  data-alarm-embed-url="<?php echo h($alarmEmbedUrl); ?>">
                <?php foreach ($columns as $column):
                  $raw = ia_text($row[$column] ?? '');
                  $isNumeric = in_array($column, $numericColumns, true);
                ?>
                  <td data-column="<?php echo h($column); ?>" data-raw="<?php echo h($raw); ?>" class="<?php echo $isNumeric ? 'num' : ''; ?>">
                    <?php if ($column === 'POZO'): ?>
                      <div class="ia-well-cell">
                        <span><?php echo h($raw); ?></span>
                        <a class="ia-alarm-bell <?php echo h(ia_alarm_class($alarmCount)); ?>"
                           href="<?php echo h($alarmUrl); ?>"
                           data-telemetry-modal-url="<?php echo h($alarmEmbedUrl); ?>"
                           data-telemetry-modal-open-url="<?php echo h($alarmUrl); ?>"
                           data-telemetry-modal-title="<?php echo h('Alarmas 24 h · ' . ($raw !== '' ? $raw : 'Pozo')); ?>"
                           data-telemetry-modal-subtitle="Consulta operativa de las alarmas del pozo"
                           data-telemetry-modal-eyebrow="Alarmas de pozo"
                           title="<?php echo h($alarmCount . ' alarmas en las últimas 24 horas'); ?>">
                          <?php echo icon('bell'); ?><span><?php echo $alarmCount; ?></span>
                        </a>
                        <?php echo clear_alarm_actions_cell(['display'=>'','subject'=>$alarmCommentSubject,'subject_label'=>'Pozo '.$rowWell,'context'=>'inyeccion_agua','show_history'=>false,'show_comment'=>true,'icon_only'=>true]); ?>
                      </div>
                    <?php elseif ($column === 'SATELITE' && $raw !== ''): ?>
                      <button type="button" class="ia-satellite-link"
                              data-satellite="<?php echo h($raw); ?>"
                              data-plant="<?php echo h(ia_text($row['PLANTA'] ?? '')); ?>"
                              title="Ver pozos y totales del satélite <?php echo h($raw); ?>">
                        <?php echo h($raw); ?>
                      </button>
                    <?php elseif ($column === 'PANTALLA' && ia_is_link($raw)): ?>
                      <a class="ia-screen-link" href="<?php echo h($raw); ?>" target="_blank" rel="noopener"
                         data-telemetry-popup-url="<?php echo h($raw); ?>"
                         data-telemetry-popup-name="CLEAR_INYECCION_AGUA"
                         title="Abrir pantalla en ventana emergente"><?php echo icon('monitor'); ?></a>
                    <?php elseif ($column === 'PANTALLA'): ?>
                    <?php elseif ($column === 'Presión Inyeccion' && $negative): ?>
                      <span class="ia-pressure-negative"><?php echo h(ia_format_number($raw)); ?></span>
                    <?php elseif ($isNumeric): ?>
                      <?php echo h(ia_format_number($raw)); ?>
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

      <section class="ia-summary" id="iaSummary" aria-label="Resumen de filas visibles">
        <div class="ia-summary__head">
          <div>
            <span>Resumen visible</span>
            <h2>Totales de la grilla filtrada</h2>
          </div>
          <small>Los valores se recalculan al aplicar filtros.</small>
        </div>
        <div class="ia-summary__grid">
          <div><span>Caudal Inst</span><strong id="iaSummaryCaudal"><?php echo h(ia_format_number($totals['Caudal Inst'])); ?></strong></div>
          <div><span>Acumulado Hoy</span><strong id="iaSummaryAcumulado"><?php echo h(ia_format_number($totals['Acumulado Hoy'])); ?></strong></div>
          <div><span>Cierre</span><strong id="iaSummaryCierre"><?php echo h(ia_format_number($totals['Cierre'])); ?></strong></div>
          <div><span>Presiones negativas</span><strong id="iaSummaryNegative"><?php echo (int)$negativePressureCount; ?></strong></div>
        </div>
      </section>
    </section>
  </main>
</div>

<div class="ia-satellite-modal" id="iaSatelliteModal" hidden aria-hidden="true">
  <div class="ia-satellite-modal__backdrop" data-ia-satellite-close></div>
  <section class="ia-satellite-dialog" role="dialog" aria-modal="true" aria-labelledby="iaSatelliteTitle">
    <header class="ia-satellite-dialog__header">
      <div>
        <span class="ia-satellite-dialog__eyebrow">Detalle de inyección</span>
        <h2 id="iaSatelliteTitle">Satélite</h2>
        <p id="iaSatelliteSubtitle"></p>
      </div>
      <button type="button" class="ia-satellite-dialog__close" data-ia-satellite-close aria-label="Cerrar">×</button>
    </header>

    <div class="ia-satellite-dialog__meta">
      <div class="ia-satellite-dialog__meta-info">
        <span><b id="iaSatelliteWellCount">0</b> pozos</span>
        <span>Valores con dos decimales</span>
        <span>Alarmas de las últimas 24 h</span>
      </div>
      <button type="button" class="ia-satellite-export" id="iaSatelliteExport"><?php echo icon('download'); ?> Exportar Excel</button>
    </div>

    <div class="ia-satellite-table-wrap">
      <table class="ia-satellite-table" id="iaSatelliteTable">
        <thead>
          <tr>
            <th>POZO</th>
            <th>ALM</th>
            <th>ZONA</th>
            <th>Presión Inyeccion</th>
            <th>Caudal Inst</th>
            <th>Acumulado Hoy</th>
            <th>Proyectado</th>
            <th>Cierre</th>
            <th>PANTALLA</th>
          </tr>
        </thead>
        <tbody id="iaSatelliteBody"></tbody>
        <tfoot>
          <tr>
            <th colspan="4">TOTALES DEL SATÉLITE</th>
            <th id="iaSatelliteTotalCaudal">0,00</th>
            <th id="iaSatelliteTotalAcumulado">0,00</th>
            <th id="iaSatelliteTotalProyectado">0,00</th>
            <th id="iaSatelliteTotalCierre">0,00</th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="ia-satellite-dialog__totals" aria-label="Totales del satélite">
      <div><span>Caudal Inst</span><strong id="iaSatelliteCardCaudal">0,00</strong></div>
      <div><span>Acumulado Hoy</span><strong id="iaSatelliteCardAcumulado">0,00</strong></div>
      <div><span>Proyectado</span><strong id="iaSatelliteCardProyectado">0,00</strong></div>
      <div><span>Cierre</span><strong id="iaSatelliteCardCierre">0,00</strong></div>
    </div>
  </section>
</div>

<?php clear_alarm_actions_modal(); ?>
<?php include __DIR__ . '/includes/telemetry_modal.php'; ?>
<script src="assets/js/app.js?v=3.1.7"></script>
<script>
window.CLEAR_INYECCION_AGUA = <?php echo json_encode([
    'key' => 'inyeccion_agua',
    'title' => 'Inyección de Agua',
    'filterColumns' => array_keys($filterColumns),
    'numericColumns' => $numericColumns,
    'sumColumns' => $sumColumns,
    'pressureColumn' => 'Presión Inyeccion'
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/js/inyeccion_agua.js?v=3.2.8-perf-safe"></script>
<script src="assets/js/telemetry_modal.js?v=3.1.9"></script>
<script src="assets/js/alarm_actions.js?v=20260826-central-1"></script>
</body>
</html>
