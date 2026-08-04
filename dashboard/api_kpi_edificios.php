<?php
/**
 * api_kpi_edificios.php
 *
 * Devuelve, en JSON, las edificaciones de un KPI de la Fase 2/3 agrupadas
 * por parroquia, con su porcentaje de avance y el sistematizador que hizo
 * el levantamiento. Lo consumen los modales de control_gubernamental.php
 * y sala_situacion.php (cada KPI es un botón que abre su lista).
 *
 * Parámetro:
 *   tipo = levantamiento | reconstruccion | recuperadas
 *
 * No crea nada nuevo en la base: se apoya en segEnReconstruccion(), que ya
 * trae edificio_id, parroquia, avance, lev_pct, lev_estado y el nombre del
 * creador del levantamiento (el sistematizador).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

requierePermiso('seguimiento', 'ver');

$tipo   = $_GET['tipo'] ?? 'reconstruccion';
$parrF  = trim($_GET['parroquia'] ?? '');

$filtros = [];
if ($parrF !== '') $filtros['parroquia'] = $parrF;

try {
    $lista = function_exists('segEnReconstruccion') ? segEnReconstruccion($filtros) : [];
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'mensaje' => 'No se pudo cargar la lista.']);
    exit;
}

/*
 * Clasificación de cada edificación en los tres KPIs:
 *   levantamiento  → tiene levantamiento (proceso/completo) y avance de obra 0%
 *   reconstruccion → avance de obra entre 1% y 99%
 *   recuperadas    → avance de obra 100%
 * Coincide con la definición de fases del sistema.
 */
$grupos = [];
$totalEdif = 0;

foreach ($lista as $e) {
    $avance = (int)($e['avance'] ?? 0);

    if ($tipo === 'recuperadas') {
        if ($avance < 100) continue;
    } elseif ($tipo === 'reconstruccion') {
        if ($avance < 1 || $avance >= 100) continue;
    } else { // levantamiento
        if ($avance >= 1) continue;
    }

    $parr = $e['parroquia'] !== '' ? $e['parroquia'] : 'Sin parroquia';
    if (!isset($grupos[$parr])) {
        $grupos[$parr] = ['parroquia' => $parr, 'edificios' => []];
    }

    /*
     * Qué porcentaje mostrar en la barra según el KPI:
     *   - En "levantamiento": el avance del LEVANTAMIENTO, no el de la obra.
     *     Si el levantamiento está cerrado (completo o incompleto), esa
     *     etapa terminó → 100%. Si sigue en proceso, su lev_pct real.
     *   - En "reconstruccion" y "recuperadas": el avance de la OBRA.
     * Así no se ve el contrasentido de "levantamiento completo" con 0%.
     */
    $levEstado = $e['lev_estado'] ?? 'proceso';
    $levPct    = (int)($e['lev_pct'] ?? 0);
    if ($tipo === 'levantamiento') {
        $avanceMostrar = ($levEstado === 'completo' || $levEstado === 'incompleto')
            ? 100 : $levPct;
        $etiquetaAvance = 'del levantamiento';
    } else {
        $avanceMostrar = $avance;
        $etiquetaAvance = 'de la obra';
    }

    $grupos[$parr]['edificios'][] = [
        'inspeccion_id'  => (int)$e['id'],
        'edificio_id'    => (int)$e['edificio_id'],
        'codigo'         => $e['codigo'] ?? '',
        'nombre'         => $e['nombre_edificio'] ?? 'Edificación',
        'avance'         => $avanceMostrar,
        'avance_obra'    => $avance,
        'etiqueta_avance'=> $etiquetaAvance,
        'lev_pct'        => $levPct,
        'lev_estado'     => $levEstado,
        'sistematizador' => $e['creado_por_nombre'] ?: 'Sin asignar',
        'n_pisos'        => (int)($e['n_pisos'] ?? 0),
        'n_aptos'        => (int)($e['n_aptos'] ?? 0),
    ];
    $totalEdif++;
}

// Ordenar parroquias por cantidad de edificios (desc) y los edificios de
// cada una por avance (desc), para que arriba salga lo más adelantado.
$salida = [];
foreach ($grupos as $g) {
    usort($g['edificios'], fn($a, $b) => $b['avance'] <=> $a['avance']);
    $g['total'] = count($g['edificios']);
    $salida[] = $g;
}
usort($salida, fn($a, $b) => $b['total'] <=> $a['total']);

$titulos = [
    'levantamiento'  => 'Con levantamiento técnico',
    'reconstruccion' => 'En reconstrucción',
    'recuperadas'    => 'Recuperadas y entregadas',
];

echo json_encode([
    'ok'         => true,
    'tipo'       => $tipo,
    'titulo'     => $titulos[$tipo] ?? 'Edificaciones',
    'total'      => $totalEdif,
    'parroquias' => $salida,
], JSON_UNESCAPED_UNICODE);
