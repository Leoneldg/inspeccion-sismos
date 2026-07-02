<?php
/**
 * Requiere que la página que incluye este archivo haya definido:
 *   $pageTitle    (string)
 *   $pageSubtitle (string, opcional)
 *   $activeModule (string: 'dashboard' | 'formulario' | 'usuarios')
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
<body>
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
        <?php if (puede('usuarios', 'ver')): ?>
        <div class="nav-label">Administración</div>
        <a href="<?= APP_URL_BASE ?>admin/usuarios.php" class="nav-item <?= $activeModule === 'usuarios' ? 'active' : '' ?>" title="Usuarios">
            <i class="bi bi-people-fill"></i> <span>Usuarios</span>
        </a>
        <a href="<?= APP_URL_BASE ?>admin/roles.php" class="nav-item <?= $activeModule === 'roles' ? 'active' : '' ?>" title="Roles y Permisos">
            <i class="bi bi-shield-lock-fill"></i> <span>Roles y Permisos</span>
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

    <div class="main-col">
        <div class="topbar">
            <div class="flex items-center gap-12">
                <button class="btn-menu" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="bi bi-list"></i></button>
                <div>
                    <div class="title"><?= e($pageTitle) ?></div>
                    <?php if ($pageSubtitle): ?><div class="subtitle"><?= e($pageSubtitle) ?></div><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="content">
            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?= e($f['tipo']) ?>">
                    <i class="bi bi-<?= $f['tipo'] === 'error' ? 'exclamation-triangle-fill' : ($f['tipo'] === 'success' ? 'check-circle-fill' : 'info-circle-fill') ?>"></i>
                    <div><?= e($f['mensaje']) ?></div>
                </div>
            <?php endforeach; ?>
