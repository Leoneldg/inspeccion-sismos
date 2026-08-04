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
        .then(function (reg) {
          // Buscar una versión nueva en cada carga.
          reg.update();
          // Si hay una versión nueva esperando, tomarla.
          reg.addEventListener('updatefound', function () {
            var nuevo = reg.installing;
            if (!nuevo) return;
            nuevo.addEventListener('statechange', function () {
              // Ya instalado y hay un SW controlando: es una actualización.
              if (nuevo.state === 'installed' && navigator.serviceWorker.controller) {
                // La próxima navegación ya usará la versión nueva.
                console.info('Nueva versión de la app lista. Se aplicará al recargar.');
              }
            });
          });
        })
        .catch(function (e) { console.warn('No se pudo registrar el service worker:', e); });

      // Cuando el SW nuevo toma control, recargar una vez para usar datos frescos.
      var recargado = false;
      navigator.serviceWorker.addEventListener('controllerchange', function () {
        if (recargado) return;
        recargado = true;
        window.location.reload();
      });
    });
  }
</script>
</head>
<?php
// Detección de rol para decidir el chrome (sidebar de campo, barra móvil).
// Se calcula aquí, antes del <body>, para poder marcar su clase.
$esAdmin = usuarioEsMaster()
        || str_contains(mb_strtolower($_SESSION['rol_nombre'] ?? '', 'UTF-8'), 'administrador');
$esSistemPuro = !$esAdmin
             && function_exists('esSistematizador') && esSistematizador();
?>
<body class="<?= ($activeModule === 'dashboard' ? 'modo-tv' : '') ?><?= (isset($_GET['embed']) && $_GET['embed'] == '1') ? ' modo-embed' : '' ?><?= $esSistemPuro ? ' tiene-barra-campo' : '' ?>">
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
        <?php
        // $esAdmin y $esSistemPuro ya se calcularon antes del <body>.
        // El menú se redujo a lo que se usa a diario.
        ?>

        <?php if ($esSistemPuro): ?>
        <!-- ══════════════════════════════════════════════
             SIDEBAR DE CAMPO · para el sistematizador.
             Solo su trabajo de terreno, sin las fases del gobernador.
             ══════════════════════════════════════════════ -->
        <div class="nav-group open" data-group="campo">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <i class="bi bi-person-workspace"></i>
                <span>Mi trabajo de campo</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </div>
            <div class="nav-group-items">
                <a href="<?= APP_URL_BASE ?>seguimiento/mi_trabajo.php" class="nav-item <?= $activeModule==='mi_trabajo'?'active':'' ?>" title="Resumen de tu trabajo">
                    <i class="bi bi-house-door-fill"></i> <span>Inicio</span>
                </a>
                <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="nav-item <?= in_array($activeModule, ['seguimiento'], true)?'active':'' ?>" title="Buscar un edificio nuevo entre todos y levantarlo">
                    <i class="bi bi-search"></i> <span>Levantamiento</span>
                </a>
                <a href="<?= APP_URL_BASE ?>seguimiento/mi_seguimiento.php" class="nav-item <?= in_array($activeModule, ['mi_seguimiento','reconstruccion'], true)?'active':'' ?>" title="Tus edificios levantados: dale seguimiento al avance">
                    <i class="bi bi-clipboard-check"></i> <span>Seguimiento</span>
                </a>
                <a href="<?= APP_URL_BASE ?>seguimiento/requisiciones.php" class="nav-item <?= $activeModule==='requisiciones'?'active':'' ?>" title="Solicitar material para la obra">
                    <i class="bi bi-file-earmark-text"></i> <span>Requisiciones</span>
                </a>
            </div>
        </div>
        <?php else: ?>

        <!-- ══════════════════════════════════════════════
             BLOQUE DIRECTIVO · lo que abre el gobernador
             Sala de situación + las 3 fases + ficha de prensa.
             ══════════════════════════════════════════════ -->
        <?php if (puede('seguimiento','ver')): ?>
        <div class="nav-group open" data-group="directivo">
            <div class="nav-group-header" onclick="toggleNavGroup(this)">
                <i class="bi bi-bank"></i>
                <span>Gobernación</span>
                <i class="bi bi-chevron-down nav-chevron"></i>
            </div>
            <div class="nav-group-items">

                <a href="<?= APP_URL_BASE ?>dashboard/sala_situacion.php" class="nav-item <?= $activeModule==='sala_situacion'?'active':'' ?>" title="Cifras macro, impacto social y mapa de afectación">
                    <i class="bi bi-grid-1x2-fill"></i> <span>Sala de situación</span>
                </a>

                <!-- FASE 1 · Inspecciones (Edificaciones va dentro, en pestaña) -->
                <a href="<?= APP_URL_BASE ?>dashboard/fase1_inspecciones.php" class="nav-item <?= in_array($activeModule, ['fase1','seguimiento'], true)?'active':'' ?>" title="Fase 1: inspecciones y edificaciones">
                    <i class="bi bi-1-circle-fill"></i> <span>Fase 1 · Inspecciones</span>
                </a>

                <!-- FASE 2 · Reconstrucción (Levantamientos, Durante y Requisiciones van dentro, en pestañas) -->
                <a href="<?= APP_URL_BASE ?>dashboard/control_gubernamental.php" class="nav-item <?= in_array($activeModule, ['control_gub','fase2','reconstruccion','durante','requisiciones'], true)?'active':'' ?>" title="Fase 2: obras, levantamientos, durante y requisiciones">
                    <i class="bi bi-2-circle-fill"></i> <span>Fase 2 · Reconstrucción</span>
                </a>

                <!-- FASE 3 · Culminadas -->
                <a href="<?= APP_URL_BASE ?>dashboard/fase3_culminadas.php" class="nav-item <?= $activeModule==='fase3'?'active':'' ?>" title="Fase 3: edificaciones ya terminadas">
                    <i class="bi bi-3-circle-fill"></i> <span>Fase 3 · Culminadas</span>
                </a>

                <!-- Reportes como botones simples de PDF -->
                <a href="<?= APP_URL_BASE ?>seguimiento/pdf_ejecutivo.php" target="_blank" class="nav-item nav-pdf" title="Ficha de prensa: PDF ejecutivo de una página">
                    <i class="bi bi-file-earmark-pdf-fill"></i> <span>Ficha de prensa (PDF)</span>
                </a>
                <a href="<?= APP_URL_BASE ?>seguimiento/reporte.php" class="nav-item nav-pdf <?= $activeModule==='reporte'?'active':'' ?>" title="Estado del programa con gráficos">
                    <i class="bi bi-bar-chart-fill"></i> <span>Reporte ejecutivo</span>
                </a>

            </div>
        </div>
        <?php endif; ?>
        <?php endif; /* fin: sistematizador puro vs directivo */ ?>

        <!-- ==============================================
             Usuario y cierre de sesión.
             Va al final: el CSS lo empuja abajo con margin-top:auto.
             ============================================== -->
        <?php if (isLoggedIn()): ?>
        <div class="user-card">
            <div class="avatar"><?= e(mb_strtoupper(mb_substr($_SESSION['nombre'] ?? '?', 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
            <div class="info">
                <strong><?= e($_SESSION['nombre'] ?? '') ?></strong>
                <span><?= e($_SESSION['rol_nombre'] ?? '') ?></span>
            </div>
            <?php if ($esAdmin || puede('configuracion','ver')): ?>
            <a href="<?= APP_URL_BASE ?>admin/configuracion.php" class="logout" title="Ajustes y configuración" aria-label="Ajustes" style="margin-right:4px;">
                <i class="bi bi-gear-fill"></i>
            </a>
            <?php endif; ?>
            <a href="<?= APP_URL_BASE ?>logout.php" class="logout" title="Cerrar sesión" aria-label="Cerrar sesión">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
        <?php endif; ?>

    </aside>

    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

    <div class="main-col">

    <?php if ($esSistemPuro): ?>
    <!-- Barra inferior de navegación · SOLO móvil, solo sistematizador.
         El CSS la muestra únicamente en pantallas angostas. -->
    <nav class="barra-campo" aria-label="Navegación de campo">
        <a href="<?= APP_URL_BASE ?>seguimiento/mi_trabajo.php" class="bc-item <?= $activeModule==='mi_trabajo'?'on':'' ?>">
            <i class="bi bi-house-door<?= $activeModule==='mi_trabajo'?'-fill':'' ?>"></i><span>Inicio</span>
        </a>
        <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="bc-item <?= $activeModule==='seguimiento'?'on':'' ?>">
            <i class="bi bi-search"></i><span>Levantar</span>
        </a>
        <a href="<?= APP_URL_BASE ?>seguimiento/mi_seguimiento.php" class="bc-item <?= in_array($activeModule,['mi_seguimiento','reconstruccion'],true)?'on':'' ?>">
            <i class="bi bi-clipboard-check"></i><span>Seguir</span>
        </a>
        <a href="<?= APP_URL_BASE ?>seguimiento/requisiciones.php" class="bc-item <?= $activeModule==='requisiciones'?'on':'' ?>">
            <i class="bi bi-file-earmark-text"></i><span>Requis.</span>
        </a>
    </nav>
    <?php endif; ?>

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
