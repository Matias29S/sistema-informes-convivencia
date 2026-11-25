<?php
/** Cursos, divisiones, preceptores y asignación de alumnos (ADMIN y PRECEPTOR). */
require_once __DIR__ . '/config/app.php';
require_role('ADMIN', 'PRECEPTOR');
global $pdo;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'curso_guardar') {
        $id = (int)($_POST['id'] ?? 0);
        $anio = (int)($_POST['anio'] ?? 0);
        $nombre = texto($_POST['nombre'] ?? '', 1, 20);
        $especialidad = texto($_POST['especialidad'] ?? '', 1, 60) ?: null;

        if ($anio < 1 || $anio > 6 || !$nombre) {
            flash('err', 'Revisá el año (1 a 6) y el nombre del curso.');
            redirect('cursos.php');
        }
        if ($especialidad === null) {
            $st = $pdo->prepare('SELECT id FROM cursos WHERE anio=? AND especialidad IS NULL AND id<>?');
            $st->execute([$anio, $id]);
        } else {
            $st = $pdo->prepare('SELECT id FROM cursos WHERE anio=? AND especialidad=? AND id<>?');
            $st->execute([$anio, $especialidad, $id]);
        }
        if ($st->fetch()) {
            flash('err', 'Ya existe un curso con ese año y especialidad.');
            redirect('cursos.php');
        }
        if ($id) {
            $st = $pdo->prepare('SELECT id FROM cursos WHERE id=?');
            $st->execute([$id]);
            if (!$st->fetch()) { flash('err', 'El curso no existe.'); redirect('cursos.php'); }
            $pdo->prepare('UPDATE cursos SET anio=?, nombre=?, especialidad=? WHERE id=?')
                ->execute([$anio, $nombre, $especialidad, $id]);
            audit('EDITAR_CURSO', 'cursos', (string)$id, "{$anio}° {$nombre} " . ($especialidad ?: ''));
            flash('ok', 'Curso actualizado.');
        } else {
            $pdo->prepare('INSERT INTO cursos (anio,nombre,especialidad) VALUES (?,?,?)')
                ->execute([$anio, $nombre, $especialidad]);
            $nuevo = (int)$pdo->lastInsertId();
            audit('CREAR_CURSO', 'cursos', (string)$nuevo, "{$anio}° {$nombre} " . ($especialidad ?: ''));
            flash('ok', 'Curso creado.');
        }
        redirect('cursos.php');
    }

    if ($accion === 'division_crear' || $accion === 'division_editar') {
        $id = $accion === 'division_editar' ? (int)($_POST['id'] ?? 0) : 0;
        $cursoId = (int)($_POST['curso_id'] ?? 0);
        $nombre = texto($_POST['nombre'] ?? '', 1, 10);
        $st = $pdo->prepare('SELECT id FROM cursos WHERE id=?');
        $st->execute([$cursoId]);
        if (!$cursoId || !$st->fetch() || !$nombre) {
            flash('err', 'Revisá el curso y el nombre de la división.');
            redirect('cursos.php');
        }
        $st = $pdo->prepare('SELECT id FROM divisiones WHERE curso_id=? AND nombre=? AND id<>?');
        $st->execute([$cursoId, $nombre, $id]);
        if ($st->fetch()) {
            flash('err', 'Esa división ya existe en el curso.');
            redirect('cursos.php');
        }
        if ($id) {
            $st = $pdo->prepare('SELECT id FROM divisiones WHERE id=?');
            $st->execute([$id]);
            if (!$st->fetch()) { flash('err', 'La división no existe.'); redirect('cursos.php'); }
            $pdo->prepare('UPDATE divisiones SET curso_id=?, nombre=? WHERE id=?')->execute([$cursoId, $nombre, $id]);
            audit('EDITAR_DIVISION', 'divisiones', (string)$id, "Curso {$cursoId}, división {$nombre}");
            flash('ok', 'División actualizada.');
        } else {
            $pdo->prepare('INSERT INTO divisiones (curso_id,nombre) VALUES (?,?)')->execute([$cursoId, $nombre]);
            $nuevo = (int)$pdo->lastInsertId();
            audit('CREAR_DIVISION', 'divisiones', (string)$nuevo, "Curso {$cursoId}, división {$nombre}");
            flash('ok', 'División creada.');
        }
        redirect('cursos.php');
    }

    if ($accion === 'preceptor_asignar' || $accion === 'preceptor_desasignar') {
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $preceptorId = (int)($_POST['preceptor_id'] ?? 0);
        $st = $pdo->prepare('SELECT id FROM divisiones WHERE id=?');
        $st->execute([$divisionId]);
        if (!$st->fetch()) { flash('err', 'La división no existe.'); redirect('cursos.php'); }
        $st = $pdo->prepare('SELECT id FROM docentes WHERE id=?');
        $st->execute([$preceptorId]);
        if (!$st->fetch()) { flash('err', 'El preceptor no existe.'); redirect('cursos.php'); }
        if ($accion === 'preceptor_asignar') {
            $st = $pdo->prepare('SELECT 1 FROM division_preceptor WHERE division_id=? AND preceptor_id=?');
            $st->execute([$divisionId, $preceptorId]);
            if (!$st->fetch()) {
                $pdo->prepare('INSERT INTO division_preceptor (division_id,preceptor_id) VALUES (?,?)')
                    ->execute([$divisionId, $preceptorId]);
                audit('ASIGNAR_PRECEPTOR', 'division_preceptor', (string)$divisionId, 'Preceptor docente id ' . $preceptorId);
                flash('ok', 'Preceptor asignado.');
            } else {
                flash('info', 'El preceptor ya estaba asignado a esa división.');
            }
        } else {
            $pdo->prepare('DELETE FROM division_preceptor WHERE division_id=? AND preceptor_id=?')
                ->execute([$divisionId, $preceptorId]);
            audit('DESASIGNAR_PRECEPTOR', 'division_preceptor', (string)$divisionId, 'Preceptor docente id ' . $preceptorId);
            flash('ok', 'Preceptor desasignado.');
        }
        redirect('cursos.php');
    }

    if ($accion === 'alumno_asignar') {
        $alumnoId = (int)($_POST['alumno_id'] ?? 0);
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $st = $pdo->prepare('SELECT id FROM alumnos WHERE id=?');
        $st->execute([$alumnoId]);
        if (!$st->fetch()) { flash('err', 'El alumno no existe.'); redirect('cursos.php'); }
        $st = $pdo->prepare('SELECT id FROM divisiones WHERE id=?');
        $st->execute([$divisionId]);
        if (!$divisionId || !$st->fetch()) { flash('err', 'La división no existe.'); redirect('cursos.php'); }
        $pdo->prepare('UPDATE alumnos SET division_id=? WHERE id=?')->execute([$divisionId, $alumnoId]);
        audit('ASIGNAR_ALUMNO_DIVISION', 'alumnos', (string)$alumnoId, 'Nueva división id ' . $divisionId);
        flash('ok', 'Alumno asignado a la división.');
        redirect('cursos.php');
    }

    denegar_acceso('Acción no permitida.');
}

// ---------- Catálogos ----------
$st = $pdo->prepare('SELECT id, anio, nombre, especialidad FROM cursos ORDER BY anio, especialidad, nombre');
$st->execute();
$cursos = $st->fetchAll();

$st = $pdo->prepare('SELECT d.id, CONCAT(c.nombre, " ", d.nombre,
        CASE WHEN c.especialidad IS NOT NULL AND c.especialidad <> "" THEN CONCAT(" ", c.especialidad) ELSE "" END) AS etiqueta
    FROM divisiones d JOIN cursos c ON c.id=d.curso_id ORDER BY c.anio, c.especialidad, d.nombre');
$st->execute();
$divisiones = $st->fetchAll();

$st = $pdo->prepare('SELECT d.id, d.legajo, us.nombre, us.apellido FROM docentes d
                     JOIN usuarios us ON us.id=d.usuario_id ORDER BY us.apellido, us.nombre');
$st->execute();
$docentes = $st->fetchAll();

$st = $pdo->prepare('SELECT a.id, us.nombre, us.apellido, us.dni, a.legajo FROM alumnos a
                     JOIN usuarios us ON us.id=a.usuario_id ORDER BY us.apellido, us.nombre LIMIT 500');
$st->execute();
$alumnos = $st->fetchAll();

$editarCurso = null;
$editarCursoId = (int)($_GET['editar_curso'] ?? 0);
if ($editarCursoId) {
    $st = $pdo->prepare('SELECT * FROM cursos WHERE id=?');
    $st->execute([$editarCursoId]);
    $editarCurso = $st->fetch() ?: null;
}
$editarDivision = null;
$editarDivisionId = (int)($_GET['editar_division'] ?? 0);
if ($editarDivisionId) {
    $st = $pdo->prepare('SELECT * FROM divisiones WHERE id=?');
    $st->execute([$editarDivisionId]);
    $editarDivision = $st->fetch() ?: null;
}

// Detalle por curso/división (consultas preparadas).
$detalle = [];
foreach ($cursos as $curso) {
    $st = $pdo->prepare('SELECT id, nombre FROM divisiones WHERE curso_id=? ORDER BY nombre');
    $st->execute([(int)$curso['id']]);
    $divs = [];
    foreach ($st->fetchAll() as $div) {
        $st2 = $pdo->prepare('SELECT doc.id, doc.legajo, us.nombre, us.apellido FROM division_preceptor dp
                              JOIN docentes doc ON doc.id=dp.preceptor_id
                              JOIN usuarios us ON us.id=doc.usuario_id
                              WHERE dp.division_id=? ORDER BY us.apellido, us.nombre');
        $st2->execute([(int)$div['id']]);
        $preceptores = $st2->fetchAll();
        $st2 = $pdo->prepare('SELECT COUNT(*) FROM alumnos WHERE division_id=?');
        $st2->execute([(int)$div['id']]);
        $div['preceptores'] = $preceptores;
        $div['cantidad_alumnos'] = (int)$st2->fetchColumn();
        $divs[] = $div;
    }
    $detalle[(int)$curso['id']] = $divs;
}

layout_header('Cursos y divisiones');
?>
<h1>Cursos y divisiones</h1>
<p class="muted">Gestión de la estructura académica, preceptores asignados y alumnos por división.</p>

<div class="grid g2">
 <div class="card">
  <h2><?= $editarCurso ? 'Editar curso' : 'Nuevo curso' ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="curso_guardar">
   <input type="hidden" name="id" value="<?= $editarCurso ? (int)$editarCurso['id'] : 0 ?>">
   <label for="anio">Año</label>
   <select id="anio" name="anio" class="select" required>
    <?php for ($i = 1; $i <= 6; $i++): ?>
     <option value="<?= $i ?>" <?= (int)($editarCurso['anio'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?>°</option>
    <?php endfor; ?>
   </select>
   <label for="nombre">Nombre</label><input id="nombre" name="nombre" class="input" required maxlength="20" value="<?= e($editarCurso['nombre'] ?? '') ?>" placeholder="1ro, 3ro, 6to…">
   <label for="especialidad">Especialidad (opcional)</label><input id="especialidad" name="especialidad" class="input" maxlength="60" value="<?= e($editarCurso['especialidad'] ?? '') ?>" placeholder="Computación, Automotores…">
   <p><button class="btn"><?= $editarCurso ? 'Guardar curso' : 'Crear curso' ?></button>
    <?php if ($editarCurso): ?><a class="btn g" href="<?= e(url('cursos.php')) ?>">Cancelar</a><?php endif; ?></p>
  </form>
 </div>
 <div class="card">
  <h2><?= $editarDivision ? 'Editar división' : 'Nueva división' ?></h2>
  <form method="post"><?= csrf_field() ?>
   <input type="hidden" name="accion" value="<?= $editarDivision ? 'division_editar' : 'division_crear' ?>">
   <input type="hidden" name="id" value="<?= $editarDivision ? (int)$editarDivision['id'] : 0 ?>">
   <label for="curso_id_div">Curso</label>
   <select id="curso_id_div" name="curso_id" class="select" required>
    <?php foreach ($cursos as $c): ?>
     <option value="<?= (int)$c['id'] ?>" <?= (int)($editarDivision['curso_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
      <?= e($c['anio'] . '° ' . $c['nombre'] . ($c['especialidad'] ? ' ' . $c['especialidad'] : '')) ?></option>
    <?php endforeach; ?>
   </select>
   <label for="nombre_div">División</label><input id="nombre_div" name="nombre" class="input" required maxlength="10" value="<?= e($editarDivision['nombre'] ?? '') ?>" placeholder="1ra, 2da…">
   <p><button class="btn"><?= $editarDivision ? 'Guardar división' : 'Crear división' ?></button>
    <?php if ($editarDivision): ?><a class="btn g" href="<?= e(url('cursos.php')) ?>">Cancelar</a><?php endif; ?></p>
  </form>
 </div>
</div>

<div class="grid g2">
 <div class="card">
  <h2>Asignar preceptor</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="preceptor_asignar">
   <label for="division_pref">División</label><select id="division_pref" name="division_id" class="select" required>
    <option value="">Seleccionar…</option>
    <?php foreach ($divisiones as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['etiqueta']) ?></option><?php endforeach; ?>
   </select>
   <label for="preceptor_id">Preceptor</label><select id="preceptor_id" name="preceptor_id" class="select" required>
    <option value="">Seleccionar…</option>
    <?php foreach ($docentes as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e(trim($d['apellido'] . ' ' . $d['nombre']) . ' (' . $d['legajo'] . ')') ?></option><?php endforeach; ?>
   </select>
   <p><button class="btn">Asignar</button></p>
  </form>
 </div>
 <div class="card">
  <h2>Asignar / cambiar alumno de división</h2>
  <p class="muted">El campo de división es obligatorio: para desasignar un alumno debe pasar a otra división.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="alumno_asignar">
   <label for="alumno_id">Alumno</label><select id="alumno_id" name="alumno_id" class="select" required>
    <option value="">Seleccionar…</option>
    <?php foreach ($alumnos as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e(trim($a['apellido'] . ' ' . $a['nombre']) . ' · ' . $a['dni'] . ' · ' . $a['legajo']) ?></option><?php endforeach; ?>
   </select>
   <label for="division_alum">División</label><select id="division_alum" name="division_id" class="select" required>
    <option value="">Seleccionar…</option>
    <?php foreach ($divisiones as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['etiqueta']) ?></option><?php endforeach; ?>
   </select>
   <p><button class="btn">Guardar asignación</button></p>
  </form>
 </div>
</div>

<?php foreach ($cursos as $curso): ?>
<div class="card">
 <h2><?= e($curso['anio'] . '° ' . $curso['nombre'] . ($curso['especialidad'] ? ' · ' . $curso['especialidad'] : '')) ?>
  <a class="btn s" href="<?= e(url('cursos.php?editar_curso=' . $curso['id'])) ?>">Editar curso</a></h2>
 <?php $divs = $detalle[(int)$curso['id']] ?? []; ?>
 <?php if (!$divs): ?><p class="muted">Sin divisiones registradas.</p>
 <?php else: ?>
  <div class="table-wrap"><table>
   <thead><tr><th>División</th><th>Preceptores</th><th>Alumnos</th><th></th></tr></thead><tbody>
   <?php foreach ($divs as $div): ?>
    <tr>
     <td><?= e($div['nombre']) ?></td>
     <td>
      <?php if (!$div['preceptores']): ?><span class="muted">Sin preceptor</span><?php endif; ?>
      <?php foreach ($div['preceptores'] as $pre): ?>
       <div class="btn-row" style="margin:.1rem 0">
        <?= e(trim($pre['apellido'] . ' ' . $pre['nombre']) . ' (' . $pre['legajo'] . ')') ?>
        <form method="post" style="display:inline" data-confirm="¿Desasignar este preceptor?"><?= csrf_field() ?>
         <input type="hidden" name="accion" value="preceptor_desasignar">
         <input type="hidden" name="division_id" value="<?= (int)$div['id'] ?>">
         <input type="hidden" name="preceptor_id" value="<?= (int)$pre['id'] ?>">
         <button class="btn s d">Quitar</button></form>
       </div>
      <?php endforeach; ?>
     </td>
     <td><?= (int)$div['cantidad_alumnos'] ?></td>
     <td><a class="btn s" href="<?= e(url('cursos.php?editar_division=' . $div['id'])) ?>">Editar</a></td>
    </tr>
   <?php endforeach; ?>
   </tbody>
  </table></div>
 <?php endif; ?>
</div>
<?php endforeach; ?>
<?php layout_footer(); ?>
