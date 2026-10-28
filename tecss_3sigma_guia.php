<?php
// Guía estática de capacitación; reutiliza los permisos de la pantalla de vibraciones.
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
auth_require();
permissions_require_menu('tecss_vibraciones');

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
$download = isset($_GET['descargar']) && $_GET['descargar'] === '1';
if ($download) header('Content-Disposition: attachment; filename="3Sigma_CLEAR_guia_animada.html"');

define('CLEAR_3SIGMA_LESSON', true);
ob_start();
require __DIR__.'/includes/tecss_3sigma_guia_content.php';
$html = ob_get_clean();
$actions = '<div class="wrap no-print"><div class="controls"><a href="tecss_vibraciones.php">← Volver a Vibraciones</a><span aria-hidden="true">·</span><a href="tecss_3sigma_guia.php?descargar=1">Descargar HTML para usar sin conexión</a></div></div>';
echo str_replace('<!-- CLEAR_WEB_ACTIONS -->', $download ? '' : $actions, $html);
