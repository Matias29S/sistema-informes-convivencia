<?php
/** Administración de cuentas: alta, edición y envío/reenvío de credenciales. */
require_once __DIR__ . '/../config/app.php';
require_permission('usuarios.crear');
global $pdo;

/** Genera una contraseña temporal nueva, la guarda hasheada y envía el correo con los datos. */
function reenviar_credenciales_usuario(int $usuarioId): bool {
    global $pdo;
    $st = $pdo->prepare('SELECT email, username, nombre, rol_id FROM usuarios WHERE id=?');
    $st->execute([$usuarioId]);
    $u = $st->fetch();
    if (!$u) return false;
    $clave = generar_clave_temporal();
    $pdo->prepare('UPDATE usuarios SET password_hash=?, verificado=1 WHERE id=?')
        ->execute([hash_password($clave), $usuarioId]);
    audit('REENVIAR_CREDENCIALES', 'usuarios', (string)$usuarioId, 'Nueva contraseña temporal generada');
    return enviar_datos_acceso($u['email'], $u['username'], $clave, $u['nombre'], $u['rol_id']);
}

/** Valida una fecha en formato YYYY-MM-DD. */
function es_fecha_valida_usuario($valor): bool {
    if (!is_string($valor) || $valor === '') return false;
    $f = DateTime::createFromFormat('!Y-m-d', $valor);
    return $f && $f->format('Y-m-d') === $valor;
}

// Catálogos necesarios para los formularios (consultas preparadas).
$st = $pdo->prepare('SELECT id, nombre FROM roles ORDER BY id');
$st->execute();
$roles = $st->fetchAll();
$rolesValidos = array_column($roles, 'id');

$st = $pdo->prepare("SELECT d.id, CONCAT(c.nombre, ' ', d.nombre,
        CASE WHEN c.especialidad IS NOT NULL AND c.especialidad <> '' THEN CONCAT(' ', c.especialidad) ELSE '' END) AS etiqueta
    FROM divisiones d JOIN cursos c ON c.id = d.curso_id
    ORDER BY c.anio, c.especialidad, d.nombre");
$st->execute();
$divisiones = $st->fetchAll();

$erroresForm = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $rol       = (string)($_POST['rol'] ?? '');
        $nombre    = texto($_POST['nombre'] ?? '', 2, 80);
        $apellido  = texto($_POST['apellido'] ?? '', 2, 80);
        $dni       = preg_replace('/\s+/', '', (string)($_POST['dni'] ?? ''));
        $email     = trim((string)($_POST['email'] ?? ''));
        $telefono  = texto($_POST['telefono'] ?? '', 0, 30) ?? '';
        $fechaNac  = trim((string)($_POST['fecha_nacimiento'] ?? ''));
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $cargo     = texto($_POST['cargo'] ?? '', 2, 80);
        $titulo    = texto($_POST['titulo'] ?? '', 1, 100);

        $tutorNombre   = texto($_POST['tutor_nombre'] ?? '', 2, 80);
        $tutorApellido = texto($_POST['tutor_apellido'] ?? '', 2, 80);
        $tutorDni      = preg_replace('/\s+/', '', (string)($_POST['tutor_dni'] ?? ''));
        $tutorEmail    = trim((string)($_POST['tutor_email'] ?? ''));
        $relacion      = texto($_POST['relacion'] ?? '', 2, 40);

        if (!in_array($rol, $rolesValidos, true)) $erroresForm[] = 'Seleccioná un rol válido.';
        if (!$nombre || !$apellido) $erroresForm[] = 'Nombre y apellido son obligatorios (2 a 80 caracteres).';
        if (!es_dni($dni)) $erroresForm[] = 'El DNI debe tener entre 7 y 9 dígitos.';
        if (!es_email($email)) $erroresForm[] = 'El correo electrónico no es válido.';

        // Unicidad de email y DNI.
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE email=?');
        $st->execute([$email]);
        if ($st->fetch()) $erroresForm[] = 'Ese correo ya está registrado.';
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE dni=?');
        $st->execute([$dni]);
        if ($st->fetch()) $erroresForm[] = 'Ese DNI ya está registrado.';

        $tutorExistenteId = 0;
        if ($rol === 'ALUMNO') {
            if (!es_fecha_valida_usuario($fechaNac)) $erroresForm[] = 'La fecha de nacimiento no es válida.';
            $st = $pdo->prepare('SELECT id FROM divisiones WHERE id=?');
            $st->execute([$divisionId]);
            if (!$divisionId || !$st->fetch()) $erroresForm[] = 'Seleccioná una división válida.';
            if (!$tutorNombre || !$tutorApellido || !es_dni($tutorDni) || !es_email($tutorEmail) || !$relacion) {
                $erroresForm[] = 'Completá todos los datos del tutor (nombre, apellido, DNI, correo y relación).';
            }
            if ($tutorEmail === $email) $erroresForm[] = 'El correo del tutor debe ser distinto del del alumno.';
            if (es_email($tutorEmail) && es_dni($tutorDni)) {
                $st = $pdo->prepare('SELECT id, rol_id, dni FROM usuarios WHERE email=?');
                $st->execute([$tutorEmail]);
                $tutorUser = $st->fetch();
                if ($tutorUser) {
                    if ($tutorUser['rol_id'] !== 'TUTOR' || (string)$tutorUser['dni'] !== $tutorDni) {
                        $erroresForm[] = 'El correo del tutor pertenece a otra cuenta o el DNI no coincide.';
                    } else {
                        $tutorExistenteId = (int)$tutorUser['id'];
                    }
                } else {
                    $st = $pdo->prepare('SELECT id FROM usuarios WHERE dni=?');
                    $st->execute([$tutorDni]);
                    if ($st->fetch()) $erroresForm[] = 'El DNI del tutor ya está registrado con otro correo.';
                }
            }
        }

        if ($rol === 'DOCENTE') {
            if (!$cargo) $erroresForm[] = 'El cargo es obligatorio.';
            if (!$titulo) $erroresForm[] = 'El título es obligatorio.';
        }

        if ($erroresForm) {
            set_old($_POST);
            flash('err', implode(' ', $erroresForm));
            redirect('admin/usuarios.php?form=nuevo');
        }

        $username = generar_username($nombre, $apellido);
        $claveTemp = generar_clave_temporal();
        $passwordHash = hash_password($claveTemp);
        $tutorUsernameNew = '';
        $tutorClaveTemp = '';

        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('INSERT INTO usuarios
                (username, email, password_hash, rol_id, nombre, apellido, dni, fecha_nacimiento, telefono, verificado, activo)
                VALUES (?,?,?,?,?,?,?,?,?,1,1)');
            $st->execute([$username, $email, $passwordHash, $rol, $nombre, $apellido, $dni,
                          $rol === 'ALUMNO' ? $fechaNac : null, $telefono]);
            $usuarioId = (int)$pdo->lastInsertId();

            if ($rol === 'ALUMNO') {
                $legajo = 'LEG-' . date('Y') . '-' . str_pad((string)$usuarioId, 3, '0', STR_PAD_LEFT);
                $st = $pdo->prepare('INSERT INTO alumnos (usuario_id, legajo, division_id, activo) VALUES (?,?,?,1)');
                $st->execute([$usuarioId, $legajo, $divisionId]);
                $alumnoId = (int)$pdo->lastInsertId();

                if ($tutorExistenteId) {
                    $tutorId = $tutorExistenteId;
                    $st = $pdo->prepare('SELECT id FROM tutores WHERE usuario_id=?');
                    $st->execute([$tutorId]);
                    $tutorRow = $st->fetch();
                    if (!$tutorRow) {
                        $st = $pdo->prepare('INSERT INTO tutores (usuario_id) VALUES (?)');
                        $st->execute([$tutorId]);
                        $tutorIdRow = (int)$pdo->lastInsertId();
                    } else {
                        $tutorIdRow = (int)$tutorRow['id'];
                    }
                } else {
                    $tutorUsernameNew = generar_username($tutorNombre, $tutorApellido);
                    $tutorClaveTemp = generar_clave_temporal();
                    $st = $pdo->prepare('INSERT INTO usuarios
                        (username, email, password_hash, rol_id, nombre, apellido, dni, verificado, activo)
                        VALUES (?,?,?,?,?,?,?,1,1)');
                    $st->execute([$tutorUsernameNew, $tutorEmail, hash_password($tutorClaveTemp),
                                  'TUTOR', $tutorNombre, $tutorApellido, $tutorDni]);
                    $tutorUserNewId = (int)$pdo->lastInsertId();
                    $st = $pdo->prepare('INSERT INTO tutores (usuario_id) VALUES (?)');
                    $st->execute([$tutorUserNewId]);
                    $tutorIdRow = (int)$pdo->lastInsertId();
                    $tutorId = $tutorUserNewId;
                }

                $st = $pdo->prepare('INSERT INTO alumno_tutor (alumno_id, tutor_id, relacion) VALUES (?,?,?)');
                $st->execute([$alumnoId, $tutorIdRow, $relacion]);
            } elseif ($rol === 'DOCENTE') {
                $legajoDoc = 'DOC-' . str_pad((string)$usuarioId, 3, '0', STR_PAD_LEFT);
                $st = $pdo->prepare('INSERT INTO docentes (usuario_id, legajo, cargo, titulo) VALUES (?,?,?,?)');
                $st->execute([$usuarioId, $legajoDoc, $cargo, $titulo ?: null]);
            } elseif ($rol === 'TUTOR') {
                $st = $pdo->prepare('INSERT INTO tutores (usuario_id, telefono) VALUES (?,?)');
                $st->execute([$usuarioId, $telefono ?: null]);
            }
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_old($_POST);
            flash('err', 'No se pudo crear la cuenta. Verificá los datos e intentá de nuevo.');
            redirect('admin/usuarios.php?form=nuevo');
        }

        $enviado = enviar_datos_acceso($email, $username, $claveTemp, $nombre, $rol);
        $enviadoTutor = true;
        if ($tutorUsernameNew !== '') {
            $enviadoTutor = enviar_datos_acceso($tutorEmail, $tutorUsernameNew, $tutorClaveTemp, $tutorNombre, 'TUTOR');
        }
        audit('CREAR_CUENTA', 'usuarios', (string)$usuarioId, "Alta de cuenta {$username} ({$rol})");
        clear_old();
        flash($enviado && $enviadoTutor ? 'ok' : 'info',
            $enviado && $enviadoTutor ? 'Cuenta creada y credenciales enviadas por correo.'
                                      : 'Cuenta creada, pero no se pudo enviar algún correo. Usá «Reenviar credenciales».');
        redirect('admin/usuarios.php');
    }

    if ($accion === 'editar') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM usuarios WHERE id=?');
        $st->execute([$id]);
        $usuario = $st->fetch();
        if (!$usuario) {
            flash('err', 'La cuenta no existe.');
            redirect('admin/usuarios.php');
        }
        $rol = (string)($_POST['rol'] ?? $usuario['rol_id']);
        $nombre = texto($_POST['nombre'] ?? '', 2, 80);
        $apellido = texto($_POST['apellido'] ?? '', 2, 80);
        $telefono = texto($_POST['telefono'] ?? '', 0, 30) ?? '';
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $cargo = texto($_POST['cargo'] ?? '', 2, 80);
        $titulo = texto($_POST['titulo'] ?? '', 1, 100);

        if (!in_array($rol, $rolesValidos, true)) $erroresForm[] = 'Rol inválido.';
        if (!$nombre || !$apellido) $erroresForm[] = 'Nombre y apellido son obligatorios.';
        if ($rol === 'ALUMNO') {
            $st = $pdo->prepare('SELECT id FROM divisiones WHERE id=?');
            $st->execute([$divisionId]);
            if (!$divisionId || !$st->fetch()) $erroresForm[] = 'Seleccioná una división válida.';
        }
        if ($rol === 'DOCENTE' && !$cargo) $erroresForm[] = 'El cargo es obligatorio.';
        if ($rol === 'DOCENTE' && !$titulo) $erroresForm[] = 'El título es obligatorio.';
        if ($erroresForm) {
            set_old($_POST);
            flash('err', implode(' ', $erroresForm));
            redirect('admin/usuarios.php?editar=' . $id);
        }

        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('UPDATE usuarios SET rol_id=?, nombre=?, apellido=?, telefono=? WHERE id=?');
            $st->execute([$rol, $nombre, $apellido, $telefono, $id]);
            if ($rol === 'ALUMNO') {
                $st = $pdo->prepare('SELECT id, legajo FROM alumnos WHERE usuario_id=?');
                $st->execute([$id]);
                $alumno = $st->fetch();
                if ($alumno) {
                    $pdo->prepare('UPDATE alumnos SET division_id=? WHERE id=?')->execute([$divisionId, (int)$alumno['id']]);
                } else {
                    $legajo = 'LEG-' . date('Y') . '-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
                    $pdo->prepare('INSERT INTO alumnos (usuario_id,legajo,division_id,activo) VALUES (?,?,?,1)')
                        ->execute([$id, $legajo, $divisionId]);
                }
            } elseif ($rol === 'DOCENTE') {
                $st = $pdo->prepare('SELECT id, legajo FROM docentes WHERE usuario_id=?');
                $st->execute([$id]);
                $docente = $st->fetch();
                if ($docente) {
                    $pdo->prepare('UPDATE docentes SET cargo=?, titulo=? WHERE id=?')
                        ->execute([$cargo, $titulo ?: null, (int)$docente['id']]);
                } else {
                    $legajo = 'DOC-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
                    $pdo->prepare('INSERT INTO docentes (usuario_id,legajo,cargo,titulo) VALUES (?,?,?,?)')
                        ->execute([$id, $legajo, $cargo, $titulo ?: null]);
                }
            }
            $pdo->commit();
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('err', 'No se pudo guardar la cuenta.');
            redirect('admin/usuarios.php?editar=' . $id);
        }
        audit('EDITAR_CUENTA', 'usuarios', (string)$id, "Rol={$rol}; nombre={$nombre} {$apellido}");
        clear_old();
        flash('ok', 'Cuenta actualizada correctamente.');
        redirect('admin/usuarios.php?editar=' . $id);
    }

    if ($accion === 'activar' || $accion === 'desactivar') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT username FROM usuarios WHERE id=?');
        $st->execute([$id]);
        $username = $st->fetchColumn();
        if (!$username) {
            flash('err', 'La cuenta no existe.');
        } elseif ($accion === 'desactivar' && $id === (int)($_SESSION['user_id'] ?? 0)) {
            flash('err', 'No podés desactivar tu propia cuenta.');
        } else {
            $valor = $accion === 'activar' ? 1 : 0;
            $pdo->prepare('UPDATE usuarios SET activo=? WHERE id=?')->execute([$valor, $id]);
            audit($accion === 'activar' ? 'ACTIVAR_CUENTA' : 'DESACTIVAR_CUENTA', 'usuarios', (string)$id, "Cuenta {$username}");
            flash('ok', $accion === 'activar' ? 'Cuenta activada.' : 'Cuenta desactivada.');
        }
        redirect('admin/usuarios.php');
    }

    if ($accion === 'reenviar') {
        $id = (int)($_POST['id'] ?? 0);
        $enviado = reenviar_credenciales_usuario($id);
        flash($enviado ? 'ok' : 'err',
            $enviado ? 'Nueva contraseña temporal generada y enviada por correo.'
                     : 'No se pudo enviar el correo o la cuenta no existe. Revisá la configuración de correo.');
        redirect('admin/usuarios.php');
    }
}

// ---------- Listado ----------
$q = trim((string)($_GET['q'] ?? ''));
$filtroRol = (string)($_GET['rol'] ?? '');
$filtroActivo = (string)($_GET['activo'] ?? '');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.nombre LIKE ? OR u.apellido LIKE ? OR u.dni LIKE ?)';
    for ($i = 0; $i < 5; $i++) $params[] = '%' . $q . '%';
}
if (in_array($filtroRol, $rolesValidos, true)) {
    $where[] = 'u.rol_id=?';
    $params[] = $filtroRol;
}
if ($filtroActivo === '1' || $filtroActivo === '0') {
    $where[] = 'u.activo=?';
    $params[] = (int)$filtroActivo;
}
$sql = 'SELECT u.*, r.nombre AS rol_nombre FROM usuarios u JOIN roles r ON r.id=u.rol_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY u.id DESC LIMIT 300';
$st = $pdo->prepare($sql);
$st->execute($params);
$cuentas = $st->fetchAll();

// Datos para el formulario de edición.
$editarId = (int)($_GET['editar'] ?? 0);
$editar = null;
$editarAlumno = null;
$editarDocente = null;
if ($editarId) {
    $st = $pdo->prepare('SELECT * FROM usuarios WHERE id=?');
    $st->execute([$editarId]);
    $editar = $st->fetch() ?: null;
    if ($editar) {
        $st = $pdo->prepare('SELECT id, division_id FROM alumnos WHERE usuario_id=?');
        $st->execute([$editarId]);
        $editarAlumno = $st->fetch() ?: null;
        $st = $pdo->prepare('SELECT id, cargo, titulo FROM docentes WHERE usuario_id=?');
        $st->execute([$editarId]);
        $editarDocente = $st->fetch() ?: null;
    }
}
$rolForm = old('rol', $editar['rol_id'] ?? 'ALUMNO');

layout_header('Administración de cuentas');
?>
<h1>Cuentas de usuario</h1>
<p class="muted">Alta de cuentas para cualquier rol, edición y reenvío de credenciales por correo.</p>

<?php if (($_GET['form'] ?? '') === 'nuevo'): ?>
<div class="card">
 <h2>Nueva cuenta</h2>
 <?php if ($erroresForm): ?><div class="alert err"><?= e(implode(' ', $erroresForm)) ?></div><?php endif; ?>
 <p class="muted">Se generan usuario y contraseña temporal aleatorios y se envían por correo. Cada persona puede cambiar la contraseña desde Mi perfil.</p>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="crear">
  <div class="grid g3">
   <div><label for="rol">Rol</label>
    <select id="rol" name="rol" class="select" required>
     <?php foreach ($roles as $r): ?>
      <option value="<?= e($r['id']) ?>" <?= $rolForm === $r['id'] ? 'selected' : '' ?>><?= e($r['nombre']) ?></option>
     <?php endforeach; ?>
    </select></div>
   <div><label for="nombre">Nombre</label><input id="nombre" name="nombre" class="input" required value="<?= e(old('nombre')) ?>"></div>
   <div><label for="apellido">Apellido</label><input id="apellido" name="apellido" class="input" required value="<?= e(old('apellido')) ?>"></div>
   <div><label for="dni">DNI</label><input id="dni" name="dni" class="input" inputmode="numeric" required value="<?= e(old('dni')) ?>"></div>
   <div><label for="email">Correo electrónico</label><input id="email" name="email" type="email" class="input" required value="<?= e(old('email')) ?>"></div>
   <div><label for="telefono">Teléfono (opcional)</label><input id="telefono" name="telefono" class="input" value="<?= e(old('telefono')) ?>"></div>
   <div><label for="fecha_nacimiento">Fecha de nacimiento (alumnos)</label><input id="fecha_nacimiento" name="fecha_nacimiento" type="date" class="input" value="<?= e(old('fecha_nacimiento')) ?>"></div>
   <div><label for="division_id">Curso / división (alumnos)</label>
    <select id="division_id" name="division_id" class="select">
     <option value="">Seleccionar…</option>
     <?php foreach ($divisiones as $d): ?>
      <option value="<?= (int)$d['id'] ?>" <?= (string)old('division_id') === (string)$d['id'] ? 'selected' : '' ?>><?= e($d['etiqueta']) ?></option>
     <?php endforeach; ?>
    </select></div>
   <div><label for="cargo">Cargo (docentes)</label><input id="cargo" name="cargo" class="input" value="<?= e(old('cargo')) ?>"></div>
   <div><label for="titulo">Título (docentes)</label><input id="titulo" name="titulo" class="input" value="<?= e(old('titulo')) ?>"></div>
  </div>
  <fieldset style="border:1px solid var(--borde);border-radius:5px;margin-top:1rem;padding:1rem">
   <legend>Datos del tutor (obligatorio si el rol es ALUMNO)</legend>
   <div class="grid g3">
    <div><label for="tutor_nombre">Nombre</label><input id="tutor_nombre" name="tutor_nombre" class="input" value="<?= e(old('tutor_nombre')) ?>"></div>
    <div><label for="tutor_apellido">Apellido</label><input id="tutor_apellido" name="tutor_apellido" class="input" value="<?= e(old('tutor_apellido')) ?>"></div>
    <div><label for="tutor_dni">DNI</label><input id="tutor_dni" name="tutor_dni" class="input" value="<?= e(old('tutor_dni')) ?>"></div>
    <div><label for="tutor_email">Correo electrónico</label><input id="tutor_email" name="tutor_email" type="email" class="input" value="<?= e(old('tutor_email')) ?>"></div>
    <div><label for="relacion">Relación</label><input id="relacion" name="relacion" class="input" placeholder="Madre, Padre, Tutor…" value="<?= e(old('relacion')) ?>"></div>
   </div>
  </fieldset>
  <p><button class="btn">Crear cuenta y enviar credenciales</button>
   <a class="btn g" href="<?= e(url('admin/usuarios.php')) ?>">Cancelar</a></p>
 </form>
</div>
<?php elseif ($editar): ?>
<div class="card">
 <h2>Editar cuenta: <?= e($editar['username']) ?></h2>
 <?php if ($erroresForm): ?><div class="alert err"><?= e(implode(' ', $erroresForm)) ?></div><?php endif; ?>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
  <div class="grid g3">
   <div><label for="rol">Rol</label><select id="rol" name="rol" class="select" required>
     <?php foreach ($roles as $r): ?><option value="<?= e($r['id']) ?>" <?= $rolForm === $r['id'] ? 'selected' : '' ?>><?= e($r['nombre']) ?></option><?php endforeach; ?>
    </select></div>
   <div><label for="nombre">Nombre</label><input id="nombre" name="nombre" class="input" required value="<?= e(old('nombre', $editar['nombre'])) ?>"></div>
   <div><label for="apellido">Apellido</label><input id="apellido" name="apellido" class="input" required value="<?= e(old('apellido', $editar['apellido'])) ?>"></div>
   <div><label for="telefono">Teléfono</label><input id="telefono" name="telefono" class="input" value="<?= e(old('telefono', $editar['telefono'])) ?>"></div>
   <div><label for="division_id">Curso / división (alumnos)</label>
    <select id="division_id" name="division_id" class="select"><option value="">Seleccionar…</option>
     <?php foreach ($divisiones as $d): ?>
      <option value="<?= (int)$d['id'] ?>" <?= (string)old('division_id', $editarAlumno['division_id'] ?? '') === (string)$d['id'] ? 'selected' : '' ?>><?= e($d['etiqueta']) ?></option>
     <?php endforeach; ?>
    </select></div>
   <div><label for="cargo">Cargo (docentes)</label><input id="cargo" name="cargo" class="input" value="<?= e(old('cargo', $editarDocente['cargo'] ?? '')) ?>"></div>
   <div><label for="titulo">Título (docentes)</label><input id="titulo" name="titulo" class="input" value="<?= e(old('titulo', $editarDocente['titulo'] ?? '')) ?>"></div>
  </div>
  <p class="muted">Correo: <?= e($editar['email']) ?> · DNI: <?= e($editar['dni']) ?>. Esos datos no se modifican desde esta pantalla.</p>
  <p><button class="btn">Guardar cambios</button> <a class="btn g" href="<?= e(url('admin/usuarios.php')) ?>">Cancelar</a></p>
 </form>
</div>
<?php endif; ?>

<div class="card">
 <h2>Buscar cuentas</h2>
 <form class="toolbar" method="get">
  <label for="q">Texto</label><input id="q" name="q" class="input" value="<?= e($q) ?>" placeholder="Usuario, email, nombre o DNI">
  <label for="rol">Rol</label><select id="rol" name="rol" class="select">
   <option value="">Todos</option>
   <?php foreach ($roles as $r): ?><option value="<?= e($r['id']) ?>" <?= $filtroRol === $r['id'] ? 'selected' : '' ?>><?= e($r['nombre']) ?></option><?php endforeach; ?>
  </select>
  <label for="activo">Estado</label><select id="activo" name="activo" class="select">
   <option value="">Todos</option>
   <option value="1" <?= $filtroActivo === '1' ? 'selected' : '' ?>>Activas</option>
   <option value="0" <?= $filtroActivo === '0' ? 'selected' : '' ?>>Desactivadas</option>
  </select>
  <button class="btn s">Filtrar</button>
  <a class="btn" href="<?= e(url('admin/usuarios.php?form=nuevo')) ?>">Nueva cuenta</a>
 </form>
 <p><input class="input" type="search" data-filter="#tabla-usuarios" placeholder="Filtrar tabla en pantalla…" aria-label="Filtrar tabla"></p>
 <div class="table-wrap"><table id="tabla-usuarios">
  <thead><tr><th>Usuario</th><th>Email</th><th>Rol</th><th>Estado</th><th>Último acceso</th><th>Acciones</th></tr></thead>
  <tbody>
  <?php if (!$cuentas): ?><tr><td colspan="6" class="muted">No hay cuentas que coincidan con la búsqueda.</td></tr><?php endif; ?>
  <?php foreach ($cuentas as $c): ?>
   <tr>
    <td><?= e($c['username']) ?><br><small><?= e(trim($c['nombre'] . ' ' . $c['apellido'])) ?></small></td>
    <td><?= e($c['email']) ?></td>
    <td><?= e($c['rol_nombre']) ?></td>
    <td><span class="pill <?= $c['activo'] ? 'ok' : 'err' ?>"><?= $c['activo'] ? 'Activa' : 'Desactivada' ?></span>
        <br><small><?= $c['verificado'] ? 'Credenciales enviadas' : 'Pendiente' ?></small></td>
    <td><?= e($c['ultimo_acceso'] ?: '—') ?></td>
    <td>
     <div class="btn-row">
      <a class="btn s" href="<?= e(url('admin/usuarios.php?editar=' . $c['id'])) ?>">Editar</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="accion" value="reenviar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
       <button class="btn s" <?= $c['verificado'] ? 'title="Genera una nueva contraseña temporal"' : '' ?>>Reenviar credenciales</button></form>
      <?php if ((int)$c['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
       <form method="post" style="display:inline" data-confirm="¿Seguro que desea <?= $c['activo'] ? 'desactivar' : 'activar' ?> esta cuenta?"><?= csrf_field() ?>
        <input type="hidden" name="accion" value="<?= $c['activo'] ? 'desactivar' : 'activar' ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <button class="btn s <?= $c['activo'] ? 'd' : '' ?>"><?= $c['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
      <?php endif; ?>
     </div>
    </td>
   </tr>
  <?php endforeach; ?>
  </tbody>
 </table></div>
 <p class="muted">No se permite eliminar cuentas: use la desactivación para conservar el historial institucional.</p>
</div>
<?php layout_footer(); ?>
