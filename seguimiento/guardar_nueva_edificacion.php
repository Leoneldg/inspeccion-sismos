<?php
/**
 * REGISTRA UNA EDIFICACIÓN encontrada en campo que no estaba en el listado.
 *
 * Crea la inspección con lo mínimo indispensable y su registro de
 * reconstrucción, para que se pueda hacer el levantamiento después.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

header('Content-Type: application/json; charset=utf-8');

function jr(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    requierePermiso('seguimiento', 'editar');

    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) jr(false, 'Datos inválidos.');

    // --- Validaciones ---
    $nombre    = trim($b['nombre_edificio'] ?? '');
    $parroquia = trim($b['parroquia'] ?? '');
    $direccion = trim($b['direccion'] ?? '');
    $decision  = trim($b['decision_final'] ?? '');
    $estado    = trim($b['estado'] ?? 'Distrito Capital');

    if ($nombre === '')    jr(false, 'Indique el nombre de la edificación.');
    if ($parroquia === '') jr(false, 'Indique la parroquia.');
    if ($decision === '')  jr(false, 'Indique la decisión.');

    if (!puedeAccederParroquia($parroquia)) {
        jr(false, 'No tiene asignada esa parroquia.');
    }

    // Coordenadas dentro de Venezuela.
    $lat = $b['latitud'] !== '' ? (float)$b['latitud'] : null;
    $lng = $b['longitud'] !== '' ? (float)$b['longitud'] : null;
    if ($lat === null || $lng === null) jr(false, 'Falta la ubicación en el mapa.');
    if ($lat < 0.6 || $lat > 12.2 || $lng < -73.4 || $lng > -59.8) {
        jr(false, 'La ubicación está fuera de Venezuela. Verifique el punto en el mapa.');
    }

    // Aviso de posible duplicado, SOLO si el usuario no confirmó ya.
    // El radio es corto (~15 m): en zonas densas hay edificios muy juntos
    // y una edificación nueva no debe quedar bloqueada por eso.
    if (empty($b['confirmar_cercano'])) {
        $st = db()->prepare(
            "SELECT id, codigo, nombre_edificio, latitud, longitud
               FROM inspecciones
              WHERE parroquia = :p
                AND latitud IS NOT NULL AND longitud IS NOT NULL
                AND ABS(latitud - :la) < 0.00014
                AND ABS(longitud - :lo) < 0.00014
              ORDER BY ABS(latitud - :la2) + ABS(longitud - :lo2)
              LIMIT 3"
        );
        $st->execute([
            'p' => $parroquia, 'la' => $lat, 'lo' => $lng,
            'la2' => $lat, 'lo2' => $lng,
        ]);
        $cercanas = $st->fetchAll();

        if ($cercanas) {
            // No se bloquea: se informa para que el técnico decida.
            $lista = [];
            foreach ($cercanas as $c) {
                $m = (int)round(
                    sqrt(pow(((float)$c['latitud'] - $lat) * 111000, 2)
                       + pow(((float)$c['longitud'] - $lng) * 108000, 2))
                );
                $lista[] = [
                    'id'     => (int)$c['id'],
                    'codigo' => $c['codigo'],
                    'nombre' => $c['nombre_edificio'],
                    'metros' => $m,
                ];
            }
            jr(false, 'Hay edificaciones registradas muy cerca de ese punto.',
               ['cercanas' => $lista, 'preguntar' => true]);
        }
    }

    // --- Datos del inspector (campos obligatorios de la tabla) ---
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $nombreUsr = $_SESSION['user_nombre'] ?? ($_SESSION['nombre'] ?? 'Sistema');
    $cedulaUsr = '';
    try {
        $q = db()->prepare('SELECT nombre_completo, cedula FROM usuarios WHERE id = :id');
        $q->execute(['id' => $uid]);
        if ($u = $q->fetch()) {
            $nombreUsr = $u['nombre_completo'] ?: $nombreUsr;
            $cedulaUsr = $u['cedula'] ?? '';
        }
    } catch (Throwable $e) { /* la columna cédula puede no existir */ }

    $pdo = db();
    $pdo->beginTransaction();

    $codigo = generarCodigoInspeccion();

    // La dirección se guarda en avenida_calle (la tabla no tiene "direccion")
    // y las familias en la columna "familias".
    $sql = 'INSERT INTO inspecciones
              (codigo, nombre_edificio, fecha_inspeccion, estado, municipio, parroquia,
               avenida_calle, latitud, longitud, uso_edificacion, num_pisos,
               familias, numero_personas, decision_final, observaciones,
               ing1_nombre, ing1_cedula, creado_por, creado_en)
            VALUES
              (:cod, :nom, :fecha, :est, :mun, :parr,
               :dir, :lat, :lng, :uso, :pisos,
               :fam, :per, :dec, :obs,
               :ing, :ced, :uid, NOW())';
    $pdo->prepare($sql)->execute([
        'cod'   => $codigo,
        'nom'   => $nombre,
        'fecha' => date('Y-m-d'),
        'est'   => $estado,
        'mun'   => trim($b['municipio'] ?? '') ?: 'Libertador',
        'parr'  => $parroquia,
        'dir'   => $direccion ?: null,
        'lat'   => $lat,
        'lng'   => $lng,
        'uso'   => trim($b['uso_edificacion'] ?? '') ?: null,
        'pisos' => (int)($b['num_pisos'] ?? 1),
        'fam'   => (int)($b['numero_familias'] ?? 0),
        'per'   => (int)($b['numero_personas'] ?? 0),
        'dec'   => $decision,
        'obs'   => trim($b['observaciones'] ?? '') ?: null,
        'ing'   => $nombreUsr,
        'ced'   => $cedulaUsr ?: 'S/C',
        'uid'   => $uid ?: null,
    ]);
    $inspeccionId = (int)$pdo->lastInsertId();

    $pdo->commit();

    // Crear el registro de reconstrucción y guardar lo de la etiqueta.
    $ed = recEdificio($inspeccionId);
    $edificioId = (int)($ed['id'] ?? 0);

    if ($edificioId > 0) {
        recAsegurarColumnasEtiqueta();
        db()->prepare(
            'UPDATE rec_edificio SET num_pisos = :np, sin_etiqueta = :se, etiqueta_motivo = :em
              WHERE id = :id'
        )->execute([
            'np' => (int)($b['num_pisos'] ?? 1),
            'se' => !empty($b['sin_etiqueta']) ? 1 : 0,
            'em' => trim($b['etiqueta_motivo'] ?? '') ?: null,
            'id' => $edificioId,
        ]);
    }

    recAuditar('edificacion_agregada', $inspeccionId, $edificioId,
        $codigo . ' · ' . $nombre . ' · ' . $parroquia);

    jr(true, 'Edificación registrada.', [
        'inspeccion_id' => $inspeccionId,
        'edificio_id'   => $edificioId,
        'codigo'        => $codigo,
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    jr(false, APP_DEBUG ? $e->getMessage() : 'No se pudo registrar la edificación.');
}
