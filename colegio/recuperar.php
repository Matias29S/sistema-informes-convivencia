<?php
/** Recuperación segura de contraseña: envía un enlace temporal por correo. */
require_once __DIR__ . '/config/app.php';
$enviado = false; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    if (!es_email($email)) $error = 'Ingresá un correo electrónico válido.';
    else {
        global $pdo;
        $st = $pdo->prepare('SELECT id, nombre FROM usuarios WHERE email=? AND activo=1');
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u) {
            $token = crear_token((int)$u['id'], 'recuperacion', 2);
            $link = APP_URL . 'cambiar_clave.php?t=' . urlencode($token);
            Mailer::send($email, 'Recuperación de contraseña · ET35',
                "Hola {$u['nombre']}:\n\nRecibimos un pedido para restablecer tu contraseña.\n"
                . "Ingresá a este enlace dentro de las próximas 2 horas:\n{$link}\n\n"
                . "Si no fuiste vos, ignorá este correo.\n\nEscuela Técnica N° 35 D.E. 18");
        }
        $enviado = true; // No revelamos si el correo existe.
    }
}
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recuperar contraseña · ET35</title><link rel="stylesheet" href="<?= e(url('assets/css/styles.css')) ?>"></head>
<body class="login-wrap"><div class="login-box">
<div class="brand"><img src="<?= e(url('assets/img/escudo.png')) ?>" alt="Escudo"></div>
<h1>Recuperar contraseña</h1>
<p class="muted" style="text-align:center">Te enviaremos un enlace temporal para crear una nueva contraseña.</p>
<?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
<?php if ($enviado): ?><div class="alert ok">Si el correo está registrado, recibiste un enlace para restablecer la contraseña.</div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
 <label for="email">Correo electrónico</label><input id="email" name="email" type="email" required value="<?= e(old('email')) ?>">
 <p><button class="btn" style="width:100%">Enviar enlace</button></p>
</form>
<p style="text-align:center"><a href="<?= e(url('login.php')) ?>">Volver al inicio de sesión</a></p>
</div></body></html>
