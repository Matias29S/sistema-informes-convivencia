<?php
/** Matriz de permisos por rol. ADMIN conserva siempre todos los permisos. */
require_once __DIR__ . '/../config/app.php';
require_role('ADMIN');
global $pdo;

$st = $pdo->prepare('SELECT id, nombre FROM roles ORDER BY id');
$st->execute();
$roles = $st->fetchAll();

$st = $pdo->prepare('SELECT id, modulo, descripcion FROM permisos ORDER BY modulo, id');
$st->execute();
$permisos = $st->fetchAll();

// Mantener la matriz consistente: ADMIN tiene todos los permisos también en la tabla.
$st = $pdo->prepare('SELECT p.id FROM permisos p WHERE NOT EXISTS
    (SELECT 1 FROM rol_permiso rp WHERE rp.rol_id=? AND rp.permiso_id=p.id)');
$st->execute(['ADMIN']);
$faltanAdmin = $st->fetchAll(PDO::FETCH_COLUMN);
if ($faltanAdmin) {
    $ins = $pdo->prepare('INSERT INTO rol_permiso (rol_id,permiso_id) VALUES (?,?)');
    foreach ($faltanAdmin as $pid) $ins->execute(['ADMIN', $pid]);
    audit('SINCRONIZAR_PERMISOS_ADMIN', 'rol_permiso', 'ADMIN', 'Permisos agregados: ' . implode(', ', $faltanAdmin));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $rol = (string)($_POST['rol_id'] ?? '');
    $permiso = (string)($_POST['permiso_id'] ?? '');
    $quitar = (int)($_POST['quitar'] ?? 0) === 1;

    $st = $pdo->prepare('SELECT id FROM roles WHERE id=?');
    $st->execute([$rol]);
    if (!$st->fetch()) { flash('err', 'El rol no existe.'); redirect('admin/permisos.php'); }
    $st = $pdo->prepare('SELECT id, descripcion FROM permisos WHERE id=?');
    $st->execute([$permiso]);
    $permisoRow = $st->fetch();
    if (!$permisoRow) { flash('err', 'El permiso no existe.'); redirect('admin/permisos.php'); }
    if ($rol === 'ADMIN') {
        flash('err', 'El rol ADMIN siempre conserva todos los permisos.');
        redirect('admin/permisos.php');
    }

    $st = $pdo->prepare('SELECT 1 FROM rol_permiso WHERE rol_id=? AND permiso_id=?');
    $st->execute([$rol, $permiso]);
    $existe = (bool)$st->fetch();
    if ($quitar && $existe) {
        $pdo->prepare('DELETE FROM rol_permiso WHERE rol_id=? AND permiso_id=?')->execute([$rol, $permiso]);
        audit('QUITAR_PERMISO', 'rol_permiso', $rol, 'Permiso quitado: ' . $permiso);
        flash('ok', 'Permiso quitado al rol ' . $rol . '.');
    } elseif (!$quitar && !$existe) {
        $pdo->prepare('INSERT INTO rol_permiso (rol_id,permiso_id) VALUES (?,?)')->execute([$rol, $permiso]);
        audit('OTORGAR_PERMISO', 'rol_permiso', $rol, 'Permiso otorgado: ' . $permiso);
        flash('ok', 'Permiso otorgado al rol ' . $rol . '.');
    } else {
        flash('info', 'El permiso ya tenía el valor solicitado.');
    }
    redirect('admin/permisos.php');
}

$asignados = [];
$st = $pdo->prepare('SELECT rol_id, permiso_id FROM rol_permiso');
$st->execute();
foreach ($st->fetchAll() as $rp) $asignados[$rp['rol_id']][$rp['permiso_id']] = true;

layout_header('Permisos por rol');
?>
<h1>Permisos por rol</h1>
<p class="muted">Los cambios se aplican de inmediato. El rol <b>ADMIN</b> siempre conserva todos los permisos.</p>
<div class="card">
 <div class="table-wrap"><table>
  <thead><tr><th>Módulo</th><th>Permiso</th>
   <?php foreach ($roles as $r): ?><th style="text-align:center"><?= e($r['nombre']) ?><br><small><?= e($r['id']) ?></small></th><?php endforeach; ?>
  </tr></thead>
  <tbody>
  <?php $moduloActual = ''; ?>
  <?php foreach ($permisos as $p): ?>
   <?php if ($p['modulo'] !== $moduloActual): $moduloActual = $p['modulo']; ?>
    <tr><td colspan="<?= 2 + count($roles) ?>"><b><?= e($moduloActual) ?></b></td></tr>
   <?php endif; ?>
   <tr>
    <td class="muted"><?= e($p['modulo']) ?></td>
    <td><?= e($p['descripcion']) ?><br><small><?= e($p['id']) ?></small></td>
    <?php foreach ($roles as $r): ?>
     <?php $tiene = $r['id'] === 'ADMIN' || !empty($asignados[$r['id']][$p['id']]); ?>
     <td style="text-align:center">
      <?php if ($r['id'] === 'ADMIN'): ?>
       <span class="pill ok">Siempre</span>
      <?php else: ?>
       <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="rol_id" value="<?= e($r['id']) ?>">
        <input type="hidden" name="permiso_id" value="<?= e($p['id']) ?>">
        <input type="hidden" name="quitar" value="<?= $tiene ? '1' : '0' ?>">
        <button class="btn s <?= $tiene ? 'ok' : '' ?>" title="<?= $tiene ? 'Quitar permiso' : 'Otorgar permiso' ?>"><?= $tiene ? '✓ Sí' : '— No' ?></button>
       </form>
      <?php endif; ?>
     </td>
    <?php endforeach; ?>
   </tr>
  <?php endforeach; ?>
  </tbody>
 </table></div>
</div>
<?php layout_footer(); ?>
