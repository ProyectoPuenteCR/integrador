<?php
/* Comentarios de alarmas.
   dbo.FIXALARMS_COMENTARIOS es el repositorio único. Los registros con
   MOTIVO = "Comentario alarma centralizado" son compartidos por todas las
   grillas; los comentarios históricos por evento se conservan como respaldo. */

function clear_alarm_comment_reason()
{
    return 'Comentario alarma centralizado';
}

function clear_alarm_comment_subject($value, $type = 'tag')
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $type = strtolower(trim((string)$type));
    if ($type === '' || $type === 'tag') return substr($value, 0, 255);
    $prefixes = ['pozo' => 'POZO:', 'instalacion' => 'INSTALACION:', 'entidad' => 'ENTIDAD:'];
    $prefix = $prefixes[$type] ?? (strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $type)) . ':');
    return substr($prefix . $value, 0, 255);
}

function clear_alarm_comment_load_sql($subject)
{
    $db = clear_db();
    $subject = trim((string)$subject);
    if (!$db->ok() || $subject === '') return [];

    $reason = clear_alarm_comment_reason();
    $row = $db->all(
        "SELECT TOP 1 ID,TAG_FIX,OPERADOR,FECHA_RECONOCIMIENTO,MOTIVO,COMENTARIO," .
        "USUARIO_CARGA,FECHA_CARGA,FECHA_MODIFICACION,ACTIVO " .
        "FROM dbo.FIXALARMS_COMENTARIOS WHERE ACTIVO=1 AND TAG_FIX=? " .
        "AND MOTIVO=? ORDER BY COALESCE(FECHA_MODIFICACION,FECHA_CARGA) DESC,ID DESC",
        [$subject, $reason]
    );
    if ($row) return $row[0];

    /* Compatibilidad: un TAG sin comentario central muestra su comentario de
       evento más reciente. Al guardarlo se crea el registro compartido. */
    if (strpos($subject, ':') === false) {
        $legacy = $db->all(
            "SELECT TOP 1 ID,TAG_FIX,OPERADOR,FECHA_RECONOCIMIENTO,MOTIVO,COMENTARIO," .
            "USUARIO_CARGA,FECHA_CARGA,FECHA_MODIFICACION,ACTIVO " .
            "FROM dbo.FIXALARMS_COMENTARIOS WHERE ACTIVO=1 AND TAG_FIX=? " .
            "AND MOTIVO=N'Comentario PI Histórico' " .
            "ORDER BY COALESCE(FECHA_MODIFICACION,FECHA_CARGA) DESC,ID DESC",
            [$subject]
        );
        if ($legacy) return $legacy[0];
    }

    /* Compatibilidad con los comentarios semanales previos. Se leen desde su
       tabla de origen hasta que el usuario los guarde en el registro central. */
    $legacyEntities = [
        'POZO:' => ['FIXALARMS_POZO_COMENTARIOS_SEMANALES', 'POZO'],
        'INSTALACION:' => ['FIXALARMS_INSTALACION_COMENTARIOS_SEMANALES', 'INSTALACION'],
    ];
    foreach ($legacyEntities as $prefix => $legacyConfig) {
        if (stripos($subject, $prefix) !== 0) continue;
        [$legacyTable, $legacyColumn] = $legacyConfig;
        if ((int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.$legacyTable',N'U') IS NULL THEN 0 ELSE 1 END") !== 1) break;
        $entity = substr($subject, strlen($prefix));
        $legacy = $db->all(
            "SELECT TOP 1 ID,$legacyColumn AS TAG_FIX,N'LEGADO_SEMANAL' AS OPERADOR," .
            "SEMANA_DESDE AS FECHA_RECONOCIMIENTO,N'Comentario semanal legado' AS MOTIVO," .
            "COMENTARIO,COALESCE(USUARIO_MODIFICACION,USUARIO_CARGA) AS USUARIO_CARGA," .
            "FECHA_CARGA,FECHA_MODIFICACION,ACTIVO FROM dbo.$legacyTable " .
            "WHERE ACTIVO=1 AND $legacyColumn=? ORDER BY SEMANA_DESDE DESC,ID DESC",
            [$entity]
        );
        if ($legacy) return $legacy[0];
        break;
    }
    return [];
}

function clear_alarm_comments_load_subjects_sql(array $subjects)
{
    $db = clear_db();
    if (!$db->ok()) return [];
    $clean = [];
    foreach ($subjects as $subject) {
        $subject = trim((string)$subject);
        if ($subject !== '') $clean[strtoupper($subject)] = $subject;
    }
    if (!$clean) return [];

    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    $reason = clear_alarm_comment_reason();
    $params = array_merge([$reason], array_values($clean));
    $rows = $db->all(
        "SELECT TAG_FIX,MOTIVO,COMENTARIO,USUARIO_CARGA,FECHA_CARGA,FECHA_MODIFICACION,ID " .
        "FROM dbo.FIXALARMS_COMENTARIOS WHERE ACTIVO=1 " .
        "AND (MOTIVO=? OR MOTIVO=N'Comentario PI Histórico') AND TAG_FIX IN ($placeholders) " .
        "ORDER BY CASE WHEN MOTIVO=? THEN 0 ELSE 1 END," .
        "COALESCE(FECHA_MODIFICACION,FECHA_CARGA) DESC,ID DESC",
        array_merge($params, [$reason])
    );
    $result = [];
    foreach ($rows as $row) {
        $key = strtoupper(trim((string)($row['TAG_FIX'] ?? $row['tag_fix'] ?? '')));
        if ($key !== '' && !isset($result[$key])) $result[$key] = $row;
    }

    $legacyGroups = [
        'POZO:' => ['FIXALARMS_POZO_COMENTARIOS_SEMANALES', 'POZO'],
        'INSTALACION:' => ['FIXALARMS_INSTALACION_COMENTARIOS_SEMANALES', 'INSTALACION'],
    ];
    foreach ($legacyGroups as $prefix => $legacyConfig) {
        [$legacyTable, $legacyColumn] = $legacyConfig;
        $entities = [];
        foreach ($clean as $upperSubject => $originalSubject) {
            if (!isset($result[$upperSubject]) && strpos($upperSubject, $prefix) === 0) {
                $entities[substr($originalSubject, strlen($prefix))] = true;
            }
        }
        if (!$entities || (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.$legacyTable',N'U') IS NULL THEN 0 ELSE 1 END") !== 1) continue;
        $legacyPlaceholders = implode(',', array_fill(0, count($entities), '?'));
        foreach ($db->all(
            "SELECT $legacyColumn AS TAG_FIX,COMENTARIO," .
            "COALESCE(USUARIO_MODIFICACION,USUARIO_CARGA) AS USUARIO_CARGA," .
            "FECHA_CARGA,FECHA_MODIFICACION,ID FROM dbo.$legacyTable " .
            "WHERE ACTIVO=1 AND $legacyColumn IN ($legacyPlaceholders) " .
            "ORDER BY SEMANA_DESDE DESC,ID DESC",
            array_keys($entities)
        ) as $legacyRow) {
            $entityKey = $prefix . strtoupper(trim((string)($legacyRow['TAG_FIX'] ?? $legacyRow['tag_fix'] ?? '')));
            if ($entityKey !== '' && !isset($result[$entityKey])) $result[$entityKey] = $legacyRow;
        }
    }
    return $result;
}

function clear_alarm_comment_upsert_sql(array $payload)
{
    $db = clear_db();
    if (!$db->ok()) return [false, $db->error(), null];
    $subject = trim((string)($payload['subject'] ?? $payload['tag'] ?? ''));
    $comment = trim((string)($payload['comment'] ?? ''));
    $user = trim((string)($payload['user'] ?? 'CLEAR'));
    if ($subject === '' || $comment === '') return [false, 'La alarma o entidad y el comentario son obligatorios.', null];
    $subject = substr($subject, 0, 255);
    $reason = clear_alarm_comment_reason();

    $existingId = $db->scalar(
        "SELECT TOP 1 ID FROM dbo.FIXALARMS_COMENTARIOS " .
        "WHERE TAG_FIX=? AND MOTIVO=? AND ACTIVO=1 ORDER BY ID DESC",
        [$subject, $reason]
    );
    if ($existingId !== null && $existingId !== '') {
        $ok = $db->execute(
            "UPDATE dbo.FIXALARMS_COMENTARIOS SET COMENTARIO=?,USUARIO_CARGA=?," .
            "FECHA_MODIFICACION=GETDATE(),ACTIVO=1 WHERE ID=?",
            [$comment, $user, (int)$existingId]
        );
        return [$ok, $ok ? '' : $db->error(), (int)$existingId];
    }

    $sql = "INSERT INTO dbo.FIXALARMS_COMENTARIOS " .
           "(ID_RECONOCIMIENTO,TAG_FIX,OPERADOR,FECHA_RECONOCIMIENTO,MOTIVO,COMENTARIO," .
           "USUARIO_CARGA,FECHA_CARGA,FECHA_MODIFICACION,ACTIVO) " .
           "VALUES (%s,?,'ALARMA_CENTRAL',GETDATE(),?,?,?,GETDATE(),NULL,1)";
    $params = [$subject, $reason, $comment, $user];
    $ok = $db->execute(sprintf($sql, 'NULL'), $params);
    if (!$ok) $ok = $db->execute(sprintf($sql, '0'), $params);
    if (!$ok) return [false, $db->error(), null];
    $newId = $db->scalar(
        "SELECT TOP 1 ID FROM dbo.FIXALARMS_COMENTARIOS WHERE TAG_FIX=? AND MOTIVO=? AND ACTIVO=1 ORDER BY ID DESC",
        [$subject, $reason]
    );
    return [true, '', $newId !== null ? (int)$newId : null];
}

function clear_alarm_event_key($tag, $timestamp, $value = '')
{
    return sha1(strtoupper(trim((string)$tag)) . '|' . trim((string)$timestamp) . '|' . trim((string)$value));
}

function clear_alarm_event_normalize_datetime($value)
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $formats = [
        'Y-m-d H:i:s.v', 'Y-m-d H:i:s.u', 'Y-m-d H:i:s',
        'Y-m-d\TH:i:s.v', 'Y-m-d\TH:i:s.u', 'Y-m-d\TH:i:s',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'j/n/Y H:i:s', 'j/n/Y H:i',
    ];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat('!' . $format, $value);
        $errors = DateTime::getLastErrors();
        $valid = $dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
        if ($valid) return $dt->format('Y-m-d H:i:s');
    }
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return '';
    }
}

function clear_alarm_event_comments_load_sql($tag, $start = '', $end = '')
{
    $db = clear_db();
    if (!$db->ok()) return [];

    $conditions = [
        "TAG_FIX = ?",
        "ACTIVO = 1",
        "MOTIVO = 'Comentario PI Histórico'",
    ];
    $params = [trim((string)$tag)];

    if (trim((string)$start) !== '') {
        $conditions[] = 'FECHA_RECONOCIMIENTO >= CONVERT(datetime2(0), ?, 121)';
        $params[] = clear_alarm_event_normalize_datetime($start);
    }
    if (trim((string)$end) !== '') {
        $conditions[] = 'FECHA_RECONOCIMIENTO <= CONVERT(datetime2(0), ?, 121)';
        $params[] = clear_alarm_event_normalize_datetime($end);
    }

    $sql = "SELECT ID, TAG_FIX, OPERADOR, FECHA_RECONOCIMIENTO, MOTIVO, COMENTARIO, " .
           "USUARIO_CARGA, FECHA_CARGA, FECHA_MODIFICACION, ACTIVO " .
           "FROM dbo.FIXALARMS_COMENTARIOS WHERE " . implode(' AND ', $conditions) .
           " ORDER BY COALESCE(FECHA_MODIFICACION, FECHA_CARGA) DESC";

    return $db->all($sql, $params);
}

function clear_alarm_event_comment_upsert_sql(array $payload)
{
    $db = clear_db();
    if (!$db->ok()) return [false, $db->error()];

    $tag = trim((string)($payload['tag'] ?? ''));
    $timestamp = clear_alarm_event_normalize_datetime($payload['timestamp'] ?? '');
    $comment = trim((string)($payload['comment'] ?? ''));
    $user = trim((string)($payload['user'] ?? 'CLEAR'));
    if ($tag === '' || $timestamp === '' || $comment === '') {
        return [false, 'TAG, fecha y comentario son obligatorios.'];
    }

    $existingId = $db->scalar(
        "SELECT TOP 1 ID FROM dbo.FIXALARMS_COMENTARIOS " .
        "WHERE TAG_FIX = ? AND FECHA_RECONOCIMIENTO = CONVERT(datetime2(0), ?, 121) " .
        "AND MOTIVO = 'Comentario PI Histórico' AND ACTIVO = 1 " .
        "ORDER BY ID DESC",
        [$tag, $timestamp]
    );

    if ($existingId !== null && $existingId !== '') {
        $ok = $db->execute(
            "UPDATE dbo.FIXALARMS_COMENTARIOS SET " .
            "COMENTARIO = ?, USUARIO_CARGA = ?, FECHA_MODIFICACION = GETDATE(), ACTIVO = 1 " .
            "WHERE ID = ?",
            [$comment, $user, (int)$existingId]
        );
        return [$ok, $ok ? '' : $db->error(), (int)$existingId];
    }

    $ok = $db->execute(
        "INSERT INTO dbo.FIXALARMS_COMENTARIOS " .
        "(ID_RECONOCIMIENTO, TAG_FIX, OPERADOR, FECHA_RECONOCIMIENTO, MOTIVO, COMENTARIO, " .
        "USUARIO_CARGA, FECHA_CARGA, FECHA_MODIFICACION, ACTIVO) " .
        "VALUES (NULL, ?, 'PI_HISTORICO', CONVERT(datetime2(0), ?, 121), 'Comentario PI Histórico', ?, ?, GETDATE(), NULL, 1)",
        [$tag, $timestamp, $comment, $user]
    );

    // Compatibilidad con instalaciones donde ID_RECONOCIMIENTO se creó como NOT NULL.
    if (!$ok) {
        $firstError = $db->error();
        $ok = $db->execute(
            "INSERT INTO dbo.FIXALARMS_COMENTARIOS " .
            "(ID_RECONOCIMIENTO, TAG_FIX, OPERADOR, FECHA_RECONOCIMIENTO, MOTIVO, COMENTARIO, " .
            "USUARIO_CARGA, FECHA_CARGA, FECHA_MODIFICACION, ACTIVO) " .
            "VALUES (0, ?, 'PI_HISTORICO', CONVERT(datetime2(0), ?, 121), 'Comentario PI Histórico', ?, ?, GETDATE(), NULL, 1)",
            [$tag, $timestamp, $comment, $user]
        );
        if (!$ok) return [false, $firstError . ' | ' . $db->error(), null];
    }

    $newId = $db->scalar(
        "SELECT TOP 1 ID FROM dbo.FIXALARMS_COMENTARIOS " .
        "WHERE TAG_FIX = ? AND FECHA_RECONOCIMIENTO = CONVERT(datetime2(0), ?, 121) " .
        "AND MOTIVO = 'Comentario PI Histórico' AND ACTIVO = 1 ORDER BY ID DESC",
        [$tag, $timestamp]
    );
    return [true, '', $newId !== null ? (int)$newId : null];
}
