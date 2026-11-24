<?php
/** PDF del informe: reproduce el acta de la vista web (misma estructura y estilo). */
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
$esCodigo = !ctype_digit($idRaw);
$st = $pdo->prepare(
    'SELECT i.*,
            us.nombre AS alumno_nombre, us.apellido AS alumno_apellido, us.dni AS alumno_dni, al.legajo,
            c.nombre AS curso, c.especialidad, dv.nombre AS division,
            dus.nombre AS docente_nombre, dus.apellido AS docente_apellido
     FROM informes i
     JOIN alumnos al ON al.id=i.alumno_id
     JOIN usuarios us ON us.id=al.usuario_id
     JOIN divisiones dv ON dv.id=i.division_id
     JOIN cursos c ON c.id=dv.curso_id
     JOIN docentes d ON d.id=i.docente_id
     JOIN usuarios dus ON dus.id=d.usuario_id
     WHERE ' . ($esCodigo ? 'i.codigo=?' : 'i.id=?')
);
$st->execute([$esCodigo ? mb_substr($idRaw, 0, 20) : (int)$idRaw]);
$inf = $st->fetch() ?: null;
if (!$inf) {
    flash('err', 'El informe solicitado no existe.');
    redirect(in_array($rol, ['ALUMNO', 'TUTOR'], true) ? 'informes/mis_informes.php' : 'informes/listado.php');
}

// ---------- Autorización ----------
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
if (!$puedeVer) denegar_acceso('No tenés autorización para generar el PDF de este informe.');
$informeId = (int)$inf['id'];

// ---------- Datos vinculados ----------
$st = $pdo->prepare('SELECT * FROM descargos WHERE informe_id=?');
$st->execute([$informeId]); $descargo = $st->fetch() ?: null;
$st = $pdo->prepare('SELECT ca.*, us.nombre, us.apellido FROM consejo_aula ca JOIN usuarios us ON us.id=ca.responsable_id WHERE ca.informe_id=?');
$st->execute([$informeId]); $ca = $st->fetch() ?: null;
$st = $pdo->prepare('SELECT cc.*, us.nombre, us.apellido FROM consejo_convivencia cc JOIN usuarios us ON us.id=cc.responsable_id WHERE cc.informe_id=?');
$st->execute([$informeId]); $cc = $st->fetch() ?: null;
$st = $pdo->prepare('SELECT cl.*, us.nombre, us.apellido FROM clasificaciones cl JOIN usuarios us ON us.id=cl.responsable_id WHERE cl.informe_id=?');
$st->execute([$informeId]); $clas = $st->fetch() ?: null;
$st = $pdo->prepare('SELECT r.*, us.nombre, us.apellido FROM resoluciones r JOIN usuarios us ON us.id=r.responsable_id WHERE r.informe_id=?');
$st->execute([$informeId]); $res = $st->fetch() ?: null;
$st = $pdo->prepare("SELECT observacion, estado, fecha FROM historial_estados WHERE informe_id=? AND observacion IS NOT NULL AND observacion<>'' ORDER BY fecha");
$st->execute([$informeId]); $observaciones = $st->fetchAll();

$alumnoNombre  = trim($inf['alumno_nombre'] . ' ' . $inf['alumno_apellido']);
$docenteNombre = trim($inf['docente_apellido'] . ', ' . $inf['docente_nombre']);
$instancia     = $clas['instancia'] ?? '';
$obsTexto      = $observaciones
    ? implode(' · ', array_map(fn($o) => estado_nombre($o['estado']) . ' (' . fmt_fecha($o['fecha']) . '): ' . $o['observacion'], $observaciones))
    : '';

// ---------- Estilo (idéntico al acta HTML) ----------
$ROJO       = [0.616, 0.078, 0.145];
$ROJO_CLARO = [0.984, 0.918, 0.929];
$GRIS_FONDO = [0.965, 0.965, 0.965];
$BORDE      = [0.82, 0.82, 0.82];
$TEXTO      = [0.12, 0.12, 0.12];
$GRIS_TEXTO = [0.35, 0.35, 0.35];

$pdf = new PDF();
$pdf->addPage();
$y = 124.0;
$izq = 42.0;
$der = 553.28;
$ancho = $der - $izq;

$cortar = function (?string $texto, int $max = 60): string {
    $texto = trim((string)($texto ?? ''));
    if ($texto === '') return '—';
    return mb_strlen($texto) > $max ? mb_substr($texto, 0, $max - 1) . '…' : $texto;
};

// ---------- Encabezado ----------
$pdf->image(__DIR__ . '/../assets/img/escudo.png', 280.64, 22, 34, 34);
$pdf->setFont('TB', 8);
$pdf->setText(...$TEXTO);
$pdf->textCenter(297.64, 64, 'GOBIERNO DE LA CIUDAD AUTÓNOMA DE BUENOS AIRES');
$pdf->setFont('TB', 7.5);
$pdf->textCenter(297.64, 73, 'MINISTERIO DE EDUCACIÓN');
$pdf->setFont('TB', 9);
$pdf->setText(...$ROJO);
$pdf->textCenter(297.64, 84, 'E.E.T. N° 35 D.E. 18 “Ing. Eduardo Latzina”');
$pdf->setStroke(...$ROJO);
$pdf->setLineWidth(1.1);
$pdf->line($izq, 91, $der, 91);
$pdf->setFont('TB', 12);
$pdf->textCenter(297.64, 104, 'INFORME DE CONVIVENCIA · ' . $inf['codigo']);
$pdf->setStroke(...$ROJO_CLARO);
$pdf->setLineWidth(0.8);
$pdf->line($izq, 112, $der, 112);

// ---------- Helpers ----------
$titulo = function (string $nro, string $texto) use ($pdf, &$y, $izq, $ROJO, $TEXTO): void {
    $pdf->setFont('TB', 9);
    $pdf->setText(...$ROJO);
    $pdf->text($izq, $y, $nro . '.-');
    $pdf->setText(...$TEXTO);
    $pdf->text($izq + 20, $y, $texto);
    $y += 13;
};

/** Etiqueta + valor subrayado, como .acta-linea. */
$linea = function (string $etiqueta, ?string $valor, float $x, float $w, int $max = 44)
    use ($pdf, $y, $cortar, $BORDE, $TEXTO): void {
    $pdf->setFont('T', 8.8);
    $pdf->setText(...$TEXTO);
    $rot = $etiqueta . ': ';
    $pdf->text($x, $y, $rot);
    $px = $x + $pdf->width($rot);
    $v = $cortar($valor, $max);
    $pdf->text($px, $y, $v);
    $pdf->setStroke(...$BORDE);
    $pdf->setLineWidth(0.4);
    $pdf->line($px, $y + 1.6, min($px + $pdf->width($v) + 3, $x + $w), $y + 1.6);
};

/** Dos campos por fila. */
$campos2 = function (array $a, array $b) use ($linea, &$y): void {
    $linea($a[0], $a[1] ?? '', 42, 248, $a[2] ?? 38);
    $linea($b[0], $b[1] ?? '', 305, 248, $b[2] ?? 38);
    $y += 12;
};

/** Recuadro gris con filete rojo a la izquierda, como .acta-cuadro. */
$cuadro = function (?string $texto, int $maxLineas = 3)
    use ($pdf, &$y, $izq, $ancho, $GRIS_FONDO, $BORDE, $ROJO, $TEXTO): void {
    $texto = trim((string)($texto ?? ''));
    $lineas = $texto === '' ? [] : $pdf->wrap($texto, 484);
    if (count($lineas) > $maxLineas) {
        $lineas = array_slice($lineas, 0, $maxLineas);
        $lineas[$maxLineas - 1] = mb_substr($lineas[$maxLineas - 1], 0, 90) . '…';
    }
    $h = max(1, count($lineas)) * 11 + 9;
    $pdf->setFill(...$GRIS_FONDO);
    $pdf->filledRect($izq, $y, $ancho, $h);
    $pdf->setFill(...$ROJO);
    $pdf->filledRect($izq, $y, 2.5, $h);
    $pdf->setStroke(...$BORDE);
    $pdf->setLineWidth(0.4);
    $pdf->rect($izq, $y, $ancho, $h);
    $pdf->setFont('T', 8.6);
    $pdf->setText(...$TEXTO);
    foreach ($lineas as $i => $l) $pdf->text($izq + 8, $y + 8 + $i * 11, $l);
    $y += $h + 6;
};

$nota = function (string $texto) use ($pdf, &$y, $izq, $GRIS_TEXTO): void {
    $pdf->setFont('T', 8);
    $pdf->setText(...$GRIS_TEXTO);
    $pdf->text($izq, $y, $texto);
    $y += 11;
};

// ---------- 1 ----------
$titulo('1', 'El alumno/a');
$campos2(['Apellido y nombre', $alumnoNombre, 40], ['Curso', $inf['curso'], 28]);
$campos2(['División', $inf['division'], 28], ['Legajo', $inf['legajo'], 28]);
$linea('DNI', $inf['alumno_dni'], 42, 248, 28);
$linea('Fecha del incidente', fmt_fecha($inf['fecha_incidente']), 305, 248, 26);
$y += 16;

// ---------- 2 ----------
$titulo('2', 'Ha realizado la acción que se describe a continuación:');
$cuadro($inf['descripcion'], 3);
$linea('Normas transgredidas', $inf['normas'], 42, 511.28, 92);
$y += 12;
$nota('transgrediendo Normas del Reglamento de Convivencia de la escuela.');

// ---------- 3 ----------
$titulo('3', 'Solicitud de sanción:');
$campos2(['Docente', $docenteNombre, 36], ['Cargo/función', $inf['cargo'], 30]);
$campos2(['Fecha', fmt_fecha($inf['fecha_incidente'], false), 22], ['Firma', $inf['firma_digital'], 28]);
$linea('Categoría solicitada', $inf['solicitud_sancion'], 42, 511.28, 90);
$y += 16;

// ---------- 4 ----------
$titulo('4', 'Descargo del Alumno/a:');
$cuadro($descargo['descripcion'] ?? 'Sin registrar', 3);
if ($descargo) {
    $campos2(['Observaciones', $descargo['observaciones'] ?? '', 28], ['Identificación', $alumnoNombre . ' · ' . $inf['legajo'], 28]);
    $linea('Fecha de presentación', fmt_fecha($descargo['fecha_presentacion']), 42, 511.28, 40);
    $y += 12;
}

// ---------- 5 ----------
$titulo('5', 'Informe de Consejo de Aula');
$cuadro($ca['observaciones'] ?? 'Sin registrar', 2);
if ($ca) {
    $campos2(['Evaluación', $ca['evaluacion'] ?? '', 28], ['Recomendaciones', $ca['recomendaciones'] ?? '', 28]);
    $campos2(['Responsable', trim($ca['apellido'] . ', ' . $ca['nombre']), 30], ['Fecha intervención', fmt_fecha($ca['fecha_intervencion']), 26]);
}

// ---------- 6 ----------
$titulo('6', 'Informe de Consejo de Convivencia');
$cuadro($cc['observaciones'] ?? 'Sin registrar', 2);
if ($cc) {
    $campos2(['Análisis', $cc['analisis'] ?? '', 26], ['Recomendaciones', $cc['recomendaciones'] ?? '', 26]);
    $campos2(['Intervención', $cc['intervencion'] ?? '', 26], ['Responsable', trim($cc['apellido'] . ', ' . $cc['nombre']), 28]);
    $linea('Fecha intervención', fmt_fecha($cc['fecha_intervencion']), 42, 511.28, 36);
    $y += 12;
}

// ---------- 7 ----------
$titulo('7', 'Observaciones');
$cuadro($obsTexto !== '' ? $obsTexto : 'Sin observaciones', 2);

// ---------- 8 ----------
$titulo('8', 'Se considera que corresponde: (Indicar a continuación)');
$pdf->setFont('T', 8.8);
$pdf->setText(...$TEXTO);
foreach ([[42, '1ª Instancia LEVE', 'LEVE'], [212, '2ª Instancia GRAVE', 'GRAVE'], [382, '3ª Instancia MUY GRAVE', 'MUY_GRAVE']] as [$x, $txt, $clave]) {
    $pdf->setStroke(...$BORDE);
    $pdf->setLineWidth(0.5);
    $pdf->rect($x, $y + 1, 9, 9);
    if ($instancia === $clave) {
        $pdf->setFont('TB', 8);
        $pdf->setText(...$ROJO);
        $pdf->text($x + 2, $y + 8, 'X');
        $pdf->setFont('T', 8.8);
        $pdf->setText(...$TEXTO);
    }
    $pdf->text($x + 13, $y + 8, $txt);
}
$y += 15;
$linea('Otra consideración', $clas['otra_consideracion'] ?? '', 42, 511.28, 88);
$y += 16;

// ---------- 9 ----------
$titulo('9', 'Notificación:');
$tabla = function (float $x, float $w, string $tituloCol) use ($pdf, $y, $ROJO_CLARO, $BORDE, $ROJO): void {
    $pdf->setFill(...$ROJO_CLARO);
    $pdf->filledRect($x, $y, $w, 13);
    $pdf->setStroke(...$BORDE);
    $pdf->setLineWidth(0.4);
    $pdf->rect($x, $y, $w, 13);
    $pdf->line($x + $w - 80, $y, $x + $w - 80, $y + 13);
    $pdf->setFont('TB', 8);
    $pdf->setText(...$ROJO);
    $pdf->text($x + 5, $y + 9, $tituloCol);
    $pdf->text($x + $w - 74, $y + 9, 'Fecha');
    $pdf->rect($x, $y + 13, $w, 17);
    $pdf->line($x + $w - 80, $y + 13, $x + $w - 80, $y + 30);
};
$tabla(42, 248, 'Alumno');
$tabla(305, 248.28, 'Padre/Madre/Tutor');
$y += 33;

// ---------- Pie ----------
$pdf->setStroke(...$ROJO_CLARO);
$pdf->setLineWidth(0.8);
$pdf->line($izq, 800, $der, 800);
$pdf->setFont('T', 7.5);
$pdf->setText(...$GRIS_TEXTO);
$pdf->text($izq, 811, 'Sistema de Gestión de Informes de Convivencia · E.E.T. N° 35 D.E. 18');
$pdf->textRight($der, 811, 'Página 1 / 1');

$nombreArchivo = 'informe_' . preg_replace('/[^A-Za-z0-9_-]/', '', $inf['codigo']) . '.pdf';
$pdf->output($nombreArchivo);
