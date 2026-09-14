<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/reporting.php';

function ai_analysis_ensure_tables() {
    $db = clear_db();
    if (!$db->ok()) return false;
    return $db->execute("IF OBJECT_ID('dbo.CLEAR_AI_DIAGNOSTICS','U') IS NULL
      CREATE TABLE dbo.CLEAR_AI_DIAGNOSTICS(
        ID BIGINT IDENTITY(1,1) PRIMARY KEY,
        FECHA_GENERACION DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
        PERIODO_DESDE DATETIME2 NOT NULL,
        PERIODO_HASTA DATETIME2 NOT NULL,
        MODELO NVARCHAR(120) NULL,
        ESTADO NVARCHAR(30) NOT NULL,
        RESUMEN NVARCHAR(MAX) NULL,
        DATOS_JSON NVARCHAR(MAX) NULL,
        ERROR NVARCHAR(MAX) NULL,
        USUARIO NVARCHAR(128) NULL
      )");
}

function ai_analysis_settings() {
    $provider = strtolower(trim((string)report_setting('AI_PROVIDER','openai')));
    if (!in_array($provider, ['openai','gemini','anthropic','openai_compatible','custom'], true)) $provider = 'openai';
    $defaults = [
        'openai' => ['endpoint'=>'https://api.openai.com/v1/responses','model'=>'gpt-5-mini'],
        'gemini' => ['endpoint'=>'https://generativelanguage.googleapis.com/v1beta/models/{MODEL}:generateContent','model'=>'gemini-2.5-flash'],
        'anthropic' => ['endpoint'=>'https://api.anthropic.com/v1/messages','model'=>'claude-sonnet-4-5'],
        'openai_compatible' => ['endpoint'=>'http://servidor-ia/v1/chat/completions','model'=>'modelo-local'],
        'custom' => ['endpoint'=>'http://servidor-ia/api/generate','model'=>'modelo-personalizado'],
    ];
    $apiKey = (string)report_setting('AI_API_KEY','');
    if ($apiKey === '') {
        $env = getenv('OPENAI_API_KEY');
        if ($provider === 'openai' && is_string($env)) $apiKey = trim($env);
    }
    return [
        'enabled' => report_setting('AI_ENABLED','0') === '1',
        'provider' => $provider,
        'endpoint' => report_setting('AI_ENDPOINT',$defaults[$provider]['endpoint']),
        'model' => report_setting('AI_MODEL',$defaults[$provider]['model']),
        'api_key' => $apiKey,
        'api_key_configured' => $apiKey !== '',
        'auth_header' => report_setting('AI_AUTH_HEADER','Authorization: Bearer {API_KEY}'),
        'hour' => max(0,min(23,(int)report_setting('AI_RUN_HOUR','06'))),
    ];
}

function ai_analysis_collect($days = 7) {
    $db = clear_db();
    $days = max(1,min(31,(int)$days));
    $to = new DateTimeImmutable('now');
    $from = $to->sub(new DateInterval('P'.$days.'D'));
    $params = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
    $where = "WHERE ALM_NATIVETIMEIN>=? AND ALM_NATIVETIMEIN<=?";
    $priority = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100),ALM_ALMPRIORITY))))";
    $msgtype = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100),ALM_MSGTYPE))))";
    $tag = "LTRIM(RTRIM(CONVERT(nvarchar(255),ALM_TAGNAME)))";
    $descr = "LTRIM(RTRIM(CONVERT(nvarchar(500),ALM_DESCR)))";
    $well = "LTRIM(RTRIM(CONVERT(nvarchar(255),ALM_ALMEXTFLD2)))";

    $data = [
      'periodo_desde'=>$from->format('Y-m-d H:i:s'),
      'periodo_hasta'=>$to->format('Y-m-d H:i:s'),
      'total'=>(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where",$params),
      'tags_unicos'=>(int)$db->scalar("SELECT COUNT(DISTINCT $tag) FROM dbo.FIXALARMS $where AND ALM_TAGNAME IS NOT NULL AND $tag<>''",$params),
      'pozos_unicos'=>(int)$db->scalar("SELECT COUNT(DISTINCT $well) FROM dbo.FIXALARMS $where AND ALM_ALMEXTFLD2 IS NOT NULL AND $well<>''",$params),
      'alta'=>(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND ($priority LIKE '%CRIT%' OR $priority IN ('HIGH','HI','ALTA'))",$params),
      'media'=>(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND $priority IN ('MEDIUM','MED','MEDIA')",$params),
      'baja'=>(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND $priority IN ('LOW','LO','BAJA','INFO')",$params),
      'operator'=>(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND $msgtype='OPERATOR'",$params),
      'text'=>(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND $msgtype='TEXT'",$params),
      'alarm'=>(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND $msgtype='ALARM'",$params),
      'network'=>(int)$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS $where AND $msgtype='NETWORK'",$params),
      'top_tags'=>[], 'top_descripciones'=>[], 'top_pozos'=>[], 'por_dia'=>[]
    ];
    foreach($db->all("SELECT TOP 15 $tag tag,MAX($descr) descripcion,COUNT(*) cantidad FROM dbo.FIXALARMS $where AND ALM_TAGNAME IS NOT NULL AND $tag<>'' GROUP BY $tag ORDER BY COUNT(*) DESC",$params) as $r)
      $data['top_tags'][]=['tag'=>$r['tag']??'','descripcion'=>$r['descripcion']??'','cantidad'=>(int)($r['cantidad']??0)];
    foreach($db->all("SELECT TOP 10 $descr descripcion,COUNT(*) cantidad FROM dbo.FIXALARMS $where AND ALM_DESCR IS NOT NULL AND $descr<>'' GROUP BY $descr ORDER BY COUNT(*) DESC",$params) as $r)
      $data['top_descripciones'][]=['descripcion'=>$r['descripcion']??'','cantidad'=>(int)($r['cantidad']??0)];
    foreach($db->all("SELECT TOP 10 $well pozo,COUNT(*) cantidad FROM dbo.FIXALARMS $where AND ALM_ALMEXTFLD2 IS NOT NULL AND UPPER($well) LIKE 'YPF.SC%' GROUP BY $well ORDER BY COUNT(*) DESC",$params) as $r)
      $data['top_pozos'][]=['pozo'=>$r['pozo']??'','cantidad'=>(int)($r['cantidad']??0)];
    foreach($db->all("SELECT CONVERT(date,ALM_NATIVETIMEIN) fecha,COUNT(*) total,SUM(CASE WHEN $priority LIKE '%CRIT%' OR $priority IN ('HIGH','HI','ALTA') THEN 1 ELSE 0 END) alta FROM dbo.FIXALARMS $where GROUP BY CONVERT(date,ALM_NATIVETIMEIN) ORDER BY fecha",$params) as $r)
      $data['por_dia'][]=['fecha'=>(string)($r['fecha']??''),'total'=>(int)($r['total']??0),'alta'=>(int)($r['alta']??0)];
    return $data;
}

function ai_analysis_prompt(array $data) {
    return "Actuá como analista senior de gestión de alarmas industriales para CLEAR Petroleum. Analizá exclusivamente el resumen agregado de los últimos 7 días. No inventes causas ni acciones de campo. Diferenciá hechos, patrones e hipótesis. Respondé en español con estas secciones: 1) Resumen ejecutivo, 2) Hallazgos prioritarios, 3) Tags/pozos a revisar, 4) Riesgos operativos potenciales, 5) Acciones recomendadas para el operador, 6) Calidad y limitaciones de los datos. Usá listas breves, incluí cifras y marcá claramente cualquier inferencia. Datos JSON:\n".json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

function ai_analysis_extract_text($provider, array $json) {
    if ($provider === 'openai' || $provider === 'openai_compatible') {
        $text = trim((string)($json['output_text'] ?? ''));
        if ($text === '') {
            foreach (($json['output'] ?? []) as $item) {
                foreach (($item['content'] ?? []) as $content) {
                    if (isset($content['text'])) $text .= (string)$content['text'];
                }
            }
        }
        if ($text === '' && isset($json['choices'][0]['message']['content'])) {
            $content = $json['choices'][0]['message']['content'];
            if (is_string($content)) $text = $content;
            elseif (is_array($content)) foreach ($content as $part) if (isset($part['text'])) $text .= (string)$part['text'];
        }
        return trim($text);
    }
    if ($provider === 'gemini') {
        $text = '';
        foreach (($json['candidates'] ?? []) as $candidate) {
            foreach (($candidate['content']['parts'] ?? []) as $part) if (isset($part['text'])) $text .= (string)$part['text'];
        }
        if ($text === '' && isset($json['outputs'][0]['text'])) $text = (string)$json['outputs'][0]['text'];
        return trim($text);
    }
    if ($provider === 'anthropic') {
        $text = '';
        foreach (($json['content'] ?? []) as $part) if (($part['type'] ?? '') === 'text' && isset($part['text'])) $text .= (string)$part['text'];
        return trim($text);
    }
    foreach (['output_text','text','response','result','content'] as $key) {
        if (isset($json[$key]) && is_string($json[$key])) return trim($json[$key]);
    }
    return '';
}

function ai_analysis_call(array $data, array $override = [], $isTest = false) {
    $cfg = array_merge(ai_analysis_settings(), $override);
    $provider = strtolower(trim((string)($cfg['provider'] ?? 'openai')));
    $endpoint = trim((string)($cfg['endpoint'] ?? ''));
    $model = trim((string)($cfg['model'] ?? ''));
    $apiKey = trim((string)($cfg['api_key'] ?? ''));
    if ($endpoint === '') return [false,'Falta configurar el endpoint de IA.',''];
    if ($model === '') return [false,'Falta configurar el modelo de IA.',''];
    if ($provider !== 'custom' && $apiKey === '') return [false,'Falta configurar la API key de IA.',''];
    if (!function_exists('curl_init')) return [false,'La extensión cURL de PHP no está habilitada.',''];

    $prompt = $isTest
        ? 'Respondé únicamente con el texto CONEXION OK.'
        : ai_analysis_prompt($data);
    $headers = ['Content-Type: application/json'];

    if ($provider === 'openai') {
        $body = ['model'=>$model,'input'=>$prompt];
        $headers[] = 'Authorization: Bearer '.$apiKey;
    } elseif ($provider === 'gemini') {
        $endpoint = str_replace('{MODEL}', rawurlencode($model), $endpoint);
        if (strpos($endpoint, '{MODEL}') === false && strpos($endpoint, ':generateContent') === false) {
            $endpoint = rtrim($endpoint, '/') . '/models/' . rawurlencode($model) . ':generateContent';
        }
        $body = ['contents'=>[['parts'=>[['text'=>$prompt]]]]];
        $headers[] = 'x-goog-api-key: '.$apiKey;
    } elseif ($provider === 'anthropic') {
        $body = ['model'=>$model,'max_tokens'=>$isTest ? 32 : 2200,'messages'=>[['role'=>'user','content'=>$prompt]]];
        $headers[] = 'x-api-key: '.$apiKey;
        $headers[] = 'anthropic-version: 2023-06-01';
    } elseif ($provider === 'openai_compatible') {
        $headers[] = 'Authorization: Bearer '.$apiKey;
        if (stripos($endpoint, '/responses') !== false) $body = ['model'=>$model,'input'=>$prompt];
        else $body = ['model'=>$model,'messages'=>[['role'=>'user','content'=>$prompt]],'temperature'=>0.2];
    } else {
        $body = ['model'=>$model,'input'=>$prompt,'prompt'=>$prompt];
        $customHeader = trim((string)($cfg['auth_header'] ?? ''));
        if ($customHeader !== '' && $apiKey !== '') {
            $headers[] = str_replace('{API_KEY}', $apiKey, $customHeader);
        }
    }

    $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $isTest ? 40 : 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $encoded,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return [false,'Error de conexión IA: '.$err,''];
    $json = json_decode($raw, true);
    if (!is_array($json)) return [false,'La API de IA devolvió una respuesta que no es JSON válido: '.substr(strip_tags((string)$raw),0,400),''];
    if ($code < 200 || $code >= 300) {
        $message = $json['error']['message'] ?? $json['message'] ?? $json['error'] ?? substr($raw,0,500);
        if (is_array($message)) $message = json_encode($message,JSON_UNESCAPED_UNICODE);
        return [false,'API IA HTTP '.$code.': '.$message,''];
    }
    $text = ai_analysis_extract_text($provider, $json);
    return $text !== '' ? [true,'',$text] : [false,'La API respondió sin texto de diagnóstico.',''];
}

function ai_analysis_generate($user='scheduler',$force=false) {
    ai_analysis_ensure_tables(); $db=clear_db(); $cfg=ai_analysis_settings();
    if(!$force && !$cfg['enabled']) return [false,'El diagnóstico IA está desactivado.'];
    if(!$force) {
      $today=(int)$db->scalar("SELECT COUNT(*) FROM dbo.CLEAR_AI_DIAGNOSTICS WHERE ESTADO='OK' AND CONVERT(date,FECHA_GENERACION)=CONVERT(date,SYSDATETIME())");
      if($today>0) return [true,'El diagnóstico de hoy ya fue generado.'];
      if((int)date('G')<$cfg['hour']) return [true,'Todavía no corresponde el horario diario.'];
    }
    $data=ai_analysis_collect(7); [$ok,$error,$text]=ai_analysis_call($data);
    $db->execute("INSERT INTO dbo.CLEAR_AI_DIAGNOSTICS(PERIODO_DESDE,PERIODO_HASTA,MODELO,ESTADO,RESUMEN,DATOS_JSON,ERROR,USUARIO) VALUES(?,?,?,?,?,?,?,?)",[$data['periodo_desde'],$data['periodo_hasta'],$cfg['model'],$ok?'OK':'ERROR',$text,json_encode($data,JSON_UNESCAPED_UNICODE),$error,$user]);
    return [$ok,$ok?'Diagnóstico generado correctamente.':$error];
}

function ai_analysis_run_if_due() { return ai_analysis_generate('scheduler',false); }
