<?php
// Crea un ente rápidamente desde el módulo de Usuarios y devuelve su id en
// JSON, para agregarlo al selector sin recargar la página.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    echo json_encode(['ok' => false, 'error' => 'Solicitud inválida.']); exit;
}
// Puede crear entes quien administra usuarios o seguimiento.
if (!puede('usuarios', 'crear') && !puede('seguimiento', 'crear')) {
    echo json_encode(['ok' => false, 'error' => 'No tiene permisos para crear entes.']); exit;
}
if (!tablaEntesExiste()) {
    echo json_encode(['ok' => false, 'error' => 'El módulo de entes no está disponible en esta instalación.']); exit;
}

$nombre = trim($_POST['nombre'] ?? '');
$tipo   = trim($_POST['tipo'] ?? 'Otro');
$estado = trim($_POST['estado'] ?? '');
$estado = $estado === '' ? null : $estado;

if ($nombre === '') {
    echo json_encode(['ok' => false, 'error' => 'Escriba el nombre del ente.']); exit;
}

$tiposValidos = ['Gobernación', 'Alcaldía', 'Ministerio', 'Empresa Pública', 'Empresa Privada', 'Empresa', 'ONG', 'Comunidad Organizada', 'Otro'];
if (!in_array($tipo, $tiposValidos, true)) $tipo = 'Otro';

// Un usuario estadal (no master) solo puede crear entes de su estado.
if (!usuarioEsMaster() && $estado !== null && estadoDelUsuario() !== null && $estado !== estadoDelUsuario()) {
    $estado = estadoDelUsuario();
}

try {
    $st = db()->prepare('INSERT INTO entes (nombre, tipo, estado, activo) VALUES (:n, :t, :e, 1)');
    $st->execute(['n' => $nombre, 't' => $tipo, 'e' => $estado]);
    $id = (int)db()->lastInsertId();
    registrarLog($_SESSION['user_id'], 'ente_creado_rapido', $nombre);
    echo json_encode(['ok' => true, 'id' => $id, 'nombre' => $nombre, 'tipo' => $tipo]);
} catch (Throwable $e) {
    if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
        // Ya existe: devolver el existente para reutilizarlo.
        $st = db()->prepare('SELECT id, nombre, tipo FROM entes WHERE nombre = :n LIMIT 1');
        $st->execute(['n' => $nombre]);
        $row = $st->fetch();
        if ($row) { echo json_encode(['ok' => true, 'id' => (int)$row['id'], 'nombre' => $row['nombre'], 'tipo' => $row['tipo'], 'existia' => true]); exit; }
        echo json_encode(['ok' => false, 'error' => 'Ya existe un ente con ese nombre.']);
    } else {
        echo json_encode(['ok' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'No se pudo crear el ente.']);
    }
}
