<?php
/**
 * DIAGNÓSTICO: verifica que la receta de cada trabajo de DEMOLICIÓN
 * incluya materiales de las TRES etapas (demolición + construcción +
 * revestimiento). Si a un trabajo "demoler y reconstruir" le faltan
 * los materiales de construcción o revestimiento, el sistema pedirá
 * de menos.
 * Uso: localhost/inspeccion/diagnostico_materiales.php
 * Bórralo al terminar.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seguimiento.php';

header('Content-Type: text/plain; charset=utf-8');

$recetas = recRecetasTrabajo();

// Trabajos que, por ser demolición combinada, DEBEN tener materiales
// de construcción y de revestimiento en su receta.
$combinados = [
    'demoler_pared_completa_concreto', 'demoler_pared_completa_arcilla',
    'demolicion_parcial_concreto', 'demolicion_parcial_arcilla',
    'demoler_reconstruir_concreto', 'demoler_reconstruir_arcilla',
];

echo "=== RECETAS DE TRABAJOS DE DEMOLICIÓN (deben tener las 3 etapas) ===\n\n";

foreach ($combinados as $clave) {
    echo "── $clave ──\n";
    if (empty($recetas[$clave])) {
        echo "  ⚠️  SIN RECETA: este trabajo no tiene materiales cargados.\n\n";
        continue;
    }
    $porEtapa = ['demolicion'=>[], 'construccion'=>[], 'revestimiento'=>[], 'SIN ETAPA'=>[]];
    foreach ($recetas[$clave] as $ing) {
        $et = $ing['etapa'] ?: 'SIN ETAPA';
        $porEtapa[$et][] = $ing['material'] . ' (' . rtrim(rtrim((string)$ing['cantidad'],'0'),'.') . ' ' . $ing['unidad'] . '/m²)';
    }
    foreach (['construccion','revestimiento'] as $et) {
        $items = $porEtapa[$et];
        if (empty($items)) {
            echo "  ⚠️  FALTA $et: no hay materiales de esta etapa.\n";
        } else {
            echo "  ✓ $et: " . implode(', ', $items) . "\n";
        }
    }
    if (!empty($porEtapa['SIN ETAPA'])) {
        echo "  ⚠️  SIN CLASIFICAR (no se suman a ninguna caja): " . implode(', ', $porEtapa['SIN ETAPA']) . "\n";
    }
    echo "\n";
}

// Prueba concreta con el ejemplo del usuario: 1 m² de demoler_reconstruir
echo "=== PRUEBA: 1 m² de 'demoler y reconstruir' (concreto) ===\n";
$res = segMaterialPorEtapa(['demoler_reconstruir_concreto' => 1.0]);
echo "Demolición: {$res['demolicion']['m2']} m² · {$res['demolicion']['sacos']} sacos escombro\n";
echo "Construcción ({$res['construccion']['m2']} m²):\n";
foreach ($res['construccion']['materiales'] as $m)
    echo "   - {$m['material']}: " . round($m['cantidad'],2) . " {$m['unidad']}\n";
echo "Revestimiento ({$res['revestimiento']['m2']} m²):\n";
foreach ($res['revestimiento']['materiales'] as $m)
    echo "   - {$m['material']}: " . round($m['cantidad'],2) . " {$m['unidad']}\n";

// Suma total de cemento (el ejemplo del usuario)
echo "\n=== SUMA TOTAL POR MATERIAL (construcción + revestimiento) ===\n";
$totales = [];
foreach (['construccion','revestimiento'] as $et) {
    foreach ($res[$et]['materiales'] as $m) {
        $k = $m['material'] . ' (' . $m['unidad'] . ')';
        $totales[$k] = ($totales[$k] ?? 0) + $m['cantidad'];
    }
}
foreach ($totales as $mat => $cant)
    echo "   $mat: " . round($cant,2) . "\n";

echo "\n=== CONCLUSIÓN ===\n";
echo "Si arriba ves '⚠️ FALTA construccion' o '⚠️ FALTA revestimiento' en algún\n";
echo "trabajo de demolición, ESE es el problema: la receta no tiene cargados los\n";
echo "materiales de esa etapa, así que el sistema pide de menos.\n";
echo "La cascada de m² SÍ está bien (demoler reparte a las 3 etapas). Lo que hay\n";
echo "que revisar es que las RECETAS en Admin > Materiales tengan los materiales\n";
echo "de cada etapa cargados y clasificados.\n";
