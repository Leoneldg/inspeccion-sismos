<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL_BASE . 'formulario/index.php');
    exit;
}

if (!csrfValidar($_POST['csrf'] ?? null)) {
    flash('error', 'La sesión del formulario expiró, intente nuevamente.');
    header('Location: ' . APP_URL_BASE . 'formulario/index.php');
    exit;
}

$id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
requierePermiso('formulario', $id ? 'editar' : 'crear');

// Validaciones mínimas de campos requeridos
$errores = [];
foreach (['ing1_nombre', 'ing1_cedula', 'nombre_edificio', 'fecha_inspeccion', 'parroquia', 'decision_final'] as $req) {
    if (trim($_POST[$req] ?? '') === '') {
        $errores[] = "El campo \"$req\" es obligatorio.";
    }
}
if ($errores) {
    flash('error', implode(' ', $errores));
    header('Location: ' . APP_URL_BASE . 'formulario/' . ($id ? "create.php?id=$id" : 'create.php'));
    exit;
}

$danosEstructurales = [];
foreach (['columna', 'viga', 'muro', 'nodo', 'losa', 'mamposteria'] as $k) {
    $v = $_POST['danos_estructurales'][$k] ?? '';
    if ($v !== '') $danosEstructurales[$k] = $v;
}

$danosNoEstructurales = [];
foreach (['paredes_tabiqueria', 'escaleras', 'tanques_balcones', 'fachada'] as $k) {
    $v = $_POST['danos_no_estructurales'][$k] ?? '';
    if ($v !== '') $danosNoEstructurales[$k] = $v;
}

$datosAdicionales = [
    'ascensores'         => !empty($_POST['extra_ascensores']),
    'cant_ascensores'    => nullSiVacio($_POST['extra_cant_ascensores'] ?? ''),
    'fuga_gas'           => !empty($_POST['extra_fuga_gas']),
    'fallas_electricas'  => !empty($_POST['extra_fallas_electricas']),
    'danos_aguas'        => !empty($_POST['extra_danos_aguas']),
    'estado_tanque'      => trim($_POST['extra_estado_tanque'] ?? ''),
    'tiempo_accion'      => nullSiVacio($_POST['extra_tiempo_accion'] ?? ''),
    'mano_obra'          => trim($_POST['extra_mano_obra'] ?? ''),
    'herramientas'       => trim($_POST['extra_herramientas'] ?? ''),
    'maquinarias'        => trim($_POST['extra_maquinarias'] ?? ''),
];

$campos = [
    'ing1_nombre'                => trim($_POST['ing1_nombre']),
    'ing1_cedula'                => trim($_POST['ing1_cedula']),
    'ing1_telefono'              => nullSiVacio(trim($_POST['ing1_telefono'] ?? '')),
    'ing1_profesion'             => nullSiVacio(trim($_POST['ing1_profesion'] ?? '')),
    'ing1_inscripcion'           => nullSiVacio(trim($_POST['ing1_inscripcion'] ?? '')),
    'ing2_nombre'                => nullSiVacio(trim($_POST['ing2_nombre'] ?? '')),
    'ing2_cedula'                => nullSiVacio(trim($_POST['ing2_cedula'] ?? '')),
    'ing2_telefono'              => nullSiVacio(trim($_POST['ing2_telefono'] ?? '')),
    'ing2_profesion'             => nullSiVacio(trim($_POST['ing2_profesion'] ?? '')),
    'ing2_inscripcion'           => nullSiVacio(trim($_POST['ing2_inscripcion'] ?? '')),

    'nombre_edificio'            => trim($_POST['nombre_edificio']),
    'fecha_inspeccion'           => $_POST['fecha_inspeccion'],
    'hora_inicio'                => nullSiVacio($_POST['hora_inicio'] ?? ''),
    'hora_culminacion'           => nullSiVacio($_POST['hora_culminacion'] ?? ''),
    'cantidad_apartamentos'      => intPost('cantidad_apartamentos'),
    'num_pisos'                  => intPost('num_pisos'),
    'num_semisotanos'            => intPost('num_semisotanos'),
    'num_sotanos'                => intPost('num_sotanos'),

    'estado'                     => nullSiVacio(trim($_POST['estado'] ?? '')),
    'ciudad'                     => nullSiVacio(trim($_POST['ciudad'] ?? '')),
    'municipio'                  => nullSiVacio(trim($_POST['municipio'] ?? '')),
    'parroquia'                  => trim($_POST['parroquia']),
    'comuna_circuito'            => nullSiVacio(trim($_POST['comuna_circuito'] ?? '')),
    'urbanizacion'                => nullSiVacio(trim($_POST['urbanizacion'] ?? '')),
    'sector'                     => nullSiVacio(trim($_POST['sector'] ?? '')),
    'avenida_calle'              => nullSiVacio(trim($_POST['avenida_calle'] ?? '')),
    'nombre_comunidad'            => nullSiVacio(trim($_POST['nombre_comunidad'] ?? '')),
    'coordenadas_utm'             => nullSiVacio(trim($_POST['coordenadas_utm'] ?? '')),
    'huso'                       => nullSiVacio(trim($_POST['huso'] ?? '')),
    'latitud'                    => nullSiVacio($_POST['latitud'] ?? ''),
    'longitud'                   => nullSiVacio($_POST['longitud'] ?? ''),

    'uso_edificacion'             => nullSiVacio($_POST['uso_edificacion'] ?? ''),
    'tipo_estructural'             => nullSiVacio($_POST['tipo_estructural'] ?? ''),
    'material_acero'              => !empty($_POST['material_acero']) ? 1 : 0,
    'material_conexiones'         => !empty($_POST['material_conexiones']) ? 1 : 0,
    'material_mamposteria'        => !empty($_POST['material_mamposteria']) ? 1 : 0,
    'material_otros'              => !empty($_POST['material_otros']) ? 1 : 0,
    'material_otros_especifique'  => nullSiVacio(trim($_POST['material_otros_especifique'] ?? '')),

    'colapso_estructura'          => in_array($_POST['colapso_estructura'] ?? '', ['No','Parcial','Total']) ? $_POST['colapso_estructura'] : 'No',
    'riesgo_edificios_aledanos'   => $_POST['riesgo_edificios_aledanos'] ?? 'No',
    'amenaza_geologica'           => $_POST['amenaza_geologica'] ?? 'No',
    'asentamiento_edificio'       => $_POST['asentamiento_edificio'] ?? 'No',
    'inclinacion_edificio'        => $_POST['inclinacion_edificio'] ?? 'No',
    'requiere_inspeccion_interna' => $_POST['requiere_inspeccion_interna'] ?? 'No',

    'danos_estructurales'         => json_encode($danosEstructurales, JSON_UNESCAPED_UNICODE),
    'requiere_intervencion'       => $_POST['requiere_intervencion'] ?? 'No',
    'pct_dano_iii'                => nullSiVacio($_POST['pct_dano_iii'] ?? ''),
    'pct_dano_iv'                 => nullSiVacio($_POST['pct_dano_iv'] ?? ''),
    'pct_dano_v'                  => nullSiVacio($_POST['pct_dano_v'] ?? ''),

    'danos_no_estructurales'      => json_encode($danosNoEstructurales, JSON_UNESCAPED_UNICODE),

    'familias'                    => intPost('familias'),
    'ninos'                       => intPost('ninos'),
    'mujeres'                     => intPost('mujeres'),
    'hombres'                     => intPost('hombres'),
    'adultos_tercera_edad'        => intPost('adultos_tercera_edad'),
    'gestantes'                   => intPost('gestantes'),
    'movilidad_reducida'          => intPost('movilidad_reducida'),
    'mascotas'                    => intPost('mascotas'),

    'decision_final'              => $_POST['decision_final'],
    'inspeccion_previa_etiqueta'  => nullSiVacio(trim($_POST['inspeccion_previa_etiqueta'] ?? '')),
    'inspeccion_especializada'    => nullSiVacio(trim($_POST['inspeccion_especializada'] ?? '')),
    'intervencion_de'              => nullSiVacio(trim($_POST['intervencion_de'] ?? '')),
    'medidas_seguridad'            => nullSiVacio(trim($_POST['medidas_seguridad'] ?? '')),
    'm2_losas'                    => nullSiVacio($_POST['m2_losas'] ?? ''),
    'muros_reconstruir'            => nullSiVacio($_POST['muros_reconstruir'] ?? ''),
    'lugares_medidas'              => nullSiVacio(trim($_POST['lugares_medidas'] ?? '')),
    'observaciones'                => nullSiVacio(trim($_POST['observaciones'] ?? '')),
    'recomendaciones'              => nullSiVacio(trim($_POST['recomendaciones'] ?? '')),

    'datos_adicionales'            => json_encode($datosAdicionales, JSON_UNESCAPED_UNICODE),
];

try {
    $pdo = db();

    if ($id) {
        $campos['actualizado_por'] = $_SESSION['user_id'];
        $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($campos)));
        $stmt = $pdo->prepare("UPDATE inspecciones SET $sets WHERE id = :id");
        $campos['id'] = $id;
        $stmt->execute($campos);
        registrarLog($_SESSION['user_id'], 'inspeccion_actualizada', "ID: $id");
        flash('success', 'Inspección actualizada correctamente.');
    } else {
        $campos['codigo']      = generarCodigoInspeccion();
        $campos['creado_por']  = $_SESSION['user_id'];
        $cols = array_keys($campos);
        $placeholders = implode(', ', array_map(fn($c) => ":$c", $cols));
        $stmt = $pdo->prepare('INSERT INTO inspecciones (' . implode(', ', $cols) . ") VALUES ($placeholders)");
        $stmt->execute($campos);
        $id = $pdo->lastInsertId();
        registrarLog($_SESSION['user_id'], 'inspeccion_creada', "ID: $id / " . $campos['codigo']);
        flash('success', 'Inspección registrada correctamente con el código ' . $campos['codigo'] . '.');
    }

    // Eliminación de fotos marcadas (solo edición)
    if (!empty($_POST['eliminar_foto']) && is_array($_POST['eliminar_foto'])) {
        foreach ($_POST['eliminar_foto'] as $fotoId) {
            eliminarFotoInspeccion((int)$fotoId, (int)$id);
        }
    }

    // Registro fotográfico nuevo
    if (!empty($_FILES['fotos'])) {
        guardarFotosInspeccion((int)$id, $_FILES['fotos']);
    }

    header('Location: ' . APP_URL_BASE . 'formulario/view.php?id=' . $id);
    exit;

} catch (Throwable $e) {
    flash('error', APP_DEBUG ? $e->getMessage() : 'Ocurrió un error al guardar la inspección. Verifique los datos e intente nuevamente.');
    header('Location: ' . APP_URL_BASE . 'formulario/' . ($id ? "create.php?id=$id" : 'create.php'));
    exit;
}
