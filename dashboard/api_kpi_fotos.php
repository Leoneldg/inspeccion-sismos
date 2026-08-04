<?php
/**
 * api_kpi_fotos.php
 *
 * Devuelve, en JSON, TODAS las fotos de una edificación agrupadas en las
 * tres fases (antes / durante / después), para el modal de fotos que se
 * abre al tocar un edificio en la lista de un KPI.
 *
 * Junta las fotos de todos los niveles del edificio (edificio, apartamento,
 * ambiente, área común y elemento de piso) leyendo la tabla rec_foto, que
 * es donde el levantamiento y el modo campo guardan todo.
 *
 * Parámetro:
 *   edificio = id de rec_edificio
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

requierePermiso('seguimiento', 'ver');

$edificioId = (int)($_GET['edificio'] ?? 0);
if ($edificioId <= 0) {
    echo json_encode(['ok' => false, 'mensaje' => 'Falta el edificio.']);
    exit;
}

$antes = [];
$durante = [];
$despues = [];

try {
    /*
     * Todas las fotos del edificio, sin importar el nivel. Se resuelve el
     * ref_id de cada nivel contra su piso/apartamento para asegurar que
     * pertenece a ESTE edificio. Se etiqueta cada foto con un rótulo
     * legible (dónde fue tomada) para mostrarlo bajo la miniatura.
     */
    $sql = "
        SELECT f.id, f.ruta, f.parte, f.descripcion, f.creado_en,
               f.nivel, f.ref_id
          FROM rec_foto f
         WHERE
            ( f.nivel = 'edificio'     AND f.ref_id = :e1 )
         OR ( f.nivel = 'apartamento'  AND f.ref_id IN (
                 SELECT ap.id FROM rec_apartamento ap
                   JOIN rec_piso p ON p.id = ap.piso_id
                  WHERE p.edificio_id = :e2 ) )
         OR ( f.nivel = 'ambiente'     AND f.ref_id IN (
                 SELECT am.id FROM rec_ambiente am
                   JOIN rec_apartamento ap ON ap.id = am.apartamento_id
                   JOIN rec_piso p ON p.id = ap.piso_id
                  WHERE p.edificio_id = :e3 ) )
         OR ( f.nivel = 'elemento_piso' AND f.ref_id IN (
                 SELECT ep.id FROM rec_elemento_piso ep
                   JOIN rec_piso p ON p.id = ep.piso_id
                  WHERE p.edificio_id = :e4 ) )
         OR ( f.nivel = 'area_comun'   AND f.ref_id IN (
                 SELECT ac.id FROM rec_area_comun ac
                  WHERE ac.edificio_id = :e5 ) )
         ORDER BY f.creado_en
    ";
    $st = db()->prepare($sql);
    $st->execute([
        'e1' => $edificioId, 'e2' => $edificioId, 'e3' => $edificioId,
        'e4' => $edificioId, 'e5' => $edificioId,
    ]);

    $rotuloNivel = [
        'edificio'      => 'Edificio',
        'apartamento'   => 'Apartamento',
        'ambiente'      => 'Ambiente',
        'elemento_piso' => 'Área del piso',
        'area_comun'    => 'Área común',
    ];

    foreach ($st->fetchAll() as $r) {
        $parte = $r['parte'] ?: 'antes';
        $item = [
            'id'    => (int)$r['id'],
            'ruta'  => APP_URL_BASE . ltrim($r['ruta'], '/'),
            'lugar' => $rotuloNivel[$r['nivel']] ?? $r['nivel'],
            'descripcion' => $r['descripcion'] ?? '',
            'fecha' => !empty($r['creado_en']) ? date('d/m/Y', strtotime($r['creado_en'])) : '',
        ];
        if ($parte === 'durante')      $durante[] = $item;
        elseif ($parte === 'despues')  $despues[] = $item;
        else                           $antes[]   = $item;
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => 'No se pudieron cargar las fotos.']);
    exit;
}

// Datos de cabecera del edificio.
$cab = ['nombre' => 'Edificación', 'avance' => 0];
try {
    $stc = db()->prepare("
        SELECT i.nombre_edificio, i.parroquia, i.codigo
          FROM rec_edificio re
          JOIN inspecciones i ON i.id = re.inspeccion_id
         WHERE re.id = :e
    ");
    $stc->execute(['e' => $edificioId]);
    if ($row = $stc->fetch()) {
        $cab['nombre']    = $row['nombre_edificio'] ?? 'Edificación';
        $cab['parroquia'] = $row['parroquia'] ?? '';
        $cab['codigo']    = $row['codigo'] ?? '';
    }
    $cab['avance'] = function_exists('recAvanceEdificio') ? recAvanceEdificio($edificioId) : 0;
} catch (Throwable $e) { /* cabecera mínima */ }

echo json_encode([
    'ok'       => true,
    'edificio' => $cab,
    'antes'    => $antes,
    'durante'  => $durante,
    'despues'  => $despues,
    'conteo'   => ['antes' => count($antes), 'durante' => count($durante), 'despues' => count($despues)],
], JSON_UNESCAPED_UNICODE);
