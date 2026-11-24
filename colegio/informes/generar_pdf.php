<?php
/** Acta institucional de convivencia: diseño renovado, una sola hoja A4. */
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
$docenteNombre = trim($inf['docente_nombre'] . ' ' . $inf['docente_apellido']);
$instancia     = $clas['instancia'] ?? '';
$respClas      = $clas ? trim($clas['apellido'] . ', ' . $clas['nombre']) : '';

// ---------- Paleta y lienzo ----------
$ROJO       = [0.616, 0.078, 0.145];  // #9c1424
$ROJO_SUAVE = [0.937, 0.855, 0.871];
$GRIS_CLARO = [0.965, 0.965, 0.965];
$GRIS_LINEA = [0.80, 0.80, 0.80];
$GRIS_TEXTO = [0.22, 0.22, 0.22];
$BLANCO     = [1, 1, 1];

$pdf = new PDF();
$pdf->addPage();
$y = 132.0;
$izq = 42.0;
$der = 553.28;
$ancho = $der - $izq;

/** Recorta con elipsis para no desbordar una línea. */
$cortar = function (?string $texto, int $max = 60): string {
    $texto = trim((string)($texto ?? ''));
    if ($texto === '') return '';
    return mb_strlen($texto) > $max ? mb_substr($texto, 0, $max - 1) . '…' : $texto;
};

// ---------- Encabezado ----------
$pdf->setFill(...$ROJO);
$pdf->filledRect(0, 0, 595.28, 7);
$pdf->image(__DIR__ . '/../assets/img/escudo.png', $izq, 25, 34, 34);
$pdf->setFont('B', 7.6);
$pdf->setText(0.20, 0.20, 0.20);
$pdf->text(84, 30, 'GOBIERNO DE LA CIUDAD AUTÓNOMA DE BUENOS AIRES');
$pdf->text(84, 39, 'MINISTERIO DE EDUCACIÓN');
$pdf->setFont('N', 8);
$pdf->text(84, 48, 'E.E.T. N° 35 D.E. 18 “Ing. Eduardo Latzina”');

$pdf->setFont('B', 14);
$pdf->setText(...$ROJO);
$pdf->textCenter(297.64, 76, 'INFORME DE CONVIVENCIA');
$pdf->setFont('N', 8);
$pdf->setText(0.35, 0.35, 0.35);
$pdf->textCenter(297.64, 89, 'Acta de intervención · ' . $inf['codigo']);

$pdf->setStroke(...$ROJO);
$pdf->setLineWidth(1.1);
$pdf->line($izq, 97, $der, 97);
$pdf->setFill(...$GRIS_CLARO);
$pdf->filledRect($izq, 103, $ancho, 19);
$pdf->setFont('N', 8);
$pdf->setText(...$GRIS_TEXTO);
$pdf->text($izq + 6, 115, 'Alumno/a: ' . $cortar($alumnoNombre, 46));
$pdf->textRight($der - 6, 115, 'Creado: ' . fmt_fecha($inf['fecha_creacion']));

// ---------- Bloques reutilizables ----------
$seccion = function (string $nro, string $titulo) use ($pdf, &$y, $izq, $ancho, $ROJO, $BLANCO): void {
    $pdf->setFill(...$ROJO);
    $pdf->filledRect($izq, $y, $ancho, 14);
    $pdf->setFont('B', 8.6);
    $pdf->setText(...$BLANCO);
    $pdf->text($izq + 6, $y + 9.5, $nro . '.-  ' . $titulo);
    $pdf->setText(0.15, 0.15, 0.15);
    $y += 20;
};

$campo = function (float $x, float $w, string $etiqueta, ?string $valor, int $max = 42)
    use ($pdf, $y, $cortar, $GRIS_LINEA): void {
    $rotulo = $etiqueta . ':';
    $pdf->setFont('B', 8);
    $pdf->text($x, $y, $rotulo);
    $px = $x + $pdf->width($rotulo) + 4;
    $pdf->setFont('N', 8);
    $v = $cortar($valor, $max);
    $pdf->text($px, $y, $v);
    $desde = $px + $pdf->width($v) + 4;
    $fin = min($x + $w, 553.28);
    $pdf->setStroke(...$GRIS_LINEA);
    $pdf->setLineWidth(0.4);
    if ($desde < $fin) $pdf->line($desde, $y + 2, $fin, $y + 2);
};

$campos2 = function (array $a, array $b) use ($campo, &$y): void {
    $campo(42, 245, $a[0], $a[1] ?? '', $a[2] ?? 42);
    $campo(305, 248.28, $b[0], $b[1] ?? '', $b[2] ?? 42);
    $y += 14;
};

$cuadro = function (?string $texto, int $maxLineas = 3)
    use ($pdf, &$y, $izq, $ancho, $GRIS_CLARO, $GRIS_LINEA, $ROJO): void {
    $texto = trim((string)($texto ?? ''));
    $lineas = $texto === '' ? [] : $pdf->wrap($texto, 488);
    if (count($lineas) > $maxLineas) {
        $lineas = array_slice($lineas, 0, $maxLineas);
        $lineas[$maxLineas - 1] = mb_substr($lineas[$maxLineas - 1], 0, 88) . '…';
    }
    $h = max(1, count($lineas)) * 11 + 10;
    $pdf->setFill(...$GRIS_CLARO);
    $pdf->filledRect($izq, $y, $ancho, $h);
    $pdf->setFill(...$ROJO);
    $pdf->filledRect($izq, $y, 3, $h);
    $pdf->setStroke(...$GRIS_LINEA);
    $pdf->setLineWidth(0.4);
    $pdf->rect($izq, $y, $ancho, $h);
    $pdf->setFont('N', 8.5);
    $pdf->setText(0.14, 0.14, 0.14);
    foreach ($lineas as $i => $l) $pdf->text($izq + 10, $y + 9 + $i * 11, $l);
    $y += $h + 8;
};

$firma = function (string $etiqueta) use ($pdf, &$y, $GRIS_LINEA, $cortar): void {
    $pdf->setFont('N', 8);
    $pdf->setText(0.22, 0.22, 0.22);
    $pdf->text(42, $y, $cortar($etiqueta, 48));
    $pdf->setStroke(...$GRIS_LINEA);
    $pdf->setLineWidth(0.4);
    $pdf->line(252, $y + 2, 400, $y + 2);
    $pdf->text(406, $y, 'Fecha:');
    $pdf->line(442, $y + 2, 553.28, $y + 2);
    $y += 16;
};

$casillas = function () use ($pdf, &$y, $instancia, $GRIS_LINEA): void {
    $opciones = [
        [42, 'LEVE', '1ª instancia · LEVE'],
        [212, 'GRAVE', '2ª instancia · GRAVE'],
        [382, 'MUY_GRAVE', '3ª instancia · MUY GRAVE'],
    ];
    $pdf->setFont('N', 8);
    foreach ($opciones as [$x, $clave, $texto]) {
        $pdf->setStroke(...$GRIS_LINEA);
        $pdf->setLineWidth(0.5);
        $pdf->rect($x, $y + 1, 9, 9);
        if ($instancia === $clave) {
            $pdf->setFont('B', 8);
            $pdf->text($x + 2, $y + 8, 'X');
            $pdf->setFont('N', 8);
        }
        $pdf->text($x + 13, $y + 8, $texto);
    }
    $y += 14;
};

// ---------- 1. El alumno/a ----------
$seccion('1', 'El alumno/a');
$campos2(['Apellido y nombre', $alumnoNombre, 42], ['DNI', $inf['alumno_dni'], 30]);
$campos2(['Año / curso', $inf['curso'], 32], ['División', $inf['division'], 32]);
$campos2(['Legajo', $inf['legajo'], 32], ['Fecha del incidente', fmt_fecha($inf['fecha_incidente']), 28]);
$y += 2;

// ---------- 2. Acción ----------
$seccion('2', 'Ha realizado la acción que se describe a continuación:');
$cuadro($inf['descripcion'], 3);
$campo(42, 511.28, 'Normas transgredidas', $inf['normas'], 96);
$y += 16;

// ---------- 3. Solicitud de sanción ----------
$seccion('3', 'Solicitud de sanción');
$campos2(['Docente', $docenteNombre, 40], ['Cargo / función', $inf['cargo'], 34]);
$campo(42, 511.28, 'Solicitud / categoría', $inf['solicitud_sancion'], 92);
$y += 16;

// ---------- 4. Descargo ----------
$seccion('4', 'Descargo del alumno/a');
$cuadro($descargo['descripcion'] ?? '', 3);
$firma('Alumno/a: ' . $alumnoNombre);
$y += 2;

// ---------- 5. Consejo de Aula ----------
$seccion('5', 'Informe de Consejo de Aula');
$cuadro($ca['observaciones'] ?? '', 3);
$firma('Responsable: ' . ($ca ? trim($ca['apellido'] . ', ' . $ca['nombre']) : ''));
$y += 2;

// ---------- 6. Consejo de Convivencia ----------
$seccion('6', 'Informe de Consejo de Convivencia');
$cuadro($cc['observaciones'] ?? '', 3);
$firma('Responsable: ' . ($cc ? trim($cc['apellido'] . ', ' . $cc['nombre']) : ''));
$y += 2;

// ---------- 7. Observaciones ----------
$seccion('7', 'Observaciones');
$obsTexto = $observaciones
    ? implode(' · ', array_map(fn($o) => estado_nombre($o['estado']) . ' (' . fmt_fecha($o['fecha']) . '): ' . $o['observacion'], $observaciones))
    : '';
$cuadro($obsTexto, 3);

// ---------- 8. Corresponde ----------
$seccion('8', 'Se considera que corresponde');
$casillas();
$campo(42, 511.28, 'Otra consideración', $clas['otra_consideracion'] ?? '', 92);
$y += 16;
$firma('Firma directivo: ' . $respClas);
$y += 2;

// ---------- 9. Notificación (sólo firmas) ----------
$seccion('9', 'Notificación');
$altas = 42;
foreach ([[42, 248, 'Alumno/a'], [305, 248.28, 'Padre/Madre/Tutor']] as [$x, $w, $titulo]) {
    $pdf->setFill(...$GRIS_CLARO);
    $pdf->filledRect($x, $y, $w, $altas);
    $pdf->setFill(...$ROJO_SUAVE);
    $pdf->filledRect($x, $y, $w, 13);
    $pdf->setFont('B', 8);
    $pdf->setText(0.35, 0.05, 0.09);
    $pdf->text($x + 5, $y + 9, $titulo);
    $pdf->setFont('N', 7.6);
    $pdf->setText(0.30, 0.30, 0.30);
    $pdf->text($x + 5, $y + 25, 'Firma:');
    $pdf->text($x + 5, $y + 36, 'Fecha:');
    $pdf->setStroke(...$GRIS_LINEA);
    $pdf->setLineWidth(0.4);
    $pdf->line($x + 38, $y + 27, $x + $w - 6, $y + 27);
    $pdf->line($x + 38, $y + 38, $x + $w - 6, $y + 38);
    $pdf->rect($x, $y, $w, $altas);
}
$y += $altas + 4;

// ---------- Pie ----------
$pdf->setStroke(...$ROJO);
$pdf->setLineWidth(0.8);
$pdf->line($izq, 806, $der, 806);
$pdf->setFont('N', 7);
$pdf->setText(0.35, 0.35, 0.35);
$pdf->text($izq, 817, 'Sistema de Gestión de Informes de Convivencia · E.E.T. N° 35 D.E. 18');
$pdf->textRight($der, 817, 'Página 1 / 1');

$nombreArchivo = 'informe_' . preg_replace('/[^A-Za-z0-9_-]/', '', $inf['codigo']) . '.pdf';
$pdf->output($nombreArchivo);
