<?php
/** Informes propios (alumno) o de alumnos asociados (tutor). Sin acceso cruzado. */
require_once __DIR__ . '/../config/app.php';
require_role('ALUMNO', 'TUTOR');
global $pdo;

$u = current_user();
$rol = $u['rol_id'];
$uid = (int)$u['id'];

$alumnosIds = [];
if ($rol === 'ALUMNO') {
    $st = $pdo->prepare('SELECT id FROM alumnos WHERE usuario_id=?');
    $st->execute([$uid]);
    $alumnosIds = array_map('intval', array_column($st->fetchAll(), 'id'));
} else {
    $st = $pdo->prepare('SELECT at.alumno_id FROM alumno_tutor at JOIN tutores t ON t.id=at.tutor_id WHERE t.usuario_id=?');
    $st->execute([$uid]);
    $alumnosIds = array_map('intval', array_column($st->fetchAll(), 'alumno_id'));
}

$tab = (($_GET['tab'] ?? '') === 'descargos') ? 'descargos' : 'todos';
$informes = [];
if ($alumnosIds) {
    $marks = implode(',', array_fill(0, count($alumnosIds), '?'));
    $where = "i.alumno_id IN ({$marks})";
    if ($tab === 'descargos') {
        $where .= " AND i.estado='PENDIENTE_DESCARGO'"
                . ' AND NOT EXISTS (SELECT 1 FROM descargos d WHERE d.informe_id=i.id)';
    }
    $st = $pdo->prepare(
        "SELECT i.codigo, i.fecha_incidente, i.fecha_creacion, i.estado,
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
         WHERE {$where}
         ORDER BY i.fecha_creacion DESC LIMIT 500"
    );
    $st->execute($alumnosIds);
    $informes = $st->fetchAll();
}

// Cantidad de descargos pendientes para el contador de la pestaña.
$pendientes = 0;
if ($alumnosIds) {
    $marks = implode(',', array_fill(0, count($alumnosIds), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM informes i WHERE i.alumno_id IN ({$marks})
                         AND i.estado='PENDIENTE_DESCARGO'
                         AND NOT EXISTS (SELECT 1 FROM descargos d WHERE d.informe_id=i.id)");
    $st->execute($alumnosIds);
    $pendientes = (int)$st->fetchColumn();
}

function clase_estado_alumno(string $estado): string {
    if ($estado === 'RESUELTO') return 'ok';
    if ($estado === 'ANULADO') return 'gray';
    if (strpos($estado, 'PENDIENTE') === 0) return 'warn';
    return 'info';
}

layout_header('Mis informes');
?>
<h1><?= $rol === 'ALUMNO' ? 'Mis informes' : 'Informes de mis alumnos/as' ?></h1>
<p class="muted">Solo se muestran los informes que te corresponden.</p>

<div class="btn-row">
  <a class="btn <?= $tab === 'todos' ? '' : 'g' ?>" href="<?= e(url('informes/mis_informes.php')) ?>">Todos</a>
  <a class="btn <?= $tab === 'descargos' ? '' : 'g' ?>" href="<?= e(url('informes/mis_informes.php?tab=descargos')) ?>">Descargos pendientes (<?= $pendientes ?>)</a>
</div>

<div class="card">
<?php if (!$informes): ?>
  <p class="muted"><?= $tab === 'descargos' ? 'No tenés descargos pendientes.' : 'No hay informes registrados.' ?></p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Código</th><th>Alumno/a</th><th>Curso / división</th><th>Docente</th><th>Fecha</th><th>Estado</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php foreach ($informes as $r): ?>
      <?php $urlDetalle = 'informes/detalle.php?id=' . rawurlencode($r['codigo']); ?>
      <tr>
        <td><a href="<?= e(url($urlDetalle)) ?>"><?= e($r['codigo']) ?></a></td>
        <td><?= e(trim($r['alumno_apellido'] . ', ' . $r['alumno_nombre'])) ?></td>
        <td><?= e(trim($r['curso'] . ' ' . ($r['especialidad'] ? $r['especialidad'] . ' ' : '') . $r['division'])) ?></td>
        <td><?= e(trim($r['docente_apellido'] . ', ' . $r['docente_nombre'])) ?></td>
        <td><?= e(fmt_fecha($r['fecha_incidente'])) ?></td>
        <td><span class="pill <?= e(clase_estado_alumno($r['estado'])) ?>"><?= e(estado_nombre($r['estado'])) ?></span></td>
        <td>
          <a class="btn s" href="<?= e(url($urlDetalle)) ?>">Ver</a>
          <a class="btn s" href="<?= e(pdf_url($r['codigo'], $r['actualizado_en'] ?? null)) ?>">PDF</a>
          <?php if ($rol === 'ALUMNO' && $r['estado'] === 'PENDIENTE_DESCARGO'): ?>
            <a class="btn s" href="<?= e(url('informes/descargo.php?id=' . rawurlencode($r['codigo']))) ?>">Descargar</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
<?php layout_footer(); ?>
