<?php
/** PDF del informe de convivencia con el formato del modelo institucional (docx). */
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
if (!$puedeVer) denegar_acceso('No tenés autorización para generar el PDF de este informe.');

$informeId = (int)$inf['id'];
$detalleUrl = 'informes/detalle.php?id=' . rawurlencode($inf['codigo']);

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
$st = $pdo->prepare('SELECT us.nombre, us.apellido, at.relacion FROM alumno_tutor at JOIN tutores t ON t.id=at.tutor_id JOIN usuarios us ON us.id=t.usuario_id WHERE at.alumno_id=? ORDER BY us.apellido');
$st->execute([(int)$inf['alumno_id']]); $tutores = $st->fetchAll();

$alumnoNombre = trim($inf['alumno_nombre'] . ' ' . $inf['alumno_apellido']);
$docenteNombre = trim($inf['docente_nombre'] . ' ' . $inf['docente_apellido']);
$instancia = $clas['instancia'] ?? '';
$responsableClas = $clas ? trim($clas['apellido'] . ', ' . $clas['nombre']) : '';

// ---------- Render PDF ----------
$pdf = new PDF();
$pdf->addPage();
$IZQ = 55.0; $DER = 540.0; $ANCHO = $DER - $IZQ;
$y = 52.0;

$cortar = function (?string $texto, int $max = 90): string {
    $texto = trim((string)($texto ?? ''));
    if ($texto === '') return 'Sin registrar';
    return mb_strlen($texto) > $max ? mb_substr($texto, 0, $max - 1) . '…' : $texto;
};
$valor = function (?string $texto): string {
    $texto = trim((string)($texto ?? ''));
    return $texto === '' ? '........................................' : $texto;
};
$esLogo = $pdf->image(__DIR__ . '/../assets/img/escudo.png', 267, 32, 60, 60);

// Encabezado institucional (primera página / páginas siguientes).
$encabezado = function (bool $completo) use ($pdf, &$y, $inf, $alumnoNombre, $esLogo): void {
    if ($completo) {
        $pdf->setFont('B', 10);
        $pdf->textCenter(297, 110, 'GOBIERNO DE LA CIUDAD AUTÓNOMA DE BUENOS AIRES');
        $pdf->textCenter(297, 124, 'MINISTERIO DE EDUCACIÓN');
        $pdf->textCenter(297, 138, 'E.E.T. N° 35 D.E. 18 “Ing. Eduardo Latzina”');
        $pdf->setFont('B', 15);
        $pdf->textCenter(297, 163, 'INFORME DE CONVIVENCIA');
        $pdf->line(55, 172, 540, 172);
        $pdf->setFont('N', 9);
        $pdf->text(55, 186, 'Informe N° ' . $inf['codigo']);
        $pdf->textRight(540, 186, 'Creado el ' . fmt_fecha($inf['fecha_creacion']));
        $y = 205.0;
    } else {
        $pdf->setFont('B', 9);
        $pdf->text(55, 40, 'INFORME DE CONVIVENCIA · ' . $inf['codigo']);
        $pdf->textRight(540, 40, $alumnoNombre);
        $pdf->line(55, 46, 540, 46);
        $y = 60.0;
    }
};
$encabezado(true);

$asegurar = function (float $alto) use ($pdf, &$y, $encabezado): void {
    if ($y + $alto > 770) {
        $pdf->addPage();
        $encabezado(false);
    }
};

/** Campo con línea de relleno, similar al modelo impreso. */
$campo = function (string $etiqueta, ?string $texto, float $anchoLinea = 0) use ($pdf, &$y, $asegurar, $cortar): void {
    $asegurar(18);
    $pdf->setFont('N', 10);
    $rotulo = $etiqueta . ': ';
    $pdf->text(55, $y, $rotulo);
    $x = 55 + $pdf->width($rotulo);
    $texto = $cortar($texto, 95);
    $pdf->text($x, $y, $texto);
    $fin = $x + $pdf->width($texto) + 12;
    $hasta = $anchoLinea > 0 ? min(540.0, $x + $anchoLinea) : 540.0;
    if ($fin < $hasta) $pdf->line($fin, $y + 2, $hasta, $y + 2);
    $y += 16;
};

/** Texto libre dentro de un recuadro. */
$cuadro = function (?string $texto, float $alto = 58) use ($pdf, &$y, $asegurar): void {
    $texto = trim((string)($texto ?? ''));
    if ($texto === '') $texto = '............................................................................................................................................................................................................................................................';
    $pdf->setFont('N', 10);
    $lineas = $pdf->wrap($texto, 465);
    $alto = max($alto, count($lineas) * 15 + 16);
    $asegurar($alto + 8);
    $pdf->rect(55, $y, 485, $alto);
    $pdf->paragraph(63, $y + 10, 465, $texto, 15);
    $y += $alto + 12;
};

/** Preámbulo numerado del modelo. */
$numeral = function (string $texto) use ($pdf, &$y, $asegurar): void {
    $asegurar(20);
    $pdf->setFont('N', 10);
    $pdf->paragraph(55, $y, 485, $texto, 15);
    $y += count($pdf->wrap($texto, 485)) * 15 + 8;
};

$casilla = function (bool $marcado, string $texto) use ($pdf, &$y, $asegurar): void {
    $asegurar(17);
    $pdf->rect(58, $y + 2, 9, 9);
    if ($marcado) {
        $pdf->setFont('B', 10);
        $pdf->text(59.5, $y + 9.5, 'X');
    }
    $pdf->setFont('N', 10);
    $pdf->text(76, $y + 10, $texto);
    $y += 17;
};

$firma = function (string $etiqueta, string $nombre = '', string $fecha = '') use ($pdf, &$y, $asegurar, $cortar): void {
    $asegurar(42);
    $pdf->setFont('N', 9);
    $pdf->text(55, $y + 8, $etiqueta . ': ' . $cortar($nombre, 55));
    $pdf->line(55, $y + 24, 285, $y + 24);
    $pdf->line(310, $y + 24, 540, $y + 24);
    $pdf->text(55, $y + 31, 'Firma');
    $pdf->text(310, $y + 31, 'Fecha: ' . $fecha);
    $y += 42;
};

// 1.- El alumno/a
$numeral('1.- El alumno/a:');
$campo('Apellido y nombre', $alumnoNombre, 260);
$campo('DNI', $inf['alumno_dni'], 80);
$campo('Legajo', $inf['legajo'], 80);
$campo('Año', $inf['curso'], 90);
$campo('División', $inf['division'], 90);

// 2.- Acción
$numeral('2.- Ha realizado la acción que se describe a continuación:');
$cuadro($inf['descripcion'], 90);
$numeral('transgrediendo Normas del Reglamento de Convivencia de la escuela.');
$campo('Normas transgredidas', $inf['normas']);

// 3.- Solicitud de sanción
$numeral('3.- Solicitud de sanción:');
$campo('Docente', $docenteNombre);
$campo('Cargo / función', $inf['cargo']);
$campo('Fecha del incidente', fmt_fecha($inf['fecha_incidente']));
$campo('Firma digital', $inf['firma_digital'], 180);
$firma('Solicitante', $docenteNombre, fmt_fecha($inf['fecha_incidente'], false));

// 4.- Descargo
$numeral('4.- Descargo del Alumno/a:');
$cuadro($descargo['descripcion'] ?? '', 72);
$campo('Observaciones', $descargo['observaciones'] ?? '');
$campo('Identificación', trim($alumnoNombre . ' · Legajo ' . $inf['legajo']));
$firma('Alumno/a', $alumnoNombre, $descargo ? fmt_fecha($descargo['fecha_presentacion'], false) : '');

// 5.- Consejo de Aula
$numeral('5.- Informe de Consejo de Aula');
$cuadro($ca['observaciones'] ?? '', 72);
$campo('Evaluación', $ca['evaluacion'] ?? '');
$campo('Recomendaciones', $ca['recomendaciones'] ?? '');
$firma('Responsable', $ca ? trim($ca['apellido'] . ', ' . $ca['nombre']) : '', $ca ? fmt_fecha($ca['fecha_intervencion'], false) : '');

// 6.- Consejo de Convivencia
$numeral('6.- Informe de Consejo de Convivencia');
$cuadro($cc['observaciones'] ?? '', 72);
$campo('Análisis', $cc['analisis'] ?? '');
$campo('Recomendaciones', $cc['recomendaciones'] ?? '');
$campo('Intervención', $cc['intervencion'] ?? '');
$firma('Responsable', $cc ? trim($cc['apellido'] . ', ' . $cc['nombre']) : '', $cc ? fmt_fecha($cc['fecha_intervencion'], false) : '');

// 7.- Observaciones
$numeral('7.- Observaciones');
if (!$observaciones) {
    $cuadro('', 50);
} else {
    foreach ($observaciones as $ob) {
        $campo(estado_nombre($ob['estado']), fmt_fecha($ob['fecha']) . ' · ' . $ob['observacion']);
    }
}

// 8.- Corresponde
$numeral('8.- Se considera que corresponde: (Indicar a continuación)');
$casilla($instancia === 'LEVE', '1ª Instancia LEVE');
$casilla($instancia === 'GRAVE', '2ª Instancia GRAVE');
$casilla($instancia === 'MUY_GRAVE', '3ª Instancia MUY GRAVE');
$campo('Otra consideración', $clas['otra_consideracion'] ?? '');
if ($res) {
    $campo('Decisión', $res['decision'] ?? '');
    $campo('Medida institucional', $res['medida'] ?? '');
    $cuadro('Fundamentación: ' . trim((string)($res['fundamentacion'] ?? '')), 55);
}
$firma('Firma Directivo', $responsableClas, $clas ? fmt_fecha($clas['fecha'], false) : '');

// 9.- Notificación
$numeral('9.- Notificación:');
$asegurar(30);
$pdf->setFont('B', 9);
$pdf->text(55, $y, 'Alumno');
$pdf->text(310, $y, 'Fecha');
$pdf->line(55, $y + 4, 540, $y + 4);
$y += 10;
$pdf->setFont('N', 9);
$pdf->text(55, $y + 6, $cortar($alumnoNombre, 60));
$pdf->text(310, $y + 6, !empty($inf['revisado']) ? fmt_fecha($inf['fecha_revision'], false) : 'Pendiente');
$pdf->line(55, $y + 10, 540, $y + 10);
$y += 24;

$asegurar(30);
$pdf->setFont('B', 9);
$pdf->text(55, $y, 'Padre/Madre/Tutor');
$pdf->text(310, $y, 'Fecha');
$pdf->line(55, $y + 4, 540, $y + 4);
$y += 10;
$pdf->setFont('N', 9);
$filasTutor = $tutores ?: [['apellido' => '', 'nombre' => 'Sin tutores asociados', 'relacion' => '']];
foreach ($filasTutor as $t) {
    $nombreTutor = trim($t['apellido'] . ', ' . $t['nombre'] . ($t['relacion'] ? ' (' . $t['relacion'] . ')' : ''));
    $pdf->text(55, $y + 6, $cortar($nombreTutor, 58));
    $pdf->text(310, $y + 6, fmt_fecha($inf['fecha_creacion'], false));
    $pdf->line(55, $y + 10, 540, $y + 10);
    $y += 20;
}
$y += 10;

$pdf->setFont('N', 8);
$asegurar(12);
$pdf->text(55, $y + 6, 'Documento generado por el Sistema de Gestión de Informes de Convivencia · E.E.T. N° 35 D.E. 18');

$nombreArchivo = 'informe_' . preg_replace('/[^A-Za-z0-9_-]/', '', $inf['codigo']) . '.pdf';
$pdf->output($nombreArchivo);
