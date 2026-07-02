<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requierePermiso('formulario', 'ver');

$pageTitle    = 'Formulario de Inspección';
$pageSubtitle = 'Listado de inspecciones registradas';
$activeModule = 'formulario';

$q         = trim($_GET['q'] ?? '');
$parroquia = trim($_GET['parroquia'] ?? '');
$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = 15;

$where  = [];
$params = [];
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
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$pdo = db();
$stmtCount = $pdo->prepare("SELECT COUNT(*) AS c FROM inspecciones $whereSql");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetch()['c'];
$totalPaginas = max(1, (int)ceil($total / $porPagina));
$offset = ($pagina - 1) * $porPagina;

$sql = "SELECT id, codigo, nombre_edificio, parroquia, fecha_inspeccion, decision_final, ing1_nombre, familias
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
        <select name="parroquia" class="form-control" style="width:200px;">
            <option value="todas">Todas las parroquias</option>
            <?php foreach ($parroquias as $p): ?>
                <option value="<?= e($p) ?>" <?= $parroquia === $p ? 'selected' : '' ?>><?= e($p) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline"><i class="bi bi-search"></i> Buscar</button>
    </form>
    <?php if (puede('formulario', 'crear')): ?>
    <a href="<?= APP_URL_BASE ?>formulario/create.php" class="btn btn-accent">
        <i class="bi bi-plus-lg"></i> Nueva inspección
    </a>
    <?php endif; ?>
</div>

<div class="card">
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
                    <td><strong><?= e($r['nombre_edificio']) ?></strong></td>
                    <td><?= e($r['parroquia']) ?></td>
                    <td><?= e($r['fecha_inspeccion']) ?></td>
                    <td><?= e($r['ing1_nombre']) ?></td>
                    <td><?= (int)$r['familias'] ?></td>
                    <td><span class="badge <?= badgeClase($r['decision_final']) ?>"><?= e($decisiones[$r['decision_final']]['corto'] ?? $r['decision_final']) ?></span></td>
                    <td>
                        <div class="flex gap-8">
                            <a href="<?= APP_URL_BASE ?>formulario/view.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm" title="Ver"><i class="bi bi-eye"></i></a>
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
<div class="flex gap-8" style="margin-top:16px;justify-content:center;">
    <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
        <a class="btn btn-sm <?= $p === $pagina ? 'btn-primary' : 'btn-outline' ?>"
           href="?pagina=<?= $p ?>&q=<?= urlencode($q) ?>&parroquia=<?= urlencode($parroquia) ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
