<?php
/** Estadísticas institucionales con filtros y exportación CSV (sólo con permiso). */
require_once __DIR__ . '/config/app.php';
require_login();
if (!has_permission('estadisticas.ver')) {
    denegar_acceso('No tenés autorización para ver estadísticas institucionales. Podés solicitar el permiso a administración.');
}
global $pdo;

/** Entero positivo de GET, o 0 si no es válido. */
function entero_filtro(string $clave): int {
    $v = trim((string)($_GET[$clave] ?? ''));
    return ($v !== '' && ctype_digit($v)) ? (int)$v : 0;
}

/** Escapa un campo CSV y neutraliza fórmulas al inicio (CSV injection). */
function csv_campo($valor): string {
    $s = str_replace(["\r", "\n"], ' ', (string)($valor ?? ''));
    if ($s !== '' && preg_match('/^[=+\-@\t]/', $s)) $s = "'" . $s;
    return $s;
}

$MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
          7 => 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// ---------- Catálogos para los select (consultas preparadas / sin parámetros) ----------
$cursos     = $pdo->query('SELECT id, anio, nombre, especialidad FROM cursos ORDER BY anio, especialidad')->fetchAll();
$divisiones = $pdo->query('SELECT dv.id, dv.curso_id, dv.nombre, c.nombre AS curso, c.especialidad
                           FROM divisiones dv JOIN cursos c ON c.id=dv.curso_id
                           ORDER BY c.anio, c.especialidad, dv.nombre')->fetchAll();
$docentes   = $pdo->query('SELECT d.id, us.nombre, us.apellido FROM docentes d
                           JOIN usuarios us ON us.id=d.usuario_id
                           ORDER BY us.apellido, us.nombre')->fetchAll();
$categorias = $pdo->query("SELECT DISTINCT solicitud_sancion FROM informes
                           WHERE solicitud_sancion IS NOT NULL AND solicitud_sancion<>''
                           ORDER BY solicitud_sancion")->fetchAll(PDO::FETCH_COLUMN);

// ---------- Validación de filtros ----------
$mesVal = entero_filtro('mes');
$mes = ($mesVal >= 1 && $mesVal <= 12) ? $mesVal : 0;

$anioVal = entero_filtro('anio');
$anio = ($anioVal >= 1900 && $anioVal <= 2100) ? $anioVal : 0;

$curso    = entero_filtro('curso');
$division = entero_filtro('division');
$docente  = entero_filtro('docente');

$categoria = mb_substr(trim((string)($_GET['categoria'] ?? '')), 0, 120);
if ($categoria !== '' && !in_array($categoria, $categorias, true)) $categoria = '';

$estado = (string)($_GET['estado'] ?? '');
if (!isset(ESTADOS_INFORME[$estado])) $estado = '';

// ---------- ÚNICA consulta dinámica de datos: placeholders para todos los valores ----------
$where  = ['1=1'];
$params = [];
if ($mes > 0)        { $where[] = 'MONTH(i.fecha_incidente)=?'; $params[] = $mes; }
if ($anio > 0)       { $where[] = 'YEAR(i.fecha_incidente)=?';  $params[] = $anio; }
if ($curso > 0)      { $where[] = 'c.id=?';                     $params[] = $curso; }
if ($division > 0)   { $where[] = 'dv.id=?';                    $params[] = $division; }
if ($categoria !== '') { $where[] = 'i.solicitud_sancion=?';    $params[] = $categoria; }
if ($estado !== '')  { $where[] = 'i.estado=?';                 $params[] = $estado; }
if ($docente > 0)    { $where[] = 'd.id=?';                     $params[] = $docente; }

$sql = 'SELECT i.codigo, i.fecha_incidente, i.fecha_creacion, i.estado, i.solicitud_sancion,
               us.nombre AS alumno_nombre, us.apellido AS alumno_apellido,
               c.id AS curso_id, c.nombre AS curso, c.especialidad,
               dv.nombre AS division,
               d.id AS docente_id, dus.nombre AS docente_nombre, dus.apellido AS docente_apellido
        FROM informes i
        JOIN alumnos al ON al.id=i.alumno_id
        JOIN usuarios us ON us.id=al.usuario_id
        JOIN divisiones dv ON dv.id=i.division_id
        JOIN cursos c ON c.id=dv.curso_id
        JOIN docentes d ON d.id=i.docente_id
        JOIN usuarios dus ON dus.id=d.usuario_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY i.fecha_incidente DESC';
$st = $pdo->prepare($sql);
$st->execute($params);
$filas = $st->fetchAll();

// ---------- Agregación en memoria a partir de la misma consulta ----------
$total = count($filas);
$resueltos = 0;
$pendientes = 0;
$mensuales = array_fill(1, 12, 0);
$porCategoria = [];
$porCurso = [];
foreach ($filas as $r) {
    if ($r['estado'] === 'RESUELTO') $resueltos++;
    elseif ($r['estado'] !== 'ANULADO') $pendientes++;

    $timestamp = strtotime($r['fecha_incidente']);
    if ($timestamp) {
        $m = (int)date('n', $timestamp);
        if ($m >= 1 && $m <= 12) $mensuales[$m]++;
    }

    $cat = trim((string)($r['solicitud_sancion'] ?? '')) ?: 'Sin categoría';
    $porCategoria[$cat] = ($porCategoria[$cat] ?? 0) + 1;

    $cur = trim((string)$r['curso'] . ' ' . (string)($r['especialidad'] ?? ''));
    $porCurso[$cur] = ($porCurso[$cur] ?? 0) + 1;
}
arsort($porCategoria);
arsort($porCurso);
$maxMes = max(1, ...array_values($mensuales));

// ---------- Exportación CSV (misma consulta, mismos permisos) ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!has_permission('estadisticas.ver')) {
        denegar_acceso('No tenés permiso para exportar las estadísticas.');
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="estadisticas_colegio_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
    fputcsv($salida, ['Código', 'Fecha incidente', 'Alumno', 'Curso', 'División',
                      'Docente', 'Categoría', 'Estado', 'Fecha creación'], ';');
    foreach ($filas as $r) {
        fputcsv($salida, [
            csv_campo($r['codigo']),
            csv_campo($r['fecha_incidente']),
            csv_campo(trim($r['alumno_apellido'] . ', ' . $r['alumno_nombre'])),
            csv_campo(trim($r['curso'] . ' ' . ($r['especialidad'] ?? ''))),
            csv_campo($r['division']),
            csv_campo(trim($r['docente_apellido'] . ', ' . $r['docente_nombre'])),
            csv_campo($r['solicitud_sancion'] ?: 'Sin categoría'),
            csv_campo(estado_nombre($r['estado'])),
            csv_campo($r['fecha_creacion']),
        ], ';');
    }
    fclose($salida);
    exit;
}

// Query para el botón de exportación (conserva los filtros activos).
$filtrosExport = array_filter([
    'mes' => $mes, 'anio' => $anio, 'curso' => $curso, 'division' => $division,
    'categoria' => $categoria, 'estado' => $estado, 'docente' => $docente,
], fn($v) => $v !== '' && $v !== 0);
$filtrosExport['export'] = 'csv';

layout_header('Estadísticas');
?>
<h1>Estadísticas institucionales</h1>
<p class="muted">Datos de informes de convivencia según los filtros seleccionados (<?= $total ?> registro(s)).</p>

<form class="toolbar" method="get" action="<?= e(url('estadisticas.php')) ?>">
  <label>Mes
    <select name="mes">
      <option value="">Todos</option>
      <?php foreach ($MESES as $num => $nombre): ?>
        <option value="<?= $num ?>" <?= $mes === $num ? 'selected' : '' ?>><?= e($nombre) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Año<input type="number" name="anio" min="1900" max="2100" value="<?= $anio ?: '' ?>" placeholder="Todos"></label>
  <label>Curso
    <select name="curso">
      <option value="">Todos</option>
      <?php foreach ($cursos as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $curso === (int)$c['id'] ? 'selected' : '' ?>>
          <?= e(trim($c['nombre'] . ' ' . ($c['especialidad'] ?? ''))) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>División
    <select name="division">
      <option value="">Todas</option>
      <?php foreach ($divisiones as $dv): ?>
        <option value="<?= (int)$dv['id'] ?>" <?= $division === (int)$dv['id'] ? 'selected' : '' ?>>
          <?= e(trim($dv['curso'] . ' ' . ($dv['especialidad'] ? $dv['especialidad'] . ' ' : '') . $dv['division'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Categoría
    <select name="categoria">
      <option value="">Todas</option>
      <?php foreach ($categorias as $cat): ?>
        <option value="<?= e($cat) ?>" <?= $categoria === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Estado
    <select name="estado">
      <option value="">Todos</option>
      <?php foreach (ESTADOS_INFORME as $clave => $nombre): ?>
        <option value="<?= e($clave) ?>" <?= $estado === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Docente
    <select name="docente">
      <option value="">Todos</option>
      <?php foreach ($docentes as $d): ?>
        <option value="<?= (int)$d['id'] ?>" <?= $docente === (int)$d['id'] ? 'selected' : '' ?>>
          <?= e(trim($d['apellido'] . ', ' . $d['nombre'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn" type="submit">Filtrar</button>
  <a class="btn g" href="<?= e(url('estadisticas.php')) ?>">Limpiar</a>
</form>

<div class="grid g3">
  <div class="stat"><span>Informes generados</span><b><?= $total ?></b></div>
  <div class="stat"><span>Resueltos</span><b style="color:var(--verde)"><?= $resueltos ?></b></div>
  <div class="stat"><span>Pendientes</span><b style="color:var(--ambar)"><?= $pendientes ?></b></div>
</div>

<div class="card" style="margin-top:1rem">
  <h2>Evolución mensual de informes</h2>
  <?php if ($total === 0): ?>
    <p class="muted">No hay informes para el período seleccionado.</p>
  <?php else: ?>
    <div class="chart">
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <?php
          $n = $mensuales[$m];
          $altura = max(2, (int)round($n * 85 / $maxMes)); // 85% deja lugar a la etiqueta
        ?>
        <div class="bar" style="height:<?= $altura ?>%" title="<?= e($MESES[$m] . ': ' . $n . ' informe(s)') ?>"><span><?= $n ?></span></div>
      <?php endfor; ?>
    </div>
    <div class="chart-labels">
      <?php for ($m = 1; $m <= 12; $m++): ?><span><?= e(substr($MESES[$m], 0, 3)) ?></span><?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<div class="grid g2" style="margin-top:1rem">
  <div class="card">
    <h2>Distribución por categoría</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Categoría</th><th>Informes</th><th>%</th></tr></thead>
      <tbody>
      <?php if (!$porCategoria): ?>
        <tr><td colspan="3" class="muted">Sin datos</td></tr>
      <?php else: foreach ($porCategoria as $nombre => $n): ?>
        <tr><td><?= e($nombre) ?></td><td><?= (int)$n ?></td><td><?= $total ? round($n * 100 / $total, 1) : 0 ?>%</td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <h2>Distribución por curso</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Curso</th><th>Informes</th><th>%</th></tr></thead>
      <tbody>
      <?php if (!$porCurso): ?>
        <tr><td colspan="3" class="muted">Sin datos</td></tr>
      <?php else: foreach ($porCurso as $nombre => $n): ?>
        <tr><td><?= e($nombre) ?></td><td><?= (int)$n ?></td><td><?= $total ? round($n * 100 / $total, 1) : 0 ?>%</td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php if (has_permission('estadisticas.ver')): ?>
<div class="btn-row" style="margin-top:1rem">
  <a class="btn" href="<?= e(url('estadisticas.php?' . http_build_query($filtrosExport))) ?>">Exportar CSV</a>
</div>
<?php endif; ?>
<?php layout_footer(); ?>
