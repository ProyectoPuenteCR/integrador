<?php
/* =============================================================
   pi_tags24h.php — Devuelve (JSON) los tags únicos de las alarmas
   de las últimas 24h, para poblar el combo de PI Histórico.
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

auth_require();

header('Content-Type: application/json; charset=utf-8');

$db = clear_db();
if (!$db->ok()) {
    echo json_encode(['ok' => false, 'tags' => []]);
    exit;
}

try {
    // Tags distintos de la tabla de alarmas 24h, ordenados alfabéticamente.
    $rows = $db->all(
        "SELECT DISTINCT ALM_TAGNAME FROM dbo.FIXALARMS_24H
         WHERE ALM_TAGNAME IS NOT NULL AND ALM_TAGNAME <> ''
         ORDER BY ALM_TAGNAME"
    );
    $tags = [];
    foreach ($rows as $r) {
        $t = trim($r['ALM_TAGNAME']);
        if ($t !== '') $tags[] = $t;
    }
    echo json_encode(['ok' => true, 'tags' => $tags]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'tags' => [], 'error' => $e->getMessage()]);
}
