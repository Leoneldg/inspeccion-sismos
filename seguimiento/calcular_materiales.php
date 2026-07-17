<?php
/**
 * Calcula la lista de materiales estimados para una intervención usando
 * la API de Claude (IA). Devuelve JSON con el listado de materiales.
 *
 * POST: tipo_construccion, metraje, unidad, csrf
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    echo json_encode(['ok' => false, 'error' => 'Solicitud inválida.']); exit;
}
if (!puede('seguimiento', 'crear')) {
    echo json_encode(['ok' => false, 'error' => 'Sin permisos.']); exit;
}

$tipo    = trim($_POST['tipo_construccion'] ?? '');
$metraje = (float)($_POST['metraje'] ?? 0);
$unidad  = trim($_POST['unidad'] ?? 'm²');

if ($tipo === '' || $metraje <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Seleccione el tipo de construcción e ingrese el metraje.']); exit;
}

// Catálogo de materiales disponibles en el sistema.
$catalogo = segCatalogoMateriales();
$unidades  = array_keys(segUnidadesMateriales());

// Construir el prompt para Claude.
$catalogoStr = '';
foreach ($catalogo as $cat => $subs) {
    $catalogoStr .= "- $cat: " . implode(', ', $subs) . "\n";
}

$prompt = <<<PROMPT
Eres un ingeniero civil venezolano experto en estimación de materiales de construcción para obras de reconstrucción post-sismo.

El ingeniero necesita calcular los materiales para la siguiente intervención:
- Tipo de construcción: $tipo
- Metraje: $metraje $unidad

Catálogo de materiales disponibles en el sistema:
$catalogoStr

Unidades disponibles: saco, und, m², m³, ml, kg, lt, galón, rollo.

Calcula la cantidad estimada de materiales necesarios para esta intervención usando estándares de construcción venezolanos (normativa COVENIN). Sé preciso y realista con las cantidades.

Responde ÚNICAMENTE con un objeto JSON válido, sin texto adicional, sin bloques de código, en este formato exacto:
{
  "materiales": [
    {"categoria": "Bloques", "subtipo": "Bloque hueco 10x20x40 cm", "unidad": "und", "cantidad": 450},
    {"categoria": "Cemento", "subtipo": "Cemento Portland tipo I (saco 42.5 kg)", "unidad": "saco", "cantidad": 12}
  ],
  "nota": "Estimación basada en estándar COVENIN para $tipo de $metraje $unidad. Ajustar según condiciones específicas del sitio."
}

Incluye solo los materiales relevantes para "$tipo". No incluyas categorías que no apliquen. Las cantidades deben ser números sin unidades.
PROMPT;

// Llamada a la API de Anthropic.
$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?? '');

// Si no hay API key configurada, usar estimaciones locales.
if (!$apiKey) {
    echo json_encode(calcularMaterialesLocal($tipo, $metraje, $unidad));
    exit;
}

$payload = [
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 1500,
    'messages'   => [
        ['role' => 'user', 'content' => $prompt]
    ],
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    // Fallback a estimaciones locales si la API falla.
    echo json_encode(calcularMaterialesLocal($tipo, $metraje, $unidad));
    exit;
}

$data = json_decode($response, true);
$texto = $data['content'][0]['text'] ?? '';

// Extraer el JSON de la respuesta.
$texto = trim($texto);
// Quitar posibles backticks de markdown.
$texto = preg_replace('/^```json?\s*/m', '', $texto);
$texto = preg_replace('/^```\s*$/m', '', $texto);
$texto = trim($texto);

$resultado = json_decode($texto, true);
if (!$resultado || !isset($resultado['materiales'])) {
    echo json_encode(calcularMaterialesLocal($tipo, $metraje, $unidad));
    exit;
}

// Validar y normalizar los materiales devueltos por la IA.
$materialesValidos = [];
$catalogoFlat = [];
foreach ($catalogo as $cat => $subs) {
    foreach ($subs as $s) { $catalogoFlat[$s] = $cat; }
}
foreach ($resultado['materiales'] as $m) {
    if (empty($m['categoria']) || !isset($m['cantidad'])) continue;
    $materialesValidos[] = [
        'categoria' => $m['categoria'],
        'subtipo'   => $m['subtipo'] ?? '',
        'unidad'    => in_array($m['unidad'] ?? 'und', $unidades) ? $m['unidad'] : 'und',
        'cantidad'  => max(0, (float)$m['cantidad']),
    ];
}

echo json_encode([
    'ok'         => true,
    'materiales' => $materialesValidos,
    'nota'       => $resultado['nota'] ?? '',
    'fuente'     => 'ia',
]);

/**
 * Estimaciones locales de respaldo (en caso de que la API no esté disponible).
 * Usa rendimientos estándar COVENIN.
 */
function calcularMaterialesLocal(string $tipo, float $metraje, string $unidad): array
{
    $tipo = strtolower($tipo);
    $mats = [];
    $nota = "Estimación estándar COVENIN para $tipo de $metraje $unidad. Verifique con el ingeniero de obra.";

    // Rendimientos base por m² según tipo de intervención.
    if (str_contains($tipo, 'pared') || str_contains($tipo, 'muro')) {
        $mats = [
            ['categoria'=>'Bloques','subtipo'=>'Bloque hueco 10x20x40 cm','unidad'=>'und','cantidad'=>round($metraje * 12.5)],
            ['categoria'=>'Cemento','subtipo'=>'Cemento Portland tipo I (saco 42.5 kg)','unidad'=>'saco','cantidad'=>round($metraje * 0.5)],
            ['categoria'=>'Arena y Agregados','subtipo'=>'Arena lavada (m³)','unidad'=>'m³','cantidad'=>round($metraje * 0.04, 2)],
            ['categoria'=>'Acero / Cabillas','subtipo'=>'Cabilla corrugada 3/8"','unidad'=>'ml','cantidad'=>round($metraje * 2.5)],
        ];
    } elseif (str_contains($tipo, 'losa') || str_contains($tipo, 'piso') || str_contains($tipo, 'placa')) {
        $mats = [
            ['categoria'=>'Cemento','subtipo'=>'Cemento Portland tipo I (saco 42.5 kg)','unidad'=>'saco','cantidad'=>round($metraje * 0.7)],
            ['categoria'=>'Arena y Agregados','subtipo'=>'Arena lavada (m³)','unidad'=>'m³','cantidad'=>round($metraje * 0.05, 2)],
            ['categoria'=>'Arena y Agregados','subtipo'=>'Gravilla / piedra picada (m³)','unidad'=>'m³','cantidad'=>round($metraje * 0.07, 2)],
            ['categoria'=>'Acero / Cabillas','subtipo'=>'Malla electrosoldada 15x15 cm','unidad'=>'m²','cantidad'=>round($metraje * 1.1)],
        ];
    } elseif (str_contains($tipo, 'techo') || str_contains($tipo, 'cubierta') || str_contains($tipo, 'teja')) {
        $mats = [
            ['categoria'=>'Madera y encofrado','subtipo'=>'Bastidor de madera 2"x4"','unidad'=>'ml','cantidad'=>round($metraje * 3)],
            ['categoria'=>'Cemento','subtipo'=>'Cemento Portland tipo I (saco 42.5 kg)','unidad'=>'saco','cantidad'=>round($metraje * 0.3)],
            ['categoria'=>'Impermeabilizantes','subtipo'=>'Membrana asfáltica (m²)','unidad'=>'m²','cantidad'=>round($metraje * 1.1)],
        ];
    } elseif (str_contains($tipo, 'viga') || str_contains($tipo, 'columna') || str_contains($tipo, 'fundaci')) {
        $mats = [
            ['categoria'=>'Cemento','subtipo'=>'Cemento Portland tipo I (saco 42.5 kg)','unidad'=>'saco','cantidad'=>round($metraje * 1.2)],
            ['categoria'=>'Arena y Agregados','subtipo'=>'Arena lavada (m³)','unidad'=>'m³','cantidad'=>round($metraje * 0.08, 2)],
            ['categoria'=>'Arena y Agregados','subtipo'=>'Gravilla / piedra picada (m³)','unidad'=>'m³','cantidad'=>round($metraje * 0.10, 2)],
            ['categoria'=>'Acero / Cabillas','subtipo'=>'Cabilla corrugada 1/2"','unidad'=>'ml','cantidad'=>round($metraje * 6)],
            ['categoria'=>'Acero / Cabillas','subtipo'=>'Cabilla corrugada 3/8"','unidad'=>'ml','cantidad'=>round($metraje * 4)],
        ];
    } elseif (str_contains($tipo, 'eléctric') || str_contains($tipo, 'electric')) {
        $mats = [
            ['categoria'=>'Cables eléctricos','subtipo'=>'Cable THHN #12 AWG','unidad'=>'ml','cantidad'=>round($metraje * 3.5)],
            ['categoria'=>'Cables eléctricos','subtipo'=>'Tubería conduit PVC 3/4"','unidad'=>'ml','cantidad'=>round($metraje * 1.5)],
        ];
    } else {
        // Genérico
        $mats = [
            ['categoria'=>'Cemento','subtipo'=>'Cemento Portland tipo I (saco 42.5 kg)','unidad'=>'saco','cantidad'=>round($metraje * 0.5)],
            ['categoria'=>'Arena y Agregados','subtipo'=>'Arena lavada (m³)','unidad'=>'m³','cantidad'=>round($metraje * 0.04, 2)],
        ];
    }

    return ['ok' => true, 'materiales' => $mats, 'nota' => $nota, 'fuente' => 'local'];
}
