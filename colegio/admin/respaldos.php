<?php
/** Respaldo SQL descargable generado con PDO (sin ejecutar comandos del sistema). */
require_once __DIR__ . '/../config/app.php';
require_role('ADMIN');
global $pdo;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $st = $pdo->prepare('SHOW TABLES');
    $st->execute();
    $tablas = $st->fetchAll(PDO::FETCH_NUM);

    $dump = "-- Respaldo de la base de datos colegio\n";
    $dump .= '-- Generado: ' . date('Y-m-d H:i:s') . " por usuario id " . (int)($_SESSION['user_id'] ?? 0) . "\n";
    $dump .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tablas as $fila) {
        $tabla = (string)$fila[0];
        // Los identificadores no se pueden enlazar como parámetros: se validan explícitamente.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tabla)) continue;

        $stCrear = $pdo->prepare('SHOW CREATE TABLE `' . $tabla . '`');
        $stCrear->execute();
        $crear = $stCrear->fetch(PDO::FETCH_NUM);
        $dump .= 'DROP TABLE IF EXISTS `' . $tabla . "`;\n";
        $dump .= ($crear[1] ?? '') . ";\n\n";

        $stDatos = $pdo->prepare('SELECT * FROM `' . $tabla . '`');
        $stDatos->execute();
        $filas = $stDatos->fetchAll(PDO::FETCH_ASSOC);
        if (!$filas) continue;

        $columnas = array_keys($filas[0]);
        $listaColumnas = implode(', ', array_map(function ($c) {
            return '`' . str_replace('`', '``', $c) . '`';
        }, $columnas));

        foreach ($filas as $f) {
            $valores = [];
            foreach ($columnas as $c) {
                $valor = $f[$c];
                $valores[] = $valor === null ? 'NULL' : $pdo->quote((string)$valor);
            }
            $dump .= 'INSERT INTO `' . $tabla . '` (' . $listaColumnas . ') VALUES ('
                   . implode(', ', $valores) . ");\n";
        }
        $dump .= "\n";
    }
    $dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    audit('DESCARGAR_RESPALDO', null, null, 'Volcado SQL de ' . count($tablas) . ' tablas');

    $nombreArchivo = 'colegio_backup_' . date('Y-m-d') . '.sql';
    header('Content-Type: application/octet-stream; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Content-Length: ' . strlen($dump));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $dump;
    exit;
}

layout_header('Respaldos');
?>
<h1>Respaldos</h1>
<div class="card">
 <h2>Descargar copia de seguridad</h2>
 <p>Se genera un archivo <code>.sql</code> con la estructura y los datos de todas las tablas, usando la conexión PDO de la aplicación.</p>
 <form method="post"><?= csrf_field() ?>
  <p><button class="btn" data-confirm="¿Descargar el respaldo completo de la base de datos?">Descargar respaldo SQL</button></p>
 </form>
</div>
<div class="card">
 <h2>Recomendación para producción</h2>
 <p class="muted">Para bases grandes es preferible usar la herramienta nativa de MySQL desde la consola del servidor:</p>
 <p><code>mysqldump -u root -p colegio &gt; backup.sql</code></p>
 <p class="muted">El volcado generado desde la web depende de los límites de memoria/ejecución de PHP; no exponga esta página a roles sin autorización.</p>
</div>
<?php layout_footer(); ?>
