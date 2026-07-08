<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requierePermiso('configuracion', 'editar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidar($_POST['csrf'] ?? null)) {
    flash('error', 'La sesión del formulario expiró, intente nuevamente.');
    header('Location: ' . APP_URL_BASE . 'admin/configuracion.php');
    exit;
}

$accion = $_POST['accion'] ?? '';

if ($accion === 'guardar_formulario') {
    $secciones = [];
    foreach (array_keys(catalogoSeccionesFormulario()) as $key) {
        $secciones[$key] = !empty($_POST['secciones'][$key]);
    }
    guardarConfigValor('formulario_secciones', $secciones, $_SESSION['user_id']);
    registrarLog($_SESSION['user_id'], 'config_formulario_actualizada', json_encode($secciones));
    flash('success', 'Secciones del formulario actualizadas.');
} elseif ($accion === 'guardar_dashboard') {
    $widgets = [];
    $orden = 1;
    foreach (array_keys(catalogoWidgetsDashboard()) as $id) {
        $w = $_POST['widgets'][$id] ?? [];
        $sinColor = !empty($w['sin_color']);
        $widgets[] = [
            'id'        => $id,
            'visible'   => !empty($w['visible']),
            'orden'     => isset($w['orden']) && $w['orden'] !== '' ? (int)$w['orden'] : $orden,
            'color'     => $sinColor ? null : nullSiVacio(trim($w['color'] ?? '')),
            'color2'    => nullSiVacio(trim($w['color2'] ?? '')),
            'gradiente' => !empty($w['gradiente']),
        ];
        $orden++;
    }
    guardarConfigValor('dashboard_widgets', $widgets, $_SESSION['user_id']);
    registrarLog($_SESSION['user_id'], 'config_dashboard_actualizada', json_encode($widgets));
    flash('success', 'Configuración del dashboard actualizada.');
} elseif ($accion === 'agregar_kpi') {
    $campos = catalogoCamposKpi();
    $campo  = trim($_POST['campo'] ?? '');
    $tipo   = trim($_POST['tipo'] ?? '');
    $label  = trim($_POST['label'] ?? '');
    $valor  = trim($_POST['valor'] ?? '');

    // Validación estricta contra la lista blanca: el nombre de columna se usa
    // directamente en SQL en dashboard/api_kpis.php, así que nunca se acepta
    // un campo que no esté en catalogoCamposKpi().
    if ($label === '' || !isset($campos[$campo])) {
        flash('error', 'KPI inválido: falta la etiqueta o el campo elegido no es válido.');
        header('Location: ' . APP_URL_BASE . 'admin/configuracion.php');
        exit;
    }
    $meta = $campos[$campo];

    if ($meta['tipo'] === 'numero') {
        if (!in_array($tipo, ['suma', 'promedio'], true)) {
            flash('error', 'Para un campo numérico el cálculo debe ser Suma o Promedio.');
            header('Location: ' . APP_URL_BASE . 'admin/configuracion.php');
            exit;
        }
        $valor = null;
    } else {
        $tipo = 'conteo';
        // Las claves de 'opciones' son siempre el valor real a comparar en SQL
        // (catalogoCamposKpi ya las normaliza así); se castea a string porque
        // PHP convierte claves de array que parecen enteros (ej. '0', '1') a int.
        $valoresPermitidos = array_map('strval', array_keys($meta['opciones']));
        if ($valor === '' || !in_array($valor, $valoresPermitidos, true)) {
            flash('error', 'El valor a contar no es válido para el campo elegido.');
            header('Location: ' . APP_URL_BASE . 'admin/configuracion.php');
            exit;
        }
    }

    $lista = obtenerConfigKpisCustom();
    $maxOrden = 0;
    foreach ($lista as $k) { $maxOrden = max($maxOrden, (int)($k['orden'] ?? 0)); }

    $lista[] = [
        'id'        => 'kpi_' . bin2hex(random_bytes(5)),
        'label'     => $label,
        'campo'     => $campo,
        'tipo'      => $tipo,
        'valor'     => $valor,
        'icono'     => nullSiVacio(trim($_POST['icono'] ?? '')) ?: 'bi-graph-up-arrow',
        'color'     => !empty($_POST['sin_color']) ? null : nullSiVacio(trim($_POST['color'] ?? '')),
        'color2'    => nullSiVacio(trim($_POST['color2'] ?? '')),
        'gradiente' => !empty($_POST['gradiente']),
        'visible'   => true,
        'orden'     => $maxOrden + 1,
    ];
    guardarConfigValor('dashboard_kpis_custom', $lista, $_SESSION['user_id']);
    registrarLog($_SESSION['user_id'], 'config_kpi_agregado', $label . ' (' . $campo . ')');
    flash('success', 'KPI "' . $label . '" agregado al dashboard.');
} elseif ($accion === 'guardar_kpis') {
    $lista = obtenerConfigKpisCustom();
    foreach ($lista as &$k) {
        $post = $_POST['kpis'][$k['id']] ?? null;
        if (!$post) continue; // no vino en este envío (no debería pasar, pero por si acaso)
        $sinColor = !empty($post['sin_color']);
        $k['visible']   = !empty($post['visible']);
        $k['orden']     = isset($post['orden']) && $post['orden'] !== '' ? (int)$post['orden'] : $k['orden'];
        $k['color']     = $sinColor ? null : nullSiVacio(trim($post['color'] ?? ''));
        $k['color2']    = nullSiVacio(trim($post['color2'] ?? ''));
        $k['gradiente'] = !empty($post['gradiente']);
    }
    unset($k);
    guardarConfigValor('dashboard_kpis_custom', $lista, $_SESSION['user_id']);
    registrarLog($_SESSION['user_id'], 'config_kpis_actualizados', (string)count($lista));
    flash('success', 'KPIs personalizados actualizados.');
} elseif ($accion === 'eliminar_kpi') {
    $kpiId = trim($_POST['kpi_id'] ?? '');
    $lista = array_values(array_filter(obtenerConfigKpisCustom(), fn($k) => $k['id'] !== $kpiId));
    guardarConfigValor('dashboard_kpis_custom', $lista, $_SESSION['user_id']);
    registrarLog($_SESSION['user_id'], 'config_kpi_eliminado', $kpiId);
    flash('success', 'KPI eliminado del dashboard.');
} elseif ($accion === 'guardar_mapa') {
    $modo     = in_array($_POST['mapa_modo'] ?? '', ['normal','inspeccion','seguimiento','personalizado'])
                ? $_POST['mapa_modo'] : 'normal';
    $inspIds  = array_map('intval', (array)($_POST['mapa_insp_ids'] ?? []));
    $segIds   = array_map('intval', (array)($_POST['mapa_seg_ids']  ?? []));
    $colorI   = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['mapa_color_insp'] ?? '') ? $_POST['mapa_color_insp'] : '#22366f';
    $colorS   = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['mapa_color_seg']  ?? '') ? $_POST['mapa_color_seg']  : '#f0a63a';
    $mapaOpc  = ['modo'=>$modo,'insp_ids'=>$inspIds,'seg_ids'=>$segIds,
                 'color_inspeccion'=>$colorI,'color_seguimiento'=>$colorS];
    guardarConfigValor('mapa_opciones', $mapaOpc, $_SESSION['user_id']);
    registrarLog($_SESSION['user_id'], 'config_mapa_actualizada', "modo=$modo ids_insp=".count($inspIds)." ids_seg=".count($segIds));
    flash('success', 'Configuración del mapa guardada.');
} else {
    flash('error', 'Acción de configuración no reconocida.');
}

header('Location: ' . APP_URL_BASE . 'admin/configuracion.php');
exit;
