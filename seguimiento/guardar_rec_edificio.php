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

    // Algunas acciones llegan con el id del edificio en vez del de la
    // inspección (por ejemplo, asignar ingeniero desde el listado).
    if ($inspeccionId <= 0 && !empty($b['edificio_id'])) {
        try {
            $stI = db()->prepare('SELECT inspeccion_id FROM rec_edificio WHERE id = :e');
            $stI->execute(['e' => (int)$b['edificio_id']]);
            $inspeccionId = (int)($stI->fetchColumn() ?: 0);
        } catch (Throwable $e) { /* cae en la validación de abajo */ }
    }

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

    // --- Ingeniero responsable del levantamiento ---
    if (($b['accion'] ?? '') === 'ingeniero') {
        recAsegurarIngeniero();
        $ingId = !empty($b['ingeniero_id']) ? (int)$b['ingeniero_id'] : null;

        // El id del edificio manda cuando viene explícito: es el caso
        // de la lista de reconstrucción. Usar el de recEdificio()
        // podría apuntar a otro registro.
        $destino = !empty($b['edificio_id']) ? (int)$b['edificio_id'] : $edificioId;
        if ($destino <= 0) resp(false, 'No se pudo ubicar la edificación.');

        // Verificar que el ingeniero exista y esté activo.
        if ($ingId !== null) {
            $stV = db()->prepare('SELECT COUNT(*) FROM ingenieros
                                   WHERE id = :i AND activo = 1');
            $stV->execute(['i' => $ingId]);
            if (!(int)$stV->fetchColumn()) {
                resp(false, 'El ingeniero no existe o está inactivo.');
            }
        }

        $up = db()->prepare('UPDATE rec_edificio SET ingeniero_id = :i WHERE id = :e');
        $up->execute(['i' => $ingId, 'e' => $destino]);

        if ($up->rowCount() === 0) {
            // Puede que el valor ya fuera el mismo: se comprueba.
            $stC = db()->prepare('SELECT ingeniero_id FROM rec_edificio WHERE id = :e');
            $stC->execute(['e' => $destino]);
            $actual = $stC->fetchColumn();
            if ((int)$actual !== (int)$ingId) {
                resp(false, 'No se encontró la edificación (id ' . $destino . ').');
            }
        }

        recAuditar('ingeniero_asignado', $inspeccionId, $destino,
                   'Ingeniero responsable: ' . ($ingId ?: 'ninguno'));
        resp(true, 'Ingeniero registrado.', ['edificio_id' => $destino]);
    }

    // --- Área común con nombre libre ---
    if (($b['accion'] ?? '') === 'area_libre') {
        recAsegurarAreasPartidas();
        $clave  = trim($b['clave'] ?? '');
        $nombre = trim($b['nombre'] ?? '');
        if ($clave === '' || $nombre === '') resp(false, 'Indique el nombre del área.');

        db()->prepare(
            'INSERT INTO rec_area_comun (edificio_id, tipo, nombre_libre, presente)
             VALUES (:e, :t, :n, 1)
             ON DUPLICATE KEY UPDATE nombre_libre = VALUES(nombre_libre)'
        )->execute(['e' => $edificioId, 't' => $clave, 'n' => $nombre]);

        recAuditar('area_agregada', $inspeccionId, $edificioId, 'Área común: ' . $nombre);
        resp(true, 'Área agregada.', ['clave' => $clave, 'nombre' => $nombre]);
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
        // No se puede cerrar el levantamiento con datos faltantes.
        // "Completo" = todo lo marcado para reparar (ambientes Y áreas
        // comunes) tiene tipo de trabajo, metros y foto. La regla vive
        // en recLevantamientoIncompleto(): la misma que usa el PDF.
        try {
            $faltan = recLevantamientoIncompleto($edificioId);
            if ($faltan) {
                resp(false,
                    'Faltan datos en ' . count($faltan) . ' reparación(es). '
                    . 'No se borró nada: complete cada punto y vuelva a cerrar.',
                    [
                        'incompletos'    => count($faltan),
                        'puede_confirmar'=> false,
                        'faltantes'      => $faltan,   // lista detallada para la guía
                    ]);
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
    recAsegurarLocales();   // columnas y tipos de local, por si faltan
    db()->prepare(
        'UPDATE rec_edificio SET num_pisos=:np, aptos_por_piso=:app, tiene_areas_comunes=:tac,
            tiene_locales=:tl, num_locales=:nl WHERE id=:id'
    )->execute([
        'np'  => ($b['num_pisos'] ?? '') !== '' ? (int)$b['num_pisos'] : null,
        'app' => ($b['aptos_por_piso'] ?? '') !== '' ? (int)$b['aptos_por_piso'] : null,
        'tac' => !empty($b['tiene_areas_comunes']) ? 1 : 0,
        'tl'  => !empty($b['tiene_locales']) ? 1 : 0,
        'nl'  => max(0, (int)($b['num_locales'] ?? 0)),
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

    // Generar los locales comerciales en la planta baja (piso 1).
    if (!empty($b['tiene_locales'])) {
        $numLoc = max(0, (int)($b['num_locales'] ?? 0));
        if ($numLoc > 0 && $numLoc <= 60) {
            recGenerarLocales($edificioId, $numLoc);
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
