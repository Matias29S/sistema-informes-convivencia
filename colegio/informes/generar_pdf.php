<?php
/** Acta de convivencia en PDF: modelo de formulario con campos punteados, en una hoja A4. */
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

$alumno  = trim($inf['alumno_nombre'] . ' ' . $inf['alumno_apellido']);
$docente = trim($inf['docente_apellido'] . ', ' . $inf['docente_nombre']);
$instancia = $clas['instancia'] ?? '';
$directivo = $clas ? trim($clas['apellido'] . ', ' . $clas['nombre']) : '';

// ---------- Dibujo del formulario ----------
const PDF_ROJO  = [0.616, 0.078, 0.145];
const PDF_CLARO = [0.984, 0.918, 0.929];
const PDF_TEXTO = [0.120, 0.120, 0.120];
const PDF_GRIS  = [0.380, 0.380, 0.380];

/** Título de sección: "1.-  Texto". */
function pt(PDF $p, float $y, string $nro, string $titulo): float {
    $p->fill(...PDF_TEXTO);
    $p->setFont('T', 9.5);
    $p->text(42, $y, $nro . '.-');
    $p->text(58, $y, $titulo);
    return $y + 18;
}

/** Texto + puntos guía hasta completar el ancho. */
function pd(PDF $p, float $y, float $x, string $texto, float $ancho, bool $negrita = false): void {
    $p->fill(...PDF_TEXTO);
    $p->setFont($negrita ? 'B' : 'T', 9);
    $txt = trim($texto);
    if ($txt !== '' && $p->width($txt) > $ancho - 14) {
        while ($txt !== '' && $p->width($txt . '…') > $ancho - 14) $txt = mb_substr($txt, 0, -1);
        $txt .= '…';
    }
    if ($txt !== '') $p->text($x, $y, $txt);
    $cursor = $x + ($txt === '' ? 0 : $p->width($txt) + 5);
    $p->setFont('T', 8.5);
    $paso = $p->width('· ');
    $cant = max(0, (int)floor(($x + $ancho - $cursor) / $paso));
    if ($cant > 0) $p->text($cursor, $y, str_repeat('· ', $cant));
}

/** Área de texto: hasta $lineas renglones punteados, con el valor escrito arriba. */
function pl(PDF $p, float $y, string $texto, int $lineas = 4, float $inter = 16): float {
    $lineasTexto = $texto === '' ? [] : array_slice($p->wrap($texto, 480), 0, $lineas);
    for ($i = 0; $i < $lineas; $i++) {
        pd($p, $y + $i * $inter, 42, $lineasTexto[$i] ?? '', 511.28);
    }
    return $y + $lineas * $inter;
}

function pcheck(PDF $p, float $y, float $x, string $texto, bool $marcado): void {
    $p->stroke(...PDF_TEXTO);
    $p->widthLine(0.7);
    $p->rect($x, $y + 1, 9, 9);
    if ($marcado) {
        $p->fill(...PDF_ROJO);
        $p->setFont('B', 9);
        $p->text($x + 2, $y + 9, 'X');
    }
    $p->fill(...PDF_TEXTO);
    $p->setFont('T', 9.5);
    $p->text($x + 16, $y + 9, $texto);
}

$pdf = new PDF();
$pdf->addPage();

// Encabezado institucional.
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

// 1.- El alumno/a.
$y = pt($pdf, $y, '1', 'El alumno/a:');
pd($pdf, $y, 42, $alumno, 244);
pd($pdf, $y, 296, 'de ' . $inf['curso'] . ' año,', 96);
pd($pdf, $y, 402, 'División ' . $inf['division'], 151.28);
$y += 18;

// 2.- Acción.
$y = pt($pdf, $y, '2', 'Ha realizado la acción que se describe a continuación:');
$y = pl($pdf, $y, (string)($inf['descripcion'] ?? ''), 4);
pd($pdf, $y, 42, 'transgrediendo Normas del Reglamento de Convivencia de la escuela.', 511.28);
$y += 20;

// 3.- Solicitud de sanción.
$y = pt($pdf, $y, '3', 'Solicitud de sanción:');
pd($pdf, $y, 42, 'Docente: ' . $docente, 138);
pd($pdf, $y, 182, 'Cargo/función: ' . ($inf['cargo'] ?? ''), 140);
pd($pdf, $y, 326, 'Fecha: ' . fmt_fecha($inf['fecha_incidente'], false), 82);
pd($pdf, $y, 412, 'Firma: ' . ($inf['firma_digital'] ?? ''), 141.28);
$y += 20;

// 4.- Descargo.
$y = pt($pdf, $y, '4', 'Descargo del Alumno/a:');
$y = pl($pdf, $y, (string)($descargo['descripcion'] ?? ''), 3);
$y += 6;

// 5.- Consejo de Aula.
$y = pt($pdf, $y, '5', 'Informe de Consejo de Aula');
$y = pl($pdf, $y, (string)($ca['observaciones'] ?? ''), 2);
$y += 6;

// 6.- Consejo de Convivencia.
$y = pt($pdf, $y, '6', 'Informe de Consejo de Convivencia');
$y = pl($pdf, $y, (string)($cc['observaciones'] ?? ''), 2);
$y += 6;

// 7.- Observaciones.
$y = pt($pdf, $y, '7', 'Observaciones');
$y = pl($pdf, $y, '', 2);

// 8.- Corresponde.
$y = pt($pdf, $y, '8', 'Se considera que corresponde: (Indicar a continuación)');
pcheck($pdf, $y, 42, '1ª Instancia  LEVE', $instancia === 'LEVE');
pcheck($pdf, $y + 17, 42, '2ª Instancia  GRAVE', $instancia === 'GRAVE');
pcheck($pdf, $y + 34, 42, '3ª Instancia  MUY GRAVE', $instancia === 'MUY_GRAVE');
$y += 62;
pd($pdf, $y, 42, 'Otra consideración: ' . ($clas['otra_consideracion'] ?? ''), 511.28);
$y += 20;
pd($pdf, $y, 42, 'Firma Directivo: ' . $directivo, 300);
$fechaDirectivo = !empty($clas['fecha']) ? 'Fecha: ' . fmt_fecha($clas['fecha'], false) : 'Fecha: ';
pd($pdf, $y, 352, $fechaDirectivo, 201.28);
$y += 22;

// 9.- Notificación: sólo los espacios para firma y fecha.
$y = pt($pdf, $y, '9', 'Notificación:');
pd($pdf, $y, 42, 'Alumno: ', 300); $y += 18;
pd($pdf, $y, 42, 'Fecha: ', 200); $y += 18;
pd($pdf, $y, 42, 'Padre/Madre/Tutor: ', 320); $y += 18;
pd($pdf, $y, 42, 'Fecha: ', 200);

// Pie.
$pdf->stroke(...PDF_CLARO);
$pdf->widthLine(0.8);
$pdf->line(42, 800, 553.28, 800);
$pdf->fill(...PDF_GRIS);
$pdf->setFont('T', 7.5);
$pdf->text(42, 811, 'Sistema de Gestión de Informes de Convivencia · E.E.T. N° 35 D.E. 18');
$pdf->textRight(553.28, 811, 'Página 1 / 1');

$pdf->output('informe_' . preg_replace('/[^A-Za-z0-9_-]/', '', $inf['codigo']) . '.pdf');
