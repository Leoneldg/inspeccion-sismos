<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Acceso denegado · <?= e(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= APP_URL_BASE ?>assets/css/style.css?v=<?= ASSET_VERSION ?>">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--azul-950);">
    <div class="card" style="max-width:420px;text-align:center;padding:36px 30px;">
        <div style="font-size:40px;color:var(--rojo);"><i class="bi bi-shield-lock-fill"></i></div>
        <h2 style="margin-top:14px;">Acceso denegado</h2>
        <p class="text-muted" style="margin-top:8px;">Su rol no tiene permisos para ver este módulo. Si considera que esto es un error, contacte al administrador del sistema.</p>
        <a href="<?= APP_URL_BASE ?>index.php" class="btn btn-primary" style="margin-top:14px;"><i class="bi bi-arrow-left"></i> Volver al inicio</a>
    </div>
</body>
</html>
