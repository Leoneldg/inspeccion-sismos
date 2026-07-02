<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'Error interno del servidor al cargar la ficha técnica.',
        'detail' => APP_DEBUG ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error'  => 'Error interno del servidor al cargar la ficha técnica.',
            'detail' => APP_DEBUG ? ($err['message'] . ' en ' . $err['file'] . ':' . $err['line']) : null,
        ], JSON_UNESCAPED_UNICODE);
    }
});

requireLogin();
if (!puede('dashboard', 'ver')) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit;
}

try {
    $stmt = db()->prepare('SELECT i.*, cu.nombre_completo AS creado_por_nombre
                            FROM inspecciones i
                            LEFT JOIN usuarios cu ON cu.id = i.creado_por
                            WHERE i.id = :id');
    $stmt->execute(['id' => $id]);
    $r = $stmt->fetch();

    if (!$r) {
        http_response_code(404);
        echo json_encode(['error' => 'No encontrada']);
        exit;
    }

    $danosEst = json_decode($r['danos_estructurales'] ?? '{}', true) ?: [];
    $danosNo  = json_decode($r['danos_no_estructurales'] ?? '{}', true) ?: [];
    $extra    = json_decode($r['datos_adicionales'] ?? '{}', true) ?: [];
    $nivelesDano = catalogoNivelDano();
    $elementosEstruct = catalogoElementosEstructurales();
    $elementosNoEstruct = catalogoElementosNoEstructurales();
    $catCategoriasFoto = catalogoCategoriasFoto();
    $decisiones = catalogoDecisionFinal();

    // Compatibilidad: si la tabla de fotos aún no existe (instalación sin
    // database/actualizacion_v2.sql aplicado), simplemente no hay fotos.
    $tieneFotos = tablaFotosExiste();
    $fotosAgrupadas = $tieneFotos ? obtenerFotosInspeccion($id) : [];
    $fotosSalida = [];
    foreach ($fotosAgrupadas as $categoria => $lista) {
        $fotosSalida[] = [
            'categoria'      => $categoria,
            'categoria_label'=> $catCategoriasFoto[$categoria] ?? ucfirst($categoria),
            'fotos'          => array_map(fn($f) => [
                'id'  => (int)$f['id'],
                'url' => APP_URL_BASE . $f['ruta'],
            ], $lista),
        ];
    }

    $danosEstructuralesTexto = [];
    foreach ($elementosEstruct as $k => $label) {
        if (!empty($danosEst[$k])) {
            $danosEstructuralesTexto[] = ['label' => $label, 'valor' => $nivelesDano[$danosEst[$k]] ?? $danosEst[$k]];
        }
    }
    $danosNoEstructuralesTexto = [];
    foreach ($elementosNoEstruct as $k => $label) {
        if (!empty($danosNo[$k])) {
            $danosNoEstructuralesTexto[] = ['label' => $label, 'valor' => $nivelesDano[$danosNo[$k]] ?? $danosNo[$k]];
        }
    }

    echo json_encode([
        'id'             => (int)$r['id'],
        'codigo'         => $r['codigo'],
        'nombre_edificio'=> $r['nombre_edificio'],
        'decision_final' => $r['decision_final'],
        'decision_color' => $decisiones[$r['decision_final']]['color'] ?? '#767c94',
        'ubicacion'      => [
            ['k' => 'Parroquia', 'v' => $r['parroquia']],
            ['k' => 'Municipio / Ciudad / Estado', 'v' => trim(($r['municipio']?:'—').' / '.($r['ciudad']?:'—').' / '.($r['estado']?:'—'))],
            ['k' => 'Urbanización / Sector', 'v' => trim(($r['urbanizacion']?:'—').' / '.($r['sector']?:'—'))],
            ['k' => 'Avenida o calle', 'v' => $r['avenida_calle'] ?: '—'],
            ['k' => 'Coordenadas', 'v' => ($r['latitud'] && $r['longitud']) ? $r['latitud'].', '.$r['longitud'] : '—'],
        ],
        'identificacion' => [
            ['k' => 'Fecha de inspección', 'v' => $r['fecha_inspeccion']],
            ['k' => 'N° de pisos / sótanos / semisótanos', 'v' => $r['num_pisos'].' / '.$r['num_sotanos'].' / '.$r['num_semisotanos']],
            ['k' => 'Cantidad de apartamentos', 'v' => (string)$r['cantidad_apartamentos']],
            ['k' => 'Uso de la edificación', 'v' => $r['uso_edificacion'] ?: '—'],
            ['k' => 'Tipo estructural', 'v' => $r['tipo_estructural'] ?: '—'],
        ],
        'riesgo' => [
            ['k' => 'Colapso de la estructura', 'v' => $r['colapso_estructura']],
            ['k' => 'Riesgo de edificios aledaños', 'v' => $r['riesgo_edificios_aledanos']],
            ['k' => 'Amenaza geológica', 'v' => $r['amenaza_geologica']],
            ['k' => 'Asentamiento / Inclinación', 'v' => trim(($r['asentamiento_edificio']?:'—').' / '.($r['inclinacion_edificio']?:'—'))],
            ['k' => 'Requiere inspección interna', 'v' => $r['requiere_inspeccion_interna']],
            ['k' => 'Requiere intervención', 'v' => $r['requiere_intervencion']],
        ],
        'danos_estructurales'    => $danosEstructuralesTexto,
        'danos_no_estructurales' => $danosNoEstructuralesTexto,
        'personas' => [
            ['k' => 'Familias', 'v' => (string)$r['familias']],
            ['k' => 'Hombres', 'v' => (string)$r['hombres']],
            ['k' => 'Mujeres', 'v' => (string)$r['mujeres']],
            ['k' => 'Niños', 'v' => (string)$r['ninos']],
            ['k' => '3ra edad', 'v' => (string)$r['adultos_tercera_edad']],
            ['k' => 'Gestantes', 'v' => (string)$r['gestantes']],
            ['k' => 'Movilidad reducida', 'v' => (string)$r['movilidad_reducida']],
            ['k' => 'Mascotas', 'v' => (string)$r['mascotas']],
        ],
        'profesional' => [
            ['k' => 'Nombre', 'v' => $r['ing1_nombre']],
            ['k' => 'Cédula', 'v' => $r['ing1_cedula']],
            ['k' => 'Profesión', 'v' => $r['ing1_profesion'] ?: '—'],
        ],
        'observaciones'    => $r['observaciones'] ?: null,
        'recomendaciones'  => $r['recomendaciones'] ?: null,
        'medidas_seguridad'=> $r['medidas_seguridad'] ?: null,
        'registrado_por'   => $r['creado_por_nombre'],
        'creado_en'        => $r['creado_en'],
        'fotos'            => $fotosSalida,
        'url_ficha_completa' => APP_URL_BASE . 'formulario/view.php?id=' . $r['id'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'Error interno del servidor al cargar la ficha técnica.',
        'detail' => APP_DEBUG ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
