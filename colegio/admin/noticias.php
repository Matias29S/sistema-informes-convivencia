<?php
/** Administración de noticias del sitio público. */
require_once __DIR__ . '/../config/app.php';
require_role('ADMIN');
global $pdo;

$autorId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT titulo FROM noticias WHERE id=?');
        $st->execute([$id]);
        $titulo = $st->fetchColumn();
        if ($titulo) {
            $pdo->prepare('DELETE FROM noticias WHERE id=?')->execute([$id]);
            audit('ELIMINAR_NOTICIA', 'noticias', (string)$id, 'Noticia: ' . $titulo);
            flash('ok', 'Noticia eliminada.');
        } else {
            flash('err', 'La noticia no existe.');
        }
        redirect('admin/noticias.php');
    }

    if ($accion !== 'guardar') denegar_acceso('Acción no permitida.');
    $id = (int)($_POST['id'] ?? 0);
    $titulo = texto($_POST['titulo'] ?? '', 3, 180);
    $resumen = texto($_POST['resumen'] ?? '', 3, 1000);
    $contenido = texto($_POST['contenido'] ?? '', 3, 20000);
    $imagenRaw = trim((string)($_POST['imagen'] ?? ''));
    $publicada = isset($_POST['publicada']) ? 1 : 0;

    if (!$titulo || !$resumen || !$contenido) {
        flash('err', 'Título, resumen y contenido son obligatorios.');
        redirect('admin/noticias.php' . ($id ? '?editar=' . $id : '?form=nuevo'));
    }
    if ($imagenRaw !== '' && !filter_var($imagenRaw, FILTER_VALIDATE_URL)) {
        flash('err', 'La imagen debe ser una URL válida (http:// o https://) o quedar vacía.');
        redirect('admin/noticias.php' . ($id ? '?editar=' . $id : '?form=nuevo'));
    }
    $imagen = $imagenRaw !== '' ? mb_substr($imagenRaw, 0, 220) : null;

    if ($id) {
        $st = $pdo->prepare('SELECT id FROM noticias WHERE id=?');
        $st->execute([$id]);
        if (!$st->fetch()) { flash('err', 'La noticia no existe.'); redirect('admin/noticias.php'); }
        $pdo->prepare('UPDATE noticias SET titulo=?, resumen=?, contenido=?, imagen=?, publicada=? WHERE id=?')
            ->execute([$titulo, $resumen, $contenido, $imagen, $publicada, $id]);
        audit('EDITAR_NOTICIA', 'noticias', (string)$id, $titulo);
        flash('ok', 'Noticia actualizada.');
    } else {
        $pdo->prepare('INSERT INTO noticias (titulo,resumen,contenido,imagen,publicada,autor_id) VALUES (?,?,?,?,?,?)')
            ->execute([$titulo, $resumen, $contenido, $imagen, $publicada, $autorId]);
        $id = (int)$pdo->lastInsertId();
        audit('CREAR_NOTICIA', 'noticias', (string)$id, $titulo);
        flash('ok', 'Noticia publicada.');
    }
    redirect('admin/noticias.php?editar=' . $id);
}

$st = $pdo->prepare('SELECT n.*, u.nombre AS autor_nombre, u.apellido AS autor_apellido
                     FROM noticias n LEFT JOIN usuarios u ON u.id=n.autor_id
                     ORDER BY n.fecha DESC LIMIT 200');
$st->execute();
$noticias = $st->fetchAll();

$editarId = (int)($_GET['editar'] ?? 0);
$editar = null;
if ($editarId) {
    $st = $pdo->prepare('SELECT * FROM noticias WHERE id=?');
    $st->execute([$editarId]);
    $editar = $st->fetch() ?: null;
}

layout_header('Noticias');
?>
<h1>Noticias</h1>
<p class="muted"><a href="<?= e(url('index.php')) ?>#noticias" target="_blank" rel="noopener">Ver vista pública (#noticias)</a></p>

<?php if (($_GET['form'] ?? '') === 'nuevo' || $editar): ?>
<div class="card">
 <h2><?= $editar ? 'Editar noticia' : 'Nueva noticia' ?></h2>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= $editar ? (int)$editar['id'] : 0 ?>">
  <label for="titulo">Título</label><input id="titulo" name="titulo" class="input" required maxlength="180" value="<?= e($editar['titulo'] ?? '') ?>">
  <label for="resumen">Resumen</label><textarea id="resumen" name="resumen" class="textarea" rows="2" required><?= e($editar['resumen'] ?? '') ?></textarea>
  <label for="contenido">Contenido</label><textarea id="contenido" name="contenido" class="textarea" rows="8" required><?= e($editar['contenido'] ?? '') ?></textarea>
  <label for="imagen">Imagen (URL opcional)</label><input id="imagen" name="imagen" class="input" type="url" maxlength="220" value="<?= e($editar['imagen'] ?? '') ?>">
  <label><input type="checkbox" name="publicada" value="1" <?= !$editar || $editar['publicada'] ? 'checked' : '' ?>> Publicada</label>
  <p><button class="btn">Guardar noticia</button> <a class="btn g" href="<?= e(url('admin/noticias.php')) ?>">Cancelar</a></p>
 </form>
</div>
<?php else: ?>
<p><a class="btn" href="<?= e(url('admin/noticias.php?form=nuevo')) ?>">Nueva noticia</a></p>
<?php endif; ?>

<div class="card">
 <h2>Noticias registradas</h2>
 <div class="table-wrap"><table>
  <thead><tr><th>Título</th><th>Fecha</th><th>Autor</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
  <?php if (!$noticias): ?><tr><td colspan="5" class="muted">No hay noticias registradas.</td></tr><?php endif; ?>
  <?php foreach ($noticias as $n): ?>
   <tr>
    <td><?= e($n['titulo']) ?><br><small><?= e(mb_substr($n['resumen'], 0, 90)) ?></small></td>
    <td><?= e($n['fecha']) ?></td>
    <td><?= e(trim(($n['autor_nombre'] ?? '') . ' ' . ($n['autor_apellido'] ?? '')) ?: '—') ?></td>
    <td><span class="pill <?= $n['publicada'] ? 'ok' : 'gray' ?>"><?= $n['publicada'] ? 'Publicada' : 'Borrador' ?></span></td>
    <td><div class="btn-row">
     <a class="btn s" href="<?= e(url('admin/noticias.php?editar=' . $n['id'])) ?>">Editar</a>
     <form method="post" style="display:inline" data-confirm="¿Eliminar esta noticia?"><?= csrf_field() ?>
      <input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
      <button class="btn s d">Eliminar</button></form>
    </div></td>
   </tr>
  <?php endforeach; ?>
  </tbody>
 </table></div>
</div>
<?php layout_footer(); ?>
