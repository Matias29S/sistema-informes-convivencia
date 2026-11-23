<?php
/** Historial de modificaciones con filtros y paginación (50 por página). */
require_once __DIR__ . '/../config/app.php';
require_role('ADMIN');
global $pdo;

/** Valida una fecha YYYY-MM-DD para los filtros. */
function fecha_filtro_valida($valor): bool {
    if (!is_string($valor) || $valor === '') return false;
    $f = DateTime::createFromFormat('!Y-m-d', $valor);
    return $f && $f->format('Y-m-d') === $valor;
}

$accion = trim((string)($_GET['accion'] ?? ''));
$usuarioId = (int)($_GET['usuario_id'] ?? 0);
$desde = (string)($_GET['desde'] ?? '');
$hasta = (string)($_GET['hasta'] ?? '');
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = 50;

$where = [];
$params = [];
if ($accion !== '') {
    $where[] = 'h.accion LIKE ?';
    $params[] = '%' . $accion . '%';
}
if ($usuarioId) {
    $st = $pdo->prepare('SELECT id FROM usuarios WHERE id=?');
    $st->execute([$usuarioId]);
    if ($st->fetch()) {
        $where[] = 'h.usuario_id=?';
        $params[] = $usuarioId;
    }
}
if (fecha_filtro_valida($desde)) {
    $where[] = 'DATE(h.fecha) >= ?';
    $params[] = $desde;
}
if (fecha_filtro_valida($hasta)) {
    $where[] = 'DATE(h.fecha) <= ?';
    $params[] = $hasta;
}
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare('SELECT COUNT(*) FROM historial_modificaciones h' . $sqlWhere);
$st->execute($params);
$total = (int)$st->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPagina));
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * $porPagina;

$st = $pdo->prepare('SELECT h.*, u.nombre, u.apellido, u.username
                     FROM historial_modificaciones h
                     LEFT JOIN usuarios u ON u.id=h.usuario_id' . $sqlWhere
                     . ' ORDER BY h.fecha DESC, h.id DESC LIMIT ? OFFSET ?');
$indice = 1;
foreach ($params as $valor) $st->bindValue($indice++, $valor);
$st->bindValue($indice++, $porPagina, PDO::PARAM_INT);
$st->bindValue($indice, $offset, PDO::PARAM_INT);
$st->execute();
$registros = $st->fetchAll();

$st = $pdo->prepare('SELECT id, nombre, apellido, username FROM usuarios ORDER BY apellido, nombre LIMIT 500');
$st->execute();
$usuarios = $st->fetchAll();

$filtrosUrl = array_filter([
    'accion' => $accion,
    'usuario_id' => $usuarioId ?: null,
    'desde' => fecha_filtro_valida($desde) ? $desde : null,
    'hasta' => fecha_filtro_valida($hasta) ? $hasta : null,
], function ($v) { return $v !== '' && $v !== null; });
$baseUrl = 'admin/auditoria.php' . ($filtrosUrl ? '?' . http_build_query($filtrosUrl) . '&' : '?');

layout_header('Auditoría');
?>
<h1>Auditoría</h1>
<p class="muted">Registro de acciones administrativas y operaciones sensibles. <?= (int)$total ?> registro(s) en total.</p>
<div class="card">
 <h2>Filtros</h2>
 <form class="toolbar" method="get">
  <label for="accion">Acción</label><input id="accion" name="accion" class="input" value="<?= e($accion) ?>" placeholder="Ej. CREAR_CUENTA">
  <label for="usuario_id">Usuario</label>
  <select id="usuario_id" name="usuario_id" class="select">
   <option value="">Todos</option>
   <?php foreach ($usuarios as $u): ?>
    <option value="<?= (int)$u['id'] ?>" <?= $usuarioId === (int)$u['id'] ? 'selected' : '' ?>><?= e(trim($u['apellido'] . ' ' . $u['nombre']) . ' (' . $u['username'] . ')') ?></option>
   <?php endforeach; ?>
  </select>
  <label for="desde">Desde</label><input id="desde" name="desde" type="date" class="input" value="<?= e(fecha_filtro_valida($desde) ? $desde : '') ?>">
  <label for="hasta">Hasta</label><input id="hasta" name="hasta" type="date" class="input" value="<?= e(fecha_filtro_valida($hasta) ? $hasta : '') ?>">
  <button class="btn s">Filtrar</button>
  <a class="btn g" href="<?= e(url('admin/auditoria.php')) ?>">Limpiar</a>
 </form>
 <p><input class="input" type="search" data-filter="#tabla-auditoria" placeholder="Filtrar tabla en pantalla…" aria-label="Filtrar tabla"></p>
 <div class="table-wrap"><table id="tabla-auditoria">
  <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Tabla</th><th>Registro</th><th>Detalle</th><th>IP</th></tr></thead>
  <tbody>
  <?php if (!$registros): ?><tr><td colspan="7" class="muted">No hay registros con esos filtros.</td></tr><?php endif; ?>
  <?php foreach ($registros as $r): ?>
   <tr>
    <td><?= e($r['fecha']) ?></td>
    <td><?= e(trim(($r['nombre'] ?? '') . ' ' . ($r['apellido'] ?? '')) ?: ($r['username'] ?? 'Sistema')) ?></td>
    <td><span class="pill info"><?= e($r['accion']) ?></span></td>
    <td><?= e($r['tabla_afectada'] ?: '—') ?></td>
    <td><?= e($r['registro_id'] ?: '—') ?></td>
    <td><?= e(mb_substr((string)$r['detalle'], 0, 200)) ?></td>
    <td><?= e($r['ip'] ?: '—') ?></td>
   </tr>
  <?php endforeach; ?>
  </tbody>
 </table></div>
 <div class="btn-row">
  <?php if ($pagina > 1): ?><a class="btn s" href="<?= e(url($baseUrl . 'pagina=' . ($pagina - 1))) ?>">← Anterior</a><?php endif; ?>
  <span class="muted">Página <?= (int)$pagina ?> de <?= (int)$paginas ?></span>
  <?php if ($pagina < $paginas): ?><a class="btn s" href="<?= e(url($baseUrl . 'pagina=' . ($pagina + 1))) ?>">Siguiente →</a><?php endif; ?>
 </div>
</div>
<?php layout_footer(); ?>
