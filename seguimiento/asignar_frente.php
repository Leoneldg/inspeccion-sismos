<?php
/**
 * Asigna una edificación a un integrante concreto de un frente de trabajo.
 * Ej: el frente GDC "LARRY-FIGUERA" se divide y se asigna a LARRY o a FIGUERA.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

function jr(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    requierePermiso('seguimiento', 'editar');

    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) jr(false, 'Datos inválidos.');

    // --- Consultar los integrantes disponibles de un frente ---
    if (($b['accion'] ?? '') === 'integrantes') {
        $estado    = trim($b['estado'] ?? 'Distrito Capital');
        $parroquia = trim($b['parroquia'] ?? '');
        if ($parroquia === '') jr(false, 'Falta la parroquia.');
        if (!puedeAccederParroquia($parroquia)) jr(false, 'No tiene asignada esta parroquia.');

        $out = [];
        foreach (frentesDeParroquia($estado, $parroquia) as $f) {
            $out[] = [
                'frente_id'   => (int)$f['id'],
                'tipo'        => $f['tipo'],
                'nombre'      => $f['nombre'],
                'sector'      => $f['sector'] ?? null,
                'integrantes' => frenteIntegrantes($f['nombre']),
            ];
        }
        jr(true, '', ['frentes' => $out, 'tipos' => frenteTipos()]);
    }

    // --- Guardar la asignación ---
    $inspeccionId = (int)($b['inspeccion_id'] ?? 0);
    $frenteId     = (int)($b['frente_id'] ?? 0);
    $tipo         = trim($b['tipo'] ?? '');
    $miembro      = trim($b['miembro'] ?? '');

    if ($inspeccionId <= 0) jr(false, 'Edificación no válida.');
    if ($tipo === '' || $miembro === '') jr(false, 'Seleccione el integrante responsable.');

    $insp = segInspeccion($inspeccionId);
    if (!$insp) jr(false, 'La edificación no existe.');
    if (!puedeAccederParroquia($insp['parroquia'] ?? null)) jr(false, 'No tiene acceso a esta parroquia.');

    asigGuardar($inspeccionId, $frenteId, $tipo, $miembro);

    jr(true, 'Asignado a ' . $miembro . '.', ['miembro' => $miembro, 'tipo' => $tipo]);

} catch (Throwable $e) {
    jr(false, APP_DEBUG ? $e->getMessage() : 'Error al asignar.');
}
