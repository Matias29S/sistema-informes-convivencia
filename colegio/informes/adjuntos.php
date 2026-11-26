<?php
/** Adjuntos del informe: alta validada y listado. El acceso respeta el alcance por rol. */
require_once __DIR__ . '/../config/app.php';
require_login();
global $pdo;

$u = current_user();
$rol = $u['rol_id'];
$uid = (int)$u['id'];

$idRaw = trim((string)($_GET['id'] ?? ($_POST['id'] ?? '')));
if ($idRaw === '') {
    flash('err', 'No se indicó un informe.');
    redirect('informes/listado.php');
}
$st = $pdo->prepare('SELECT i.id, i.codigo, i.estado, i.alumno_id, i.division_id, i.docente_id
                     FROM informes i WHERE ' . (ctype_digit($idRaw) ? 'i.id=?' : 'i.codigo=?'));
$st->execute([ctype_digit($idRaw) ? (int)$idRaw : mb_substr($idRaw, 0, 20)]);
$inf = $st->fetch() ?: null;
if (!$inf) denegar_acceso('El informe solicitado no existe.');

// Misma restricción de datos que el detalle del informe.
$miAlumnoId = 0; $miDocenteId = 0; $miTutorId = 0;
if ($rol === 'ALUMNO') {
    $st = $pdo->prepare('SELECT id FROM alumnos WHERE usuario_id=?');
    $st->execute([$uid]); $miAlumnoId = (int)$st->fetchColumn();
} elseif ($rol === 'TUTOR') {
    $st = $pdo->prepare('SELECT id FROM tutores WHERE usuario_id=?');
    $st->execute([$uid]); $miTutorId = (int)$st->fetchColumn();
} elseif (in_array($rol, ['DOCENTE', 'PRECEPTOR'], true)) {
    $st = $pdo->prepare('SELECT id FROM docentes WHERE usuario_id=?');
    $st->execute([$uid]); $miDocenteId = (int)$st->fetchColumn();
}

$puedeVer = false;
if (in_array($rol, ['ADMIN', 'DIRECTIVO'], true)) $puedeVer = true;
elseif ($rol === 'DOCENTE') $puedeVer = $miDocenteId > 0 && (int)$inf['docente_id'] === $miDocenteId;
elseif ($rol === 'PRECEPTOR') {
    $st = $pdo->prepare('SELECT 1 FROM division_preceptor WHERE preceptor_id=? AND division_id=?');
    $st->execute([$miDocenteId, (int)$inf['division_id']]);
    $puedeVer = (bool)$st->fetchColumn();
} elseif ($rol === 'ALUMNO') $puedeVer = $miAlumnoId > 0 && (int)$inf['alumno_id'] === $miAlumnoId;
elseif ($rol === 'TUTOR') {
    $st = $pdo->prepare('SELECT 1 FROM alumno_tutor WHERE tutor_id=? AND alumno_id=?');
    $st->execute([$miTutorId, (int)$inf['alumno_id']]);
    $puedeVer = (bool)$st->fetchColumn();
}
if (!$puedeVer) denegar_acceso('No tenés autorización para ver los adjuntos de este informe.');

$detalleUrl = 'informes/detalle.php?id=' . rawurlencode($inf['codigo']);
$anulado = $inf['estado'] === 'ANULADO';
$puedeSubir = !$anulado && in_array($rol, ['ADMIN', 'DIRECTIVO', 'PRECEPTOR', 'DOCENTE'], true) && has_permission('informes.ver');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($anulado) denegar_acceso('No se pueden agregar adjuntos a un informe anulado.');
    if (!$puedeSubir) denegar_acceso('Tu rol no puede adjuntar archivos.');
    $guardado = subir_archivo($_FILES['archivo'] ?? [], (int)$inf['id']);
    if (!$guardado) {
        flash('err', 'No se pudo adjuntar el archivo. Verificá el formato (PDF, JPG, PNG o WEBP) y el tamaño máximo (5 MB).');
    } else {
        audit('SUBIR_ADJUNTO', 'archivos_adjuntos', (string)$inf['id'], 'Adjunto ' . $guardado . ' en informe ' . $inf['codigo']);
        flash('ok', 'Archivo adjuntado correctamente.');
    }
    redirect('informes/adjuntos.php?id=' . rawurlencode($inf['codigo']));
}

$st = $pdo->prepare('SELECT a.*, us.nombre, us.apellido FROM archivos_adjuntos a
                     LEFT JOIN usuarios us ON us.id=a.subido_por
                     WHERE a.informe_id=? ORDER BY a.fecha DESC');
$st->execute([(int)$inf['id']]);
$adjuntos = $st->fetchAll();

layout_header('Adjuntos · ' . $inf['codigo']);
?>
<h1>Adjuntos del informe <?= e($inf['codigo']) ?></h1>
<div class="grid g2">
 <div class="card">
  <h2>Archivos adjuntos</h2>
  <?php if (!$adjuntos): ?><p class="muted">No hay archivos adjuntos.</p>
  <?php else: ?><div class="table-wrap"><table>
   <thead><tr><th>Nombre</th><th>Tipo</th><th>Tamaño</th><th>Subido por</th><th>Fecha</th><th></th></tr></thead><tbody>
   <?php foreach ($adjuntos as $a): ?>
    <tr>
     <td><?= e($a['nombre_original']) ?></td>
     <td><?= e($a['mime']) ?></td>
     <td><?= number_format((int)$a['tamano'] / 1024, 1) ?> KB</td>
     <td><?= e(trim($a['apellido'] . ', ' . $a['nombre']) ?: 'Sistema') ?></td>
     <td><?= e($a['fecha']) ?></td>
     <td><a class="btn s" href="<?= e(url('informes/descargar_adjunto.php?id=' . rawurlencode($inf['codigo']) . '&archivo=' . rawurlencode($a['nombre_guardado']))) ?>">Descargar</a></td>
    </tr>
   <?php endforeach; ?>
   </tbody></table></div><?php endif; ?>
 </div>
 <?php if ($puedeSubir): ?>
 <div class="card">
  <h2>Agregar archivo</h2>
  <form method="post" enctype="multipart/form-data" action="<?= e(url('informes/adjuntos.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e($inf['codigo']) ?>">
    <label for="archivo">Archivo (PDF, JPG, PNG o WEBP · máx. 5 MB)</label>
    <input type="file" id="archivo" name="archivo" accept=".pdf,image/jpeg,image/png,image/webp" required>
    <div class="btn-row">
      <button class="btn" type="submit">Adjuntar</button>
      <a class="btn g" href="<?= e(url($detalleUrl)) ?>">Volver al informe</a>
    </div>
  </form>
 </div>
 <?php endif; ?>
</div>
<?php layout_footer(); ?>
