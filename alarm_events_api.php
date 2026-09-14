<?php
ob_start();
ini_set('display_errors','0');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/alarm_event_comments.php';

auth_require();
header('Content-Type: application/json; charset=utf-8');

function out($data, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function clean($value, $length = 255) {
    $value = trim((string)$value);
    return strlen($value) > $length ? substr($value, 0, $length) : $value;
}

function normalize_sql_datetime($value) {
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = preg_replace('/Z$/i', '', $value);
    $value = str_replace('T', ' ', $value);
    $value = preg_replace('/\.\d+$/', '', $value);

    $formats = [
        'Y-m-d H:i:s', 'Y-m-d H:i',
        'd/m/Y H:i:s', 'd/m/Y H:i',
        'j/n/Y H:i:s', 'j/n/Y H:i',
        'm/d/Y H:i:s', 'm/d/Y H:i',
    ];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat('!' . $format, $value);
        $errors = DateTime::getLastErrors();
        if ($dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    $ts = strtotime($value);
    return $ts === false ? '' : date('Y-m-d H:i:s', $ts);
}

function sql_datetime_to_iso($value) {
    $normalized = normalize_sql_datetime($value);
    return $normalized === '' ? '' : str_replace(' ', 'T', $normalized);
}

function row_value_ci(array $row, $name, $default = '') {
    if (array_key_exists($name, $row)) return $row[$name];
    $target = strtoupper((string)$name);
    foreach ($row as $key => $value) {
        if (strtoupper((string)$key) === $target) return $value;
    }
    return $default;
}

function alarm_source_rows($db, $tableName, $tag, $startSql, $endSql) {
    $safeTables = ['FIXALARMS'];
    if (!in_array($tableName, $safeTables, true)) return [];
    $meta = $db->all(
        "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=? ORDER BY ORDINAL_POSITION",
        [$tableName]
    );
    $lookup = [];
    $types = [];
    foreach ($meta as $item) {
        $name = trim((string)row_value_ci($item, 'COLUMN_NAME', reset($item)));
        if ($name !== '') {
            $key = strtoupper($name);
            $lookup[$key] = $name;
            $types[$key] = [
                'type' => strtolower(trim((string)row_value_ci($item, 'DATA_TYPE'))),
                'max_length' => (int)row_value_ci($item, 'CHARACTER_MAXIMUM_LENGTH', 0),
            ];
        }
    }
    $date = $lookup['ALM_NATIVETIMEIN'] ?? '';
    $tagColumn = $lookup['ALM_TAGNAME'] ?? '';
    if ($date === '' || $tagColumn === '') return [];
    $column = static function ($name) { return '[' . str_replace(']', ']]', $name) . ']'; };
    $optional = static function ($lookup, $candidates, $alias) use ($column) {
        foreach ($candidates as $candidate) {
            if (isset($lookup[$candidate])) return $column($lookup[$candidate]) . ' AS [' . $alias . ']';
        }
        return "CAST('' AS nvarchar(1)) AS [" . $alias . ']';
    };
    $fields = [
        $column($date) . ' AS [ALM_NATIVETIMEIN]',
        $column($tagColumn) . ' AS [ALM_TAGNAME]',
        $optional($lookup, ['ALM_TAGDESC','ALM_DESCR','DESCRIPCION'], 'ALM_TAGDESC'),
        $optional($lookup, ['ALM_VALUE','VALOR'], 'ALM_VALUE'),
        $optional($lookup, ['ALM_UNIT','UNIDAD'], 'ALM_UNIT'),
        $optional($lookup, ['ALM_ALMSTATUS','ESTADO'], 'ALM_ALMSTATUS'),
        $optional($lookup, ['ALM_ALMPRIORITY','PRIORIDAD'], 'ALM_ALMPRIORITY'),
    ];
    $tagType = $types[strtoupper($tagColumn)] ?? ['type'=>'', 'max_length'=>0];
    $tagIsIndexable = !in_array($tagType['type'], ['text','ntext','image','xml'], true) && (int)$tagType['max_length'] !== -1;
    // En columnas varchar/nvarchar usamos igualdad directa para permitir un
    // seek por TAG + fecha. La conversión queda solo como compatibilidad LOB.
    $tagPredicate = $tagIsIndexable
        ? $column($tagColumn) . " = ?"
        : "LTRIM(RTRIM(CONVERT(nvarchar(4000), " . $column($tagColumn) . "))) = ?";
    $sql = "SELECT TOP 10000 " . implode(', ', $fields) . " FROM dbo.[" . $tableName . "] " .
           "WHERE " . $tagPredicate . " " .
           "AND " . $column($date) . " >= CONVERT(datetime2, ?, 120) " .
           "AND " . $column($date) . " <= CONVERT(datetime2, ?, 120) ORDER BY " . $column($date) . ' ASC';
    return $db->all($sql, [$tag, $startSql, $endSql]);
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$db = clear_db();
if (!$db->ok()) out(['ok' => false, 'error' => $db->error()], 500);

if ($action === 'save_event_comment') {
    if (!permissions_can('comments.create')) out(['ok'=>false,'error'=>'No tenés permiso para crear comentarios.'],403);
    $tag = clean($_POST['tag'] ?? '');
    $timestamp = clean($_POST['timestamp'] ?? '', 100);
    $comment = trim((string)($_POST['comment'] ?? ''));
    if ($tag === '' || $timestamp === '' || $comment === '') {
        out(['ok'=>false,'error'=>'TAG, fecha de la alarma y comentario son obligatorios.'],400);
    }
    [$ok, $error, $id] = clear_alarm_event_comment_upsert_sql([
        'tag'=>$tag,
        'timestamp'=>$timestamp,
        'comment'=>$comment,
        'user'=>auth_user() ?: 'CLEAR',
    ]);
    if (!$ok) out(['ok'=>false,'error'=>'No se pudo guardar el comentario de la alarma. '.$error],500);
    audit_log('COMENTARIO_ALARMA_SCADA','scada_realtime',json_encode(['tag'=>$tag,'timestamp'=>$timestamp],JSON_UNESCAPED_UNICODE));
    out([
        'ok'=>true,
        'message'=>'Comentario guardado en el histórico de la alarma.',
        'id'=>$id,
        'user'=>auth_user() ?: 'CLEAR',
        'saved_at'=>date('Y-m-d H:i:s'),
    ]);
}

if ($action === 'save_comment') {
    if (!permissions_can('comments.create')) out(['ok'=>false,'error'=>'No tenés permiso para crear comentarios.'],403);
    $key = clean($_POST['event_key'] ?? '', 100);
    $subject = clean($_POST['subject'] ?? ($_POST['tag'] ?? ''));
    $tag = clean($_POST['tag'] ?? '');
    $timestamp = clean($_POST['timestamp'] ?? '', 100);
    $comment = trim((string)($_POST['comment'] ?? ''));

    if ($key === '' || $subject === '' || $comment === '') {
        out(['ok' => false, 'error' => 'El comentario y la alarma o entidad son obligatorios.'], 400);
    }

    [$ok, $error, $id] = clear_alarm_comment_upsert_sql([
        'event_key' => $key,
        'subject' => $subject,
        'tag' => $tag,
        'timestamp' => $timestamp,
        'comment' => $comment,
        'user' => auth_user() ?: 'CLEAR',
    ]);

    if (!$ok) {
        out([
            'ok' => false,
            'error' => 'No se pudo guardar el comentario central en dbo.FIXALARMS_COMENTARIOS.' . ($error ? ' ' . $error : ''),
        ], 500);
    }

    out([
        'ok' => true,
        'message' => 'Comentario central guardado.',
        'id' => $id,
        'user' => auth_user() ?: 'CLEAR',
        'saved_at' => date('Y-m-d H:i:s'),
    ]);
}

if ($action === 'get_comment') {
    if (!permissions_can('comments.view') && !permissions_can('comments.create')) {
        out(['ok' => false, 'error' => 'No tenés permiso para ver comentarios.'], 403);
    }
    $subject = clean($_GET['subject'] ?? ($_GET['tag'] ?? ''));
    if ($subject === '') {
        out(['ok' => false, 'error' => 'La alarma o entidad es obligatoria.'], 400);
    }

    $comment = clear_alarm_comment_load_sql($subject);
    out([
        'ok' => true,
        'found' => !empty($comment),
        'comment' => (string)row_value_ci($comment, 'COMENTARIO'),
        'user' => (string)row_value_ci($comment, 'USUARIO_CARGA'),
        'created_at' => (string)row_value_ci($comment, 'FECHA_CARGA'),
        'updated_at' => (string)row_value_ci($comment, 'FECHA_MODIFICACION', row_value_ci($comment, 'FECHA_CARGA')),
        'id' => row_value_ci($comment, 'ID', null) !== null ? (int)row_value_ci($comment, 'ID') : null,
    ]);
}

$tag = clean($_GET['tag'] ?? '');
$start = clean($_GET['start'] ?? '', 100);
$end = clean($_GET['end'] ?? '', 100);
if ($tag === '' || $start === '' || $end === '') {
    out(['ok' => false, 'error' => 'Faltan parámetros.'], 400);
}

$startSql = normalize_sql_datetime($start);
$endSql = normalize_sql_datetime($end);
if ($startSql === '' || $endSql === '') {
    out(['ok' => false, 'error' => 'El rango de fechas enviado no es válido.'], 400);
}

$rowsAll = [];
try { $rowsAll = alarm_source_rows($db, 'FIXALARMS', $tag, $startSql, $endSql); } catch (Throwable $e) { $rowsAll = []; }

// Histórico exclusivo de dbo.FIXALARMS. El SCADA mantiene esta tabla;
// la aplicación solamente la consulta en modo lectura.
$rows = [];
$seen = [];
foreach ($rowsAll as $row) {
    $dedupeKey = trim((string)row_value_ci($row, 'ALM_NATIVETIMEIN')) . '|' .
                 trim((string)row_value_ci($row, 'ALM_TAGNAME')) . '|' .
                 trim((string)row_value_ci($row, 'ALM_VALUE'));
    if (isset($seen[$dedupeKey])) continue;
    $seen[$dedupeKey] = true;
    $rows[] = $row;
}
usort($rows, function ($a, $b) {
    return strcmp((string)row_value_ci($a, 'ALM_NATIVETIMEIN'), (string)row_value_ci($b, 'ALM_NATIVETIMEIN'));
});

$sqlComments = [];
try { $sqlComments = clear_alarm_event_comments_load_sql($tag, $startSql, $endSql); } catch (Throwable $e) { $sqlComments = []; }
$commentsByTimestamp = [];
foreach ($sqlComments as $commentRow) {
    $commentDate = clear_alarm_event_normalize_datetime($commentRow['FECHA_RECONOCIMIENTO'] ?? '');
    if ($commentDate === '') continue;
    if (!isset($commentsByTimestamp[$commentDate])) {
        $commentsByTimestamp[$commentDate] = $commentRow;
    }
}
$centralComment = [];
try {
    $candidateCentralComment = clear_alarm_comment_load_sql($tag);
    if (strcasecmp(trim((string)row_value_ci($candidateCentralComment, 'MOTIVO')), clear_alarm_comment_reason()) === 0) {
        $centralComment = $candidateCentralComment;
    }
} catch (Throwable $e) { $centralComment = []; }
$preferEventComments = strtolower(trim((string)($_GET['comment_scope'] ?? ''))) === 'event';

$items = [];
foreach ($rows as $row) {
    $timestampRaw = row_value_ci($row, 'ALM_NATIVETIMEIN');
    $timestampSql = normalize_sql_datetime($timestampRaw);
    $timestampIso = sql_datetime_to_iso($timestampRaw);
    if ($timestampIso === '') continue;
    $value = (string)row_value_ci($row, 'ALM_VALUE');
    $key = clear_alarm_event_key($tag, $timestampSql, $value);
    $normalizedTimestamp = clear_alarm_event_normalize_datetime($timestampSql);
    $eventComment = $commentsByTimestamp[$normalizedTimestamp] ?? [];
    $commentRow = $preferEventComments ? ($eventComment ?: $centralComment) : ($centralComment ?: $eventComment);

    // Parseo robusto del valor numérico SQL. Algunos drivers devuelven
    // espacios, coma decimal, unidad anexada o separadores de miles.
    $numericValue = null;
    $rawNumeric = trim((string)$value);
    if ($rawNumeric !== '') {
        $candidate = preg_replace('/\s+/u', '', $rawNumeric);
        if (strpos($candidate, ',') !== false && strpos($candidate, '.') !== false) {
            // Si tiene punto y coma, el último separador se interpreta como decimal.
            if (strrpos($candidate, ',') > strrpos($candidate, '.')) {
                $candidate = str_replace('.', '', $candidate);
                $candidate = str_replace(',', '.', $candidate);
            } else {
                $candidate = str_replace(',', '', $candidate);
            }
        } else {
            $candidate = str_replace(',', '.', $candidate);
        }
        if (!is_numeric($candidate)) {
            if (preg_match('/[-+]?\d+(?:[.,]\d+)?/', $candidate, $m)) {
                $candidate = str_replace(',', '.', $m[0]);
            }
        }
        if (is_numeric($candidate)) $numericValue = (float)$candidate;
    }

    $items[] = [
        'event_key' => $key,
        'timestamp' => $timestampIso,
        'tag' => $tag,
        'description' => (string)row_value_ci($row, 'ALM_TAGDESC'),
        'value' => $value,
        'numeric_value' => $numericValue,
        'unit' => (string)row_value_ci($row, 'ALM_UNIT'),
        'status' => (string)row_value_ci($row, 'ALM_ALMSTATUS'),
        'priority' => (string)row_value_ci($row, 'ALM_ALMPRIORITY'),
        'comment' => (string)($commentRow['COMENTARIO'] ?? ''),
        'user' => (string)($commentRow['USUARIO_CARGA'] ?? ''),
        'updated_at' => (string)($commentRow['FECHA_MODIFICACION'] ?? ($commentRow['FECHA_CARGA'] ?? '')),
        'comment_id' => isset($commentRow['ID']) ? (int)$commentRow['ID'] : null,
    ];
}

$numericCount = 0; foreach ($items as $it) { if ($it['numeric_value'] !== null) $numericCount++; }
out(['ok' => true, 'items' => $items, 'count' => count($items), 'numeric_count' => $numericCount, 'storage' => 'sql', 'source' => 'dbo.FIXALARMS', 'range' => ['start' => $startSql, 'end' => $endSql]]);
