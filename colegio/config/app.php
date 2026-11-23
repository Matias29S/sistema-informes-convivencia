<?php
/** Arranque de la aplicación: sesión, zona horaria y dependencias. */
session_name('sgic35');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');

define('BASE_URL', '/colegio/');
define('APP_URL', 'http://localhost/colegio/');
define('APP_NAME', 'Sistema de Informes de Convivencia');
define('UPLOAD_DIR', __DIR__ . '/../storage/uploads/');

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/../includes/layout.php';
