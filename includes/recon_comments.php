<?php
/* =============================================================
   Comentarios de reconocimientos — almacenamiento EXCLUSIVO SQL
   Tabla: dbo.FIXALARMS_COMENTARIOS
============================================================= */

function clear_recon_comment_group_key($tag, $operator)
{
    $tagValue = trim((string)$tag);
    $operatorValue = trim((string)$operator);
    $tagValue = function_exists('mb_strtoupper') ? mb_strtoupper($tagValue, 'UTF-8') : strtoupper($tagValue);
    $operatorValue = function_exists('mb_strtoupper') ? mb_strtoupper($operatorValue, 'UTF-8') : strtoupper($operatorValue);
    return $tagValue . '|' . $operatorValue;
}

function clear_recon_comment_normalize_datetime($value)
{
    $value = trim((string)$value);
    if ($value === '') return '';

    // SQL Server puede devolver fechas en ISO o la interfaz puede enviarlas como dd/MM/yyyy.
    // Convertimos siempre a un formato ISO inequívoco antes de enviarlo a SQL Server.
    $formats = [
        'Y-m-d H:i:s.v',
        'Y-m-d H:i:s.u',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:s.v',
        'Y-m-d\TH:i:s.u',
        'Y-m-d\TH:i:s',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'j/n/Y H:i:s',
        'j/n/Y H:i',
        'd-m-Y H:i:s',
        'd-m-Y H:i',
    ];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat('!' . $format, $value);
        $errors = DateTime::getLastErrors();
        $valid = $dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
        if ($valid) return $dt->format('Y-m-d H:i:s');
    }

    // Último intento para valores que incluyan zona horaria u otros sufijos ISO.
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return '';
    }
}

function clear_recon_comment_event_key($tag, $operator, $eventDate, $description = '')
{
    return sha1(
        clear_recon_comment_group_key($tag, $operator) . '|' .
        clear_recon_comment_normalize_datetime($eventDate) . '|' .
        trim((string)$description)
    );
}

function clear_recon_comment_map_sql_row(array $row)
{
    $date = (string)($row['FECHA_RECONOCIMIENTO'] ?? $row['fecha_reconocimiento'] ?? '');
    $created = (string)($row['FECHA_CARGA'] ?? $row['fecha_carga'] ?? '');
    $updated = (string)($row['FECHA_MODIFICACION'] ?? $row['fecha_modificacion'] ?? '');
    $tag = (string)($row['TAG_FIX'] ?? $row['tag_fix'] ?? '');
    $operator = (string)($row['OPERADOR'] ?? $row['operador'] ?? '');
    $idRecon = $row['ID_RECONOCIMIENTO'] ?? $row['id_reconocimiento'] ?? null;
    $description = (string)($row['DESCRIPCION_ALARMA'] ?? $row['descripcion_alarma'] ?? $row['ALM_DESCR'] ?? $row['alm_descr'] ?? '');

    return [
        'id' => (int)($row['ID'] ?? $row['id'] ?? 0),
        'id_reconocimiento' => ($idRecon === '' || $idRecon === null) ? null : (int)$idRecon,
        'event_key' => clear_recon_comment_event_key($tag, $operator, $date, ''),
        'tag' => $tag,
        'operator' => $operator,
        'event_date' => $date,
        'description' => $description,
        'priority' => '',
        'reason' => (string)($row['MOTIVO'] ?? $row['motivo'] ?? ''),
        'comment' => (string)($row['COMENTARIO'] ?? $row['comentario'] ?? ''),
        'user' => (string)($row['USUARIO_CARGA'] ?? $row['usuario_carga'] ?? ''),
        'created_at' => $created,
        'updated_at' => $updated,
        'active' => (int)($row['ACTIVO'] ?? $row['activo'] ?? 1),
    ];
}

/* Devuelve TODOS los comentarios activos para Dashboard y exportación. */
function clear_recon_comments_load_all()
{
    $db = clear_db();
    if (!$db->ok()) return [];

    $rows = $db->all(
        "SELECT C.ID, C.ID_RECONOCIMIENTO, C.TAG_FIX, C.OPERADOR, " .
        "CONVERT(varchar(19), C.FECHA_RECONOCIMIENTO, 120) AS FECHA_RECONOCIMIENTO, " .
        "C.MOTIVO, C.COMENTARIO, C.USUARIO_CARGA, " .
        "CONVERT(varchar(19), C.FECHA_CARGA, 120) AS FECHA_CARGA, " .
        "CONVERT(varchar(19), C.FECHA_MODIFICACION, 120) AS FECHA_MODIFICACION, C.ACTIVO, " .
        "COALESCE(A.ALM_DESCR, '') AS DESCRIPCION_ALARMA " .
        "FROM dbo.FIXALARMS_COMENTARIOS C " .
        "OUTER APPLY (SELECT TOP 1 CONVERT(nvarchar(1000), F.ALM_DESCR) AS ALM_DESCR " .
        " FROM dbo.FIXALARMS F WHERE LTRIM(RTRIM(CONVERT(nvarchar(255),F.ALM_TAGNAME))) = LTRIM(RTRIM(C.TAG_FIX)) " .
        " ORDER BY ABS(DATEDIFF(second, F.ALM_NATIVETIMEIN, C.FECHA_RECONOCIMIENTO))) A " .
        "WHERE C.ACTIVO = 1 AND C.COMENTARIO IS NOT NULL AND LTRIM(RTRIM(C.COMENTARIO)) <> '' " .
        "ORDER BY COALESCE(C.FECHA_MODIFICACION, C.FECHA_CARGA) DESC, C.ID DESC"
    );

    $result = [];
    foreach ($rows as $row) $result[] = clear_recon_comment_map_sql_row($row);
    return $result;
}

function clear_recon_comment_counts_by_group()
{
    $db = clear_db();
    if (!$db->ok()) return [];

    $rows = $db->all(
        "SELECT TAG_FIX, OPERADOR, COUNT(*) AS TOTAL " .
        "FROM dbo.FIXALARMS_COMENTARIOS " .
        "WHERE ACTIVO = 1 AND COMENTARIO IS NOT NULL AND LTRIM(RTRIM(COMENTARIO)) <> '' " .
        "GROUP BY TAG_FIX, OPERADOR"
    );

    $counts = [];
    foreach ($rows as $row) {
        $tag = (string)($row['TAG_FIX'] ?? '');
        $operator = (string)($row['OPERADOR'] ?? '');
        $total = (int)($row['TOTAL'] ?? 0);
        $counts[clear_recon_comment_group_key($tag, $operator)] = $total;
    }
    return $counts;
}

/* Índice de comentarios para un TAG + operador. Se indexa por fecha normalizada. */
function clear_recon_comments_index_by_event($tag, $operator)
{
    $db = clear_db();
    if (!$db->ok()) return [];

    $rows = $db->all(
        "SELECT ID, ID_RECONOCIMIENTO, TAG_FIX, OPERADOR, " .
        "CONVERT(varchar(19), FECHA_RECONOCIMIENTO, 120) AS FECHA_RECONOCIMIENTO, " .
        "MOTIVO, COMENTARIO, USUARIO_CARGA, " .
        "CONVERT(varchar(19), FECHA_CARGA, 120) AS FECHA_CARGA, " .
        "CONVERT(varchar(19), FECHA_MODIFICACION, 120) AS FECHA_MODIFICACION, ACTIVO " .
        "FROM dbo.FIXALARMS_COMENTARIOS " .
        "WHERE TAG_FIX = ? AND OPERADOR = ? AND ACTIVO = 1 " .
        "ORDER BY COALESCE(FECHA_MODIFICACION, FECHA_CARGA) DESC, ID DESC",
        [trim((string)$tag), trim((string)$operator)]
    );

    $indexed = [];
    foreach ($rows as $row) {
        $item = clear_recon_comment_map_sql_row($row);
        $dateKey = clear_recon_comment_normalize_datetime($item['event_date']);
        if ($dateKey !== '' && !isset($indexed[$dateKey])) $indexed[$dateKey] = $item;
        if ($item['id_reconocimiento'] !== null) $indexed['id:' . $item['id_reconocimiento']] = $item;
    }
    return $indexed;
}

/* INSERT/UPDATE de comentarios de Reconocidas y Reconocidas por usuario. */
function clear_recon_comment_upsert(array $payload)
{
    $db = clear_db();
    if (!$db->ok()) return false;

    $idRecon = $payload['id_reconocimiento'] ?? null;
    $tag = trim((string)($payload['tag'] ?? ''));
    $operator = trim((string)($payload['operator'] ?? ''));
    $eventDate = clear_recon_comment_normalize_datetime($payload['event_date'] ?? '');
    $reason = trim((string)($payload['reason'] ?? ''));
    $comment = trim((string)($payload['comment'] ?? ''));
    $user = trim((string)($payload['user'] ?? 'CLEAR'));

    if ($tag === '' || $operator === '' || $eventDate === '' || $reason === '' || $comment === '') return false;

    $existingId = null;
    if ($idRecon !== null && $idRecon !== '' && (int)$idRecon > 0) {
        $existingId = $db->scalar(
            "SELECT TOP 1 ID FROM dbo.FIXALARMS_COMENTARIOS " .
            "WHERE ID_RECONOCIMIENTO = ? AND ACTIVO = 1 ORDER BY ID DESC",
            [(int)$idRecon]
        );
    }
    if ($existingId === null || $existingId === '') {
        $existingId = $db->scalar(
            "SELECT TOP 1 ID FROM dbo.FIXALARMS_COMENTARIOS " .
            "WHERE TAG_FIX = ? AND OPERADOR = ? AND FECHA_RECONOCIMIENTO = CONVERT(datetime2(0), ?, 121) AND ACTIVO = 1 " .
            "ORDER BY ID DESC",
            [$tag, $operator, $eventDate]
        );
    }

    if ($existingId !== null && $existingId !== '') {
        return $db->execute(
            "UPDATE dbo.FIXALARMS_COMENTARIOS SET " .
            "ID_RECONOCIMIENTO = ?, TAG_FIX = ?, OPERADOR = ?, FECHA_RECONOCIMIENTO = CONVERT(datetime2(0), ?, 121), " .
            "MOTIVO = ?, COMENTARIO = ?, USUARIO_CARGA = ?, FECHA_MODIFICACION = GETDATE(), ACTIVO = 1 " .
            "WHERE ID = ?",
            [($idRecon !== null && $idRecon !== '' ? (int)$idRecon : null), $tag, $operator, $eventDate, $reason, $comment, $user, (int)$existingId]
        );
    }

    $ok = $db->execute(
        "INSERT INTO dbo.FIXALARMS_COMENTARIOS " .
        "(ID_RECONOCIMIENTO, TAG_FIX, OPERADOR, FECHA_RECONOCIMIENTO, MOTIVO, COMENTARIO, " .
        "USUARIO_CARGA, FECHA_CARGA, FECHA_MODIFICACION, ACTIVO) " .
        "VALUES (?, ?, ?, CONVERT(datetime2(0), ?, 121), ?, ?, ?, GETDATE(), NULL, 1)",
        [($idRecon !== null && $idRecon !== '' ? (int)$idRecon : null), $tag, $operator, $eventDate, $reason, $comment, $user]
    );

    // Compatibilidad si ID_RECONOCIMIENTO está definido NOT NULL.
    if (!$ok && ($idRecon === null || $idRecon === '')) {
        $ok = $db->execute(
            "INSERT INTO dbo.FIXALARMS_COMENTARIOS " .
            "(ID_RECONOCIMIENTO, TAG_FIX, OPERADOR, FECHA_RECONOCIMIENTO, MOTIVO, COMENTARIO, " .
            "USUARIO_CARGA, FECHA_CARGA, FECHA_MODIFICACION, ACTIVO) " .
            "VALUES (0, ?, ?, CONVERT(datetime2(0), ?, 121), ?, ?, ?, GETDATE(), NULL, 1)",
            [$tag, $operator, $eventDate, $reason, $comment, $user]
        );
    }
    return $ok;
}


/* Borrado lógico de un comentario. Conserva el registro para auditoría. */
function clear_recon_comment_disable($id)
{
    $db = clear_db();
    if (!$db->ok()) return false;
    $id = (int)$id;
    if ($id <= 0) return false;
    return $db->execute(
        "UPDATE dbo.FIXALARMS_COMENTARIOS SET ACTIVO = 0, FECHA_MODIFICACION = GETDATE() WHERE ID = ? AND ACTIVO = 1",
        [$id]
    );
}
