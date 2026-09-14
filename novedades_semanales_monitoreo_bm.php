<?php
// Pantalla retirada de Novedades semanales. No consulta RTQP ni borra datos.
require_once __DIR__.'/includes/auth.php';auth_require();
permissions_require_menu('novedades_semanales_monitoreo');
header('Location: novedades_semanales_monitoreo.php',true,302);exit;
