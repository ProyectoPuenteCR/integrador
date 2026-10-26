<?php
// Caché PI independiente: ninguna petición web consulta PI ni recalcula alarmas.
function tecss_vib_pi_ready($db)
{
    return $db->ok() && (int)$db->scalar("SELECT CASE WHEN " .
        "OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_PI_EVENTO',N'U') IS NOT NULL AND " .
        "OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_PI_SYNC',N'U') IS NOT NULL THEN 1 ELSE 0 END") === 1;
}

function tecss_vib_pi_sync($db)
{
    if (!tecss_vib_pi_ready($db)) return ['available'=>false, 'reason'=>'Sin sincronizar PI'];
    $rows = $db->all("SELECT *,DATEDIFF(second, HASTA, SYSDATETIME()) AS EDAD_SEG " .
        "FROM dbo.CLEAR_TECSS_VIBRACIONES_PI_SYNC WHERE ID=1");
    if (!$rows || $db->error()) return ['available'=>false, 'reason'=>'Sin sincronizar PI'];
    $row = $rows[0];
    return ['available'=>true, 'stale'=>(int)$row['EDAD_SEG'] > 1800 || (int)$row['EDAD_SEG'] < -60 || (int)$row['ULTIMO_ERROR'] === 1,
        'synced_at'=>tecss_vib_text($row['ULTIMO_OK']), 'from'=>tecss_vib_text($row['DESDE']),
        'through'=>tecss_vib_text($row['HASTA'])];
}

function tecss_vib_pi_overview($db, array $wells, array $sync)
{
    if (empty($sync['available']) || !$wells) return [];
    if (count($wells) > 500) {
        $map = [];
        foreach (array_chunk($wells, 500) as $chunk) $map += tecss_vib_pi_overview($db, $chunk, $sync);
        return $map;
    }
    $marks = implode(',', array_fill(0, count($wells), '?'));
    $rows = $db->all("SELECT POZO,SUM(CASE WHEN FIN IS NULL THEN 1 ELSE 0 END) AS ABIERTOS," .
        "SUM(CASE WHEN INICIO>=DATEADD(hour,-48,SYSDATETIME()) THEN 1 ELSE 0 END) AS EVENTOS_48H," .
        "MAX(INICIO) AS ULTIMO_EVENTO FROM dbo.CLEAR_TECSS_VIBRACIONES_PI_EVENTO " .
        "WHERE POZO IN ($marks) GROUP BY POZO", $wells);
    if ($db->error()) throw new RuntimeException('No se pudo leer el resumen PI.');
    $map = [];
    foreach ($rows as $row) $map[$row['POZO']] = $row;
    return $map;
}

function tecss_vib_pi_label(array $sync, array $row = [])
{
    if (empty($sync['available'])) return 'Sin sincronizar PI';
    if (!empty($sync['stale'])) return 'PI pendiente de actualizar';
    if ((int)($row['ABIERTOS'] ?? 0) > 0) return 'Evento PI abierto';
    if ((int)($row['EVENTOS_48H'] ?? 0) > 0) return 'Evento PI en últimas 48 h';
    if (strtotime($sync['from']) > time()-48*3600) return 'Cobertura PI menor a 48 h';
    return 'Sin eventos PI en 48 h';
}

function tecss_vib_pi_detail($db, $well, $from, $to)
{
    $sync = tecss_vib_pi_sync($db);
    if (empty($sync['available'])) return ['sync'=>$sync, 'events'=>[]];
    $rows = $db->all("SELECT TOP (1001) WEBID,NOMBRE,INICIO,FIN,VIBR_INICIO,UMBRAL_INICIO," .
        "ENVIO_REGISTRADO,ANOTACIONES_LEIDAS FROM dbo.CLEAR_TECSS_VIBRACIONES_PI_EVENTO " .
        "WHERE POZO=? AND INICIO<? AND (FIN IS NULL OR FIN>=?) ORDER BY INICIO DESC", [$well,$to,$from]);
    if ($db->error()) return ['sync'=>['available'=>false,'reason'=>'Error al consultar los eventos PI'], 'events'=>[]];
    $truncated = count($rows) > 1000;
    $events = [];
    foreach (array_slice($rows, 0, 1000) as $row) {
        $events[] = ['id'=>$row['WEBID'], 'name'=>$row['NOMBRE'],
            'start'=>tecss_vib_text($row['INICIO']), 'end'=>tecss_vib_text($row['FIN']),
            'vibration_start'=>is_numeric($row['VIBR_INICIO']) ? (float)$row['VIBR_INICIO'] : null,
            'threshold_start'=>is_numeric($row['UMBRAL_INICIO']) ? (float)$row['UMBRAL_INICIO'] : null,
            'notification_at'=>tecss_vib_text($row['ENVIO_REGISTRADO']),
            'annotations_read'=>(int)$row['ANOTACIONES_LEIDAS'] === 1];
    }
    $sync['covers_range'] = strtotime($sync['from']) <= strtotime($from) &&
        strtotime($sync['through']) >= min(time()-60, strtotime($to));
    return ['sync'=>$sync, 'events'=>$events, 'truncated'=>$truncated];
}
