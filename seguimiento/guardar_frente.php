<?php
/**
 * Gestión de frentes de trabajo, su equipo de supervisión y sus cuadrillas.
 * Todas las acciones llegan por JSON con el campo "accion".
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
    frenteNumAsegurarTablas();

    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) jr(false, 'Datos inválidos.');

    $accion = $b['accion'] ?? '';
    $estado = estadoDelUsuario() ?: 'Distrito Capital';
    $pdo = db();

    frenteRespAsegurar();

    // ---------- FRENTE POR RESPONSABLE (numeración correlativa) ----------
    if ($accion === 'crear_frente_resp') {
        $parr = trim($b['parroquia'] ?? '');
        if ($parr === '') jr(false, 'Indique la parroquia.');
        if (!puedeAccederParroquia($parr)) jr(false, 'No tiene asignada esa parroquia.');

        $r = frenteCrear((int)($b['responsable_id'] ?? 0), $parr, $estado,
                         trim($b['nombre'] ?? ''));
        jr(true, 'Frente creado.', [
            'frente_id' => $r['id'], 'numero' => $r['numero'],
            'nombre' => $r['nombre'] ?? null,
        ]);
    }

    // ---------- BRIGADAS ----------
    if ($accion === 'crear_brigada') {
        $fid = (int)($b['frente_id'] ?? 0);
        if ($fid <= 0) jr(false, 'Frente no válido.');

        // El usuario de un frente solo crea brigadas en el suyo.
        $miFrente = frenteDelUsuario();
        if ($miFrente > 0 && $fid !== $miFrente) {
            jr(false, 'Solo puede crear brigadas en su propio frente.');
        }

        $r = brigadaCrear($fid);
        jr(true, 'Brigada creada.', ['brigada_id' => $r['id'], 'numero' => $r['numero']]);
    }

    if ($accion === 'quitar_brigada') {
        $id = (int)($b['brigada_id'] ?? 0);
        if ($id <= 0) jr(false, 'Brigada no válida.');

        $miFrente = frenteDelUsuario();
        if ($miFrente > 0) {
            $st = $pdo->prepare('SELECT frente_id FROM brigada WHERE id = :id');
            $st->execute(['id' => $id]);
            if ((int)$st->fetchColumn() !== $miFrente) {
                jr(false, 'Esa brigada no pertenece a su frente.');
            }
        }

        $pdo->prepare('DELETE FROM obra_brigada WHERE brigada_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM brigada WHERE id = :id')->execute(['id' => $id]);
        jr(true, 'Brigada eliminada.');
    }

    if ($accion === 'asignar_obra_brigada') {
        $insp = (int)($b['inspeccion_id'] ?? 0);
        $brig = (int)($b['brigada_id'] ?? 0);
        if ($insp <= 0 || $brig <= 0) jr(false, 'Datos incompletos.');

        $miFrente = frenteDelUsuario();
        if ($miFrente > 0) {
            $st = $pdo->prepare('SELECT frente_id FROM brigada WHERE id = :b');
            $st->execute(['b' => $brig]);
            if ((int)$st->fetchColumn() !== $miFrente) {
                jr(false, 'Esa brigada no pertenece a su frente.');
            }
        }

        $pdo->prepare(
            'INSERT INTO obra_brigada (inspeccion_id, brigada_id, asignado_por)
             VALUES (:i, :b, :u)
             ON DUPLICATE KEY UPDATE asignado_por = VALUES(asignado_por)'
        )->execute(['i' => $insp, 'b' => $brig, 'u' => $_SESSION['user_id'] ?? null]);

        $st = $pdo->prepare('SELECT numero FROM brigada WHERE id = :b');
        $st->execute(['b' => $brig]);
        recAuditar('brigada_asignada', $insp, null, 'Brigada ' . ($st->fetchColumn() ?: $brig));
        jr(true, 'Brigada asignada.');
    }

    if ($accion === 'quitar_obra_brigada') {
        $insp = (int)($b['inspeccion_id'] ?? 0);
        $brig = (int)($b['brigada_id'] ?? 0);
        $pdo->prepare('DELETE FROM obra_brigada WHERE inspeccion_id = :i AND brigada_id = :b')
            ->execute(['i' => $insp, 'b' => $brig]);
        jr(true, 'Brigada retirada.');
    }

    // ---------- FRENTE ----------
    // =================================================================
    // EQUIPO DEL FRENTE
    // ---------------------------------------------------------------
    // Responsable escrito a mano, más un ingeniero del catálogo y un
    // sistematizador del sistema. Uno de cada uno por frente.
    // =================================================================
    if ($accion === 'equipo') {
        segAsegurarEquipoFrente();

        $frenteId = (int)($b['frente_id'] ?? 0);
        if ($frenteId <= 0) resp(false, 'Frente no válido.');

        $ingId = !empty($b['ingeniero_id']) ? (int)$b['ingeniero_id'] : null;
        $sisId = !empty($b['sistematizador_id']) ? (int)$b['sistematizador_id'] : null;

        // Comprobar que existan, para no dejar referencias rotas.
        if ($ingId !== null) {
            $stV = db()->prepare('SELECT COUNT(*) FROM ingenieros
                                   WHERE id = :i AND activo = 1');
            $stV->execute(['i' => $ingId]);
            if (!(int)$stV->fetchColumn()) resp(false, 'El ingeniero no existe.');
        }
        if ($sisId !== null) {
            $stV = db()->prepare('SELECT COUNT(*) FROM usuarios
                                   WHERE id = :u AND activo = 1');
            $stV->execute(['u' => $sisId]);
            if (!(int)$stV->fetchColumn()) resp(false, 'El usuario no existe.');
        }

        db()->prepare('UPDATE frente
                          SET responsable = :r, responsable_tlf = :t,
                              ingeniero_id = :i, sistematizador_id = :s
                        WHERE id = :f')
            ->execute([
                'r' => trim($b['responsable'] ?? '') ?: null,
                't' => trim($b['responsable_tlf'] ?? '') ?: null,
                'i' => $ingId,
                's' => $sisId,
                'f' => $frenteId,
            ]);

        resp(true, 'Equipo del frente actualizado.');
    }

    if ($accion === 'crear_frente') {
        $numero = (int)($b['numero'] ?? 0);
        if ($numero < 1) jr(false, 'Indique el número del frente.');

        $st = $pdo->prepare('SELECT id FROM frente WHERE numero = :n AND estado = :e');
        $st->execute(['n' => $numero, 'e' => $estado]);
        if ($st->fetch()) jr(false, 'Ya existe el Frente de Trabajo ' . $numero . '.');

        $pdo->prepare(
            'INSERT INTO frente (numero, nombre, ente_id, estado, creado_por)
             VALUES (:n, :nom, :ente, :e, :u)'
        )->execute([
            'n'    => $numero,
            'nom'  => trim($b['nombre'] ?? '') ?: null,
            'ente' => (int)($b['ente_id'] ?? 0) ?: null,
            'e'    => $estado,
            'u'    => $_SESSION['user_id'] ?? null,
        ]);
        $id = (int)$pdo->lastInsertId();
        recAuditar('frente_creado', null, null, 'Frente de Trabajo ' . $numero);
        jr(true, 'Frente creado.', ['frente_id' => $id]);
    }

    if ($accion === 'renombrar_frente') {
        $id = (int)($b['frente_id'] ?? 0);
        if ($id <= 0) jr(false, 'Frente no válido.');
        frenteRenombrar($id, trim($b['nombre'] ?? ''));
        jr(true, 'Nombre actualizado.');
    }

    if ($accion === 'desactivar_frente') {
        $id = (int)($b['frente_id'] ?? 0);
        if ($id <= 0) jr(false, 'Frente no válido.');

        $st = $pdo->prepare('SELECT numero FROM frente WHERE id = :id');
        $st->execute(['id' => $id]);
        $numero = (int)($st->fetchColumn() ?: 0);

        // Se elimina de verdad para que su número quede libre y la
        // secuencia no deje saltos. Las obras no se borran: solo pierden
        // la asignación al frente.
        $pdo->prepare('DELETE FROM asignacion_frente_obra WHERE frente_id = :id')->execute(['id' => $id]);
        try {
            $pdo->prepare('DELETE FROM obra_brigada WHERE brigada_id IN
                            (SELECT id FROM brigada WHERE frente_id = :id)')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM brigada WHERE frente_id = :id')->execute(['id' => $id]);
        } catch (Throwable $e) { /* sin brigadas */ }
        try {
            $pdo->prepare('DELETE FROM frente_supervisor WHERE frente_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM frente_parroquia WHERE frente_id = :id')->execute(['id' => $id]);
        } catch (Throwable $e) {}
        $pdo->prepare('DELETE FROM frente WHERE id = :id')->execute(['id' => $id]);

        recAuditar('frente_eliminado', null, null, 'Frente de Trabajo ' . $numero);
        jr(true, 'Frente eliminado. El número ' . $numero . ' queda libre.');
    }

    // ---------- PARROQUIAS ----------
    if ($accion === 'agregar_parroquia') {
        $id = (int)($b['frente_id'] ?? 0);
        $parr = trim($b['parroquia'] ?? '');
        if ($id <= 0 || $parr === '') jr(false, 'Datos incompletos.');
        if (!puedeAccederParroquia($parr)) jr(false, 'No tiene asignada esa parroquia.');

        $pdo->prepare(
            'INSERT IGNORE INTO frente_parroquia (frente_id, estado, parroquia)
             VALUES (:f, :e, :p)'
        )->execute(['f' => $id, 'e' => $estado, 'p' => $parr]);
        jr(true, 'Parroquia agregada.');
    }

    if ($accion === 'quitar_parroquia') {
        $id = (int)($b['frente_id'] ?? 0);
        $parr = trim($b['parroquia'] ?? '');
        $pdo->prepare(
            'DELETE FROM frente_parroquia WHERE frente_id = :f AND parroquia = :p'
        )->execute(['f' => $id, 'p' => $parr]);
        jr(true, 'Parroquia quitada.');
    }

    // ---------- SUPERVISIÓN ----------
    if ($accion === 'agregar_supervisor') {
        $id = (int)($b['frente_id'] ?? 0);
        $nom = trim($b['nombre'] ?? '');
        if ($id <= 0 || $nom === '') jr(false, 'Indique el nombre del supervisor.');

        $pdo->prepare(
            'INSERT INTO frente_supervisor (frente_id, nombre, cedula, telefono, cargo)
             VALUES (:f, :n, :c, :t, :ca)'
        )->execute([
            'f'  => $id, 'n' => $nom,
            'c'  => trim($b['cedula'] ?? '') ?: null,
            't'  => trim($b['telefono'] ?? '') ?: null,
            'ca' => trim($b['cargo'] ?? '') ?: 'Supervisor',
        ]);
        jr(true, 'Supervisor agregado.');
    }

    if ($accion === 'quitar_supervisor') {
        $id = (int)($b['supervisor_id'] ?? 0);
        $pdo->prepare('UPDATE frente_supervisor SET activo = 0 WHERE id = :id')->execute(['id' => $id]);
        jr(true, 'Supervisor quitado.');
    }

    // ---------- CUADRILLAS ----------
    if ($accion === 'agregar_cuadrilla') {
        $id = (int)($b['frente_id'] ?? 0);
        if ($id <= 0) jr(false, 'Frente no válido.');

        $numero = cuadrillaSiguienteNumero($id);
        $pdo->prepare(
            'INSERT INTO cuadrilla (frente_id, numero, nombre, especialidad)
             VALUES (:f, :n, :nom, :esp)'
        )->execute([
            'f'   => $id, 'n' => $numero,
            'nom' => trim($b['nombre'] ?? '') ?: null,
            'esp' => trim($b['especialidad'] ?? '') ?: null,
        ]);
        recAuditar('cuadrilla_creada', null, null, 'Cuadrilla ' . $numero . ' del frente #' . $id);
        jr(true, 'Cuadrilla creada.', ['numero' => $numero]);
    }

    if ($accion === 'quitar_cuadrilla') {
        $id = (int)($b['cuadrilla_id'] ?? 0);
        if ($id <= 0) jr(false, 'Cuadrilla no válida.');
        $pdo->prepare('DELETE FROM cuadrilla_integrante WHERE cuadrilla_id = :id')->execute(['id' => $id]);
        $pdo->prepare('UPDATE asignacion_frente_obra SET cuadrilla_id = NULL WHERE cuadrilla_id = :id')
            ->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM cuadrilla WHERE id = :id')->execute(['id' => $id]);
        jr(true, 'Cuadrilla eliminada.');
    }

    // ---------- INTEGRANTES ----------
    if ($accion === 'agregar_integrante') {
        $id = (int)($b['cuadrilla_id'] ?? 0);
        $nom = trim($b['nombre'] ?? '');
        if ($id <= 0 || $nom === '') jr(false, 'Indique el nombre.');

        // Si se marca como jefe, se quita el rol al anterior.
        if (!empty($b['es_jefe'])) {
            $pdo->prepare('UPDATE cuadrilla_integrante SET es_jefe = 0 WHERE cuadrilla_id = :c')
                ->execute(['c' => $id]);
        }
        $pdo->prepare(
            'INSERT INTO cuadrilla_integrante (cuadrilla_id, nombre, cedula, telefono, oficio, es_jefe)
             VALUES (:c, :n, :ced, :t, :o, :j)'
        )->execute([
            'c'   => $id, 'n' => $nom,
            'ced' => trim($b['cedula'] ?? '') ?: null,
            't'   => trim($b['telefono'] ?? '') ?: null,
            'o'   => trim($b['oficio'] ?? '') ?: null,
            'j'   => !empty($b['es_jefe']) ? 1 : 0,
        ]);
        jr(true, 'Integrante agregado.');
    }

    if ($accion === 'quitar_integrante') {
        $id = (int)($b['integrante_id'] ?? 0);
        $pdo->prepare('DELETE FROM cuadrilla_integrante WHERE id = :id')->execute(['id' => $id]);
        jr(true, 'Integrante quitado.');
    }

    // ---------- ASIGNAR OBRA ----------
    if ($accion === 'asignar_obra') {
        $insp = (int)($b['inspeccion_id'] ?? 0);
        $fre  = (int)($b['frente_id'] ?? 0);
        if ($insp <= 0) jr(false, 'Edificación no válida.');

        // El frente debe tener a alguien que pueda verla al entrar.
        if ($fre > 0) {
            $stU = $pdo->prepare('SELECT COUNT(*) FROM usuarios
                                   WHERE frente_id = :f AND activo = 1');
            $stU->execute(['f' => $fre]);
            if ((int)$stU->fetchColumn() === 0) {
                $stN = $pdo->prepare('SELECT numero FROM frente WHERE id = :f');
                $stN->execute(['f' => $fre]);
                $num = $stN->fetchColumn() ?: $fre;
                jr(false, 'El Frente de Trabajo ' . $num . ' no tiene ningún usuario '
                        . 'vinculado. Nadie podría ver esta edificación al entrar al '
                        . 'sistema.' . "\n\n" . 'Asigne el frente a un usuario desde '
                        . 'Administración > Usuarios.');
            }
        }
        asignarObraAFrente($insp, $fre, (int)($b['cuadrilla_id'] ?? 0) ?: null);
        jr(true, 'Frente asignado.');
    }

    // ---------- CUADRILLAS EN UNA OBRA ----------
    if ($accion === 'asignar_cuadrilla_obra') {
        $insp = (int)($b['inspeccion_id'] ?? 0);
        $cuad = (int)($b['cuadrilla_id'] ?? 0);
        if ($insp <= 0 || $cuad <= 0) jr(false, 'Datos incompletos.');

        // El usuario de un frente solo puede tocar sus propias obras.
        $miFrente = frenteDelUsuario();
        if ($miFrente > 0) {
            $st = $pdo->prepare('SELECT frente_id FROM asignacion_frente_obra WHERE inspeccion_id = :i');
            $st->execute(['i' => $insp]);
            if ((int)$st->fetchColumn() !== $miFrente) {
                jr(false, 'Esa edificación no pertenece a su frente.');
            }
            $st = $pdo->prepare('SELECT frente_id FROM cuadrilla WHERE id = :c');
            $st->execute(['c' => $cuad]);
            if ((int)$st->fetchColumn() !== $miFrente) {
                jr(false, 'Esa cuadrilla no pertenece a su frente.');
            }
        }

        asignarCuadrillaAObra($insp, $cuad, trim($b['tarea'] ?? '') ?: null);
        jr(true, 'Cuadrilla asignada.');
    }

    if ($accion === 'quitar_cuadrilla_obra') {
        $insp = (int)($b['inspeccion_id'] ?? 0);
        $cuad = (int)($b['cuadrilla_id'] ?? 0);
        if ($insp <= 0 || $cuad <= 0) jr(false, 'Datos incompletos.');

        $miFrente = frenteDelUsuario();
        if ($miFrente > 0) {
            $st = $pdo->prepare('SELECT frente_id FROM asignacion_frente_obra WHERE inspeccion_id = :i');
            $st->execute(['i' => $insp]);
            if ((int)$st->fetchColumn() !== $miFrente) {
                jr(false, 'Esa edificación no pertenece a su frente.');
            }
        }

        quitarCuadrillaDeObra($insp, $cuad);
        jr(true, 'Cuadrilla retirada.');
    }

    // ---------- CONSULTAR ----------
    if ($accion === 'listar') {
        $parr = trim($b['parroquia'] ?? '');
        // Solo los frentes que tienen usuario vinculado: asignar una obra
        // a un frente sin usuario significaría que nadie la vería.
        $soloConUsuario = !empty($b['solo_con_usuario']);
        $lista = $parr !== ''
               ? frentesEnParroquia($parr, $soloConUsuario)
               : frentesNumerados($estado);

        // Cuántos quedaron fuera por no tener usuario, para avisarlo.
        $sinUsuario = 0;
        if ($soloConUsuario && $parr !== '') {
            $sinUsuario = count(frentesEnParroquia($parr, false)) - count($lista);
        }

        jr(true, '', ['frentes' => $lista, 'sin_usuario' => $sinUsuario]);
    }

    jr(false, 'Acción no reconocida.');

} catch (Throwable $e) {
    jr(false, APP_DEBUG ? $e->getMessage() : 'Error al procesar la solicitud.');
}
