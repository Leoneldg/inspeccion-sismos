<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

requireLogin();

$volver = APP_URL_BASE . 'seguimiento/entes.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    flash('error', 'Solicitud inválida.'); header('Location: ' . $volver); exit;
}
if (!puede('seguimiento', 'crear') && !puede('seguimiento', 'editar')) {
    flash('error', 'No tiene permisos.'); header('Location: ' . $volver); exit;
}

$id      = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
$nombre  = trim($_POST['nombre'] ?? '');
$tipo    = $_POST['tipo'] ?? 'Otro';
$estado  = trim($_POST['estado'] ?? '');
$estado  = $estado === '' ? null : $estado;
$cNombre = nullSiVacio(trim($_POST['contacto_nombre'] ?? ''));
$cTel    = nullSiVacio(trim($_POST['contacto_telefono'] ?? ''));
$cEmail  = nullSiVacio(trim($_POST['contacto_email'] ?? ''));
$activo  = !empty($_POST['activo']) ? 1 : 0;

$tiposValidos = ['Gobernación', 'Alcaldía', 'Ministerio', 'Empresa Pública', 'Empresa Privada', 'ONG', 'Comunidad Organizada', 'Otro'];
if (!in_array($tipo, $tiposValidos, true)) $tipo = 'Otro';

if ($nombre === '') {
    flash('error', 'El nombre del ente es obligatorio.');
    header('Location: ' . $volver . ($id ? "?id=$id" : '')); exit;
}

// Un usuario estadal solo puede crear entes de su estado (o nacionales).
if (!usuarioEsMaster() && $estado !== null && $estado !== estadoDelUsuario()) {
    $estado = estadoDelUsuario();
}

try {
    if ($id) {
        db()->prepare(
            'UPDATE entes SET nombre=:n, tipo=:t, estado=:e, contacto_nombre=:cn, contacto_telefono=:ct, contacto_email=:ce, activo=:a WHERE id=:id'
        )->execute(['n' => $nombre, 't' => $tipo, 'e' => $estado, 'cn' => $cNombre, 'ct' => $cTel, 'ce' => $cEmail, 'a' => $activo, 'id' => $id]);
        flash('success', 'Ente actualizado.');
    } else {
        db()->prepare(
            'INSERT INTO entes (nombre, tipo, estado, contacto_nombre, contacto_telefono, contacto_email, activo)
             VALUES (:n, :t, :e, :cn, :ct, :ce, :a)'
        )->execute(['n' => $nombre, 't' => $tipo, 'e' => $estado, 'cn' => $cNombre, 'ct' => $cTel, 'ce' => $cEmail, 'a' => $activo]);
        flash('success', 'Ente registrado.');
    }
    registrarLog($_SESSION['user_id'], 'ente_guardado', $nombre);
} catch (Throwable $e) {
    if ($e instanceof PDOException && (int)$e->errorInfo[1] === 1062) {
        flash('error', 'Ya existe un ente con ese nombre.');
    } else {
        flash('error', APP_DEBUG ? $e->getMessage() : 'No se pudo guardar el ente.');
    }
}

header('Location: ' . $volver);
exit;
