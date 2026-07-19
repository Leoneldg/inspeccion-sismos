<?php
/**
 * DIAGNÓSTICO de la ficha de seguimiento.
 *
 * Prueba una por una las consultas que usa la ficha e informa cuál falla.
 * Úselo cuando la ficha se quede cargando sin mostrar nada.
 *
 * Uso: seguimiento/diagnostico_ficha.php?inspeccion=4449
 *
 * BÓRRELO del servidor cuando termine de revisar.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'ver');
header('Content-Type: text/plain; charset=utf-8');

$inspeccionId = (int)($_GET['inspeccion'] ?? 0);
if ($inspeccionId <= 0) {
    exit("Indique la inspección: ?inspeccion=NUMERO\n");
}

function prueba(string $nombre, callable $fn): void
{
    echo str_pad($nombre, 46, '.');
    try {
        $r = $fn();
        if (is_array($r)) {
            echo 'OK (' . count($r) . " elementos)\n";
        } elseif (is_numeric($r)) {
            echo "OK ($r)\n";
        } else {
            echo "OK\n";
        }
    } catch (Throwable $e) {
        echo "FALLA\n";
        echo '    → ' . $e->getMessage() . "\n";
        echo '    → ' . basename($e->getFile()) . ' línea ' . $e->getLine() . "\n";
    }
}

echo "DIAGNÓSTICO DE LA FICHA · inspección $inspeccionId\n";
echo str_repeat('=', 62) . "\n\n";

// 1) La inspección existe
$insp = null;
prueba('Inspección', function () use ($inspeccionId, &$insp) {
    $insp = segInspeccion($inspeccionId);
    if (!$insp) throw new RuntimeException('No existe esa inspección');
    return $insp['nombre_edificio'] ?? '';
});

// 2) El edificio de reconstrucción
$edificioId = 0;
prueba('Registro de reconstrucción', function () use ($inspeccionId, &$edificioId) {
    $ed = recEdificio($inspeccionId);
    $edificioId = (int)($ed['id'] ?? 0);
    if ($edificioId <= 0) throw new RuntimeException('Sin levantamiento');
    return $edificioId;
});

if ($edificioId <= 0) {
    echo "\nNo se puede seguir sin el registro de reconstrucción.\n";
    exit;
}

echo "\n--- CONSULTAS DE LA FICHA ---\n\n";

prueba('Pisos', fn() => recPisos($edificioId));
prueba('Metros por nivel', fn() => recMetrosPorNivel($edificioId));
prueba('Trabajos registrados', fn() => recTrabajosDeEdificio($edificioId));
prueba('Tipos de trabajo (catálogo)', fn() => recTiposTrabajo());
prueba('Recetas de materiales', fn() => recRecetasTrabajo());
prueba('Áreas comunes', fn() => recAreasComunes($edificioId));
prueba('Plan de fechas', fn() => recPlan($edificioId) ?: []);
prueba('Fotos del edificio', fn() => recFotos('edificio', $edificioId));

echo "\n--- EL ÁRBOL COMPLETO ---\n\n";
prueba('recArbolAvance()', function () use ($edificioId) {
    $a = recArbolAvance($edificioId);
    return $a['pisos'] ?? [];
});

echo "\n--- TABLAS NECESARIAS ---\n\n";
$tablas = ['rec_edificio', 'rec_piso', 'rec_apartamento', 'rec_ambiente',
           'rec_reparacion', 'rec_foto', 'rec_area_comun', 'rec_elemento_piso',
           'rec_avance_apto', 'rec_avance_ambiente',
           'rec_tipo_trabajo', 'rec_receta_trabajo', 'rec_plan_edificio'];
foreach ($tablas as $t) {
    echo str_pad($t, 46, '.');
    try {
        $n = db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "existe ($n filas)\n";
    } catch (Throwable $e) {
        echo "NO EXISTE\n";
    }
}

echo "\n--- COLUMNAS QUE PODRÍAN FALTAR ---\n\n";
$revisar = [
    'rec_reparacion' => ['tipo_trabajo', 'tipo_superficie', 'metros_cuadrados'],
    'rec_edificio'   => ['sin_etiqueta', 'etiqueta_motivo', 'completado'],
];
foreach ($revisar as $tabla => $cols) {
    try {
        $existentes = db()->query("SHOW COLUMNS FROM `$tabla`")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $c) {
            echo str_pad("$tabla.$c", 46, '.');
            echo in_array($c, $existentes, true) ? "existe\n" : "FALTA\n";
        }
    } catch (Throwable $e) {
        echo "$tabla → no se pudo revisar\n";
    }
}

echo "\n" . str_repeat('=', 62) . "\n";
echo "Si algo dice FALLA o NO EXISTE, ese es el problema.\n";
echo "Recuerde borrar este archivo del servidor.\n";
