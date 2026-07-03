<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Guardar/editar una inspección con varias fotos implica trabajo de CPU
// (compresión de imágenes) que puede tardar más que el límite por defecto
// de PHP en algunos servidores (30s). Le damos más margen aquí; el trabajo
// pesado de verdad (comprimir) igual se hace después de responder al
// navegador, ver más abajo.
@set_time_limit(120);

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL_BASE . 'formulario/index.php');
    exit;
}

// Si el navegador mandó contenido (Content-Length > 0) pero PHP no recibió
// NADA en $_POST ni $_FILES, es la huella clásica de que se superó
// post_max_size o upload_max_filesize: PHP descarta TODO el request en
// silencio (sin marcar error en ningún campo puntual). Sin este log, esto
// se ve igual que "el usuario dejó campos vacíos".
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    error_log('[save.php] POST y FILES vacíos con Content-Length=' . $_SERVER['CONTENT_LENGTH']
        . ' bytes — se superó post_max_size (actual: ' . ini_get('post_max_size')
        . ') o upload_max_filesize (actual: ' . ini_get('upload_max_filesize')
        . '). El request se descartó completo, incluyendo los datos del formulario, no solo la foto.');
    flash('error', 'La foto es demasiado pesada para el límite actual del servidor. Contacte al administrador.');
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

// Deduplicación de envíos (modo offline / reintentos de red): si este mismo
// envío ya se procesó antes -aunque el navegador haya interpretado esa vez
// como "fallo de red" por un timeout- no lo volvemos a procesar. Evita crear
// inspecciones duplicadas o volver a subir las mismas fotos al reintentar.
$clientSubmissionId = trim((string)($_POST['client_submission_id'] ?? '')) ?: null;
if (!$id && $clientSubmissionId) {
    $idExistente = envioYaProcesado($clientSubmissionId);
    if ($idExistente) {
        header('Location: ' . APP_URL_BASE . 'formulario/view.php?id=' . $idExistente);
        exit;
    }
}

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

    // A partir de aquí, el registro principal YA está guardado. Si algo
    // pasa con las fotos (incluso un corte de conexión del cliente), la
    // inspección en sí no se pierde ni queda a medias.
    registrarEnvioProcesado($clientSubmissionId, (int)$id);

    // Eliminación de fotos marcadas (solo edición) — es rápido (solo
    // borra archivo + fila), se hace antes de responder.
    if (!empty($_POST['eliminar_foto']) && is_array($_POST['eliminar_foto'])) {
        foreach ($_POST['eliminar_foto'] as $fotoId) {
            eliminarFotoInspeccion((int)$fotoId, (int)$id);
        }
    }

    // Registro fotográfico nuevo: se guarda YA (archivo movido + fila en BD,
    // visible de inmediato en la ficha), pero SIN comprimir todavía. Eso es
    // lo que puede tardar varios segundos con varias fotos, y es la causa
    // más probable de que el guardado "se sienta lento" en producción.
    $fotoIdsPendientesDeComprimir = [];
    if (!empty($_FILES['fotos'])) {
        $fotoIdsPendientesDeComprimir = guardarFotosInspeccion((int)$id, $_FILES['fotos']);
    }

    $destino = APP_URL_BASE . 'formulario/view.php?id=' . $id;

    // Si el servidor corre bajo PHP-FPM (lo normal en producción), podemos
    // enviarle la respuesta al navegador YA MISMO y seguir ejecutando en
    // segundo plano para comprimir las fotos. El usuario ve el guardado
    // como instantáneo; la compresión (que solo aligera el archivo, la foto
    // original ya quedó guardada y visible) termina segundos después sin
    // que nadie tenga que esperarla.
    if ($fotoIdsPendientesDeComprimir && function_exists('fastcgi_finish_request')) {
        header('Location: ' . $destino);
        // Cierra la conexión con el navegador; el script sigue corriendo.
        session_write_close();
        fastcgi_finish_request();

        foreach ($fotoIdsPendientesDeComprimir as $fotoId) {
            comprimirFotoPorId($fotoId);
        }
        exit;
    }

    // Sin PHP-FPM (p. ej. servidor de desarrollo con `php -S`, o mod_php):
    // comprimimos de forma síncrona, como antes.
    foreach ($fotoIdsPendientesDeComprimir as $fotoId) {
        comprimirFotoPorId($fotoId);
    }

    header('Location: ' . $destino);
    exit;

} catch (Throwable $e) {
    flash('error', APP_DEBUG ? $e->getMessage() : 'Ocurrió un error al guardar la inspección. Verifique los datos e intente nuevamente.');
    header('Location: ' . APP_URL_BASE . 'formulario/' . ($id ? "create.php?id=$id" : 'create.php'));
    exit;
}
