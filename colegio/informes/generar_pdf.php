<?php
/** Acta de convivencia en PDF: una hoja A4, misma información que la vista web. */
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
            c.nombre AS curso, dv.nombre AS division,
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

// Autorización (idéntica a la vista web).
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
$puedeVer = in_array($rol, ['ADMIN', 'DIRECTIVO'], true);
if ($rol === 'DOCENTE') $puedeVer = $miDocenteId > 0 && (int)$inf['docente_id'] === $miDocenteId;
if ($rol === 'PRECEPTOR') {
    $st = $pdo->prepare('SELECT 1 FROM division_preceptor WHERE preceptor_id=? AND division_id=?');
    $st->execute([$miDocenteId, (int)$inf['division_id']]);
    $puedeVer = (bool)$st->fetchColumn();
}
if ($rol === 'ALUMNO') $puedeVer = $miAlumnoId > 0 && (int)$inf['alumno_id'] === $miAlumnoId;
if ($rol === 'TUTOR') {
    $st = $pdo->prepare('SELECT 1 FROM alumno_tutor WHERE tutor_id=? AND alumno_id=?');
    $st->execute([$miTutorId, (int)$inf['alumno_id']]);
    $puedeVer = (bool)$st->fetchColumn();
}
if (!$puedeVer) denegar_acceso('No tenés autorización para generar el PDF de este informe.');

$informeId = (int)$inf['id'];
$datos = function (string $sql) use ($pdo, $informeId): ?array {
    $st = $pdo->prepare($sql);
    $st->execute([$informeId]);
    return $st->fetch() ?: null;
};
$descargo = $datos('SELECT * FROM descargos WHERE informe_id=?');
$ca = $datos('SELECT ca.*, us.nombre, us.apellido FROM consejo_aula ca JOIN usuarios us ON us.id=ca.responsable_id WHERE ca.informe_id=?');
$cc = $datos('SELECT cc.*, us.nombre, us.apellido FROM consejo_convivencia cc JOIN usuarios us ON us.id=cc.responsable_id WHERE cc.informe_id=?');
$clas = $datos('SELECT cl.*, us.nombre, us.apellido FROM clasificaciones cl JOIN usuarios us ON us.id=cl.responsable_id WHERE cl.informe_id=?');
$res = $datos('SELECT r.*, us.nombre, us.apellido FROM resoluciones r JOIN usuarios us ON us.id=r.responsable_id WHERE r.informe_id=?');
$st = $pdo->prepare("SELECT observacion, estado, fecha FROM historial_estados WHERE informe_id=? AND observacion IS NOT NULL AND observacion<>'' ORDER BY fecha");
$st->execute([$informeId]);
$observaciones = $st->fetchAll();

$alumno = trim($inf['alumno_nombre'] . ' ' . $inf['alumno_apellido']);
$docente = trim($inf['docente_apellido'] . ', ' . $inf['docente_nombre']);
$instancia = $clas['instancia'] ?? '';

// ---------- Dibujo ----------
const PDF_ROJO  = [0.616, 0.078, 0.145];
const PDF_CLARO = [0.984, 0.918, 0.929];
const PDF_GRIS  = [0.965, 0.965, 0.965];
const PDF_BORDE = [0.820, 0.820, 0.820];
const PDF_TEXTO = [0.150, 0.150, 0.150];
const PDF_MUTED = [0.380, 0.380, 0.380];

function pv(?string $v): string {
    $v = trim((string)($v ?? ''));
    return $v === '' ? '—' : $v;
}

function pt(PDF $p, float $y, string $nro, string $titulo): float {
    $p->fill(...PDF_ROJO);
    $p->setFont('B', 9);
    $p->text(42, $y, $nro . '.-');
    $p->text(62, $y, $titulo);
    return $y + 16;
}

function pc(PDF $p, float $y, string $etiqueta, ?string $valor, float $x, float $w, int $max = 40): void {
    $p->fill(...PDF_TEXTO);
    $p->setFont('T', 8.8);
    $rot = $etiqueta . ': ';
    $p->text($x, $y, $rot);
    $px = $x + $p->width($rot);
    $v = pv($valor);
    if (mb_strlen($v) > $max) $v = mb_substr($v, 0, $max - 1) . '…';
    $p->text($px, $y, $v);
    $p->stroke(...PDF_BORDE);
    $p->widthLine(0.35);
    $p->line($px, $y + 1.4, min($px + $p->width($v) + 3, $x + $w), $y + 1.4);
}

function pq(PDF $p, float $y, ?string $texto, int $max = 2): float {
    $lineas = $p->wrap(pv($texto), 484);
    if (count($lineas) > $max) $lineas = array_slice($lineas, 0, $max);
    $h = count($lineas) * 13 + 11;
    $p->fill(...PDF_GRIS);
    $p->filledRect(42, $y, 511.28, $h);
    $p->fill(...PDF_ROJO);
    $p->filledRect(42, $y, 2.5, $h);
    $p->stroke(...PDF_BORDE);
    $p->widthLine(0.4);
    $p->rect(42, $y, 511.28, $h);
    $p->fill(...PDF_TEXTO);
    $p->setFont('T', 8.6);
    foreach ($lineas as $i => $l) $p->text(50, $y + 9 + $i * 13, $l);
    return $y + $h + 8;
}

function pn(PDF $p, float $y, string $texto): float {
    $p->fill(...PDF_MUTED);
    $p->setFont('T', 8);
    $p->text(42, $y, $texto);
    return $y + 14;
}

function pk(PDF $p, float $y, string $instancia): float {
    $p->setFont('T', 8.8);
    $p->fill(...PDF_TEXTO);
    foreach ([[42, 'LEVE', '1ª Instancia LEVE'], [212, 'GRAVE', '2ª Instancia GRAVE'], [382, 'MUY_GRAVE', '3ª Instancia MUY GRAVE']] as [$x, $clave, $texto]) {
        $p->stroke(...PDF_BORDE);
        $p->widthLine(0.5);
        $p->rect($x, $y + 1, 9, 9);
        if ($instancia === $clave) {
            $p->fill(...PDF_ROJO);
            $p->setFont('B', 8);
            $p->text($x + 2, $y + 8, 'X');
            $p->setFont('T', 8.8);
            $p->fill(...PDF_TEXTO);
        }
        $p->text($x + 13, $y + 8, $texto);
    }
    return $y + 18;
}

function pf(PDF $p, float $y, float $x, float $w, string $titulo): void {
    $p->fill(...PDF_CLARO);
    $p->filledRect($x, $y, $w, 15);
    $p->stroke(...PDF_BORDE);
    $p->widthLine(0.4);
    $p->rect($x, $y, $w, 15);
    $p->line($x + $w - 80, $y, $x + $w - 80, $y + 15);
    $p->fill(...PDF_ROJO);
    $p->setFont('B', 8);
    $p->text($x + 5, $y + 10, $titulo);
    $p->text($x + $w - 74, $y + 10, 'Fecha');
    $p->rect($x, $y + 15, $w, 19);
    $p->line($x + $w - 80, $y + 15, $x + $w - 80, $y + 34);
}

$pdf = new PDF();
$pdf->addPage();

// Encabezado.
$pdf->image(__DIR__ . '/../assets/img/escudo.png', 280.64, 22, 34, 34);
$pdf->fill(...PDF_TEXTO);
$pdf->setFont('B', 8);
$pdf->textCenter(297.64, 64, 'GOBIERNO DE LA CIUDAD AUTÓNOMA DE BUENOS AIRES');
$pdf->setFont('B', 7.5);
$pdf->textCenter(297.64, 73, 'MINISTERIO DE EDUCACIÓN');
$pdf->fill(...PDF_ROJO);
$pdf->setFont('B', 9);
$pdf->textCenter(297.64, 84, 'E.E.T. N° 35 D.E. 18 “Ing. Eduardo Latzina”');
$pdf->stroke(...PDF_ROJO);
$pdf->widthLine(1.1);
$pdf->line(42, 91, 553.28, 91);
$pdf->setFont('B', 12);
$pdf->textCenter(297.64, 104, 'INFORME DE CONVIVENCIA · ' . $inf['codigo']);
$pdf->stroke(...PDF_CLARO);
$pdf->widthLine(0.8);
$pdf->line(42, 112, 553.28, 112);

$y = 124.0;

// 1. El alumno/a.
$y = pt($pdf, $y, '1', 'El alumno/a');
pc($pdf, $y, 'Apellido y nombre', $alumno, 42, 248, 38);
pc($pdf, $y, 'Curso', $inf['curso'], 305, 248, 24); $y += 15;
pc($pdf, $y, 'División', $inf['division'], 42, 248, 24);
pc($pdf, $y, 'Legajo', $inf['legajo'], 305, 248, 24); $y += 15;
pc($pdf, $y, 'DNI', $inf['alumno_dni'], 42, 248, 24);
pc($pdf, $y, 'Fecha del incidente', fmt_fecha($inf['fecha_incidente']), 305, 248, 24); $y += 19;

// 2. Acción.
$y = pt($pdf, $y, '2', 'Ha realizado la acción que se describe a continuación:');
$y = pq($pdf, $y, $inf['descripcion']);
pc($pdf, $y, 'Normas transgredidas', $inf['normas'], 42, 511.28, 88); $y += 15;
$y = pn($pdf, $y, 'transgrediendo Normas del Reglamento de Convivencia de la escuela.');

// 3. Solicitud de sanción.
$y = pt($pdf, $y, '3', 'Solicitud de sanción:');
pc($pdf, $y, 'Docente', $docente, 42, 248, 34);
pc($pdf, $y, 'Cargo/función', $inf['cargo'], 305, 248, 26); $y += 15;
pc($pdf, $y, 'Fecha', fmt_fecha($inf['fecha_incidente'], false), 42, 248, 20);
pc($pdf, $y, 'Firma', $inf['firma_digital'], 305, 248, 26); $y += 15;
pc($pdf, $y, 'Categoría solicitada', $inf['solicitud_sancion'], 42, 511.28, 86); $y += 19;

// 4. Descargo.
$y = pt($pdf, $y, '4', 'Descargo del Alumno/a:');
$y = pq($pdf, $y, $descargo['descripcion'] ?? 'Sin registrar');
if ($descargo) {
    pc($pdf, $y, 'Observaciones', $descargo['observaciones'] ?? '', 42, 248, 26);
    pc($pdf, $y, 'Identificación', $alumno . ' · ' . $inf['legajo'], 305, 248, 26); $y += 15;
    pc($pdf, $y, 'Fecha de presentación', fmt_fecha($descargo['fecha_presentacion']), 42, 511.28, 36); $y += 15;
}

// 5. Consejo de Aula.
$y = pt($pdf, $y, '5', 'Informe de Consejo de Aula');
$y = pq($pdf, $y, $ca['observaciones'] ?? 'Sin registrar');
if ($ca) {
    pc($pdf, $y, 'Evaluación', $ca['evaluacion'] ?? '', 42, 248, 26);
    pc($pdf, $y, 'Recomendaciones', $ca['recomendaciones'] ?? '', 305, 248, 26); $y += 15;
    pc($pdf, $y, 'Responsable', trim($ca['apellido'] . ', ' . $ca['nombre']), 42, 248, 28);
    pc($pdf, $y, 'Fecha de intervención', fmt_fecha($ca['fecha_intervencion']), 305, 248, 24); $y += 15;
}

// 6. Consejo de Convivencia.
$y = pt($pdf, $y, '6', 'Informe de Consejo de Convivencia');
$y = pq($pdf, $y, $cc['observaciones'] ?? 'Sin registrar');
if ($cc) {
    pc($pdf, $y, 'Análisis', $cc['analisis'] ?? '', 42, 248, 26);
    pc($pdf, $y, 'Recomendaciones', $cc['recomendaciones'] ?? '', 305, 248, 26); $y += 15;
    pc($pdf, $y, 'Intervención', $cc['intervencion'] ?? '', 42, 248, 26);
    pc($pdf, $y, 'Responsable', trim($cc['apellido'] . ', ' . $cc['nombre']), 305, 248, 26); $y += 15;
    pc($pdf, $y, 'Fecha de intervención', fmt_fecha($cc['fecha_intervencion']), 42, 511.28, 34); $y += 15;
}

// 7. Observaciones.
$y = pt($pdf, $y, '7', 'Observaciones');
if ($observaciones) {
    foreach ($observaciones as $ob) {
        $y = pq($pdf, $y, $ob['observacion'], 2);
        $y = pn($pdf, $y, estado_nombre($ob['estado']) . ' · ' . fmt_fecha($ob['fecha']));
    }
} else {
    $y = pq($pdf, $y, 'Sin observaciones registradas.', 1);
}

// 8. Corresponde.
$y = pt($pdf, $y, '8', 'Se considera que corresponde: (Indicar a continuación)');
$y = pk($pdf, $y, $instancia);
pc($pdf, $y, 'Otra consideración', $clas['otra_consideracion'] ?? '', 42, 511.28, 86); $y += 15;
if ($res) {
    pc($pdf, $y, 'Decisión', $res['decision'] ?? '', 42, 248, 26);
    pc($pdf, $y, 'Medida institucional', $res['medida'] ?? '', 305, 248, 26); $y += 15;
    $y = pq($pdf, $y, $res['fundamentacion'] ?? '', 2);
}
pc($pdf, $y, 'Firma Directivo', $clas ? trim($clas['apellido'] . ', ' . $clas['nombre']) : '', 42, 248, 28);
pc($pdf, $y, 'Fecha', fmt_fecha($clas['fecha'] ?? '', false), 305, 248, 20); $y += 19;

// 9. Notificación: sólo casilleros vacíos.
$y = pt($pdf, $y, '9', 'Notificación:');
pf($pdf, $y, 42, 248, 'Alumno');
pf($pdf, $y, 305, 248.28, 'Padre/Madre/Tutor');
$y = pn($pdf, $y + 37, 'Espacios reservados para la firma y la fecha de notificación.');

// Pie.
$pdf->stroke(...PDF_CLARO);
$pdf->widthLine(0.8);
$pdf->line(42, 800, 553.28, 800);
$pdf->fill(...PDF_MUTED);
$pdf->setFont('T', 7.5);
$pdf->text(42, 811, 'Sistema de Gestión de Informes de Convivencia · E.E.T. N° 35 D.E. 18');
$pdf->textRight(553.28, 811, 'Página 1 / 1');

$pdf->output('informe_' . preg_replace('/[^A-Za-z0-9_-]/', '', $inf['codigo']) . '.pdf');
