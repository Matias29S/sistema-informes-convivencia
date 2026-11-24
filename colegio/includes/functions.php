<?php
/** Funciones auxiliares globales. */

function e($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Zona horaria única del sistema: todas las fechas se guardan y muestran en UTC-3. */
function tz_argentina(): DateTimeZone {
    static $tz = null;
    if ($tz === null) $tz = new DateTimeZone('America/Argentina/Buenos_Aires');
    return $tz;
}

/** Fecha/hora actual en Argentina para escribir en columnas DATETIME. */
function ahora_db(): string {
    return (new DateTimeImmutable('now', tz_argentina()))->format('Y-m-d H:i:s');
}

/**
 * Formatea un DATETIME de MySQL en fecha legible.
 * La zona se fija explícitamente: el valor ya viene en hora Argentina de la BD,
 * así que no dependemos de la configuración de PHP del servidor.
 */
function fmt_fecha($valor, bool $conHora = true): string {
    $s = trim((string)($valor ?? ''));
    if ($s === '' || $s === '0000-00-00 00:00:00') return '—';
    try {
        $dt = new DateTimeImmutable($s, tz_argentina());
        return $dt->format($conHora ? 'd/m/Y H:i' : 'd/m/Y');
    } catch (Exception $e) {
        return e($s);
    }
}

function url(string $path = ''): string {
    return BASE_URL . ltrim($path, '/');
}

/** URL de un asset con versión por filemtime: evita caché vieja al actualizar. */
function asset_url(string $path): string {
    $rel = ltrim($path, '/');
    $v = @filemtime(__DIR__ . '/../assets/' . $rel) ?: 0;
    return url('assets/' . $rel . '?v=' . $v);
}

/** URL del PDF con versión: cambia al editar el generador o el informe. */
function pdf_url(string $codigo, ?string $actualizado = null): string {
    $v = @filemtime(__DIR__ . '/../informes/generar_pdf.php') ?: 0;
    if ($actualizado) {
        $t = strtotime($actualizado);
        if ($t) $v = max($v, $t);
    }
    return url('informes/generar_pdf.php?id=' . rawurlencode($codigo) . '&v=' . $v);
}

function redirect(string $path): void {
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void {
    $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Token de seguridad inválido. Recargá la página e intentá de nuevo.');
    }
}

function flash(string $tipo, string $mensaje): void {
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function flash_get(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function old(string $clave, $default = '') {
    return $_SESSION['old'][$clave] ?? $default;
}

function set_old(array $datos): void {
    $_SESSION['old'] = $datos;
}

function clear_old(): void {
    unset($_SESSION['old']);
}

/** Lee/escribe un valor de la tabla configuracion. */
function cfg(string $clave, $default = ''): string {
    global $pdo;
    static $cache = [];
    if (array_key_exists($clave, $cache)) return $cache[$clave];
    try {
        $st = $pdo->prepare('SELECT valor FROM configuracion WHERE clave=?');
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        $cache[$clave] = $v === false ? $default : (string)$v;
    } catch (Exception $e) {
        $cache[$clave] = $default;
    }
    return $cache[$clave];
}

/** Hash seguro PBKDF2-SHA256 (compatible con los datos de prueba del SQL). */
function hash_password(string $pass, ?string $salt = null): string {
    $salt = $salt ?: bin2hex(random_bytes(16));
    // 128 hex chars = 64 bytes, compatible con los hashes de prueba del SQL.
    $hash = hash_pbkdf2('sha256', $pass, $salt, 120000, 128, false);
    return 'pbkdf2_sha256$120000$' . $salt . '$' . $hash;
}

function verify_password(string $pass, string $stored): bool {
    $p = explode('$', $stored);
    if (count($p) !== 4 || $p[0] !== 'pbkdf2_sha256') return false;
    $len = strlen($p[3]); // compat: 64 o 128 hex chars
    $calc = hash_pbkdf2('sha256', $pass, $p[2], (int)$p[1], $len, false);
    return hash_equals($p[3], $calc);
}

function es_email($v): bool { return (bool)filter_var($v, FILTER_VALIDATE_EMAIL); }
function es_dni($v): bool { return (bool)preg_match('/^\d{7,9}$/', (string)$v); }
function texto($v, int $min = 1, int $max = 5000): ?string {
    $v = trim((string)$v);
    return (mb_strlen($v) >= $min && mb_strlen($v) <= $max) ? $v : null;
}

/** Registro de acciones administrativas y operaciones sensibles. */
function audit(string $accion, ?string $tabla = null, ?string $id = null, ?string $detalle = null): void {
    global $pdo;
    $st = $pdo->prepare('INSERT INTO historial_modificaciones (usuario_id,accion,tabla_afectada,registro_id,detalle,ip) VALUES (?,?,?,?,?,?)');
    $st->execute([
        $_SESSION['user_id'] ?? null, $accion, $tabla, (string)$id, $detalle,
        $_SERVER['REMOTE_ADDR'] ?? 'CLI'
    ]);
}

/** Notificación interna + correo opcional. */
function notify(int $usuarioId, string $titulo, string $descripcion, string $tipo = 'general', ?string $enlace = null, bool $email = true): void {
    global $pdo;
    $st = $pdo->prepare('INSERT INTO notificaciones (usuario_id,titulo,descripcion,tipo,enlace) VALUES (?,?,?,?,?)');
    $st->execute([$usuarioId, $titulo, $descripcion, $tipo, $enlace]);
    if ($email) {
        $u = $pdo->prepare('SELECT email FROM usuarios WHERE id=?');
        $u->execute([$usuarioId]);
        $mail = $u->fetchColumn();
        if ($mail) Mailer::send($mail, $titulo, $descripcion . ($enlace ? "\n\nIngresá al sistema: " . APP_URL . ltrim($enlace, '/') : ''));
    }
}

/** Notifica a todos los usuarios que tienen un permiso. */
function notify_role(string $permiso, string $titulo, string $descripcion, string $tipo, ?string $enlace = null): void {
    global $pdo;
    $st = $pdo->prepare('SELECT DISTINCT u.id FROM usuarios u JOIN rol_permiso rp ON rp.rol_id=u.rol_id WHERE rp.permiso_id=? AND u.activo=1');
    $st->execute([$permiso]);
    foreach ($st->fetchAll() as $u) notify((int)$u['id'], $titulo, $descripcion, $tipo, $enlace, true);
}

/** Genera un usuario legible pero con sufijo aleatorio, garantizando unicidad en BD. */
function generar_username(string $nombre, string $apellido): string {
    global $pdo;
    $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $nombre . $apellido)));
    if ($base === '') $base = 'usr';
    $base = mb_substr($base, 0, 6);
    $st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE username=?');
    do {
        // 8 caracteres hexadecimales aleatorios: difícil de repetir y fácil de leer.
        $u = $base . '.' . bin2hex(random_bytes(4));
        $st->execute([$u]);
    } while ((int)$st->fetchColumn() > 0);
    return $u;
}

/** Contraseña temporal aleatoria (evita caracteres confusos como 0/O, 1/l). */
function generar_clave_temporal(int $longitud = 12): string {
    $grupos = [
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'abcdefghijkmnopqrstuvwxyz',
        '23456789',
        '!@#$%*',
    ];
    $todo = implode('', $grupos);
    $clave = [];
    foreach ($grupos as $g) $clave[] = $g[random_int(0, strlen($g) - 1)];
    while (count($clave) < $longitud) $clave[] = $todo[random_int(0, strlen($todo) - 1)];
    for ($i = count($clave) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$clave[$i], $clave[$j]] = [$clave[$j], $clave[$i]];
    }
    return implode('', $clave);
}

/** Envía usuario y contraseña temporal. La persona puede cambiarla luego desde Mi perfil. */
function enviar_datos_acceso(string $email, string $username, string $clave, string $nombre, string $rol): bool {
    $asunto = 'Tus datos de acceso a ' . APP_NAME;
    $cuerpo = "Hola {$nombre}:\n\n"
        . "Se creó una cuenta para vos con el rol {$rol}.\n\n"
        . "Usuario: {$username}\n"
        . "Contraseña temporal: {$clave}\n\n"
        . "Ingresá en: " . APP_URL . "login.php\n"
        . "Por seguridad, cambiá la contraseña desde «Mi perfil» luego de entrar.\n\n"
        . "Si no esperabas este correo, contactá a administración.\n";
    return Mailer::send($email, $asunto, $cuerpo);
}

function next_codigo_informe(): string {
    global $pdo;
    // Incremento atómico:LAST_INSERT_ID() es seguro incluso con creaciones simultáneas.
    $pdo->prepare("UPDATE contadores SET valor=LAST_INSERT_ID(valor+1) WHERE nombre='informe'")->execute();
    return 'INF-' . str_pad((int)$pdo->lastInsertId(), 4, '0', STR_PAD_LEFT);
}

/** Crea y persiste un token temporal (activación / recuperación). */
function crear_token(int $usuarioId, string $tipo, int $horas = 48): string {
    global $pdo;
    $token = bin2hex(random_bytes(32));
    $st = $pdo->prepare('INSERT INTO tokens (usuario_id,tipo,token_hash,expira) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL ? HOUR))');
    $st->execute([$usuarioId, $tipo, hash('sha256', $token), $horas]);
    return $token;
}

function token_valido(string $token, string $tipo): ?array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM tokens WHERE token_hash=? AND tipo=? AND usado=0 AND expira>NOW()');
    $st->execute([hash('sha256', $token), $tipo]);
    return $st->fetch() ?: null;
}

const ESTADOS_INFORME = [
    'PENDIENTE_REVISION' => 'Pendiente de revisión',
    'EN_REVISION'        => 'En revisión',
    'PENDIENTE_DESCARGO' => 'Pendiente de descargo',
    'PENDIENTE_CONSEJO_AULA' => 'Pendiente de intervención del Consejo de Aula',
    'PENDIENTE_CONSEJO_CONVIVENCIA' => 'Pendiente de intervención del Consejo de Convivencia',
    'PENDIENTE_RESOLUCION' => 'Pendiente de resolución',
    'RESUELTO'           => 'Resuelto',
    'REVISADO'           => 'Revisado por el alumno/a',
    'ANULADO'            => 'Anulado',
];

function estado_nombre(string $k): string { return ESTADOS_INFORME[$k] ?? $k; }

function cambiar_estado(int $informeId, string $estado, ?string $obs = null): void {
    global $pdo;
    $pdo->prepare('UPDATE informes SET estado=? WHERE id=?')->execute([$estado, $informeId]);
    $pdo->prepare('INSERT INTO historial_estados (informe_id,estado,usuario_id,observacion) VALUES (?,?,?,?)')
        ->execute([$informeId, $estado, $_SESSION['user_id'] ?? null, $obs]);
}

/** Subida de archivos con validación estricta. */
function subir_archivo(array $file, ?int $informeId = null): ?string {
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $permitidos = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($permitidos[$mime])) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $nombre = bin2hex(random_bytes(16)) . '.' . $permitidos[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $nombre)) return null;
    global $pdo;
    $pdo->prepare('INSERT INTO archivos_adjuntos (informe_id,nombre_original,nombre_guardado,mime,tamano,subido_por) VALUES (?,?,?,?,?,?)')
        ->execute([$informeId, mb_substr($file['name'], 0, 200), $nombre, $mime, $file['size'], $_SESSION['user_id'] ?? null]);
    return $nombre;
}
