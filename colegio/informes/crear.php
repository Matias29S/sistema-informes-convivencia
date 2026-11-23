<?php
/** Alta de informes de convivencia. Solo docentes (o roles con informes.crear). */
require_once __DIR__ . '/../config/app.php';
require_permission('informes.crear');
global $pdo;

$u = current_user();
$rol = $u['rol_id'];
$uid = (int)$u['id'];
$esAdmin = ($rol === 'ADMIN');

// El informe siempre se asocia a un docente: se toma el perfil del usuario logueado.
$st = $pdo->prepare('SELECT d.id, d.cargo FROM docentes d WHERE d.usuario_id=?');
$st->execute([$uid]);
$miDocente = $st->fetch() ?: null;
if (!$esAdmin && !$miDocente) {
    denegar_acceso('Tu cuenta no tiene un perfil docente asociado para registrar informes.');
}

// Alumnos disponibles: admin = todos; docente = solo sus divisiones asignadas.
if ($esAdmin) {
    $alumnos = $pdo->query(
        'SELECT a.id, a.legajo, us.nombre, us.apellido, c.nombre AS curso, c.especialidad, dv.nombre AS division
         FROM alumnos a
         JOIN usuarios us ON us.id=a.usuario_id
         JOIN divisiones dv ON dv.id=a.division_id
         JOIN cursos c ON c.id=dv.curso_id
         WHERE a.activo=1
         ORDER BY c.anio, c.especialidad, dv.nombre, us.apellido, us.nombre'
    )->fetchAll();
    $docentes = $pdo->query(
        'SELECT d.id, d.cargo, us.nombre, us.apellido
         FROM docentes d JOIN usuarios us ON us.id=d.usuario_id
         ORDER BY us.apellido, us.nombre'
    )->fetchAll();
} else {
    $st = $pdo->prepare(
        'SELECT DISTINCT a.id, a.legajo, us.nombre, us.apellido, c.nombre AS curso, c.especialidad, dv.nombre AS division
         FROM alumnos a
         JOIN usuarios us ON us.id=a.usuario_id
         JOIN divisiones dv ON dv.id=a.division_id
         JOIN cursos c ON c.id=dv.curso_id
         JOIN asignaciones_docentes ad ON ad.division_id=a.division_id
         WHERE a.activo=1 AND ad.docente_id=?
         ORDER BY c.anio, c.especialidad, dv.nombre, us.apellido, us.nombre'
    );
    $st->execute([(int)$miDocente['id']]);
    $alumnos = $st->fetchAll();
    $docentes = [];
}

$firmaAut = mb_substr(trim($u['nombre'] . ' ' . $u['apellido']) . ' (ID: ' . $uid . ')', 0, 180);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $errores = [];
    $alumnoId = (int)($_POST['alumno_id'] ?? 0);
    $docenteId = $esAdmin ? (int)($_POST['docente_id'] ?? 0) : (int)$miDocente['id'];

    // Verificación del alumno y de su división (alcance del docente).
    $alumno = null;
    if ($alumnoId > 0) {
        $st = $pdo->prepare('SELECT a.id, a.usuario_id, a.division_id FROM alumnos a WHERE a.id=? AND a.activo=1');
        $st->execute([$alumnoId]);
        $alumno = $st->fetch() ?: null;
    }
    if (!$alumno) {
        $errores[] = 'Seleccioná un alumno válido.';
    } elseif (!$esAdmin) {
        $st = $pdo->prepare('SELECT 1 FROM asignaciones_docentes WHERE docente_id=? AND division_id=?');
        $st->execute([(int)$miDocente['id'], (int)$alumno['division_id']]);
        if (!$st->fetchColumn()) $errores[] = 'El alumno no pertenece a una división asignada.';
    }

    // El docente indicado debe existir (los admin pueden registrar en nombre de otro docente).
    $docenteUsa = null;
    if ($docenteId > 0) {
        $st = $pdo->prepare('SELECT d.id, d.cargo FROM docentes d WHERE d.id=?');
        $st->execute([$docenteId]);
        $docenteUsa = $st->fetch() ?: null;
    }
    if (!$docenteUsa) $errores[] = 'El docente indicado no es válido.';

    // Fecha del incidente (datetime-local -> DATETIME).
    $fechaRaw = trim((string)($_POST['fecha_incidente'] ?? ''));
    $fechaIncidente = null;
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s'] as $formato) {
        $obj = DateTime::createFromFormat($formato, $fechaRaw);
        if ($obj && $obj->format($formato) === $fechaRaw) {
            $fechaIncidente = $obj->format('Y-m-d H:i:s');
            break;
        }
    }
    if (!$fechaIncidente) $errores[] = 'Indicá una fecha y hora válidas del incidente.';

    $descripcion = texto($_POST['descripcion'] ?? '', 10, 5000);
    $normas = texto($_POST['normas'] ?? '', 3, 5000);
    $sancion = texto($_POST['solicitud_sancion'] ?? '', 2, 200);
    $cargo = texto($_POST['cargo'] ?? '', 2, 100);
    if (!$descripcion) $errores[] = 'La descripción detallada debe tener entre 10 y 5000 caracteres.';
    if (!$normas) $errores[] = 'Indicá las normas del reglamento transgredidas.';
    if (!$sancion) $errores[] = 'Indicá la sanción o categoría solicitada.';
    if (!$cargo) $errores[] = 'Indicá el cargo o función.';

    if ($errores) {
        set_old([
            'alumno_id' => $alumnoId, 'docente_id' => $docenteId,
            'fecha_incidente' => $fechaRaw, 'descripcion' => (string)($_POST['descripcion'] ?? ''),
            'normas' => (string)($_POST['normas'] ?? ''),
            'solicitud_sancion' => (string)($_POST['solicitud_sancion'] ?? ''),
            'cargo' => (string)($_POST['cargo'] ?? ''),
        ]);
        flash('err', implode(' ', $errores));
        redirect('informes/crear.php');
    }

    $codigo = next_codigo_informe();
    $divisionId = (int)$alumno['division_id'];
    $fechaCreacion = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare(
            'INSERT INTO informes
             (codigo, alumno_id, docente_id, division_id, fecha_incidente, descripcion, normas,
              solicitud_sancion, cargo, fecha_creacion, firma_digital, estado, creado_por)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $codigo, $alumnoId, $docenteId, $divisionId, $fechaIncidente, $descripcion,
            $normas, $sancion, $cargo, $fechaCreacion, $firmaAut, 'PENDIENTE_REVISION', $uid,
        ]);
        $informeId = (int)$pdo->lastInsertId();
        cambiar_estado($informeId, 'PENDIENTE_REVISION', 'Informe creado');
        audit('CREAR_INFORME', 'informes', (string)$informeId, 'Informe ' . $codigo . ' creado por ' . trim($u['nombre'] . ' ' . $u['apellido']));
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('err', 'No se pudo guardar el informe. Intentá nuevamente.');
        redirect('informes/crear.php');
    }
    clear_old();

    $enlace = 'informes/detalle.php?id=' . rawurlencode($codigo);
    $asunto = 'Nuevo informe de convivencia ' . $codigo;
    $detalle = 'Se registró el informe ' . $codigo . ' correspondiente a ' . $alumnoId . '. Revisalo en el sistema.';
    notify_role('informes.revisar', $asunto, $detalle, 'informe', $enlace);
    notify((int)$alumno['usuario_id'], $asunto, 'Se registró un informe de convivencia que te concierne. Podés verlo en el sistema.', 'informe', $enlace);

    $st = $pdo->prepare(
        'SELECT DISTINCT us.id FROM alumno_tutor at
         JOIN tutores t ON t.id=at.tutor_id
         JOIN usuarios us ON us.id=t.usuario_id
         WHERE at.alumno_id=? AND us.activo=1'
    );
    $st->execute([$alumnoId]);
    foreach ($st->fetchAll() as $tutor) {
        notify((int)$tutor['id'], $asunto, 'Se registró un informe de convivencia de un alumno a tu cargo.', 'informe', $enlace);
    }
    flash('ok', 'Informe ' . $codigo . ' creado correctamente.');
    redirect($enlace);
}

layout_header('Crear informe de convivencia');
?>
<h1>Nuevo informe de convivencia</h1>
<p class="muted">Completá todos los campos. La firma digital y la fecha de creación se registran automáticamente.</p>
<form method="post" action="<?= e(url('informes/crear.php')) ?>" class="card">
  <?= csrf_field() ?>
  <?php if ($esAdmin): ?>
  <label for="docente_id">Docente que registra</label>
  <select id="docente_id" name="docente_id" required>
    <option value="">Seleccioná un docente…</option>
    <?php foreach ($docentes as $d): ?>
      <option value="<?= (int)$d['id'] ?>" data-cargo="<?= e($d['cargo']) ?>" <?= (int)old('docente_id') === (int)$d['id'] ? 'selected' : '' ?>>
        <?= e(trim($d['apellido'] . ', ' . $d['nombre'])) ?> · <?= e($d['cargo']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <label for="alumno_id">Alumno/a *</label>
  <select id="alumno_id" name="alumno_id" required>
    <option value="">Seleccioná un alumno…</option>
    <?php foreach ($alumnos as $a): ?>
      <option value="<?= (int)$a['id'] ?>" <?= (int)old('alumno_id') === (int)$a['id'] ? 'selected' : '' ?>>
        <?= e(trim($a['apellido'] . ', ' . $a['nombre'])) ?> — <?= e(trim($a['curso'] . ' ' . ($a['especialidad'] ? $a['especialidad'] . ' ' : '') . $a['division'])) ?> (<?= e($a['legajo']) ?>)
      </option>
    <?php endforeach; ?>
  </select>
  <?php if (!$alumnos): ?><p class="alert info">No hay alumnos en tus divisiones asignadas. Contactá a administración.</p><?php endif; ?>

  <label for="fecha_incidente">Fecha y hora del incidente *</label>
  <input type="datetime-local" id="fecha_incidente" name="fecha_incidente" value="<?= e(old('fecha_incidente')) ?>" required>

  <label for="descripcion">Descripción detallada de la acción *</label>
  <textarea id="descripcion" name="descripcion" rows="6" required><?= e(old('descripcion')) ?></textarea>

  <label for="normas">Normas del Reglamento de Convivencia transgredidas *</label>
  <textarea id="normas" name="normas" rows="3" required><?= e(old('normas')) ?></textarea>

  <label for="solicitud_sancion">Solicitud de sanción / categoría *</label>
  <input type="text" id="solicitud_sancion" name="solicitud_sancion" list="categorias" value="<?= e(old('solicitud_sancion')) ?>" required>
  <datalist id="categorias">
    <option value="1ª LEVE"></option><option value="2ª GRAVE"></option><option value="3ª MUY GRAVE"></option>
  </datalist>

  <label for="cargo">Cargo / función *</label>
  <input type="text" id="cargo" name="cargo" value="<?= e(old('cargo', $docenteUsa['cargo'] ?? ($miDocente['cargo'] ?? ''))) ?>" required>

  <div class="grid g2">
    <div>
      <label for="fecha_creacion">Fecha de creación (automática)</label>
      <input id="fecha_creacion" type="text" value="<?= e(date('d/m/Y H:i')) ?>" readonly>
    </div>
    <div>
      <label for="firma">Firma / identificación digital (automática)</label>
      <input id="firma" type="text" value="<?= e($firmaAut) ?>" readonly>
    </div>
  </div>

  <div class="btn-row">
    <button class="btn" type="submit">Guardar informe</button>
    <a class="btn g" href="<?= e(url('dashboard.php')) ?>">Cancelar</a>
  </div>
</form>
<?php if ($esAdmin): ?>
<script>
(function () {
  var sel = document.getElementById('docente_id');
  var cargo = document.getElementById('cargo');
  if (!sel || !cargo) return;
  sel.addEventListener('change', function () {
    var opt = sel.options[sel.selectedIndex];
    if (opt && opt.getAttribute('data-cargo')) cargo.value = opt.getAttribute('data-cargo');
  });
})();
</script>
<?php endif; ?>
<?php layout_footer(); ?>
