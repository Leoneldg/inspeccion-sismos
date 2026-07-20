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

// Borrar datos es delicado: solo master y administradores.
$rolActual = mb_strtolower($_SESSION['rol_nombre'] ?? '', 'UTF-8');
$puedeLimpiar = usuarioEsMaster()
             || str_contains($rolActual, 'administrador')
             || str_contains($rolActual, 'superadmin');

if (!$puedeLimpiar) {
    http_response_code(403);
    exit('Solo un administrador puede usar esta herramienta.');
}

$pdo = db();
$mensaje = null;
$tipoMsg = 'success';

/**
 * Borra el LEVANTAMIENTO TÉCNICO de una inspección, conservando la inspección.
 * Elimina pisos, apartamentos, ambientes, avances, áreas comunes y las fotos
 * del levantamiento. La inspección queda limpia y vuelve a "sin asignar",
 * lista para rehacer el levantamiento desde cero.
 */
function borrarLevantamiento(PDO $pdo, int $inspeccionId): array
{
    $res = ['fotos' => 0, 'ambientes' => 0, 'apartamentos' => 0, 'pisos' => 0, 'edificio' => 0];

    $st = $pdo->prepare('SELECT id FROM rec_edificio WHERE inspeccion_id = :i');
    $st->execute(['i' => $inspeccionId]);
    $edificioId = (int)($st->fetchColumn() ?: 0);
    if ($edificioId <= 0) return $res;   // no tenía levantamiento

    // Recolectar los ids de toda la jerarquía.
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
    $elemIds = [];
    if ($pisoIds) {
        $in = implode(',', array_map('intval', $pisoIds));
        try {
            $elemIds = $pdo->query("SELECT id FROM rec_elemento_piso WHERE piso_id IN ($in)")
                           ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {}
    }

    // 1) Fotos: archivo en disco + registro.
    $niveles = [
        'edificio'      => [$edificioId],
        'piso'          => $pisoIds,
        'apartamento'   => $aptoIds,
        'ambiente'      => $ambIds,
        'elemento_piso' => $elemIds,
    ];
    foreach ($niveles as $nivel => $ids) {
        if (!$ids) continue;
        $in = implode(',', array_map('intval', $ids));
        try {
            $fotos = $pdo->query("SELECT ruta FROM rec_foto WHERE nivel='$nivel' AND ref_id IN ($in)")->fetchAll();
            foreach ($fotos as $f) {
                $abs = dirname(__DIR__) . '/' . ltrim($f['ruta'], '/');
                if (is_file($abs)) @unlink($abs);
                $res['fotos']++;
            }
            $pdo->exec("DELETE FROM rec_foto WHERE nivel='$nivel' AND ref_id IN ($in)");
        } catch (Throwable $e) {}
    }

    // 2) De abajo hacia arriba: ambientes -> apartamentos -> pisos -> edificio.
    if ($ambIds) {
        $in = implode(',', array_map('intval', $ambIds));
        try { $pdo->exec("DELETE FROM rec_avance_ambiente WHERE ambiente_id IN ($in)"); } catch (Throwable $e) {}
        try { $pdo->exec("DELETE FROM rec_reparacion WHERE nivel='ambiente' AND ref_id IN ($in)"); } catch (Throwable $e) {}
        $pdo->exec("DELETE FROM rec_ambiente WHERE id IN ($in)");
        $res['ambientes'] = count($ambIds);
    }
    if ($aptoIds) {
        $in = implode(',', array_map('intval', $aptoIds));
        try { $pdo->exec("DELETE FROM rec_avance_apto WHERE apartamento_id IN ($in)"); } catch (Throwable $e) {}
        $pdo->exec("DELETE FROM rec_apartamento WHERE id IN ($in)");
        $res['apartamentos'] = count($aptoIds);
    }
    if ($elemIds) {
        $in = implode(',', array_map('intval', $elemIds));
        try { $pdo->exec("DELETE FROM rec_reparacion WHERE nivel='elemento_piso' AND ref_id IN ($in)"); } catch (Throwable $e) {}
        try { $pdo->exec("DELETE FROM rec_elemento_piso WHERE id IN ($in)"); } catch (Throwable $e) {}
    }
    if ($pisoIds) {
        $in = implode(',', array_map('intval', $pisoIds));
        $pdo->exec("DELETE FROM rec_piso WHERE id IN ($in)");
        $res['pisos'] = count($pisoIds);
    }
    foreach (['rec_area_comun', 'rec_plan_edificio'] as $t) {
        try { $pdo->exec("DELETE FROM `$t` WHERE edificio_id = $edificioId"); } catch (Throwable $e) {}
    }
    $pdo->exec("DELETE FROM rec_edificio WHERE id = $edificioId");
    $res['edificio'] = 1;

    // 3) Limpiar el seguimiento de obra.
    try {
        $pdo->prepare('DELETE FROM seguimiento_obras WHERE inspeccion_id = :i')->execute(['i' => $inspeccionId]);
    } catch (Throwable $e) {}

    // Quitar asignaciones a frentes y brigadas.
    foreach (['asignacion_frente_obra', 'obra_brigada', 'asignacion_frente'] as $t) {
        try { $pdo->prepare("DELETE FROM `$t` WHERE inspeccion_id = :i")->execute(['i' => $inspeccionId]); }
        catch (Throwable $e) { /* la tabla puede no existir */ }
    }

    // 4) La inspección se conserva: solo se borró su levantamiento.
    //    Para eliminarla por completo hay que marcar "Eliminar del todo",
    //    que llama a borrarEdificacionCompleta().
    recAuditar('levantamiento_eliminado', $inspeccionId, null,
        'Levantamiento borrado: ' . $res['pisos'] . ' piso(s), ' . $res['apartamentos']
        . ' apto(s), ' . $res['ambientes'] . ' ambiente(s), ' . $res['fotos'] . ' foto(s)');

    return $res;
}

/**
 * Elimina POR COMPLETO una edificación agregada en campo: su
 * levantamiento, la inspección y todo rastro en la bitácora.
 *
 * Solo se permite con las que se registraron en campo, nunca con las
 * del listado original importado.
 */
function borrarEdificacionCompleta(PDO $pdo, int $inspeccionId): array
{
    $res = ['inspeccion' => 0, 'auditoria' => 0, 'era_agregada' => false];

    // Comprobación de seguridad: solo las agregadas en campo.
    $st = $pdo->prepare("SELECT COUNT(*) FROM rec_auditoria
                          WHERE inspeccion_id = :i AND accion = 'edificacion_agregada'");
    $st->execute(['i' => $inspeccionId]);
    $res['era_agregada'] = ((int)$st->fetchColumn()) > 0;

    if (!$res['era_agregada']) {
        // No se toca: es una inspección del listado original.
        return $res;
    }

    // 1) Borrar el levantamiento y todo lo que cuelga de él.
    borrarLevantamiento($pdo, $inspeccionId);

    // 2) Quitar asignaciones a frentes y brigadas.
    foreach (['asignacion_frente_obra', 'obra_brigada', 'asignacion_frente',
              'obra_cuadrilla', 'asignacion_integrante'] as $t) {
        try {
            $pdo->prepare("DELETE FROM `$t` WHERE inspeccion_id = :i")->execute(['i' => $inspeccionId]);
        } catch (Throwable $e) { /* la tabla puede no existir */ }
    }

    // 3) Borrar la bitácora de esta edificación (incluida la marca
    //    'edificacion_agregada', que es la que alimenta el contador).
    try {
        $stA = $pdo->prepare('DELETE FROM rec_auditoria WHERE inspeccion_id = :i');
        $stA->execute(['i' => $inspeccionId]);
        $res['auditoria'] = $stA->rowCount();
    } catch (Throwable $e) {}

    // 4) Borrar la inspección.
    try {
        $stI = $pdo->prepare('DELETE FROM inspecciones WHERE id = :i');
        $stI->execute(['i' => $inspeccionId]);
        $res['inspeccion'] = $stI->rowCount();
    } catch (Throwable $e) {}

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
        // Modo de borrado: solo el levantamiento, o la edificación completa.
        $completo = !empty($_POST['borrar_completo']);

        $tot = ['fotos'=>0,'ambientes'=>0,'apartamentos'=>0,'pisos'=>0,'edificio'=>0];
        $nombres = [];
        $eliminadas = 0;
        $noAgregadas = [];

        foreach ($ids as $iid) {
            $st = $pdo->prepare('SELECT codigo, nombre_edificio FROM inspecciones WHERE id = :i');
            $st->execute(['i' => $iid]);
            if (!($row = $st->fetch())) continue;
            $etq = $row['codigo'] . ' — ' . $row['nombre_edificio'];

            if ($completo) {
                $r = borrarEdificacionCompleta($pdo, $iid);
                if ($r['era_agregada']) {
                    $eliminadas += $r['inspeccion'];
                    $nombres[] = $etq;
                } else {
                    // No se toca: es del listado original importado.
                    $noAgregadas[] = $etq;
                }
            } else {
                $nombres[] = $etq;
                $r = borrarLevantamiento($pdo, $iid);
                foreach ($tot as $k => $v) {
                    if (isset($r[$k])) $tot[$k] += $r[$k];
                }
            }
        }

        if ($completo) {
            $mensaje = $eliminadas . ' edificación(es) eliminada(s) por completo del sistema.';
            if ($noAgregadas) {
                $mensaje .= ' No se tocaron ' . count($noAgregadas)
                    . ' porque pertenecen al listado original: '
                    . implode(', ', array_slice($noAgregadas, 0, 3))
                    . (count($noAgregadas) > 3 ? '…' : '')
                    . '. Para esas use el borrado del levantamiento.';
            }
            registrarLog($_SESSION['user_id'] ?? null, 'edificaciones_eliminadas', implode(' | ', $nombres));
        } else {
            $mensaje = $tot['edificio'] . ' levantamiento(s) eliminado(s). Se borraron '
                     . $tot['pisos'] . ' piso(s), ' . $tot['apartamentos'] . ' apartamento(s), '
                     . $tot['ambientes'] . ' ambiente(s) y ' . $tot['fotos'] . ' foto(s). '
                     . 'Las ' . count($nombres) . ' inspección(es) se conservaron y volvieron a "sin asignar".';
            registrarLog($_SESSION['user_id'] ?? null, 'levantamientos_eliminados', implode(' | ', $nombres));
        }
    }
}

// --- Listar SOLO las que tienen levantamiento técnico ---
//     Las cerradas (completado=1) van primero: son las que suman a RECONSTRUCCIÓN.
$soloCerrados = ($_GET['f'] ?? 'cerrados') === 'cerrados';
// Filtro por estado del levantamiento.
$estadoF = trim($_GET['estado'] ?? '');
$condCompletado = '';
if ($estadoF === 'proceso')      $condCompletado = 'AND re.completado = 0';
elseif ($estadoF === 'cerrado')  $condCompletado = 'AND re.completado = 1';
elseif ($soloCerrados)           $condCompletado = 'AND re.completado = 1';
$limite = max(10, min(200, (int)($_GET['n'] ?? 40)));

$st = $pdo->prepare("
    SELECT i.id, i.codigo, i.nombre_edificio, i.parroquia, i.decision_final, i.creado_en,
           re.id AS edificio_id, re.completado, re.completado_en,
           re.num_pisos, re.aptos_por_piso,
           (SELECT COUNT(*) FROM rec_piso p WHERE p.edificio_id = re.id) AS n_pisos,
           (SELECT COUNT(*) FROM rec_apartamento a
              JOIN rec_piso p2 ON p2.id = a.piso_id WHERE p2.edificio_id = re.id) AS n_aptos,
           (SELECT COUNT(*) FROM rec_ambiente m
              JOIN rec_apartamento a2 ON a2.id = m.apartamento_id
              JOIN rec_piso p3 ON p3.id = a2.piso_id WHERE p3.edificio_id = re.id) AS n_amb,
           u.nombre_completo AS cerrado_por
      FROM inspecciones i
      JOIN rec_edificio re ON re.inspeccion_id = i.id
      LEFT JOIN usuarios u ON u.id = re.completado_por
     WHERE 1=1 $condCompletado
     ORDER BY re.completado DESC, re.id DESC
     LIMIT $limite
");
$st->execute();
$filas = $st->fetchAll();

$pageTitle    = 'Limpiar levantamientos de prueba';
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
        <strong style="color:#A61C1C;"><i class="bi bi-exclamation-triangle-fill"></i> Se borra el levantamiento, no la inspección.</strong>
        <div style="font-size:12px;color:#55617f;margin-top:4px;">
            Se eliminan los pisos, apartamentos, ambientes, avances y fotos del levantamiento técnico.
            <strong>La inspección se conserva</strong> y vuelve a contarse como <em>sin asignar</em>,
            lista para rehacer el levantamiento desde cero. Esta acción no se puede deshacer.
        </div>
    </div>

    <form method="post" id="form-limpiar">
        <input type="hidden" name="accion" value="borrar">

        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
            <?php
            // Cuántos hay de cada estado, para mostrarlo en los botones.
            $nProceso = 0; $nCerrado = 0;
            try {
                $stC = $pdo->query("SELECT re.completado, COUNT(*) AS n
                                      FROM rec_edificio re GROUP BY re.completado");
                foreach ($stC->fetchAll() as $c) {
                    if ((int)$c['completado'] === 1) $nCerrado = (int)$c['n'];
                    else                             $nProceso = (int)$c['n'];
                }
            } catch (Throwable $e) {}

            $titulos = [
                'proceso' => 'Levantamientos EN PROCESO',
                'cerrado' => 'Levantamientos CERRADOS',
            ];
            ?>
            <div style="font-weight:700;color:#22366F;">
                <i class="bi bi-list-check"></i>
                <?= $titulos[$estadoF] ?? ($soloCerrados ? 'Levantamientos CERRADOS'
                                                         : 'Todos los levantamientos') ?>
                (<?= count($filas) ?>)
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <a href="?estado=proceso&n=<?= $limite ?>" class="btn btn-outline btn-sm"
                   style="<?= $estadoF === 'proceso' ? 'background:#fffbf0;font-weight:700;border-color:#C9A227;' : '' ?>">
                    En proceso (<?= $nProceso ?>)
                </a>
                <a href="?estado=cerrado&n=<?= $limite ?>" class="btn btn-outline btn-sm"
                   style="<?= $estadoF === 'cerrado' ? 'background:#eff8f1;font-weight:700;border-color:#2E7D32;' : '' ?>">
                    Cerrados (<?= $nCerrado ?>)
                </a>
                <a href="?f=todos&n=<?= $limite ?>" class="btn btn-outline btn-sm"
                   style="<?= (!$estadoF && !$soloCerrados) ? 'background:#eef2fb;font-weight:700;' : '' ?>">Todos</a>
                <span class="text-sm text-muted" style="margin-left:6px;">Mostrar:</span>
                <?php foreach ([20, 40, 100, 200] as $n): ?>
                <a href="?<?= $estadoF ? 'estado=' . $estadoF : ('f=' . ($soloCerrados ? 'cerrados' : 'todos')) ?>&n=<?= $n ?>"
                   class="btn btn-outline btn-sm" style="<?= $limite === $n ? 'background:#eef2fb;' : '' ?>"><?= $n ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!$filas): ?>
            <p class="text-muted" style="margin:14px 0;">
                No hay levantamientos <?= $soloCerrados ? 'cerrados' : '' ?> registrados.
            </p>
        <?php else: ?>
        <table class="lp-tabla">
            <thead><tr>
                <th style="width:34px;"><input type="checkbox" onclick="marcarTodo(this)"></th>
                <th style="width:44px;">ID</th>
                <th>Edificación</th>
                <th style="width:118px;">Código</th>
                <th style="width:100px;">Parroquia</th>
                <th style="width:150px;">Contenido</th>
                <th style="width:140px;">Cerrado</th>
            </tr></thead>
            <tbody>
            <?php foreach ($filas as $f): ?>
            <tr>
                <td><input type="checkbox" name="ids[]" value="<?= (int)$f['id'] ?>"
                           onchange="this.closest('tr').classList.toggle('marcada', this.checked); contar();"></td>
                <td style="color:#97a0b8;"><?= (int)$f['id'] ?></td>
                <td>
                    <div style="font-weight:600;color:#2a3140;"><?= e($f['nombre_edificio'] ?: 'Sin nombre') ?></div>
                    <div style="font-size:10px;color:#97a0b8;">
                        Registrada <?= !empty($f['creado_en']) ? date('d/m/Y', strtotime($f['creado_en'])) : '—' ?>
                    </div>
                </td>
                <td style="color:#767c94;font-size:11px;"><?= e($f['codigo']) ?></td>
                <td style="font-size:11px;"><?= e($f['parroquia'] ?: '—') ?></td>
                <td>
                    <span class="lp-chip"><?= (int)$f['n_pisos'] ?> piso(s)</span>
                    <span class="lp-chip"><?= (int)$f['n_aptos'] ?> apto(s)</span>
                    <?php if ((int)$f['n_amb'] > 0): ?>
                    <span class="lp-chip"><?= (int)$f['n_amb'] ?> amb.</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:11px;">
                    <?php if ((int)$f['completado'] === 1): ?>
                        <span class="lp-chip" style="background:#2E7D3218;color:#2E7D32;">Cerrado</span>
                        <?php if (!empty($f['completado_en'])): ?>
                        <div style="color:#97a0b8;font-size:10px;margin-top:2px;">
                            <?= date('d/m/Y H:i', strtotime($f['completado_en'])) ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($f['cerrado_por'])): ?>
                        <div style="color:#97a0b8;font-size:10px;"><?= e($f['cerrado_por']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#C9A227;">En proceso</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div style="margin-top:16px;padding-top:14px;border-top:1px solid #eef0f5;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <label class="text-sm" style="font-weight:600;">Para confirmar, escriba <code>BORRAR</code>:</label>
                <input type="text" name="confirmar" class="form-control" style="max-width:200px;"
                       placeholder="BORRAR" autocomplete="off">
            </div>
            <label style="display:flex;align-items:flex-start;gap:9px;background:#fff6f6;
                          border:1px solid #A61C1C33;border-radius:9px;padding:11px 13px;
                          margin:10px 0;cursor:pointer;max-width:560px;">
                <input type="checkbox" name="borrar_completo" id="lp-completo" style="margin-top:2px;">
                <span>
                    <span style="font-weight:700;color:#A61C1C;font-size:14px;">
                        Eliminar la edificación por completo
                    </span>
                    <span style="display:block;font-size:12.5px;color:#5b6478;margin-top:2px;">
                        Borra la inspección y todo su rastro, incluido el contador de
                        "agregadas en campo". Solo funciona con las registradas en campo:
                        las del listado original nunca se eliminan.
                    </span>
                </span>
            </label>

            <button type="submit" class="btn" style="background:#A61C1C;color:#fff;border:0;"
                    onclick="return confirmarBorrado()">
                <i class="bi bi-trash3"></i> <span id="lp-btn-texto">Borrar levantamiento</span>
                (<span id="lp-contador">0</span>)
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
// Cambiar el texto del botón según el modo elegido.
document.addEventListener('DOMContentLoaded', function () {
    const chk = document.getElementById('lp-completo');
    const txt = document.getElementById('lp-btn-texto');
    if (chk && txt) {
        chk.addEventListener('change', function () {
            txt.textContent = this.checked
                ? 'ELIMINAR edificación completa' : 'Borrar levantamiento';
        });
    }
});

function confirmarBorrado() {
    const n = document.querySelectorAll('input[name="ids[]"]:checked').length;
    if (n === 0) { alert('Seleccione al menos un levantamiento.'); return false; }

    const completo = document.getElementById('lp-completo');
    if (completo && completo.checked) {
        return confirm(
            'ATENCIÓN: se eliminarán POR COMPLETO ' + n + ' edificación(es).\n\n'
            + 'Se borra la inspección, su levantamiento, sus fotos y todo su rastro '
            + 'en el sistema. Esta acción NO se puede deshacer.\n\n'
            + 'Las del listado original no se tocan: solo se eliminan las que se '
            + 'registraron en campo.\n\n'
            + '¿Continuar?');
    }

    return confirm('Se borrará el levantamiento de ' + n + ' edificación(es): pisos, apartamentos, ambientes y fotos.\n\nLa inspección se conserva y volverá a "sin asignar".\n\n¿Continuar?');
}
contar();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
