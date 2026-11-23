<?php
/** Conexión PDO a MySQL. Consultas preparadas en todo el sistema. */
$dsn = 'mysql:host=localhost;dbname=colegio;charset=utf8mb4';
$dbUser = 'root';
$dbPass = '';
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Guardar y leer todas las fechas en hora Argentina (UTC-3), sin importar la zona del servidor.
    try { $pdo->exec("SET time_zone = '-03:00'"); } catch (PDOException $e) { /* hosting sin soporte */ }
} catch (PDOException $e) {
    http_response_code(500);
    die('<h1>Error de base de datos</h1><p>Verificá que MySQL esté activo y que hayas importado <code>database/colegio.sql</code>.</p><pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>');
}
