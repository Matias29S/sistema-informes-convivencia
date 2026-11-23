<?php
/** Docentes: administración completa (ADMIN) y vista propia con cursos y alumnos (DOCENTE). */
require_once __DIR__ . '/config/app.php';
require_role('ADMIN', 'DOCENTE');
global $pdo;

$usuario = current_user();
$rol = $usuario['rol_id'];
$uid = (int)$usuario['id'];

/** Envía el enlace de activación sin exponer la contraseña temporal. */
function enviar_activacion_docente(int $usuarioId, string $email, string $username): bool {
    $token = crear_token($usuarioId, 'activacion', 48);
    $cuerpo = "Hola {$username}:\n\nSe creó una cuenta para vos en el Sistema de Informes de Convivencia.\n"
        . "Usuario: {$username}\n\nDefiní tu contraseña en (48 horas):\n"
        . APP_URL . 'activar.php?t=' . $token . "\n";
    return Mailer::send($email, 'Activá tu cuenta en ' . APP_NAME, $cuerpo);
}

$erroresForm = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($rol !== 'ADMIN') denegar_acceso('Solo administración puede modificar docentes.');
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $nombre = texto($_POST['nombre'] ?? '', 2, 80);
        $apellido = texto($_POST['apellido'] ?? '', 2, 80);
        $dni = preg_replace('/\s+/', '', (string)($_POST['dni'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $telefono = texto($_POST['telefono'] ?? '', 0, 30) ?? '';
        $cargo = texto($_POST['cargo'] ?? '', 2, 80) ?: 'Docente';
        $titulo = texto($_POST['titulo'] ?? '', 2, 100);

        if (!$nombre || !$apellido) $erroresForm[] = 'Nombre y apellido son obligatorios.';
        if (!$titulo) $erroresForm[] = 'El título es obligatorio.';
        if (!es_dni($dni)) $erroresForm[] = 'El DNI no es válido.';
        if (!es_email($email)) $erroresForm[] = 'El correo electrónico no es válido.';
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE email=?');
        $st->execute([$email]);
        if ($st->fetch()) $erroresForm[] = 'Ese correo ya está registrado.';
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE dni=?');
        $st->execute([$dni]);
        if ($st->fetch()) $erroresForm[] = 'Ese DNI ya está registrado.';
        if ($erroresForm) {
            set_old($_POST);
            flash('err', implode(' ', $erroresForm));
            redirect('docentes.php?form=nuevo');
        }

        $username = generar_username($nombre, $apellido);
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('INSERT INTO usuarios
                (username,email,password_hash,rol_id,nombre,apellido,dni,telefono,verificado,activo)
                VALUES (?,?,?,?,?,?,?,?,0,1)');
            $st->execute([$username, $email, hash_password(bin2hex(random_bytes(16))), 'DOCENTE',
                          $nombre, $apellido, $dni, $telefono]);
            $nuevoId = (int)$pdo->lastInsertId();
            $legajo = 'DOC-' . str_pad((string)$nuevoId, 3, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO docentes (usuario_id,legajo,cargo,titulo) VALUES (?,?,?,?)')
                ->execute([$nuevoId, $legajo, $cargo, $titulo ?: null]);
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_old($_POST);
            flash('err', 'No se pudo registrar el docente.');
            redirect('docentes.php?form=nuevo');
        }
        $enviado = enviar_activacion_docente($nuevoId, $email, $username);
        audit('CREAR_DOCENTE', 'docentes', (string)$nuevoId, "Alta de docente {$username}");
        clear_old();
        flash($enviado ? 'ok' : 'info', $enviado
            ? 'Docente registrado y correo de activación enviado.'
            : 'Docente registrado, pero no se pudo enviar el correo. Puede reenviarse desde Administración de cuentas.');
        redirect('docentes.php');
    }

    if ($accion === 'editar') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT d.id, d.legajo, u.id AS usuario_id FROM docentes d JOIN usuarios u ON u.id=d.usuario_id WHERE d.id=?');
        $st->execute([$id]);
        $docente = $st->fetch();
        if (!$docente) {
            flash('err', 'El docente no existe.');
            redirect('docentes.php');
        }
        $nombre = texto($_POST['nombre'] ?? '', 2, 80);
        $apellido = texto($_POST['apellido'] ?? '', 2, 80);
        $telefono = texto($_POST['telefono'] ?? '', 0, 30) ?? '';
        $cargo = texto($_POST['cargo'] ?? '', 2, 80);
        $titulo = texto($_POST['titulo'] ?? '', 2, 100);
        if (!$nombre || !$apellido || !$cargo || !$titulo) {
            flash('err', 'Nombre, apellido, cargo y título son obligatorios.');
            redirect('docentes.php?editar=' . $id);
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE usuarios SET nombre=?, apellido=?, telefono=? WHERE id=?')
                ->execute([$nombre, $apellido, $telefono, (int)$docente['usuario_id']]);
            $pdo->prepare('UPDATE docentes SET cargo=?, titulo=? WHERE id=?')
                ->execute([$cargo, $titulo ?: null, $id]);
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('err', 'No se pudo actualizar el docente.');
            redirect('docentes.php?editar=' . $id);
        }
        audit('EDITAR_DOCENTE', 'docentes', (string)$id, "Cargo: {$cargo}; título: " . ($titulo ?: '—'));
        flash('ok', 'Docente actualizado correctamente.');
        redirect('docentes.php?editar=' . $id);
    }

    if ($accion === 'activar' || $accion === 'desactivar') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT u.id AS usuario_id, u.username FROM docentes d JOIN usuarios u ON u.id=d.usuario_id WHERE d.id=?');
        $st->execute([$id]);
        $docente = $st->fetch();
        if (!$docente) {
            flash('err', 'El docente no existe.');
        } elseif ((int)$docente['usuario_id'] === $uid) {
            flash('err', 'No podés desactivar tu propia cuenta.');
        } else {
            $valor = $accion === 'activar' ? 1 : 0;
            $pdo->prepare('UPDATE usuarios SET activo=? WHERE id=?')->execute([$valor, (int)$docente['usuario_id']]);
            audit($accion === 'activar' ? 'ACTIVAR_DOCENTE' : 'DESACTIVAR_DOCENTE', 'docentes', (string)$id, 'Cuenta ' . $docente['username']);
            flash('ok', $accion === 'activar' ? 'Docente activado.' : 'Docente desactivado.');
        }
        redirect('docentes.php');
    }

    denegar_acceso('Acción no permitida.');
}

// ---------- Vista del docente sobre sus propios datos ----------
$miDocente = null;
$miasAsignaciones = [];
$misAlumnos = [];
if ($rol === 'DOCENTE') {
    $st = $pdo->prepare('SELECT d.*, u.nombre, u.apellido, u.dni, u.email, u.telefono, u.activo
                         FROM docentes d JOIN usuarios u ON u.id=d.usuario_id WHERE u.id=?');
    $st->execute([$uid]);
    $miDocente = $st->fetch() ?: null;
    if ($miDocente) {
        $st = $pdo->prepare('SELECT m.nombre AS materia, c.nombre AS curso, c.especialidad, dv.nombre AS division
                             FROM asignaciones_docentes ad
                             JOIN materias m ON m.id=ad.materia_id
                             JOIN divisiones dv ON dv.id=ad.division_id
                             JOIN cursos c ON c.id=dv.curso_id
                             WHERE ad.docente_id=? ORDER BY c.anio, c.especialidad, dv.nombre, m.nombre');
        $st->execute([(int)$miDocente['id']]);
        $miasAsignaciones = $st->fetchAll();

        $st = $pdo->prepare('SELECT DISTINCT us.nombre, us.apellido, us.dni, a.legajo, c.nombre AS curso,
                                    c.especialidad, dv.nombre AS division
                             FROM alumnos a
                             JOIN usuarios us ON us.id=a.usuario_id
                             JOIN divisiones dv ON dv.id=a.division_id
                             JOIN cursos c ON c.id=dv.curso_id
                             JOIN asignaciones_docentes ad ON ad.division_id=a.division_id
                             WHERE ad.docente_id=? ORDER BY c.anio, dv.nombre, us.apellido');
        $st->execute([(int)$miDocente['id']]);
        $misAlumnos = $st->fetchAll();
    }
}

// ---------- Listado administrativo ----------
$q = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = '';
if ($q !== '') {
    $where = ' WHERE u.nombre LIKE ? OR u.apellido LIKE ? OR u.dni LIKE ? OR d.legajo LIKE ? OR u.email LIKE ?';
    for ($i = 0; $i < 5; $i++) $params[] = '%' . $q . '%';
}
$st = $pdo->prepare('SELECT d.id, d.legajo, d.cargo, d.titulo, u.id AS usuario_id, u.nombre, u.apellido, u.dni, u.email, u.telefono, u.activo
                     FROM docentes d JOIN usuarios u ON u.id=d.usuario_id' . $where
                     . ' ORDER BY u.apellido, u.nombre LIMIT 300');
$st->execute($params);
$docentes = $st->fetchAll();

$editarId = (int)($_GET['editar'] ?? 0);
$editar = null;
if ($editarId) {
    $st = $pdo->prepare('SELECT d.*, u.nombre, u.apellido, u.telefono FROM docentes d JOIN usuarios u ON u.id=d.usuario_id WHERE d.id=?');
    $st->execute([$editarId]);
    $editar = $st->fetch() ?: null;
}

layout_header($rol === 'DOCENTE' ? 'Mis datos docentes' : 'Docentes');
?>

<?php if ($rol === 'DOCENTE'): ?>
<?php if (!$miDocente): ?><div class="alert err">No hay un legajo docente asociado a tu cuenta. Contactá a administración.</div><?php else: ?>
<div class="grid g2">
 <div class="card">
  <h2>Mis datos</h2>
  <p><b>Legajo:</b> <?= e($miDocente['legajo']) ?><br>
     <b>Nombre:</b> <?= e(trim($miDocente['nombre'] . ' ' . $miDocente['apellido'])) ?><br>
     <b>DNI:</b> <?= e($miDocente['dni']) ?><br>
     <b>Correo:</b> <?= e($miDocente['email']) ?><br>
     <b>Teléfono:</b> <?= e($miDocente['telefono'] ?: '—') ?><br>
     <b>Cargo:</b> <?= e($miDocente['cargo']) ?><br>
     <b>Título:</b> <?= e($miDocente['titulo'] ?: '—') ?></p>
 </div>
 <div class="card">
  <h2>Mis materias y divisiones</h2>
  <?php if (!$miasAsignaciones): ?><p class="muted">No tenés asignaciones registradas.</p>
  <?php else: ?><div class="table-wrap"><table><thead><tr><th>Materia</th><th>Curso / división</th></tr></thead><tbody>
   <?php foreach ($miasAsignaciones as $as): ?>
    <tr><td><?= e($as['materia']) ?></td><td><?= e(trim($as['curso'] . ' ' . $as['division'] . ($as['especialidad'] ? ' ' . $as['especialidad'] : ''))) ?></td></tr>
   <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
 </div>
</div>
<div class="card">
 <h2>Alumnos de mis divisiones</h2>
 <?php if (!$misAlumnos): ?><p class="muted">No hay alumnos en tus divisiones asignadas.</p>
 <?php else: ?><div class="table-wrap"><table><thead><tr><th>Alumno</th><th>DNI</th><th>Legajo</th><th>Curso / división</th></tr></thead><tbody>
  <?php foreach ($misAlumnos as $a): ?>
   <tr><td><?= e(trim($a['nombre'] . ' ' . $a['apellido'])) ?></td><td><?= e($a['dni']) ?></td><td><?= e($a['legajo']) ?></td>
       <td><?= e(trim($a['curso'] . ' ' . $a['division'] . ($a['especialidad'] ? ' ' . $a['especialidad'] : ''))) ?></td></tr>
  <?php endforeach; ?>
 </tbody></table></div><?php endif; ?>
</div>
<?php endif; ?>
<?php else: ?>
<h1>Docentes</h1>

<?php if (($_GET['form'] ?? '') === 'nuevo'): ?>
<div class="card">
 <h2>Registrar docente</h2>
 <?php if ($erroresForm): ?><div class="alert err"><?= e(implode(' ', $erroresForm)) ?></div><?php endif; ?>
 <p class="muted">La contraseña la define el docente desde el enlace de activación enviado por correo.</p>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="crear">
  <div class="grid g3">
   <div><label for="nombre">Nombre</label><input id="nombre" name="nombre" class="input" required value="<?= e(old('nombre')) ?>"></div>
   <div><label for="apellido">Apellido</label><input id="apellido" name="apellido" class="input" required value="<?= e(old('apellido')) ?>"></div>
   <div><label for="dni">DNI</label><input id="dni" name="dni" class="input" required value="<?= e(old('dni')) ?>"></div>
   <div><label for="email">Correo electrónico</label><input id="email" name="email" type="email" class="input" required value="<?= e(old('email')) ?>"></div>
   <div><label for="telefono">Teléfono (opcional)</label><input id="telefono" name="telefono" class="input" value="<?= e(old('telefono')) ?>"></div>
   <div><label for="cargo">Cargo</label><input id="cargo" name="cargo" class="input" value="<?= e(old('cargo', 'Docente')) ?>"></div>
   <div><label for="titulo">Título</label><input id="titulo" name="titulo" class="input" required value="<?= e(old('titulo')) ?>"></div>
  </div>
  <p><button class="btn">Registrar y enviar activación</button> <a class="btn g" href="<?= e(url('docentes.php')) ?>">Cancelar</a></p>
 </form>
</div>
<?php elseif ($editar): ?>
<div class="card">
 <h2>Editar docente: <?= e(trim($editar['nombre'] . ' ' . $editar['apellido'])) ?></h2>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
  <div class="grid g3">
   <div><label for="nombre">Nombre</label><input id="nombre" name="nombre" class="input" required value="<?= e($editar['nombre']) ?>"></div>
   <div><label for="apellido">Apellido</label><input id="apellido" name="apellido" class="input" required value="<?= e($editar['apellido']) ?>"></div>
   <div><label for="telefono">Teléfono</label><input id="telefono" name="telefono" class="input" value="<?= e($editar['telefono']) ?>"></div>
   <div><label for="cargo">Cargo</label><input id="cargo" name="cargo" class="input" required value="<?= e($editar['cargo']) ?>"></div>
   <div><label for="titulo">Título</label><input id="titulo" name="titulo" class="input" required value="<?= e($editar['titulo']) ?>"></div>
  </div>
  <p><button class="btn">Guardar</button> <a class="btn g" href="<?= e(url('docentes.php')) ?>">Cancelar</a></p>
 </form>
</div>
<?php endif; ?>

<div class="card">
 <h2>Buscar docentes</h2>
 <form class="toolbar" method="get">
  <input id="q" name="q" class="input" value="<?= e($q) ?>" placeholder="Nombre, DNI, legajo o correo">
  <button class="btn s">Buscar</button>
  <a class="btn" href="<?= e(url('docentes.php?form=nuevo')) ?>">Nuevo docente</a>
 </form>
 <p><input class="input" type="search" data-filter="#tabla-docentes" placeholder="Filtrar tabla en pantalla…" aria-label="Filtrar tabla"></p>
 <div class="table-wrap"><table id="tabla-docentes">
  <thead><tr><th>Docente</th><th>DNI</th><th>Legajo</th><th>Cargo</th><th>Título</th><th>Estado</th><th>Acciones</th></tr></thead>
  <tbody>
  <?php if (!$docentes): ?><tr><td colspan="7" class="muted">No hay docentes que coincidan con la búsqueda.</td></tr><?php endif; ?>
  <?php foreach ($docentes as $d): ?>
   <tr>
    <td><?= e(trim($d['nombre'] . ' ' . $d['apellido'])) ?><br><small><?= e($d['email']) ?></small></td>
    <td><?= e($d['dni']) ?></td><td><?= e($d['legajo']) ?></td>
    <td><?= e($d['cargo']) ?></td><td><?= e($d['titulo'] ?: '—') ?></td>
    <td><span class="pill <?= $d['activo'] ? 'ok' : 'err' ?>"><?= $d['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
    <td><div class="btn-row">
     <a class="btn s" href="<?= e(url('docentes.php?editar=' . $d['id'])) ?>">Editar</a>
     <?php if ((int)$d['usuario_id'] !== $uid): ?>
      <form method="post" style="display:inline" data-confirm="¿Seguro que desea cambiar el estado del docente?"><?= csrf_field() ?>
       <input type="hidden" name="accion" value="<?= $d['activo'] ? 'desactivar' : 'activar' ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
       <button class="btn s <?= $d['activo'] ? 'd' : '' ?>"><?= $d['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
     <?php endif; ?>
    </div></td>
   </tr>
  <?php endforeach; ?>
  </tbody>
 </table></div>
</div>
<?php endif; ?>
<?php layout_footer(); ?>
