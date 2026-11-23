<?php
/** Presentación del descargo: exclusiva del alumno titular del informe, una sola vez. */
require_once __DIR__ . '/../config/app.php';
require_role('ALUMNO');
global $pdo;

$u = current_user();
$uid = (int)$u['id'];
$st = $pdo->prepare('SELECT a.id, a.legajo FROM alumnos a WHERE a.usuario_id=?');
$st->execute([$uid]);
$alumno = $st->fetch() ?: null;
if (!$alumno) denegar_acceso('Tu cuenta no tiene un perfil de alumno asociado.');
$alumnoId = (int)$alumno['id'];

$idRaw = trim((string)($_GET['id'] ?? ''));
if ($idRaw === '') {
    flash('err', 'No se indicó un informe.');
    redirect('informes/mis_informes.php');
}
$st = $pdo->prepare(
    'SELECT i.id, i.codigo, i.alumno_id, i.estado, i.fecha_incidente, i.descripcion, i.division_id,
            us.nombre AS alumno_nombre, us.apellido AS alumno_apellido
     FROM informes i JOIN alumnos al ON al.id=i.alumno_id
     JOIN usuarios us ON us.id=al.usuario_id
     WHERE ' . (ctype_digit($idRaw) ? 'i.id=?' : 'i.codigo=?')
);
$st->execute([ctype_digit($idRaw) ? (int)$idRaw : mb_substr($idRaw, 0, 20)]);
$inf = $st->fetch() ?: null;
if (!$inf || (int)$inf['alumno_id'] !== $alumnoId) denegar_acceso('Este informe no pertenece a tu cuenta.');

$st = $pdo->prepare('SELECT id FROM descargos WHERE informe_id=?');
$st->execute([(int)$inf['id']]);
$existeDescargo = (bool)$st->fetchColumn();
$estadoValido = $inf['estado'] === 'PENDIENTE_DESCARGO';

// El descargo es único e inmodificable: cualquier intento posterior se rechaza.
if ($existeDescargo || !$estadoValido) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') denegar_acceso('El descargo ya fue presentado o el informe ya no admite descargos.');
    flash('info', 'El descargo ya fue presentado o el informe ya no admite descargos.');
    redirect('informes/detalle.php?id=' . rawurlencode($inf['codigo']));
}

$detalleUrl = 'informes/detalle.php?id=' . rawurlencode($inf['codigo']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $descripcion = texto($_POST['descripcion'] ?? '', 10, 5000);
    $observaciones = texto($_POST['observaciones'] ?? '', 0, 5000) ?? '';
    if (!$descripcion) {
        set_old(['descripcion' => (string)($_POST['descripcion'] ?? ''), 'observaciones' => (string)($_POST['observaciones'] ?? '')]);
        flash('err', 'La versión de los hechos debe tener entre 10 y 5000 caracteres.');
        redirect('informes/descargo.php?id=' . rawurlencode($inf['codigo']));
    }

    // Reverificación dentro de la operación para evitar duplicados por concurrencia.
    $st = $pdo->prepare('SELECT 1 FROM descargos WHERE informe_id=?');
    $st->execute([(int)$inf['id']]);
    if ($st->fetchColumn()) denegar_acceso('El descargo ya fue presentado.');

    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO descargos (informe_id, alumno_id, descripcion, observaciones, fecha_presentacion, firmado) VALUES (?,?,?,?,?,1)');
        $st->execute([(int)$inf['id'], $alumnoId, $descripcion, $observaciones, date('Y-m-d H:i:s')]);
        cambiar_estado((int)$inf['id'], 'PENDIENTE_CONSEJO_AULA', 'Descargo presentado por el alumno');
        audit('CREAR_DESCARGO', 'descargos', (string)$inf['id'], 'Descargo del informe ' . $inf['codigo']);
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('err', 'No se pudo registrar el descargo. Intentá nuevamente.');
        redirect('informes/descargo.php?id=' . rawurlencode($inf['codigo']));
    }
    clear_old();

    $asunto = 'Descargo presentado · ' . $inf['codigo'];
    $cuerpo = 'El/la alumno/a presentó el descargo correspondiente al informe ' . $inf['codigo'] . '.';
    // Preceptores de la división del informe.
    $st = $pdo->prepare('SELECT DISTINCT us.id FROM division_preceptor dp
                         JOIN docentes d ON d.id=dp.preceptor_id
                         JOIN usuarios us ON us.id=d.usuario_id
                         WHERE dp.division_id=? AND us.activo=1');
    $st->execute([(int)$inf['division_id']]);
    foreach ($st->fetchAll() as $dest) notify((int)$dest['id'], $asunto, $cuerpo, 'informe', $detalleUrl);
    // Directivos.
    $st = $pdo->prepare("SELECT id FROM usuarios WHERE rol_id='DIRECTIVO' AND activo=1");
    $st->execute();
    foreach ($st->fetchAll() as $dest) notify((int)$dest['id'], $asunto, $cuerpo, 'informe', $detalleUrl);

    flash('ok', 'Descargo presentado correctamente.');
    redirect($detalleUrl);
}

layout_header('Presentar descargo · ' . $inf['codigo']);
?>
<h1>Presentar descargo</h1>
<p class="muted">Informe <?= e($inf['codigo']) ?> · incidente del <?= e(fmt_fecha($inf['fecha_incidente'])) ?>. No podrás editarlo una vez enviado.</p>
<div class="grid g2">
<div class="card">
  <h2>Datos del informe</h2>
  <p><strong>Alumno/a:</strong> <?= e(trim($inf['alumno_apellido'] . ', ' . $inf['alumno_nombre'])) ?></p>
  <p><strong>Legajo:</strong> <?= e($alumno['legajo']) ?></p>
  <p><strong>Hecho informado:</strong></p>
  <div class="alert info"><?= e($inf['descripcion']) ?></div>
  <p class="muted">Estado actual: <span class="pill warn"><?= e(estado_nombre($inf['estado'])) ?></span></p>
</div>
<div class="card">
  <h2>Tu descargo</h2>
  <form method="post" action="<?= e(url('informes/descargo.php?id=' . rawurlencode($inf['codigo']))) ?>">
    <?= csrf_field() ?>
    <label for="descripcion">Versión de los hechos *</label>
    <textarea id="descripcion" name="descripcion" rows="8" required><?= e(old('descripcion')) ?></textarea>
    <label for="observaciones">Observaciones adicionales</label>
    <textarea id="observaciones" name="observaciones" rows="4"><?= e(old('observaciones')) ?></textarea>
    <label>Fecha de presentación (automática)</label>
    <input type="text" value="<?= e(date('d/m/Y H:i')) ?>" readonly>
    <label>Identificación del alumno (automática)</label>
    <input type="text" value="<?= e(trim($inf['alumno_apellido'] . ', ' . $inf['alumno_nombre']) . ' · Legajo ' . $alumno['legajo'] . ' · ID ' . $uid) ?>" readonly>
    <div class="btn-row">
      <button class="btn" type="submit">Enviar descargo</button>
      <a class="btn g" href="<?= e(url($detalleUrl)) ?>">Cancelar</a>
    </div>
  </form>
</div>
</div>
<?php layout_footer(); ?>
