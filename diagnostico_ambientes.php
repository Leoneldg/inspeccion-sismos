<?php
/**
 * DIAGNÓSTICO v2: por qué no salen los ambientes.
 * Ahora detecta duplicados de rec_edificio y cuál tiene los datos.
 *
 * Uso: localhost/inspeccion/diagnostico_ambientes.php?inspeccion=ID
 * Bórralo al terminar.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seguimiento.php';

header('Content-Type: text/plain; charset=utf-8');

$inspeccionId = (int)($_GET['inspeccion'] ?? 0);
if ($inspeccionId <= 0) {
    echo "Uso: ?inspeccion=ID\n\nEdificios con levantamiento (primeros 15):\n";
    $st = db()->query("SELECT i.id, i.nombre_edificio, re.id AS edificio_id
                       FROM inspecciones i JOIN rec_edificio re ON re.inspeccion_id = i.id LIMIT 15");
    foreach ($st->fetchAll() as $r)
        echo "  inspeccion={$r['id']}  edificio_id={$r['edificio_id']}  {$r['nombre_edificio']}\n";
    exit;
}

echo "=== INSPECCIÓN $inspeccionId ===\n";
$insp = db()->prepare("SELECT id, codigo, nombre_edificio, parroquia FROM inspecciones WHERE id=:i");
$insp->execute(['i'=>$inspeccionId]);
$datosInsp = $insp->fetch(PDO::FETCH_ASSOC);
if (!$datosInsp) { echo "No existe esa inspección.\n"; exit; }
echo "Código: {$datosInsp['codigo']}\n";
echo "Nombre: {$datosInsp['nombre_edificio']}\n";
echo "Parroquia: {$datosInsp['parroquia']}\n\n";

// TODOS los rec_edificio de esta inspección (por si hay duplicados)
echo "=== rec_edificio de esta inspección ===\n";
$re = db()->prepare("SELECT id, completado FROM rec_edificio WHERE inspeccion_id=:i");
$re->execute(['i'=>$inspeccionId]);
$edificios = $re->fetchAll(PDO::FETCH_ASSOC);
echo "Cantidad de rec_edificio: " . count($edificios) . "\n";
foreach ($edificios as $e) {
    $eid = (int)$e['id'];
    $np = db()->prepare("SELECT COUNT(*) FROM rec_piso WHERE edificio_id=:e"); $np->execute(['e'=>$eid]);
    $na = db()->prepare("SELECT COUNT(*) FROM rec_ambiente am JOIN rec_apartamento ap ON ap.id=am.apartamento_id JOIN rec_piso pi ON pi.id=ap.piso_id WHERE pi.edificio_id=:e"); $na->execute(['e'=>$eid]);
    echo "  edificio_id=$eid  completado={$e['completado']}  pisos=" . $np->fetchColumn() . "  ambientes=" . $na->fetchColumn() . "\n";
}

// ¿Hay OTRAS inspecciones con el mismo nombre/código (duplicados)?
echo "\n=== POSIBLES DUPLICADOS (mismo nombre) ===\n";
$dup = db()->prepare("SELECT i.id, i.codigo, re.id AS edificio_id,
                        (SELECT COUNT(*) FROM rec_ambiente am JOIN rec_apartamento ap ON ap.id=am.apartamento_id JOIN rec_piso pi ON pi.id=ap.piso_id WHERE pi.edificio_id=re.id) AS ambientes
                      FROM inspecciones i
                      JOIN rec_edificio re ON re.inspeccion_id=i.id
                      WHERE i.nombre_edificio=:n AND i.nombre_edificio<>''");
$dup->execute(['n'=>$datosInsp['nombre_edificio']]);
$dups = $dup->fetchAll(PDO::FETCH_ASSOC);
echo "Inspecciones con el nombre \"{$datosInsp['nombre_edificio']}\": " . count($dups) . "\n";
foreach ($dups as $d) {
    $marca = ((int)$d['ambientes'] > 0) ? '  <-- ESTE TIENE LOS DATOS' : '';
    echo "  inspeccion={$d['id']}  codigo={$d['codigo']}  edificio_id={$d['edificio_id']}  ambientes={$d['ambientes']}$marca\n";
}

echo "\n=== CONCLUSIÓN ===\n";
$conDatos = array_filter($dups, fn($d) => (int)$d['ambientes'] > 0);
if (count($dups) > 1 && count($conDatos) > 0) {
    echo "HAY DUPLICADOS. Este edificio existe varias veces:\n";
    echo "Uno tiene los ambientes y otro está vacío. El modo campo está\n";
    echo "abriendo el VACÍO. Es el problema de duplicados que hay que limpiar.\n";
    echo "Los datos reales están en la(s) inspección(es) marcadas arriba.\n";
} elseif (count($conDatos) === 0) {
    echo "Ninguna copia de este edificio tiene ambientes. El levantamiento\n";
    echo "no registró ambientes para este edificio (ni sus duplicados).\n";
} else {
    echo "No hay duplicados. El edificio simplemente no tiene ambientes registrados.\n";
}
