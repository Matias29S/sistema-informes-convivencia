<?php
/** Detalle institucional del informe (acta de 6 secciones) y acciones según rol. */
require_once __DIR__ . '/../config/app.php';
require_login();
global $pdo;

$u = current_user();
$rol = $u['rol_id'];
$uid = (int)$u['id'];
$idRaw = trim((string)($_GET['id'] ?? ''));
$porCodigo = !ctype_digit($idRaw);

if ($idRaw === '') {
    flash('err', 'No se indicó un informe.');
    redirect(in_array($rol, ['ALUMNO', 'TUTOR'], true) ? 'informes/mis_informes.php' : 'informes/listado.php');
}

$st = $pdo->prepare(
    'SELECT i.*,
            us.nombre AS alumno_nombre, us.apellido AS alumno_apellido, us.dni AS alumno_dni,
            al.legajo,
            c.nombre AS curso, c.especialidad, dv.nombre AS division,
            dus.nombre AS docente_nombre, dus.apellido AS docente_apellido,
            cus.nombre AS creador_nombre, cus.apellido AS creador_apellido
     FROM informes i
     JOIN alumnos al ON al.id=i.alumno_id
     JOIN usuarios us ON us.id=al.usuario_id
     JOIN divisiones dv ON dv.id=i.division_id
     JOIN cursos c ON c.id=dv.curso_id
     JOIN docentes d ON d.id=i.docente_id
     JOIN usuarios dus ON dus.id=d.usuario_id
     JOIN usuarios cus ON cus.id=i.creado_por
     WHERE ' . ($porCodigo ? 'i.codigo=?' : 'i.id=?')
);
$st->execute([$porCodigo ? mb_substr($idRaw, 0, 20) : (int)$idRaw]);
$inf = $st->fetch() ?: null;
if (!$inf) {
    flash('err', 'El informe solicitado no existe.');
    redirect(in_array($rol, ['ALUMNO', 'TUTOR'], true) ? 'informes/mis_informes.php' : 'informes/listado.php');
}

// ---------- Autorización de acceso a LOS DATOS ----------
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
if (!$puedeVer) denegar_acceso('No tenés autorización para ver este informe.');

$detalleUrl = 'informes/detalle.php?id=' . rawurlencode($inf['codigo']);
$informeId = (int)$inf['id'];

// Cuando el alumno abre su informe, queda registrado como revisado (sin cambiar el estado del trámite).
if ($rol === 'ALUMNO' && $miAlumnoId > 0 && (int)$inf['alumno_id'] === $miAlumnoId && empty($inf['revisado'])) {
    try {
        $st = $pdo->prepare('UPDATE informes SET revisado=1, fecha_revision=NOW() WHERE id=? AND (revisado IS NULL OR revisado=0)');
        $st->execute([$informeId]);
        if ($st->rowCount() > 0) {
            $st = $pdo->prepare('INSERT INTO historial_estados (informe_id, estado, usuario_id, observacion) VALUES (?,?,?,?)');
            $st->execute([$informeId, 'REVISADO', $uid, 'Informe revisado por el alumno/a']);
            $inf['revisado'] = 1;
            $inf['fecha_revision'] = ahora_db();
        }
    } catch (PDOException $e) {
        // Si la migración todavía no se aplicó, el informe sigue siendo visible igual.
    }
}

// ---------- Anulación (POST con CSRF y permiso estricto) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['accion'] ?? '') !== 'anular' || !in_array($rol, ['ADMIN', 'DIRECTIVO'], true)) {
        denegar_acceso('No tenés permiso para anular informes.');
    }
    if ($inf['estado'] === 'ANULADO') {
        flash('err', 'El informe ya está anulado.');
        redirect($detalleUrl);
    }
    $obs = 'Informe anulado por ' . trim($u['nombre'] . ' ' . $u['apellido']) . ' (ID ' . $uid . ')';
    cambiar_estado($informeId, 'ANULADO', $obs);
    audit('ANULAR_INFORME', 'informes', (string)$informeId, 'Informe ' . $inf['codigo'] . ' anulado');
    flash('ok', 'Informe anulado correctamente.');
    redirect($detalleUrl);
}

// ---------- Información asociada ----------
$st = $pdo->prepare('SELECT * FROM descargos WHERE informe_id=?');
$st->execute([$informeId]); $descargo = $st->fetch() ?: null;

$st = $pdo->prepare('SELECT ca.*, us.nombre AS resp_nombre, us.apellido AS resp_apellido
                     FROM consejo_aula ca JOIN usuarios us ON us.id=ca.responsable_id
                     WHERE ca.informe_id=?');
$st->execute([$informeId]); $consejoAula = $st->fetch() ?: null;

$st = $pdo->prepare('SELECT cc.*, us.nombre AS resp_nombre, us.apellido AS resp_apellido
                     FROM consejo_convivencia cc JOIN usuarios us ON us.id=cc.responsable_id
                     WHERE cc.informe_id=?');
$st->execute([$informeId]); $consejoConv = $st->fetch() ?: null;

$st = $pdo->prepare('SELECT cl.*, us.nombre AS resp_nombre, us.apellido AS resp_apellido
                     FROM clasificaciones cl JOIN usuarios us ON us.id=cl.responsable_id
                     WHERE cl.informe_id=?');
$st->execute([$informeId]); $clasificacion = $st->fetch() ?: null;

$st = $pdo->prepare('SELECT r.*, us.nombre AS resp_nombre, us.apellido AS resp_apellido
                     FROM resoluciones r JOIN usuarios us ON us.id=r.responsable_id
                     WHERE r.informe_id=?');
$st->execute([$informeId]); $resolucion = $st->fetch() ?: null;

$st = $pdo->prepare('SELECT observacion, estado, fecha FROM historial_estados
                     WHERE informe_id=? AND observacion IS NOT NULL AND observacion<>\'\'
                     ORDER BY fecha DESC');
$st->execute([$informeId]); $observaciones = $st->fetchAll();

$st = $pdo->prepare('SELECT us.nombre, us.apellido, at.relacion
                     FROM alumno_tutor at JOIN tutores t ON t.id=at.tutor_id
                     JOIN usuarios us ON us.id=t.usuario_id
                     WHERE at.alumno_id=? ORDER BY us.apellido, us.nombre');
$st->execute([(int)$inf['alumno_id']]); $tutores = $st->fetchAll();

$st = $pdo->prepare('SELECT n.usuario_id, n.fecha, n.titulo, us.nombre, us.apellido
                     FROM notificaciones n JOIN usuarios us ON us.id=n.usuario_id
                     WHERE n.enlace=? ORDER BY n.fecha DESC');
$st->execute([$detalleUrl]); $notificaciones = $st->fetchAll();

function caja_chk(bool $marcado): string {
    return '<span class="box">' . ($marcado ? '×' : '') . '</span>';
}
function linea_valor($valor, string $clase = ''): string {
    $valor = trim((string)($valor ?? ''));
    $clase = trim('acta-linea ' . $clase);
    return $valor === ''
        ? '<span class="' . e($clase) . ' acta-vacia">&nbsp;</span>'
        : '<span class="' . e($clase) . '">' . e($valor) . '</span>';
}
function linea_punteada($valor = ''): string {
    return '<div class="acta-puntos">' . e((string)($valor ?? '')) . '</div>';
}
function clase_pill(string $estado): string {
    if ($estado === 'RESUELTO') return 'ok';
    if ($estado === 'ANULADO') return 'gray';
    if (strpos($estado, 'PENDIENTE') === 0) return 'warn';
    return 'info';
}

$anulado = $inf['estado'] === 'ANULADO';
$puedeRevisar = !$anulado && in_array($rol, ['DIRECTIVO', 'PRECEPTOR', 'ADMIN'], true) && has_permission('informes.revisar')
                 && in_array($inf['estado'], ['PENDIENTE_REVISION', 'EN_REVISION'], true);
$puedeConsejos = !$anulado && in_array($rol, ['DIRECTIVO', 'PRECEPTOR', 'ADMIN'], true) && has_permission('consejos.registrar');
$puedeResolver = !$anulado && in_array($rol, ['DIRECTIVO', 'ADMIN'], true) && has_permission('informes.resolver');
$puedeAnular = in_array($rol, ['DIRECTIVO', 'ADMIN'], true);
$puedeDescargo = !$anulado && $rol === 'ALUMNO' && $miAlumnoId > 0 && (int)$inf['alumno_id'] === $miAlumnoId
                 && $inf['estado'] === 'PENDIENTE_DESCARGO' && !$descargo;

$instancia = $clasificacion['instancia'] ?? '';
if ($instancia === '') $instancia = instancia_de($inf['solicitud_sancion'] ?? '');
$nombresInstancia = ['LEVE' => '1ª LEVE', 'GRAVE' => '2ª GRAVE', 'MUY_GRAVE' => '3ª MUY GRAVE', 'OTRA' => 'Otra consideración'];
$cursoTexto = trim($inf['curso'] . ' ' . ($inf['especialidad'] ? $inf['especialidad'] . ' ' : '') . $inf['division']);

layout_header('Informe ' . $inf['codigo']);
?>
<h1>Informe <?= e($inf['codigo']) ?></h1>
<p class="muted">
  Estado: <span class="pill <?= e(clase_pill($inf['estado'])) ?>"><?= e(estado_nombre($inf['estado'])) ?></span>
  · Creado el <?= e(fmt_fecha($inf['fecha_creacion'])) ?>
</p>
<?php if ($anulado): ?>
  <div class="alert info">Este informe está anulado. Se puede consultar, pero las acciones de tramitación están deshabilitadas.</div>
<?php endif; ?>

<div class="detalle-layout">
<div class="detalle-acta">
  <div class="acta">
    <div class="acta-logo"><img src="<?= e(asset_url('img/escudo.png')) ?>" alt="Escudo de la escuela"></div>
    <div class="acta-gcba">
      <div class="txt">
        <div class="sup">GOBIERNO DE LA CIUDAD AUTÓNOMA DE BUENOS AIRES</div>
        <div class="sub">MINISTERIO DE EDUCACIÓN</div>
        <div class="esc">E.E.T. N° 35 D.E. 18 “Ing. Eduardo Latzina”</div>
      </div>
    </div>
    <div class="acta-titulo">INFORME DE CONVIVENCIA · <?= e($inf['codigo']) ?></div>

    <div class="acta-sec"><div class="num">1.-</div><div>
      El alumno/a: <?= linea_valor(trim($inf['alumno_nombre'] . ' ' . $inf['alumno_apellido'])) ?>
      de <?= linea_valor($inf['curso'], 'acta-corto') ?> año,
      División <?= linea_valor($inf['division'], 'acta-corto') ?>.
    </div></div>

    <div class="acta-sec"><div class="num">2.-</div><div>
      Ha realizado la acción que se describe a continuación:
      <?= linea_punteada($inf['descripcion']) ?>
      <?= linea_punteada() ?>
      <?= linea_punteada() ?>
      <?= linea_punteada() ?>
    </div></div>

    <div class="acta-sec"><div class="num">3.-</div><div>
      Solicitud de sanción:
      <div class="acta-campos">
        <span>Docente: <?= linea_valor(trim($inf['docente_apellido'] . ', ' . $inf['docente_nombre'])) ?></span>
        <span>Cargo/función: <?= linea_valor($inf['cargo']) ?></span>
        <span>Fecha: <?= linea_valor(fmt_fecha($inf['fecha_incidente'], false)) ?></span>
        <span>Firma: <?= linea_valor($inf['firma_digital']) ?></span>
      </div>
    </div></div>

    <div class="acta-sec"><div class="num">4.-</div><div>
      Observaciones
      <?= linea_punteada() ?>
      <?= linea_punteada() ?>
    </div></div>

    <div class="acta-sec"><div class="num">5.-</div><div>
      Se considera que corresponde: (Indicar a continuación)
      <div class="acta-chk"><?= caja_chk($instancia === 'LEVE') ?> 1ª Instancia LEVE</div>
      <div class="acta-chk"><?= caja_chk($instancia === 'GRAVE') ?> 2ª Instancia GRAVE</div>
      <div class="acta-chk"><?= caja_chk($instancia === 'MUY_GRAVE') ?> 3ª Instancia MUY GRAVE</div>
      <div class="acta-campos"><span>Otra consideración: <?= linea_valor($clasificacion['otra_consideracion'] ?? '') ?></span></div>
      <div class="acta-campos">
        <span>Firma Directivo: <?= linea_valor($clasificacion ? trim($clasificacion['resp_apellido'] . ', ' . $clasificacion['resp_nombre']) : '') ?></span>
        <span>Fecha: <?= linea_valor(!empty($clasificacion['fecha']) ? fmt_fecha($clasificacion['fecha'], false) : '') ?></span>
      </div>
    </div></div>

    <div class="acta-sec"><div class="num">6.-</div><div>
      Notificación:
      <div class="acta-campos"><span>Alumno: <?= linea_valor('', 'acta-larga') ?></span></div>
      <div class="acta-campos"><span>Fecha: <?= linea_valor('', 'acta-media') ?></span></div>
      <div class="acta-campos"><span>Padre/Madre/Tutor: <?= linea_valor('', 'acta-larga') ?></span></div>
      <div class="acta-campos"><span>Fecha: <?= linea_valor('', 'acta-media') ?></span></div>
    </div></div>
  </div>
</div>

<aside class="detalle-side">
  <div class="card">
    <h2>Datos del informe</h2>
    <p><strong>Código:</strong> <?= e($inf['codigo']) ?></p>
    <p><strong>Estado:</strong> <span class="pill <?= e(clase_pill($inf['estado'])) ?>"><?= e(estado_nombre($inf['estado'])) ?></span></p>
    <p><strong>Alumno/a:</strong> <?= e(trim($inf['alumno_apellido'] . ', ' . $inf['alumno_nombre'])) ?></p>
    <p><strong>Legajo / DNI:</strong> <?= e($inf['legajo']) ?> / <?= e($inf['alumno_dni']) ?></p>
    <p><strong>Curso / división:</strong> <?= e($cursoTexto) ?></p>
    <p><strong>Docente:</strong> <?= e(trim($inf['docente_apellido'] . ', ' . $inf['docente_nombre'])) ?></p>
    <p><strong>Fecha del incidente:</strong> <?= e(fmt_fecha($inf['fecha_incidente'])) ?></p>
    <p><strong>Fecha de creación:</strong> <?= e(fmt_fecha($inf['fecha_creacion'])) ?></p>
    <p><strong>Creado por:</strong> <?= e(trim($inf['creador_apellido'] . ', ' . $inf['creador_nombre'])) ?></p>
    <p><strong>Firma digital:</strong> <?= e($inf['firma_digital']) ?></p>
  </div>

  <div class="card">
    <h2>Acciones</h2>
    <div class="btn-row">
      <?php if ($puedeRevisar): ?>
        <a class="btn" href="<?= e(url('informes/revision.php?id=' . rawurlencode($inf['codigo']))) ?>">Revisión / solicitar descargo</a>
      <?php endif; ?>
      <?php if ($puedeDescargo): ?>
        <a class="btn" href="<?= e(url('informes/descargo.php?id=' . rawurlencode($inf['codigo']))) ?>">Presentar descargo</a>
      <?php endif; ?>
      <?php if ($puedeConsejos): ?>
        <a class="btn" href="<?= e(url('informes/consejo_aula.php?id=' . rawurlencode($inf['codigo']))) ?>">Consejo de Aula</a>
        <a class="btn" href="<?= e(url('informes/consejo_convivencia.php?id=' . rawurlencode($inf['codigo']))) ?>">Consejo de Convivencia</a>
      <?php endif; ?>
      <?php if ($puedeResolver): ?>
        <a class="btn" href="<?= e(url('informes/clasificacion.php?id=' . rawurlencode($inf['codigo']))) ?>">Clasificación</a>
        <a class="btn" href="<?= e(url('informes/resolucion.php?id=' . rawurlencode($inf['codigo']))) ?>">Resolución</a>
      <?php endif; ?>
      <a class="btn g" href="<?= e(pdf_url($inf['codigo'], $inf['actualizado_en'] ?? $inf['fecha_creacion'])) ?>">Descargar PDF</a>
      <a class="btn g" href="<?= e(url('informes/adjuntos.php?id=' . rawurlencode($inf['codigo']))) ?>">Adjuntos</a>
      <a class="btn g" href="<?= e(url('informes/historial.php?id=' . rawurlencode($inf['codigo']))) ?>">Historial</a>
      <?php if (in_array($rol, ['ALUMNO', 'TUTOR'], true)): ?>
        <a class="btn g" href="<?= e(url('informes/mis_informes.php')) ?>">Mis informes</a>
      <?php else: ?>
        <a class="btn g" href="<?= e(url('informes/listado.php')) ?>">Volver al listado</a>
      <?php endif; ?>
    </div>
    <?php if ($puedeAnular && $inf['estado'] !== 'ANULADO'): ?>
      <form method="post" action="<?= e(url($detalleUrl)) ?>" data-confirm="¿Anular el informe <?= e($inf['codigo']) ?>? Esta acción no se puede deshacer.">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="anular">
        <button class="btn d" type="submit">Anular informe</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Resumen del trámite</h2>
    <p><strong>Descargo:</strong> <?= $descargo ? 'Presentado el ' . e($descargo['fecha_presentacion']) : 'Pendiente / sin registrar' ?></p>
    <p><strong>Consejo de Aula:</strong> <?= $consejoAula ? 'Registrado el ' . e($consejoAula['fecha_intervencion']) : 'Sin registrar' ?></p>
    <p><strong>Consejo de Convivencia:</strong> <?= $consejoConv ? 'Registrado el ' . e($consejoConv['fecha_intervencion']) : 'Sin registrar' ?></p>
    <p><strong>Clasificación:</strong> <?= $instancia !== '' ? e($nombresInstancia[$instancia] ?? $instancia) : 'Sin registrar' ?></p>
    <p><strong>Resolución:</strong> <?= $resolucion ? 'Registrada el ' . e($resolucion['fecha']) : 'Sin registrar' ?></p>
  </div>

  <?php if ($observaciones): ?>
  <div class="card">
    <h2>Observaciones del historial</h2>
    <?php foreach ($observaciones as $ob): ?>
      <p><?= e($ob['observacion']) ?><br><small><?= e(estado_nombre($ob['estado'])) ?> · <?= e(fmt_fecha($ob['fecha'])) ?></small></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</aside>
</div>
<?php layout_footer(); ?>
