<?php
/* =============================================================
   CLEAR — Diagnóstico Zafiro (solo lectura)
   Ejecuta las pruebas con la misma conexión SQL usada por la web.
   No modifica tablas ni consulta dbo.FIXALARMS.
============================================================= */

require_once __DIR__ . '/includes/auth.php';
auth_require_admin();

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$config = require __DIR__ . '/config.php';
$dbConfig = isset($config['db']) && is_array($config['db']) ? $config['db'] : [];

function dz_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dz_run($dbConfig, $sql, $params = [])
{
    try {
        $db = new DB($dbConfig);
        if (!$db->ok()) {
            return ['rows' => [], 'error' => (string)$db->error()];
        }
        $rows = $db->all($sql, $params);
        return ['rows' => is_array($rows) ? $rows : [], 'error' => (string)$db->error()];
    } catch (Throwable $e) {
        return ['rows' => [], 'error' => $e->getMessage()];
    } catch (Exception $e) {
        return ['rows' => [], 'error' => $e->getMessage()];
    }
}

function dz_row_value($row, $wanted)
{
    foreach ((array)$row as $key => $value) {
        if (strcasecmp((string)$key, (string)$wanted) === 0) {
            return $value;
        }
    }
    return null;
}

function dz_normalize($value)
{
    $value = strtr((string)$value, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'
    ]);
    return preg_replace('/[^A-Z0-9]+/', '', strtoupper($value));
}

function dz_quote_identifier($name)
{
    return '[' . str_replace(']', ']]', (string)$name) . ']';
}

function dz_detect_columns($columnNames)
{
    $detected = ['well' => null, 'state' => null, 'method' => null];

    foreach ($columnNames as $name) {
        $normalized = dz_normalize($name);

        if ($detected['well'] === null) {
            if (in_array($normalized, ['POZO', 'POZONAME', 'NOMBREPOZO', 'WELL', 'WELLNAME'], true)) {
                $detected['well'] = $name;
            }
        }

        if ($detected['state'] === null) {
            if (
                in_array($normalized, ['ESTADOZAFIRO', 'ZAFIROESTADO', 'ESTADO'], true)
                || strpos($normalized, 'CAMBIODEESTADOESTADO') !== false
                || (strpos($normalized, 'CAMBIODEESTADO') !== false && substr($normalized, -6) === 'ESTADO')
            ) {
                $detected['state'] = $name;
            }
        }

        if ($detected['method'] === null) {
            if (
                in_array($normalized, ['METODOZAFIRO', 'ZAFIROMETODO', 'METODO', 'SISTEMAEXTRACCION'], true)
                || strpos($normalized, 'SISTEMADEEXTRACCION') !== false
            ) {
                $detected['method'] = $name;
            }
        }
    }

    return $detected;
}

function dz_relevant_columns($columnNames)
{
    $out = [];
    foreach ($columnNames as $name) {
        $normalized = dz_normalize($name);
        if (
            strpos($normalized, 'POZO') !== false
            || strpos($normalized, 'WELL') !== false
            || strpos($normalized, 'ESTADO') !== false
            || strpos($normalized, 'METODO') !== false
            || strpos($normalized, 'EXTRACCION') !== false
        ) {
            $out[] = $name;
        }
    }
    return $out;
}

$connection = dz_run($dbConfig, "SELECT DB_NAME() AS DB_ACTUAL, @@SERVERNAME AS SERVIDOR");
$currentDb = '';
$serverName = '';
if (!empty($connection['rows'])) {
    $currentDb = (string)dz_row_value($connection['rows'][0], 'DB_ACTUAL');
    $serverName = (string)dz_row_value($connection['rows'][0], 'SERVIDOR');
}

$sources = [
    [
        'label' => 'Vista en la base actual',
        'object' => '[dbo].[VW_CLEAR_ZAFIRO_TELEMETRIA]',
        'metadata' => "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
        'params' => ['dbo', 'VW_CLEAR_ZAFIRO_TELEMETRIA']
    ],
    [
        'label' => 'Vista en LCMDB',
        'object' => '[LCMDB].[dbo].[VW_CLEAR_ZAFIRO_TELEMETRIA]',
        'metadata' => "SELECT COLUMN_NAME FROM [LCMDB].[INFORMATION_SCHEMA].[COLUMNS] WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
        'params' => ['dbo', 'VW_CLEAR_ZAFIRO_TELEMETRIA']
    ],
    [
        'label' => 'Fuente CLEAR_API_Q158_POZOS en LCMDB',
        'object' => '[LCMDB].[dbo].[CLEAR_API_Q158_POZOS]',
        'metadata' => "SELECT COLUMN_NAME FROM [LCMDB].[INFORMATION_SCHEMA].[COLUMNS] WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
        'params' => ['dbo', 'CLEAR_API_Q158_POZOS']
    ]
];

$results = [];
foreach ($sources as $source) {
    $meta = dz_run($dbConfig, $source['metadata'], $source['params']);
    $names = [];
    foreach ($meta['rows'] as $row) {
        $name = dz_row_value($row, 'COLUMN_NAME');
        if ($name !== null && $name !== '') {
            $names[] = (string)$name;
        }
    }

    $detected = dz_detect_columns($names);
    $sample = ['rows' => [], 'error' => '', 'mode' => ''];
    if ($detected['well'] !== null && $detected['state'] !== null && $detected['method'] !== null) {
        $well = dz_quote_identifier($detected['well']);
        $state = dz_quote_identifier($detected['state']);
        $method = dz_quote_identifier($detected['method']);
        $sql = "SELECT TOP (5) CONVERT(nvarchar(255), {$well}) AS Z_POZO, "
             . "CONVERT(nvarchar(255), {$state}) AS Z_ESTADO, "
             . "CONVERT(nvarchar(255), {$method}) AS Z_METODO "
             . "FROM {$source['object']} "
             . "WHERE CONVERT(nvarchar(255), {$well}) LIKE ? "
             . "ORDER BY {$well}";
        $sample = dz_run($dbConfig, $sql, ['%CnE-1373%']);
        $sample['mode'] = 'CnE-1373';

        if ($sample['error'] === '' && empty($sample['rows'])) {
            $fallbackSql = "SELECT TOP (3) CONVERT(nvarchar(255), {$well}) AS Z_POZO, "
                         . "CONVERT(nvarchar(255), {$state}) AS Z_ESTADO, "
                         . "CONVERT(nvarchar(255), {$method}) AS Z_METODO "
                         . "FROM {$source['object']} "
                         . "WHERE {$well} IS NOT NULL "
                         . "ORDER BY {$well}";
            $sample = dz_run($dbConfig, $fallbackSql);
            $sample['mode'] = 'primeras filas (no coincidió CnE-1373)';
        }
    }

    $results[] = [
        'source' => $source,
        'meta' => $meta,
        'columns' => $names,
        'relevant' => dz_relevant_columns($names),
        'detected' => $detected,
        'sample' => $sample
    ];
}

$directSql = "SELECT TOP (5) "
           . "CONVERT(nvarchar(255), [Pozo]) AS Z_POZO, "
           . "CONVERT(nvarchar(255), [Resumen de Producción Teórica de Pozo>>Cambio de estado>>Estado]) AS Z_ESTADO, "
           . "CONVERT(nvarchar(255), [Resumen de Producción Teórica de Pozo>>Sistema de Extracción>>Sistema de Extracción_name]) AS Z_METODO "
           . "FROM [LCMDB].[dbo].[CLEAR_API_Q158_POZOS] "
           . "WHERE CONVERT(nvarchar(255), [Pozo]) LIKE ? "
           . "ORDER BY [Pozo]";
$direct = dz_run($dbConfig, $directSql, ['%CnE-1373%']);

$telemetry = dz_run(
    $dbConfig,
    "SELECT TOP (5) CONVERT(nvarchar(255), [POZO]) AS T_POZO FROM [dbo].[BM_RTQP] WHERE CONVERT(nvarchar(255), [POZO]) LIKE ? ORDER BY [POZO]",
    ['%CnE-1373%']
);

function dz_status($ok, $yes = 'OK', $no = 'FALLÓ')
{
    return '<span class="status ' . ($ok ? 'ok' : 'bad') . '">' . dz_h($ok ? $yes : $no) . '</span>';
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnóstico Zafiro · CLEAR</title>
<style>
:root{color-scheme:light;--ink:#173447;--muted:#637b8a;--line:#d6e0e6;--bg:#eef3f6;--card:#fff;--ok:#157347;--okbg:#dff4e8;--bad:#b42318;--badbg:#fde7e5}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:1180px;margin:28px auto;padding:0 18px}.head,.card{background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:0 3px 14px rgba(23,52,71,.05)}
.head{padding:22px 24px;margin-bottom:16px}.card{padding:20px 22px;margin:14px 0}h1{margin:0 0 5px;font-size:24px}h2{font-size:18px;margin:0 0 13px}h3{font-size:14px;margin:18px 0 8px}
p{margin:6px 0}.muted{color:var(--muted)}.status{display:inline-block;padding:3px 9px;border-radius:999px;font-weight:700;font-size:12px}.ok{background:var(--okbg);color:var(--ok)}.bad{background:var(--badbg);color:var(--bad)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.fact{padding:10px 12px;border:1px solid var(--line);border-radius:9px}.fact b{display:block;font-size:12px;color:var(--muted);margin-bottom:3px}
table{width:100%;border-collapse:collapse;margin-top:8px;font-size:13px}th,td{border:1px solid var(--line);padding:8px 9px;text-align:left;vertical-align:top}th{background:#f5f8fa}.error{white-space:pre-wrap;background:var(--badbg);color:var(--bad);padding:10px;border-radius:8px;overflow-wrap:anywhere}.cols{white-space:pre-wrap;background:#f5f8fa;border:1px solid var(--line);padding:10px;border-radius:8px;overflow-wrap:anywhere}
code{background:#edf2f5;padding:2px 5px;border-radius:4px}a{color:#0b6680}
</style>
</head>
<body>
<main>
<section class="head">
  <h1>Diagnóstico de datos Zafiro</h1>
  <p>Prueba de solo lectura usando la misma conexión SQL de CLEAR.</p>
  <p class="muted">Generado: <?= dz_h(date('Y-m-d H:i:s')) ?> · Usuario: <?= dz_h(auth_user()) ?></p>
</section>

<section class="card">
  <h2>Conexión de la aplicación</h2>
  <div class="grid">
    <div class="fact"><b>Conexión</b><?= dz_status($connection['error'] === '' && !empty($connection['rows'])) ?></div>
    <div class="fact"><b>Servidor SQL</b><?= dz_h($serverName !== '' ? $serverName : 'No disponible') ?></div>
    <div class="fact"><b>Base actual</b><?= dz_h($currentDb !== '' ? $currentDb : 'No disponible') ?></div>
    <div class="fact"><b>Driver configurado</b><?= dz_h(isset($dbConfig['driver']) ? $dbConfig['driver'] : 'No indicado') ?></div>
  </div>
  <?php if ($connection['error'] !== ''): ?><div class="error"><?= dz_h($connection['error']) ?></div><?php endif; ?>
</section>

<section class="card">
  <h2>Pozo en telemetría</h2>
  <p><?= dz_status($telemetry['error'] === '' && !empty($telemetry['rows']), 'ENCONTRADO', 'NO ENCONTRADO') ?> Consulta en <code>dbo.BM_RTQP</code>.</p>
  <?php if ($telemetry['error'] !== ''): ?><div class="error"><?= dz_h($telemetry['error']) ?></div><?php endif; ?>
  <?php if (!empty($telemetry['rows'])): ?>
  <table><thead><tr><th>POZO</th></tr></thead><tbody>
  <?php foreach ($telemetry['rows'] as $row): ?><tr><td><?= dz_h(dz_row_value($row, 'T_POZO')) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>

<?php foreach ($results as $result): ?>
<section class="card">
  <h2><?= dz_h($result['source']['label']) ?></h2>
  <p><code><?= dz_h($result['source']['object']) ?></code></p>
  <div class="grid">
    <div class="fact"><b>Lectura de metadatos</b><?= dz_status($result['meta']['error'] === '' && count($result['columns']) > 0) ?></div>
    <div class="fact"><b>Columnas encontradas</b><?= dz_h(count($result['columns'])) ?></div>
    <div class="fact"><b>Pozo detectado</b><?= dz_h($result['detected']['well'] ?: 'NO') ?></div>
    <div class="fact"><b>Estado detectado</b><?= dz_h($result['detected']['state'] ?: 'NO') ?></div>
    <div class="fact"><b>Método detectado</b><?= dz_h($result['detected']['method'] ?: 'NO') ?></div>
  </div>

  <?php if ($result['meta']['error'] !== ''): ?>
    <h3>Error de metadatos</h3><div class="error"><?= dz_h($result['meta']['error']) ?></div>
  <?php endif; ?>

  <?php if (!empty($result['relevant'])): ?>
    <h3>Columnas relevantes</h3><div class="cols"><?= dz_h(implode("\n", $result['relevant'])) ?></div>
  <?php endif; ?>

  <?php if ($result['sample']['error'] !== ''): ?>
    <h3>Error al leer datos</h3><div class="error"><?= dz_h($result['sample']['error']) ?></div>
  <?php elseif ($result['detected']['well'] !== null && $result['detected']['state'] !== null && $result['detected']['method'] !== null): ?>
    <h3>Resultado (<?= dz_h($result['sample']['mode']) ?>)</h3>
    <p><?= dz_status(!empty($result['sample']['rows']), 'CON DATOS', 'SIN FILAS') ?></p>
    <?php if (!empty($result['sample']['rows'])): ?>
    <table><thead><tr><th>Pozo</th><th>Estado Zafiro</th><th>Método Zafiro</th></tr></thead><tbody>
      <?php foreach ($result['sample']['rows'] as $row): ?>
      <tr>
        <td><?= dz_h(dz_row_value($row, 'Z_POZO')) ?></td>
        <td><?= dz_h(dz_row_value($row, 'Z_ESTADO')) ?></td>
        <td><?= dz_h(dz_row_value($row, 'Z_METODO')) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  <?php else: ?>
    <h3>Resultado</h3><p><?= dz_status(false, 'OK', 'NO SE PUDO ARMAR LA CONSULTA') ?></p>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<section class="card">
  <h2>Prueba directa con los campos solicitados</h2>
  <p>Omite la detección automática y consulta directamente <code>LCMDB.dbo.CLEAR_API_Q158_POZOS</code>.</p>
  <p><?= dz_status($direct['error'] === '' && !empty($direct['rows']), 'CON DATOS', 'SIN DATOS / ERROR') ?></p>
  <?php if ($direct['error'] !== ''): ?><div class="error"><?= dz_h($direct['error']) ?></div><?php endif; ?>
  <?php if (!empty($direct['rows'])): ?>
  <table><thead><tr><th>Pozo</th><th>Estado Zafiro</th><th>Método Zafiro</th></tr></thead><tbody>
    <?php foreach ($direct['rows'] as $row): ?>
    <tr>
      <td><?= dz_h(dz_row_value($row, 'Z_POZO')) ?></td>
      <td><?= dz_h(dz_row_value($row, 'Z_ESTADO')) ?></td>
      <td><?= dz_h(dz_row_value($row, 'Z_METODO')) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Siguiente paso</h2>
  <p>Enviá una captura completa de esta página. Con ella se puede aplicar la corrección exacta sin volver a adivinar la base, los permisos ni los nombres de columnas.</p>
</section>
</main>
</body>
</html>
