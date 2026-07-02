<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

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

$danosEst = json_decode($r['danos_estructurales'] ?? '{}', true) ?: [];
$danosNo  = json_decode($r['danos_no_estructurales'] ?? '{}', true) ?: [];
$extra    = json_decode($r['datos_adicionales'] ?? '{}', true) ?: [];
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
        <a href="<?= APP_URL_BASE ?>formulario/index.php" class="btn btn-outline btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;">
<div>
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><h2><i class="bi bi-building"></i> Identificación y ubicación</h2></div>
        <div class="card-body">
            <?php
            fila('Nombre del edificio', $r['nombre_edificio']);
            fila('Fecha de inspección', $r['fecha_inspeccion']);
            fila('Horario', ($r['hora_inicio'] ?: '—') . ' a ' . ($r['hora_culminacion'] ?: '—'));
            fila('N° de pisos / sótanos / semisótanos', $r['num_pisos'] . ' / ' . $r['num_sotanos'] . ' / ' . $r['num_semisotanos']);
            fila('Cantidad de apartamentos', $r['cantidad_apartamentos']);
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
            fila('Riesgo de edificios aledaños', $r['riesgo_edificios_aledanos']);
            fila('Amenaza geológica', $r['amenaza_geologica']);
            fila('Asentamiento / Inclinación', trim(($r['asentamiento_edificio']?:'—').' / '.($r['inclinacion_edificio']?:'—')));
            fila('Requiere inspección interna', $r['requiere_inspeccion_interna']);
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
