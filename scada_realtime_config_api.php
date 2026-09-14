<?php
ob_start();
ini_set('display_errors', '0');

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/appconfig.php';
require_once __DIR__ . '/includes/scada_realtime.php';

auth_require_admin();
permissions_require_menu('config_scada_realtime');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function scada_cfg_out($payload, $status = 200)
{
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function scada_cfg_pi_get($path)
{
    $pi = pi_config();
    $base = rtrim((string)($pi['base'] ?? ''), '/');
    if ($base === '' || trim((string)($pi['ds_webid'] ?? '')) === '') {
        return [false, null, 'PI Web API o el Data Server WebID no están configurados.'];
    }
    $url = $base . '/' . ltrim($path, '/');
    $auth = pi_auth_header();
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $headers = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'];
        if ($auth !== '') $headers[] = 'Authorization: ' . $auth;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            return [false, null, $error !== '' ? $error : ('PI Web API respondió HTTP ' . $status . '.')];
        }
    } else {
        $context = stream_context_create([
            'ssl'=>['verify_peer'=>false, 'verify_peer_name'=>false],
            'http'=>[
                'method'=>'GET',
                'header'=>($auth !== '' ? "Authorization: $auth\r\n" : '') . "Accept: application/json\r\n",
                'timeout'=>20,
                'ignore_errors'=>true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) return [false, null, 'No se pudo conectar a PI Web API.'];
    }
    $json = json_decode((string)$body, true);
    return is_array($json) ? [true, $json, ''] : [false, null, 'PI devolvió una respuesta no válida.'];
}

function scada_cfg_resolve_pi($tag)
{
    $tag = clear_scada_normalize_tag($tag);
    $stagePoint = clear_scada_pi_stage_point(clear_db(), $tag);
    if ($stagePoint && !empty($stagePoint['WebId'])) return [true, $stagePoint, ''];

    $pi = pi_config();
    $ds = trim((string)($pi['ds_webid'] ?? ''));
    $path = '/dataservers/' . rawurlencode($ds) . '/points?nameFilter=' . rawurlencode('*' . $tag . '*') .
            '&maxCount=20&selectedFields=Items.WebId;Items.Name;Items.Descriptor;Items.EngineeringUnits;Items.Path';
    [$ok, $data, $error] = scada_cfg_pi_get($path);
    if (!$ok) return [false, null, $error];
    $items = isset($data['Items']) && is_array($data['Items']) ? $data['Items'] : [];
    foreach ($items as $item) {
        $name = clear_scada_normalize_tag($item['Name'] ?? '', 512);
        $upperName = strtoupper($name);
        $upperTag = strtoupper($tag);
        if ($upperName === $upperTag || $upperName === 'LHC_' . $upperTag ||
            substr($upperName, -strlen($upperTag) - 1) === '_' . $upperTag) {
            $item['Name'] = $name;
            return [true, $item, ''];
        }
    }
    return [false, null, 'No se encontró el TAG normalizado en PI ni en PI_Points_Stage: ' . $tag . '.'];
}

function scada_cfg_save_pi_link($db, $tag, array $point, $user)
{
    $tag = clear_scada_normalize_tag($tag);
    $webId = clear_scada_normalize_tag($point['WebId'] ?? '', 512);
    $name = clear_scada_normalize_tag($point['Name'] ?? $tag, 512);
    $unit = trim((string)($point['EngineeringUnits'] ?? ''));
    if ($webId === '') return false;
    $exists = (int)$db->scalar("SELECT COUNT(*) FROM dbo.CLEAR_SCADA_TAG_MAP WHERE TAG=?", [$tag]);
    if ($exists) {
        return $db->execute(
            "UPDATE dbo.CLEAR_SCADA_TAG_MAP SET PI_POINT=?,PI_WEBID=?," .
            "UNIDAD=COALESCE(NULLIF(UNIDAD,N''),?),UPDATED_AT=SYSDATETIME(),UPDATED_BY=? WHERE TAG=?",
            [$name, $webId, $unit, $user, $tag]
        );
    }
    return $db->execute(
        "INSERT dbo.CLEAR_SCADA_TAG_MAP(TAG,PI_POINT,PI_WEBID,UNIDAD,ALARM_TAG,ACTIVO,UPDATED_BY) VALUES(?,?,?,?,?,1,?)",
        [$tag, $name, $webId, $unit, $tag, $user]
    );
}

$db = clear_db();
if (!$db->ok()) scada_cfg_out(['ok'=>false, 'error'=>'Sin conexión a SQL Server. '.$db->error()], 500);
if (!clear_scada_objects_ready($db)) {
    scada_cfg_out(['ok'=>false, 'error'=>'Falta instalar SQL/CLEAR_SCADA_REALTIME_20260908.sql.'], 503);
}

$action = strtolower(clear_scada_clean($_REQUEST['action'] ?? 'overview', 40));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clear_scada_verify_csrf($_POST['csrf'] ?? '')) {
        scada_cfg_out(['ok'=>false, 'error'=>'La sesión administrativa venció. Actualizá la página.'], 419);
    }
}

if ($action === 'overview') {
    $search = clear_scada_clean($_GET['search'] ?? '', 120);
    $tagParams = [];
    $tagWhere = '';
    if ($search !== '') {
        $tagWhere = "WHERE V.TAG LIKE ? OR V.DESCRIPCION LIKE ? OR V.BATERIA LIKE ?";
        $needle = '%' . $search . '%';
        $tagParams = [$needle, $needle, $needle];
    }
    $candidates = $db->all(
        "WITH C AS (SELECT S.name AS SOURCE_SCHEMA,T.name AS SOURCE_TABLE,T.object_id," .
        "SUM(CASE WHEN Col.name IN(N'Nodo',N'Tag',N'Descripcion',N'ItemValor',N'ValorNumerico',N'ValorTexto',N'Calidad',N'FechaOPC',N'FechaActualizacion') THEN 1 ELSE 0 END) MATCHING_COLUMNS " .
        "FROM sys.tables T JOIN sys.schemas S ON S.schema_id=T.schema_id JOIN sys.columns Col ON Col.object_id=T.object_id " .
        "GROUP BY S.name,T.name,T.object_id) " .
        "SELECT C.SOURCE_SCHEMA,C.SOURCE_TABLE,C.MATCHING_COLUMNS,ISNULL(P.ROWS_COUNT,0) AS ROWS_COUNT " .
        "FROM C OUTER APPLY(SELECT SUM(row_count) ROWS_COUNT FROM sys.dm_db_partition_stats D WHERE D.object_id=C.object_id AND D.index_id IN(0,1))P " .
        "WHERE C.MATCHING_COLUMNS=9 ORDER BY CASE WHEN C.SOURCE_SCHEMA=N'dbo' THEN 0 ELSE 1 END,C.SOURCE_TABLE"
    );
    $batteries = $db->all(
        "SELECT BATERIA,NOMBRE,ZONA,ACTIVA,ORDEN,UPDATED_AT,UPDATED_BY FROM dbo.CLEAR_SCADA_BATERIA ORDER BY ZONA,ORDEN,BATERIA"
    );
    $tags = $db->all(
        "SELECT TOP(500) V.TAG,MAX(V.DESCRIPCION) AS DESCRIPCION,MAX(V.NODO) AS NODO,MAX(V.BATERIA) AS BATERIA_DERIVADA," .
        "MAX(V.AREA_EQUIPO) AS AREA_DERIVADA,MAX(M.BATERIA) AS BATERIA,MAX(M.ZONA) AS ZONA,MAX(M.AREA_EQUIPO) AS AREA_EQUIPO," .
        "MAX(M.UNIDAD) AS UNIDAD,MAX(M.PI_POINT) AS PI_POINT,MAX(M.PI_WEBID) AS PI_WEBID,MAX(M.PI_VISION_URL) AS PI_VISION_URL," .
        "MAX(M.ALARM_TAG) AS ALARM_TAG,MAX(CONVERT(int,ISNULL(M.ACTIVO,1))) AS ACTIVO " .
        "FROM dbo.CLEAR_SCADA_REALTIME_V V LEFT JOIN dbo.CLEAR_SCADA_TAG_MAP M ON M.TAG=V.TAG $tagWhere " .
        "GROUP BY V.TAG ORDER BY V.TAG",
        $tagParams
    );
    $status = $db->all("SELECT COMPONENTE,ESTADO,INICIO,FIN,FILAS,DETALLE FROM dbo.CLEAR_SCADA_RUNTIME_STATUS ORDER BY COMPONENTE");
    scada_cfg_out([
        'ok'=>true,
        'settings'=>clear_scada_settings($db),
        'candidates'=>$candidates,
        'batteries'=>$batteries,
        'tags'=>$tags,
        'status'=>$status,
        'csrf'=>clear_scada_csrf_token(),
    ]);
}

$user = auth_user() ?: 'CLEAR';

if ($action === 'save_settings') {
    $schema = clear_scada_clean($_POST['source_schema'] ?? 'dbo', 128);
    $table = clear_scada_clean($_POST['source_table'] ?? '', 128);
    $refresh = max(5, min(300, (int)($_POST['refresh_seconds'] ?? 10)));
    $stale = max(10, min(86400, (int)($_POST['stale_seconds'] ?? 900)));
    $window = max(1, min(720, (int)($_POST['alarm_window_hours'] ?? 24)));
    $vision = clear_scada_clean($_POST['pi_vision_base_url'] ?? '', 1000);
    if ($schema === '' || $table === '') scada_cfg_out(['ok'=>false, 'error'=>'Seleccioná la tabla fuente del colector.'], 400);
    $ok = $db->execute("EXEC dbo.SP_CLEAR_SCADA_CONFIGURAR_FUENTE ?,?,?", [$schema, $table, $user]);
    if (!$ok) scada_cfg_out(['ok'=>false, 'error'=>'No se pudo configurar la fuente. '.$db->error()], 500);
    $ok = $db->execute(
        "UPDATE dbo.CLEAR_SCADA_SETTINGS SET REFRESH_SECONDS=?,STALE_SECONDS=?,ALARM_WINDOW_HOURS=?," .
        "PI_VISION_BASE_URL=?,UPDATED_AT=SYSDATETIME(),UPDATED_BY=? WHERE ID=1",
        [$refresh, $stale, $window, $vision, $user]
    );
    if (!$ok) scada_cfg_out(['ok'=>false, 'error'=>'No se pudieron guardar los intervalos. '.$db->error()], 500);
    audit_log('SCADA_CONFIG_ACTUALIZADA', 'scada_realtime', json_encode(['source'=>$schema.'.'.$table,'refresh'=>$refresh,'stale'=>$stale,'window'=>$window], JSON_UNESCAPED_UNICODE));
    scada_cfg_out(['ok'=>true, 'message'=>'Configuración guardada. La caché de alarmas se actualizará en el próximo ciclo.']);
}

if ($action === 'save_battery') {
    $battery = strtoupper(clear_scada_clean($_POST['battery'] ?? '', 128));
    $name = clear_scada_clean($_POST['name'] ?? '', 255);
    $zone = clear_scada_clean($_POST['zone'] ?? '', 128);
    $order = max(0, min(9999, (int)($_POST['order'] ?? 0)));
    $active = !empty($_POST['active']) ? 1 : 0;
    if ($battery === '') scada_cfg_out(['ok'=>false, 'error'=>'La batería es obligatoria.'], 400);
    $exists = (int)$db->scalar("SELECT COUNT(*) FROM dbo.CLEAR_SCADA_BATERIA WHERE BATERIA=?", [$battery]);
    $ok = $exists
        ? $db->execute("UPDATE dbo.CLEAR_SCADA_BATERIA SET NOMBRE=?,ZONA=?,ACTIVA=?,ORDEN=?,UPDATED_AT=SYSDATETIME(),UPDATED_BY=? WHERE BATERIA=?", [$name,$zone,$active,$order,$user,$battery])
        : $db->execute("INSERT dbo.CLEAR_SCADA_BATERIA(BATERIA,NOMBRE,ZONA,ACTIVA,ORDEN,UPDATED_BY) VALUES(?,?,?,?,?,?)", [$battery,$name,$zone,$active,$order,$user]);
    if (!$ok) scada_cfg_out(['ok'=>false, 'error'=>'No se pudo guardar la batería. '.$db->error()], 500);
    audit_log('SCADA_BATERIA_GUARDADA', 'scada_realtime', $battery);
    scada_cfg_out(['ok'=>true, 'message'=>'Batería guardada.']);
}

if ($action === 'save_tag') {
    $tag = clear_scada_normalize_tag($_POST['tag'] ?? '');
    if ($tag === '') scada_cfg_out(['ok'=>false, 'error'=>'El TAG es obligatorio.'], 400);
    $values = [
        clear_scada_clean($_POST['battery'] ?? '', 128),
        clear_scada_clean($_POST['zone'] ?? '', 128),
        clear_scada_clean($_POST['area'] ?? '', 255),
        clear_scada_clean($_POST['unit'] ?? '', 64),
        clear_scada_normalize_tag($_POST['pi_point'] ?? '', 255),
        clear_scada_normalize_tag($_POST['pi_webid'] ?? '', 512),
        clear_scada_clean($_POST['pi_vision_url'] ?? '', 1000),
        clear_scada_clean($_POST['alarm_tag'] ?? $tag, 255),
        !empty($_POST['active']) ? 1 : 0,
    ];
    $exists = (int)$db->scalar("SELECT COUNT(*) FROM dbo.CLEAR_SCADA_TAG_MAP WHERE TAG=?", [$tag]);
    if ($exists) {
        $ok = $db->execute(
            "UPDATE dbo.CLEAR_SCADA_TAG_MAP SET BATERIA=?,ZONA=?,AREA_EQUIPO=?,UNIDAD=?,PI_POINT=?,PI_WEBID=?," .
            "PI_VISION_URL=?,ALARM_TAG=?,ACTIVO=?,UPDATED_AT=SYSDATETIME(),UPDATED_BY=? WHERE TAG=?",
            array_merge($values, [$user, $tag])
        );
    } else {
        $ok = $db->execute(
            "INSERT dbo.CLEAR_SCADA_TAG_MAP(TAG,BATERIA,ZONA,AREA_EQUIPO,UNIDAD,PI_POINT,PI_WEBID,PI_VISION_URL,ALARM_TAG,ACTIVO,UPDATED_BY) " .
            "VALUES(?,?,?,?,?,?,?,?,?,?,?)",
            array_merge([$tag], $values, [$user])
        );
    }
    if (!$ok) scada_cfg_out(['ok'=>false, 'error'=>'No se pudo guardar el TAG. '.$db->error()], 500);
    audit_log('SCADA_TAG_GUARDADO', 'scada_realtime', $tag);
    scada_cfg_out(['ok'=>true, 'message'=>'Relación del TAG guardada.']);
}

if ($action === 'resolve_pi') {
    $tag = clear_scada_normalize_tag($_POST['tag'] ?? '');
    if ($tag === '') scada_cfg_out(['ok'=>false, 'error'=>'Seleccioná un TAG.'], 400);
    [$ok, $point, $error] = scada_cfg_resolve_pi($tag);
    if (!$ok) scada_cfg_out(['ok'=>false, 'error'=>$error], 404);
    if (!scada_cfg_save_pi_link($db, $tag, $point, $user)) {
        scada_cfg_out(['ok'=>false, 'error'=>'PI encontró el punto, pero no se pudo guardar la relación. '.$db->error()], 500);
    }
    audit_log('SCADA_PI_VINCULADO', 'scada_realtime', $tag);
    scada_cfg_out(['ok'=>true, 'message'=>'Punto PI vinculado.', 'point'=>$point]);
}

if ($action === 'auto_link') {
    $limit = max(1, min(25, (int)($_POST['limit'] ?? 10)));
    $missing = $db->all(
        "SELECT TOP ($limit) V.TAG FROM dbo.CLEAR_SCADA_REALTIME_V V " .
        "LEFT JOIN dbo.CLEAR_SCADA_TAG_MAP M ON M.TAG=V.TAG " .
        "WHERE NULLIF(LTRIM(RTRIM(ISNULL(M.PI_WEBID,N''))),N'') IS NULL GROUP BY V.TAG ORDER BY V.TAG"
    );
    $linked = [];
    $notFound = [];
    foreach ($missing as $row) {
        $tag = clear_scada_normalize_tag(clear_scada_row_value($row, 'TAG', ''));
        if ($tag === '') continue;
        [$ok, $point] = scada_cfg_resolve_pi($tag);
        if ($ok && scada_cfg_save_pi_link($db, $tag, $point, $user)) $linked[] = $tag;
        else $notFound[] = $tag;
    }
    audit_log('SCADA_PI_VINCULACION_AUTOMATICA', 'scada_realtime', json_encode(['linked'=>count($linked),'not_found'=>count($notFound)]));
    scada_cfg_out(['ok'=>true, 'message'=>count($linked).' puntos vinculados.', 'linked'=>$linked, 'not_found'=>$notFound]);
}

scada_cfg_out(['ok'=>false, 'error'=>'Acción no válida.'], 400);
