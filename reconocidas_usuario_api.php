<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/recon_comments.php';

auth_require();
ob_start();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function json_out($data, $code = 200) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clean_text_value($v, $max = 255) {
    $v = trim((string)$v);
    if ($v === '') return '';
    $length = function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
    if ($length > $max) $v = function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    return $v;
}

function first_existing_field(array $row, array $candidates) {
    foreach ($candidates as $field) {
        if (array_key_exists($field, $row)) return $field;
        $up = strtoupper($field);
        foreach ($row as $key => $value) {
            if (strtoupper((string)$key) === $up) return $key;
        }
    }
    return null;
}


set_exception_handler(function ($e) {
    json_out(['ok' => false, 'error' => 'Error interno: ' . $e->getMessage()], 500);
});

$db = clear_db();
if (!$db->ok()) {
    json_out(['ok' => false, 'error' => $db->error() ?: 'Sin conexión a la base de datos.'], 500);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$tag = clean_text_value($_POST['tag'] ?? $_GET['tag'] ?? '', 255);
$operator = clean_text_value($_POST['operator'] ?? $_GET['operator'] ?? '', 255);

if ($action === 'details') {
    if (!permissions_can('comments.view')) json_out(['ok'=>false,'error'=>'No tenés permiso para ver comentarios.'],403);
    if ($tag === '' || $operator === '') json_out(['ok' => false, 'error' => 'Faltan parámetros.'], 400);

    $sql = "SELECT TOP 100 * FROM dbo.FIXALARMS_RECONOCIDAS WHERE TAG_FIX = ? AND OPERADOR = ? ORDER BY ALM_NATIVETIMEIN DESC";
    $rows = $db->all($sql, [$tag, $operator]);
    if (empty($rows)) {
        // fallback por si la vista expone otros nombres de campos o no tiene fecha estándar
        $sql = "SELECT TOP 100 * FROM dbo.FIXALARMS_RECONOCIDAS WHERE TAG_FIX = ? AND OPERADOR = ?";
        $rows = $db->all($sql, [$tag, $operator]);
    }

    $comments = clear_recon_comments_index_by_event($tag, $operator);
    $items = [];
    $timestamps = [];

    foreach ($rows as $row) {
        $dateField = first_existing_field($row, ['ALM_NATIVETIMEIN', 'FECHA_HORA', 'FECHA', 'HORA', 'DATETIME']);
        $descField = first_existing_field($row, ['ALM_DESCR', 'DESCRIPCION', 'DESCRIPCIÓN']);
        $prioField = first_existing_field($row, ['ALM_ALMPRIORITY', 'PRIORIDAD']);
        $statusField = first_existing_field($row, ['ESTADO', 'ALM_VALUE', 'VALOR']);
        $ackField = first_existing_field($row, ['ALARMA_RECONOCIDA', 'RECONOCIDA']);
        $idField = first_existing_field($row, ['ID_RECONOCIMIENTO', 'ID', 'ROW_ID']);

        $idReconocimiento = (int)($idField !== null ? $row[$idField] : 0);
        $eventDate = trim((string)($dateField !== null ? $row[$dateField] : ''));
        $description = trim((string)($descField !== null ? $row[$descField] : ''));
        $priority = trim((string)($prioField !== null ? $row[$prioField] : ''));
        $status = trim((string)($statusField !== null ? $row[$statusField] : ''));
        $ack = trim((string)($ackField !== null ? $row[$ackField] : ''));
        $eventKey = clear_recon_comment_event_key($tag, $operator, $eventDate, $description);
        $commentDateKey = clear_recon_comment_normalize_datetime($eventDate);
        $commentRecord = $commentDateKey !== '' ? ($comments[$commentDateKey] ?? null) : null;

        if ($eventDate !== '') $timestamps[] = $eventDate;

        $items[] = [
            'event_key' => $eventKey,
            'id_reconocimiento' => $idReconocimiento > 0 ? $idReconocimiento : null,
            'event_date' => $eventDate,
            'description' => $description,
            'priority' => $priority,
            'status' => $status,
            'ack_text' => $ack,
            'reason' => (string)($commentRecord['reason'] ?? ''),
            'comment' => (string)($commentRecord['comment'] ?? ''),
            'user' => (string)($commentRecord['user'] ?? ''),
            'created_at' => (string)($commentRecord['created_at'] ?? ''),
            'updated_at' => (string)($commentRecord['updated_at'] ?? ''),
        ];
    }

    $firstDate = '';
    $lastDate = '';
    if (!empty($timestamps)) {
        sort($timestamps);
        $firstDate = $timestamps[0];
        $lastDate = $timestamps[count($timestamps) - 1];
    }

    json_out([
        'ok' => true,
        'summary' => [
            'tag' => $tag,
            'operator' => $operator,
            'total' => count($items),
            'first_date' => $firstDate,
            'last_date' => $lastDate,
        ],
        'items' => $items,
        'reason_options' => [
            'Verificación operativa',
            'Equipo en mantenimiento',
            'Falla conocida',
            'Alarma transitoria',
            'Prueba de instrumentación',
            'Intervención del operador',
            'Condición normalizada',
            'Falsa alarma',
            'Otro',
        ],
    ]);
}

if ($action === 'save_comment') {
    if (!permissions_can('comments.create')) json_out(['ok'=>false,'error'=>'No tenés permiso para crear comentarios.'],403);
    if ($tag === '' || $operator === '') json_out(['ok' => false, 'error' => 'Faltan parámetros.'], 400);
    $eventKey = clean_text_value($_POST['event_key'] ?? '', 100);
    $idReconocimiento = (int)($_POST['id_reconocimiento'] ?? 0);
    $eventDate = clean_text_value($_POST['event_date'] ?? '', 100);
    $description = trim((string)($_POST['description'] ?? ''));
    $priority = clean_text_value($_POST['priority'] ?? '', 100);
    $reason = trim((string)($_POST['reason'] ?? ''));
    $comment = trim((string)($_POST['comment'] ?? ''));
    if ($eventKey === '' || $reason === '' || $comment === '') json_out(['ok' => false, 'error' => 'Motivo y comentario son obligatorios.'], 400);

    $commentLength = function_exists('mb_strlen') ? mb_strlen($comment, 'UTF-8') : strlen($comment);
    if ($reason === 'Otro' && $commentLength < 5) {
        json_out(['ok' => false, 'error' => 'Cuando el motivo es "Otro", describí un poco más el comentario.'], 400);
    }

    $ok = clear_recon_comment_upsert([
        'event_key' => $eventKey,
        'id_reconocimiento' => $idReconocimiento > 0 ? $idReconocimiento : null,
        'tag' => $tag,
        'operator' => $operator,
        'event_date' => $eventDate,
        'description' => $description,
        'priority' => $priority,
        'reason' => $reason,
        'comment' => $comment,
        'user' => auth_user() ?: 'CLEAR',
    ]);

    if (!$ok) json_out(['ok' => false, 'error' => 'No se pudo guardar el comentario en dbo.FIXALARMS_COMENTARIOS. ' . $db->error()], 500);

    $counts = clear_recon_comment_counts_by_group();
    $groupCount = (int)($counts[clear_recon_comment_group_key($tag, $operator)] ?? 0);
    json_out([
        'ok' => true,
        'message' => 'Comentario guardado correctamente.',
        'comment_count' => $groupCount,
        'user' => auth_user() ?: 'CLEAR',
        'saved_at' => date('Y-m-d H:i:s'),
    ]);
}

json_out(['ok' => false, 'error' => 'Acción inválida.'], 400);
