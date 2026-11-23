<?php
/** Descarga segura de adjuntos: el archivo se resuelve desde la BD, nunca desde texto libre. */
require_once __DIR__ . '/../config/app.php';
require_login();
global $pdo;

$u = current_user();
$rol = $u['rol_id'];
$uid = (int)$u['id'];
$codigo = trim((string)($_GET['id'] ?? ''));
$archivo = basename(trim((string)($_GET['archivo'] ?? '')));
if ($codigo === '' || $archivo === '') denegar_acceso('Solicitud incompleta.');

$st = $pdo->prepare('SELECT i.id, i.alumno_id, i.division_id, i.docente_id FROM informes i WHERE i.codigo=?');
$st->execute([mb_substr($codigo, 0, 20)]);
$inf = $st->fetch() ?: null;
if (!$inf) denegar_acceso('El informe solicitado no existe.');

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
if (in_array($rol, ['ADMIN', 'DIRECTIVO'], true)) $puedeVer = true;
elseif ($rol === 'DOCENTE') $puedeVer = $miDocenteId > 0 && (int)$inf['docente_id'] === $miDocenteId;
elseif ($rol === 'PRECEPTOR') {
    $st = $pdo->prepare('SELECT 1 FROM division_preceptor WHERE preceptor_id=? AND division_id=?');
    $st->execute([$miDocenteId, (int)$inf['division_id']]);
    $puedeVer = (bool)$st->fetchColumn();
} elseif ($rol === 'ALUMNO') $puedeVer = $miAlumnoId > 0 && (int)$inf['alumno_id'] === $miAlumnoId;
elseif ($rol === 'TUTOR') {
    $st = $pdo->prepare('SELECT 1 FROM alumno_tutor WHERE tutor_id=? AND alumno_id=?');
    $st->execute([$miTutorId, (int)$inf['alumno_id']]);
    $puedeVer = (bool)$st->fetchColumn();
}
if (!$puedeVer) denegar_acceso('No tenés autorización para descargar este archivo.');

$st = $pdo->prepare('SELECT nombre_original, nombre_guardado, mime, tamano FROM archivos_adjuntos WHERE informe_id=? AND nombre_guardado=?');
$st->execute([(int)$inf['id'], $archivo]);
$adj = $st->fetch() ?: null;
if (!$adj) denegar_acceso('El archivo solicitado no existe.');
$ruta = UPLOAD_DIR . $adj['nombre_guardado'];
if (!is_file($ruta)) denegar_acceso('El archivo no está disponible.');

header('Content-Type: ' . $adj['mime']);
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $adj['nombre_original']) . '"');
header('Content-Length: ' . (int)$adj['tamano']);
header('X-Content-Type-Options: nosniff');
readfile($ruta);
exit;
