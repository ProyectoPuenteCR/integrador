<?php
ob_start();
ini_set('display_errors','0');
error_reporting(E_ALL);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/ai_analysis.php';

function cia_json($payload, $status = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    auth_require();
    if (!auth_es_admin()) throw new RuntimeException('Acceso restringido a administradores.');

    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'save_settings') {
        $allowedProviders = ['openai','gemini','anthropic','openai_compatible','custom'];
        $provider = strtolower(trim((string)($_POST['AI_PROVIDER'] ?? 'openai')));
        if (!in_array($provider, $allowedProviders, true)) throw new RuntimeException('Proveedor de IA no válido.');

        $values = [
            'AI_ENABLED' => trim((string)($_POST['AI_ENABLED'] ?? '0')) === '1' ? '1' : '0',
            'AI_PROVIDER' => $provider,
            'AI_ENDPOINT' => trim((string)($_POST['AI_ENDPOINT'] ?? '')),
            'AI_MODEL' => trim((string)($_POST['AI_MODEL'] ?? '')),
            'AI_RUN_HOUR' => (string)max(0, min(23, (int)($_POST['AI_RUN_HOUR'] ?? 6))),
            'AI_AUTH_HEADER' => trim((string)($_POST['AI_AUTH_HEADER'] ?? '')),
        ];
        foreach ($values as $key => $value) report_setting_save($key, $value, auth_user());

        $newKey = trim((string)($_POST['AI_API_KEY'] ?? ''));
        if ($newKey !== '') report_setting_save('AI_API_KEY', $newKey, auth_user());

        audit_log('AI_SETTINGS_SAVE','config_ia','Configuración multiproveedor de IA actualizada: '.$provider);
        cia_json(['ok'=>true,'message'=>'Configuración de IA guardada correctamente.']);
    }

    if ($action === 'test_connection') {
        $override = [
            'provider' => strtolower(trim((string)($_POST['AI_PROVIDER'] ?? 'openai'))),
            'endpoint' => trim((string)($_POST['AI_ENDPOINT'] ?? '')),
            'model' => trim((string)($_POST['AI_MODEL'] ?? '')),
            'api_key' => trim((string)($_POST['AI_API_KEY'] ?? '')),
            'auth_header' => trim((string)($_POST['AI_AUTH_HEADER'] ?? '')),
        ];
        if ($override['api_key'] === '') $override['api_key'] = ai_analysis_settings()['api_key'];
        [$ok,$error,$text] = ai_analysis_call(['prueba'=>'Respondé únicamente: CONEXION OK'], $override, true);
        if (!$ok) throw new RuntimeException($error);
        audit_log('AI_CONNECTION_TEST','config_ia','Conexión IA verificada: '.$override['provider']);
        cia_json(['ok'=>true,'message'=>'Conexión correcta con '.$override['provider'].'.']);
    }

    if ($action === 'run_now') {
        [$ok,$message] = ai_analysis_generate(auth_user(), true);
        if (!$ok) throw new RuntimeException($message);
        audit_log('AI_DIAGNOSTIC_RUN','config_ia',$message);
        cia_json(['ok'=>true,'message'=>$message]);
    }

    throw new RuntimeException('Acción no válida.');
} catch (Throwable $e) {
    cia_json(['ok'=>false,'error'=>$e->getMessage()], 400);
}
