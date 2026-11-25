<?php
/** Activación de cuenta: el usuario crea su contraseña desde el enlace enviado por correo. */
require_once __DIR__ . '/config/app.php';
$token = $_GET['t'] ?? '';
$reg = $token ? token_valido($token, 'activacion') : null;
$error = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $token = $_POST['t'] ?? '';
    $reg = token_valido($token, 'activacion');
    $pass = $_POST['clave'] ?? '';
    $pass2 = $_POST['clave2'] ?? '';
    if (!$reg) $error = 'El enlace no es válido o venció. Pedí uno nuevo a administración.';
    elseif (mb_strlen($pass) < 8) $error = 'La contraseña debe tener al menos 8 caracteres.';
    elseif ($pass !== $pass2) $error = 'Las contraseñas no coinciden.';
    else {
        global $pdo;
        $pdo->prepare('UPDATE usuarios SET password_hash=?, verificado=1, activo=1 WHERE id=?')->execute([hash_password($pass), $reg['usuario_id']]);
        $pdo->prepare('UPDATE tokens SET usado=1 WHERE id=?')->execute([$reg['id']]);
        audit('ACTIVACION_CUENTA', 'usuarios', (string)$reg['usuario_id'], 'Cuenta activada por el usuario');
        $ok = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Activar cuenta · ET35</title><link rel="stylesheet" href="<?= e(asset_url('css/styles.css')) ?>"></head>
<body class="login-wrap"><div class="login-box">
<div class="brand"><img src="<?= e(asset_url('img/escudo.png')) ?>" alt="Escudo"></div>
<h1>Activar cuenta</h1>
<?php if ($ok): ?>
 <div class="alert ok">Tu cuenta fue activada correctamente.</div>
 <p style="text-align:center"><a class="btn" href="<?= e(url('login.php')) ?>">Iniciar sesión</a></p>
<?php elseif (!$reg): ?>
 <div class="alert err">Enlace inválido o vencido. Solicitá uno nuevo a administración.</div>
<?php else: ?>
 <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
 <p class="muted" style="font-size:.85rem">Establecé una contraseña personal para ingresar al sistema.</p>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
  <label for="clave">Nueva contraseña</label><input id="clave" name="clave" type="password" minlength="8" required>
  <label for="clave2">Repetir contraseña</label><input id="clave2" name="clave2" type="password" minlength="8" required>
  <p><button class="btn" style="width:100%">Activar cuenta</button></p>
 </form>
<?php endif; ?>
</div></body></html>
