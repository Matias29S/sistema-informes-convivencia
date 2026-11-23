<?php
/** PDF institucional del informe de convivencia (mismo contenido que el acta web). */
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
$st = $pdo->prepare('SELECT n.fecha, us.nombre, us.apellido FROM notificaciones n JOIN usuarios us ON us.id=n.usuario_id WHERE n.enlace=? ORDER BY n.fecha');
$st->execute([$detalleUrl]); $notificaciones = $st->fetchAll();

$alumnoNombre = trim($inf['alumno_apellido'] . ', ' . $inf['alumno_nombre']);
$docenteNombre = trim($inf['docente_apellido'] . ', ' . $inf['docente_nombre']);
$cursoTexto = trim($inf['curso'] . ' ' . ($inf['especialidad'] ? $inf['especialidad'] . ' ' : '') . $inf['division']);
$instanciaNombre = [
    'LEVE' => '1ª LEVE', 'GRAVE' => '2ª GRAVE', 'MUY_GRAVE' => '3ª MUY GRAVE', 'OTRA' => 'OTRA consideración',
][$clas['instancia'] ?? ''] ?? 'Sin registrar';

// ---------- Render PDF ----------
$pdf = new PDF();
$pdf->addPage();
$y = 48.0;

$cortar = function (?string $texto, int $max = 150): string {
    $texto = trim((string)($texto ?? ''));
    return $texto === '' ? 'Sin registrar' : (mb_strlen($texto) > $max ? mb_substr($texto, 0, $max - 1) . '…' : $texto);
};

// Encabezado completo (primera página) / reducido (páginas siguientes).
$encabezado = function (bool $completo) use ($pdf, &$y, $inf, $alumnoNombre): void {
    if ($completo) {
        $pdf->setFont('B', 9);
        $pdf->text(45, 40, 'Gobierno de la Ciudad Autónoma de Buenos Aires');
        $pdf->textRight(550, 40, 'Ministerio de Educación');
        $pdf->line(45, 47, 550, 47);
        $pdf->setFont('B', 11);
        $pdf->textCenter(297, 60, 'E.E.T. N° 35 D.E. 18 "Ing. Eduardo Latzina"');
        $pdf->setFont('B', 14);
        $pdf->textCenter(297, 80, 'INFORME DE CONVIVENCIA');
        $pdf->rect(45, 88, 505, 20);
        $pdf->setFont('B', 9);
        $pdf->text(52, 101, 'INFORME N°: ' . $inf['codigo']);
        $pdf->text(250, 101, 'Alumno/a: ' . mb_substr($alumnoNombre, 0, 34));
        $pdf->textRight(543, 101, 'Estado: ' . mb_substr(estado_nombre($inf['estado']), 0, 26));
        $pdf->line(45, 112, 550, 112);
        $y = 122.0;
    } else {
        $pdf->setFont('B', 8);
        $pdf->text(45, 36, 'INFORME DE CONVIVENCIA · ' . $inf['codigo']);
        $pdf->textRight(550, 36, 'Alumno/a: ' . mb_substr($alumnoNombre, 0, 34));
        $pdf->line(45, 42, 550, 42);
        $y = 54.0;
    }
};
$encabezado(true);

$asegurar = function (float $alto) use ($pdf, &$y, $encabezado): void {
    if ($y + $alto > 760) {
        $pdf->addPage();
        $encabezado(false);
    }
};

$seccion = function (string $nro, string $titulo) use ($pdf, &$y, $asegurar): void {
    $asegurar(22);
    $pdf->setFont('B', 10);
    $pdf->rect(45, $y, 505, 14);
    $pdf->text(51, $y + 9.5, $nro . '. ' . $titulo);
    $y += 21;
};

$campo = function (string $etiqueta, ?string $valor, int $max = 80) use ($pdf, &$y, $asegurar, $cortar): void {
    $asegurar(14);
    $rotulo = $etiqueta . ':';
    $pdf->setFont('B', 9);
    $pdf->text(50, $y, $rotulo);
    $pdf->setFont('N', 9);
    $pdf->text(50 + $pdf->width($rotulo) + 8, $y, $cortar($valor, $max));
    $y += 13;
};

$parrafo = function (?string $texto, float $ancho = 485) use ($pdf, &$y, $asegurar): void {
    $texto = trim((string)($texto ?? ''));
    if ($texto === '') $texto = 'Sin registrar';
    $pdf->setFont('N', 9);
    $alto = count($pdf->wrap($texto, $ancho)) * (9 * 1.35) + 8;
    $asegurar($alto + 14);
    $pdf->rect(48, $y, 499, $alto);
    $pdf->paragraph(54, $y + 5, $ancho, $texto);
    $y += $alto + 12;
};

$casilla = function (bool $marcado, string $texto) use ($pdf, &$y, $asegurar): void {
    $asegurar(14);
    $pdf->rect(52, $y + 1, 8, 8);
    if ($marcado) {
        $pdf->setFont('B', 9);
        $pdf->text(53.5, $y + 7.5, 'X');
    }
    $pdf->setFont('N', 9);
    $pdf->text(67, $y + 8, $texto);
    $y += 13;
};

$firmas = function (string $izquierda, string $derecha) use ($pdf, &$y, $asegurar, $cortar): void {
    $asegurar(34);
    $pdf->line(50, $y + 18, 270, $y + 18);
    $pdf->line(325, $y + 18, 545, $y + 18);
    $pdf->setFont('N', 8);
    $pdf->text(50, $y + 25, $cortar($izquierda, 52));
    $pdf->text(325, $y + 25, $cortar($derecha, 52));
    $y += 34;
};

// 1. Datos del alumno
$seccion('1', 'Datos del/de la alumno/a');
$campo('Alumno/a', $alumnoNombre);
$campo('Año / curso y división', $cursoTexto);
$campo('Legajo', $inf['legajo']);
$campo('DNI', $inf['alumno_dni']);

// 2. Acción y normas transgredidas
$asegurar(20);
$pdf->setFont('N', 9);
$pdf->paragraph(50, $y, 495, 'Se registra la siguiente acción, transgrediendo Normas del Reglamento de Convivencia de la escuela.');
$y += 17;
$parrafo($inf['descripcion']);
$campo('Normas transgredidas', 'Ver detalle a continuación');
$parrafo($inf['normas']);

// 3. Solicitud de sanción y firma docente
$seccion('3', 'Solicitud de sanción');
$campo('Solicitud / categoría', $inf['solicitud_sancion']);
$campo('Docente', $docenteNombre);
$campo('Cargo / función', $inf['cargo']);
$campo('Fecha del incidente', $inf['fecha_incidente']);
$campo('Firma digital', $inf['firma_digital'], 70);
$firmas($docenteNombre, 'Firma del/de la docente');

// 4. Descargo
$seccion('4', 'Descargo del/de la alumno/a');
$parrafo($descargo['descripcion'] ?? '');
$campo('Observaciones', $descargo['observaciones'] ?? '');
$campo('Identificación', trim($alumnoNombre . ' · Legajo ' . $inf['legajo']));
$campo('Fecha de presentación', $descargo['fecha_presentacion'] ?? '');
$firmas($alumnoNombre, $tutores ? trim($tutores[0]['apellido'] . ', ' . $tutores[0]['nombre']) : 'Firma del/de la tutor/a');

// 5. Consejo de Aula
$seccion('5', 'Consejo de Aula');
$parrafo($ca['observaciones'] ?? '');
$campo('Evaluación', $ca['evaluacion'] ?? '');
$campo('Recomendaciones', $ca['recomendaciones'] ?? '');
$campo('Responsable', $ca ? trim($ca['apellido'] . ', ' . $ca['nombre']) : '');
$campo('Fecha de intervención', $ca['fecha_intervencion'] ?? '');
$firmas($ca ? trim($ca['apellido'] . ', ' . $ca['nombre']) : 'Responsable del Consejo de Aula', 'Firma del Consejo de Aula');

// 6. Consejo de Convivencia
$seccion('6', 'Consejo de Convivencia');
$parrafo($cc['observaciones'] ?? '');
$campo('Análisis', $cc['analisis'] ?? '');
$campo('Recomendaciones', $cc['recomendaciones'] ?? '');
$campo('Intervención', $cc['intervencion'] ?? '');
$campo('Responsable', $cc ? trim($cc['apellido'] . ', ' . $cc['nombre']) : '');
$campo('Fecha de intervención', $cc['fecha_intervencion'] ?? '');
$firmas($cc ? trim($cc['apellido'] . ', ' . $cc['nombre']) : 'Responsable del Consejo de Convivencia', 'Firma del Consejo de Convivencia');

// 7. Observaciones
$seccion('7', 'Observaciones');
if (!$observaciones) {
    $parrafo('Sin observaciones registradas.');
} else {
    foreach ($observaciones as $ob) {
        $campo(estado_nombre($ob['estado']), 'Registrado el ' . $ob['fecha']);
        $parrafo($ob['observacion']);
    }
}
if ($res) $campo('Observaciones de la resolución', $res['observaciones'] ?? '');

// 8. Clasificación y resolución
$seccion('8', 'Clasificación y resolución');
$casilla(($clas['instancia'] ?? '') === 'LEVE', '1ª LEVE');
$casilla(($clas['instancia'] ?? '') === 'GRAVE', '2ª GRAVE');
$casilla(($clas['instancia'] ?? '') === 'MUY_GRAVE', '3ª MUY GRAVE');
$casilla(($clas['instancia'] ?? '') === 'OTRA', 'OTRA consideración');
$campo('Otra consideración', $clas['otra_consideracion'] ?? '');
$campo('Clasificación', $instanciaNombre);
$campo('Responsable de la clasificación', $clas ? trim($clas['apellido'] . ', ' . $clas['nombre']) : '');
$campo('Fecha de clasificación', $clas['fecha'] ?? '');
$firmas($clas ? trim($clas['apellido'] . ', ' . $clas['nombre']) : 'Firma del/de la directivo/a', 'Directivo/a');
$campo('Decisión', $res['decision'] ?? '', 85);
$campo('Medida institucional', $res['medida'] ?? '', 85);
$parrafo('Fundamentación: ' . trim((string)($res['fundamentacion'] ?? '')));
$campo('Responsable de la resolución', $res ? trim($res['apellido'] . ', ' . $res['nombre']) : '');
$campo('Fecha de resolución', $res['fecha'] ?? '');
$firmas($res ? trim($res['apellido'] . ', ' . $res['nombre']) : 'Responsable de la resolución', 'Firma del/de la directivo/a');

// 9. Notificación
$seccion('9', 'Notificación');
$campo('Alumno/a', $alumnoNombre);
$nroTutor = 1;
foreach ($tutores as $t) {
    $campo('Tutor/a ' . $nroTutor, trim($t['apellido'] . ', ' . $t['nombre']) . ' (' . $t['relacion'] . ')');
    $nroTutor++;
}
if (!$tutores) $campo('Tutor/a', 'Sin tutores asociados');
$campo('Fecha de creación', $inf['fecha_creacion']);
$campo('Fecha de resolución', $res['fecha'] ?? '');
foreach ($notificaciones as $n) {
    $campo('Notificación', trim($n['apellido'] . ', ' . $n['nombre']) . ' · ' . $n['fecha'], 75);
}
$firmas('Notificado: ' . mb_substr($alumnoNombre, 0, 30), 'Notificado: ' . ($tutores ? mb_substr(trim($tutores[0]['apellido'] . ', ' . $tutores[0]['nombre']), 0, 28) : 'Tutor/a'));

$pdf->setFont('N', 8);
$asegurar(12);
$pdf->text(45, $y + 6, 'Documento generado por el Sistema de Gestión de Informes de Convivencia · E.E.T. N° 35 D.E. 18');

$nombreArchivo = 'informe_' . preg_replace('/[^A-Za-z0-9_-]/', '', $inf['codigo']) . '.pdf';
$pdf->output($nombreArchivo);
