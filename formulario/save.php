<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

@set_time_limit(120);

// ── ¿Viene de fetch() o del sincronizador offline? ───────────────────────────
$esFetch   = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
          || ($_POST['_fetch'] ?? '') === '1';     // fallback POST si el header no llega
$esOffline = ($_SERVER['HTTP_X_OFFLINE_SYNC'] ?? '') === '1'
          || ($_POST['_offline_sync'] ?? '') === '1';
$usarJson  = $esFetch || $esOffline;

function responderJson(bool $ok, string $url = '', string $error = '', int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(compact('ok', 'url', 'error'));
    exit;
}

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($usarJson) responderJson(false, '', 'Método no permitido.', 405);
    header('Location: ' . APP_URL_BASE . 'formulario/index.php'); exit;
}

// POST vacío con Content-Length > 0 = se superó post_max_size
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $limite = ini_get('post_max_size');
    error_log('[save.php] POST vacío — post_max_size=' . $limite . ' superado. Content-Length=' . $_SERVER['CONTENT_LENGTH']);
    $msg = "Las fotos son demasiado pesadas para el servidor (límite: $limite). Reduzca el tamaño o cantidad de fotos e intente nuevamente.";
    if ($usarJson) responderJson(false, '', $msg, 413);
    flash('error', $msg);
    header('Location: ' . APP_URL_BASE . 'formulario/index.php'); exit;
}

if (!csrfValidar($_POST['csrf'] ?? null)) {
    $msg = 'La sesión expiró. Recargue la página e intente nuevamente.';
    if ($usarJson) responderJson(false, '', $msg, 403);
    flash('error', $msg);
    header('Location: ' . APP_URL_BASE . 'formulario/index.php'); exit;
}

$id      = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
$esNuevo = $id === null;

// Verificar permisos manualmente (no usar requierePermiso() porque redirige sin JSON)
if (!puede('formulario', $id ? 'editar' : 'crear')) {
    $msg = 'No tiene permiso para ' . ($id ? 'editar' : 'crear') . ' inspecciones.';
    if ($usarJson) responderJson(false, '', $msg, 403);
    http_response_code(403); include __DIR__ . '/../403.php'; exit;
}

// Deduplicación de envíos (modo offline / reintentos de red): si este mismo
// envío ya se procesó antes -aunque el navegador haya interpretado esa vez
// como "fallo de red" por un timeout- no lo volvemos a procesar. Evita crear
// inspecciones duplicadas o volver a subir las mismas fotos al reintentar.
$clientSubmissionId = trim((string)($_POST['client_submission_id'] ?? '')) ?: null;
if (!$id && $clientSubmissionId) {
    $idExistente = envioYaProcesado($clientSubmissionId);
    if ($idExistente) {
        $urlExistente = APP_URL_BASE . 'formulario/view.php?id=' . $idExistente;
        if ($usarJson) responderJson(true, $urlExistente);
        header('Location: ' . $urlExistente); exit;
    }
}

// Progreso "en vivo" para mostrar en la pantalla mientras se guarda (ver
// formulario/progreso.php, que el navegador consulta en paralelo). Se arma
// la lista de pasos ahora, ANTES de validar nada, para que el usuario vea
// feedback desde el primer instante tras apretar "Guardar".
if ($clientSubmissionId) {
    $totalFotosNuevas = contarArchivosSubidos($_FILES['fotos'] ?? []);
    $pasos = [
        ['clave' => 'validando', 'texto' => 'Validando datos', 'estado' => 'en_progreso'],
        ['clave' => 'ficha', 'texto' => 'Guardando ficha técnica', 'estado' => 'pendiente'],
    ];
    if ($totalFotosNuevas > 0) {
        $pasos[] = [
            'clave' => 'fotos', 'texto' => "Guardando fotos (0 de $totalFotosNuevas)",
            'estado' => 'pendiente', 'total' => $totalFotosNuevas, 'hechas' => 0,
        ];
    }
    $pasos[] = ['clave' => 'listo', 'texto' => 'Listo', 'estado' => 'pendiente'];
    progresoIniciar($clientSubmissionId, $pasos);
}

// Validaciones mínimas de campos requeridos
$errores = [];
foreach (['ing1_nombre', 'ing1_cedula', 'nombre_edificio', 'fecha_inspeccion', 'estado', 'municipio', 'parroquia', 'decision_final'] as $req) {
    if (trim($_POST[$req] ?? '') === '') {
        $errores[] = "El campo \"$req\" es obligatorio.";
    }
}
// Alcance nacional: un usuario estadal solo puede registrar inspecciones en
// su propio estado. Se valida en servidor (no basta con ocultar el selector).
require_once __DIR__ . '/../includes/territorial.php';
if (!usuarioEsMaster()) {
    $estadoPost = trim($_POST['estado'] ?? '');
    $estadoUsuario = estadoDelUsuario();
    if ($estadoUsuario === null || $estadoPost !== $estadoUsuario) {
        $errores[] = 'No tiene permiso para registrar inspecciones fuera de su estado asignado' .
                     ($estadoUsuario ? " ($estadoUsuario)." : '.');
    }
}
if (empty($_POST['ing1_id'])) {
    $errores[] = 'Debe seleccionar un profesional responsable del directorio de ingenieros.';
}
if ($errores) {
    $msgErr = implode(' ', $errores);
    progresoActualizar($clientSubmissionId, 'validando', 'error', $msgErr);
    if ($usarJson) responderJson(false, '', $msgErr, 422);
    flash('error', $msgErr);
    header('Location: ' . APP_URL_BASE . 'formulario/' . ($id ? "create.php?id=$id" : 'create.php')); exit;
}
progresoActualizar($clientSubmissionId, 'validando', 'listo');
progresoActualizar($clientSubmissionId, 'ficha', 'en_progreso');

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

// Punto 3 y 4 de la planilla FUNVISIS: elementos del piso crítico con daño
// severo/completo (conteo N) y con daño moderado (sin daño/moderado/examinados).
$elementosPisoCritico = ['severo' => [], 'moderado' => []];
foreach (array_keys(catalogoElementosPisoCritico()) as $k) {
    $sev = $_POST['elementos_piso_critico']['severo'][$k] ?? '';
    if ($sev !== '') $elementosPisoCritico['severo'][$k] = (int)$sev;

    $mod = $_POST['elementos_piso_critico']['moderado'][$k] ?? [];
    $fila = array_filter([
        'sin_dano'   => nullSiVacio($mod['sin_dano'] ?? ''),
        'moderado'   => nullSiVacio($mod['moderado'] ?? ''),
        'examinados' => nullSiVacio($mod['examinados'] ?? ''),
    ], fn($v) => $v !== null);
    if ($fila) $elementosPisoCritico['moderado'][$k] = $fila;
}

// Punto 7 de la planilla: acciones recomendadas (inspección detallada + medidas de prevención).
$accionesRecomendadas = [
    'inspeccion_detallada' => array_fill_keys(
        array_keys(array_filter($_POST['inspeccion_detallada'] ?? [])), true
    ),
    'medidas_prevencion'   => array_merge(
        array_fill_keys(array_keys(array_filter($_POST['medidas_prevencion'] ?? [])), true),
        ['otra_texto' => trim($_POST['medida_prevencion_otra'] ?? '')]
    ),
];

$campos = [
    'ing1_id'                    => nullSiVacio($_POST['ing1_id'] ?? ''),
    'ing1_nombre'                => trim($_POST['ing1_nombre']),
    'ing1_cedula'                => trim($_POST['ing1_cedula']),
    'ing1_telefono'              => nullSiVacio(trim($_POST['ing1_telefono'] ?? '')),
    'ing1_profesion'             => nullSiVacio(trim($_POST['ing1_profesion'] ?? '')),
    'ing1_inscripcion'           => nullSiVacio(trim($_POST['ing1_inscripcion'] ?? '')),
    'ing2_id'                    => nullSiVacio($_POST['ing2_id'] ?? ''),
    'ing2_nombre'                => nullSiVacio(trim($_POST['ing2_nombre'] ?? '')),
    'ing2_cedula'                => nullSiVacio(trim($_POST['ing2_cedula'] ?? '')),
    'ing2_telefono'              => nullSiVacio(trim($_POST['ing2_telefono'] ?? '')),
    'ing2_profesion'             => nullSiVacio(trim($_POST['ing2_profesion'] ?? '')),
    'ing2_inscripcion'           => nullSiVacio(trim($_POST['ing2_inscripcion'] ?? '')),

    'planilla_numero'            => nullSiVacio(trim($_POST['planilla_numero'] ?? '')),
    'tipo_evento'                => nullSiVacio(trim($_POST['tipo_evento'] ?? '')),
    'fecha_evento'               => nullSiVacio($_POST['fecha_evento'] ?? ''),

    'nombre_edificio'            => trim($_POST['nombre_edificio']),
    'fecha_inspeccion'           => $_POST['fecha_inspeccion'],
    'hora_inicio'                => nullSiVacio($_POST['hora_inicio'] ?? ''),
    'hora_culminacion'           => nullSiVacio($_POST['hora_culminacion'] ?? ''),
    'cantidad_apartamentos'      => intPost('cantidad_apartamentos'),
    'num_pisos'                  => intPost('num_pisos'),
    'num_semisotanos'            => intPost('num_semisotanos'),
    'num_sotanos'                => intPost('num_sotanos'),
    'anio_construccion'          => nullSiVacio($_POST['anio_construccion'] ?? ''),
    'numero_personas'            => nullSiVacio($_POST['numero_personas'] ?? ''),

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
    'material_concreto'           => !empty($_POST['material_concreto']) ? 1 : 0,
    'material_acero'              => !empty($_POST['material_acero']) ? 1 : 0,
    'material_conexiones'         => !empty($_POST['material_conexiones']) ? 1 : 0,
    'material_mamposteria'        => !empty($_POST['material_mamposteria']) ? 1 : 0,
    'mamposteria_formal'          => !empty($_POST['mamposteria_formal']) ? 1 : 0,
    'mamposteria_informal'        => !empty($_POST['mamposteria_informal']) ? 1 : 0,
    'material_otros'              => !empty($_POST['material_otros']) ? 1 : 0,
    'material_otros_especifique'  => nullSiVacio(trim($_POST['material_otros_especifique'] ?? '')),

    'colapso_estructura'          => in_array($_POST['colapso_estructura'] ?? '', ['No','Parcial','Total']) ? $_POST['colapso_estructura'] : 'No',
    'riesgo_edificios_aledanos'   => $_POST['riesgo_edificios_aledanos'] ?? 'No',
    'amenaza_geologica'           => $_POST['amenaza_geologica'] ?? 'No',
    'asentamiento_edificio'       => $_POST['asentamiento_edificio'] ?? 'No',
    'inclinacion_edificio'        => $_POST['inclinacion_edificio'] ?? 'No',
    'requiere_inspeccion_interna' => $_POST['requiere_inspeccion_interna'] ?? 'No',
    'riesgo_externo'              => nullSiVacio($_POST['riesgo_externo'] ?? ''),

    'pisos_inspeccionados'          => nullSiVacio(trim($_POST['pisos_inspeccionados'] ?? '')),
    'acceso_miembros_estructurales' => nullSiVacio($_POST['acceso_miembros_estructurales'] ?? ''),
    'piso_critico'                  => nullSiVacio(trim($_POST['piso_critico'] ?? '')),
    'riesgo_estructural_severo'     => nullSiVacio($_POST['riesgo_estructural_severo'] ?? ''),
    'elementos_piso_critico'        => json_encode($elementosPisoCritico, JSON_UNESCAPED_UNICODE),
    'riesgo_estructural_moderado'   => nullSiVacio($_POST['riesgo_estructural_moderado'] ?? ''),

    'danos_estructurales'         => json_encode($danosEstructurales, JSON_UNESCAPED_UNICODE),
    'requiere_intervencion'       => $_POST['requiere_intervencion'] ?? 'No',
    'pct_dano_iii'                => nullSiVacio($_POST['pct_dano_iii'] ?? ''),
    'pct_dano_iv'                 => nullSiVacio($_POST['pct_dano_iv'] ?? ''),
    'pct_dano_v'                  => nullSiVacio($_POST['pct_dano_v'] ?? ''),

    'danos_no_estructurales'      => json_encode($danosNoEstructurales, JSON_UNESCAPED_UNICODE),
    'riesgo_componentes'          => nullSiVacio($_POST['riesgo_componentes'] ?? ''),

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
    'acciones_recomendadas'         => json_encode($accionesRecomendadas, JSON_UNESCAPED_UNICODE),

    'datos_adicionales'            => json_encode($datosAdicionales, JSON_UNESCAPED_UNICODE),
    'tiene_tanque_agua'             => !empty($_POST['tiene_tanque_agua']) ? 1 : 0,
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
        // El ente "dueño" de la inspección es el ente del usuario que la crea.
        if (!empty($_SESSION['ente_id']) && columnaInspeccionExiste('ente_id')) {
            $campos['ente_id'] = (int)$_SESSION['ente_id'];
        }
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
    progresoActualizar($clientSubmissionId, 'ficha', 'listo');

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
        progresoActualizar($clientSubmissionId, 'fotos', 'en_progreso');
        $fotoIdsPendientesDeComprimir = guardarFotosInspeccion((int)$id, $_FILES['fotos'], $clientSubmissionId);
    }
    progresoActualizar($clientSubmissionId, 'listo', 'listo');

    $destino = APP_URL_BASE . 'formulario/view.php?id=' . $id . ($esNuevo ? '&nuevo=1' : '');

    // Si viene del sincronizador offline, responder con JSON en vez de redirigir.
    // El JS detecta { ok: true } y elimina la inspección del IndexedDB.
    if ($esOffline) {
        if ($fotoIdsPendientesDeComprimir && function_exists('fastcgi_finish_request')) {
            session_write_close();
            responderJson(true, $destino);
            fastcgi_finish_request();
            foreach ($fotoIdsPendientesDeComprimir as $fotoId) { comprimirFotoPorId($fotoId); }
            exit;
        }
        foreach ($fotoIdsPendientesDeComprimir as $fotoId) { comprimirFotoPorId($fotoId); }
        responderJson(true, $destino);
    }

    // Si el servidor corre bajo PHP-FPM (lo normal en producción), podemos
    // enviarle la respuesta al navegador YA MISMO y seguir ejecutando en
    // segundo plano para comprimir las fotos.
    if ($fotoIdsPendientesDeComprimir && function_exists('fastcgi_finish_request')) {
        if ($usarJson) {
            responderJson(true, $destino);
        } else {
            header('Location: ' . $destino);
        }
        session_write_close();
        fastcgi_finish_request();
        foreach ($fotoIdsPendientesDeComprimir as $fotoId) {
            comprimirFotoPorId($fotoId);
        }
        exit;
    }

    // Sin PHP-FPM: comprimir de forma síncrona.
    foreach ($fotoIdsPendientesDeComprimir as $fotoId) {
        comprimirFotoPorId($fotoId);
    }

    if ($usarJson) responderJson(true, $destino);
    header('Location: ' . $destino);
    exit;

} catch (Throwable $e) {
    progresoActualizar($clientSubmissionId, 'ficha', 'error', 'Ocurrió un error al guardar');
    $mensajeError = APP_DEBUG ? $e->getMessage() : 'Ocurrió un error al guardar la inspección. Verifique los datos e intente nuevamente.';
    if ($usarJson) responderJson(false, '', $mensajeError, 500);
    flash('error', $mensajeError);
    header('Location: ' . APP_URL_BASE . 'formulario/' . ($id ? "create.php?id=$id" : 'create.php'));
    exit;
}
