<?php
/** Clasificación institucional (1ª LEVE / 2ª GRAVE / 3ª MUY GRAVE / OTRA). No decide sanciones. */
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
    'SELECT i.id, i.codigo, i.estado, i.descripcion, i.solicitud_sancion,
            us.nombre AS alumno_nombre, us.apellido AS alumno_apellido
     FROM informes i JOIN alumnos al ON al.id=i.alumno_id
     JOIN usuarios us ON us.id=al.usuario_id
     WHERE ' . (ctype_digit($idRaw) ? 'i.id=?' : 'i.codigo=?')
);
$st->execute([ctype_digit($idRaw) ? (int)$idRaw : mb_substr($idRaw, 0, 20)]);
$inf = $st->fetch() ?: null;
if (!$inf) denegar_acceso('El informe solicitado no existe.');
if ($inf['estado'] === 'ANULADO') denegar_acceso('No se puede clasificar un informe anulado.');

$detalleUrl = 'informes/detalle.php?id=' . rawurlencode($inf['codigo']);
$st = $pdo->prepare('SELECT * FROM clasificaciones WHERE informe_id=?');
$st->execute([(int)$inf['id']]);
$existente = $st->fetch() ?: null;

$instancias = [
    'LEVE' => '1ª LEVE',
    'GRAVE' => '2ª GRAVE',
    'MUY_GRAVE' => '3ª MUY GRAVE',
    'OTRA' => 'OTRA consideración',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $instancia = (string)($_POST['instancia'] ?? '');
    $otra = texto($_POST['otra_consideracion'] ?? '', 0, 5000) ?? '';
    $errores = [];
    if (!isset($instancias[$instancia])) $errores[] = 'Seleccioná una instancia de clasificación válida.';
    if ($instancia === 'OTRA' && mb_strlen($otra) < 3) $errores[] = 'Indicá la otra consideración.';
    if ($errores) {
        set_old(['instancia' => $instancia, 'otra_consideracion' => (string)($_POST['otra_consideracion'] ?? '')]);
        flash('err', implode(' ', $errores));
        redirect('informes/clasificacion.php?id=' . rawurlencode($inf['codigo']));
    }
    if ($instancia !== 'OTRA') $otra = '';

    $fecha = date('Y-m-d H:i:s');
    try {
        if ($existente) {
            $st = $pdo->prepare('UPDATE clasificaciones SET instancia=?, otra_consideracion=?, responsable_id=?, fecha=? WHERE informe_id=?');
            $st->execute([$instancia, $otra, $uid, $fecha, (int)$inf['id']]);
            $accion = 'EDITAR_CLASIFICACION';
        } else {
            $st = $pdo->prepare('INSERT INTO clasificaciones (informe_id, instancia, otra_consideracion, responsable_id, fecha) VALUES (?,?,?,?,?)');
            $st->execute([(int)$inf['id'], $instancia, $otra, $uid, $fecha]);
            $accion = 'CLASIFICAR_INFORME';
        }
    } catch (Exception $ex) {
        flash('err', 'No se pudo guardar la clasificación.');
        redirect('informes/clasificacion.php?id=' . rawurlencode($inf['codigo']));
    }
    audit($accion, 'clasificaciones', (string)$inf['id'], 'Informe ' . $inf['codigo'] . ' · ' . $instancias[$instancia]);
    clear_old();
    flash('ok', 'Clasificación registrada. La sanción se decide en la resolución.');
    redirect($detalleUrl);
}

layout_header('Clasificación · ' . $inf['codigo']);
?>
<h1>Clasificación del informe</h1>
<p class="muted">Informe <?= e($inf['codigo']) ?> · <?= e(trim($inf['alumno_apellido'] . ', ' . $inf['alumno_nombre'])) ?>. Este paso no define la sanción.</p>
<div class="grid g2">
<div class="card">
  <h2>Antecedentes</h2>
  <p><strong>Solicitud del docente:</strong> <?= e($inf['solicitud_sancion']) ?></p>
  <div class="alert info"><?= e($inf['descripcion']) ?></div>
</div>
<div class="card">
  <h2>Instancia</h2>
  <form method="post" action="<?= e(url('informes/clasificacion.php?id=' . rawurlencode($inf['codigo']))) ?>">
    <?= csrf_field() ?>
    <label for="instancia">Clasificación *</label>
    <select id="instancia" name="instancia" required>
      <?php foreach ($instancias as $clave => $nombre): ?>
        <option value="<?= e($clave) ?>" <?= old('instancia', $existente['instancia'] ?? '') === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="otra_consideracion">Otra consideración (solo si corresponde)</label>
    <textarea id="otra_consideracion" name="otra_consideracion" rows="4"><?= e(old('otra_consideracion', $existente['otra_consideracion'] ?? '')) ?></textarea>
    <label>Responsable (automático)</label>
    <input type="text" value="<?= e(trim($u['apellido'] . ', ' . $u['nombre']) . ' · ID ' . $uid) ?>" readonly>
    <label>Fecha (automática)</label>
    <input type="text" value="<?= e(date('d/m/Y H:i')) ?>" readonly>
    <div class="btn-row">
      <button class="btn" type="submit">Guardar clasificación</button>
      <a class="btn g" href="<?= e(url($detalleUrl)) ?>">Cancelar</a>
    </div>
  </form>
</div>
</div>
<?php layout_footer(); ?>
