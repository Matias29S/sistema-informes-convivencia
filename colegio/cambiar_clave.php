<?php
/** Establece una nueva contraseña usando un token de recuperación. */
require_once __DIR__ . '/config/app.php';
$token = $_GET['t'] ?? '';
$reg = $token ? token_valido($token, 'recuperacion') : null;
$error = ''; $ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $token = $_POST['t'] ?? '';
    $reg = token_valido($token, 'recuperacion');
    $pass = $_POST['clave'] ?? ''; $pass2 = $_POST['clave2'] ?? '';
    if (!$reg) $error = 'El enlace no es válido o venció.';
    elseif (mb_strlen($pass) < 8) $error = 'La contraseña debe tener al menos 8 caracteres.';
    elseif ($pass !== $pass2) $error = 'Las contraseñas no coinciden.';
    else {
        global $pdo;
        $pdo->prepare('UPDATE usuarios SET password_hash=?, intentos=0, bloqueado_hasta=NULL WHERE id=?')->execute([hash_password($pass), $reg['usuario_id']]);
        $pdo->prepare('UPDATE tokens SET usado=1 WHERE id=?')->execute([$reg['id']]);
        audit('RECUPERACION_CLAVE', 'usuarios', (string)$reg['usuario_id'], 'Contraseña restablecida');
        $ok = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nueva contraseña · ET35</title><link rel="stylesheet" href="<?= e(asset_url('css/styles.css')) ?>"></head>
<body class="login-wrap"><div class="login-box">
<div class="brand"><img src="<?= e(asset_url('img/escudo.png')) ?>" alt="Escudo"></div>
<h1>Nueva contraseña</h1>
<?php if ($ok): ?><div class="alert ok">Contraseña actualizada. Ya podés ingresar.</div><p style="text-align:center"><a class="btn" href="<?= e(url('login.php')) ?>">Iniciar sesión</a></p>
<?php elseif (!$reg): ?><div class="alert err">Enlace inválido o vencido. Solicitá uno nuevo.</div>
<?php else: ?>
 <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
  <label for="clave">Nueva contraseña</label><input id="clave" name="clave" type="password" minlength="8" required>
  <label for="clave2">Repetir contraseña</label><input id="clave2" name="clave2" type="password" minlength="8" required>
  <p><button class="btn" style="width:100%">Guardar contraseña</button></p>
 </form>
<?php endif; ?>
</div></body></html>
