<?php
/** Bandeja de notificaciones: sólo las notificaciones del usuario autenticado. */
require_once __DIR__ . '/../config/app.php';
require_login();
global $pdo;

$u = current_user();
$uid = (int)$u['id'];

// ---------- Filtros ----------
$filtro = (string)($_GET['f'] ?? 'todas');
if ($filtro !== 'unread') $filtro = 'todas';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

/** Devuelve una URL interna segura o '' si el valor no es una ruta relativa válida. */
function url_notificacion_segura($enlace): string {
    $enlace = trim((string)$enlace);
    if ($enlace === '') return '';
    // Rechaza esquemas (javascript:, http:, data:...), rutas //dominio y barras invertidas.
    if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $enlace) || str_starts_with($enlace, '//') || str_contains($enlace, '\\') || str_contains($enlace, '..')) return '';
    if (!preg_match('~^[A-Za-z0-9_./?=&%#:-]+$~', $enlace)) return '';
    return url($enlace);
}

// ---------- Acción POST: marcar todas como leídas ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['accion'] ?? '') === 'todas') {
        $pdo->prepare('UPDATE notificaciones SET leida=1 WHERE usuario_id=? AND leida=0')->execute([$uid]);
        flash('ok', 'Todas tus notificaciones fueron marcadas como leídas.');
    } else {
        flash('err', 'Acción desconocida.');
    }
    $query = [];
    if ($filtro === 'unread') $query['f'] = 'unread';
    if ($q !== '') $query['q'] = $q;
    redirect('notificaciones/listado.php' . ($query ? '?' . http_build_query($query) : ''));
}

// ---------- Consulta (siempre restringida al usuario actual) ----------
$where  = ['usuario_id=?'];
$params = [$uid];
if ($filtro === 'unread') $where[] = 'leida=0';
if ($q !== '') {
    $where[] = '(titulo LIKE ? OR descripcion LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$st = $pdo->prepare(
    'SELECT id, titulo, descripcion, tipo, enlace, leida, fecha
     FROM notificaciones
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY leida ASC, fecha DESC
     LIMIT 300'
);
$st->execute($params);
$notificaciones = $st->fetchAll();

$st = $pdo->prepare('SELECT COUNT(*) FROM notificaciones WHERE usuario_id=? AND leida=0');
$st->execute([$uid]);
$sinLeer = (int)$st->fetchColumn();

$base = 'notificaciones/listado.php';
$urlTodas = e(url($base));
$urlUnread = e(url($base . '?f=unread'));

layout_header('Notificaciones');
?>
<h1>Mis notificaciones</h1>
<p class="muted">
  <?= count($notificaciones) ?> resultado(s) · <?= $sinLeer ?> sin leer.
  Sólo se muestran las notificaciones dirigidas a tu cuenta.
</p>

<div class="btn-row">
  <a class="btn <?= $filtro === 'todas' ? '' : 'g' ?>" href="<?= $urlTodas ?>">Todas</a>
  <a class="btn <?= $filtro === 'unread' ? '' : 'g' ?>" href="<?= $urlUnread ?>">No leídas<?= $sinLeer ? ' (' . $sinLeer . ')' : '' ?></a>
</div>

<form class="toolbar" method="get" action="<?= e(url($base)) ?>">
  <?php if ($filtro === 'unread'): ?><input type="hidden" name="f" value="unread"><?php endif; ?>
  <label>Buscar<input type="search" name="q" value="<?= e($q) ?>" placeholder="Título o descripción"></label>
  <button class="btn" type="submit">Buscar</button>
  <a class="btn g" href="<?= e(url($base . ($filtro === 'unread' ? '?f=unread' : ''))) ?>">Limpiar</a>
</form>

<?php if ($sinLeer > 0): ?>
<form method="post" action="<?= e($_SERVER['REQUEST_URI'] ?? url($base)) ?>" style="margin-bottom:1rem">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="todas">
  <button class="btn" type="submit" data-confirm="¿Marcar todas tus notificaciones como leídas?">Marcar todas como leídas</button>
</form>
<?php endif; ?>

<?php if (!$notificaciones): ?>
  <div class="card"><p class="muted">No hay notificaciones que coincidan con los filtros.</p></div>
<?php else: ?>
  <?php foreach ($notificaciones as $n): ?>
    <?php
      $idNotif = (int)$n['id'];
      $destino = url_notificacion_segura($n['enlace']);
      $leida = (int)$n['leida'] === 1;
    ?>
    <div class="card" style="margin-bottom:.75rem">
      <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;align-items:start">
        <h3 style="margin:0">
          <?php if ($destino !== ''): ?>
            <a href="<?= e($destino) ?>" data-notif-id="<?= $idNotif ?>"><?= e($n['titulo']) ?></a>
          <?php else: ?>
            <?= e($n['titulo']) ?>
          <?php endif; ?>
        </h3>
        <span class="pill <?= $leida ? 'gray' : 'warn' ?>"><?= $leida ? 'Leída' : 'No leída' ?></span>
      </div>
      <p class="muted" style="margin:.45rem 0">
        <span class="pill info"><?= e($n['tipo']) ?></span>
        · <?= e($n['fecha']) ?>
      </p>
      <p><?= e($n['descripcion']) ?></p>
      <div class="btn-row" style="margin-top:.6rem">
        <?php if ($destino !== ''): ?>
          <a class="btn s" href="<?= e($destino) ?>" data-notif-id="<?= $idNotif ?>">Abrir destino</a>
        <?php endif; ?>
        <?php if (!$leida): ?>
          <form method="post" action="<?= e(url('notificaciones/marcar_leida.php')) ?>" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $idNotif ?>">
            <button class="btn s" type="submit">Marcar como leída</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php layout_footer(); ?>
