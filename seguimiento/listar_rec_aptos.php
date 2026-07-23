<?php
/** Devuelve (JSON) apartamentos de un piso, ambientes de un apto, o fotos de un ambiente. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');
function resp($ok,$extra=[]){ echo json_encode(array_merge(['ok'=>$ok],$extra),JSON_UNESCAPED_UNICODE); exit; }

try {
    requierePermiso('seguimiento', 'ver');

    // Apartamentos de un piso
    if (isset($_GET['piso_id'])) {
        $aptos = recApartamentos((int)$_GET['piso_id']);
        resp(true, ['apartamentos' => $aptos]);
    }

    // Ambientes de un apartamento
    if (isset($_GET['ambientes_de'])) {

        resp(true, ['ambientes' => recAmbientes((int)$_GET['ambientes_de'])]);
    }

    // Locales comerciales de un edificio, con sus conteos de ambientes
    // ya calculados para poder precargar el formulario.
    if (isset($_GET['locales_de'])) {
        $locales = recLocalesEdificio((int)$_GET['locales_de']);
        foreach ($locales as &$lc) {
            $lid = (int)$lc['id'];
            $cuenta = function ($tipo) use ($lid) {
                try {
                    $st = db()->prepare('SELECT COUNT(*) FROM rec_ambiente
                                          WHERE apartamento_id = :a AND tipo = :t');
                    $st->execute(['a' => $lid, 't' => $tipo]);
                    return (int)$st->fetchColumn();
                } catch (Throwable $e) { return 0; }
            };
            // Mismos nombres que espera pintarApartamento en modo local.
            $lc['num_venta']      = $cuenta('Área de venta');
            $lc['num_deposito']   = $cuenta('Depósito');
            $lc['num_banoslocal'] = $cuenta('Baño de local');
            $lc['es_local']       = 1;
        }
        unset($lc);
        resp(true, ['locales' => $locales]);
    }

    // Fotos de un ambiente (con URL completa)
    if (isset($_GET['fotos_de'])) {
        $fotos = recFotos('ambiente', (int)$_GET['fotos_de']);
        foreach ($fotos as &$f) $f['ruta'] = APP_URL_BASE . $f['ruta'];
        resp(true, ['fotos' => $fotos]);
    }

    // Fotos de un elemento de piso
    if (isset($_GET['fotos_elemento'])) {
        $fotos = recFotos('elemento_piso', (int)$_GET['fotos_elemento']);
        foreach ($fotos as &$f) $f['ruta'] = APP_URL_BASE . $f['ruta'];
        resp(true, ['fotos' => $fotos]);
    }

    // Reparaciones (m² por superficie) de un ambiente
    if (isset($_GET['reparaciones_de'])) {
        resp(true, ['reparaciones' => recReparaciones('ambiente', (int)$_GET['reparaciones_de'])]);
    }

    // Fotos del edificio (etiqueta, etc.)
    if (isset($_GET['fotos_edificio'])) {
        $fotos = recFotos('edificio', (int)$_GET['fotos_edificio']);
        foreach ($fotos as &$f) $f['ruta'] = APP_URL_BASE . $f['ruta'];
        resp(true, ['fotos' => $fotos]);
    }

    // Fotos de un nivel genérico (edificio, etc.)
    if (isset($_GET['fotos_nivel']) && isset($_GET['ref_id'])) {
        $nivel = $_GET['fotos_nivel'];
        if (in_array($nivel, ['edificio','piso','elemento_piso','apartamento','ambiente'], true)) {
            $fotos = recFotos($nivel, (int)$_GET['ref_id']);
            foreach ($fotos as &$f) $f['ruta'] = APP_URL_BASE . $f['ruta'];
            resp(true, ['fotos' => $fotos]);
        }
    }

    resp(false, ['mensaje' => 'Parámetro no reconocido.']);
} catch (Throwable $e) {
    resp(false, ['mensaje' => APP_DEBUG ? $e->getMessage() : 'Error.']);
}
