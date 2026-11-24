<?php
/** Historial de estados, auditoría del informe y usuarios intervinientes. */
require_once __DIR__ . '/../config/app.php';
require_login();
global $pdo;

$u = current_user();
$rol = $u['rol_id'];
$uid = (int)$u['id'];

$idRaw = trim((string)($_GET['id'] ?? ''));
if ($idRaw === '') {
    flash('err', 'No se indicó un informe.');
    redirect(in_array($rol, ['ALUMNO', 'TUTOR'], true) ? 'informes/mis_informes.php' : 'informes/listado.php');
}
$st = $pdo->prepare(
    'SELECT i.id, i.codigo, i.estado, i.alumno_id, i.docente_id, i.division_id, i.creado_por,
            us.nombre AS alumno_nombre, us.apellido AS alumno_apellido
     FROM informes i JOIN alumnos al ON al.id=i.alumno_id
     JOIN usuarios us ON us.id=al.usuario_id
     WHERE ' . (ctype_digit($idRaw) ? 'i.id=?' : 'i.codigo=?')
);
$st->execute([ctype_digit($idRaw) ? (int)$idRaw : mb_substr($idRaw, 0, 20)]);
$inf = $st->fetch() ?: null;
if (!$inf) {
    flash('err', 'El informe solicitado no existe.');
    redirect(in_array($rol, ['ALUMNO', 'TUTOR'], true) ? 'informes/mis_informes.php' : 'informes/listado.php');
}

// ---------- Autorización de datos ----------
$miAlumnoId = 0; $miDocenteId = 0; $miTutorId = 0;
if ($rol === 'ALUMNO') {
    $st = $pdo->prepare('SELECT id FROM alumnos WHERE usuario_id=?');
    $st->execute([$uid]); $miAlumnoId = (int)$st->fetchColumn();
} elseif ($rol === 'TUTOR') {
    $st = $pdo->prepare('SELECT id FROM tutores WHERE usuario_id=?');
    $st->execute([$uid]); $miTutorId = (int)$st->fetchColumn();
} elseif (in_array($rol, ['DOCENTE', 'PRECEPTOR'], true)) {
    $st = $pdo->prepare('SELECT id FROM docentes WHERE usuario_id=?');
    $st->execute([$uid]); $miDocenteId = (int)$st->fetchColumn();
}

$puedeVer = false;
if (in_array($rol, ['ADMIN', 'DIRECTIVO'], true)) {
    $puedeVer = true;
} elseif ($rol === 'DOCENTE') {
    $puedeVer = $miDocenteId > 0 && (int)$inf['docente_id'] === $miDocenteId;
} elseif ($rol === 'PRECEPTOR') {
    $st = $pdo->prepare('SELECT 1 FROM division_preceptor WHERE preceptor_id=? AND division_id=?');
    $st->execute([$miDocenteId, (int)$inf['division_id']]);
    $puedeVer = (bool)$st->fetchColumn();
} elseif ($rol === 'ALUMNO') {
    $puedeVer = $miAlumnoId > 0 && (int)$inf['alumno_id'] === $miAlumnoId;
} elseif ($rol === 'TUTOR') {
    $st = $pdo->prepare('SELECT 1 FROM alumno_tutor WHERE tutor_id=? AND alumno_id=?');
    $st->execute([$miTutorId, (int)$inf['alumno_id']]);
    $puedeVer = (bool)$st->fetchColumn();
}
if (!$puedeVer) denegar_acceso('No tenés autorización para ver el historial de este informe.');

$informeId = (int)$inf['id'];
$codigo = $inf['codigo'];

// ---------- Historial de estados ----------
$st = $pdo->prepare('SELECT h.*, us.nombre, us.apellido
                     FROM historial_estados h LEFT JOIN usuarios us ON us.id=h.usuario_id
                     WHERE h.informe_id=? ORDER BY h.fecha DESC, h.id DESC');
$st->execute([$informeId]);
$estados = $st->fetchAll();

// ---------- Filas de auditoría relacionadas ----------
$st = $pdo->prepare("SELECT h.*, us.nombre, us.apellido
                     FROM historial_modificaciones h LEFT JOIN usuarios us ON us.id=h.usuario_id
                     WHERE h.registro_id IN (?,?)
                       AND h.tabla_afectada IN ('informes','descargos','consejo_aula','consejo_convivencia','clasificaciones','resoluciones')
                       OR h.detalle LIKE ?
                     ORDER BY h.fecha DESC, h.id DESC LIMIT 200");
$st->execute([(string)$informeId, $codigo, '%' . $codigo . '%']);
$auditorias = $st->fetchAll();

// ---------- Línea de tiempo unificada ----------
$eventos = [];
foreach ($estados as $h) {
    $eventos[] = [
        'fecha' => $h['fecha'],
        'titulo' => 'Cambio de estado: ' . estado_nombre($h['estado']),
        'detalle' => $h['observacion'] ?: 'Sin observaciones',
        'usuario' => trim(($h['apellido'] ?? '') . ', ' . ($h['nombre'] ?? '')) ?: 'Sistema',
        'tipo' => 'estado',
    ];
}
foreach ($auditorias as $a) {
    $eventos[] = [
        'fecha' => $a['fecha'],
        'titulo' => $a['accion'],
        'detalle' => $a['detalle'] ?: ($a['tabla_afectada'] ?: 'Sin detalle'),
        'usuario' => trim(($a['apellido'] ?? '') . ', ' . ($a['nombre'] ?? '')) ?: 'Sistema',
        'tipo' => 'auditoria',
    ];
}
usort($eventos, function ($a, $b) { return strcmp($b['fecha'], $a['fecha']); });

// ---------- Usuarios participantes ----------
$participantes = [(int)$inf['creado_por']];
foreach ($estados as $h) if (!empty($h['usuario_id'])) $participantes[] = (int)$h['usuario_id'];
foreach ($auditorias as $a) if (!empty($a['usuario_id'])) $participantes[] = (int)$a['usuario_id'];

$tablasResponsable = [
    'consejo_aula' => 'responsable_id',
    'consejo_convivencia' => 'responsable_id',
    'clasificaciones' => 'responsable_id',
    'resoluciones' => 'responsable_id',
];
foreach ($tablasResponsable as $tabla => $col) {
    $st = $pdo->prepare("SELECT {$col} AS usuario_id FROM {$tabla} WHERE informe_id=?");
    $st->execute([$informeId]);
    $participantes[] = (int)$st->fetchColumn();
}
$st = $pdo->prepare('SELECT d.usuario_id FROM docentes d WHERE d.id=?');
$st->execute([(int)$inf['docente_id']]);
$participantes[] = (int)$st->fetchColumn();
$st = $pdo->prepare('SELECT usuario_id FROM alumnos WHERE id=?');
$st->execute([(int)$inf['alumno_id']]);
$participantes[] = (int)$st->fetchColumn();

$participantes = array_values(array_unique(array_filter($participantes)));
$usuarios = [];
if ($participantes) {
    $marks = implode(',', array_fill(0, count($participantes), '?'));
    $st = $pdo->prepare("SELECT us.id, us.nombre, us.apellido, r.nombre AS rol_nombre
                         FROM usuarios us JOIN roles r ON r.id=us.rol_id
                         WHERE us.id IN ({$marks}) ORDER BY us.apellido, us.nombre");
    $st->execute($participantes);
    $usuarios = $st->fetchAll();
}

$detalleUrl = 'informes/detalle.php?id=' . rawurlencode($codigo);
layout_header('Historial · ' . $codigo);
?>
<h1>Historial del informe <?= e($codigo) ?></h1>
<p class="muted">Alumno/a: <?= e(trim($inf['alumno_apellido'] . ', ' . $inf['alumno_nombre'])) ?> · Estado actual: <?= e(estado_nombre($inf['estado'])) ?></p>

<div class="grid g2">
<div class="card">
  <h2>Línea de tiempo</h2>
  <?php if (!$eventos): ?><p class="muted">No hay movimientos registrados.</p>
  <?php else: ?>
  <table><thead><tr><th>Fecha</th><th>Evento</th><th>Detalle</th><th>Usuario</th></tr></thead><tbody>
    <?php foreach ($eventos as $ev): ?>
      <tr>
        <td><?= e($ev['fecha']) ?></td>
        <td><span class="pill <?= $ev['tipo'] === 'estado' ? 'info' : 'gray' ?>"><?= e($ev['titulo']) ?></span></td>
        <td><?= e($ev['detalle']) ?></td>
        <td><?= e($ev['usuario']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<div>
  <div class="card">
    <h2>Usuarios intervinientes</h2>
    <?php if (!$usuarios): ?><p class="muted">No hay usuarios registrados.</p>
    <?php else: ?><ul>
      <?php foreach ($usuarios as $persona): ?>
        <li><?= e(trim($persona['apellido'] . ', ' . $persona['nombre'])) ?> <small>(<?= e($persona['rol_nombre']) ?>)</small></li>
      <?php endforeach; ?>
    </ul><?php endif; ?>
  </div>
  <div class="card">
    <h2>Accesos rápidos</h2>
    <div class="btn-row">
      <a class="btn g" href="<?= e(url($detalleUrl)) ?>">Ver informe</a>
      <a class="btn g" href="<?= e(pdf_url($codigo)) ?>">Descargar PDF</a>
    </div>
  </div>
</div>
</div>
<?php layout_footer(); ?>
