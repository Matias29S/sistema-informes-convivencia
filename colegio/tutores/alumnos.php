<?php
/** Portal del tutor: alumnos asociados, sus informes y sus propias notificaciones. */
require_once __DIR__ . '/../config/app.php';
require_role('TUTOR');
global $pdo;

$u = current_user();
$uid = (int)$u['id'];

// El perfil de tutor se resuelve desde el usuario de sesión: nunca desde GET/POST.
$st = $pdo->prepare('SELECT id FROM tutores WHERE usuario_id=?');
$st->execute([$uid]);
$tutorId = (int)$st->fetchColumn();
if ($tutorId <= 0) denegar_acceso('Tu cuenta no tiene un perfil de tutor asociado.');

// ---------- Alumnos asociados (alcance estricto por tutor_id) ----------
$st = $pdo->prepare(
    'SELECT a.id AS alumno_id, a.legajo, a.activo,
            us.nombre, us.apellido, us.dni,
            c.nombre AS curso, c.especialidad, dv.nombre AS division,
            at.relacion
     FROM alumno_tutor at
     JOIN alumnos a ON a.id=at.alumno_id
     JOIN usuarios us ON us.id=a.usuario_id
     JOIN divisiones dv ON dv.id=a.division_id
     JOIN cursos c ON c.id=dv.curso_id
     WHERE at.tutor_id=?
     ORDER BY c.anio, c.especialidad, dv.nombre, us.apellido, us.nombre'
);
$st->execute([$tutorId]);
$alumnos = $st->fetchAll();

// ---------- Informes de esos alumnos (misma restricción por subconsulta) ----------
$st = $pdo->prepare(
    'SELECT i.id, i.codigo, i.fecha_incidente, i.estado, i.alumno_id,
            dus.nombre AS docente_nombre, dus.apellido AS docente_apellido
     FROM informes i
     JOIN docentes d ON d.id=i.docente_id
     JOIN usuarios dus ON dus.id=d.usuario_id
     WHERE i.alumno_id IN (SELECT at.alumno_id FROM alumno_tutor at WHERE at.tutor_id=?)
     ORDER BY i.fecha_incidente DESC
     LIMIT 300'
);
$st->execute([$tutorId]);
$informes = $st->fetchAll();
$porAlumno = [];
foreach ($informes as $inf) $porAlumno[(int)$inf['alumno_id']][] = $inf;

// ---------- Notificaciones del tutor ----------
$st = $pdo->prepare(
    'SELECT id, titulo, descripcion, tipo, enlace, leida, fecha
     FROM notificaciones WHERE usuario_id=?
     ORDER BY leida ASC, fecha DESC LIMIT 10'
);
$st->execute([$uid]);
$notificaciones = $st->fetchAll();

/** Sólo rutas internas relativas; evita javascript:, http:// y //dominio. */
function enlace_tutor_seguro($enlace): string {
    $enlace = trim((string)$enlace);
    if ($enlace === '') return '';
    if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $enlace) || str_starts_with($enlace, '//') || str_contains($enlace, '\\') || str_contains($enlace, '..')) return '';
    if (!preg_match('~^[A-Za-z0-9_./?=&%#:-]+$~', $enlace)) return '';
    return url($enlace);
}

function pill_estado_tutor(string $estado): string {
    if ($estado === 'RESUELTO') return 'ok';
    if ($estado === 'ANULADO') return 'gray';
    return str_starts_with($estado, 'PENDIENTE') ? 'warn' : 'info';
}

layout_header('Alumnos asociados');
?>
<h1>Mis alumnos asociados</h1>
<p class="muted">Información de tus hijos/as o representados, con sus informes de convivencia.</p>

<?php if (!$alumnos): ?>
  <div class="card"><p class="muted">Todavía no hay alumnos asociados a tu cuenta. Contactá a administración si creés que es un error.</p></div>
<?php else: ?>
  <?php foreach ($alumnos as $a): ?>
    <?php $aid = (int)$a['alumno_id']; $lis = $porAlumno[$aid] ?? []; ?>
    <div class="card" style="margin-bottom:1rem">
      <h2><?= e(trim($a['apellido'] . ', ' . $a['nombre'])) ?></h2>
      <p class="muted">
        Legajo: <?= e($a['legajo']) ?> · DNI: <?= e($a['dni']) ?> ·
        <?= e(trim($a['curso'] . ' ' . ($a['especialidad'] ? $a['especialidad'] . ' ' : '') . $a['division'])) ?> ·
        Vinculación: <?= e($a['relacion']) ?>
        <?= (int)$a['activo'] === 1 ? '' : ' · <span class="pill gray">Inactivo</span>' ?>
      </p>
      <h3>Informes de convivencia (<?= count($lis) ?>)</h3>
      <?php if (!$lis): ?>
        <p class="muted">Este alumno no tiene informes registrados.</p>
      <?php else: ?>
        <div class="table-wrap"><table>
          <thead><tr><th>Código</th><th>Fecha del incidente</th><th>Docente</th><th>Estado</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($lis as $inf): ?>
            <?php $detalle = url('informes/detalle.php?id=' . rawurlencode($inf['codigo'])); ?>
            <tr>
              <td><?= e($inf['codigo']) ?></td>
              <td><?= e($inf['fecha_incidente']) ?></td>
              <td><?= e(trim($inf['docente_apellido'] . ', ' . $inf['docente_nombre'])) ?></td>
              <td><span class="pill <?= e(pill_estado_tutor($inf['estado'])) ?>"><?= e(estado_nombre($inf['estado'])) ?></span></td>
              <td><a class="btn s" href="<?= e($detalle) ?>">Ver detalle</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="card" style="margin-top:1rem">
  <h2>Mis notificaciones</h2>
  <?php if (!$notificaciones): ?>
    <p class="muted">No tenés notificaciones.</p>
  <?php else: ?>
    <?php foreach ($notificaciones as $n): ?>
      <?php $destino = enlace_tutor_seguro($n['enlace']); ?>
      <div style="border-bottom:1px solid var(--borde);padding:.6rem 0">
        <b><?= e($n['titulo']) ?></b>
        <span class="pill <?= (int)$n['leida'] === 1 ? 'gray' : 'warn' ?>"><?= (int)$n['leida'] === 1 ? 'Leída' : 'No leída' ?></span>
        <p class="muted" style="margin:.3rem 0"><span class="pill info"><?= e($n['tipo']) ?></span> · <?= e($n['fecha']) ?></p>
        <p><?= e($n['descripcion']) ?></p>
        <div class="btn-row" style="margin-top:.3rem">
          <?php if ($destino !== ''): ?><a class="btn s" href="<?= e($destino) ?>">Abrir destino</a><?php endif; ?>
          <?php if ((int)$n['leida'] === 0): ?>
            <?php /* Sin data-notif-id: app.js resuelve marcar_leida.php de forma relativa y aquí apuntaría a /tutores/. */ ?>
            <form method="post" action="<?= e(url('notificaciones/marcar_leida.php')) ?>" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
              <button class="btn s" type="submit">Marcar como leída</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <div class="btn-row" style="margin-top:.75rem">
    <a class="btn g" href="<?= e(url('notificaciones/listado.php')) ?>">Ver todas mis notificaciones</a>
  </div>
</div>
<?php layout_footer(); ?>
