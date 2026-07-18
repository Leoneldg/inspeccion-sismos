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

<!-- PWA: app instalable con soporte offline -->
<link rel="manifest" href="<?= APP_URL_BASE ?>manifest.json">
<meta name="theme-color" content="#22366f">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Inspección">
<link rel="apple-touch-icon" href="<?= APP_URL_BASE ?>assets/pwa/icon-180.png">
<link rel="icon" type="image/png" sizes="192x192" href="<?= APP_URL_BASE ?>assets/pwa/icon-192.png">
<script>
  // Registrar el service worker (permite que la app funcione sin conexión).
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('<?= APP_URL_BASE ?>service-worker.js')
        .catch(function (e) { console.warn('No se pudo registrar el service worker:', e); });
    });
  }
</script>
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
            <div class="mark"><i class="bi bi-cone-striped"></i></div>
            <div class="txt">
                <strong>Obras Avanzadas</strong>
                <span>Seguimiento y control</span>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════
             GRUPO 1: Operaciones de campo
             ══════════════════════════════════════════════ -->
        <?php if (puede('dashboard','ver') || puede('formulario','ver') || puede('seguimiento','ver')): ?>
        <div class="nav-group <?= in_array($activeModule, ['dashboard','formulario','import_export','seguimiento']) ? 'open tiene-activo' : 'open' ?>" data-group="campo">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <i class="bi bi-clipboard2-check group-icon"></i>
                <span>Inspecciones</span>
                <i class="bi bi-chevron-down group-chevron"></i>
            </div>
            <div class="nav-group-items">
                <?php if (false && puede('dashboard','ver')): // OCULTO: sistema enfocado en Seguimiento y Control ?>
                <a href="<?= APP_URL_BASE ?>dashboard/index.php" class="nav-item <?= $activeModule==='dashboard'?'active':'' ?>" title="Dashboard">
                    <i class="bi bi-bar-chart-line-fill"></i> <span>Dashboard</span>
                </a>
                <?php endif; ?>
                <?php if (false && puede('formulario','ver')): // OCULTO: sistema enfocado en Seguimiento y Control ?>
                <a href="<?= APP_URL_BASE ?>formulario/index.php" class="nav-item <?= $activeModule==='formulario'?'active':'' ?>" title="Formulario de Inspección">
                    <i class="bi bi-clipboard2-check-fill"></i> <span>Formulario</span>
                </a>
                <?php endif; ?>
                <?php if (puede('seguimiento','ver')): ?>
                <?php if (function_exists('usuarioLimitadoAFrente') && usuarioLimitadoAFrente()): ?>
                <a href="<?= APP_URL_BASE ?>seguimiento/mi_frente.php" class="nav-item <?= $activeModule==='mi_frente'?'active':'' ?>" title="Mi frente de trabajo">
                    <i class="bi bi-diagram-3-fill"></i> <span>Mi frente</span>
                </a>
                <?php endif; ?>
                <?php if (function_exists('usuarioLimitadoAParroquia') && usuarioLimitadoAParroquia()): ?>
                <a href="<?= APP_URL_BASE ?>seguimiento/mi_parroquia.php" class="nav-item <?= $activeModule==='mi_parroquia'?'active':'' ?>" title="Mi parroquia">
                    <i class="bi bi-geo-alt-fill"></i> <span>Mi parroquia</span>
                </a>
                <?php endif; ?>
                <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="nav-item <?= $activeModule==='seguimiento'?'active':'' ?>" title="Seguimiento y Control">
                    <i class="bi bi-tools"></i> <span>Seguimiento y Control</span>
                </a>
                <a href="<?= APP_URL_BASE ?>seguimiento/en_reconstruccion.php" class="nav-item <?= $activeModule==='reconstruccion'?'active':'' ?>" title="Edificaciones en obra">
                    <i class="bi bi-hammer"></i> <span>En reconstrucción</span>
                </a>
                <?php if (puede('seguimiento','editar')): ?>
                <a href="<?= APP_URL_BASE ?>seguimiento/nueva_edificacion.php" class="nav-item <?= $activeModule==='nueva_edif'?'active':'' ?>" title="Agregar edificación">
                    <i class="bi bi-plus-circle"></i> <span>Agregar edificación</span>
                </a>
                <a href="<?= APP_URL_BASE ?>seguimiento/agregadas.php" class="nav-item <?= $activeModule==='agregadas'?'active':'' ?>" title="Edificaciones agregadas en campo">
                    <i class="bi bi-clipboard-plus"></i> <span>Agregadas en campo</span>
                </a>
                <?php endif; ?>
                <?php if (usuarioEsMaster()): ?>
                <a href="<?= APP_URL_BASE ?>seguimiento/limpiar_pruebas.php" class="nav-item <?= $activeModule==='limpiar'?'active':'' ?>" title="Limpiar levantamientos de prueba">
                    <i class="bi bi-trash3"></i> <span>Limpiar levantamientos</span>
                </a>
                <?php endif; ?>
                <a href="<?= APP_URL_BASE ?>seguimiento/representantes.php" class="nav-item <?= $activeModule==='representantes'?'active':'' ?>" title="Representantes por parroquia">
                    <i class="bi bi-people-fill"></i> <span>Representantes</span>
                </a>
                <a href="<?= APP_URL_BASE ?>seguimiento/frentes.php" class="nav-item <?= $activeModule==='frentes'?'active':'' ?>" title="Frentes de trabajo y cuadrillas">
                    <i class="bi bi-diagram-3-fill"></i> <span>Frentes de trabajo</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════
             GRUPO 2: Datos e informes
             ══════════════════════════════════════════════ -->
        <?php if (puede('import_export','ver') || puede('correcciones','ver')): ?>
        <div class="nav-group <?= in_array($activeModule, ['import_export','correcciones']) ? 'open tiene-activo' : '' ?>" data-group="datos">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <i class="bi bi-database group-icon"></i>
                <span>Datos e informes</span>
                <i class="bi bi-chevron-down group-chevron"></i>
            </div>
            <div class="nav-group-items">
                <?php if (puede('import_export','ver')): ?>
                <a href="<?= APP_URL_BASE ?>dashboard/informes.php" class="nav-item <?= $activeModule==='informes'?'active':'' ?>" title="Informes">
                    <i class="bi bi-file-earmark-bar-graph"></i> <span>Informes</span>
                </a>
                <?php endif; ?>
                <?php if (puede('import_export','ver')): ?>
                <a href="<?= APP_URL_BASE ?>dashboard/import_export.php" class="nav-item <?= $activeModule==='import_export'?'active':'' ?>" title="Importar / Exportar">
                    <i class="bi bi-arrow-left-right"></i> <span>Importar / Exportar</span>
                </a>
                <?php endif; ?>
                <?php if (puede('correcciones','ver')): ?>
                <a href="<?= APP_URL_BASE ?>admin/correcciones.php" class="nav-item <?= $activeModule==='correcciones'?'active':'' ?>" title="Correcciones">
                    <i class="bi bi-clipboard2-pulse-fill"></i> <span>Correcciones</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════
             GRUPO 3: Administración de personas
             ══════════════════════════════════════════════ -->
        <?php if (puede('usuarios','ver') || puede('ingenieros','ver')): ?>
        <div class="nav-group <?= in_array($activeModule, ['usuarios','roles','ingenieros']) ? 'open tiene-activo' : '' ?>" data-group="admin">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <i class="bi bi-people group-icon"></i>
                <span>Administración</span>
                <i class="bi bi-chevron-down group-chevron"></i>
            </div>
            <div class="nav-group-items">
                <?php if (puede('usuarios','ver')): ?>
                <a href="<?= APP_URL_BASE ?>admin/usuarios.php" class="nav-item <?= $activeModule==='usuarios'?'active':'' ?>" title="Usuarios">
                    <i class="bi bi-people-fill"></i> <span>Usuarios</span>
                </a>
                <a href="<?= APP_URL_BASE ?>admin/sistematizadores.php" class="nav-item <?= $activeModule==='sistematizadores'?'active':'' ?>" title="Sistematizadores">
                    <i class="bi bi-person-check-fill"></i> <span>Sistematizadores</span>
                </a>
                <?php if (puede('usuarios','editar')): ?>
                <a href="<?= APP_URL_BASE ?>admin/roles.php" class="nav-item <?= $activeModule==='roles'?'active':'' ?>" title="Roles y Permisos">
                    <i class="bi bi-shield-lock-fill"></i> <span>Roles y Permisos</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (puede('ingenieros','ver')): ?>
                <a href="<?= APP_URL_BASE ?>admin/ingenieros.php" class="nav-item <?= $activeModule==='ingenieros'?'active':'' ?>" title="Ingenieros / Inspectores">
                    <i class="bi bi-person-vcard-fill"></i> <span>Ingenieros</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════
             GRUPO 4: Sistema (solo superadmin)
             ══════════════════════════════════════════════ -->
        <?php if (puede('configuracion','ver') || (function_exists('usuarioEsMaster') && usuarioEsMaster())): ?>
        <div class="nav-group <?= in_array($activeModule, ['configuracion','entes_resumen']) ? 'open tiene-activo' : '' ?>" data-group="sistema">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <i class="bi bi-gear group-icon"></i>
                <span>Sistema</span>
                <i class="bi bi-chevron-down group-chevron"></i>
            </div>
            <div class="nav-group-items">
                <?php if (function_exists('usuarioEsMaster') && usuarioEsMaster()): ?>
                <a href="<?= APP_URL_BASE ?>admin/entes_resumen.php" class="nav-item <?= $activeModule==='entes_resumen'?'active':'' ?>" title="Resumen por ente">
                    <i class="bi bi-hdd-stack-fill"></i> <span>Bases por ente</span>
                </a>
                <?php endif; ?>
                <?php if (puede('configuracion','ver')): ?>
                <a href="<?= APP_URL_BASE ?>admin/configuracion.php" class="nav-item <?= $activeModule==='configuracion'?'active':'' ?>" title="Configuración del Sistema">
                    <i class="bi bi-sliders"></i> <span>Configuración</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
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
<script>
function toggleNavGroup(header) {
    var group = header.closest('.nav-group');
    if (!group) return;
    group.classList.toggle('open');
    // Guardar estado en localStorage para recordar qué grupos están abiertos
    var groupId = group.dataset.group;
    if (groupId) {
        var key = 'nav_group_' + groupId;
        localStorage.setItem(key, group.classList.contains('open') ? '1' : '0');
    }
}
// Restaurar estado de grupos (excepto el que tiene el ítem activo, que siempre abre)
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.nav-group').forEach(function(group) {
        var id = group.dataset.group;
        if (!id) return;
        // Si tiene ítem activo, siempre abierto — no consultar localStorage
        if (group.classList.contains('tiene-activo')) return;
        var stored = localStorage.getItem('nav_group_' + id);
        // Por defecto cerrado si no hay preferencia guardada
        if (stored === '1') {
            group.classList.add('open');
        } else if (stored === null) {
            // Primera vez: dejar cerrado (excepto Inspecciones que abre por defecto)
            if (id === 'campo') group.classList.add('open');
        }
    });
});
</script>
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
