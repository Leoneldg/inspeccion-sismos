<?php
/**
 * Autenticación y control de acceso basado en roles y módulos (RBAC).
 */
require_once __DIR__ . '/db.php';

const MAX_INTENTOS_LOGIN = 5;
const BLOQUEO_MINUTOS    = 10;

/** Devuelve true si hay un usuario autenticado en la sesión. */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

/** Obliga a que exista sesión activa; de lo contrario redirige al login. */
/**
 * ¿La petición actual es AJAX / espera JSON?
 * Sirve para no devolver HTML de login a una llamada de JavaScript.
 */
function esPeticionAjax(): bool
{
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') return true;
    if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) return true;
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) return true;
    // Endpoints del sistema que siempre responden JSON.
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return (bool)preg_match('/^(guardar_|listar_|buscar_|subir_|calcular_|asignar_|api_|arbol_|puntos_|ficha_)/', $script);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        // A las peticiones AJAX se les responde JSON, no HTML de login:
        // así el usuario ve un mensaje claro y no pierde el trabajo en silencio.
        if (esPeticionAjax()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'sesion_expirada' => true,
                'mensaje' => 'Su sesión expiró. Abra el sistema en otra pestaña, inicie sesión y vuelva a intentarlo (no cierre esta ventana para no perder lo que lleva).',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . APP_URL_BASE . 'login.php?next=' . $redirect);
        exit;
    }
}

/**
 * Carga (y cachea en sesión) la matriz de permisos del rol actual:
 * [ 'formulario' => ['ver'=>1,'crear'=>1,'editar'=>1,'eliminar'=>0], ... ]
 */
function permisosUsuario(): array
{
    if (!isLoggedIn()) {
        return [];
    }
    // Caché de permisos en sesión. Se invalida automáticamente para CUALQUIER
    // usuario cuando aparecen módulos nuevos que no están en la caché (p. ej.
    // tras instalar el módulo de Seguimiento), sin necesidad de re-login.
    if (isset($_SESSION['permisos'])) {
        $cached = $_SESSION['permisos'];
        $keys = array_keys($cached);
        $hayModulosNuevos = false;
        if (count($keys) > 0) {
            $placeholders = implode(', ', array_fill(0, count($keys), '?'));
            $stmt = db()->prepare("SELECT COUNT(*) AS cnt FROM modulos WHERE clave NOT IN ($placeholders)");
            $stmt->execute($keys);
            $row = $stmt->fetch();
            $hayModulosNuevos = $row && (int)$row['cnt'] > 0;
        } else {
            $hayModulosNuevos = true; // caché vacía: recargar
        }
        if ($hayModulosNuevos) {
            unset($_SESSION['permisos']); // recargar desde BD abajo
        } else {
            return $cached;
        }
    }

    $stmt = db()->prepare(
        'SELECT m.clave, p.ver, p.crear, p.editar, p.eliminar
         FROM rol_modulo_permisos p
         JOIN modulos m ON m.id = p.modulo_id
         WHERE p.rol_id = :rol_id'
    );
    $stmt->execute(['rol_id' => $_SESSION['rol_id']]);

    $permisos = [];
    foreach ($stmt->fetchAll() as $row) {
        $permisos[$row['clave']] = [
            'ver'      => (bool)$row['ver'],
            'crear'    => (bool)$row['crear'],
            'editar'   => (bool)$row['editar'],
            'eliminar' => (bool)$row['eliminar'],
        ];
    }
    // Si el usuario es Administrador, asegurar que tenga permisos para todos los módulos
    if (!empty($_SESSION['rol_nombre']) && $_SESSION['rol_nombre'] === 'Administrador') {
        $mods = db()->query('SELECT clave FROM modulos')->fetchAll();
        foreach ($mods as $m) {
            $k = $m['clave'];
            if (!isset($permisos[$k])) {
                $permisos[$k] = ['ver' => true, 'crear' => true, 'editar' => true, 'eliminar' => true];
            }
        }
    }
    $_SESSION['permisos'] = $permisos;
    return $permisos;
}

/** Verifica si el usuario actual tiene una acción concreta sobre un módulo. */
function puede(string $modulo, string $accion = 'ver'): bool
{
    $permisos = permisosUsuario();
    return !empty($permisos[$modulo][$accion]);
}

/**
 * Exige permiso sobre un módulo/acción; si no lo tiene, muestra 403.
 */
function requierePermiso(string $modulo, string $accion = 'ver'): void
{
    requireLogin();
    if (!puede($modulo, $accion)) {
        if (esPeticionAjax()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'mensaje' => 'No tiene permiso para realizar esta acción.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(403);
        include __DIR__ . '/../403.php';
        exit;
    }
}

/** Intenta autenticar. Devuelve true/false. Incluye control de fuerza bruta simple. */
function intentarLogin(string $usuario, string $password): array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.nombre_completo, u.usuario, u.password_hash, u.activo,
                u.rol_id, r.nombre AS rol_nombre,
                u.es_master, u.estado_asignado, u.ente_id,
                e.tipo AS ente_tipo, e.estado AS ente_estado, e.nombre AS ente_nombre
         FROM usuarios u
         JOIN roles r ON r.id = u.rol_id
         LEFT JOIN entes e ON e.id = u.ente_id
         WHERE u.usuario = :usuario1 OR u.email = :usuario2
         LIMIT 1'
    );
    $stmt->execute(['usuario1' => $usuario, 'usuario2' => $usuario]);
    $user = $stmt->fetch();

    $intentosKey = 'login_attempts_' . md5(strtolower($usuario));
    $intentos = $_SESSION[$intentosKey] ?? ['count' => 0, 'time' => time()];

    if ($intentos['count'] >= MAX_INTENTOS_LOGIN && (time() - $intentos['time']) < BLOQUEO_MINUTOS * 60) {
        return [false, 'Demasiados intentos fallidos. Intente nuevamente en unos minutos.'];
    }

    if (!$user || !$user['activo'] || !password_verify($password, $user['password_hash'])) {
        $intentos['count'] = ($intentos['count'] ?? 0) + 1;
        $intentos['time']  = time();
        $_SESSION[$intentosKey] = $intentos;
        registrarLog(null, 'login_fallido', 'Usuario: ' . $usuario);
        return [false, 'Usuario o contraseña incorrectos.'];
    }

    unset($_SESSION[$intentosKey]);
    session_regenerate_id(true);

    $_SESSION['user_id']    = $user['id'];
    $_SESSION['nombre']     = $user['nombre_completo'];
    $_SESSION['usuario']    = $user['usuario'];
    $_SESSION['rol_id']     = $user['rol_id'];
    $_SESSION['rol_nombre'] = $user['rol_nombre'];
    // Alcance nacional: master (ve todo el país) vs. estadal (un solo estado)
    $_SESSION['es_master']       = (int)($user['es_master'] ?? 0);
    $_SESSION['estado_asignado'] = $user['estado_asignado'] ?? null;
    // Parroquias asignadas (tolerante: la columna puede no existir todavia).
    try {
        $stp = db()->prepare('SELECT parroquias_asignadas FROM usuarios WHERE id = :id');
        $stp->execute(['id' => $user['id']]);
        $_SESSION['parroquias_asignadas'] = $stp->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $_SESSION['parroquias_asignadas'] = null;
    }
    // Pertenencia a un ente (aislamiento de datos por ente).
    $_SESSION['ente_id']     = $user['ente_id'] ?? null;
    $_SESSION['ente_tipo']   = $user['ente_tipo'] ?? null;
    $_SESSION['ente_estado'] = $user['ente_estado'] ?? null;
    $_SESSION['ente_nombre'] = $user['ente_nombre'] ?? null;
    unset($_SESSION['permisos']); // forzar recarga de permisos

    db()->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :id')
        ->execute(['id' => $user['id']]);

    registrarLog($user['id'], 'login_exitoso', null);

    return [true, null];
}

function cerrarSesion(): void
{
    if (isLoggedIn()) {
        registrarLog($_SESSION['user_id'], 'logout', null);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function registrarLog(?int $usuarioId, string $accion, ?string $detalle): void
{
    try {
        db()->prepare(
            'INSERT INTO log_actividad (usuario_id, accion, detalle, ip) VALUES (:u, :a, :d, :ip)'
        )->execute([
            'u'  => $usuarioId,
            'a'  => $accion,
            'd'  => $detalle,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // No interrumpir el flujo si el log falla
    }
}

/** Token CSRF simple para formularios. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfValidar(?string $token): bool
{
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
