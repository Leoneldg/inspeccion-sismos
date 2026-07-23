<?php
/**
 * REQUISICIONES DE MATERIAL
 * =========================================================================
 *
 * Que resuelve
 * ------------
 * El levantamiento tecnico calcula solo lo estructural: bloques, cemento,
 * arena, friso, pintura. Sale de los metros que midio el tecnico.
 *
 * Pero la reconstruccion necesita mucho mas: cables, tomacorrientes,
 * breakers, tuberias, codos, llaves, cal, clavos... Nada de eso se deduce
 * de los metros cuadrados: hay que solicitarlo.
 *
 * Este modulo emite REQUISICIONES: la solicitud formal del material que
 * hace falta para reconstruir una edificacion.
 *
 * Como funciona una requisicion
 * -----------------------------
 * Es un documento con numero propio (REQ-2026-0001), fecha y solicitante.
 * Tiene dos momentos:
 *
 *   BORRADOR   se esta preparando. Se agregan, corrigen y quitan
 *              renglones libremente.
 *   EMITIDA    ya se solicito. Queda cerrada como constancia de lo
 *              que se pidio y en que fecha.
 *
 * No hay aprobacion: se prepara y se emite. Una requisicion emitida se
 * puede reabrir si hubo un error, y queda registrado que se reabrio.
 *
 * Se pide todo de una vez, asi que normalmente hay una requisicion por
 * edificacion. Aun asi el modulo admite varias, porque en la practica
 * siempre aparece algo que se quedo por fuera y hace falta una segunda.
 *
 * Como convive con lo que ya existe
 * ---------------------------------
 * NO toca ninguna tabla del sistema. Crea las suyas, con prefijo `req_`:
 *
 *   req_rubro       catalogo de rubros    (Electricidad, Plomeria...)
 *   req_item        catalogo de articulos (Cable #12, Codo 1/2"...)
 *   req_requisicion cabecera del documento
 *   req_renglon     lo solicitado en cada requisicion
 *
 * No modifica, no borra y no sustituye filas ni columnas de las tablas
 * existentes. Se relaciona con el resto por `edificio_id` (rec_edificio),
 * igual que los demas modulos de seguimiento.
 *
 * Las tablas se crean solas la primera vez que se abre el modulo, con el
 * mismo patron `recAsegurar...()` del resto del sistema. No hace falta
 * correr ningun SQL a mano.
 * =========================================================================
 */

// =========================================================================
// ESTADOS
// =========================================================================

/** Estados posibles de una requisicion. */
function reqEstados(): array
{
    return [
        'borrador' => [
            'nombre' => 'Borrador',
            'color'  => '#A66A00',
            'fondo'  => '#fdf3e7',
            'icono'  => 'bi-pencil-square',
            'ayuda'  => 'Se está preparando. Puede agregar y quitar materiales.',
        ],
        'emitida' => [
            'nombre' => 'Emitida',
            'color'  => '#2E7D32',
            'fondo'  => '#e5f7ee',
            'icono'  => 'bi-send-check-fill',
            'ayuda'  => 'Ya se solicitó. Queda como constancia de lo pedido.',
        ],
    ];
}

/** Datos de presentacion de un estado. */
function reqEstadoInfo(?string $estado): array
{
    $e = reqEstados();
    return $e[$estado ?? 'borrador'] ?? $e['borrador'];
}

// =========================================================================
// CREACION DE TABLAS
// =========================================================================

/**
 * Crea las tablas del modulo si no existen y siembra el catalogo.
 * Se llama al principio de cada pagina del modulo.
 */
function reqAsegurarTablas(): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;

    try {
        // --- Rubros ---
        db()->exec("CREATE TABLE IF NOT EXISTS req_rubro (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            clave VARCHAR(40) NOT NULL,
            nombre VARCHAR(120) NOT NULL,
            icono VARCHAR(40) DEFAULT NULL,
            color VARCHAR(20) DEFAULT NULL,
            orden INT NOT NULL DEFAULT 0,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_req_rubro (clave)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Articulos del catalogo ---
        db()->exec("CREATE TABLE IF NOT EXISTS req_item (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            rubro_id INT UNSIGNED NOT NULL,
            nombre VARCHAR(160) NOT NULL,
            unidad VARCHAR(20) NOT NULL DEFAULT 'unidad',
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_por INT UNSIGNED DEFAULT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_req_item_rubro (rubro_id),
            UNIQUE KEY uq_req_item (rubro_id, nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Cabecera de la requisicion ---
        //
        // `numero` es el consecutivo visible (REQ-2026-0001). Se calcula
        // al crear y no cambia nunca: es el identificador del documento.
        db()->exec("CREATE TABLE IF NOT EXISTS req_requisicion (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            numero VARCHAR(30) NOT NULL,
            edificio_id INT UNSIGNED NOT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'borrador',
            titulo VARCHAR(160) DEFAULT NULL,
            observaciones VARCHAR(500) DEFAULT NULL,
            solicitante_id INT UNSIGNED DEFAULT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            emitida_en DATETIME DEFAULT NULL,
            emitida_por INT UNSIGNED DEFAULT NULL,
            reabierta_en DATETIME DEFAULT NULL,
            reabierta_por INT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_req_numero (numero),
            KEY idx_req_ed (edificio_id),
            KEY idx_req_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Renglones ---
        //
        // `item_id` puede ser NULL: asi se admite un material escrito a
        // mano sin obligar a meterlo en el catalogo. Manda `nombre_libre`.
        db()->exec("CREATE TABLE IF NOT EXISTS req_renglon (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            requisicion_id INT UNSIGNED NOT NULL,
            rubro_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED DEFAULT NULL,
            nombre_libre VARCHAR(160) DEFAULT NULL,
            unidad VARCHAR(20) NOT NULL DEFAULT 'unidad',
            cantidad DECIMAL(12,2) NOT NULL DEFAULT 0,
            nota VARCHAR(300) DEFAULT NULL,
            creado_por INT UNSIGNED DEFAULT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_ren_req (requisicion_id),
            KEY idx_ren_rubro (requisicion_id, rubro_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        reqSembrarCatalogo();

    } catch (Throwable $e) {
        error_log('reqAsegurarTablas: ' . $e->getMessage());
    }
}

/**
 * Siembra rubros y articulos de arranque, solo si el catalogo esta vacio.
 * La idea es que al abrir el modulo ya haya de donde escoger.
 */
function reqSembrarCatalogo(): void
{
    try {
        $hay = (int)db()->query('SELECT COUNT(*) FROM req_rubro')->fetchColumn();
        if ($hay > 0) return;

        $rubros = [
            ['electricidad', 'Electricidad',      'bi-lightning-charge-fill', '#B8860B', 1],
            ['plomeria',     'Plomería',          'bi-droplet-fill',          '#1F6FB2', 2],
            ['albanileria',  'Albañilería',       'bi-bricks',                '#8a6d1a', 3],
            ['herreria',     'Herrería',          'bi-nut-fill',              '#5b6478', 4],
            ['carpinteria',  'Carpintería',       'bi-door-closed-fill',      '#7a5230', 5],
            ['impermeab',    'Impermeabilización','bi-umbrella-fill',         '#2E7D32', 6],
            ['herramientas', 'Herramientas',      'bi-tools',                 '#5c4a8a', 7],
            ['otros',        'Otros materiales',  'bi-box-seam',              '#22366F', 9],
        ];
        $stR = db()->prepare('INSERT INTO req_rubro (clave, nombre, icono, color, orden)
                              VALUES (:c, :n, :i, :co, :o)');
        foreach ($rubros as $r) {
            $stR->execute(['c'=>$r[0], 'n'=>$r[1], 'i'=>$r[2], 'co'=>$r[3], 'o'=>$r[4]]);
        }

        $items = [
            'electricidad' => [
                ['Cable THW #12 (100 m)', 'rollo'], ['Cable THW #10 (100 m)', 'rollo'],
                ['Cable THW #14 (100 m)', 'rollo'], ['Tomacorriente doble', 'unidad'],
                ['Interruptor sencillo', 'unidad'], ['Interruptor doble', 'unidad'],
                ['Breaker 1x20A', 'unidad'], ['Breaker 2x30A', 'unidad'],
                ['Tablero 8 circuitos', 'unidad'], ['Caja de paso 4x4', 'unidad'],
                ['Tubo conduit 1/2"', 'unidad'], ['Bombillo LED 9W', 'unidad'],
                ['Portalámpara', 'unidad'], ['Teipe aislante', 'rollo'],
            ],
            'plomeria' => [
                ['Tubo PVC 1/2"', 'unidad'], ['Tubo PVC 3/4"', 'unidad'],
                ['Tubo PVC 4" (aguas negras)', 'unidad'], ['Codo PVC 1/2" 90°', 'unidad'],
                ['Codo PVC 3/4" 90°', 'unidad'], ['Tee PVC 1/2"', 'unidad'],
                ['Llave de paso 1/2"', 'unidad'], ['Llave de lavamanos', 'unidad'],
                ['Poceta completa', 'unidad'], ['Lavamanos', 'unidad'],
                ['Pega para PVC', 'unidad'], ['Teflón', 'rollo'],
                ['Tanque de agua 500 L', 'unidad'],
            ],
            'albanileria' => [
                ['Cal hidratada', 'saco'], ['Yeso', 'saco'],
                ['Cemento blanco', 'saco'], ['Granzón', 'm3'],
                ['Piedra picada', 'm3'], ['Cerámica de piso', 'm2'],
                ['Pego para cerámica', 'saco'], ['Fragua', 'kg'],
            ],
            'herreria' => [
                ['Cabilla 3/8"', 'unidad'], ['Cabilla 1/2"', 'unidad'],
                ['Alambre de amarre', 'kg'], ['Malla truckson', 'm2'],
                ['Electrodo de soldadura', 'kg'],
            ],
            'carpinteria' => [
                ['Puerta de madera', 'unidad'], ['Marco de puerta', 'unidad'],
                ['Cerradura', 'unidad'], ['Bisagra', 'unidad'],
                ['Clavos 2"', 'kg'], ['Tornillos', 'caja'],
            ],
            'impermeab' => [
                ['Manto asfáltico', 'rollo'], ['Pintura impermeabilizante', 'unidad'],
                ['Imprimante asfáltico', 'unidad'],
            ],
            'herramientas' => [
                ['Pala', 'unidad'], ['Carretilla', 'unidad'],
                ['Cuchara de albañil', 'unidad'], ['Nivel', 'unidad'],
                ['Guantes', 'par'], ['Casco', 'unidad'],
            ],
            'otros' => [],
        ];

        $stI = db()->prepare('INSERT INTO req_item (rubro_id, nombre, unidad) VALUES (:r, :n, :u)');
        foreach ($items as $claveRubro => $lista) {
            $rid = (int)db()->query("SELECT id FROM req_rubro WHERE clave = "
                                    . db()->quote($claveRubro))->fetchColumn();
            if ($rid <= 0) continue;
            foreach ($lista as $it) {
                try { $stI->execute(['r'=>$rid, 'n'=>$it[0], 'u'=>$it[1]]); }
                catch (Throwable $e) { /* duplicado: seguir */ }
            }
        }
    } catch (Throwable $e) {
        error_log('reqSembrarCatalogo: ' . $e->getMessage());
    }
}

// =========================================================================
// UNIDADES
// =========================================================================

/** Unidades disponibles. */
function reqUnidades(): array
{
    return ['unidad', 'saco', 'rollo', 'caja', 'metro', 'm2', 'm3',
            'kg', 'litro', 'galón', 'par', 'juego', 'pieza'];
}

/** Unidades que no admiten fracciones: no se pide medio tomacorriente. */
function reqUnidadEsEntera(string $unidad): bool
{
    return in_array(mb_strtolower(trim($unidad), 'UTF-8'),
                    ['unidad', 'saco', 'rollo', 'caja', 'pieza', 'par', 'juego'], true);
}

/** Formatea una cantidad al estilo venezolano (coma decimal). */
function reqFormatoCantidad(float $cant, string $unidad): string
{
    $dec = reqUnidadEsEntera($unidad) ? 0 : 2;
    return number_format($cant, $dec, ',', '.');
}

// =========================================================================
// CATALOGO
// =========================================================================

/** Rubros activos, ordenados. */
function reqRubros(): array
{
    reqAsegurarTablas();
    try {
        return db()->query('SELECT * FROM req_rubro WHERE activo = 1
                            ORDER BY orden, nombre')->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/** Articulos del catalogo, agrupados por rubro. */
function reqItemsPorRubro(): array
{
    reqAsegurarTablas();
    try {
        $out = [];
        foreach (db()->query('SELECT * FROM req_item WHERE activo = 1 ORDER BY nombre')->fetchAll() as $it) {
            $out[(int)$it['rubro_id']][] = $it;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Agrega un articulo al catalogo (o devuelve el que ya existia). */
function reqAgregarItem(int $rubroId, string $nombre, string $unidad): ?int
{
    reqAsegurarTablas();
    $nombre = trim($nombre);
    $unidad = trim($unidad) ?: 'unidad';
    if ($nombre === '' || $rubroId <= 0) return null;

    try {
        $st = db()->prepare('SELECT id FROM req_item
                              WHERE rubro_id = :r AND nombre = :n LIMIT 1');
        $st->execute(['r' => $rubroId, 'n' => $nombre]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) {
            db()->prepare('UPDATE req_item SET activo = 1, unidad = :u WHERE id = :id')
                ->execute(['u' => $unidad, 'id' => $id]);
            return $id;
        }
        db()->prepare('INSERT INTO req_item (rubro_id, nombre, unidad, creado_por)
                       VALUES (:r, :n, :u, :c)')
            ->execute(['r'=>$rubroId, 'n'=>$nombre, 'u'=>$unidad,
                       'c'=>$_SESSION['user_id'] ?? null]);
        return (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('reqAgregarItem: ' . $e->getMessage());
        return null;
    }
}

/** Agrega un rubro nuevo (o devuelve el existente). */
function reqAgregarRubro(string $nombre): ?int
{
    reqAsegurarTablas();
    $nombre = trim($nombre);
    if ($nombre === '') return null;

    $clave = mb_strtolower($nombre, 'UTF-8');
    $clave = strtr($clave, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $clave = preg_replace('/[^a-z0-9]+/', '_', $clave);
    $clave = trim((string)$clave, '_');
    if ($clave === '') $clave = 'rubro_' . time();
    $clave = mb_substr($clave, 0, 40);

    try {
        $st = db()->prepare('SELECT id FROM req_rubro WHERE clave = :c LIMIT 1');
        $st->execute(['c' => $clave]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) {
            db()->prepare('UPDATE req_rubro SET activo = 1 WHERE id = :id')->execute(['id' => $id]);
            return $id;
        }
        $orden = (int)db()->query('SELECT COALESCE(MAX(orden), 0) + 1 FROM req_rubro')->fetchColumn();
        db()->prepare('INSERT INTO req_rubro (clave, nombre, icono, color, orden)
                       VALUES (:c, :n, :i, :co, :o)')
            ->execute(['c'=>$clave, 'n'=>$nombre, 'i'=>'bi-box-seam', 'co'=>'#22366F', 'o'=>$orden]);
        return (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('reqAgregarRubro: ' . $e->getMessage());
        return null;
    }
}

// =========================================================================
// REQUISICIONES
// =========================================================================

/**
 * Numero consecutivo del documento: REQ-2026-0001.
 *
 * El consecutivo es por año. Se calcula sobre lo que ya existe y se
 * reintenta si dos personas crean una requisicion al mismo tiempo (la
 * restriccion UNIQUE del numero impide que se repita).
 */
function reqSiguienteNumero(): string
{
    $anio = date('Y');
    try {
        $st = db()->prepare("SELECT numero FROM req_requisicion
                              WHERE numero LIKE :p
                           ORDER BY id DESC LIMIT 1");
        $st->execute(['p' => 'REQ-' . $anio . '-%']);
        $ultimo = (string)($st->fetchColumn() ?: '');
        $n = 0;
        if ($ultimo !== '' && preg_match('/-(\d+)$/', $ultimo, $m)) {
            $n = (int)$m[1];
        }
        return 'REQ-' . $anio . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return 'REQ-' . $anio . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}

/**
 * Crea una requisicion en borrador para una edificacion.
 * Devuelve ['ok'=>bool, 'mensaje'=>string, 'id'=>int, 'numero'=>string]
 */
function reqCrear(int $edificioId, string $titulo = '', string $observaciones = ''): array
{
    reqAsegurarTablas();
    if ($edificioId <= 0) return ['ok'=>false, 'mensaje'=>'Edificación no válida.'];

    $titulo = mb_substr(trim($titulo), 0, 160);
    $observaciones = mb_substr(trim($observaciones), 0, 500);

    // Hasta 5 intentos por si otro usuario toma el mismo numero.
    for ($i = 0; $i < 5; $i++) {
        $numero = reqSiguienteNumero();
        try {
            db()->prepare(
                'INSERT INTO req_requisicion
                    (numero, edificio_id, estado, titulo, observaciones, solicitante_id)
                 VALUES (:n, :e, :st, :t, :o, :s)'
            )->execute([
                'n'=>$numero, 'e'=>$edificioId, 'st'=>'borrador',
                't'=>($titulo ?: null), 'o'=>($observaciones ?: null),
                's'=>$_SESSION['user_id'] ?? null,
            ]);
            return ['ok'=>true, 'mensaje'=>'Requisición ' . $numero . ' creada.',
                    'id'=>(int)db()->lastInsertId(), 'numero'=>$numero];
        } catch (Throwable $e) {
            // Numero repetido: se reintenta con el siguiente.
            if ($i === 4) {
                error_log('reqCrear: ' . $e->getMessage());
                return ['ok'=>false, 'mensaje'=>'No se pudo crear la requisición.'];
            }
        }
    }
    return ['ok'=>false, 'mensaje'=>'No se pudo crear la requisición.'];
}

/** Cabecera de una requisicion, con datos de la edificacion. */
function reqObtener(int $requisicionId): ?array
{
    reqAsegurarTablas();
    if ($requisicionId <= 0) return null;
    try {
        $st = db()->prepare(
            "SELECT r.*,
                    i.id AS inspeccion_id, i.codigo, i.nombre_edificio, i.parroquia,
                    us.nombre_completo AS usuario_creador,
                    ue.nombre_completo AS emitida_por_nombre
               FROM req_requisicion r
               JOIN rec_edificio re ON re.id = r.edificio_id
               JOIN inspecciones i  ON i.id = re.inspeccion_id
          LEFT JOIN usuarios us     ON us.id = r.solicitante_id
          LEFT JOIN usuarios ue     ON ue.id = r.emitida_por
              WHERE r.id = :id"
        );
        $st->execute(['id' => $requisicionId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('reqObtener: ' . $e->getMessage());
        return null;
    }
}

/**
 * Quien solicita la requisicion.
 *
 * Es el ingeniero (o profesional) responsable del levantamiento de esa
 * edificacion: es quien responde tecnicamente por lo que se pide.
 * Se lee de rec_edificio.ingeniero_id a traves de recIngenieroDe().
 *
 * Si esa edificacion todavia no tiene responsable asignado, se cae al
 * usuario que creo el documento, para que la requisicion nunca salga
 * sin nombre.
 *
 * Devuelve ['nombre'=>..., 'cedula'=>..., 'profesion'=>..., 'es_ingeniero'=>bool]
 */
function reqSolicitante(array $requisicion): array
{
    $vacio = ['nombre' => '', 'cedula' => '', 'profesion' => '', 'es_ingeniero' => false];

    // Si el listado ya trajo el nombre por JOIN, se usa: evita una
    // consulta por cada fila de la tabla.
    $yaTraido = trim((string)($requisicion['ingeniero_nombre'] ?? ''));
    if ($yaTraido !== '') {
        return ['nombre' => $yaTraido, 'cedula' => '', 'profesion' => '', 'es_ingeniero' => true];
    }

    $edificioId = (int)($requisicion['edificio_id'] ?? 0);
    if ($edificioId > 0) {
        try {
            $ing = recIngenieroDe($edificioId);
            if ($ing && !empty($ing['nombre'])) {
                return [
                    'nombre'       => (string)$ing['nombre'],
                    'cedula'       => (string)($ing['cedula'] ?? ''),
                    'profesion'    => (string)($ing['profesion'] ?? ''),
                    'es_ingeniero' => true,
                ];
            }
        } catch (Throwable $e) { /* cae al usuario */ }
    }

    $u = trim((string)($requisicion['usuario_creador'] ?? ''));
    if ($u !== '') {
        return ['nombre' => $u, 'cedula' => '', 'profesion' => '', 'es_ingeniero' => false];
    }
    return $vacio;
}

/** Requisiciones de una edificacion, la mas reciente primero. */
function reqDeEdificio(int $edificioId): array
{
    reqAsegurarTablas();
    if ($edificioId <= 0) return [];
    try {
        $hayIng2  = function_exists('tablaIngenierosExiste') ? tablaIngenierosExiste() : false;
        $selIng2  = $hayIng2 ? 'ing.nombre_completo AS ingeniero_nombre,' : '';
        $joinIng2 = $hayIng2 ? 'LEFT JOIN ingenieros ing ON ing.id = re2.ingeniero_id' : '';

        $st = db()->prepare(
            "SELECT r.*,
                    us.nombre_completo AS usuario_creador,
                    $selIng2
                    (SELECT COUNT(*) FROM req_renglon rr
                      WHERE rr.requisicion_id = r.id) AS n_renglones
               FROM req_requisicion r
          LEFT JOIN usuarios us      ON us.id = r.solicitante_id
          LEFT JOIN rec_edificio re2 ON re2.id = r.edificio_id
               $joinIng2
              WHERE r.edificio_id = :e
           ORDER BY r.id DESC"
        );
        $st->execute(['e' => $edificioId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/** Renglones de una requisicion, agrupados por rubro. */
function reqRenglones(int $requisicionId): array
{
    reqAsegurarTablas();
    if ($requisicionId <= 0) return [];
    try {
        $st = db()->prepare(
            "SELECT rr.*,
                    ru.nombre AS rubro_nombre, ru.icono AS rubro_icono,
                    ru.color AS rubro_color, ru.orden AS rubro_orden,
                    COALESCE(it.nombre, rr.nombre_libre) AS material
               FROM req_renglon rr
               JOIN req_rubro ru ON ru.id = rr.rubro_id
          LEFT JOIN req_item it  ON it.id = rr.item_id
              WHERE rr.requisicion_id = :r
           ORDER BY ru.orden, ru.nombre, material"
        );
        $st->execute(['r' => $requisicionId]);

        $out = [];
        foreach ($st->fetchAll() as $f) {
            $rid = (int)$f['rubro_id'];
            if (!isset($out[$rid])) {
                $out[$rid] = [
                    'rubro' => [
                        'id'     => $rid,
                        'nombre' => $f['rubro_nombre'],
                        'icono'  => $f['rubro_icono'] ?: 'bi-box-seam',
                        'color'  => $f['rubro_color'] ?: '#22366F',
                    ],
                    'lineas' => [],
                ];
            }
            $out[$rid]['lineas'][] = $f;
        }
        return $out;
    } catch (Throwable $e) {
        error_log('reqRenglones: ' . $e->getMessage());
        return [];
    }
}

/** Cuantos renglones tiene una requisicion. */
function reqTotalRenglones(int $requisicionId): int
{
    reqAsegurarTablas();
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM req_renglon WHERE requisicion_id = :r');
        $st->execute(['r' => $requisicionId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** ¿Se puede modificar? Solo mientras este en borrador. */
function reqEsEditable(?array $requisicion): bool
{
    return $requisicion !== null && ($requisicion['estado'] ?? '') === 'borrador';
}

/** Actualiza titulo y observaciones (solo en borrador). */
function reqActualizarCabecera(int $requisicionId, string $titulo, string $observaciones): array
{
    $r = reqObtener($requisicionId);
    if (!$r) return ['ok'=>false, 'mensaje'=>'La requisición no existe.'];
    if (!reqEsEditable($r)) {
        return ['ok'=>false, 'mensaje'=>'Una requisición emitida no se puede modificar.'];
    }
    try {
        db()->prepare('UPDATE req_requisicion SET titulo = :t, observaciones = :o WHERE id = :id')
            ->execute([
                't'  => (mb_substr(trim($titulo), 0, 160) ?: null),
                'o'  => (mb_substr(trim($observaciones), 0, 500) ?: null),
                'id' => $requisicionId,
            ]);
        return ['ok'=>true, 'mensaje'=>'Datos actualizados.'];
    } catch (Throwable $e) {
        error_log('reqActualizarCabecera: ' . $e->getMessage());
        return ['ok'=>false, 'mensaje'=>'No se pudo actualizar.'];
    }
}

/**
 * Emite la requisicion: queda cerrada como constancia de lo solicitado.
 * No se emite vacia: un documento sin renglones no pide nada.
 */
function reqEmitir(int $requisicionId): array
{
    $r = reqObtener($requisicionId);
    if (!$r) return ['ok'=>false, 'mensaje'=>'La requisición no existe.'];
    if (($r['estado'] ?? '') === 'emitida') {
        return ['ok'=>false, 'mensaje'=>'Esta requisición ya fue emitida.'];
    }
    if (reqTotalRenglones($requisicionId) === 0) {
        return ['ok'=>false, 'mensaje'=>'Agregue al menos un material antes de emitirla.'];
    }
    try {
        db()->prepare(
            "UPDATE req_requisicion
                SET estado = 'emitida', emitida_en = NOW(), emitida_por = :u
              WHERE id = :id"
        )->execute(['u' => $_SESSION['user_id'] ?? null, 'id' => $requisicionId]);
        return ['ok'=>true, 'mensaje'=>'Requisición ' . $r['numero'] . ' emitida.'];
    } catch (Throwable $e) {
        error_log('reqEmitir: ' . $e->getMessage());
        return ['ok'=>false, 'mensaje'=>'No se pudo emitir.'];
    }
}

/**
 * Reabre una requisicion emitida, para corregir un error.
 * Queda registrado quien la reabrio y cuando: el documento no se
 * modifica en silencio.
 */
function reqReabrir(int $requisicionId): array
{
    $r = reqObtener($requisicionId);
    if (!$r) return ['ok'=>false, 'mensaje'=>'La requisición no existe.'];
    if (($r['estado'] ?? '') !== 'emitida') {
        return ['ok'=>false, 'mensaje'=>'Solo se puede reabrir una requisición emitida.'];
    }
    try {
        db()->prepare(
            "UPDATE req_requisicion
                SET estado = 'borrador', reabierta_en = NOW(), reabierta_por = :u
              WHERE id = :id"
        )->execute(['u' => $_SESSION['user_id'] ?? null, 'id' => $requisicionId]);
        return ['ok'=>true, 'mensaje'=>'Requisición reabierta. Puede corregirla y volver a emitirla.'];
    } catch (Throwable $e) {
        error_log('reqReabrir: ' . $e->getMessage());
        return ['ok'=>false, 'mensaje'=>'No se pudo reabrir.'];
    }
}

/** Borra una requisicion completa (solo en borrador). */
function reqBorrar(int $requisicionId): array
{
    $r = reqObtener($requisicionId);
    if (!$r) return ['ok'=>false, 'mensaje'=>'La requisición no existe.'];
    if (!reqEsEditable($r)) {
        return ['ok'=>false, 'mensaje'=>'Una requisición emitida no se puede borrar. Reábrala primero.'];
    }
    try {
        db()->prepare('DELETE FROM req_renglon WHERE requisicion_id = :r')
            ->execute(['r' => $requisicionId]);
        db()->prepare('DELETE FROM req_requisicion WHERE id = :id')
            ->execute(['id' => $requisicionId]);
        return ['ok'=>true, 'mensaje'=>'Requisición eliminada.'];
    } catch (Throwable $e) {
        error_log('reqBorrar: ' . $e->getMessage());
        return ['ok'=>false, 'mensaje'=>'No se pudo eliminar.'];
    }
}

// =========================================================================
// RENGLONES
// =========================================================================

/**
 * Guarda un renglon (crea o actualiza).
 *
 * Si el material ya esta en la requisicion se ACTUALIZA en vez de
 * duplicar: es lo que espera quien vuelve a cargar algo que ya puso.
 */
function reqGuardarRenglon(int $requisicionId, array $datos): array
{
    reqAsegurarTablas();

    $r = reqObtener($requisicionId);
    if (!$r) return ['ok'=>false, 'mensaje'=>'La requisición no existe.'];
    if (!reqEsEditable($r)) {
        return ['ok'=>false, 'mensaje'=>'Una requisición emitida no se puede modificar. Reábrala si necesita corregirla.'];
    }

    $rubroId  = (int)($datos['rubro_id'] ?? 0);
    $itemId   = (int)($datos['item_id'] ?? 0);
    $libre    = trim((string)($datos['nombre_libre'] ?? ''));
    $unidad   = trim((string)($datos['unidad'] ?? 'unidad')) ?: 'unidad';
    $cantidad = (float)str_replace(',', '.', (string)($datos['cantidad'] ?? 0));
    $nota     = trim((string)($datos['nota'] ?? ''));
    $renglonId= (int)($datos['renglon_id'] ?? 0);

    if ($rubroId <= 0)                 return ['ok'=>false, 'mensaje'=>'Elija el rubro.'];
    if ($itemId <= 0 && $libre === '') return ['ok'=>false, 'mensaje'=>'Elija o escriba el material.'];
    if ($cantidad <= 0)                return ['ok'=>false, 'mensaje'=>'La cantidad debe ser mayor que cero.'];

    if (reqUnidadEsEntera($unidad)) $cantidad = ceil($cantidad);

    if (mb_strlen($libre) > 160) return ['ok'=>false, 'mensaje'=>'El nombre del material es muy largo.'];
    if (mb_strlen($nota) > 300)  return ['ok'=>false, 'mensaje'=>'La nota es muy larga.'];

    try {
        // --- Modificar un renglon concreto ---
        if ($renglonId > 0) {
            db()->prepare(
                'UPDATE req_renglon
                    SET rubro_id = :r, item_id = :i, nombre_libre = :nl,
                        unidad = :u, cantidad = :c, nota = :nt, actualizado_en = NOW()
                  WHERE id = :id AND requisicion_id = :req'
            )->execute([
                'r'=>$rubroId, 'i'=>($itemId ?: null), 'nl'=>($itemId ? null : $libre),
                'u'=>$unidad, 'c'=>$cantidad, 'nt'=>($nota ?: null),
                'id'=>$renglonId, 'req'=>$requisicionId,
            ]);
            return ['ok'=>true, 'mensaje'=>'Material actualizado.', 'id'=>$renglonId];
        }

        // --- ¿Ya esta ese material en esta requisicion? ---
        if ($itemId > 0) {
            $st = db()->prepare('SELECT id FROM req_renglon
                                  WHERE requisicion_id = :r AND item_id = :i LIMIT 1');
            $st->execute(['r'=>$requisicionId, 'i'=>$itemId]);
        } else {
            $st = db()->prepare('SELECT id FROM req_renglon
                                  WHERE requisicion_id = :r AND rubro_id = :ru
                                    AND nombre_libre = :nl LIMIT 1');
            $st->execute(['r'=>$requisicionId, 'ru'=>$rubroId, 'nl'=>$libre]);
        }
        $existe = (int)($st->fetchColumn() ?: 0);

        if ($existe > 0) {
            db()->prepare(
                'UPDATE req_renglon
                    SET cantidad = :c, unidad = :u, nota = :nt, actualizado_en = NOW()
                  WHERE id = :id'
            )->execute(['c'=>$cantidad, 'u'=>$unidad, 'nt'=>($nota ?: null), 'id'=>$existe]);
            return ['ok'=>true, 'mensaje'=>'Ya estaba en la requisición: se actualizó la cantidad.', 'id'=>$existe];
        }

        db()->prepare(
            'INSERT INTO req_renglon
                (requisicion_id, rubro_id, item_id, nombre_libre, unidad, cantidad, nota, creado_por)
             VALUES (:req, :r, :i, :nl, :u, :c, :nt, :cp)'
        )->execute([
            'req'=>$requisicionId, 'r'=>$rubroId, 'i'=>($itemId ?: null),
            'nl'=>($itemId ? null : $libre), 'u'=>$unidad, 'c'=>$cantidad,
            'nt'=>($nota ?: null), 'cp'=>$_SESSION['user_id'] ?? null,
        ]);
        return ['ok'=>true, 'mensaje'=>'Material agregado.', 'id'=>(int)db()->lastInsertId()];

    } catch (Throwable $e) {
        error_log('reqGuardarRenglon: ' . $e->getMessage());
        return ['ok'=>false, 'mensaje'=>'No se pudo guardar el material.'];
    }
}

/** Quita un renglon (solo en borrador). */
function reqBorrarRenglon(int $requisicionId, int $renglonId): array
{
    $r = reqObtener($requisicionId);
    if (!$r) return ['ok'=>false, 'mensaje'=>'La requisición no existe.'];
    if (!reqEsEditable($r)) {
        return ['ok'=>false, 'mensaje'=>'Una requisición emitida no se puede modificar.'];
    }
    try {
        $st = db()->prepare('DELETE FROM req_renglon WHERE id = :id AND requisicion_id = :r');
        $st->execute(['id'=>$renglonId, 'r'=>$requisicionId]);
        if ($st->rowCount() === 0) return ['ok'=>false, 'mensaje'=>'No se encontró ese material.'];
        return ['ok'=>true, 'mensaje'=>'Material eliminado.'];
    } catch (Throwable $e) {
        error_log('reqBorrarRenglon: ' . $e->getMessage());
        return ['ok'=>false, 'mensaje'=>'No se pudo eliminar.'];
    }
}

/**
 * Copia los renglones de otra requisicion.
 * Sirve para edificios parecidos: se prepara uno y se replica.
 */
function reqCopiarRenglones(int $destinoId, int $origenId): array
{
    $d = reqObtener($destinoId);
    if (!$d) return ['ok'=>false, 'mensaje'=>'La requisición no existe.'];
    if (!reqEsEditable($d)) {
        return ['ok'=>false, 'mensaje'=>'Una requisición emitida no se puede modificar.'];
    }
    if ($origenId <= 0 || $origenId === $destinoId) {
        return ['ok'=>false, 'mensaje'=>'Elija una requisición de origen válida.'];
    }
    try {
        $st = db()->prepare('SELECT * FROM req_renglon WHERE requisicion_id = :r');
        $st->execute(['r' => $origenId]);
        $filas = $st->fetchAll() ?: [];
        if (!$filas) return ['ok'=>false, 'mensaje'=>'Esa requisición no tiene materiales.'];

        $n = 0;
        foreach ($filas as $f) {
            $r = reqGuardarRenglon($destinoId, [
                'rubro_id'     => (int)$f['rubro_id'],
                'item_id'      => (int)($f['item_id'] ?? 0),
                'nombre_libre' => (string)($f['nombre_libre'] ?? ''),
                'unidad'       => (string)$f['unidad'],
                'cantidad'     => (float)$f['cantidad'],
                'nota'         => (string)($f['nota'] ?? ''),
            ]);
            if (!empty($r['ok'])) $n++;
        }
        return ['ok'=>true, 'mensaje'=>"Se copiaron $n materiales.", 'copiadas'=>$n];
    } catch (Throwable $e) {
        error_log('reqCopiarRenglones: ' . $e->getMessage());
        return ['ok'=>false, 'mensaje'=>'No se pudo copiar.'];
    }
}

// =========================================================================
// LISTADOS Y CONSOLIDADO
// =========================================================================

/** Todas las requisiciones, con filtros. Es la pantalla principal. */
function reqListado(array $filtros = []): array
{
    reqAsegurarTablas();
    try {
        $conds = [];
        $params = [];
        aplicarScopeEstado($conds, $params, 'i');
        aplicarScopeParroquia($conds, $params, 'i');

        if (!empty($filtros['estado'])) {
            $conds[] = 'r.estado = :est';
            $params['est'] = $filtros['estado'];
        }
        if (!empty($filtros['parroquia'])) {
            $conds[] = 'i.parroquia = :parr';
            $params['parr'] = $filtros['parroquia'];
        }
        if (!empty($filtros['texto'])) {
            $conds[] = '(i.nombre_edificio LIKE :txt OR i.codigo LIKE :txt OR r.numero LIKE :txt)';
            $params['txt'] = '%' . $filtros['texto'] . '%';
        }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

        // El directorio de ingenieros puede no existir en instalaciones
        // antiguas: solo se une si la tabla esta.
        $hayIng  = function_exists('tablaIngenierosExiste') ? tablaIngenierosExiste() : false;
        $selIng  = $hayIng ? 'ing.nombre_completo AS ingeniero_nombre,' : '';
        $joinIng = $hayIng ? 'LEFT JOIN ingenieros ing ON ing.id = re.ingeniero_id' : '';

        $st = db()->prepare(
            "SELECT r.*,
                    i.id AS inspeccion_id, i.codigo, i.nombre_edificio, i.parroquia,
                    us.nombre_completo AS usuario_creador,
                    $selIng
                    (SELECT COUNT(*) FROM req_renglon rr
                      WHERE rr.requisicion_id = r.id) AS n_renglones
               FROM req_requisicion r
               JOIN rec_edificio re ON re.id = r.edificio_id
               JOIN inspecciones i  ON i.id = re.inspeccion_id
          LEFT JOIN usuarios us     ON us.id = r.solicitante_id
               $joinIng
               $where
           ORDER BY r.id DESC"
        );
        $st->execute($params);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('reqListado: ' . $e->getMessage());
        return [];
    }
}

/**
 * Edificaciones con levantamiento y cuantas requisiciones tienen.
 * Sirve para saber a cual todavia no se le ha pedido nada.
 */
function reqEdificaciones(array $filtros = []): array
{
    reqAsegurarTablas();
    try {
        $conds = [];
        $params = [];
        aplicarScopeEstado($conds, $params, 'i');
        aplicarScopeParroquia($conds, $params, 'i');

        if (!empty($filtros['parroquia'])) {
            $conds[] = 'i.parroquia = :parr';
            $params['parr'] = $filtros['parroquia'];
        }
        if (!empty($filtros['texto'])) {
            $conds[] = '(i.nombre_edificio LIKE :txt OR i.codigo LIKE :txt)';
            $params['txt'] = '%' . $filtros['texto'] . '%';
        }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $st = db()->prepare(
            "SELECT i.id AS inspeccion_id, i.codigo, i.nombre_edificio,
                    i.parroquia, re.id AS edificio_id, re.completado,
                    (SELECT COUNT(*) FROM req_requisicion q
                      WHERE q.edificio_id = re.id) AS n_req,
                    (SELECT COUNT(*) FROM req_requisicion q2
                      WHERE q2.edificio_id = re.id AND q2.estado = 'emitida') AS n_emitidas
               FROM inspecciones i
               JOIN rec_edificio re ON re.inspeccion_id = i.id
               $where
           ORDER BY i.nombre_edificio"
        );
        $st->execute($params);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('reqEdificaciones: ' . $e->getMessage());
        return [];
    }
}

/**
 * Consolidado: cuanto se ha solicitado en total de cada material.
 *
 * Por defecto cuenta solo las requisiciones EMITIDAS: los borradores
 * todavia se estan preparando y sumarlos daria una cifra falsa.
 */
function reqConsolidado(array $filtros = []): array
{
    reqAsegurarTablas();
    try {
        $conds = [];
        $params = [];

        $soloEmitidas = !array_key_exists('incluir_borradores', $filtros)
                        || !$filtros['incluir_borradores'];
        if ($soloEmitidas) $conds[] = "r.estado = 'emitida'";

        if (!empty($filtros['parroquia'])) {
            $conds[] = 'i.parroquia = :parr';
            $params['parr'] = $filtros['parroquia'];
        }
        $where = $conds ? ('AND ' . implode(' AND ', $conds)) : '';

        $st = db()->prepare(
            "SELECT ru.id AS rubro_id, ru.nombre AS rubro_nombre,
                    ru.icono AS rubro_icono, ru.color AS rubro_color, ru.orden,
                    COALESCE(it.nombre, rr.nombre_libre) AS material,
                    rr.unidad,
                    SUM(rr.cantidad) AS total,
                    COUNT(DISTINCT r.edificio_id) AS n_edificios,
                    COUNT(DISTINCT r.id) AS n_requisiciones
               FROM req_renglon rr
               JOIN req_requisicion r ON r.id = rr.requisicion_id
               JOIN req_rubro ru      ON ru.id = rr.rubro_id
          LEFT JOIN req_item it       ON it.id = rr.item_id
               JOIN rec_edificio re   ON re.id = r.edificio_id
               JOIN inspecciones i    ON i.id = re.inspeccion_id
              WHERE 1 = 1 $where
           GROUP BY ru.id, ru.nombre, ru.icono, ru.color, ru.orden, material, rr.unidad
           ORDER BY ru.orden, ru.nombre, material"
        );
        $st->execute($params);

        $out = [];
        foreach ($st->fetchAll() as $f) {
            $rid = (int)$f['rubro_id'];
            if (!isset($out[$rid])) {
                $out[$rid] = [
                    'rubro' => [
                        'id'     => $rid,
                        'nombre' => $f['rubro_nombre'],
                        'icono'  => $f['rubro_icono'] ?: 'bi-box-seam',
                        'color'  => $f['rubro_color'] ?: '#22366F',
                    ],
                    'lineas' => [],
                ];
            }
            $out[$rid]['lineas'][] = $f;
        }
        return $out;
    } catch (Throwable $e) {
        error_log('reqConsolidado: ' . $e->getMessage());
        return [];
    }
}

/** Requisiciones emitidas de otras edificaciones, para copiar de ellas. */
function reqParaCopiar(int $excluirId = 0): array
{
    reqAsegurarTablas();
    try {
        $st = db()->prepare(
            "SELECT r.id, r.numero, i.nombre_edificio, i.codigo,
                    (SELECT COUNT(*) FROM req_renglon rr
                      WHERE rr.requisicion_id = r.id) AS n
               FROM req_requisicion r
               JOIN rec_edificio re ON re.id = r.edificio_id
               JOIN inspecciones i  ON i.id = re.inspeccion_id
              WHERE r.id <> :x
                AND (SELECT COUNT(*) FROM req_renglon rr2
                      WHERE rr2.requisicion_id = r.id) > 0
           ORDER BY r.id DESC LIMIT 100"
        );
        $st->execute(['x' => $excluirId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}
