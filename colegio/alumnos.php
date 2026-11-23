<?php
/** Gestión de alumnos. Docentes: solo lectura y solo alumnos de sus divisiones asignadas. */
require_once __DIR__ . '/config/app.php';
require_role('ADMIN', 'PRECEPTOR', 'DOCENTE');
global $pdo;

$usuario = current_user();
$rol = $usuario['rol_id'];
$uid = (int)$usuario['id'];
$puedeEditar = in_array($rol, ['ADMIN', 'PRECEPTOR'], true);
$puedeDesactivar = ($rol === 'ADMIN');
$puedeVerInformes = has_permission('informes.ver');

// Fragmentos fijos de SQL para limitar el alcance de los docentes.
$joinDocente = '';
$whereDocente = '';
$paramsDocente = [];
if ($rol === 'DOCENTE') {
    $joinDocente = ' JOIN asignaciones_docentes ad ON ad.division_id = a.division_id'
                 . ' JOIN docentes doc ON doc.id = ad.docente_id ';
    $whereDocente = ' AND doc.usuario_id=? ';
    $paramsDocente[] = $uid;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'editar' && $puedeEditar) {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT id FROM alumnos WHERE id=?');
        $st->execute([$id]);
        if (!$st->fetch()) {
            flash('err', 'El alumno no existe.');
            redirect('alumnos.php');
        }
        $nombre = texto($_POST['nombre'] ?? '', 2, 80);
        $apellido = texto($_POST['apellido'] ?? '', 2, 80);
        $telefono = texto($_POST['telefono'] ?? '', 0, 30) ?? '';
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $st = $pdo->prepare('SELECT id FROM divisiones WHERE id=?');
        $st->execute([$divisionId]);
        if (!$nombre || !$apellido || !$divisionId || !$st->fetch()) {
            flash('err', 'Revisá nombre, apellido y división.');
            redirect('alumnos.php?ver=' . $id);
        }
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT usuario_id FROM alumnos WHERE id=?');
            $st->execute([$id]);
            $usuarioId = (int)$st->fetchColumn();
            $pdo->prepare('UPDATE usuarios SET nombre=?, apellido=?, telefono=? WHERE id=?')
                ->execute([$nombre, $apellido, $telefono, $usuarioId]);
            $pdo->prepare('UPDATE alumnos SET division_id=? WHERE id=?')->execute([$divisionId, $id]);
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('err', 'No se pudo guardar el alumno.');
            redirect('alumnos.php?ver=' . $id);
        }
        audit('EDITAR_ALUMNO', 'alumnos', (string)$id, "Datos actualizados; división {$divisionId}");
        flash('ok', 'Alumno actualizado correctamente.');
        redirect('alumnos.php?ver=' . $id);
    }

    if (($accion === 'activar' || $accion === 'desactivar') && $puedeDesactivar) {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT legajo FROM alumnos WHERE id=?');
        $st->execute([$id]);
        $legajo = $st->fetchColumn();
        if (!$legajo) {
            flash('err', 'El alumno no existe.');
        } else {
            $valor = $accion === 'activar' ? 1 : 0;
            $pdo->prepare('UPDATE alumnos SET activo=? WHERE id=?')->execute([$valor, $id]);
            audit($accion === 'activar' ? 'ACTIVAR_ALUMNO' : 'DESACTIVAR_ALUMNO', 'alumnos', (string)$id, 'Legajo ' . $legajo);
            flash('ok', $accion === 'activar' ? 'Alumno activado.' : 'Alumno desactivado.');
        }
        redirect('alumnos.php?ver=' . $id);
    }

    denegar_acceso('No tenés permiso para realizar esa acción sobre alumnos.');
}

// ---------- Listado ----------
$q = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = $paramsDocente;
if ($q !== '') {
    $where[] = '(u.nombre LIKE ? OR u.apellido LIKE ? OR u.dni LIKE ? OR a.legajo LIKE ?)';
    for ($i = 0; $i < 4; $i++) $params[] = '%' . $q . '%';
}
$sql = 'SELECT DISTINCT a.id, a.legajo, a.activo, u.nombre, u.apellido, u.dni, u.email, u.telefono,
               c.nombre AS curso, c.especialidad, dv.nombre AS division
        FROM alumnos a
        JOIN usuarios u ON u.id = a.usuario_id
        JOIN divisiones dv ON dv.id = a.division_id
        JOIN cursos c ON c.id = dv.curso_id'
        . $joinDocente
        . ' WHERE 1=1 ' . $whereDocente . implode(' AND ', $where)
        . ' ORDER BY c.anio, c.especialidad, dv.nombre, u.apellido, u.nombre LIMIT 500';
$st = $pdo->prepare($sql);
$st->execute($params);
$alumnos = $st->fetchAll();

// ---------- Ficha / edición ----------
$verId = (int)($_GET['ver'] ?? 0);
$alumno = null;
if ($verId) {
    $st = $pdo->prepare('SELECT DISTINCT a.*, u.nombre, u.apellido, u.dni, u.email, u.telefono,
                            c.nombre AS curso, c.especialidad, dv.nombre AS division
                         FROM alumnos a
                         JOIN usuarios u ON u.id = a.usuario_id
                         JOIN divisiones dv ON dv.id = a.division_id
                         JOIN cursos c ON c.id = dv.curso_id'
                         . $joinDocente . ' WHERE a.id=?' . $whereDocente);
    $paramsFicha = array_merge([$verId], $paramsDocente);
    $st->execute($paramsFicha);
    $alumno = $st->fetch() ?: null;
}

$informes = [];
$totalInformes = 0;
if ($alumno && $puedeVerInformes) {
    $paramsInf = [$alumno['id']];
    $filtroInfDocente = '';
    if ($rol === 'DOCENTE') {
        $filtroInfDocente = ' AND i.docente_id = (SELECT id FROM docentes WHERE usuario_id=?)';
        $paramsInf[] = $uid;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM informes i WHERE i.alumno_id=?' . $filtroInfDocente);
    $st->execute($paramsInf);
    $totalInformes = (int)$st->fetchColumn();

    $st = $pdo->prepare('SELECT i.codigo, i.fecha_creacion, i.estado, i.descripcion
                         FROM informes i WHERE i.alumno_id=?' . $filtroInfDocente
                         . ' ORDER BY i.fecha_creacion DESC LIMIT 10');
    $st->execute($paramsInf);
    $informes = $st->fetchAll();
}

$st = $pdo->prepare('SELECT d.id, CONCAT(c.nombre, " ", d.nombre,
        CASE WHEN c.especialidad IS NOT NULL AND c.especialidad <> "" THEN CONCAT(" ", c.especialidad) ELSE "" END) AS etiqueta
    FROM divisiones d JOIN cursos c ON c.id=d.curso_id ORDER BY c.anio, c.especialidad, d.nombre');
$st->execute();
$divisiones = $st->fetchAll();

layout_header($alumno ? 'Ficha de alumno' : 'Alumnos');
?>
<?php if ($rol === 'DOCENTE'): ?><div class="alert info">Acceso de solo lectura: se muestran únicamente los alumnos de las divisiones que tiene asignadas.</div><?php endif; ?>

<h1><?= $alumno ? e(trim($alumno['nombre'] . ' ' . $alumno['apellido'])) : 'Alumnos' ?></h1>

<?php if ($alumno): ?>
<div class="grid g2">
 <div class="card">
  <h2>Datos del alumno</h2>
  <p><b>Legajo:</b> <?= e($alumno['legajo']) ?><br>
     <b>DNI:</b> <?= e($alumno['dni']) ?><br>
     <b>Curso / división:</b> <?= e(trim($alumno['curso'] . ' ' . $alumno['division'] . ($alumno['especialidad'] ? ' ' . $alumno['especialidad'] : ''))) ?><br>
     <b>Correo:</b> <?= e($alumno['email']) ?><br>
     <b>Teléfono:</b> <?= e($alumno['telefono'] ?: '—') ?><br>
     <b>Estado:</b> <span class="pill <?= $alumno['activo'] ? 'ok' : 'err' ?>"><?= $alumno['activo'] ? 'Activo' : 'Inactivo' ?></span></p>
  <p><a class="btn g" href="<?= e(url('alumnos.php')) ?>">Volver al listado</a></p>
 </div>
 <?php if ($puedeEditar): ?>
 <div class="card">
  <h2>Editar alumno</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= (int)$alumno['id'] ?>">
   <label for="nombre">Nombre</label><input id="nombre" name="nombre" class="input" required value="<?= e($alumno['nombre']) ?>">
   <label for="apellido">Apellido</label><input id="apellido" name="apellido" class="input" required value="<?= e($alumno['apellido']) ?>">
   <label for="telefono">Teléfono</label><input id="telefono" name="telefono" class="input" value="<?= e($alumno['telefono']) ?>">
   <label for="division_id">Curso / división</label>
   <select id="division_id" name="division_id" class="select" required>
    <?php foreach ($divisiones as $d): ?>
     <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === (int)$alumno['division_id'] ? 'selected' : '' ?>><?= e($d['etiqueta']) ?></option>
    <?php endforeach; ?>
   </select>
   <p><button class="btn">Guardar</button></p>
  </form>
  <?php if ($puedeDesactivar): ?>
  <form method="post" data-confirm="¿Seguro que desea cambiar el estado del alumno?"><?= csrf_field() ?>
   <input type="hidden" name="accion" value="<?= $alumno['activo'] ? 'desactivar' : 'activar' ?>"><input type="hidden" name="id" value="<?= (int)$alumno['id'] ?>">
   <button class="btn <?= $alumno['activo'] ? 'd' : '' ?>"><?= $alumno['activo'] ? 'Desactivar alumno' : 'Activar alumno' ?></button>
  </form>
  <?php endif; ?>
 </div>
 <?php endif; ?>
</div>

<?php if ($puedeVerInformes): ?>
<div class="card">
 <h2>Historial institucional de informes <span class="muted">(<?= (int)$totalInformes ?>)</span></h2>
 <?php if (!$informes): ?><p class="muted">No hay informes registrados para este alumno.</p>
 <?php else: ?><div class="table-wrap"><table>
  <thead><tr><th>Código</th><th>Fecha</th><th>Estado</th><th>Descripción</th></tr></thead><tbody>
  <?php foreach ($informes as $inf): ?>
   <tr><td><a href="<?= e(url('informes/detalle.php?id=' . $inf['codigo'])) ?>"><?= e($inf['codigo']) ?></a></td>
       <td><?= e($inf['fecha_creacion']) ?></td>
       <td><span class="pill <?= $inf['estado'] === 'RESUELTO' ? 'ok' : ($inf['estado'] === 'ANULADO' ? 'gray' : 'warn') ?>"><?= e(estado_nombre($inf['estado'])) ?></span></td>
       <td><?= e(mb_substr($inf['descripcion'], 0, 140)) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card">
 <h2>Buscar alumnos</h2>
 <form class="toolbar" method="get">
  <label for="q">Nombre, apellido, DNI o legajo</label>
  <input id="q" name="q" class="input" value="<?= e($q) ?>" placeholder="Buscar…">
  <button class="btn s">Buscar</button>
 </form>
 <p><input class="input" type="search" data-filter="#tabla-alumnos" placeholder="Filtrar tabla en pantalla…" aria-label="Filtrar tabla"></p>
 <div class="table-wrap"><table id="tabla-alumnos">
  <thead><tr><th>Alumno</th><th>DNI</th><th>Legajo</th><th>Curso / división</th><th>Estado</th><th></th></tr></thead>
  <tbody>
  <?php if (!$alumnos): ?><tr><td colspan="6" class="muted">No hay alumnos para mostrar.</td></tr><?php endif; ?>
  <?php foreach ($alumnos as $a): ?>
   <tr>
    <td><?= e(trim($a['nombre'] . ' ' . $a['apellido'])) ?></td>
    <td><?= e($a['dni']) ?></td>
    <td><?= e($a['legajo']) ?></td>
    <td><?= e(trim($a['curso'] . ' ' . $a['division'] . ($a['especialidad'] ? ' ' . $a['especialidad'] : ''))) ?></td>
    <td><span class="pill <?= $a['activo'] ? 'ok' : 'err' ?>"><?= $a['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
    <td><a class="btn s" href="<?= e(url('alumnos.php?ver=' . $a['id'])) ?>">Ver</a></td>
   </tr>
  <?php endforeach; ?>
  </tbody>
 </table></div>
</div>
<?php endif; ?>
<?php layout_footer(); ?>
