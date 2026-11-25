<?php
/** Página institucional pública (accesible sin iniciar sesión). */
require_once __DIR__ . '/config/app.php';
global $pdo;
$noticias = $pdo->query("SELECT id,titulo,resumen,fecha FROM noticias WHERE publicada=1 ORDER BY fecha DESC LIMIT 6")->fetchAll();
$autoridades = $pdo->query("SELECT nombre,apellido,rol_id FROM usuarios WHERE activo=1 AND rol_id IN ('DIRECTIVO','PRECEPTOR','ADMIN') ORDER BY rol_id LIMIT 8")->fetchAll();
$escuela = cfg('escuela_nombre', 'E.E.T. N° 35 D.E. 18 "Ing. Eduardo Latzina"');
$direccion = cfg('escuela_direccion', 'Av. Lope de Vega 2150, CABA');
$telefono = cfg('escuela_telefono', '4567-5838 / 1441');
$email = cfg('escuela_email', 'det_35_de18@bue.edu.ar');
$fmt = fn($f) => date('d/m/Y', strtotime($f));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Escuela Técnica N° 35 D.E. 18 Ing. Eduardo Latzina. Informes de convivencia, institución, noticias y contacto.">
<meta name="theme-color" content="#9c1424">
<title><?= e($escuela) ?> · Sistema de Informes</title>
<link rel="stylesheet" href="<?= e(asset_url('css/styles.css')) ?>">
</head>
<body class="pub">
<nav class="pubnav">
 <a href="#inicio">Inicio</a><a href="#institucion">Institución</a>
 <a href="#noticias">Noticias y novedades</a><a href="#contacto">Contacto</a>
 <a href="<?= e(url('login.php')) ?>" style="background:var(--azul);color:#fff">Acceso al sistema</a>
</nav>

<header class="hero" id="inicio">
 <img src="<?= e(asset_url('img/escudo.png')) ?>" alt="Escudo de la escuela" style="width:90px;background:#fff;border-radius:8px;padding:6px">
 <h1><?= e($escuela) ?></h1>
 <p>Educación técnica de calidad en la Ciudad de Buenos Aires. Formamos estudiantes con compromiso, responsabilidad y respeto por la convivencia.</p>
 <div class="btn-row" style="justify-content:center">
  <a class="btn" href="<?= e(url('login.php')) ?>">Ingresar al sistema de gestión</a>
  <a class="btn g" href="#noticias">Ver novedades</a>
 </div>
</header>

<div class="wrap">
 <section>
  <span class="section-tag">Bienvenidos</span>
  <h2>Inicio</h2>
  <div class="cards">
   <div class="card"><h3>Nuestra misión</h3><p>Brindar una formación técnica integral que prepare a nuestros estudiantes para el mundo laboral y la ciudadanía responsable.</p></div>
   <div class="card"><h3>Convivencia</h3><p>Promovemos una cultura institucional basada en el respeto, el diálogo y la resolución pacífica de los conflictos.</p></div>
   <div class="card"><h3>Accesos rápidos</h3><p><a href="#institucion">Institución</a> · <a href="#noticias">Noticias</a><br><a href="#contacto">Contacto</a></p></div>
  </div>
 </section>

 <section id="institucion">
  <span class="section-tag">Quiénes somos</span>
  <h2>Institución</h2>
  <div class="grid g2">
   <div class="card">
    <h3>Historia</h3>
    <p>La Escuela Técnica N° 35 D.E. 18 "Ing. Eduardo Latzina" forma parte del sistema educativo de la Ciudad Autónoma de Buenos Aires, con una trayectoria dedicada a la enseñanza técnica y la formación de personas íntegras.</p>
    <h3>Misión y visión</h3>
    <p><b>Misión:</b> garantizar una educación técnica de calidad, con inclusión y equidad, que desarrolle competencias profesionales y valores ciudadanos.</p>
    <p><b>Visión:</b> ser una institución referente por su compromiso con la excelencia educativa, la innovación y la convivencia escolar.</p>
   </div>
   <div class="card">
    <h3>Objetivos institucionales</h3>
    <ul>
     <li>Fortalecer el aprendizaje de conocimientos técnicos y generales.</li>
     <li>Promover la participación y el sentido de pertenencia.</li>
     <li>Garantizar la convivencia escolar democrática y el respeto de los derechos.</li>
     <li>Articular con las familias y la comunidad.</li>
    </ul>
    <h3>Autoridades</h3>
    <table><thead><tr><th>Cargo</th><th>Responsable</th></tr></thead><tbody>
    <?php foreach ($autoridades as $a): ?>
     <tr><td><?= e($a['rol_id'] === 'DIRECTIVO' ? 'Dirección/Regencia' : ($a['rol_id'] === 'PRECEPTOR' ? 'Precepturía' : 'Administración')) ?></td>
         <td><?= e($a['nombre'] . ' ' . $a['apellido']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
   </div>
  </div>
 </section>

 <section id="noticias">
  <span class="section-tag">Actualidad</span>
  <h2>Noticias y novedades</h2>
  <div class="cards">
   <?php if (!$noticias): ?><div class="card"><p class="muted">Todavía no hay publicaciones.</p></div><?php endif; ?>
   <?php foreach ($noticias as $n): ?>
    <article class="news">
     <div class="fecha"><?= e($fmt($n['fecha'])) ?></div>
     <h3><?= e($n['titulo']) ?></h3>
     <p><?= e($n['resumen']) ?></p>
    </article>
   <?php endforeach; ?>
  </div>
 </section>

 <section id="contacto">
  <span class="section-tag">Estamos cerca</span>
  <h2>Contacto</h2>
  <div class="contacto">
   <div class="card">
    <h3>Datos de contacto</h3>
    <p><b>Dirección:</b> <?= e($direccion) ?><br>
       <b>Teléfono:</b> <?= e($telefono) ?><br>
       <b>Correo:</b> <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></p>
    <p class="muted">Horario de atención: lunes a viernes de 8:00 a 16:00 hs.</p>
    <a class="btn" href="<?= e(url('login.php')) ?>">Acceder al sistema de informes</a>
   </div>
   <div class="card" style="padding:0;overflow:hidden">
    <iframe class="mapa" title="Ubicación de la escuela" loading="lazy"
      src="https://www.openstreetmap.org/export/embed.html?bbox=-58.4840%2C-34.6130%2C-58.4640%2C-34.5960&layer=mapnik&marker=-34.6045%2C-58.4740"></iframe>
   </div>
  </div>
 </section>
</div>
<footer>
 <p><?= e($escuela) ?> · <?= e($direccion) ?> · Tel. <?= e($telefono) ?></p>
 <p><a href="<?= e(url('login.php')) ?>">Acceso al sistema</a> · <a href="#inicio">Volver arriba</a></p>
</footer>
</body>
</html>
