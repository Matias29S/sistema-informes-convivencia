<?php
/** Intervención del Consejo de Convivencia. Requiere consejos.registrar y alcance sobre la división. */
require_once __DIR__ . '/../config/app.php';
require_permission('consejos.registrar');
global $pdo;

$u = current_user();
$rol = $u['rol_id'];
$uid = (int)$u['id'];
if (!in_array($rol, ['DIRECTIVO', 'PRECEPTOR', 'ADMIN'], true)) {
    denegar_acceso('Tu rol no puede registrar intervenciones de consejos.');
}

$idRaw = trim((string)($_GET['id'] ?? ''));
if ($idRaw === '') {
    flash('err', 'No se indicó un informe.');
    redirect('informes/listado.php');
}
$st = $pdo->prepare(
    'SELECT i.id, i.codigo, i.division_id, i.estado, i.descripcion,
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
    $st = $pdo->prepare('SELECT id FROM docentes d WHERE d.usuario_id=?');
    $st->execute([$uid]);
    $preceptorId = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT 1 FROM division_preceptor WHERE preceptor_id=? AND division_id=?');
    $st->execute([$preceptorId, (int)$inf['division_id']]);
    if (!$preceptorId || !$st->fetchColumn()) denegar_acceso('Este informe no corresponde a tus divisiones asignadas.');
}
if (in_array($inf['estado'], ['RESUELTO', 'ANULADO'], true)) {
    denegar_acceso('El informe ya está cerrado y no admite nuevas intervenciones.');
}

$detalleUrl = 'informes/detalle.php?id=' . rawurlencode($inf['codigo']);
$st = $pdo->prepare('SELECT * FROM consejo_convivencia WHERE informe_id=?');
$st->execute([(int)$inf['id']]);
$existente = $st->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $observaciones = texto($_POST['observaciones'] ?? '', 3, 5000);
    $analisis = texto($_POST['analisis'] ?? '', 0, 5000) ?? '';
    $recomendaciones = texto($_POST['recomendaciones'] ?? '', 0, 5000) ?? '';
    $intervencion = texto($_POST['intervencion'] ?? '', 0, 5000) ?? '';
    if (!$observaciones) {
        set_old([
            'observaciones' => (string)($_POST['observaciones'] ?? ''),
            'analisis' => (string)($_POST['analisis'] ?? ''),
            'recomendaciones' => (string)($_POST['recomendaciones'] ?? ''),
            'intervencion' => (string)($_POST['intervencion'] ?? ''),
        ]);
        flash('err', 'Las observaciones del Consejo de Convivencia son obligatorias.');
        redirect('informes/consejo_convivencia.php?id=' . rawurlencode($inf['codigo']));
    }

    $fecha = date('Y-m-d H:i:s');
    try {
        $pdo->beginTransaction();
        if ($existente) {
            $st = $pdo->prepare('UPDATE consejo_convivencia SET observaciones=?, analisis=?, recomendaciones=?, intervencion=?, fecha_intervencion=?, responsable_id=? WHERE informe_id=?');
            $accion = 'EDITAR_CONSEJO_CONVIVENCIA';
        } else {
            $st = $pdo->prepare('INSERT INTO consejo_convivencia (informe_id, observaciones, analisis, recomendaciones, intervencion, fecha_intervencion, responsable_id) VALUES (?,?,?,?,?,?,?)');
            $accion = 'REGISTRAR_CONSEJO_CONVIVENCIA';
        }
        $params = [$observaciones, $analisis, $recomendaciones, $intervencion, $fecha, $uid];
        if (!$existente) array_unshift($params, (int)$inf['id']);
        else $params[] = (int)$inf['id'];
        $st->execute($params);
        cambiar_estado((int)$inf['id'], 'PENDIENTE_RESOLUCION', 'Intervención del Consejo de Convivencia registrada');
        audit($accion, 'consejo_convivencia', (string)$inf['id'], 'Informe ' . $inf['codigo']);
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('err', 'No se pudo guardar la intervención.');
        redirect('informes/consejo_convivencia.php?id=' . rawurlencode($inf['codigo']));
    }
    clear_old();

    $st = $pdo->prepare("SELECT id FROM usuarios WHERE rol_id='DIRECTIVO' AND activo=1");
    $st->execute();
    foreach ($st->fetchAll() as $dest) {
        notify((int)$dest['id'], 'Consejo de Convivencia · ' . $inf['codigo'],
               'Se registró la intervención del Consejo de Convivencia; el informe está listo para resolver.',
               'informe', $detalleUrl);
    }
    flash('ok', 'Intervención del Consejo de Convivencia registrada.');
    redirect($detalleUrl);
}

layout_header('Consejo de Convivencia · ' . $inf['codigo']);
?>
<h1>Consejo de Convivencia</h1>
<p class="muted">Informe <?= e($inf['codigo']) ?> · <?= e(trim($inf['alumno_apellido'] . ', ' . $inf['alumno_nombre'])) ?> · <?= e(trim($inf['curso'] . ' ' . $inf['division'])) ?></p>
<div class="grid g2">
<div class="card">
  <h2>Motivo del informe</h2>
  <div class="alert info"><?= e($inf['descripcion']) ?></div>
  <p><strong>Estado actual:</strong> <span class="pill warn"><?= e(estado_nombre($inf['estado'])) ?></span></p>
  <?php if ($existente): ?>
    <div class="alert ok">Ya existe una intervención registrada; se actualizará con los nuevos datos.</div>
  <?php endif; ?>
</div>
<div class="card">
  <h2>Registro de intervención</h2>
  <form method="post" action="<?= e(url('informes/consejo_convivencia.php?id=' . rawurlencode($inf['codigo']))) ?>">
    <?= csrf_field() ?>
    <label for="observaciones">Observaciones *</label>
    <textarea id="observaciones" name="observaciones" rows="5" required><?= e(old('observaciones', $existente['observaciones'] ?? '')) ?></textarea>
    <label for="analisis">Análisis</label>
    <textarea id="analisis" name="analisis" rows="4"><?= e(old('analisis', $existente['analisis'] ?? '')) ?></textarea>
    <label for="recomendaciones">Recomendaciones</label>
    <textarea id="recomendaciones" name="recomendaciones" rows="4"><?= e(old('recomendaciones', $existente['recomendaciones'] ?? '')) ?></textarea>
    <label for="intervencion">Intervención</label>
    <textarea id="intervencion" name="intervencion" rows="4"><?= e(old('intervencion', $existente['intervencion'] ?? '')) ?></textarea>
    <label>Fecha (automática)</label>
    <input type="text" value="<?= e(date('d/m/Y H:i')) ?>" readonly>
    <label>Responsable (automático)</label>
    <input type="text" value="<?= e(trim($u['apellido'] . ', ' . $u['nombre']) . ' · ID ' . $uid) ?>" readonly>
    <div class="btn-row">
      <button class="btn" type="submit">Guardar intervención</button>
      <a class="btn g" href="<?= e(url($detalleUrl)) ?>">Cancelar</a>
    </div>
  </form>
</div>
</div>
<?php layout_footer(); ?>
