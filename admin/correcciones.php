<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

requierePermiso('correcciones', 'ver');

$pageTitle    = 'Correcciones del Sistema';
$pageSubtitle = 'Inspecciones con datos, fotos o inspectores pendientes por completar';
$activeModule = 'correcciones';

$pdo = db();

// ---------------------------------------------------------------------
// Filtros de la pantalla
// ---------------------------------------------------------------------
$filtroTipo   = $_GET['tipo'] ?? '';      // '' | 'fotos' | 'inspector' | 'datos'
$filtroEstado = trim($_GET['estado'] ?? '');
$q            = trim($_GET['q'] ?? '');

$conds  = [];
$params = [];
aplicarScopeEstado($conds, $params, 'i');

if (!usuarioEsMaster() && $filtroEstado !== '') {
    $filtroEstado = ''; // un usuario estadal no puede espiar otro estado por la URL
}
if ($filtroEstado !== '') {
    $conds[] = 'i.estado = :filtro_estado';
    $params['filtro_estado'] = $filtroEstado;
}
if ($q !== '') {
    $conds[] = '(i.codigo LIKE :q1 OR i.nombre_edificio LIKE :q2 OR i.parroquia LIKE :q3 OR i.municipio LIKE :q4)';
    $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = "%$q%";
}
$whereSql = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

$sql = "
    SELECT
        i.id, i.codigo, i.nombre_edificio, i.estado, i.municipio, i.parroquia,
        i.fecha_inspeccion, i.creado_en,
        i.ing1_id, i.ing1_nombre, i.ing1_cedula, i.ing2_id,
        i.uso_edificacion, i.tipo_estructural, i.latitud, i.longitud,
        i.decision_final,
        (SELECT COUNT(*) FROM inspeccion_fotos f WHERE f.inspeccion_id = i.id) AS num_fotos
    FROM inspecciones i
    $whereSql
    ORDER BY i.creado_en DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$todas = $stmt->fetchAll();

/**
 * Calcula la lista de problemas de una inspección. Cada uno trae una
 * clave corta (para el filtro por tipo) y una etiqueta para mostrar.
 */
function problemasDeInspeccion(array $r): array
{
    $problemas = [];

    if ((int)$r['num_fotos'] === 0) {
        $problemas[] = ['tipo' => 'fotos', 'etiqueta' => 'Sin fotos', 'icono' => 'bi-camera-fill'];
    }
    $ing1_generico = !$r['ing1_id'] || $r['ing1_cedula'] === '0' || $r['ing1_nombre'] === 'Inspector no identificado';
    if ($ing1_generico) {
        $problemas[] = ['tipo' => 'inspector', 'etiqueta' => 'Sin inspector principal', 'icono' => 'bi-person-fill-exclamation'];
    } elseif (!$r['ing1_id']) {
        $problemas[] = ['tipo' => 'inspector', 'etiqueta' => 'Inspector no vinculado al directorio', 'icono' => 'bi-person-fill-exclamation'];
    }
    if (!$r['ing2_id']) {
        $problemas[] = ['tipo' => 'inspector', 'etiqueta' => 'Sin segundo profesional', 'icono' => 'bi-person-fill-add'];
    }
    if ($r['latitud'] === null || $r['longitud'] === null) {
        $problemas[] = ['tipo' => 'datos', 'etiqueta' => 'Sin coordenadas en el mapa', 'icono' => 'bi-geo-alt-fill'];
    }
    if (!$r['uso_edificacion']) {
        $problemas[] = ['tipo' => 'datos', 'etiqueta' => 'Sin uso de edificación', 'icono' => 'bi-building'];
    }
    if (!$r['tipo_estructural']) {
        $problemas[] = ['tipo' => 'datos', 'etiqueta' => 'Sin tipo estructural', 'icono' => 'bi-diagram-3-fill'];
    }
    if (!$r['municipio']) {
        $problemas[] = ['tipo' => 'datos', 'etiqueta' => 'Sin municipio', 'icono' => 'bi-signpost-split-fill'];
    }

    return $problemas;
}

$pendientes = [];
$conteoTipo = ['fotos' => 0, 'inspector' => 0, 'datos' => 0];
foreach ($todas as $r) {
    $problemas = problemasDeInspeccion($r);
    if (!$problemas) {
        continue;
    }
    $tiposEnFila = array_unique(array_column($problemas, 'tipo'));
    foreach ($tiposEnFila as $t) {
        $conteoTipo[$t]++;
    }
    if ($filtroTipo !== '' && !in_array($filtroTipo, $tiposEnFila, true)) {
        continue;
    }
    $r['problemas'] = $problemas;
    $pendientes[] = $r;
}

// Las que tienen más cosas pendientes primero (más urgentes de corregir).
usort($pendientes, fn($a, $b) => count($b['problemas']) <=> count($a['problemas']));

include __DIR__ . '/../includes/header.php';
?>

<div class="flex gap-12" style="flex-wrap:wrap;margin-bottom:16px;">
    <a href="<?= APP_URL_BASE ?>admin/correcciones.php" class="card-mini-link <?= $filtroTipo==='' ? 'activo' : '' ?>" style="flex:1;min-width:170px;">
        <div class="card" style="padding:14px 16px;<?= $filtroTipo==='' ? 'border-color:var(--azul-700);' : '' ?>">
            <div class="text-sm text-muted">Total con pendientes</div>
            <div style="font-size:26px;font-weight:700;"><?= count(array_filter($todas, fn($r) => problemasDeInspeccion($r))) ?></div>
        </div>
    </a>
    <a href="<?= APP_URL_BASE ?>admin/correcciones.php?tipo=fotos" class="card-mini-link" style="flex:1;min-width:170px;">
        <div class="card" style="padding:14px 16px;<?= $filtroTipo==='fotos' ? 'border-color:var(--rojo);' : '' ?>">
            <div class="text-sm text-muted"><i class="bi bi-camera-fill"></i> Sin fotos</div>
            <div style="font-size:26px;font-weight:700;color:var(--rojo);"><?= $conteoTipo['fotos'] ?></div>
        </div>
    </a>
    <a href="<?= APP_URL_BASE ?>admin/correcciones.php?tipo=inspector" class="card-mini-link" style="flex:1;min-width:170px;">
        <div class="card" style="padding:14px 16px;<?= $filtroTipo==='inspector' ? 'border-color:var(--amarillo);' : '' ?>">
            <div class="text-sm text-muted"><i class="bi bi-person-fill-exclamation"></i> Faltan inspectores</div>
            <div style="font-size:26px;font-weight:700;color:var(--amarillo);"><?= $conteoTipo['inspector'] ?></div>
        </div>
    </a>
    <a href="<?= APP_URL_BASE ?>admin/correcciones.php?tipo=datos" class="card-mini-link" style="flex:1;min-width:170px;">
        <div class="card" style="padding:14px 16px;<?= $filtroTipo==='datos' ? 'border-color:var(--azul-700);' : '' ?>">
            <div class="text-sm text-muted"><i class="bi bi-exclamation-triangle-fill"></i> Datos incompletos</div>
            <div style="font-size:26px;font-weight:700;"><?= $conteoTipo['datos'] ?></div>
        </div>
    </a>
</div>

<div class="card">
    <div class="card-header" style="flex-wrap:wrap;gap:10px;">
        <h2><i class="bi bi-clipboard2-pulse-fill"></i> Inspecciones por corregir (<?= count($pendientes) ?>)</h2>
        <form method="get" class="flex gap-8" style="flex-wrap:wrap;">
            <?php if ($filtroTipo !== ''): ?><input type="hidden" name="tipo" value="<?= e($filtroTipo) ?>"><?php endif; ?>
            <?php if (usuarioEsMaster()): ?>
            <select name="estado" class="form-control" style="width:auto;" onchange="this.form.submit()">
                <option value="">Todos los estados</option>
                <?php foreach (catalogoEstados() as $es): ?>
                <option value="<?= e($es) ?>" <?= $filtroEstado === $es ? 'selected' : '' ?>><?= e($es) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="text" name="q" class="form-control" placeholder="Buscar por código, edificio, parroquia…" value="<?= e($q) ?>" style="width:auto;min-width:220px;">
            <button class="btn btn-outline btn-sm"><i class="bi bi-search"></i> Buscar</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Edificación</th>
                    <th>Ubicación</th>
                    <th>Fecha</th>
                    <th>Pendientes</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$pendientes): ?>
                <tr><td colspan="6" class="text-sm text-muted" style="text-align:center;padding:26px;">
                    <i class="bi bi-check-circle-fill" style="color:var(--verde);font-size:20px;"></i><br>
                    ¡Todo en orden! No hay inspecciones con pendientes<?= $filtroTipo ? ' de este tipo' : '' ?>.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($pendientes as $r):
                $urgencia = count($r['problemas']) >= 3 ? 'fila-alerta-alta' : 'fila-alerta-media';
            ?>
                <tr class="fila-alerta <?= $urgencia ?>"
                    onclick="window.location='<?= APP_URL_BASE ?>formulario/create.php?id=<?= (int)$r['id'] ?>'"
                    title="Clic para abrir y corregir esta inspección">
                    <td><span class="text-mono"><?= e($r['codigo']) ?></span></td>
                    <td><strong><?= e($r['nombre_edificio']) ?></strong></td>
                    <td class="text-sm text-muted"><?= e($r['parroquia'] ?: $r['municipio']) ?> — <?= e($r['estado']) ?></td>
                    <td class="text-sm text-muted"><?= e($r['fecha_inspeccion']) ?></td>
                    <td>
                        <div class="flex gap-8" style="flex-wrap:wrap;">
                            <?php foreach ($r['problemas'] as $p): ?>
                            <span class="badge badge-highlight-<?= e($p['tipo']) ?>"><i class="bi <?= e($p['icono']) ?>"></i> <?= e($p['etiqueta']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td>
                        <a href="<?= APP_URL_BASE ?>formulario/create.php?id=<?= (int)$r['id'] ?>" class="btn btn-primary btn-sm" onclick="event.stopPropagation();">
                            <i class="bi bi-pencil-fill"></i> Corregir
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
