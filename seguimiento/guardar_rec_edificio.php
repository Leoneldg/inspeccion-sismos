<?php
/**
 * Guarda datos del edificio. Dos modos:
 *  - Paso 1 (por defecto): datos básicos (pisos, aptos, áreas comunes) + genera pisos.
 *  - Cierre (accion='cierre'): azotea/tanques/impermeabilización + tiempo estimado.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');
function resp($ok,$msg='',$extra=[]){ echo json_encode(array_merge(['ok'=>$ok,'mensaje'=>$msg],$extra),JSON_UNESCAPED_UNICODE); exit; }

try {
    requierePermiso('seguimiento', 'editar');
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) resp(false, 'Datos inválidos.');

    $inspeccionId = (int)($b['inspeccion_id'] ?? 0);
    if ($inspeccionId <= 0) resp(false, 'Edificio no válido.');
    if (!segInspeccion($inspeccionId)) resp(false, 'El edificio no existe.');

    $ed = recEdificio($inspeccionId);
    $edificioId = (int)$ed['id'];

    // --- Modo CIERRE: azotea/tanques/impermeabilización + plan de tiempo ---
    if (($b['accion'] ?? '') === 'cierre') {
        $estados = ['Buena','Regular','Requiere reparación','No aplica'];
        $norm = fn($v) => in_array($v, $estados, true) ? $v : null;
        db()->prepare(
            'UPDATE rec_edificio SET azotea_estado=:ae, azotea_obs=:ao, tanques_estado=:te, tanques_obs=:to,
                impermeabilizacion_estado=:ie, impermeabilizacion_obs=:io WHERE id=:id'
        )->execute([
            'ae'=>$norm($b['azotea_estado'] ?? null), 'ao'=>trim($b['azotea_obs'] ?? '') ?: null,
            'te'=>$norm($b['tanques_estado'] ?? null), 'to'=>trim($b['tanques_obs'] ?? '') ?: null,
            'ie'=>$norm($b['impermeabilizacion_estado'] ?? null), 'io'=>trim($b['impermeabilizacion_obs'] ?? '') ?: null,
            'id'=>$edificioId,
        ]);
        // Guardar el plan de tiempo estimado.
        recGuardarPlan($edificioId, $b);
        resp(true, 'Cierre guardado.', ['edificio_id' => $edificioId]);
    }

    // --- Modo PASO 1: datos básicos + generar pisos ---
    // Solo actualiza los campos básicos, sin tocar la azotea (que va en el cierre).
    db()->prepare(
        'UPDATE rec_edificio SET num_pisos=:np, aptos_por_piso=:app, tiene_areas_comunes=:tac, completado=1 WHERE id=:id'
    )->execute([
        'np'  => ($b['num_pisos'] ?? '') !== '' ? (int)$b['num_pisos'] : null,
        'app' => ($b['aptos_por_piso'] ?? '') !== '' ? (int)$b['aptos_por_piso'] : null,
        'tac' => !empty($b['tiene_areas_comunes']) ? 1 : 0,
        'id'  => $edificioId,
    ]);

    // Guardar las áreas comunes seleccionadas.
    if (isset($b['areas_comunes']) && is_array($b['areas_comunes'])) {
        recGuardarAreasComunes($edificioId, $b['areas_comunes']);
    }

    $numPisos = (int)($b['num_pisos'] ?? 0);
    if ($numPisos > 0 && $numPisos <= 200) {
        recGenerarPisos($edificioId, $numPisos);
    }

    resp(true, 'Datos del edificio guardados.', ['edificio_id' => $edificioId]);
} catch (Throwable $e) {
    resp(false, APP_DEBUG ? $e->getMessage() : 'Error al guardar.');
}
