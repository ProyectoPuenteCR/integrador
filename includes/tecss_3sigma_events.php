<?php
/* PI schema verified on 2026-10-28: Notification Subscribers.Description contains
 * Notification + Subscribers[{Configuration,Type,SendTime,SendStatus,RetryCount}].
 * Only this persisted record is evidence of email delivery. Never use excesses,
 * the annotation's summary text, or today's subscription configuration. */
function sigma_af_date($value)
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d\d-\d\dT.*(?:Z|[+-]\d\d:\d\d)$/', $value) || strpos($value, '9999-') === 0) return null;
    try { return (new DateTimeImmutable($value))->format('Y-m-d\TH:i:s.uP'); }
    catch (Exception $e) { return null; }
}

function sigma_af_audits(array $annotations)
{
    $groups = [];
    foreach ($annotations as $annotation) {
        $creator = $annotation['Creator'] ?? '';
        if (is_array($creator)) $creator = $creator['Name'] ?? '';
        if (!preg_match('/(?:^|\\\\)PINotificationsService$/i', (string)$creator) || ($annotation['Name'] ?? '') !== 'Notification Subscribers') continue;
        $description = json_decode((string)($annotation['Description'] ?? ''), true);
        if (!is_array($description) || !is_array($description['Subscribers'] ?? null)) continue;
        foreach ($description['Subscribers'] as $subscriber) {
            if (!is_array($subscriber) || strcasecmp((string)($subscriber['Type'] ?? ''), 'Email') !== 0) continue;
            $raw = trim((string)($subscriber['SendStatus'] ?? ''));
            $statuses = ['Success'=>'Enviado', 'Failure'=>'Error', 'Failed'=>'Error', 'Error'=>'Error', 'Pending'=>'Pendiente', 'Retrying'=>'Reintentando'];
            $state = $statuses[$raw] ?? ($raw !== '' ? 'Estado PI: '.$raw : 'No registrado');
            $sendTime = sigma_af_date($subscriber['SendTime'] ?? null);
            // Keep error details only when supplied by the persisted subscriber.
            $error = $subscriber['ErrorMessage'] ?? $subscriber['Error'] ?? $subscriber['SendError'] ?? null;
            if (is_array($error)) $error = json_encode($error, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $error = $error === null ? null : (string)$error;
            $retry = isset($subscriber['RetryCount']) && is_numeric($subscriber['RetryCount']) ? (int)$subscriber['RetryCount'] : null;
            $annotationId = (string)($annotation['Id'] ?? '');
            $key = json_encode([$annotationId, $sendTime, $raw, $error, $retry]);
            if (!isset($groups[$key])) $groups[$key] = [
                'annotation_id'=>$annotationId, 'status'=>$state, 'status_raw'=>$raw,
                'sent_at'=>$sendTime, 'recorded_at'=>sigma_af_date($annotation['CreationDate'] ?? null),
                'recipients'=>[], 'error'=>$error, 'retry_count'=>$retry,
                'detail'=>(string)($annotation['Value'] ?? ''),
            ];
            $address = trim((string)($subscriber['Configuration'] ?? ''));
            if ($address !== '' && !in_array($address, $groups[$key]['recipients'], true)) $groups[$key]['recipients'][] = $address;
        }
    }
    return array_values($groups);
}

function sigma_af_collect(callable $get, $well, DateTimeImmutable $start, DateTimeImmutable $end)
{
    $database = $get('/assetdatabases?'.http_build_query(['path'=>'\\\\SWPCRDLHSCD51\\IUP']));
    if (empty($database['WebId'])) throw new RuntimeException('Base AF no disponible.');
    $prefix = (stripos($well, 'YPF.SC.') === 0 ? $well : 'YPF.SC.'.$well).'-3SIGMA-TECSS-';
    // Reject query wildcard injection. Exact prefix is checked again below.
    if (preg_match('/[\x00-\x1f*?\[\]]/', $prefix)) throw new RuntimeException('Nombre de pozo no compatible con la búsqueda AF.');
    $query = ['templateName'=>'3Sigma_Superado', 'searchMode'=>'Overlapped',
        'searchFullHierarchy'=>'true', 'nameFilter'=>$prefix.'*',
        'startTime'=>$start->format(DATE_ATOM), 'endTime'=>$end->format(DATE_ATOM),
        'sortField'=>'StartTime', 'sortOrder'=>'Ascending', 'maxCount'=>251, 'startIndex'=>0];
    $result = $get('/assetdatabases/'.rawurlencode($database['WebId']).'/eventframes?'.http_build_query($query));
    if (!is_array($result['Items'] ?? null)) throw new RuntimeException('Colección de eventos AF inválida.');
    $warnings = [];
    if (count($result['Items']) > 250) $warnings[] = 'Hay más de 250 alertas: reducí el rango para consultar todas.';
    $events = [];
    foreach (array_slice($result['Items'], 0, 250) as $frame) {
        if (($frame['TemplateName'] ?? '') !== '3Sigma_Superado' || strncasecmp((string)($frame['Name'] ?? ''), $prefix, strlen($prefix)) !== 0) continue;
        $begin = sigma_af_date($frame['StartTime'] ?? null);
        if (!$begin || empty($frame['WebId'])) throw new RuntimeException('Evento AF sin identificación o inicio válido.');
        $finish = sigma_af_date($frame['EndTime'] ?? null);
        // AF overlap search includes events that began before the requested range.
        if (new DateTimeImmutable($begin) >= $end || ($finish && new DateTimeImmutable($finish) < $start)) continue;
        $event = ['id'=>(string)$frame['Id'], 'name'=>(string)$frame['Name'],
            'start'=>$begin, 'end'=>$finish, 'audits'=>[], 'audit_available'=>true];
        try {
            $annotations = $get('/eventframes/'.rawurlencode($frame['WebId']).'/annotations?maxCount=1001&startIndex=0');
            if (!is_array($annotations['Items'] ?? null) || count($annotations['Items']) > 1000) throw new RuntimeException('Auditoría incompleta.');
            // Keep all attempts, including retries and outcomes for different recipients.
            $event['audits'] = sigma_af_audits($annotations['Items']);
        } catch (RuntimeException $e) {
            $event['audit_available'] = false;
            $warnings[] = 'No se pudo leer la auditoría de uno o más eventos; no se presume su estado de email.';
        }
        $events[] = $event;
    }
    return ['events'=>$events, 'warnings'=>array_values(array_unique($warnings))];
}

function sigma_af_connection($db, array $app)
{
    $pi = $app['pi'] ?? [];
    // Do not call pi_config(): it can create CLEAR_CONFIG during a web request.
    $exists = $db->scalar("SELECT OBJECT_ID(N'dbo.CLEAR_CONFIG',N'U')");
    if ($db->error()) throw new RuntimeException('Configuración PI no disponible.');
    $rows = $exists ? $db->all("SELECT CLAVE,VALOR FROM dbo.CLEAR_CONFIG WHERE CLAVE IN ('pi_base','pi_user','pi_pass','pi_ca_bundle')") : [];
    if ($db->error()) throw new RuntimeException('Configuración PI no disponible.');
    $pi['user'] = ''; $pi['pass'] = '';
    foreach ($rows as $row) {
        $keys = ['pi_base'=>'base','pi_user'=>'user','pi_pass'=>'pass','pi_ca_bundle'=>'ca_bundle'];
        if (isset($keys[$row['CLAVE']]) && $row['VALOR'] !== null && $row['VALOR'] !== '') $pi[$keys[$row['CLAVE']]] = $row['VALOR'];
    }
    $base = rtrim((string)($pi['base'] ?? ''), '/');
    $parts = parse_url($base);
    if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || !preg_match('#/piwebapi$#i', $parts['path'] ?? '') || isset($parts['query']) || isset($parts['fragment'])) throw new RuntimeException('Se requiere una conexión HTTPS PI Web API válida.');
    $auth = ($pi['user'] !== '' || $pi['pass'] !== '') ? 'Basic '.base64_encode($pi['user'].':'.$pi['pass']) : (string)($pi['auth'] ?? '');
    if ($auth === '' || preg_match('/[\r\n]/', $auth)) throw new RuntimeException('Autenticación PI no disponible.');
    $resolve = [];
    $ca = (string)($pi['ca_bundle'] ?? '');
    // Existing CLEAR endpoint uses an IP; verify against its actual certificate
    // name and public trust anchor inspected on this installation. Never disable TLS.
    if (($parts['host'] ?? '') === '10.17.40.37') {
        $port = $parts['port'] ?? 443;
        $base = 'https://SWPCRDLHSCD51:'.$port.$parts['path'];
        $resolve = ['SWPCRDLHSCD51:'.$port.':10.17.40.37'];
        if ($ca === '') $ca = __DIR__.'/certs/clear_pi_3sigma.pem';
    }
    if ($ca !== '' && !is_readable($ca)) throw new RuntimeException('Certificado PI no disponible.');
    return ['base'=>$base,'auth'=>$auth,'ca'=>$ca,'resolve'=>$resolve];
}

function sigma_af_getter(array $connection)
{
    if (!function_exists('curl_init')) throw new RuntimeException('La lectura de eventos PI requiere cURL.');
    $deadline = microtime(true) + 25;
    return function($path) use ($connection, $deadline) {
        if (!is_string($path) || $path[0] !== '/' || substr($path,0,2) === '//' || preg_match('/[\r\n]/', $path)) throw new RuntimeException('Ruta PI inválida.');
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) throw new RuntimeException('Se agotó el tiempo de consulta de auditoría.');
        $ch = curl_init($connection['base'].$path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: '.$connection['auth']],
            CURLOPT_CONNECTTIMEOUT_MS=>min(3000,(int)ceil($remaining*1000)),
            CURLOPT_TIMEOUT_MS=>min(8000,(int)ceil($remaining*1000)),
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
        if ($connection['ca'] !== '') curl_setopt($ch, CURLOPT_CAINFO, $connection['ca']);
        if ($connection['resolve']) curl_setopt($ch, CURLOPT_RESOLVE, $connection['resolve']);
        $body = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($body === false || $http !== 200) throw new RuntimeException('No se pudo leer PI Web API (HTTP '.$http.').');
        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException('Respuesta PI inválida.');
        return $data;
    };
}
