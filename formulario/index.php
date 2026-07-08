<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

requierePermiso('formulario', 'ver');

if (!empty($_GET['guardado_offline'])) {
    flash('info', 'Sin conexión: la inspección quedó guardada en este dispositivo y se subirá automáticamente en cuanto vuelva la señal. No cierres sesión en un dispositivo compartido hasta que se sincronice.');
}

$pageTitle    = 'Formulario de Inspección';
$pageSubtitle = 'Listado de inspecciones registradas';
$activeModule = 'formulario';

$q         = trim($_GET['q'] ?? '');
$parroquia = trim($_GET['parroquia'] ?? '');
$estadoFiltroList = trim($_GET['estado'] ?? '');
$tanque    = trim($_GET['tanque'] ?? '');
$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = 15;

$where  = [];
$params = [];
// Alcance nacional: el estadal queda restringido a su estado; el master
// puede filtrar por el estado que quiera desde el desplegable.
// Aislamiento: si el usuario pertenece a un ente, se filtra POR ENTE (una
// Gobernación ve su estado; el resto, su ente). Si no tiene ente, se mantiene
// el alcance por estado como antes.
$tieneEnteInsp = columnaInspeccionExiste('ente_id') && enteDelUsuario() !== null;
if ($tieneEnteInsp) {
    aplicarScopeEnte($where, $params, 'ente_id', 'estado');
} else {
    aplicarScopeEstado($where, $params);
}
if (usuarioEsMaster() && $estadoFiltroList !== '' && $estadoFiltroList !== 'todos') {
    $where[] = 'estado = :estado_f';
    $params['estado_f'] = $estadoFiltroList;
}
if ($q !== '') {
    $where[] = '(nombre_edificio LIKE :q1 OR codigo LIKE :q2 OR ing1_nombre LIKE :q3)';
    $params['q1'] = "%$q%";
    $params['q2'] = "%$q%";
    $params['q3'] = "%$q%";
}
if ($parroquia !== '' && $parroquia !== 'todas') {
    $where[] = 'parroquia = :parroquia';
    $params['parroquia'] = $parroquia;
}
if ($tanque === 'si') {
    $where[] = 'tiene_tanque_agua = 1';
} elseif ($tanque === 'no') {
    $where[] = '(tiene_tanque_agua = 0 OR tiene_tanque_agua IS NULL)';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$pdo = db();
$stmtCount = $pdo->prepare("SELECT COUNT(*) AS c FROM inspecciones $whereSql");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetch()['c'];
$totalPaginas = max(1, (int)ceil($total / $porPagina));
$offset = ($pagina - 1) * $porPagina;

$sql = "SELECT id, codigo, nombre_edificio, estado, municipio, parroquia, fecha_inspeccion, decision_final, ing1_nombre, familias, tiene_tanque_agua
        FROM inspecciones $whereSql ORDER BY creado_en DESC LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue(":$k", $v); }
$stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$inspecciones = $stmt->fetchAll();

$parroquias = catalogoParroquias();
sort($parroquias, SORT_LOCALE_STRING);
$decisiones = catalogoDecisionFinal();

function badgeClase($decision) {
    if (str_contains($decision, 'Acceso Permitido')) return 'badge-verde';
    if (str_contains($decision, 'Precaución')) return 'badge-amarillo';
    if (str_contains($decision, 'No Permitido')) return 'badge-rojo';
    return 'badge-gris';
}

include __DIR__ . '/../includes/header.php';
?>

<div class="flex justify-between items-center gap-12" style="flex-wrap:wrap;margin-bottom:16px;">
    <form method="get" class="flex gap-8" style="flex-wrap:wrap;">
        <input type="text" name="q" class="form-control" style="width:260px;" placeholder="Buscar por edificio, código o inspector…" value="<?= e($q) ?>">
        <?php if (usuarioEsMaster()): ?>
        <select name="estado" class="form-control" style="width:190px;">
            <option value="todos">Todos los estados</option>
            <?php foreach (catalogoEstados() as $estOpt): ?>
                <option value="<?= e($estOpt) ?>" <?= $estadoFiltroList === $estOpt ? 'selected' : '' ?>><?= e($estOpt) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="parroquia" class="form-control" style="width:200px;">
            <option value="todas">Todas las parroquias</option>
            <?php foreach ($parroquias as $p): ?>
                <option value="<?= e($p) ?>" <?= $parroquia === $p ? 'selected' : '' ?>><?= e($p) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="tanque" class="form-control" style="width:190px;">
            <option value="">Tanque de agua: todos</option>
            <option value="si" <?= $tanque === 'si' ? 'selected' : '' ?>>Con tanque de agua</option>
            <option value="no" <?= $tanque === 'no' ? 'selected' : '' ?>>Sin tanque de agua</option>
        </select>
        <button class="btn btn-outline"><i class="bi bi-search"></i> Buscar</button>
    </form>
    <?php if (puede('formulario', 'crear')): ?>
    <a href="<?= APP_URL_BASE ?>formulario/create.php" class="btn btn-accent">
        <i class="bi bi-plus-lg"></i> Nueva inspección
    </a>
    <?php endif; ?>
</div>

<div class="card contenido-online">
    <?php if (!$inspecciones): ?>
        <div class="empty-state">
            <i class="bi bi-clipboard2-x"></i>
            No se encontraron inspecciones con los filtros aplicados.
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Edificación</th>
                    <?php if (usuarioEsMaster()): ?><th>Estado</th><?php endif; ?>
                    <th>Parroquia</th>
                    <th>Fecha</th>
                    <th>Inspector</th>
                    <th>Familias</th>
                    <th>Decisión</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($inspecciones as $r): ?>
                <tr>
                    <td><span style="font-family:var(--font-mono);font-size:12.5px;color:var(--gris-500);"><?= e($r['codigo']) ?></span></td>
                    <td><strong><?= e($r['nombre_edificio']) ?></strong> <?php if (!empty($r['tiene_tanque_agua'])): ?><i class="bi bi-droplet-fill text-sm" style="color:var(--azul-500);" title="Tiene tanque de agua"></i><?php endif; ?></td>
                    <?php if (usuarioEsMaster()): ?><td><span class="badge badge-gris"><?= e($r['estado'] ?? '—') ?></span></td><?php endif; ?>
                    <td><?= e($r['parroquia']) ?><?php if (!empty($r['municipio']) && ($r['estado'] ?? '') !== 'Distrito Capital'): ?><br><span class="text-sm text-muted"><?= e($r['municipio']) ?></span><?php endif; ?></td>
                    <td><?= e($r['fecha_inspeccion']) ?></td>
                    <td><?= e($r['ing1_nombre']) ?></td>
                    <td><?= (int)$r['familias'] ?></td>
                    <td><span class="badge <?= badgeClase($r['decision_final']) ?>"><?= e($decisiones[$r['decision_final']]['corto'] ?? $r['decision_final']) ?></span></td>
                    <td>
                        <div class="flex gap-8">
                            <a href="<?= APP_URL_BASE ?>formulario/view.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm" title="Ver"><i class="bi bi-eye"></i></a>
                            <button type="button" class="btn btn-outline btn-sm" title="Ver código QR"
                                onclick="abrirModalQR('<?= e(urlAbsoluta('dashboard/export_pdf.php?id=' . (int)$r['id'] . '&token=' . tokenPdfPublico((int)$r['id']))) ?>', '<?= e($r['codigo']) ?>')">
                                <i class="bi bi-qr-code"></i>
                            </button>
                            <?php if (puede('formulario', 'editar')): ?>
                            <a href="<?= APP_URL_BASE ?>formulario/create.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm" title="Editar"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (puede('formulario', 'eliminar')): ?>
                            <form method="post" action="<?= APP_URL_BASE ?>formulario/delete.php" onsubmit="return confirm('¿Eliminar esta inspección de forma permanente?');">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-danger btn-sm" title="Eliminar"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($totalPaginas > 1): ?>
<div class="flex wrap-on-small gap-8 contenido-online" style="margin-top:16px;justify-content:center;align-items:center;">
    <?php
    // Paginación resumida: siempre muestra primera, última, actual y 2 vecinas.
    // Entre bloques no contiguos inserta "…"
    $url = fn($p) => '?pagina=' . $p . '&q=' . urlencode($q) . '&parroquia=' . urlencode($parroquia) . '&estado=' . urlencode($estadoFiltroList);
    $mostrar = [];
    for ($p = 1; $p <= $totalPaginas; $p++) {
        if ($p === 1 || $p === $totalPaginas                  // primera y última siempre
            || abs($p - $pagina) <= 2) {                       // ±2 de la actual
            $mostrar[] = $p;
        }
    }
    $mostrar = array_unique($mostrar);
    sort($mostrar);
    // botón anterior
    if ($pagina > 1): ?>
        <a class="btn btn-sm btn-outline" href="<?= $url($pagina - 1) ?>"><i class="bi bi-chevron-left"></i></a>
    <?php endif;
    $prev = null;
    foreach ($mostrar as $p):
        if ($prev !== null && $p - $prev > 1): ?>
            <span style="padding:0 4px;color:var(--gris-400);">…</span>
        <?php endif; ?>
        <a class="btn btn-sm <?= $p === $pagina ? 'btn-primary' : 'btn-outline' ?>"
           href="<?= $url($p) ?>"><?= $p ?></a>
    <?php $prev = $p; endforeach;
    // botón siguiente
    if ($pagina < $totalPaginas): ?>
        <a class="btn btn-sm btn-outline" href="<?= $url($pagina + 1) ?>"><i class="bi bi-chevron-right"></i></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- =====================================================================
     PANEL OFFLINE: inspecciones pendientes de subir
     ===================================================================== -->
<div id="panel-offline-pendientes" style="display:none;margin-top:0;">
    <div class="card" style="border-top:4px solid var(--azul-700,#22366f);">
        <div class="card-header" style="background:var(--azul-900,#101b42);color:#fff;border-radius:0;">
            <div>
                <h2 style="color:#fff;margin:0;"><i class="bi bi-cloud-slash-fill"></i> Sin conexión</h2>
                <div style="font-size:13px;color:#aab4d8;margin-top:2px;">Inspecciones guardadas en este dispositivo</div>
            </div>
            <span id="offline-resumen" class="badge" style="background:#fff2;color:#fff;font-size:12px;">Cargando…</span>
        </div>
        <div class="card-body" style="padding:0;">
            <div id="offline-lista-pendientes" style="padding:16px;min-height:80px;"></div>
        </div>
    </div>
</div>

<!-- Botón flotante (con internet, cuando hay pendientes) -->
<button id="btn-flotante-pendientes"
    style="display:none;position:fixed;bottom:24px;right:24px;z-index:900;padding:12px 20px;background:var(--azul-700,#22366f);color:#fff;border:none;border-radius:50px;box-shadow:0 4px 18px rgba(0,0,0,.3);cursor:pointer;font-size:13px;font-weight:700;gap:8px;align-items:center;"
    onclick="window.SismosOfflinePanel?.abrir()">
    <i class="bi bi-cloud-arrow-up-fill"></i>
    <span id="btn-flotante-label">Pendientes</span>
</button>

<!-- Modal (con internet) -->
<div id="modal-pendientes-offline"
    style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9998;align-items:flex-start;justify-content:center;padding:24px;overflow-y:auto;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:680px;margin:auto;box-shadow:0 12px 48px rgba(0,0,0,.3);">
        <!-- Cabecera modal -->
        <div style="padding:18px 20px;border-bottom:1px solid var(--gris-200);display:flex;justify-content:space-between;align-items:center;border-radius:16px 16px 0 0;background:var(--azul-900,#101b42);">
            <div>
                <h3 style="margin:0;font-size:16px;color:#fff;"><i class="bi bi-cloud-arrow-up-fill"></i> Inspecciones pendientes de subir</h3>
                <div id="buzon-resumen" style="font-size:12px;color:#aab4d8;margin-top:2px;"></div>
            </div>
            <button onclick="window.SismosOfflinePanel?.cerrar()" style="background:none;border:none;font-size:26px;cursor:pointer;color:#fff;line-height:1;">×</button>
        </div>
        <!-- Lista -->
        <div id="buzon-offline-lista" style="padding:16px;max-height:60vh;overflow-y:auto;"></div>
        <!-- Pie -->
        <div style="padding:12px 20px;border-top:1px solid var(--gris-200);display:flex;gap:8px;justify-content:flex-end;border-radius:0 0 16px 16px;">
            <button id="buzon-reintentar-todo" class="btn btn-primary btn-sm" disabled>
                <i class="bi bi-arrow-repeat"></i> Reintentar todo
            </button>
            <button onclick="window.SismosOfflinePanel?.cerrar()" class="btn btn-outline btn-sm">Cerrar</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var offline = function () { return window.SismosOffline; };
    var BASE    = window._APP_URL_BASE || '/';

    // ── Render del panel sin conexión ─────────────────────────────────────────
    async function renderPanelOffline() {
        var cont    = document.getElementById('offline-lista-pendientes');
        var resumen = document.getElementById('offline-resumen');
        if (!cont || !offline()) return;

        var pendientes = [];
        try { pendientes = await offline().listarPendientes(); } catch(e) {
            cont.innerHTML = '<p style="color:#b42318;padding:8px;"><i class="bi bi-exclamation-circle"></i> Error accediendo al almacenamiento local.</p>';
            return;
        }

        var MAX = offline().MAX_INTENTOS_SYNC || 8;
        if (resumen) resumen.textContent = pendientes.length + ' inspección(es)';

        if (!pendientes.length) {
            cont.innerHTML = '<div style="text-align:center;padding:24px;color:var(--gris-500);"><i class="bi bi-check2-circle" style="font-size:36px;color:#1a8a4a;display:block;margin-bottom:8px;"></i>No hay inspecciones pendientes.</div>';
            return;
        }

        cont.innerHTML = renderItems(pendientes, MAX, false);
        adjuntarEventos(cont, false);
    }

    // ── Render del modal (con internet) ───────────────────────────────────────
    async function renderModal() {
        var cont    = document.getElementById('buzon-offline-lista');
        var resumen = document.getElementById('buzon-resumen');
        if (!cont || !offline()) return;

        var pendientes = [];
        try { pendientes = await offline().listarPendientes(); } catch(e) {
            cont.innerHTML = '<p style="color:#b42318;padding:8px;"><i class="bi bi-exclamation-circle"></i> Error accediendo al almacenamiento.</p>';
            return;
        }

        var MAX = offline().MAX_INTENTOS_SYNC || 8;
        var conError = pendientes.filter(function(p){ return (p.intentos||0)>=MAX; }).length;
        if (resumen) resumen.textContent = pendientes.length + ' pendiente' + (pendientes.length===1?'':'s')
            + (conError ? ' · ' + conError + ' con error' : '');

        var btnTodo = document.getElementById('buzon-reintentar-todo');
        if (btnTodo) btnTodo.disabled = !pendientes.length || !navigator.onLine;

        if (!pendientes.length) {
            cont.innerHTML = '<div style="text-align:center;padding:24px;color:var(--gris-500);"><i class="bi bi-check2-circle" style="font-size:36px;color:#1a8a4a;display:block;margin-bottom:8px;"></i>Todo sincronizado.</div>';
            return;
        }

        cont.innerHTML = renderItems(pendientes, MAX, true);
        adjuntarEventos(cont, true);
    }

    // ── Genera el HTML de las tarjetas ────────────────────────────────────────
    function esc(s) { return String(s||'').replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
    function fmtFecha(ts) {
        if (!ts) return '—';
        var d = new Date(ts);
        return d.toLocaleDateString('es-VE') + ' ' + d.toLocaleTimeString('es-VE',{hour:'2-digit',minute:'2-digit'});
    }

    function renderItems(pendientes, MAX, esModal) {
        // Ordenar: con error primero, luego fecha descendente
        pendientes = pendientes.slice().sort(function(a,b){
            var ae=(a.intentos||0)>=MAX, be=(b.intentos||0)>=MAX;
            return ae!==be?(be?1:-1):(b.creado||0)-(a.creado||0);
        });
        return pendientes.map(function(p) {
            var meta      = p.meta||{};
            var intentos  = p.intentos||0;
            var agotado   = intentos>=MAX;
            var subiendo  = !!p.subiendo;
            var fotos     = Array.isArray(p.campos)?p.campos.filter(function(c){return c&&c.isFile;}).length:0;
            var nombre    = meta.nombre_edificio||'(Sin nombre)';
            var ubic      = [meta.parroquia,meta.municipio,meta.estado].filter(Boolean).join(', ');

            var badge = subiendo
                ? '<span class="badge badge-azul"><i class="bi bi-arrow-repeat girando"></i> Subiendo…</span>'
                : agotado
                ? '<span class="badge badge-rojo"><i class="bi bi-exclamation-circle"></i> Error</span>'
                : intentos>0
                ? '<span class="badge badge-amarillo"><i class="bi bi-clock-history"></i> '+intentos+'/'+MAX+' intentos</span>'
                : '<span class="badge badge-gris">Pendiente</span>';

            var errorMsg = p.ultimoError
                ? '<div style="margin:8px 0;padding:8px 12px;background:#fff5f5;border:1px solid #fca5a5;border-radius:6px;font-size:12.5px;color:#b42318;">'
                  + '<i class="bi bi-exclamation-triangle-fill"></i> ' + esc(p.ultimoError) + '</div>'
                : '';

            var barra = subiendo
                ? '<div style="height:4px;background:#e5e7eb;border-radius:2px;margin:8px 0;overflow:hidden;"><div style="height:100%;background:var(--azul-700);border-radius:2px;animation:progresoBarra 1.5s ease-in-out infinite;"></div></div>'
                : '';

            var botones = '';
            if (!subiendo) {
                if (navigator.onLine || esModal) {
                    botones += '<button type="button" class="btn btn-primary btn-sm btn-rein-off" data-id="'+p.id+'">'
                        +'<i class="bi bi-arrow-repeat"></i> '+(agotado?'Reintentar':'Subir ahora')+'</button>';
                }
                botones += '<a href="'+BASE+'formulario/create.php?editar_offline='+p.id+'" class="btn btn-outline btn-sm">'
                    +'<i class="bi bi-pencil"></i> Editar</a>';
                botones += '<button type="button" class="btn btn-danger btn-sm btn-del-off" data-id="'+p.id+'">'
                    +'<i class="bi bi-trash"></i> Eliminar</button>';
            }

            return '<div class="buzon-item'+(agotado?' buzon-item-error':subiendo?' buzon-item-subiendo':'')+'" data-id="'+p.id+'" '
                +'style="border:1px solid var(--gris-200);border-radius:10px;padding:14px;margin-bottom:10px;background:#fff;">'
                +'<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">'
                    +'<div style="flex:1;min-width:0;">'
                        +'<div style="font-weight:700;font-size:14px;margin-bottom:3px;"><i class="bi bi-building"></i> '+esc(nombre)+'</div>'
                        +'<div style="font-size:12px;color:var(--gris-500);">'
                            +(ubic?'<span><i class="bi bi-geo-alt"></i> '+esc(ubic)+'</span> ':'')
                            +(meta.fecha_inspeccion?'<span><i class="bi bi-calendar3"></i> '+esc(meta.fecha_inspeccion)+'</span> ':'')
                            +(fotos?'<span><i class="bi bi-camera"></i> '+fotos+' foto'+(fotos===1?'':'s')+'</span> ':'')
                            +'<span><i class="bi bi-clock-history"></i> '+fmtFecha(p.creado)+'</span>'
                        +'</div>'
                    +'</div>'
                    +'<div>'+badge+'</div>'
                +'</div>'
                +errorMsg+barra
                +(botones?'<div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;">'+botones+'</div>':'')
            +'</div>';
        }).join('');
    }

    // ── Eventos de botones ────────────────────────────────────────────────────
    function adjuntarEventos(cont, esModal) {
        cont.querySelectorAll('.btn-rein-off').forEach(function(btn) {
            btn.addEventListener('click', async function() {
                var id = +btn.dataset.id;
                if (!navigator.onLine) {
                    alert('Sin conexión. Conéctese para reintentar.');
                    return;
                }
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-arrow-repeat girando"></i> Reintentando…';
                try {
                    await offline().reintentarUno(id);
                } catch(e) {
                    window.SismosToast?.('<i class="bi bi-exclamation-triangle-fill"></i> Error: '+e.message, 'error');
                }
                if (esModal) await renderModal(); else await renderPanelOffline();
                actualizarBotónFlotante();
            });
        });
        cont.querySelectorAll('.btn-del-off').forEach(function(btn) {
            btn.addEventListener('click', async function() {
                var id  = +btn.dataset.id;
                var fil = cont.querySelector('[data-id="'+id+'"]');
                var nom = fil?.querySelector('[style*="font-weight:700"]')?.textContent?.trim()||'esta inspección';
                if (!confirm('¿Eliminar '+nom+' del dispositivo?\n\nSi aún no se subió al servidor, los datos se perderán.')) return;
                btn.disabled = true;
                try {
                    await offline().eliminarPendiente(id);
                    await offline().actualizarBadge();
                } catch(e) { alert('No se pudo eliminar: '+e.message); btn.disabled=false; return; }
                if (esModal) await renderModal(); else await renderPanelOffline();
                actualizarBotónFlotante();
            });
        });
    }

    // ── Botón flotante ────────────────────────────────────────────────────────
    async function actualizarBotónFlotante() {
        var btn = document.getElementById('btn-flotante-pendientes');
        if (!btn || !offline()) return;
        var pendientes = [];
        try { pendientes = await offline().listarPendientes(); } catch(e) {}
        if (pendientes.length > 0) {
            btn.style.display = 'inline-flex';
            document.getElementById('btn-flotante-label').textContent =
                pendientes.length + ' pendiente'+(pendientes.length===1?'':'s');
        } else {
            btn.style.display = 'none';
        }
    }

    // ── Abrir/cerrar modal ────────────────────────────────────────────────────
    function abrirModal() {
        var m = document.getElementById('modal-pendientes-offline');
        if (!m) return;
        m.style.display = 'flex';
        renderModal();
    }
    function cerrarModal() {
        var m = document.getElementById('modal-pendientes-offline');
        if (m) m.style.display = 'none';
        actualizarBotónFlotante();
    }
    document.getElementById('modal-pendientes-offline')?.addEventListener('click', function(e) {
        if (e.target === this) cerrarModal();
    });
    document.getElementById('buzon-reintentar-todo')?.addEventListener('click', async function() {
        if (!navigator.onLine) { alert('Sin conexión. Conéctese primero.'); return; }
        this.disabled = true;
        this.innerHTML = '<i class="bi bi-arrow-repeat girando"></i> Reintentando…';
        try { await offline().reintentarFallidos(); } catch(e) {}
        await renderModal();
        this.disabled = false;
        this.innerHTML = '<i class="bi bi-arrow-repeat"></i> Reintentar todo';
        actualizarBotónFlotante();
    });

    // ── Cambiar vista según conexión ──────────────────────────────────────────
    async function actualizarVista() {
        var hayInternet = navigator.onLine;
        var pendientes  = [];
        try { if (offline()) pendientes = await offline().listarPendientes(); } catch(e) {}

        // Panel sin conexión: SOLO cuando no hay internet Y hay pendientes
        var panelOffline = document.getElementById('panel-offline-pendientes');
        if (!hayInternet && pendientes.length > 0) {
            document.body.classList.add('sin-conexion');
            if (panelOffline) panelOffline.style.display = '';
            renderPanelOffline();
        } else {
            document.body.classList.remove('sin-conexion');
            if (panelOffline) panelOffline.style.display = 'none';
        }

        // Botón flotante: solo con internet y pendientes
        if (hayInternet) {
            actualizarBotónFlotante();
        } else {
            var btn = document.getElementById('btn-flotante-pendientes');
            if (btn) btn.style.display = 'none';
        }
    }

    window.SismosOfflinePanel = { abrir: abrirModal, cerrar: cerrarModal, render: renderModal };

    window.addEventListener('online',  function() { actualizarVista(); });
    window.addEventListener('offline', function() { actualizarVista(); });

    // Ejecutar después de que el browser pinte el HTML inicial.
    // El panel ya tiene display:none en el HTML — esto lo muestra
    // SOLO si no hay internet Y hay pendientes reales.
    setTimeout(function() { actualizarVista(); }, 50);
    setInterval(function() { if (navigator.onLine) actualizarBotónFlotante(); }, 20000);

    // CSS para animación offline y ocultar contenido online sin conexión
    var style = document.createElement('style');
    style.textContent =
        '.sin-conexion .contenido-online { display: none !important; }'
        + '@keyframes progresoBarra { 0%{width:0%;margin-left:0} 50%{width:60%;margin-left:20%} 100%{width:0%;margin-left:100%} }';
    document.head.appendChild(style);
})();
</script>
    <div class="card" style="margin-top:0;">
        <div class="card-header" style="background:var(--azul-900,#101b42);color:#fff;border-radius:var(--radio) var(--radio) 0 0;">
            <h2 style="color:#fff;"><i class="bi bi-cloud-slash-fill"></i> Sin conexión — Inspecciones pendientes de subir</h2>
            <div id="offline-resumen" class="text-sm" style="color:#aab4d8;">Cargando…</div>
        </div>
        <div class="card-body" style="padding:0;">
            <div id="offline-lista-pendientes" style="padding:16px;"></div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
