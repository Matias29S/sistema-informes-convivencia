<?php
/** Listado de informes con filtros y alcance por rol. */
require_once __DIR__ . '/../config/app.php';
require_login();
global $pdo;

$u = current_user();
$rol = $u['rol_id'];
$uid = (int)$u['id'];

// Alumnos y tutores consultan sus informes desde su propia pantalla.
if ($rol === 'ALUMNO' || $rol === 'TUTOR') redirect('informes/mis_informes.php');
require_permission('informes.ver');

// Restricción de datos según el rol.
$scopeWhere = '';
$scopeParams = [];
if ($rol === 'DOCENTE') {
    $st = $pdo->prepare('SELECT id FROM docentes WHERE usuario_id=?');
    $st->execute([$uid]);
    $docenteId = (int)$st->fetchColumn();
    if (!$docenteId) denegar_acceso('Tu cuenta no tiene un perfil docente asociado.');
    $scopeWhere = ' AND i.docente_id=?';
    $scopeParams[] = $docenteId;
} elseif ($rol === 'PRECEPTOR') {
    $st = $pdo->prepare('SELECT id FROM docentes WHERE usuario_id=?');
    $st->execute([$uid]);
    $docenteId = (int)$st->fetchColumn();
    if (!$docenteId) denegar_acceso('Tu cuenta no tiene un perfil de preceptor asociado.');
    $scopeWhere = ' AND i.division_id IN (SELECT division_id FROM division_preceptor WHERE preceptor_id=?)';
    $scopeParams[] = $docenteId;
} elseif (!in_array($rol, ['ADMIN', 'DIRECTIVO'], true)) {
    denegar_acceso('Tu rol no tiene acceso al listado general de informes.');
}

// ---------- Filtros ----------
$filtros = [];
$params = $scopeParams;

$estado = (string)($_GET['estado'] ?? '');
if ($estado !== '' && isset(ESTADOS_INFORME[$estado])) {
    $filtros[] = 'i.estado=?';
    $params[] = $estado;
} else {
    $estado = '';
}

$divisionId = (int)($_GET['division_id'] ?? 0);
if ($divisionId > 0) {
    $filtros[] = 'i.division_id=?';
    $params[] = $divisionId;
}

function fecha_filtro_valida(string $raw): bool {
    $raw = trim($raw);
    if ($raw === '') return false;
    $d = DateTime::createFromFormat('Y-m-d', $raw);
    return $d && $d->format('Y-m-d') === $raw;
}
$desde = trim((string)($_GET['desde'] ?? ''));
$hasta = trim((string)($_GET['hasta'] ?? ''));
if (fecha_filtro_valida($desde)) {
    $filtros[] = 'DATE(i.fecha_incidente)>=?';
    $params[] = $desde;
    $desdeVal = $desde;
} else { $desdeVal = ''; }
if (fecha_filtro_valida($hasta)) {
    $filtros[] = 'DATE(i.fecha_incidente)<=?';
    $params[] = $hasta;
    $hastaVal = $hasta;
} else { $hastaVal = ''; }

$codigo = mb_substr(trim((string)($_GET['codigo'] ?? '')), 0, 20);
if ($codigo !== '') {
    $filtros[] = 'i.codigo LIKE ?';
    $params[] = '%' . $codigo . '%';
}

$sql = 'SELECT i.id, i.codigo, i.fecha_incidente, i.fecha_creacion, i.estado,
               us.nombre AS alumno_nombre, us.apellido AS alumno_apellido,
               c.nombre AS curso, c.especialidad, dv.nombre AS division,
               dus.nombre AS docente_nombre, dus.apellido AS docente_apellido
        FROM informes i
        JOIN alumnos al ON al.id=i.alumno_id
        JOIN usuarios us ON us.id=al.usuario_id
        JOIN divisiones dv ON dv.id=i.division_id
        JOIN cursos c ON c.id=dv.curso_id
        JOIN docentes d ON d.id=i.docente_id
        JOIN usuarios dus ON dus.id=d.usuario_id
        WHERE 1=1 ' . $scopeWhere . ' AND ' . (implode(' AND ', $filtros) ?: '1=1') . '
        ORDER BY i.fecha_creacion DESC LIMIT 500';
$st = $pdo->prepare($sql);
$st->execute($params);
$informes = $st->fetchAll();
$divisiones = $pdo->query(
    'SELECT dv.id, c.nombre AS curso, c.especialidad, dv.nombre AS division
     FROM divisiones dv JOIN cursos c ON c.id=dv.curso_id
     ORDER BY c.anio, c.especialidad, dv.nombre'
)->fetchAll();

function clase_estado(string $estado): string {
    if ($estado === 'RESUELTO') return 'ok';
    if ($estado === 'ANULADO') return 'gray';
    if (strpos($estado, 'PENDIENTE') === 0) return 'warn';
    return 'info';
}

layout_header('Listado de informes');
?>
<h1>Informes de convivencia</h1>
<p class="muted"><?= count($informes) ?> resultado(s) · el listado respeta tu alcance según el rol.</p>

<form class="toolbar" method="get" action="<?= e(url('informes/listado.php')) ?>">
  <label>Código<input type="text" name="codigo" value="<?= e($codigo) ?>" placeholder="INF-0001"></label>
  <label>Estado
    <select name="estado">
      <option value="">Todos</option>
      <?php foreach (ESTADOS_INFORME as $clave => $nombre): ?>
        <option value="<?= e($clave) ?>" <?= $estado === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Curso / división
    <select name="division_id">
      <option value="">Todas</option>
      <?php foreach ($divisiones as $dv): ?>
        <option value="<?= (int)$dv['id'] ?>" <?= $divisionId === (int)$dv['id'] ? 'selected' : '' ?>>
          <?= e(trim($dv['curso'] . ' ' . ($dv['especialidad'] ? $dv['especialidad'] . ' ' : '') . $dv['division'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Desde<input type="date" name="desde" value="<?= e($desdeVal) ?>"></label>
  <label>Hasta<input type="date" name="hasta" value="<?= e($hastaVal) ?>"></label>
  <button class="btn" type="submit">Filtrar</button>
  <a class="btn g" href="<?= e(url('informes/listado.php')) ?>">Limpiar</a>
</form>

<div class="card">
<?php if (!$informes): ?>
  <p class="muted">No hay informes que coincidan con los filtros.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Código</th><th>Alumno</th><th>Curso / división</th><th>Docente</th><th>Fecha del incidente</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($informes as $r): ?>
      <tr>
        <td><a href="<?= e(url('informes/detalle.php?id=' . rawurlencode($r['codigo']))) ?>"><?= e($r['codigo']) ?></a></td>
        <td><?= e(trim($r['alumno_apellido'] . ', ' . $r['alumno_nombre'])) ?></td>
        <td><?= e(trim($r['curso'] . ' ' . ($r['especialidad'] ? $r['especialidad'] . ' ' : '') . $r['division'])) ?></td>
        <td><?= e(trim($r['docente_apellido'] . ', ' . $r['docente_nombre'])) ?></td>
        <td><?= e(fmt_fecha($r['fecha_incidente'])) ?></td>
        <td><span class="pill <?= e(clase_estado($r['estado'])) ?>"><?= e(estado_nombre($r['estado'])) ?></span></td>
        <td><a class="btn s" href="<?= e(url('informes/detalle.php?id=' . rawurlencode($r['codigo']))) ?>">Ver</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>

<div class="btn-row">
  <?php if (has_permission('informes.crear')): ?>
    <a class="btn" href="<?= e(url('informes/crear.php')) ?>">Crear informe</a>
  <?php endif; ?>
  <?php if ($rol === 'PRECEPTOR'): ?>
    <a class="btn g" href="<?= e(url('informes/seguimiento.php')) ?>">Seguimiento</a>
  <?php endif; ?>
</div>
<?php layout_footer(); ?>
