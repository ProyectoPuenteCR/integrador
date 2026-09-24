<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/alarm_actions.php';
require_once __DIR__ . '/includes/pozos_top20_week.php';

auth_require();
permissions_require_menu('pozos_top20');

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = 'pozos_top20';
$db = clear_db();
$dbError = $db->ok() ? '' : $db->error();
$table = 'dbo.FIXALARMS';

$prefixPresent = array_key_exists('prefijo_pozo', $_GET);
$prefix = $prefixPresent ? trim((string)$_GET['prefijo_pozo']) : trim((string)user_pref_get('pozos_top20_prefix', 'YPF.SC'));
if ($prefixPresent) user_pref_set('pozos_top20_prefix', $prefix);
$search = trim((string)($_GET['q'] ?? ''));
$refresh = trim((string)($_GET['refresh'] ?? '0'));
if (!in_array($refresh, ['0','300','600','1800'], true)) $refresh = '0';
$today = new DateTimeImmutable('now');
$selectedWeek = pt20_selected_week($_GET, $today);
$weekOptions = pt20_week_options($today, 53);
$fromDate = $selectedWeek['start']->format('Y-m-d');
$toDate = $selectedWeek['end']->format('Y-m-d');
$fromSql = $fromDate . 'T00:00:00';
$toSqlExclusive = $selectedWeek['next']->format('Y-m-d') . 'T00:00:00';

function pt20_terms($value) {
    $value = trim((string)$value);
    return $value === '' ? [] : array_values(array_filter(preg_split('/\s+/', $value)));
}
function pt20_num($value) { return number_format((int)$value, 0, ',', '.'); }
function pt20_pct($value) { return number_format((float)$value, 1, ',', '.') . '%'; }

$rows = [];
$total = 0;
$topTag = '';
$topTagCount = 0;
$topWell = '';
$topWellCount = 0;
$top5Count = 0;
$top5Pct = 0;
$weeklyComments = [];
$canViewComments = permissions_can('comments.view');
$canCreateComments = permissions_can('comments.create');
$tagColumn = 'ALM_TAGNAME';
$descColumn = 'ALM_DESCR';
$wellColumn = 'ALM_ALMEXTFLD2';
$dateColumn = 'ALM_NATIVETIMEIN';

if ($db->ok()) {
    $metaRows = $db->all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS'");
    $lookup = [];
    foreach ($metaRows as $metaRow) {
        $col = trim((string)($metaRow['COLUMN_NAME'] ?? $metaRow['column_name'] ?? reset($metaRow)));
        if ($col !== '') $lookup[strtoupper($col)] = $col;
    }
    $tagColumn = $lookup['ALM_TAGNAME'] ?? $tagColumn;
    $descColumn = $lookup['ALM_DESCR'] ?? ($lookup['ALM_TAGDESC'] ?? $descColumn);
    $wellColumn = $lookup['ALM_ALMEXTFLD2'] ?? $wellColumn;
    $dateColumn = $lookup['ALM_NATIVETIMEIN'] ?? $dateColumn;

    $tagExpr = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$tagColumn])))";
    $wellExpr = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$wellColumn])))";
    $descExpr = "LTRIM(RTRIM(CONVERT(nvarchar(1000), [$descColumn])))";
    $conditions = ["[$tagColumn] IS NOT NULL", "$tagExpr <> ''", "[$wellColumn] IS NOT NULL", "$wellExpr <> ''", "[$dateColumn] >= CONVERT(datetime2, ?, 126)", "[$dateColumn] < CONVERT(datetime2, ?, 126)"];
    $params = [$fromSql, $toSqlExclusive];
    if ($prefix !== '') {
        $conditions[] = "UPPER($wellExpr) LIKE ?";
        $params[] = strtoupper($prefix) . '%';
    }
    foreach (pt20_terms($search) as $term) {
        $conditions[] = "($tagExpr LIKE ? OR $descExpr LIKE ? OR $wellExpr LIKE ?)";
        $params[] = '%' . $term . '%';
        $params[] = '%' . $term . '%';
        $params[] = '%' . $term . '%';
    }
    $where = 'WHERE ' . implode(' AND ', $conditions);

    $total = (int)$db->scalar("SELECT COUNT(*) FROM $table $where", $params);
    $rows = $db->all(
        "SELECT TOP 20 $wellExpr AS pozo, $tagExpr AS tag, MAX($descExpr) AS descripcion, COUNT(*) AS total_alarmas " .
        "FROM $table $where GROUP BY $wellExpr, $tagExpr ORDER BY COUNT(*) DESC, $wellExpr ASC, $tagExpr ASC",
        $params
    );
    if ($rows) {
        $topTag = trim((string)($rows[0]['tag'] ?? $rows[0]['TAG'] ?? ''));
        $topTagCount = (int)($rows[0]['total_alarmas'] ?? $rows[0]['TOTAL_ALARMAS'] ?? 0);
        foreach (array_slice($rows, 0, 5) as $row) $top5Count += (int)($row['total_alarmas'] ?? $row['TOTAL_ALARMAS'] ?? 0);
    }
    $wellRows = $db->all("SELECT TOP 1 $wellExpr AS pozo, COUNT(*) AS cantidad FROM $table $where GROUP BY $wellExpr ORDER BY COUNT(*) DESC, $wellExpr ASC", $params);
    if ($wellRows) {
        $topWell = trim((string)($wellRows[0]['pozo'] ?? $wellRows[0]['POZO'] ?? ''));
        $topWellCount = (int)($wellRows[0]['cantidad'] ?? $wellRows[0]['CANTIDAD'] ?? 0);
    }
    if ($total > 0) $top5Pct = $top5Count * 100 / $total;

    if (($canViewComments || $canCreateComments) && $rows) {
        $commentSubjects = [];
        foreach ($rows as $commentSourceRow) {
            $commentWell = trim((string)($commentSourceRow['pozo'] ?? $commentSourceRow['POZO'] ?? ''));
            if ($commentWell !== '') $commentSubjects[] = clear_alarm_comment_subject($commentWell, 'pozo');
        }
        $weeklyComments = clear_alarm_comments_load_subjects_sql($commentSubjects);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Top 20 Pozos · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260924-pozos-top20-comment-1">
  <link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260807-2">
  <style>
    .pt20Toolbar{display:grid;grid-template-columns:minmax(170px,.4fr) minmax(300px,.8fr) minmax(270px,.62fr) auto auto auto;gap:12px;align-items:center;margin-bottom:15px}
    .pt20Input{display:flex;align-items:center;gap:9px;background:var(--surface,#fff);border:1px solid var(--line-mid);border-radius:10px;min-height:42px;padding:0 12px;overflow:hidden}.pt20Input svg{width:16px!important;height:16px!important;min-width:16px!important;max-width:16px!important;display:block!important}.pt20Input span{font-size:10px;font-weight:800;letter-spacing:.7px;color:var(--text-mut);text-transform:uppercase}.pt20Input input,.pt20Input select{border:0;outline:0;background:transparent;color:var(--text);font:inherit;min-width:0;flex:1}
    .pt20Week{display:flex;align-items:center;gap:9px;background:var(--surface,#fff);border:1px solid var(--line-mid);border-radius:10px;min-height:42px;padding:0 12px}.pt20Week span{font-size:9px;font-weight:800;letter-spacing:.7px;color:var(--text-mut);text-transform:uppercase}.pt20Week select{border:0;outline:0;background:transparent;color:var(--text);font:inherit;min-width:190px;flex:1}.pt20Cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}
    .pt20Table .tagAnchor{font-weight:700;color:var(--petrol);text-decoration:underline;text-decoration-color:rgba(26,77,92,.28);text-underline-offset:3px}
    .pt20CommentButton{position:relative;display:inline-flex;align-items:center;justify-content:center;gap:7px;min-width:38px;height:31px;padding:0 10px;border:1px solid var(--line-mid);border-radius:8px;background:var(--surface,#fff);color:var(--petrol);cursor:pointer}.pt20CommentButton svg{width:15px;height:15px}.pt20CommentButton:hover,.pt20CommentButton:focus-visible{border-color:var(--petrol);background:var(--petrol-soft);outline:0}.pt20CommentButton.has-comment{background:var(--petrol);border-color:var(--petrol);color:#fff}.pt20CommentButton__dot{display:none;width:7px;height:7px;border-radius:50%;background:#38d58b}.pt20CommentButton.has-comment .pt20CommentButton__dot{display:block}
    .pt20CommentCell{display:flex;align-items:center;gap:9px;min-width:280px;max-width:520px}.pt20CommentCell__text{flex:1;min-width:0;white-space:normal;line-height:1.35;color:var(--text-soft)}.pt20CommentCell__text.has-comment{color:var(--text);font-weight:600}.pt20CommentCell .alarmCell{flex:0 0 auto}.pt20CommentCell .alarmCell__actions{margin-left:0}
    .pt20CommentOverlay{position:fixed;inset:0;z-index:151;background:rgba(12,35,44,.45);backdrop-filter:blur(3px)}.pt20CommentModal{position:fixed;z-index:152;left:50%;top:50%;width:min(590px,calc(100vw - 28px));transform:translate(-50%,-46%) scale(.985);background:var(--surface,#fff);border:1px solid var(--line-mid);border-radius:18px;box-shadow:0 28px 72px rgba(12,35,44,.32);opacity:0;pointer-events:none;transition:.17s ease;overflow:hidden}.pt20CommentModal.is-open{opacity:1;pointer-events:auto;transform:translate(-50%,-50%) scale(1)}.pt20CommentModal__head{display:flex;justify-content:space-between;gap:18px;padding:20px 22px 16px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,rgba(26,77,92,.08),transparent)}.pt20CommentModal__eyebrow{margin-bottom:4px;color:var(--text-mut);font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase}.pt20CommentModal h2{margin:0;color:var(--petrol);font-family:var(--font-head);font-size:26px}.pt20CommentModal__head p{margin:5px 0 0;color:var(--text-soft);font-size:12px}.pt20CommentModal__close{width:38px;height:38px;border:1px solid var(--line-mid);border-radius:10px;background:var(--surface,#fff);color:var(--text-soft);font-size:24px;cursor:pointer}.pt20CommentModal__body{padding:18px 22px}.pt20CommentModal__body label{display:block;margin-bottom:7px;color:var(--text-soft);font-size:11px;font-weight:800;letter-spacing:.7px;text-transform:uppercase}.pt20CommentModal textarea{width:100%;min-height:145px;resize:vertical;border:1px solid var(--line-mid);border-radius:11px;padding:11px 13px;background:var(--surface,#fff);color:var(--text);font:inherit;line-height:1.5;outline:0}.pt20CommentModal textarea:focus{border-color:var(--petrol);box-shadow:0 0 0 3px var(--petrol-soft)}.pt20CommentModal__previous,.pt20CommentModal__status{margin-bottom:12px;padding:10px 12px;border-radius:10px;font-size:12px}.pt20CommentModal__previous{border:1px solid #cae4d6;background:#f2fbf6;color:var(--green-tx)}.pt20CommentModal__status{margin:10px 0 0;background:#eef6f9;color:var(--petrol)}.pt20CommentModal__status.is-error{background:#fff1ef;color:var(--red)}.pt20CommentModal__status.is-ok{background:#edf9f3;color:var(--green-tx)}.pt20CommentModal__foot{display:flex;justify-content:flex-end;gap:10px;padding:0 22px 20px}.pt20CommentModal__foot button{min-height:40px;padding:0 15px;border-radius:10px;font:inherit;cursor:pointer}.pt20CommentModal__cancel{border:1px solid var(--line-mid);background:var(--surface,#fff);color:var(--text)}.pt20CommentModal__save{border:0;background:var(--petrol);color:#fff;font-weight:700}.pt20CommentModal__save:disabled{opacity:.58;cursor:wait}body.has-pt20-comment{overflow:hidden}
    @media(max-width:1200px){.pt20Toolbar{grid-template-columns:1fr 1fr}.pt20Cards{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:700px){.pt20Toolbar,.pt20Cards{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page__head">
      <div><h1 class="page__title">Top 20 Pozos · Semanal</h1><div class="page__sub">Alarmas agrupadas por pozo y TAG · semanas fijas de miércoles a martes</div></div>
      <div class="page__live"><span class="dot"></span><?php echo $dbError ? 'Sin conexión' : 'En vivo'; ?></div>
    </div>

    <?php if ($dbError): ?>
      <div class="tablewrap"><div class="empty"><p><b>Sin conexión:</b> <?php echo h($dbError); ?></p></div></div>
    <?php else: ?>
      <form class="pt20Toolbar" method="get" action="pozos_top20.php" id="pozosTop20Filters">
        <label class="pt20Input"><?php echo icon('search'); ?><span>Prefijo</span><input type="text" name="prefijo_pozo" value="<?php echo h($prefix); ?>" placeholder="Ej. YPF.SC"></label>
        <label class="pt20Input"><?php echo icon('search'); ?><span>Buscar</span><input type="search" name="q" value="<?php echo h($search); ?>" placeholder="Buscar TAG, descripción o pozo…"></label>
        <label class="pt20Week"><span>Semana</span><select name="semana" id="pt20Week">
          <?php foreach ($weekOptions as $weekOption): ?>
          <option value="<?php echo h($weekOption['value']); ?>" <?php echo $weekOption['value'] === $fromDate ? 'selected' : ''; ?>><?php echo h($weekOption['label']); ?></option>
          <?php endforeach; ?>
        </select></label>
        <label class="pt20Input"><span>Actualizar</span><select name="refresh" id="pt20Refresh"><option value="0" <?php echo $refresh==='0'?'selected':''; ?>>Desactivada</option><option value="300" <?php echo $refresh==='300'?'selected':''; ?>>5 minutos</option><option value="600" <?php echo $refresh==='600'?'selected':''; ?>>10 minutos</option><option value="1800" <?php echo $refresh==='1800'?'selected':''; ?>>30 minutos</option></select></label>
        <button type="submit" class="toolbar__dateApply">Aplicar</button>
        <button type="button" class="btn-export" id="pt20Export"><?php echo icon('download'); ?> Exportar CSV</button>
      </form>

      <section class="pt20Cards">
        <article class="stat acc-blue"><div class="stat__label">Total semanal de alarmas</div><div class="stat__value is-blue"><?php echo pt20_num($total); ?></div><div class="stat__detail">Semana del <b><?php echo h(date('d/m/Y', strtotime($fromDate))); ?></b> al <b><?php echo h(date('d/m/Y', strtotime($toDate))); ?></b> con prefijo <b><?php echo h($prefix !== '' ? $prefix : 'Todos'); ?></b>.</div></article>
        <article class="stat acc-red"><div class="stat__label">TAG más frecuente</div><div class="stat__value is-red" style="font-size:18px"><?php echo h($topTag !== '' ? $topTag : 'Sin datos'); ?></div><div class="stat__detail"><b><?php echo pt20_num($topTagCount); ?></b> alarmas.</div></article>
        <article class="stat acc-green"><div class="stat__label">Pozo principal</div><div class="stat__value is-green" style="font-size:18px"><?php echo h($topWell !== '' ? $topWell : 'Sin datos'); ?></div><div class="stat__detail"><b><?php echo pt20_num($topWellCount); ?></b> registros.</div></article>
        <article class="stat acc-amber"><div class="stat__label">Concentración del Top 5</div><div class="stat__value is-amber"><?php echo pt20_pct($top5Pct); ?></div><div class="stat__detail">Los cinco TAG principales concentran <b><?php echo pt20_num($top5Count); ?></b> alarmas.</div></article>
      </section>

      <div class="tablewrap">
        <?php if (!$rows): ?><div class="empty"><p>No se encontraron datos para el prefijo y búsqueda seleccionados.</p></div><?php else: ?>
        <div class="tablescroll"><table class="grid grid--sortable js-sortable pt20Table" id="pozosTop20Table">
          <thead><tr><th>POZO ↕</th><th>TAG ↕</th><th>DESCRIPCIÓN ↕</th><th>TOTAL ALARMAS ↕</th><th>COMENTARIO</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row):
            $tag = (string)($row['tag'] ?? $row['TAG'] ?? '');
            $well = trim((string)($row['pozo'] ?? $row['POZO'] ?? ''));
            $commentSubject = clear_alarm_comment_subject($well, 'pozo');
            $commentRow = $weeklyComments[strtoupper($commentSubject)] ?? [];
            $commentText = trim((string)($commentRow['COMENTARIO'] ?? $commentRow['comentario'] ?? ''));
            $hasWeeklyComment = $commentText !== '';
          ?>
            <tr>
              <td><?php echo h($well); ?></td>
              <td><?php echo clear_alarm_actions_cell(['display'=>$tag,'tag'=>$tag,'preserve_pi_link'=>true,'show_comment'=>false]); ?></td>
              <td><?php echo h((string)($row['descripcion']??$row['DESCRIPCION']??'')); ?></td>
              <td><?php echo pt20_num($row['total_alarmas']??$row['TOTAL_ALARMAS']??0); ?></td>
              <td>
                <div class="pt20CommentCell">
                  <div class="pt20CommentCell__text<?php echo $hasWeeklyComment ? ' has-comment' : ''; ?>" title="<?php echo h($commentText); ?>">
                    <?php echo $hasWeeklyComment ? nl2br(h($commentText)) : '—'; ?>
                  </div>
                  <?php echo clear_alarm_actions_cell(['display'=>'Comentario','tag'=>$tag,'subject'=>$commentSubject,'subject_label'=>'Pozo '.$well,'context'=>'pozos_top20','preserve_pi_link'=>false,'show_history'=>false,'show_comment'=>true,'has_comment'=>$hasWeeklyComment,'icon_only'=>true]); ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>
<?php clear_alarm_actions_modal(); ?>
<div class="piModal__overlay" id="piModalOverlay" hidden></div>
<section class="piModal" id="piModal" aria-hidden="true" aria-labelledby="piModalTitle"><div class="piModal__head"><div><div class="piModal__eyebrow">Vista rápida</div><h2 id="piModalTitle">PI Histórico</h2><p class="piModal__sub">Tendencia y referencias SQL existentes.</p></div><div class="piModal__actions"><a class="piModal__link" id="piModalOpenPage" href="pi_historico.php" target="_blank" rel="noopener">Abrir página completa</a><button type="button" class="piModal__close" id="piModalClose" aria-label="Cerrar PI Histórico">×</button></div></div><div class="piModal__body"><iframe id="piModalFrame" class="piModal__frame" src="about:blank" title="PI Histórico embebido" loading="lazy"></iframe></div></section>
<script src="assets/js/app.js?v=20260924-columns-1"></script>
<script src="assets/js/alarm_actions.js?v=20260826-central-1"></script>
</body>
</html>
