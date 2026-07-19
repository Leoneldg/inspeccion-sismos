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
    // --- Constancia de que la edificación no tiene etiqueta ---
    if (($b['accion'] ?? '') === 'sin_etiqueta') {
        recAsegurarColumnasEtiqueta();
        $sin = !empty($b['sin_etiqueta']) ? 1 : 0;
        db()->prepare(
            'UPDATE rec_edificio SET sin_etiqueta = :s, etiqueta_motivo = :m, etiqueta_obs = :o
              WHERE id = :id'
        )->execute([
            's'  => $sin,
            'm'  => trim($b['etiqueta_motivo'] ?? '') ?: null,
            'o'  => trim($b['etiqueta_obs'] ?? '') ?: null,
            'id' => $edificioId,
        ]);
        recAuditar('etiqueta', $inspeccionId, $edificioId,
            $sin ? ('Sin etiqueta: ' . (trim($b['etiqueta_motivo'] ?? '') ?: 'sin motivo indicado'))
                 : 'Tiene etiqueta');
        resp(true, 'Registrado.', ['edificio_id' => $edificioId]);
    }

    // --- Fechas de inicio y entrega de la obra ---
    if (($b['accion'] ?? '') === 'plazo') {
        recGuardarPlan($edificioId, [
            'fecha_inicio_estimada' => $b['fecha_inicio_estimada'] ?? null,
            'fecha_fin_estimada'    => $b['fecha_fin_estimada'] ?? null,
        ]);
        recAuditar('plazo_definido', $inspeccionId, $edificioId,
            'Entrega: ' . ($b['fecha_fin_estimada'] ?? 'sin fecha'));
        resp(true, 'Plazo guardado.', ['edificio_id' => $edificioId]);
    }

    if (($b['accion'] ?? '') === 'cierre') {
        // Comprobar que los ambientes marcados como "necesita reparación"
        // tengan metros cuadrados. Sin ese dato no hay cálculo de materiales.
        try {
            $stM = db()->prepare("
                SELECT ap.identificador, am.tipo, am.numero
                  FROM rec_ambiente am
                  JOIN rec_apartamento ap ON ap.id = am.apartamento_id
                  JOIN rec_piso pi ON pi.id = ap.piso_id
                 WHERE pi.edificio_id = :e
                   AND am.necesita_reparacion = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM rec_reparacion rr
                        WHERE rr.nivel = 'ambiente' AND rr.ref_id = am.id
                          AND rr.metros_cuadrados > 0
                   )
                 ORDER BY pi.numero_piso, ap.identificador
                 LIMIT 15
            ");
            $stM->execute(['e' => $edificioId]);
            $faltan = $stM->fetchAll();
            if ($faltan) {
                $lista = [];
                foreach ($faltan as $f) {
                    $lista[] = 'Apto ' . $f['identificador'] . ' · ' . $f['tipo'] . ' ' . $f['numero'];
                }
                resp(false, "Faltan los metros cuadrados a reparar en:\n\n· "
                    . implode("\n· ", $lista)
                    . "\n\nComplételos antes de cerrar el levantamiento.");
            }
        } catch (Throwable $e) { /* si la consulta falla, no bloquear el cierre */ }

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

        // Marcar el levantamiento como COMPLETADO, con quién y cuándo.
        try {
            recAsegurarAuditoria();
            db()->prepare(
                'UPDATE rec_edificio SET completado=1, completado_por=:u, completado_en=NOW() WHERE id=:id'
            )->execute(['u' => $_SESSION['user_id'] ?? null, 'id' => $edificioId]);
        } catch (Throwable $e) { /* seguir */ }

        recAuditar('levantamiento_cerrado', $inspeccionId, $edificioId, 'Levantamiento técnico cerrado');
        resp(true, 'Cierre guardado.', ['edificio_id' => $edificioId]);
    }

    // --- Modo PASO 1: datos básicos + generar pisos ---
    // NO marca completado: eso ocurre solo al cerrar el levantamiento (paso 3).
    db()->prepare(
        'UPDATE rec_edificio SET num_pisos=:np, aptos_por_piso=:app, tiene_areas_comunes=:tac WHERE id=:id'
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
    $aptosPorPiso = (int)($b['aptos_por_piso'] ?? 0);
    if ($numPisos > 0 && $numPisos <= 200) {
        recGenerarPisos($edificioId, $numPisos);
        // Generar también los apartamentos de cada piso, para que todo
        // quede listo de una vez y la ficha cargue completa al instante.
        if ($aptosPorPiso > 0 && $aptosPorPiso <= 100) {
            foreach (recPisos($edificioId) as $piso) {
                recGenerarApartamentos((int)$piso['id'], $aptosPorPiso, (int)$piso['numero_piso']);
            }
        }
    }

    // Devolver el árbol completo (pisos + apartamentos) para dibujarlo sin recargar.
    recAuditar('levantamiento_paso1', $inspeccionId, $edificioId,
        $numPisos . ' piso(s), ' . $aptosPorPiso . ' apto(s) por piso');
    resp(true, 'Datos del edificio guardados.', [
        'edificio_id' => $edificioId,
        'arbol'       => recArbolAvance($edificioId),
    ]);
} catch (Throwable $e) {
    resp(false, APP_DEBUG ? $e->getMessage() : 'Error al guardar.');
}
