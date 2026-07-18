<?php
/**
 * LIMPIAR PRUEBAS — herramienta para revisar y eliminar inspecciones de prueba.
 *
 * Muestra las inspecciones más recientes con todo lo que arrastran
 * (pisos, apartamentos, fotos) para decidir cuáles borrar.
 * El borrado exige confirmación y elimina en cascada de forma ordenada.
 *
 * Solo para usuarios master.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requireLogin();
if (!usuarioEsMaster()) {
    http_response_code(403);
    exit('Solo un usuario master puede usar esta herramienta.');
}

$pdo = db();
$mensaje = null;
$tipoMsg = 'success';

/**
 * Borra una inspección y TODO lo que cuelga de ella, en orden seguro.
 * Devuelve un resumen de lo eliminado.
 */
function borrarInspeccionCompleta(PDO $pdo, int $inspeccionId): array
{
    $res = ['fotos' => 0, 'ambientes' => 0, 'apartamentos' => 0, 'pisos' => 0, 'edificio' => 0];

    // Localizar el edificio de reconstrucción, si existe.
    $st = $pdo->prepare('SELECT id FROM rec_edificio WHERE inspeccion_id = :i');
    $st->execute(['i' => $inspeccionId]);
    $edificioId = (int)($st->fetchColumn() ?: 0);

    if ($edificioId > 0) {
        // IDs de pisos, apartamentos y ambientes.
        $pisos = $pdo->prepare('SELECT id FROM rec_piso WHERE edificio_id = :e');
        $pisos->execute(['e' => $edificioId]);
        $pisoIds = $pisos->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $aptoIds = [];
        if ($pisoIds) {
            $in = implode(',', array_map('intval', $pisoIds));
            $aptoIds = $pdo->query("SELECT id FROM rec_apartamento WHERE piso_id IN ($in)")
                           ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        $ambIds = [];
        if ($aptoIds) {
            $in = implode(',', array_map('intval', $aptoIds));
            $ambIds = $pdo->query("SELECT id FROM rec_ambiente WHERE apartamento_id IN ($in)")
                          ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        // Borrar fotos (archivos en disco + registros).
        $niveles = [
            'edificio'    => [$edificioId],
            'piso'        => $pisoIds,
            'apartamento' => $aptoIds,
            'ambiente'    => $ambIds,
        ];
        foreach ($niveles as $nivel => $ids) {
            if (!$ids) continue;
            $in = implode(',', array_map('intval', $ids));
            $fotos = $pdo->query("SELECT id, ruta FROM rec_foto WHERE nivel='$nivel' AND ref_id IN ($in)")->fetchAll();
            foreach ($fotos as $f) {
                $abs = dirname(__DIR__) . '/' . ltrim($f['ruta'], '/');
                if (is_file($abs)) @unlink($abs);
                $res['fotos']++;
            }
            $pdo->exec("DELETE FROM rec_foto WHERE nivel='$nivel' AND ref_id IN ($in)");
        }

        // Avances y datos dependientes (de abajo hacia arriba).
        if ($ambIds) {
            $in = implode(',', array_map('intval', $ambIds));
            foreach (['rec_avance_ambiente' => 'ambiente_id', 'rec_reparacion' => 'ref_id'] as $t => $col) {
                try { $pdo->exec("DELETE FROM `$t` WHERE `$col` IN ($in)"); } catch (Throwable $e) {}
            }
            $pdo->exec("DELETE FROM rec_ambiente WHERE id IN ($in)");
            $res['ambientes'] = count($ambIds);
        }
        if ($aptoIds) {
            $in = implode(',', array_map('intval', $aptoIds));
            try { $pdo->exec("DELETE FROM rec_avance_apto WHERE apartamento_id IN ($in)"); } catch (Throwable $e) {}
            $pdo->exec("DELETE FROM rec_apartamento WHERE id IN ($in)");
            $res['apartamentos'] = count($aptoIds);
        }
        if ($pisoIds) {
            $in = implode(',', array_map('intval', $pisoIds));
            try { $pdo->exec("DELETE FROM rec_elemento_piso WHERE piso_id IN ($in)"); } catch (Throwable $e) {}
            $pdo->exec("DELETE FROM rec_piso WHERE id IN ($in)");
            $res['pisos'] = count($pisoIds);
        }
        foreach (['rec_area_comun' => 'edificio_id', 'rec_plan_edificio' => 'edificio_id'] as $t => $col) {
            try { $pdo->exec("DELETE FROM `$t` WHERE `$col` = $edificioId"); } catch (Throwable $e) {}
        }
        $pdo->exec("DELETE FROM rec_edificio WHERE id = $edificioId");
        $res['edificio'] = 1;
    }

    // Datos ligados a la inspección.
    foreach (['seguimiento_obras', 'rec_auditoria', 'inspeccion_fotos'] as $t) {
        try { $pdo->prepare("DELETE FROM `$t` WHERE inspeccion_id = :i")->execute(['i' => $inspeccionId]); } catch (Throwable $e) {}
    }
    $pdo->prepare('DELETE FROM inspecciones WHERE id = :i')->execute(['i' => $inspeccionId]);

    return $res;
}

// --- Procesar borrado ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'borrar') {
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $ids = array_filter($ids, fn($x) => $x > 0);
    $confirma = trim($_POST['confirmar'] ?? '');

    if (!$ids) {
        $mensaje = 'No seleccionó ninguna inspección.';
        $tipoMsg = 'error';
    } elseif ($confirma !== 'BORRAR') {
        $mensaje = 'Para confirmar debe escribir exactamente la palabra BORRAR.';
        $tipoMsg = 'error';
    } else {
        $tot = ['fotos'=>0,'ambientes'=>0,'apartamentos'=>0,'pisos'=>0,'edificio'=>0];
        $nombres = [];
        foreach ($ids as $iid) {
            $st = $pdo->prepare('SELECT codigo, nombre_edificio FROM inspecciones WHERE id = :i');
            $st->execute(['i' => $iid]);
            if ($row = $st->fetch()) {
                $nombres[] = $row['codigo'] . ' — ' . $row['nombre_edificio'];
                $r = borrarInspeccionCompleta($pdo, $iid);
                foreach ($r as $k => $v) $tot[$k] += $v;
            }
        }
        $mensaje = count($nombres) . ' inspección(es) eliminada(s): '
                 . $tot['edificio'] . ' levantamiento(s), ' . $tot['pisos'] . ' piso(s), '
                 . $tot['apartamentos'] . ' apartamento(s), ' . $tot['ambientes'] . ' ambiente(s) y '
                 . $tot['fotos'] . ' foto(s).';
        registrarLog($_SESSION['user_id'] ?? null, 'inspecciones_prueba_eliminadas', implode(' | ', $nombres));
    }
}

// --- Listar candidatas: las más recientes ---
$limite = max(10, min(100, (int)($_GET['n'] ?? 40)));
$st = $pdo->prepare("
    SELECT i.id, i.codigo, i.nombre_edificio, i.parroquia, i.fecha_inspeccion, i.creado_en,
           i.decision_final,
           re.id AS edificio_id, re.completado,
           (SELECT COUNT(*) FROM rec_piso p WHERE p.edificio_id = re.id) AS n_pisos,
           (SELECT COUNT(*) FROM rec_apartamento a
              JOIN rec_piso p2 ON p2.id = a.piso_id WHERE p2.edificio_id = re.id) AS n_aptos,
           u.nombre_completo AS creador
      FROM inspecciones i
      LEFT JOIN rec_edificio re ON re.inspeccion_id = i.id
      LEFT JOIN usuarios u ON u.id = i.creado_por
     ORDER BY i.id DESC
     LIMIT $limite
");
$st->execute();
$filas = $st->fetchAll();

$pageTitle    = 'Limpiar inspecciones de prueba';
$pageSubtitle = 'Herramienta de mantenimiento';
$activeModule = 'seguimiento';
include __DIR__ . '/../includes/header.php';
?>
<style>
.lp-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07); padding:18px 20px; margin-bottom:16px; }
.lp-tabla { width:100%; border-collapse:collapse; }
.lp-tabla th { background:#f4f7fd; font-size:10px; text-transform:uppercase; letter-spacing:.3px;
               color:#55617f; padding:8px 6px; text-align:left; border-bottom:2px solid #e5e8f0; }
.lp-tabla td { font-size:12px; padding:8px 6px; border-bottom:1px solid #f0f2f7; }
.lp-tabla tr:hover td { background:#fafbfe; }
.lp-tabla tr.marcada td { background:#fff6f6; }
.lp-aviso { background:#A61C1C0d; border:1px solid #A61C1C33; border-radius:9px; padding:12px 14px; margin-bottom:14px; }
.lp-chip { font-size:10px; padding:1px 7px; border-radius:10px; background:#eef2fb; color:#2d4488; }
</style>

<?php if ($mensaje): ?>
<div class="alert alert-<?= $tipoMsg === 'error' ? 'error' : 'success' ?>" style="margin-bottom:14px;">
    <i class="bi bi-<?= $tipoMsg === 'error' ? 'exclamation-triangle-fill' : 'check-circle-fill' ?>"></i>
    <div><?= e($mensaje) ?></div>
</div>
<?php endif; ?>

<div class="lp-card">
    <div class="lp-aviso">
        <strong style="color:#A61C1C;"><i class="bi bi-exclamation-triangle-fill"></i> Esta acción no se puede deshacer.</strong>
        <div style="font-size:12px;color:#55617f;margin-top:4px;">
            Al eliminar una inspección se borran también su levantamiento técnico, pisos,
            apartamentos, ambientes, avances y las fotos guardadas en el servidor.
            Revise bien la lista antes de confirmar.
        </div>
    </div>

    <form method="post" id="form-limpiar">
        <input type="hidden" name="accion" value="borrar">

        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
            <div style="font-weight:700;color:#22366F;">
                <i class="bi bi-list-check"></i> Últimas <?= count($filas) ?> inspecciones registradas
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <span class="text-sm text-muted">Mostrar:</span>
                <?php foreach ([20, 40, 60, 100] as $n): ?>
                <a href="?n=<?= $n ?>" class="btn btn-outline btn-sm" style="<?= $limite === $n ? 'background:#eef2fb;' : '' ?>"><?= $n ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <table class="lp-tabla">
            <thead><tr>
                <th style="width:34px;"><input type="checkbox" onclick="marcarTodo(this)"></th>
                <th style="width:44px;">ID</th>
                <th>Edificación</th>
                <th style="width:120px;">Código</th>
                <th style="width:110px;">Parroquia</th>
                <th style="width:130px;">Levantamiento</th>
                <th style="width:130px;">Registrada</th>
            </tr></thead>
            <tbody>
            <?php foreach ($filas as $f): ?>
            <tr>
                <td><input type="checkbox" name="ids[]" value="<?= (int)$f['id'] ?>"
                           onchange="this.closest('tr').classList.toggle('marcada', this.checked); contar();"></td>
                <td style="color:#97a0b8;"><?= (int)$f['id'] ?></td>
                <td>
                    <div style="font-weight:600;color:#2a3140;"><?= e($f['nombre_edificio'] ?: 'Sin nombre') ?></div>
                    <?php if (!empty($f['creador'])): ?>
                    <div style="font-size:10px;color:#97a0b8;">por <?= e($f['creador']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="color:#767c94;font-size:11px;"><?= e($f['codigo']) ?></td>
                <td style="font-size:11px;"><?= e($f['parroquia'] ?: '—') ?></td>
                <td>
                    <?php if (!empty($f['edificio_id'])): ?>
                        <span class="lp-chip"><?= (int)$f['n_pisos'] ?> piso(s) · <?= (int)$f['n_aptos'] ?> apto(s)</span>
                        <?php if ((int)$f['completado'] === 1): ?>
                        <span class="lp-chip" style="background:#2E7D3218;color:#2E7D32;">Cerrado</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#c4c9d6;font-size:11px;">Sin levantamiento</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:11px;color:#767c94;">
                    <?= !empty($f['creado_en']) ? date('d/m/Y H:i', strtotime($f['creado_en'])) : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:16px;padding-top:14px;border-top:1px solid #eef0f5;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <label class="text-sm" style="font-weight:600;">Para confirmar, escriba <code>BORRAR</code>:</label>
                <input type="text" name="confirmar" class="form-control" style="max-width:200px;"
                       placeholder="BORRAR" autocomplete="off">
            </div>
            <button type="submit" class="btn" style="background:#A61C1C;color:#fff;border:0;"
                    onclick="return confirmarBorrado()">
                <i class="bi bi-trash3"></i> Eliminar seleccionadas (<span id="lp-contador">0</span>)
            </button>
            <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="btn btn-outline">Cancelar</a>
        </div>
    </form>
</div>

<script>
function marcarTodo(chk) {
    document.querySelectorAll('input[name="ids[]"]').forEach(c => {
        c.checked = chk.checked;
        c.closest('tr').classList.toggle('marcada', chk.checked);
    });
    contar();
}
function contar() {
    const n = document.querySelectorAll('input[name="ids[]"]:checked').length;
    document.getElementById('lp-contador').textContent = n;
}
function confirmarBorrado() {
    const n = document.querySelectorAll('input[name="ids[]"]:checked').length;
    if (n === 0) { alert('Seleccione al menos una inspección.'); return false; }
    return confirm('Se eliminarán ' + n + ' inspección(es) con todos sus datos y fotos.\n\nEsta acción no se puede deshacer. ¿Continuar?');
}
contar();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
