<?php
/** Marca como leída UNA notificación del usuario actual (AJAX o formulario). */
require_once __DIR__ . '/../config/app.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    die('Método no permitido.');
}
csrf_check();

global $pdo;
$uid = (int)current_user()['id'];

// app.js envía el header X-CSRF-Token; un formulario normal no lo envía.
$esAjax = isset($_SERVER['HTTP_X_CSRF_TOKEN']);

$idRaw = trim((string)($_POST['id'] ?? ''));
$id = ctype_digit($idRaw) ? (int)$idRaw : 0;
if ($id <= 0) {
    if ($esAjax) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'id inválido';
        exit;
    }
    flash('err', 'La notificación indicada no es válida.');
    redirect('notificaciones/listado.php');
}

// El usuario_id en el WHERE impide marcar notificaciones de otras cuentas.
$st = $pdo->prepare('UPDATE notificaciones SET leida=1 WHERE id=? AND usuario_id=?');
$st->execute([$id, $uid]);

if ($esAjax) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ok';
    exit;
}

if ($st->rowCount() > 0) {
    flash('ok', 'Notificación marcada como leída.');
} else {
    flash('err', 'No se encontró esa notificación entre las tuyas (quizá ya estaba leída).');
}
redirect('notificaciones/listado.php');
