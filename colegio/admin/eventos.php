<?php
/** Administración de eventos del calendario público. */
require_once __DIR__ . '/../config/app.php';
require_role('ADMIN');
global $pdo;

$tiposEvento = ['academico', 'examen', 'acto', 'reunion', 'actividad'];

/** Convierte el valor de un input datetime-local a DATETIME de MySQL. */
function evento_fecha(string $valor): ?string {
    $valor = trim($valor);
    if ($valor === '') return null;
    $f = DateTime::createFromFormat('!Y-m-d\TH:i', $valor);
    if (!$f || $f->format('Y-m-d\TH:i') !== $valor) return null;
    return $f->format('Y-m-d H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT titulo FROM eventos WHERE id=?');
        $st->execute([$id]);
        $titulo = $st->fetchColumn();
        if ($titulo) {
            $pdo->prepare('DELETE FROM eventos WHERE id=?')->execute([$id]);
            audit('ELIMINAR_EVENTO', 'eventos', (string)$id, 'Evento: ' . $titulo);
            flash('ok', 'Evento eliminado.');
        } else {
            flash('err', 'El evento no existe.');
        }
        redirect('admin/eventos.php');
    }
    if ($accion !== 'guardar') denegar_acceso('Acción no permitida.');

    $id = (int)($_POST['id'] ?? 0);
    $titulo = texto($_POST['titulo'] ?? '', 3, 180);
    $descripcion = texto($_POST['descripcion'] ?? '', 0, 5000) ?: null;
    $inicio = evento_fecha((string)($_POST['fecha_inicio'] ?? ''));
    $fin = evento_fecha((string)($_POST['fecha_fin'] ?? ''));
    $tipo = (string)($_POST['tipo'] ?? 'actividad');
    $visible = isset($_POST['visible']) ? 1 : 0;

    if (!$titulo || !$inicio) {
        flash('err', 'Título y fecha de inicio son obligatorios.');
        redirect('admin/eventos.php' . ($id ? '?editar=' . $id : '?form=nuevo'));
    }
    if (!in_array($tipo, $tiposEvento, true)) {
        flash('err', 'El tipo de evento no es válido.');
        redirect('admin/eventos.php' . ($id ? '?editar=' . $id : '?form=nuevo'));
    }
    if ($fin !== null && strtotime($fin) < strtotime($inicio)) {
        flash('err', 'La fecha de fin no puede ser anterior a la de inicio.');
        redirect('admin/eventos.php' . ($id ? '?editar=' . $id : '?form=nuevo'));
    }

    if ($id) {
        $st = $pdo->prepare('SELECT id FROM eventos WHERE id=?');
        $st->execute([$id]);
        if (!$st->fetch()) { flash('err', 'El evento no existe.'); redirect('admin/eventos.php'); }
        $pdo->prepare('UPDATE eventos SET titulo=?, descripcion=?, fecha_inicio=?, fecha_fin=?, tipo=?, visible=? WHERE id=?')
            ->execute([$titulo, $descripcion, $inicio, $fin, $tipo, $visible, $id]);
        audit('EDITAR_EVENTO', 'eventos', (string)$id, $titulo);
        flash('ok', 'Evento actualizado.');
    } else {
        $pdo->prepare('INSERT INTO eventos (titulo,descripcion,fecha_inicio,fecha_fin,tipo,visible) VALUES (?,?,?,?,?,?)')
            ->execute([$titulo, $descripcion, $inicio, $fin, $tipo, $visible]);
        $id = (int)$pdo->lastInsertId();
        audit('CREAR_EVENTO', 'eventos', (string)$id, $titulo);
        flash('ok', 'Evento creado.');
    }
    redirect('admin/eventos.php?editar=' . $id);
}

$st = $pdo->prepare('SELECT * FROM eventos ORDER BY fecha_inicio DESC LIMIT 300');
$st->execute();
$eventos = $st->fetchAll();

$editarId = (int)($_GET['editar'] ?? 0);
$editar = null;
if ($editarId) {
    $st = $pdo->prepare('SELECT * FROM eventos WHERE id=?');
    $st->execute([$editarId]);
    $editar = $st->fetch() ?: null;
}

/** Formatea un DATETIME de MySQL para un input datetime-local. */
function evento_para_input($valor): string {
    return $valor ? date('Y-m-d\TH:i', strtotime($valor)) : '';
}

layout_header('Calendario de eventos');
?>
<h1>Eventos</h1>
<p class="muted"><a href="<?= e(url('index.php')) ?>#noticias" target="_blank" rel="noopener">Ver sitio público</a></p>

<?php if (($_GET['form'] ?? '') === 'nuevo' || $editar): ?>
<div class="card">
 <h2><?= $editar ? 'Editar evento' : 'Nuevo evento' ?></h2>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= $editar ? (int)$editar['id'] : 0 ?>">
  <label for="titulo">Título</label><input id="titulo" name="titulo" class="input" required maxlength="180" value="<?= e($editar['titulo'] ?? '') ?>">
  <label for="descripcion">Descripción</label><textarea id="descripcion" name="descripcion" class="textarea" rows="4"><?= e($editar['descripcion'] ?? '') ?></textarea>
  <div class="grid g2">
   <div><label for="fecha_inicio">Fecha y hora de inicio</label><input id="fecha_inicio" name="fecha_inicio" type="datetime-local" class="input" required value="<?= e(evento_para_input($editar['fecha_inicio'] ?? null)) ?>"></div>
   <div><label for="fecha_fin">Fecha y hora de fin (opcional)</label><input id="fecha_fin" name="fecha_fin" type="datetime-local" class="input" value="<?= e(evento_para_input($editar['fecha_fin'] ?? null)) ?>"></div>
   <div><label for="tipo">Tipo</label><select id="tipo" name="tipo" class="select">
     <?php foreach ($tiposEvento as $t): ?><option value="<?= e($t) ?>" <?= ($editar['tipo'] ?? 'actividad') === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option><?php endforeach; ?>
    </select></div>
   <div><label><input type="checkbox" name="visible" value="1" <?= !$editar || $editar['visible'] ? 'checked' : '' ?>> Visible en el calendario</label></div>
  </div>
  <p><button class="btn">Guardar evento</button> <a class="btn g" href="<?= e(url('admin/eventos.php')) ?>">Cancelar</a></p>
 </form>
</div>
<?php else: ?>
<p><a class="btn" href="<?= e(url('admin/eventos.php?form=nuevo')) ?>">Nuevo evento</a></p>
<?php endif; ?>

<div class="card">
 <h2>Eventos registrados</h2>
 <div class="table-wrap"><table>
  <thead><tr><th>Evento</th><th>Inicio</th><th>Fin</th><th>Tipo</th><th>Visible</th><th>Acciones</th></tr></thead><tbody>
  <?php if (!$eventos): ?><tr><td colspan="6" class="muted">No hay eventos registrados.</td></tr><?php endif; ?>
  <?php foreach ($eventos as $ev): ?>
   <tr>
    <td><?= e($ev['titulo']) ?><br><small><?= e(mb_substr((string)$ev['descripcion'], 0, 90)) ?></small></td>
    <td><?= e($ev['fecha_inicio']) ?></td>
    <td><?= e($ev['fecha_fin'] ?: '—') ?></td>
    <td><?= e($ev['tipo']) ?></td>
    <td><span class="pill <?= $ev['visible'] ? 'ok' : 'gray' ?>"><?= $ev['visible'] ? 'Sí' : 'No' ?></span></td>
    <td><div class="btn-row">
     <a class="btn s" href="<?= e(url('admin/eventos.php?editar=' . $ev['id'])) ?>">Editar</a>
     <form method="post" style="display:inline" data-confirm="¿Eliminar este evento?"><?= csrf_field() ?>
      <input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
      <button class="btn s d">Eliminar</button></form>
    </div></td>
   </tr>
  <?php endforeach; ?>
  </tbody>
 </table></div>
</div>
<?php layout_footer(); ?>
