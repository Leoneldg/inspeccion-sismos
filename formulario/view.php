<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';

requierePermiso('formulario', 'ver');

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT i.*, cu.nombre_completo AS creado_por_nombre
                        FROM inspecciones i
                        LEFT JOIN usuarios cu ON cu.id = i.creado_por
                        WHERE i.id = :id');
$stmt->execute(['id' => $id]);
$r = $stmt->fetch();

if (!$r) {
    flash('error', 'La inspección solicitada no existe.');
    header('Location: ' . APP_URL_BASE . 'formulario/index.php');
    exit;
}

// Aislamiento por ente: un usuario no puede abrir por URL una inspección que
// no pertenece a su ente (una Gobernación sí puede ver las de su estado; el
// master, todas).
if (!usuarioEsMaster() && enteDelUsuario() !== null && function_exists('scopeEnteSql')) {
    [$fragEnte, $pEnte] = scopeEnteSql('ente_id', 'estado');
    if ($fragEnte !== '' && columnaInspeccionExiste('ente_id')) {
        $chk = db()->prepare("SELECT 1 FROM inspecciones WHERE id = :id AND ($fragEnte) LIMIT 1");
        $chk->execute(array_merge(['id' => $id], $pEnte));
        if (!$chk->fetchColumn()) {
            flash('error', 'No tiene acceso a esa inspección.');
            header('Location: ' . APP_URL_BASE . 'formulario/index.php');
            exit;
        }
    }
}

$danosEst = json_decode($r['danos_estructurales'] ?? '{}', true) ?: [];
$danosNo  = json_decode($r['danos_no_estructurales'] ?? '{}', true) ?: [];
$extra    = json_decode($r['datos_adicionales'] ?? '{}', true) ?: [];
$pisoCriticoData = json_decode($r['elementos_piso_critico'] ?? '{}', true) ?: [];
$accionesData    = json_decode($r['acciones_recomendadas'] ?? '{}', true) ?: [];
$elementosPisoCritico = catalogoElementosPisoCritico();
$nivelesDano = catalogoNivelDano();
$elementosEstruct = catalogoElementosEstructurales();
$elementosNoEstruct = catalogoElementosNoEstructurales();
$decisiones = catalogoDecisionFinal();
$fotosAgrupadas = obtenerFotosInspeccion((int)$r['id']);
$catCategoriasFoto = catalogoCategoriasFoto();

$pageTitle    = $r['nombre_edificio'];
$pageSubtitle = 'Detalle de inspección · ' . $r['codigo'];
$activeModule = 'formulario';

include __DIR__ . '/../includes/header.php';

function fila($label, $valor) {
    if ($valor === null || $valor === '') $valor = '<span class="text-muted">—</span>';
    echo '<div style="padding:8px 0;border-bottom:1px solid var(--gris-100);display:flex;justify-content:space-between;gap:16px;">
            <span class="text-sm text-muted">' . e($label) . '</span>
            <span style="font-weight:600;text-align:right;">' . (is_string($valor) ? $valor : e((string)$valor)) . '</span>
          </div>';
}
?>

<div class="flex justify-between items-center" style="margin-bottom:16px;">
    <span class="badge <?= str_contains($r['decision_final'],'Permitido')&&!str_contains($r['decision_final'],'No')?'badge-verde':(str_contains($r['decision_final'],'Precaución')?'badge-amarillo':'badge-rojo') ?>" style="font-size:13px;padding:8px 16px;">
        <?= e($r['decision_final']) ?>
    </span>
    <div class="flex gap-8">
        <?php if (puede('formulario', 'editar')): ?>
        <a href="<?= APP_URL_BASE ?>formulario/create.php?id=<?= (int)$r['id'] ?>" class="btn btn-primary btn-sm"><i class="bi bi-pencil"></i> Editar</a>
        <?php endif; ?>
        <?php if (puede('import_export', 'ver')): ?>
        <a href="<?= APP_URL_BASE ?>dashboard/export_pdf.php?id=<?= (int)$r['id'] ?>" class="btn btn-danger btn-sm" target="_blank"><i class="bi bi-file-earmark-pdf-fill"></i> Exportar PDF</a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline btn-sm"
            onclick="abrirModalQR('<?= e(urlAbsoluta('dashboard/export_pdf.php?id=' . (int)$r['id'] . '&token=' . tokenPdfPublico((int)$r['id']))) ?>', '<?= e($r['codigo']) ?>')">
            <i class="bi bi-qr-code"></i> Código QR
        </button>
        <a href="<?= APP_URL_BASE ?>formulario/index.php" class="btn btn-outline btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
</div>

<div class="split-grid cols-21">
<div>
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2><i class="bi bi-building"></i> Identificación y ubicación</h2></div>
        <div class="card-body">
            <?php
            fila('Planilla N°', $r['planilla_numero'] ?: $r['codigo']);
            fila('Tipo de evento', $r['tipo_evento']);
            fila('Fecha del evento', $r['fecha_evento']);
            fila('Nombre del edificio', $r['nombre_edificio']);
            fila('Fecha de inspección', $r['fecha_inspeccion']);
            fila('Horario', ($r['hora_inicio'] ?: '—') . ' a ' . ($r['hora_culminacion'] ?: '—'));
            fila('N° de pisos / sótanos / semisótanos', $r['num_pisos'] . ' / ' . $r['num_sotanos'] . ' / ' . $r['num_semisotanos']);
            fila('Año de construcción', $r['anio_construccion']);
            fila('Cantidad de apartamentos', $r['cantidad_apartamentos']);
            fila('N° de personas (general)', $r['numero_personas']);
            fila('Parroquia', $r['parroquia']);
            fila('Municipio / Ciudad / Estado', trim(($r['municipio']?:'—').' / '.($r['ciudad']?:'—').' / '.($r['estado']?:'—')));
            fila('Urbanización / Sector', trim(($r['urbanizacion']?:'—').' / '.($r['sector']?:'—')));
            fila('Avenida o calle', $r['avenida_calle']);
            fila('Coordenadas', $r['latitud'] && $r['longitud'] ? $r['latitud'].', '.$r['longitud'] : null);
            ?>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2><i class="bi bi-diagram-3-fill"></i> Características y evaluación de riesgo</h2></div>
        <div class="card-body">
            <?php
            fila('Uso de la edificación', $r['uso_edificacion']);
            fila('Tipo estructural', $r['tipo_estructural']);
            fila('Colapso de la estructura', $r['colapso_estructura']);
            fila('Peligro por edificios aledaños', $r['riesgo_edificios_aledanos']);
            fila('Peligro geológico o geotécnico', $r['amenaza_geologica']);
            fila('Asentamiento / Inclinación', trim(($r['asentamiento_edificio']?:'—').' / '.($r['inclinacion_edificio']?:'—')));
            fila('Requiere inspección interna', $r['requiere_inspeccion_interna']);
            fila('Riesgo Externo', $r['riesgo_externo']);
            ?>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2><i class="bi bi-building-fill-exclamation"></i> Piso crítico</h2></div>
        <div class="card-body">
            <?php
            fila('Pisos inspeccionados', $r['pisos_inspeccionados']);
            fila('Acceso a miembros estructurales', $r['acceso_miembros_estructurales']);
            fila('Piso crítico', $r['piso_critico']);
            foreach ($elementosPisoCritico as $k => $label) {
                fila('N° con daño severo/completo — ' . $label, $pisoCriticoData['severo'][$k] ?? null);
            }
            fila('Riesgo Estructural por daño Severo/Completo', $r['riesgo_estructural_severo']);
            fila('Riesgo Estructural por Daño Moderado', $r['riesgo_estructural_moderado']);
            fila('Riesgo de Componentes', $r['riesgo_componentes']);
            ?>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2><i class="bi bi-bricks"></i> Daño en elementos estructurales</h2></div>
        <div class="card-body">
            <?php foreach ($elementosEstruct as $k => $label):
                fila($label, isset($danosEst[$k]) ? ($nivelesDano[$danosEst[$k]] ?? $danosEst[$k]) : null);
            endforeach; ?>
            <?php fila('Requiere intervención', $r['requiere_intervencion']); ?>
            <?php fila('% Daño III / IV / V', $r['pct_dano_iii'].'% / '.$r['pct_dano_iv'].'% / '.$r['pct_dano_v'].'%'); ?>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2><i class="bi bi-layout-wtf"></i> Daño en elementos no estructurales</h2></div>
        <div class="card-body">
            <?php foreach ($elementosNoEstruct as $k => $label):
                fila($label, isset($danosNo[$k]) ? ($nivelesDano[$danosNo[$k]] ?? $danosNo[$k]) : null);
            endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><i class="bi bi-card-text"></i> Observaciones y recomendaciones</h2></div>
        <div class="card-body">
            <p><strong>Medidas de seguridad:</strong><br><?= nl2br(e($r['medidas_seguridad'] ?: '—')) ?></p>
            <p><strong>Observaciones:</strong><br><?= nl2br(e($r['observaciones'] ?: '—')) ?></p>
            <p><strong>Recomendaciones:</strong><br><?= nl2br(e($r['recomendaciones'] ?: '—')) ?></p>
            <?php
            $insDet = array_keys(array_filter($accionesData['inspeccion_detallada'] ?? []));
            $medPrev = $accionesData['medidas_prevencion'] ?? [];
            $medPrevTexto = array_keys(array_filter($medPrev, fn($v,$k) => $k !== 'otra_texto' && $v, ARRAY_FILTER_USE_BOTH));
            $catInsDet = catalogoInspeccionDetallada();
            $catMedPrev = catalogoMedidasPrevencion();
            ?>
            <p><strong>Inspección detallada recomendada:</strong><br>
                <?= $insDet ? e(implode(', ', array_map(fn($k) => $catInsDet[$k] ?? $k, $insDet))) : '—' ?>
            </p>
            <p><strong>Medidas de prevención:</strong><br>
                <?= $medPrevTexto ? e(implode(', ', array_map(fn($k) => $catMedPrev[$k] ?? $k, $medPrevTexto))) : '—' ?>
                <?= !empty($medPrev['otra_texto']) ? ' · '.e($medPrev['otra_texto']) : '' ?>
            </p>
        </div>
    </div>

    <?php if ($fotosAgrupadas): ?>
    <div class="card" style="margin-top:16px;">
        <div class="card-header"><h2><i class="bi bi-camera-fill"></i> Registro fotográfico</h2></div>
        <div class="card-body">
            <?php foreach ($fotosAgrupadas as $categoria => $fotos): ?>
                <div class="foto-categoria-titulo"><?= e($catCategoriasFoto[$categoria] ?? ucfirst($categoria)) ?></div>
                <div class="foto-galeria">
                    <?php foreach ($fotos as $f): ?>
                        <a href="<?= APP_URL_BASE . e($f['ruta']) ?>" target="_blank"><img src="<?= APP_URL_BASE . e($f['ruta']) ?>" loading="lazy"></a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div>
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2><i class="bi bi-person-badge-fill"></i> Profesional responsable</h2></div>
        <div class="card-body">
            <?php
            fila('Nombre', $r['ing1_nombre']);
            fila('Cédula', $r['ing1_cedula']);
            fila('Teléfono', $r['ing1_telefono']);
            fila('Profesión', $r['ing1_profesion']);
            fila('N° de inscripción', $r['ing1_inscripcion']);
            ?>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2><i class="bi bi-people-fill"></i> Personas y animales afectados</h2></div>
        <div class="card-body">
            <?php
            fila('Familias', $r['familias']);
            fila('Hombres', $r['hombres']);
            fila('Mujeres', $r['mujeres']);
            fila('Niños', $r['ninos']);
            fila('3ra edad', $r['adultos_tercera_edad']);
            fila('Gestantes', $r['gestantes']);
            fila('Movilidad reducida', $r['movilidad_reducida']);
            fila('Mascotas', $r['mascotas']);
            ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><i class="bi bi-clock-history"></i> Auditoría</h2></div>
        <div class="card-body">
            <?php
            fila('Código', $r['codigo']);
            fila('Registrado por', $r['creado_por_nombre']);
            fila('Creado', $r['creado_en']);
            fila('Última actualización', $r['actualizado_en']);
            ?>
        </div>
    </div>
</div>
</div>

<?php if (isset($_GET['nuevo'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    abrirModalQR('<?= e(urlAbsoluta('dashboard/export_pdf.php?id=' . (int)$r['id'] . '&token=' . tokenPdfPublico((int)$r['id']))) ?>', '<?= e($r['codigo']) ?>');
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
