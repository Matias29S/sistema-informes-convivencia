<?php
/** Autenticación, sesiones y control de acceso (permisos verificados en servidor). */

function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    static $cache = null;
    if (is_array($cache) && (int)$cache['id'] === (int)$_SESSION['user_id']) return $cache;
    global $pdo;
    $st = $pdo->prepare('SELECT u.*, r.nombre AS rol_nombre FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?');
    $st->execute([$_SESSION['user_id']]);
    $cache = $st->fetch() ?: null;
    return $cache;
}

function require_login(): void {
    if (!current_user()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? url('dashboard.php');
        redirect('login.php');
    }
}

function es_rol(string ...$roles): bool {
    $u = current_user();
    return $u && in_array($u['rol_id'], $roles, true);
}

function require_role(string ...$roles): void {
    require_login();
    if (!es_rol(...$roles)) denegar_acceso('No tenés permiso para acceder a esta sección.');
}

function has_permission(string $permiso): bool {
    global $pdo;
    static $cache = [];
    if (array_key_exists($permiso, $cache)) return $cache[$permiso];
    $u = current_user();
    if (!$u) return $cache[$permiso] = false;
    if ($u['rol_id'] === 'ADMIN') return $cache[$permiso] = true;
    $st = $pdo->prepare('SELECT 1 FROM rol_permiso WHERE rol_id=? AND permiso_id=?');
    $st->execute([$u['rol_id'], $permiso]);
    return $cache[$permiso] = (bool)$st->fetchColumn();
}

function require_permission(string $permiso): void {
    require_login();
    if (!has_permission($permiso)) denegar_acceso('No tenés el permiso requerido: ' . e($permiso));
}

/** Intenta iniciar sesión. Devuelve [bool, string]. */
function intentar_login(string $usuario, string $clave): array {
    global $pdo;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    $usuario = trim($usuario);

    // Control de intentos por IP en los últimos 15 minutos.
    $st = $pdo->prepare('SELECT COUNT(*) FROM intentos_login WHERE ip=? AND exitoso=0 AND fecha>DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 20) return [false, 'Demasiados intentos. Esperá unos minutos.'];

    $st = $pdo->prepare('SELECT * FROM usuarios WHERE email=? OR username=? LIMIT 1');
    $st->execute([$usuario, $usuario]);
    $u = $st->fetch();

    if (!$u || !verify_password($clave, $u['password_hash'])) {
        $pdo->prepare('INSERT INTO intentos_login (email,ip,exitoso) VALUES (?,?,0)')->execute([$usuario, $ip]);
        if ($u) {
            $n = (int)$u['intentos'] + 1;
            $bloqueo = $n >= 5 ? (new DateTimeImmutable('+15 minutes', tz_argentina()))->format('Y-m-d H:i:s') : null;
            $pdo->prepare('UPDATE usuarios SET intentos=?, bloqueado_hasta=? WHERE id=?')->execute([$n, $bloqueo, $u['id']]);
            if ($bloqueo) return [false, 'Cuenta bloqueada por 15 minutos por intentos fallidos.'];
        }
        return [false, 'Correo/usuario o contraseña incorrectos.'];
    }
    if (!$u['activo']) return [false, 'La cuenta está desactivada. Contactá a administración.'];
    if (!$u['verificado']) return [false, 'La cuenta aún no fue activada. Revisá tu correo.'];
    if (!empty($u['bloqueado_hasta']) && strtotime($u['bloqueado_hasta']) > time()) return [false, 'Cuenta bloqueada temporalmente.'];

    $pdo->prepare('UPDATE usuarios SET intentos=0, bloqueado_hasta=NULL, ultimo_acceso=NOW() WHERE id=?')->execute([$u['id']]);
    $pdo->prepare('INSERT INTO intentos_login (email,ip,exitoso) VALUES (?,?,1)')->execute([$u['email'], $ip]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    return [true, ''];
}

function cerrar_sesion(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
