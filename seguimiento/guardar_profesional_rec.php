<?php
/**
 * Registra un profesional que NO es ingeniero y lo asigna como responsable
 * del levantamiento.
 *
 * Se usa desde el paso 1 de levantamiento.php, cuando el tecnico activa
 * el interruptor "No es ingeniero".
 *
 * NO crea ni modifica tablas: escribe en la MISMA tabla `ingenieros` que
 * usa el selector normal, aprovechando las columnas que ya existen
 * (nombre_completo, cedula, telefono, profesion, foto). El responsable
 * sigue guardandose en rec_edificio.ingeniero_id, asi que la ficha, el
 * PDF y el resto del sistema lo leen sin cambiar nada.
 *
 * Recibe multipart/form-data (hace falta por la foto):
 *   inspeccion_id  : id de la inspeccion (o edificio_id)
 *   edificio_id    : (opcional) id del edificio
 *   nombre         : obligatorio
 *   cedula         : obligatorio
 *   telefono       : obligatorio
 *   profesion      : obligatorio (del desplegable o escrita a mano)
 *   foto           : opcional
 *   profesional_id : (opcional) para actualizar uno ya creado
 *
 * Responde JSON.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

function respP(bool $ok, string $msg = '', array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $msg], $extra),
                     JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    requierePermiso('seguimiento', 'editar');

    // ---------------------------------------------------------------
    // Ubicar la edificacion
    // ---------------------------------------------------------------
    $inspeccionId = (int)($_POST['inspeccion_id'] ?? 0);
    $edificioPost = (int)($_POST['edificio_id'] ?? 0);

    if ($inspeccionId <= 0 && $edificioPost > 0) {
        $stI = db()->prepare('SELECT inspeccion_id FROM rec_edificio WHERE id = :e');
        $stI->execute(['e' => $edificioPost]);
        $inspeccionId = (int)($stI->fetchColumn() ?: 0);
    }
    if ($inspeccionId <= 0)      respP(false, 'No se pudo ubicar la edificación.');
    if (!segInspeccion($inspeccionId)) respP(false, 'La edificación no existe.');

    $ed = recEdificio($inspeccionId);
    $edificioId = $edificioPost > 0 ? $edificioPost : (int)$ed['id'];
    if ($edificioId <= 0) respP(false, 'No se pudo ubicar la edificación.');

    // ---------------------------------------------------------------
    // Validar los campos obligatorios
    //
    // Nombre, cedula, telefono y profesion son obligatorios.
    // La foto NO lo es.
    // ---------------------------------------------------------------
    $nombre    = trim((string)($_POST['nombre'] ?? ''));
    $cedula    = trim((string)($_POST['cedula'] ?? ''));
    $telefono  = trim((string)($_POST['telefono'] ?? ''));
    $profesion = trim((string)($_POST['profesion'] ?? ''));

    $faltan = [];
    if ($nombre    === '') $faltan[] = 'nombre';
    if ($cedula    === '') $faltan[] = 'cédula';
    if ($telefono  === '') $faltan[] = 'número de teléfono';
    if ($profesion === '') $faltan[] = 'profesión';
    if ($faltan) {
        respP(false, 'Faltan datos obligatorios: ' . implode(', ', $faltan) . '.');
    }

    // Limites de las columnas, para no perder texto por truncado silencioso.
    if (mb_strlen($nombre) > 150)    respP(false, 'El nombre es demasiado largo.');
    if (mb_strlen($cedula) > 30)     respP(false, 'La cédula es demasiado larga.');
    if (mb_strlen($telefono) > 40)   respP(false, 'El teléfono es demasiado largo.');
    if (mb_strlen($profesion) > 100) respP(false, 'La profesión es demasiado larga.');

    $pdo = db();

    // ---------------------------------------------------------------
    // La cedula identifica a la persona.
    //
    // Si ya existe alguien con esa cedula se ACTUALIZA en vez de crear
    // un duplicado: la columna suele tener restriccion UNIQUE y, ademas,
    // es lo que espera el usuario cuando corrige un dato.
    // ---------------------------------------------------------------
    $profesionalId = (int)($_POST['profesional_id'] ?? 0);

    $stDup = $pdo->prepare('SELECT id FROM ingenieros WHERE cedula = :c LIMIT 1');
    $stDup->execute(['c' => $cedula]);
    $existente = (int)($stDup->fetchColumn() ?: 0);

    if ($existente > 0) {
        $profesionalId = $existente;
    }

    // Deja la profesion en el catalogo para que aparezca la proxima vez.
    try { registrarProfesion($profesion); } catch (Throwable $e) { /* opcional */ }

    // ---------------------------------------------------------------
    // Ente y estado: se heredan del usuario que registra, igual que en
    // admin/guardar_ingeniero.php, para que el registro quede visible
    // dentro de su mismo alcance.
    // ---------------------------------------------------------------
    $tieneEnte = false;
    try { $pdo->query('SELECT ente_id FROM ingenieros LIMIT 1'); $tieneEnte = true; }
    catch (Throwable $e) { $tieneEnte = false; }

    $enteAsignado = !empty($_SESSION['ente_id']) ? (int)$_SESSION['ente_id'] : null;
    $estadoIng = null;
    if ($enteAsignado) {
        try {
            $stE = $pdo->prepare('SELECT estado FROM entes WHERE id = :id');
            $stE->execute(['id' => $enteAsignado]);
            $estadoIng = $stE->fetchColumn() ?: null;
        } catch (Throwable $e) { $estadoIng = null; }
    }
    if ($estadoIng === null && !usuarioEsMaster() && !empty($_SESSION['estado_asignado'])) {
        $estadoIng = $_SESSION['estado_asignado'];
    }

    // ---------------------------------------------------------------
    // Crear o actualizar
    // ---------------------------------------------------------------
    if ($profesionalId > 0) {
        if ($tieneEnte) {
            $pdo->prepare(
                'UPDATE ingenieros
                    SET nombre_completo = :n, cedula = :c, telefono = :t,
                        profesion = :p, activo = 1,
                        ente_id = COALESCE(ente_id, :ente),
                        estado  = COALESCE(estado, :estado)
                  WHERE id = :id'
            )->execute([
                'n' => $nombre, 'c' => $cedula, 't' => $telefono,
                'p' => $profesion, 'ente' => $enteAsignado,
                'estado' => $estadoIng, 'id' => $profesionalId,
            ]);
        } else {
            $pdo->prepare(
                'UPDATE ingenieros
                    SET nombre_completo = :n, cedula = :c, telefono = :t,
                        profesion = :p, activo = 1
                  WHERE id = :id'
            )->execute([
                'n' => $nombre, 'c' => $cedula, 't' => $telefono,
                'p' => $profesion, 'id' => $profesionalId,
            ]);
        }
        $accionLog = 'profesional_actualizado';
    } else {
        if ($tieneEnte) {
            $pdo->prepare(
                'INSERT INTO ingenieros
                    (nombre_completo, cedula, telefono, profesion, activo,
                     creado_por, ente_id, estado)
                 VALUES (:n, :c, :t, :p, 1, :u, :ente, :estado)'
            )->execute([
                'n' => $nombre, 'c' => $cedula, 't' => $telefono,
                'p' => $profesion, 'u' => $_SESSION['user_id'] ?? null,
                'ente' => $enteAsignado, 'estado' => $estadoIng,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO ingenieros
                    (nombre_completo, cedula, telefono, profesion, activo, creado_por)
                 VALUES (:n, :c, :t, :p, 1, :u)'
            )->execute([
                'n' => $nombre, 'c' => $cedula, 't' => $telefono,
                'p' => $profesion, 'u' => $_SESSION['user_id'] ?? null,
            ]);
        }
        $profesionalId = (int)$pdo->lastInsertId();
        $accionLog = 'profesional_creado';
    }

    if ($profesionalId <= 0) respP(false, 'No se pudo registrar al profesional.');

    // ---------------------------------------------------------------
    // Foto (OPCIONAL): si falla, el registro NO se pierde.
    // ---------------------------------------------------------------
    $avisoFoto = '';
    if (!empty($_FILES['foto']['name']) &&
        (int)($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $ruta = guardarFotoIngeniero($profesionalId, $_FILES['foto']);
            if ($ruta) {
                $pdo->prepare('UPDATE ingenieros SET foto = :f WHERE id = :id')
                    ->execute(['f' => $ruta, 'id' => $profesionalId]);
            } else {
                $avisoFoto = ' La foto no se pudo procesar, pero los datos sí quedaron guardados.';
            }
        } catch (Throwable $e) {
            error_log('guardar_profesional_rec · foto: ' . $e->getMessage());
            $avisoFoto = ' La foto no se pudo guardar, pero los datos sí quedaron guardados.';
        }
    }

    // ---------------------------------------------------------------
    // Asignarlo como responsable del levantamiento.
    // Se usa la MISMA columna de siempre: rec_edificio.ingeniero_id
    // ---------------------------------------------------------------
    recAsegurarIngeniero();
    $up = $pdo->prepare('UPDATE rec_edificio SET ingeniero_id = :i WHERE id = :e');
    $up->execute(['i' => $profesionalId, 'e' => $edificioId]);

    if ($up->rowCount() === 0) {
        // Puede que ya estuviera asignado: se confirma antes de dar error.
        $stC = $pdo->prepare('SELECT ingeniero_id FROM rec_edificio WHERE id = :e');
        $stC->execute(['e' => $edificioId]);
        if ((int)$stC->fetchColumn() !== $profesionalId) {
            respP(false, 'No se encontró la edificación (id ' . $edificioId . ').');
        }
    }

    try {
        recAuditar($accionLog, $inspeccionId, $edificioId,
            'Responsable (no ingeniero): ' . $nombre . ' · ' . $cedula . ' · ' . $profesion);
    } catch (Throwable $e) { /* la auditoria no debe tumbar el guardado */ }

    respP(true, 'Profesional registrado y asignado.' . $avisoFoto, [
        'profesional_id' => $profesionalId,
        'edificio_id'    => $edificioId,
        'nombre'         => $nombre,
        'cedula'         => $cedula,
        'profesion'      => $profesion,
    ]);

} catch (Throwable $e) {
    error_log('guardar_profesional_rec: ' . $e->getMessage());
    respP(false, APP_DEBUG ? $e->getMessage() : 'No se pudo registrar al profesional.');
}
