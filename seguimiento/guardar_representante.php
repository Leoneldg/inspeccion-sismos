<?php
/**
 * Guarda, actualiza o desactiva un representante y sus parroquias.
 * Responde en JSON. Lo consume la página representantes.php.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

function resp(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    requierePermiso('seguimiento', 'editar');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) resp(false, 'Datos inválidos.');

    // --- Desactivar ---
    if (($body['accion'] ?? '') === 'desactivar') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) resp(false, 'Representante no válido.');
        repDesactivar($id);
        resp(true, 'Representante desactivado.');
    }

    // --- Crear o actualizar ---
    $nombre = trim($body['nombre'] ?? '');
    if ($nombre === '') resp(false, 'El nombre es obligatorio.');

    $parroquias = $body['parroquias'] ?? [];
    if (!is_array($parroquias) || !$parroquias) resp(false, 'Seleccione al menos una parroquia.');

    $pdo = db();
    $pdo->beginTransaction();

    $id = (int)($body['id'] ?? 0);
    if ($id > 0) {
        // Actualizar datos del representante existente.
        $pdo->prepare(
            'UPDATE representantes SET nombre=:n, cedula=:c, telefono=:t, email=:e, cargo=:ca WHERE id=:id'
        )->execute([
            'n'  => $nombre,
            'c'  => trim($body['cedula'] ?? '') ?: null,
            't'  => trim($body['telefono'] ?? '') ?: null,
            'e'  => trim($body['email'] ?? '') ?: null,
            'ca' => trim($body['cargo'] ?? '') ?: null,
            'id' => $id,
        ]);
        // Reemplazar sus parroquias: se borran las actuales y se ponen las nuevas.
        $pdo->prepare('DELETE FROM representante_parroquia WHERE representante_id = :id')
            ->execute(['id' => $id]);
    } else {
        $id = repCrear([
            'nombre'   => $nombre,
            'cedula'   => $body['cedula'] ?? '',
            'telefono' => $body['telefono'] ?? '',
            'email'    => $body['email'] ?? '',
            'cargo'    => $body['cargo'] ?? '',
        ]);
    }

    foreach ($parroquias as $p) {
        $est = trim($p['estado'] ?? '');
        $par = trim($p['parroquia'] ?? '');
        $mun = trim($p['municipio'] ?? '');
        if ($est === '' || $par === '') continue;
        repAsignarParroquia($id, $est, $mun, $par);
    }

    $pdo->commit();
    resp(true, 'Representante guardado.', ['id' => $id]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    resp(false, APP_DEBUG ? $e->getMessage() : 'Error al guardar. Intente de nuevo.');
}
