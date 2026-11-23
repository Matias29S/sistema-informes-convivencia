<?php
/** Plantilla visual: barra lateral, barra superior, mensajes y cierre. */

function denegar_acceso(string $mensaje = 'No tenés autorización para ver esta página.'): void {
    http_response_code(403);
    layout_header('Acceso denegado');
    echo '<div class="card" style="max-width:620px;margin:4rem auto;text-align:center">'
        . '<h2 style="color:var(--rojo)">Acceso denegado</h2><p>' . e($mensaje) . '</p>'
        . '<p class="muted">Si creés que es un error, contactá a administración.</p>'
        . '<a class="btn" href="' . e(url('dashboard.php')) . '">Volver al panel</a></div>';
    layout_footer();
    exit;
}

function menu_items(): array {
    $u = current_user();
    if (!$u) return [];
    $rol = $u['rol_id'];
    $items = [['dashboard.php', 'Panel', 'grid']];

    if (has_permission('informes.crear')) $items[] = ['informes/crear.php', 'Crear informe', 'plus'];
    if (has_permission('informes.ver'))   $items[] = ['informes/listado.php', 'Informes', 'file'];
    if (has_permission('descargo.crear')) $items[] = ['informes/mis_informes.php?tab=descargos', 'Mis descargos', 'edit'];

    if ($rol === 'ADMIN' || $rol === 'PRECEPTOR') {
        $items[] = ['alumnos.php', 'Alumnos', 'users'];
        $items[] = ['cursos.php', 'Cursos y divisiones', 'book'];
    }
    if ($rol === 'ADMIN' || $rol === 'DOCENTE') $items[] = ['docentes.php', 'Docentes', 'users'];
    if ($rol === 'DIRECTIVO') {
        $items[] = ['informes/listado.php?estado=PENDIENTE_RESOLUCION', 'Resolver informes', 'check'];
        $items[] = ['estadisticas.php', 'Estadísticas', 'chart'];
    }
    if ($rol === 'PRECEPTOR') $items[] = ['informes/seguimiento.php', 'Seguimiento', 'clock'];
    if ($rol === 'TUTOR')     $items[] = ['tutores/alumnos.php', 'Alumnos asociados', 'users'];

    if (has_permission('estadisticas.ver') && $rol !== 'DIRECTIVO') $items[] = ['estadisticas.php', 'Estadísticas', 'chart'];
    if (has_permission('noticias.editar')) {
        $items[] = ['admin/noticias.php', 'Noticias', 'news'];
        $items[] = ['admin/eventos.php', 'Calendario', 'calendar'];
    }
    if (has_permission('usuarios.crear')) {
        $items[] = ['admin/usuarios.php', 'Cuentas', 'user'];
    }
    if ($rol === 'ADMIN') {
        $items[] = ['admin/permisos.php', 'Permisos', 'shield'];
        $items[] = ['admin/configuracion.php', 'Configuración', 'cog'];
        $items[] = ['admin/auditoria.php', 'Auditoría', 'history'];
        $items[] = ['admin/respaldos.php', 'Respaldos', 'save'];
    }
    $items[] = ['notificaciones/listado.php', 'Notificaciones', 'bell'];
    $items[] = ['perfil.php', 'Mi perfil', 'user'];
    return $items;
}

function layout_header(string $titulo = '', string $modulo = ''): void {
    $u = current_user();
    global $pdo;
    $sinLeer = 0;
    if ($u) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM notificaciones WHERE usuario_id=? AND leida=0');
        $st->execute([$u['id']]);
        $sinLeer = (int)$st->fetchColumn();
    }
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#123a63">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e(($titulo ? $titulo . ' - ' : '') . APP_NAME) ?> - ET35</title>
<link rel="stylesheet" href="<?= e(url('assets/css/styles.css')) ?>">
<script>window.BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
</head>
<body class="app-body">
<div class="layout">
 <aside id="sidebar" class="sidebar">
  <div class="brand">
   <img src="<?= e(url('assets/img/escudo.png')) ?>" alt="Escudo">
   <div><strong>ET N° 35</strong><small>Informes de Convivencia</small></div>
  </div>
  <nav class="side-nav">
   <?php
   $rutaActual = rtrim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/');
   foreach (menu_items() as [$link, $label, $icono]):
     $rutaLink = rtrim(BASE_URL . (string)(parse_url($link, PHP_URL_PATH) ?: ''), '/');
   ?>
    <a href="<?= e(url($link)) ?>" class="<?= $rutaActual === $rutaLink ? 'active' : '' ?>">
      <span class="ico"><?= e(substr($icono, 0, 1)) ?></span><?= e($label) ?>
    </a>
   <?php endforeach; ?>
  </nav>
  <div class="side-foot"><a href="<?= e(url('index.php')) ?>">Visitá el sitio público</a></div>
 </aside>
 <div class="main-wrap">
  <header class="topbar">
   <button class="menu-btn" id="menuBtn" aria-label="Abrir menú">☰</button>
   <div class="top-title"><?= e($titulo ?: APP_NAME) ?></div>
   <div class="top-right">
    <?php if ($u): ?>
     <a class="bell" href="<?= e(url('notificaciones/listado.php')) ?>" title="Notificaciones">🔔<?php if ($sinLeer): ?><span class="badge-dot"><?= $sinLeer ?></span><?php endif; ?></a>
     <a class="user-chip" href="<?= e(url('perfil.php')) ?>">
       <span class="avatar"><?= e(mb_substr($u['nombre'], 0, 1)) ?></span>
       <span class="user-data"><b><?= e($u['nombre'] . ' ' . $u['apellido']) ?></b><small><?= e($u['rol_nombre']) ?></small></span>
     </a>
     <a class="logout" href="<?= e(url('logout.php')) ?>" title="Cerrar sesión">⇥</a>
    <?php endif; ?>
   </div>
  </header>
  <main class="content">
   <?php foreach (flash_get() as $f): ?>
    <div class="alert <?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></div>
   <?php endforeach;
}

function layout_footer(): void {
    ?>
  </main>
 </div>
</div>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
<?php
}
