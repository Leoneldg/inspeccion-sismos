<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Página de diagnóstico para verificar registros y archivos de fotos
requierePermiso('dashboard', 'ver');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo "Uso: debug_photos.php?id=NN (ID de inspección)";
    exit;
}

$stmt = db()->prepare('SELECT * FROM inspecciones WHERE id = :id');
$stmt->execute(['id' => $id]);
$r = $stmt->fetch();
if (!$r) {
    echo "Inspección no encontrada.";
    exit;
}

echo "Inspección: " . htmlspecialchars($r['codigo'] . ' - ' . $r['nombre_edificio']) . "<br><br>";

if (!tablaFotosExiste()) {
    echo "La tabla 'inspeccion_fotos' no existe en la base de datos.<br>";
    exit;
}

$stmt = db()->prepare('SELECT * FROM inspeccion_fotos WHERE inspeccion_id = :id ORDER BY creado_en ASC');
$stmt->execute(['id' => $id]);
$fotos = $stmt->fetchAll();

if (!$fotos) {
    echo "No hay registros en 'inspeccion_fotos' para esta inspección.<br>";
    exit;
}

echo "Registros encontrados: " . count($fotos) . "<br><br>";
foreach ($fotos as $f) {
    $rutaRel = $f['ruta'];
    $rutaAbs = __DIR__ . '/../' . $rutaRel;
    echo "ID: " . (int)$f['id'] . " - Categoria: " . htmlspecialchars($f['categoria']) . " - Nombre original: " . htmlspecialchars($f['nombre_original']) . "<br>";
    echo "Ruta (DB): " . htmlspecialchars($rutaRel) . "<br>";
    echo "Ruta absoluta (servidor): " . htmlspecialchars($rutaAbs) . "<br>";
    echo "Archivo existe: " . (is_file($rutaAbs) ? '<strong style="color:green">Sí</strong>' : '<strong style="color:red">No</strong>') . "<br>";
    if (is_file($rutaAbs)) {
        echo "Tamaño: " . filesize($rutaAbs) . " bytes<br>";
        $mime = mime_content_type($rutaAbs) ?: 'desconocido';
        echo "Tipo MIME: " . htmlspecialchars($mime) . "<br>";
        echo "URL accesible: <a href=\"" . APP_URL_BASE . $rutaRel . "\" target=\"_blank\">" . APP_URL_BASE . $rutaRel . "</a><br>";
    }
    echo "<hr>";
}
