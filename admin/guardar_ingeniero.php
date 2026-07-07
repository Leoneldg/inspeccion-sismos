<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

$id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
requierePermiso('ingenieros', $id ? 'editar' : 'crear');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    flash('error', 'La sesión del formulario expiró, intente nuevamente.');
    header('Location: ' . APP_URL_BASE . 'admin/ingenieros.php');
    exit;
}

$nombre  = trim($_POST['nombre_completo'] ?? '');
$cedula  = trim($_POST['cedula'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');
$profesion = trim($_POST['profesion'] ?? '');
$colegio  = trim($_POST['colegio_inscripcion'] ?? '');
$activo   = !empty($_POST['activo']) ? 1 : 0;
// Estado (alcance nacional): el estadal siempre queda con su propio estado.
$estadoIng = trim($_POST['estado'] ?? '');
if (!usuarioEsMaster()) {
    $estadoIng = estadoDelUsuario() ?? '';
}
$estadoIng = $estadoIng === '' ? null : $estadoIng;

// Al editar, un estadal no puede tocar profesionales de otro estado.
if ($id && !usuarioEsMaster()) {
    $chk = db()->prepare('SELECT estado FROM ingenieros WHERE id = :id');
    $chk->execute(['id' => $id]);
    $obj = $chk->fetch();
    if ($obj && ($obj['estado'] ?? null) !== null && ($obj['estado'] ?? null) !== estadoDelUsuario()) {
        flash('error', 'No puede editar profesionales de otro estado.');
        header('Location: ' . APP_URL_BASE . 'admin/ingenieros.php');
        exit;
    }
}

if ($nombre === '' || $cedula === '') {
    flash('error', 'Nombre completo y cédula son obligatorios.');
    header('Location: ' . APP_URL_BASE . 'admin/ingenieros.php' . ($id ? '?id=' . $id : ''));
    exit;
}

$pdo = db();

// Cédula única (con mensaje amigable en vez de dejar reventar la
// restricción UNIQUE de la base de datos)
$stmtDup = $pdo->prepare('SELECT id FROM ingenieros WHERE cedula = :cedula AND id <> :id');
$stmtDup->execute(['cedula' => $cedula, 'id' => $id ?? 0]);
if ($stmtDup->fetch()) {
    flash('error', 'Ya existe otro profesional registrado con esa cédula.');
    header('Location: ' . APP_URL_BASE . 'admin/ingenieros.php' . ($id ? '?id=' . $id : ''));
    exit;
}

if ($id) {
    $pdo->prepare(
        'UPDATE ingenieros SET nombre_completo=:nombre, cedula=:cedula, telefono=:telefono,
         profesion=:profesion, colegio_inscripcion=:colegio, estado=:estado, activo=:activo WHERE id=:id'
    )->execute([
        'nombre' => $nombre, 'cedula' => $cedula, 'telefono' => nullSiVacio($telefono),
        'profesion' => nullSiVacio($profesion), 'colegio' => nullSiVacio($colegio), 'estado' => $estadoIng, 'activo' => $activo, 'id' => $id,
    ]);
    $ingenieroId = $id;
    registrarLog($_SESSION['user_id'], 'ingeniero_actualizado', "$nombre ($cedula)");
    flash('success', 'Profesional actualizado.');
} else {
    $pdo->prepare(
        'INSERT INTO ingenieros (nombre_completo, cedula, telefono, profesion, colegio_inscripcion, estado, activo, creado_por)
         VALUES (:nombre, :cedula, :telefono, :profesion, :colegio, :estado, :activo, :creado_por)'
    )->execute([
        'nombre' => $nombre, 'cedula' => $cedula, 'telefono' => nullSiVacio($telefono),
        'profesion' => nullSiVacio($profesion), 'colegio' => nullSiVacio($colegio), 'estado' => $estadoIng, 'activo' => $activo,
        'creado_por' => $_SESSION['user_id'],
    ]);
    $ingenieroId = (int)$pdo->lastInsertId();
    registrarLog($_SESSION['user_id'], 'ingeniero_creado', "$nombre ($cedula)");
    flash('success', 'Profesional agregado.');
}

// Foto (opcional): si viene un archivo válido, se guarda y se actualiza la ruta
if (!empty($_FILES['foto']['name'])) {
    $ruta = guardarFotoIngeniero($ingenieroId, $_FILES['foto']);
    if ($ruta) {
        $pdo->prepare('UPDATE ingenieros SET foto = :foto WHERE id = :id')->execute(['foto' => $ruta, 'id' => $ingenieroId]);
    } else {
        flash('error', 'El profesional se guardó, pero la foto no se pudo procesar (revise que sea una imagen válida y no muy pesada).');
    }
}

header('Location: ' . APP_URL_BASE . 'admin/ingenieros.php');
exit;
