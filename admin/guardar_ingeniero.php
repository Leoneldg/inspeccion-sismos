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
// La profesión viene de un selector; si se eligió "Otro", del campo de texto.
$profSel = trim($_POST['profesion_sel'] ?? '');
$profNueva = trim($_POST['profesion_nueva'] ?? '');
if ($profSel === '__otro__' || $profSel === '__nueva__' || ($profSel === '' && $profNueva !== '')) {
    $profesion = $profNueva;
} else {
    $profesion = $profSel;
}
// Compatibilidad: si algún cliente envía el campo antiguo "profesion".
if ($profesion === '' && isset($_POST['profesion'])) {
    $profesion = trim($_POST['profesion']);
}
// Registrar la profesión en el catálogo para que quede disponible y se pueda
// filtrar/contar por ella.
if ($profesion !== '') {
    registrarProfesion($profesion);
}
$colegio  = trim($_POST['colegio_inscripcion'] ?? '');
$activo   = !empty($_POST['activo']) ? 1 : 0;

// ¿Existe la columna ente_id en ingenieros? (para asignar el ente)
$tieneEnteIng = false;
try { db()->query('SELECT ente_id FROM ingenieros LIMIT 1'); $tieneEnteIng = true; } catch (Throwable $e) { $tieneEnteIng = false; }

// Ente del profesional: el elegido en el formulario; si no se eligió, el del
// usuario que lo crea (para usuarios con ente). El estado se deriva del ente.
$entePost = isset($_POST['ente_id']) && $_POST['ente_id'] !== '' ? (int)$_POST['ente_id'] : null;
$enteAsignado = $entePost ?? (!empty($_SESSION['ente_id']) ? (int)$_SESSION['ente_id'] : null);
$estadoIng = null;
if ($enteAsignado) {
    try {
        $stE = db()->prepare('SELECT estado FROM entes WHERE id = :id');
        $stE->execute(['id' => $enteAsignado]);
        $estadoIng = $stE->fetchColumn() ?: null;
    } catch (Throwable $e) { $estadoIng = null; }
}
// Si no hay ente pero el usuario es estadal, al menos toma su estado.
if ($estadoIng === null && !usuarioEsMaster() && !empty($_SESSION['estado_asignado'])) {
    $estadoIng = $_SESSION['estado_asignado'];
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
    if ($tieneEnteIng) {
        $pdo->prepare(
            'UPDATE ingenieros SET nombre_completo=:nombre, cedula=:cedula, telefono=:telefono,
             profesion=:profesion, colegio_inscripcion=:colegio, activo=:activo, ente_id=:ente, estado=:estado WHERE id=:id'
        )->execute([
            'nombre' => $nombre, 'cedula' => $cedula, 'telefono' => nullSiVacio($telefono),
            'profesion' => nullSiVacio($profesion), 'colegio' => nullSiVacio($colegio), 'activo' => $activo,
            'ente' => $enteAsignado, 'estado' => $estadoIng, 'id' => $id,
        ]);
    } else {
        $pdo->prepare(
            'UPDATE ingenieros SET nombre_completo=:nombre, cedula=:cedula, telefono=:telefono,
             profesion=:profesion, colegio_inscripcion=:colegio, activo=:activo WHERE id=:id'
        )->execute([
            'nombre' => $nombre, 'cedula' => $cedula, 'telefono' => nullSiVacio($telefono),
            'profesion' => nullSiVacio($profesion), 'colegio' => nullSiVacio($colegio), 'activo' => $activo, 'id' => $id,
        ]);
    }
    $ingenieroId = $id;
    registrarLog($_SESSION['user_id'], 'ingeniero_actualizado', "$nombre ($cedula)");
    flash('success', 'Profesional actualizado.');
} else {
    if ($tieneEnteIng) {
        $pdo->prepare(
            'INSERT INTO ingenieros (nombre_completo, cedula, telefono, profesion, colegio_inscripcion, activo, creado_por, ente_id, estado)
             VALUES (:nombre, :cedula, :telefono, :profesion, :colegio, :activo, :creado_por, :ente, :estado)'
        )->execute([
            'nombre' => $nombre, 'cedula' => $cedula, 'telefono' => nullSiVacio($telefono),
            'profesion' => nullSiVacio($profesion), 'colegio' => nullSiVacio($colegio), 'activo' => $activo,
            'creado_por' => $_SESSION['user_id'], 'ente' => $enteAsignado, 'estado' => $estadoIng,
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO ingenieros (nombre_completo, cedula, telefono, profesion, colegio_inscripcion, activo, creado_por)
             VALUES (:nombre, :cedula, :telefono, :profesion, :colegio, :activo, :creado_por)'
        )->execute([
            'nombre' => $nombre, 'cedula' => $cedula, 'telefono' => nullSiVacio($telefono),
            'profesion' => nullSiVacio($profesion), 'colegio' => nullSiVacio($colegio), 'activo' => $activo,
            'creado_por' => $_SESSION['user_id'],
        ]);
    }
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
