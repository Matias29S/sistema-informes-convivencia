<?php
/** Datos personales y cambio de contraseña del usuario autenticado. */
require_once __DIR__ . '/config/app.php';
require_login();
global $pdo;
$u = current_user();
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'datos') {
        $nombre = texto($_POST['nombre'] ?? '', 2, 80);
        $apellido = texto($_POST['apellido'] ?? '', 2, 80);
        $email = trim($_POST['email'] ?? '');
        $tel = texto($_POST['telefono'] ?? '', 0, 30) ?? '';
        $dir = texto($_POST['direccion'] ?? '', 0, 180) ?? '';
        if (!$nombre || !$apellido || !es_email($email)) $err = 'Revisá nombre, apellido y correo electrónico.';
        else {
            $st = $pdo->prepare('SELECT id FROM usuarios WHERE email=? AND id<>?');
            $st->execute([$email, $u['id']]);
            if ($st->fetch()) $err = 'Ese correo ya está registrado.';
            else {
                $pdo->prepare('UPDATE usuarios SET nombre=?, apellido=?, email=?, telefono=?, direccion=? WHERE id=?')
                    ->execute([$nombre, $apellido, $email, $tel, $dir, $u['id']]);
                audit('EDITAR_PERFIL', 'usuarios', (string)$u['id'], 'Datos personales actualizados');
                $msg = 'Datos guardados correctamente.';
            }
        }
    } elseif ($accion === 'clave') {
        $actual = $_POST['actual'] ?? ''; $nueva = $_POST['nueva'] ?? ''; $rep = $_POST['rep'] ?? '';
        if (!verify_password($actual, $u['password_hash'])) $err = 'La contraseña actual es incorrecta.';
        elseif (mb_strlen($nueva) < 8) $err = 'La nueva contraseña debe tener al menos 8 caracteres.';
        elseif ($nueva !== $rep) $err = 'Las contraseñas no coinciden.';
        else {
            $pdo->prepare('UPDATE usuarios SET password_hash=? WHERE id=?')->execute([hash_password($nueva), $u['id']]);
            audit('CAMBIAR_CLAVE', 'usuarios', (string)$u['id'], 'Contraseña cambiada');
            $msg = 'Contraseña cambiada correctamente.';
        }
    }
    $u = current_user();
}
layout_header('Mi perfil');
?>
<div class="grid g2">
 <div class="card">
  <h2>Mis datos</h2>
  <p class="muted">Rol: <b><?= e($u['rol_nombre']) ?></b> · Usuario: <b><?= e($u['username']) ?></b><br>
   Último acceso: <?= e($u['ultimo_acceso'] ?: '—') ?></p>
  <?php if ($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="datos">
   <label for="nombre">Nombre</label><input id="nombre" name="nombre" required value="<?= e($u['nombre']) ?>">
   <label for="apellido">Apellido</label><input id="apellido" name="apellido" required value="<?= e($u['apellido']) ?>">
   <label for="email">Correo electrónico</label><input id="email" name="email" type="email" required value="<?= e($u['email']) ?>">
   <label for="telefono">Teléfono</label><input id="telefono" name="telefono" value="<?= e($u['telefono']) ?>">
   <label for="direccion">Dirección</label><input id="direccion" name="direccion" value="<?= e($u['direccion']) ?>">
   <p><button class="btn">Guardar datos</button></p>
  </form>
 </div>
 <div class="card">
  <h2>Cambiar contraseña</h2>
  <p class="muted">Usá una contraseña de al menos 8 caracteres.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="clave">
   <label for="actual">Contraseña actual</label><input id="actual" name="actual" type="password" required>
   <label for="nueva">Nueva contraseña</label><input id="nueva" name="nueva" type="password" minlength="8" required>
   <label for="rep">Repetir nueva contraseña</label><input id="rep" name="rep" type="password" minlength="8" required>
   <p><button class="btn">Cambiar contraseña</button></p>
  </form>
 </div>
</div>
<?php layout_footer(); ?>
