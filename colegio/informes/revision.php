<?php
/** Revisión inicial del informe: toma en revisión y solicitud de descargo al alumno. */
require_once __DIR__ . '/../config/app.php';
require_permission('informes.revisar');
global $pdo;

$u = current_user();
$rol = $u['rol_id'];
$uid = (int)$u['id'];
if (!in_array($rol, ['ADMIN', 'DIRECTIVO', 'PRECEPTOR'], true)) {
    denegar_acceso('Tu rol no puede revisar informes.');
}

$idRaw = trim((string)($_GET['id'] ?? ''));
if ($idRaw === '') {
    flash('err', 'No se indicó un informe.');
    redirect('informes/listado.php');
}
$st = $pdo->prepare(
    'SELECT i.id, i.codigo, i.estado, i.descripcion, i.alumno_id, i.division_id,
            us.nombre AS alumno_nombre, us.apellido AS alumno_apellido,
            c.nombre AS curso, dv.nombre AS division
     FROM informes i
     JOIN alumnos al ON al.id=i.alumno_id
     JOIN usuarios us ON us.id=al.usuario_id
     JOIN divisiones dv ON dv.id=i.division_id
     JOIN cursos c ON c.id=dv.curso_id
     WHERE ' . (ctype_digit($idRaw) ? 'i.id=?' : 'i.codigo=?')
);
$st->execute([ctype_digit($idRaw) ? (int)$idRaw : mb_substr($idRaw, 0, 20)]);
$inf = $st->fetch() ?: null;
if (!$inf) denegar_acceso('El informe solicitado no existe.');

if ($rol === 'PRECEPTOR') {
    $st = $pdo->prepare('SELECT d.id FROM docentes d WHERE d.usuario_id=?');
    $st->execute([$uid]);
    $preceptorId = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT 1 FROM division_preceptor WHERE preceptor_id=? AND division_id=?');
    $st->execute([$preceptorId, (int)$inf['division_id']]);
    if (!$preceptorId || !$st->fetchColumn()) denegar_acceso('Este informe no corresponde a tus divisiones asignadas.');
}

$estadosValidos = ['PENDIENTE_REVISION', 'EN_REVISION'];
if (!in_array($inf['estado'], $estadosValidos, true)) {
    flash('info', 'El informe ya superó la etapa de revisión.');
    redirect('informes/detalle.php?id=' . rawurlencode($inf['codigo']));
}

$detalleUrl = 'informes/detalle.php?id=' . rawurlencode($inf['codigo']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = (string)($_POST['accion'] ?? '');
    $obs = texto($_POST['observaciones'] ?? '', 0, 5000) ?? '';

    if ($accion === 'tomar') {
        $nuevoEstado = 'EN_REVISION';
        $obs = $obs !== '' ? $obs : 'Informe tomado en revisión por ' . trim($u['nombre'] . ' ' . $u['apellido']);
        $mensaje = 'Informe tomado en revisión.';
    } elseif ($accion === 'solicitar_descargo') {
        $nuevoEstado = 'PENDIENTE_DESCARGO';
        $obs = $obs !== '' ? $obs : 'Se solicita el descargo del/de la alumno/a';
        $mensaje = 'Se solicitó el descargo al alumno/a y a su tutor/a.';
    } else {
        denegar_acceso('Acción de revisión no válida.');
    }

    try {
        $pdo->beginTransaction();
        cambiar_estado((int)$inf['id'], $nuevoEstado, $obs);
        audit('REVISAR_INFORME', 'informes', (string)$inf['id'],
              'Informe ' . $inf['codigo'] . ' · ' . estado_nombre($nuevoEstado));
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('err', 'No se pudo registrar la revisión. Intentá nuevamente.');
        redirect('informes/revision.php?id=' . rawurlencode($inf['codigo']));
    }

    if ($accion === 'solicitar_descargo') {
        $asunto = 'Descargo pendiente · ' . $inf['codigo'];
        $cuerpo = 'Se solicitó tu descargo en el marco del informe ' . $inf['codigo'] . '. Ingresá al sistema para presentarlo.';

        // El descargo es del alumno: se notifica a su usuario y a los tutores asociados.
        $st = $pdo->prepare('SELECT usuario_id FROM alumnos WHERE id=?');
        $st->execute([(int)$inf['alumno_id']]);
        $alumnoUsuarioId = (int)$st->fetchColumn();
        if ($alumnoUsuarioId > 0) notify($alumnoUsuarioId, $asunto, $cuerpo, 'informe', $detalleUrl);

        $st = $pdo->prepare(
            'SELECT DISTINCT us.id FROM alumno_tutor at
             JOIN tutores t ON t.id=at.tutor_id
             JOIN usuarios us ON us.id=t.usuario_id
             WHERE at.alumno_id=? AND us.activo=1'
        );
        $st->execute([(int)$inf['alumno_id']]);
        foreach ($st->fetchAll() as $tutor) {
            notify((int)$tutor['id'], $asunto,
                   'Se solicitó el descargo correspondiente al informe ' . $inf['codigo'] . ' de un alumno a tu cargo.',
                   'informe', $detalleUrl);
        }
    }

    flash('ok', $mensaje);
    redirect($detalleUrl);
}

layout_header('Revisión · ' . $inf['codigo']);
?>
<h1>Revisión del informe</h1>
<p class="muted">Informe <?= e($inf['codigo']) ?> · <?= e(trim($inf['alumno_apellido'] . ', ' . $inf['alumno_nombre'])) ?> · <?= e(trim($inf['curso'] . ' ' . $inf['division'])) ?></p>
<div class="grid g2">
 <div class="card">
  <h2>Hecho informado</h2>
  <div class="alert info"><?= e($inf['descripcion']) ?></div>
  <p><strong>Estado actual:</strong> <span class="pill warn"><?= e(estado_nombre($inf['estado'])) ?></span></p>
  <p class="muted">La revisión permite tomar el caso en tratamiento y, a continuación, solicitar el descargo del/de la alumno/a antes de intervenir el Consejo de Aula.</p>
 </div>
 <div class="card">
  <h2>Registrar revisión</h2>
  <form method="post" action="<?= e(url('informes/revision.php?id=' . rawurlencode($inf['codigo']))) ?>">
    <?= csrf_field() ?>
    <label for="observaciones">Observaciones de la revisión (opcional)</label>
    <textarea id="observaciones" name="observaciones" rows="4"><?= e(old('observaciones')) ?></textarea>
    <div class="btn-row">
      <button class="btn g" type="submit" name="accion" value="tomar">Tomar en revisión</button>
      <button class="btn" type="submit" name="accion" value="solicitar_descargo" data-confirm="¿Solicitar el descargo del/de la alumno/a? Se enviará una notificación.">Solicitar descargo</button>
    </div>
    <p><a class="muted" href="<?= e(url($detalleUrl)) ?>">Volver al detalle</a></p>
  </form>
 </div>
</div>
<?php layout_footer(); ?>
