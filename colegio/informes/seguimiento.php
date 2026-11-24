<?php
/** Seguimiento de informes en trámite, limitado a las divisiones del preceptor. Solo lectura. */
require_once __DIR__ . '/../config/app.php';
require_role('PRECEPTOR');
global $pdo;

$u = current_user();
$uid = (int)$u['id'];
$st = $pdo->prepare('SELECT id FROM docentes WHERE usuario_id=?');
$st->execute([$uid]);
$preceptorId = (int)$st->fetchColumn();
if (!$preceptorId) denegar_acceso('Tu cuenta no tiene un perfil de preceptor asociado.');

$estadosSeguimiento = [
    'PENDIENTE_REVISION',
    'EN_REVISION',
    'PENDIENTE_DESCARGO',
    'PENDIENTE_CONSEJO_AULA',
    'PENDIENTE_CONSEJO_CONVIVENCIA',
    'PENDIENTE_RESOLUCION',
];
$estadoFiltro = (string)($_GET['estado'] ?? '');
if ($estadoFiltro !== '' && !in_array($estadoFiltro, $estadosSeguimiento, true)) $estadoFiltro = '';

$whereEstados = $estadoFiltro !== '' ? 'i.estado=?' : 'i.estado IN (' . implode(',', array_fill(0, count($estadosSeguimiento), '?')) . ')';
$params = [$preceptorId];
if ($estadoFiltro === '') $params = array_merge($params, $estadosSeguimiento);
else $params[] = $estadoFiltro;

$st = $pdo->prepare(
    'SELECT i.codigo, i.fecha_creacion, i.fecha_incidente, i.estado,
            us.nombre AS alumno_nombre, us.apellido AS alumno_apellido,
            c.nombre AS curso, c.especialidad, dv.nombre AS division,
            dus.nombre AS docente_nombre, dus.apellido AS docente_apellido,
            (SELECT COUNT(*) FROM descargos d WHERE d.informe_id=i.id) AS tiene_descargo,
            (SELECT COUNT(*) FROM consejo_aula ca WHERE ca.informe_id=i.id) AS tiene_ca,
            (SELECT COUNT(*) FROM consejo_convivencia cc WHERE cc.informe_id=i.id) AS tiene_cc,
            (SELECT COUNT(*) FROM resoluciones r WHERE r.informe_id=i.id) AS tiene_resolucion
     FROM informes i
     JOIN division_preceptor dp ON dp.division_id=i.division_id AND dp.preceptor_id=?
     JOIN alumnos al ON al.id=i.alumno_id
     JOIN usuarios us ON us.id=al.usuario_id
     JOIN divisiones dv ON dv.id=i.division_id
     JOIN cursos c ON c.id=dv.curso_id
     JOIN docentes d ON d.id=i.docente_id
     JOIN usuarios dus ON dus.id=d.usuario_id
     WHERE ' . $whereEstados . '
     ORDER BY i.fecha_creacion DESC LIMIT 500'
);
$st->execute($params);
$casos = $st->fetchAll();

$st = $pdo->prepare(
    'SELECT i.estado, COUNT(*) AS n
     FROM informes i JOIN division_preceptor dp ON dp.division_id=i.division_id AND dp.preceptor_id=?
     WHERE i.estado IN (' . implode(',', array_fill(0, count($estadosSeguimiento), '?')) . ')
     GROUP BY i.estado'
);
$st->execute(array_merge([$preceptorId], $estadosSeguimiento));
$conteos = [];
foreach ($st->fetchAll() as $r) $conteos[$r['estado']] = (int)$r['n'];

function clase_estado_seguimiento(string $estado): string {
    if (strpos($estado, 'PENDIENTE') === 0) return 'warn';
    return 'info';
}

layout_header('Seguimiento de informes');
?>
<h1>Seguimiento</h1>
<p class="muted">Informes en trámite de tus divisiones asignadas. Esta vista es de solo lectura.</p>

<div class="grid g4">
  <?php foreach ($estadosSeguimiento as $estado): ?>
    <div class="stat"><span><?= e(estado_nombre($estado)) ?></span><b><?= $conteos[$estado] ?? 0 ?></b></div>
  <?php endforeach; ?>
</div>

<form class="toolbar" method="get" action="<?= e(url('informes/seguimiento.php')) ?>">
  <label>Estado
    <select name="estado">
      <option value="">Todos los estados en trámite</option>
      <?php foreach ($estadosSeguimiento as $estado): ?>
        <option value="<?= e($estado) ?>" <?= $estadoFiltro === $estado ? 'selected' : '' ?>><?= e(estado_nombre($estado)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn" type="submit">Filtrar</button>
  <a class="btn g" href="<?= e(url('informes/listado.php')) ?>">Ver listado completo</a>
</form>

<div class="card">
<?php if (!$casos): ?>
  <p class="muted">No hay casos en seguimiento con los filtros seleccionados.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Código</th><th>Alumno/a</th><th>División</th><th>Docente</th><th>Fecha</th><th>Estado</th><th>Instancias</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($casos as $r): ?>
      <tr>
        <td><a href="<?= e(url('informes/detalle.php?id=' . rawurlencode($r['codigo']))) ?>"><?= e($r['codigo']) ?></a></td>
        <td><?= e(trim($r['alumno_apellido'] . ', ' . $r['alumno_nombre'])) ?></td>
        <td><?= e(trim($r['curso'] . ' ' . ($r['especialidad'] ? $r['especialidad'] . ' ' : '') . $r['division'])) ?></td>
        <td><?= e(trim($r['docente_apellido'] . ', ' . $r['docente_nombre'])) ?></td>
        <td><?= e(fmt_fecha($r['fecha_incidente'])) ?></td>
        <td><span class="pill <?= e(clase_estado_seguimiento($r['estado'])) ?>"><?= e(estado_nombre($r['estado'])) ?></span></td>
        <td>
          <small>
            Descargo: <?= (int)$r['tiene_descargo'] ? 'sí' : 'no' ?> ·
            C. Aula: <?= (int)$r['tiene_ca'] ? 'sí' : 'no' ?> ·
            C. Convivencia: <?= (int)$r['tiene_cc'] ? 'sí' : 'no' ?> ·
            Resolución: <?= (int)$r['tiene_resolucion'] ? 'sí' : 'no' ?>
          </small>
        </td>
        <td>
          <a class="btn s" href="<?= e(url('informes/detalle.php?id=' . rawurlencode($r['codigo']))) ?>">Ver</a>
          <a class="btn s" href="<?= e(url('informes/historial.php?id=' . rawurlencode($r['codigo']))) ?>">Historial</a>
          <a class="btn s" href="<?= e(pdf_url($r['codigo'], $r['actualizado_en'] ?? null)) ?>">PDF</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
<?php layout_footer(); ?>
