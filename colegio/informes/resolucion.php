<?php
/** Resolución final del informe: decisión, medida institucional y fundamentación. */
require_once __DIR__ . '/../config/app.php';
require_role('DIRECTIVO', 'ADMIN');
require_permission('informes.resolver');
global $pdo;

$u = current_user();
$uid = (int)$u['id'];

$idRaw = trim((string)($_GET['id'] ?? ''));
if ($idRaw === '') {
    flash('err', 'No se indicó un informe.');
    redirect('informes/listado.php');
}
$st = $pdo->prepare(
    'SELECT i.id, i.codigo, i.estado, i.division_id, i.descripcion, i.alumno_id,
            us.id AS alumno_usuario_id, us.nombre AS alumno_nombre, us.apellido AS alumno_apellido,
            du.id AS docente_usuario_id
     FROM informes i
     JOIN alumnos al ON al.id=i.alumno_id
     JOIN usuarios us ON us.id=al.usuario_id
     JOIN docentes d ON d.id=i.docente_id
     JOIN usuarios du ON du.id=d.usuario_id
     WHERE ' . (ctype_digit($idRaw) ? 'i.id=?' : 'i.codigo=?')
);
$st->execute([ctype_digit($idRaw) ? (int)$idRaw : mb_substr($idRaw, 0, 20)]);
$inf = $st->fetch() ?: null;
if (!$inf) denegar_acceso('El informe solicitado no existe.');
if ($inf['estado'] === 'ANULADO') denegar_acceso('No se puede resolver un informe anulado.');

$detalleUrl = 'informes/detalle.php?id=' . rawurlencode($inf['codigo']);
$st = $pdo->prepare('SELECT id FROM resoluciones WHERE informe_id=?');
$st->execute([(int)$inf['id']]);
$yaResuelto = (bool)$st->fetchColumn();
if ($yaResuelto) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') denegar_acceso('El informe ya tiene una resolución registrada.');
    flash('info', 'El informe ya tiene una resolución registrada.');
    redirect($detalleUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $decision = texto($_POST['decision'] ?? '', 3, 5000);
    $medida = texto($_POST['medida'] ?? '', 0, 5000) ?? '';
    $fundamentacion = texto($_POST['fundamentacion'] ?? '', 10, 5000);
    $observaciones = texto($_POST['observaciones'] ?? '', 0, 5000) ?? '';
    $errores = [];
    if (!$decision) $errores[] = 'Indicá la decisión.';
    if (!$fundamentacion) $errores[] = 'La fundamentación debe tener al menos 10 caracteres.';
    if ($errores) {
        set_old([
            'decision' => (string)($_POST['decision'] ?? ''),
            'medida' => (string)($_POST['medida'] ?? ''),
            'fundamentacion' => (string)($_POST['fundamentacion'] ?? ''),
            'observaciones' => (string)($_POST['observaciones'] ?? ''),
        ]);
        flash('err', implode(' ', $errores));
        redirect('informes/resolucion.php?id=' . rawurlencode($inf['codigo']));
    }

    $fecha = ahora_db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO resoluciones (informe_id, decision, medida, fundamentacion, responsable_id, fecha, observaciones) VALUES (?,?,?,?,?,?,?)');
        $st->execute([(int)$inf['id'], $decision, $medida, $fundamentacion, $uid, $fecha, $observaciones]);
        $obsEstado = $observaciones !== '' ? $observaciones : 'Resolución registrada';
        cambiar_estado((int)$inf['id'], 'RESUELTO', $obsEstado);
        audit('RESOLVER_INFORME', 'resoluciones', (string)$inf['id'], 'Informe ' . $inf['codigo'] . ' resuelto');
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('err', 'No se pudo guardar la resolución.');
        redirect('informes/resolucion.php?id=' . rawurlencode($inf['codigo']));
    }
    clear_old();

    $asunto = 'Informe resuelto ' . $inf['codigo'];
    $cuerpo = 'Se registró la resolución del informe ' . $inf['codigo'] . '. Ingresá al sistema para ver el detalle.';
    $destinatarios = [(int)$inf['alumno_usuario_id']];

    $st = $pdo->prepare('SELECT DISTINCT us.id FROM alumno_tutor at
                         JOIN tutores t ON t.id=at.tutor_id
                         JOIN usuarios us ON us.id=t.usuario_id
                         WHERE at.alumno_id=? AND us.activo=1');
    $st->execute([(int)$inf['alumno_id']]);
    foreach ($st->fetchAll() as $row) $destinatarios[] = (int)$row['id'];

    $destinatarios[] = (int)$inf['docente_usuario_id'];

    $st = $pdo->prepare('SELECT DISTINCT us.id FROM division_preceptor dp
                         JOIN docentes d ON d.id=dp.preceptor_id
                         JOIN usuarios us ON us.id=d.usuario_id
                         WHERE dp.division_id=? AND us.activo=1');
    $st->execute([(int)$inf['division_id']]);
    foreach ($st->fetchAll() as $row) $destinatarios[] = (int)$row['id'];

    foreach (array_unique($destinatarios) as $destino) {
        if ($destino > 0) notify($destino, $asunto, $cuerpo, 'informe', $detalleUrl);
    }

    flash('ok', 'Resolución registrada. El informe quedó en estado Resuelto.');
    redirect($detalleUrl);
}

layout_header('Resolución · ' . $inf['codigo']);
?>
<h1>Resolución del informe</h1>
<p class="muted">Informe <?= e($inf['codigo']) ?> · <?= e(trim($inf['alumno_apellido'] . ', ' . $inf['alumno_nombre'])) ?></p>
<div class="grid g2">
<div class="card">
  <h2>Antecedentes</h2>
  <div class="alert info"><?= e($inf['descripcion']) ?></div>
  <p><strong>Estado actual:</strong> <span class="pill warn"><?= e(estado_nombre($inf['estado'])) ?></span></p>
</div>
<div class="card">
  <h2>Registro de resolución</h2>
  <form method="post" action="<?= e(url('informes/resolucion.php?id=' . rawurlencode($inf['codigo']))) ?>" data-confirm="¿Confirmar la resolución de este informe? Se notificará a las personas intervinientes.">
    <?= csrf_field() ?>
    <label for="decision">Decisión *</label>
    <textarea id="decision" name="decision" rows="4" required><?= e(old('decision')) ?></textarea>
    <label for="medida">Medida institucional (opcional)</label>
    <textarea id="medida" name="medida" rows="3"><?= e(old('medida')) ?></textarea>
    <label for="fundamentacion">Fundamentación *</label>
    <textarea id="fundamentacion" name="fundamentacion" rows="6" required><?= e(old('fundamentacion')) ?></textarea>
    <label for="observaciones">Observaciones</label>
    <textarea id="observaciones" name="observaciones" rows="3"><?= e(old('observaciones')) ?></textarea>
    <label>Responsable (automático)</label>
    <input type="text" value="<?= e(trim($u['apellido'] . ', ' . $u['nombre']) . ' · ID ' . $uid) ?>" readonly>
    <label>Fecha (automática)</label>
    <input type="text" value="<?= e(date('d/m/Y H:i')) ?>" readonly>
    <div class="btn-row">
      <button class="btn" type="submit">Registrar resolución</button>
      <a class="btn g" href="<?= e(url($detalleUrl)) ?>">Cancelar</a>
    </div>
  </form>
</div>
</div>
<?php layout_footer(); ?>
