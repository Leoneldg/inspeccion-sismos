<?php
/**
 * Requiere que la página que incluye este archivo haya definido:
 *   $pageTitle    (string)
 *   $pageSubtitle (string, opcional)
 *   $activeModule (string: 'dashboard' | 'formulario' | 'usuarios' | 'roles' | 'import_export' | 'configuracion' | 'ingenieros')
 */
$pageTitle    = $pageTitle ?? APP_NAME;
$pageSubtitle = $pageSubtitle ?? '';
$activeModule = $activeModule ?? '';
$flashes = obtenerFlashes();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= APP_URL_BASE ?>assets/css/style.css?v=<?= ASSET_VERSION ?>">
</head>
<body<?= $activeModule === 'dashboard' ? ' class="modo-tv"' : '' ?>>
<div class="offline-banner">
    <i class="bi bi-wifi-off"></i>
    Sin conexión: lo que guardes se sube automáticamente al recuperar señal.
</div>
<script>
    // Aplica el estado colapsado del sidebar ANTES de pintar, evitando parpadeo.
    if (localStorage.getItem('sidebar_collapsed') === '1') {
        document.body.classList.add('sidebar-collapsed');
    }
</script>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <button class="sidebar-toggle" id="sidebar-toggle" title="Contraer / expandir menú">
            <i class="bi bi-chevron-double-left"></i>
        </button>
        <div class="brand">
            <div class="mark"><i class="bi bi-buildings"></i></div>
            <div class="txt">
                <strong>Post-Sismo</strong>
                <span>Inspección de edificaciones</span>
            </div>
        </div>

        <div class="nav-label">Módulos</div>
        <?php if (puede('dashboard', 'ver')): ?>
        <a href="<?= APP_URL_BASE ?>dashboard/index.php" class="nav-item <?= $activeModule === 'dashboard' ? 'active' : '' ?>" title="Dashboard">
            <i class="bi bi-bar-chart-line-fill"></i> <span>Dashboard</span>
        </a>
        <?php endif; ?>
        <?php if (puede('formulario', 'ver')): ?>
        <a href="<?= APP_URL_BASE ?>formulario/index.php" class="nav-item <?= $activeModule === 'formulario' ? 'active' : '' ?>" title="Formulario de Inspección">
            <i class="bi bi-clipboard2-check-fill"></i> <span>Formulario de Inspección</span>
        </a>
        <?php endif; ?>
        <?php if (puede('import_export', 'ver')): ?>
        <a href="<?= APP_URL_BASE ?>dashboard/import_export.php" class="nav-item <?= $activeModule === 'import_export' ? 'active' : '' ?>" title="Importar / Exportar">
            <i class="bi bi-upload"></i> <span>Importar / Exportar</span>
        </a>
        <?php endif; ?>
        <?php if (puede('seguimiento', 'ver')): ?>
        <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="nav-item <?= $activeModule === 'seguimiento' ? 'active' : '' ?>" title="Seguimiento y Control">
            <i class="bi bi-clipboard-data-fill"></i> <span>Seguimiento y Control</span>
        </a>
        <?php endif; ?>
        <?php if (puede('usuarios', 'ver')): ?>
        <div class="nav-label">Administración</div>
        <a href="<?= APP_URL_BASE ?>admin/usuarios.php" class="nav-item <?= $activeModule === 'usuarios' ? 'active' : '' ?>" title="Usuarios">
            <i class="bi bi-people-fill"></i> <span>Usuarios</span>
        </a>
        <a href="<?= APP_URL_BASE ?>admin/roles.php" class="nav-item <?= $activeModule === 'roles' ? 'active' : '' ?>" title="Roles y Permisos">
            <i class="bi bi-shield-lock-fill"></i> <span>Roles y Permisos</span>
        </a>
        <?php endif; ?>
        <?php if (puede('ingenieros', 'ver')): ?>
        <a href="<?= APP_URL_BASE ?>admin/ingenieros.php" class="nav-item <?= $activeModule === 'ingenieros' ? 'active' : '' ?>" title="Ingenieros / Inspectores">
            <i class="bi bi-person-vcard-fill"></i> <span>Ingenieros / Inspectores</span>
        </a>
        <?php endif; ?>
        <?php if (puede('configuracion', 'ver')): ?>
        <a href="<?= APP_URL_BASE ?>admin/configuracion.php" class="nav-item <?= $activeModule === 'configuracion' ? 'active' : '' ?>" title="Configuración del Sistema">
            <i class="bi bi-sliders"></i> <span>Configuración del Sistema</span>
        </a>
        <?php endif; ?>

        <div class="user-card">
            <div class="avatar"><?= e(mb_strtoupper(mb_substr($_SESSION['nombre'] ?? '?', 0, 1))) ?></div>
            <div class="info">
                <strong><?= e($_SESSION['nombre'] ?? '') ?></strong>
                <span><?= e($_SESSION['rol_nombre'] ?? '') ?></span>
            </div>
            <a href="<?= APP_URL_BASE ?>logout.php" class="logout" title="Cerrar sesión"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </aside>

    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

    <div class="main-col">
        <div class="topbar">
            <div class="flex items-center gap-12">
                <button class="btn-menu" id="btn-menu" aria-label="Abrir menú"><i class="bi bi-list"></i></button>
                <div>
                    <div class="title"><?= e($pageTitle) ?></div>
                    <?php if ($pageSubtitle): ?><div class="subtitle"><?= e($pageSubtitle) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="pendientes-offline oculto-offline" data-pendientes-offline-wrap title="Inspecciones guardadas localmente esperando señal para subirse">
                <i class="bi bi-cloud-arrow-up"></i>
                <span data-pendientes-offline>0</span> por subir
                <button type="button" class="btn-reintentar-offline oculto-offline" data-pendientes-offline-error
                        title="Algunas no se pudieron sincronizar automáticamente (sesión expirada u otro error). Click para reintentar."
                        onclick="window.SismosOffline && window.SismosOffline.reintentarFallidos()">
                    <i class="bi bi-exclamation-triangle-fill"></i> <span data-pendientes-offline-error-count>0</span> con error, reintentar
                </button>
            </div>
        </div>
        <div class="content">
            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?= e($f['tipo']) ?>">
                    <i class="bi bi-<?= $f['tipo'] === 'error' ? 'exclamation-triangle-fill' : ($f['tipo'] === 'success' ? 'check-circle-fill' : 'info-circle-fill') ?>"></i>
                    <div><?= e($f['mensaje']) ?></div>
                </div>
            <?php endforeach; ?>
