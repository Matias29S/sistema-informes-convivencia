<?php
/** Panel personalizado según el rol del usuario. */
require_once __DIR__ . '/config/app.php';
require_login();
global $pdo;
$u = current_user();
$rol = $u['rol_id'];
$uid = (int)$u['id'];

$totalInformes = (int)$pdo->query('SELECT COUNT(*) FROM informes')->fetchColumn();
$pendientes = (int)$pdo->query("SELECT COUNT(*) FROM informes WHERE estado NOT IN ('RESUELTO','ANULADO')")->fetchColumn();
$porRol = [];
foreach ($pdo->query('SELECT rol_id, COUNT(*) n FROM usuarios GROUP BY rol_id') as $r) $porRol[$r['rol_id']] = (int)$r['n'];

$ultimos = [];
if (in_array($rol, ['ADMIN','DIRECTIVO'], true)) {
  $ultimos = $pdo->query('SELECT i.codigo,i.fecha_creacion,i.estado,us.nombre,us.apellido,ud.nombre dn,ud.apellido da
    FROM informes i JOIN alumnos al ON al.id=i.alumno_id JOIN usuarios us ON us.id=al.usuario_id
    JOIN docentes d ON d.id=i.docente_id JOIN usuarios ud ON ud.id=d.usuario_id ORDER BY i.fecha_creacion DESC LIMIT 6')->fetchAll();
} elseif ($rol === 'PRECEPTOR') {
  $st = $pdo->prepare('SELECT dp.division_id FROM division_preceptor dp JOIN docentes doc ON doc.id=dp.preceptor_id WHERE doc.usuario_id=?');
  $st->execute([$uid]); $misDivs = array_column($st->fetchAll(), 'division_id');
  if ($misDivs) {
    $in = implode(',', array_fill(0, count($misDivs), '?'));
    $st = $pdo->prepare("SELECT i.codigo,i.fecha_creacion,i.estado,us.nombre,us.apellido,ud.nombre dn,ud.apellido da
      FROM informes i JOIN alumnos al ON al.id=i.alumno_id JOIN usuarios us ON us.id=al.usuario_id
      JOIN docentes d ON d.id=i.docente_id JOIN usuarios ud ON ud.id=d.usuario_id WHERE i.division_id IN ($in) ORDER BY i.fecha_creacion DESC LIMIT 6");
    $st->execute($misDivs); $ultimos = $st->fetchAll();
  }
} elseif ($rol === 'DOCENTE') {
  $st = $pdo->prepare('SELECT i.codigo,i.fecha_creacion,i.estado,us.nombre,us.apellido,ud.nombre dn,ud.apellido da
    FROM informes i JOIN alumnos al ON al.id=i.alumno_id JOIN usuarios us ON us.id=al.usuario_id
    JOIN docentes d ON d.id=i.docente_id JOIN usuarios ud ON ud.id=d.usuario_id WHERE d.usuario_id=? ORDER BY i.fecha_creacion DESC LIMIT 6');
  $st->execute([$uid]); $ultimos = $st->fetchAll();
} elseif ($rol === 'ALUMNO') {
  $st = $pdo->prepare('SELECT i.codigo,i.fecha_creacion,i.estado,us.nombre,us.apellido,ud.nombre dn,ud.apellido da
    FROM informes i JOIN alumnos al ON al.id=i.alumno_id JOIN usuarios us ON us.id=al.usuario_id
    JOIN docentes d ON d.id=i.docente_id JOIN usuarios ud ON ud.id=d.usuario_id WHERE al.usuario_id=? ORDER BY i.fecha_creacion DESC LIMIT 6');
  $st->execute([$uid]); $ultimos = $st->fetchAll();
} elseif ($rol === 'TUTOR') {
  $st = $pdo->prepare('SELECT i.codigo,i.fecha_creacion,i.estado,us.nombre,us.apellido,ud.nombre dn,ud.apellido da
    FROM informes i JOIN alumnos al ON al.id=i.alumno_id JOIN usuarios us ON us.id=al.usuario_id
    JOIN docentes d ON d.id=i.docente_id JOIN usuarios ud ON ud.id=d.usuario_id
    JOIN alumno_tutor at ON at.alumno_id=al.id JOIN tutores t ON t.id=at.tutor_id
    WHERE t.usuario_id=? ORDER BY i.fecha_creacion DESC LIMIT 6');
  $st->execute([$uid]); $ultimos = $st->fetchAll();
}

layout_header('Panel de control');
?>

<h1>Bienvenido/a, <?= e($u['nombre']) ?></h1>
<p class="muted">Rol: <?= e($u['rol_nombre']) ?> · Último acceso: <?= e($u['ultimo_acceso'] ?: 'primer ingreso') ?></p>

<?php if ($rol === 'ADMIN'): ?>
 <div class="grid g4">
  <div class="stat"><span>Alumnos</span><b><?= $porRol['ALUMNO'] ?? 0 ?></b></div>
  <div class="stat"><span>Docentes</span><b><?= $porRol['DOCENTE'] ?? 0 ?></b></div>
  <div class="stat"><span>Cursos</span><b><?= (int)$pdo->query('SELECT COUNT(*) FROM cursos')->fetchColumn() ?></b></div>
  <div class="stat"><span>Usuarios</span><b><?= array_sum($porRol) ?></b></div>
  <div class="stat"><span>Informes</span><b><?= $totalInformes ?></b></div>
  <div class="stat"><span>Pendientes</span><b style="color:var(--ambar)"><?= $pendientes ?></b></div>
 </div>
 <div class="btn-row">
  <a class="btn" href="<?= e(url('admin/usuarios.php')) ?>">Administrar cuentas</a>
  <a class="btn" href="<?= e(url('alumnos.php')) ?>">Alumnos</a>
  <a class="btn" href="<?= e(url('docentes.php')) ?>">Docentes</a>
  <a class="btn" href="<?= e(url('cursos.php')) ?>">Cursos</a>
  <a class="btn g" href="<?= e(url('estadisticas.php')) ?>">Estadísticas</a>
 </div>
 <div class="card"><h2>Últimos movimientos</h2><div class="table-wrap"><table>
  <thead><tr><th>Acción</th><th>Usuario</th><th>Fecha</th></tr></thead><tbody>
  <?php foreach ($pdo->query('SELECT h.accion,h.fecha,u.nombre,u.apellido FROM historial_modificaciones h LEFT JOIN usuarios u ON u.id=h.usuario_id ORDER BY h.fecha DESC LIMIT 10') as $h): ?>
   <tr><td><?= e($h['accion']) ?></td><td><?= e(trim($h['nombre'] . ' ' . $h['apellido']) ?: 'Sistema') ?></td><td><?= e($h['fecha']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>

<?php elseif ($rol === 'DOCENTE'): ?>
 <?php
 $st = $pdo->prepare('SELECT COUNT(DISTINCT ad.division_id) FROM asignaciones_docentes ad JOIN docentes d ON d.id=ad.docente_id WHERE d.usuario_id=?');
 $st->execute([$uid]); $misCursos = (int)$st->fetchColumn();
 $st = $pdo->prepare('SELECT COUNT(*) FROM alumnos a JOIN divisiones dv ON dv.id=a.division_id JOIN asignaciones_docentes ad ON ad.division_id=dv.id JOIN docentes d ON d.id=ad.docente_id WHERE d.usuario_id=? AND a.activo=1');
 $st->execute([$uid]); $misAlumnos = (int)$st->fetchColumn();
 $st = $pdo->prepare('SELECT COUNT(*) FROM informes i JOIN docentes d ON d.id=i.docente_id JOIN usuarios ud ON ud.id=d.usuario_id WHERE d.usuario_id=?');
 $st->execute([$uid]); $misInformes = (int)$st->fetchColumn();
 ?>
 <div class="grid g4">
  <div class="stat"><span>Mis cursos</span><b><?= $misCursos ?></b></div>
  <div class="stat"><span>Alumnos</span><b><?= $misAlumnos ?></b></div>
  <div class="stat"><span>Informes creados</span><b><?= $misInformes ?></b></div>
  <div class="stat"><span>Pendientes</span><b style="color:var(--ambar)"><?= $pendientes ?></b></div>
 </div>
 <div class="btn-row">
  <a class="btn" href="<?= e(url('informes/crear.php')) ?>">Crear informe de convivencia</a>
  <a class="btn g" href="<?= e(url('informes/listado.php')) ?>">Mis informes</a>
  <a class="btn g" href="<?= e(url('docentes.php')) ?>">Mis cursos y alumnos</a>
 </div>

<?php elseif ($rol === 'PRECEPTOR'): ?>
 <?php
 $st = $pdo->prepare('SELECT COUNT(*) FROM division_preceptor dp JOIN docentes d ON d.id=dp.preceptor_id WHERE d.usuario_id=?');
 $st->execute([$uid]); $misDiv = (int)$st->fetchColumn();
 $nuevos = (int)$pdo->query("SELECT COUNT(*) FROM informes WHERE estado='PENDIENTE_REVISION'")->fetchColumn();
 $seguimiento = (int)$pdo->query("SELECT COUNT(*) FROM informes WHERE estado LIKE 'PENDIENTE%' AND estado<>'PENDIENTE_REVISION'")->fetchColumn();
 ?>
 <div class="grid g4">
  <div class="stat"><span>Divisiones asignadas</span><b><?= $misDiv ?></b></div>
  <div class="stat"><span>Alumnos registrados</span><b><?= $porRol['ALUMNO'] ?? 0 ?></b></div>
  <div class="stat"><span>Informes nuevos</span><b style="color:var(--ambar)"><?= $nuevos ?></b></div>
  <div class="stat"><span>En seguimiento</span><b><?= $seguimiento ?></b></div>
 </div>
 <div class="btn-row">
  <a class="btn" href="<?= e(url('informes/listado.php?estado=PENDIENTE_REVISION')) ?>">Revisar informes</a>
  <a class="btn g" href="<?= e(url('informes/seguimiento.php')) ?>">Seguimiento</a>
  <a class="btn g" href="<?= e(url('cursos.php')) ?>">Cursos y divisiones</a>
 </div>

<?php elseif ($rol === 'DIRECTIVO'): ?>
 <?php
 $resueltos = (int)$pdo->query("SELECT COUNT(*) FROM informes WHERE estado='RESUELTO'")->fetchColumn();
 $aRes = (int)$pdo->query("SELECT COUNT(*) FROM informes WHERE estado='PENDIENTE_RESOLUCION'")->fetchColumn();
 ?>
 <div class="grid g4">
  <div class="stat"><span>Total de informes</span><b><?= $totalInformes ?></b></div>
  <div class="stat"><span>Pendientes de resolución</span><b style="color:var(--ambar)"><?= $aRes ?></b></div>
  <div class="stat"><span>Resueltos</span><b style="color:var(--verde)"><?= $resueltos ?></b></div>
  <div class="stat"><span>Anulados</span><b><?= (int)$pdo->query("SELECT COUNT(*) FROM informes WHERE estado='ANULADO'")->fetchColumn() ?></b></div>
 </div>
 <div class="grid g2">
  <div class="card"><h3>Distribución por curso</h3><div class="table-wrap"><table>
   <thead><tr><th>Curso</th><th>Informes</th></tr></thead><tbody>
   <?php foreach ($pdo->query('SELECT c.nombre cur, COUNT(*) n FROM informes i JOIN divisiones d ON d.id=i.division_id JOIN cursos c ON c.id=d.curso_id GROUP BY c.id ORDER BY n DESC LIMIT 8') as $r): ?>
    <tr><td><?= e($r['cur']) ?></td><td><?= (int)$r['n'] ?></td></tr><?php endforeach; ?>
   </tbody></table></div></div>
  <div class="card"><h3>Distribución por categoría</h3><div class="table-wrap"><table>
   <thead><tr><th>Categoría</th><th>Informes</th></tr></thead><tbody>
   <?php foreach ($pdo->query('SELECT solicitud_sancion, COUNT(*) n FROM informes GROUP BY solicitud_sancion ORDER BY n DESC LIMIT 8') as $r): ?>
    <tr><td><?= e($r['solicitud_sancion'] ?: 'Sin categoría') ?></td><td><?= (int)$r['n'] ?></td></tr><?php endforeach; ?>
   </tbody></table></div></div>
 </div>
 <div class="btn-row">
  <a class="btn" href="<?= e(url('informes/listado.php?estado=PENDIENTE_RESOLUCION')) ?>">Resolver informes</a>
  <a class="btn g" href="<?= e(url('estadisticas.php')) ?>">Estadísticas institucionales</a>
 </div>

<?php elseif ($rol === 'ALUMNO'): ?>
 <?php
 $st = $pdo->prepare('SELECT a.id, dv.nombre division, c.nombre curso FROM alumnos a JOIN usuarios us ON us.id=a.usuario_id JOIN divisiones dv ON dv.id=a.division_id JOIN cursos c ON c.id=dv.curso_id WHERE us.id=?');
 $st->execute([$uid]); $alumno = $st->fetch();
 $st = $pdo->prepare('SELECT COUNT(*) FROM informes WHERE alumno_id=?'); $st->execute([$alumno['id'] ?? 0]); $misInf = (int)$st->fetchColumn();
 $st = $pdo->prepare("SELECT COUNT(*) FROM informes i WHERE i.alumno_id=? AND i.estado='PENDIENTE_DESCARGO' AND NOT EXISTS (SELECT 1 FROM descargos d WHERE d.informe_id=i.id)");
 $st->execute([$alumno['id'] ?? 0]); $descPend = (int)$st->fetchColumn();
 ?>
 <div class="grid g3">
  <div class="stat"><span>Curso y división</span><b style="font-size:1.1rem"><?= e(trim(($alumno['curso'] ?? '') . ' ' . ($alumno['division'] ?? ''))) ?></b></div>
  <div class="stat"><span>Mis informes</span><b><?= $misInf ?></b></div>
  <div class="stat"><span>Descargos pendientes</span><b style="color:var(--ambar)"><?= $descPend ?></b></div>
 </div>
 <div class="btn-row">
  <a class="btn" href="<?= e(url('informes/mis_informes.php')) ?>">Ver mis informes</a>
  <a class="btn g" href="<?= e(url('informes/mis_informes.php?tab=descargos')) ?>">Realizar descargo</a>
  <a class="btn g" href="<?= e(url('notificaciones/listado.php')) ?>">Notificaciones</a>
 </div>

<?php elseif ($rol === 'TUTOR'): ?>
 <?php
 $st = $pdo->prepare('SELECT COUNT(*) FROM alumno_tutor at JOIN tutores t ON t.id=at.tutor_id WHERE t.usuario_id=?');
 $st->execute([$uid]); $cantAl = (int)$st->fetchColumn();
  $st = $pdo->prepare('SELECT COUNT(*) FROM informes i JOIN alumno_tutor at ON at.alumno_id=i.alumno_id JOIN tutores t ON t.id=at.tutor_id WHERE t.usuario_id=?');
  $st->execute([$uid]); $infTutor = (int)$st->fetchColumn();
  $st = $pdo->prepare('SELECT COUNT(*) FROM notificaciones WHERE usuario_id=?');
  $st->execute([$uid]); $notifTutor = (int)$st->fetchColumn();
 ?>
 <div class="grid g3">
  <div class="stat"><span>Alumnos asociados</span><b><?= $cantAl ?></b></div>
  <div class="stat"><span>Informes de mis alumnos</span><b><?= $infTutor ?></b></div>
  <div class="stat"><span>Mis notificaciones</span><b><?= $notifTutor ?></b></div>
 </div>
 <div class="btn-row">
  <a class="btn" href="<?= e(url('tutores/alumnos.php')) ?>">Alumnos asociados</a>
  <a class="btn g" href="<?= e(url('notificaciones/listado.php')) ?>">Notificaciones</a>
 </div>
<?php endif; ?>

<div class="card">
 <h2>Actividad reciente de informes</h2>
 <?php if (!$ultimos): ?><p class="muted">Todavía no hay informes registrados.</p>
 <?php else: ?><div class="table-wrap"><table>
  <thead><tr><th>Código</th><th>Alumno</th><th>Docente</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>
  <?php foreach ($ultimos as $r): ?>
   <tr><td><a href="<?= e(url('informes/detalle.php?id=' . $r['codigo'])) ?>"><?= e($r['codigo']) ?></a></td>
       <td><?= e($r['nombre'] . ' ' . $r['apellido']) ?></td>
       <td><?= e($r['dn'] . ' ' . $r['da']) ?></td>
       <td><span class="pill <?= $r['estado'] === 'RESUELTO' ? 'ok' : ($r['estado'] === 'ANULADO' ? 'gray' : 'warn') ?>"><?= e(estado_nombre($r['estado'])) ?></span></td>
       <td><?= e($r['fecha_creacion']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php layout_footer(); ?>
