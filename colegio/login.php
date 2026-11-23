<?php
require_once __DIR__ . '/config/app.php';
if (current_user()) redirect('dashboard.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$ok, $error] = intentar_login($_POST['usuario'] ?? '', $_POST['clave'] ?? '');
    if ($ok) redirect($_SESSION['redirect_after_login'] ?? 'dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión · ET35</title>
<link rel="stylesheet" href="<?= e(url('assets/css/styles.css')) ?>">
</head>
<body class="login-wrap">
<div class="login-box">
 <div class="brand"><img src="<?= e(url('assets/img/escudo.png')) ?>" alt="Escudo"></div>
 <h1>Iniciar sesión</h1>
 <p class="sub">Sistema de Informes de Convivencia</p>
 <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
 <?php foreach (flash_get() as $f): ?><div class="alert <?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></div><?php endforeach; ?>
 <form method="post">
  <?= csrf_field() ?>
  <label for="usuario">Correo electrónico o usuario</label>
  <input id="usuario" name="usuario" type="text" required autofocus autocomplete="username" value="<?= e(old('usuario')) ?>">
  <label for="clave">Contraseña</label>
  <input id="clave" name="clave" type="password" required autocomplete="current-password">
  <p class="muted" style="font-size:.78rem"><input type="checkbox" id="ver" data-toggle-pass="#clave" style="width:auto"> <label for="ver" style="display:inline;font-weight:400">Mostrar contraseña</label></p>
  <p><button class="btn" style="width:100%" type="submit">Ingresar</button></p>
 </form>
 <p style="text-align:center;font-size:.85rem"><a href="<?= e(url('recuperar.php')) ?>">Olvidé mi contraseña</a> · <a href="<?= e(url('index.php')) ?>">Sitio público</a></p>
 <div class="pre"><b>Cuentas de prueba</b><br>
  admin@et35.edu.ar · Admin123!<br>
  directivo@et35.edu.ar · Directivo123!<br>
  preceptor@et35.edu.ar · Preceptor123!<br>
  docente@et35.edu.ar · Docente123!<br>
  alumno.demo@gmail.com · Alumno123!<br>
  tutor.demo@gmail.com · Tutor123!<br>
  <small>El acceso con estas cuentas es solo para probar la instalación.</small>
 </div>
</div>
</body>
</html>
