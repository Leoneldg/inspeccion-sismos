<?php
/**
 * DIAGNÓSTICO: ambientes, áreas comunes Y elementos del piso.
 * Uso: localhost/inspeccion/diagnostico_arbol.php?inspeccion=7062
 * Bórralo al terminar.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seguimiento.php';

header('Content-Type: text/plain; charset=utf-8');
$inspeccionId = (int)($_GET['inspeccion'] ?? 0);
if ($inspeccionId <= 0) { echo "Uso: ?inspeccion=ID\n"; exit; }

$ed = recEdificio($inspeccionId);
$edificioId = (int)($ed['id'] ?? 0);
echo "=== inspeccion=$inspeccionId  edificio_id=$edificioId ===\n\n";
$q = function($sql,$p){ $s=db()->prepare($sql); $s->execute($p); return $s->fetchColumn(); };

echo "AMBIENTES a reparar: " . $q("SELECT COUNT(*) FROM rec_ambiente am JOIN rec_apartamento ap ON ap.id=am.apartamento_id JOIN rec_piso pi ON pi.id=ap.piso_id WHERE pi.edificio_id=:e AND am.necesita_reparacion=1", ['e'=>$edificioId]) . "\n";

echo "\n=== ÁREAS COMUNES (rec_area_comun) ===\n";
echo "Todas: " . $q("SELECT COUNT(*) FROM rec_area_comun WHERE edificio_id=:e", ['e'=>$edificioId]) . "\n";
echo "A reparar: " . $q("SELECT COUNT(*) FROM rec_area_comun WHERE edificio_id=:e AND necesita_reparacion=1", ['e'=>$edificioId]) . "\n";
$st = db()->prepare("SELECT tipo, nombre_libre, necesita_reparacion FROM rec_area_comun WHERE edificio_id=:e LIMIT 10");
$st->execute(['e'=>$edificioId]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a)
    echo "  " . ($a['nombre_libre'] ?: $a['tipo']) . " · necesita_reparacion={$a['necesita_reparacion']}\n";

echo "\n=== ELEMENTOS DEL PISO (rec_elemento_piso: pasillos, escaleras) ===\n";
$totEp = $q("SELECT COUNT(*) FROM rec_elemento_piso ep JOIN rec_piso pi ON pi.id=ep.piso_id WHERE pi.edificio_id=:e", ['e'=>$edificioId]);
echo "Todos: $totEp\n";
$epRep = $q("SELECT COUNT(*) FROM rec_elemento_piso ep JOIN rec_piso pi ON pi.id=ep.piso_id WHERE pi.edificio_id=:e AND ep.necesita_reparacion=1", ['e'=>$edificioId]);
echo "A reparar: $epRep\n";
$st = db()->prepare("SELECT ep.tipo, ep.necesita_reparacion, ep.presente, pi.numero_piso FROM rec_elemento_piso ep JOIN rec_piso pi ON pi.id=ep.piso_id WHERE pi.edificio_id=:e LIMIT 15");
$st->execute(['e'=>$edificioId]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ep)
    echo "  Piso {$ep['numero_piso']} · {$ep['tipo']} · presente={$ep['presente']} · necesita_reparacion={$ep['necesita_reparacion']}\n";

echo "\n=== QUÉ DEVUELVE recArbolAvance ===\n";
$arbol = recArbolAvance($edificioId);
echo "Pisos: " . count($arbol['pisos'] ?? []) . "\n";
echo "areas_comunes que devuelve: " . count($arbol['areas_comunes'] ?? []) . "\n";
if (!empty($arbol['areas_comunes']))
    foreach ($arbol['areas_comunes'] as $a) echo "  - " . ($a['nombre'] ?? '?') . "\n";

echo "\n=== CONCLUSIÓN ===\n";
echo "El modo campo (recArbolAvance) hoy muestra: ambientes + areas_comunes.\n";
echo "NO muestra los elementos del piso (pasillos, escaleras) como trabajables.\n";
if ($epRep > 0) echo "-> Este edificio tiene $epRep elementos del piso a reparar que NO se ven.\n";
if (count($arbol['areas_comunes'] ?? []) == 0 && $q("SELECT COUNT(*) FROM rec_area_comun WHERE edificio_id=:e AND necesita_reparacion=1", ['e'=>$edificioId]) > 0)
    echo "-> Hay áreas comunes a reparar pero el árbol devuelve 0: revisar.\n";
