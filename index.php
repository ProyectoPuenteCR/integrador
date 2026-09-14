<?php
require_once __DIR__ . '/includes/auth.php';
auth_require();
header('Location: inicio.php');
exit;
