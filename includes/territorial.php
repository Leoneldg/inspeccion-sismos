<?php
/**
 * =====================================================================
 * Alcance NACIONAL — utilidades territoriales y de scoping por estado.
 * =====================================================================
 * Este archivo centraliza TODA la lógica del alcance nacional para que el
 * resto del sistema cambie lo mínimo posible:
 *
 *   - Catálogo jerárquico Estado → Municipio → Parroquia (Venezuela).
 *   - Correspondencia municipio ⇆ parroquia: en los estados distintos a
 *     Distrito Capital, el MUNICIPIO cumple el mismo papel que la parroquia
 *     en Caracas (unidad geográfica base del mapa/geojson). Ver
 *     unidadBaseDelEstado().
 *   - Scoping: un usuario "estadal" solo ve inspecciones de su estado; el
 *     usuario "master" ve todo el país.
 *
 * Requiere que la sesión ya esté iniciada (config.php) y db.php disponible.
 */

require_once __DIR__ . '/db.php';

/** Carga (cacheada) la jerarquía territorial completa del país. */
function territorio(): array
{
    static $data = null;
    if ($data === null) {
        $data = require __DIR__ . '/territorial_data.php';
    }
    return $data;
}

/** Lista ordenada de los 24 estados de Venezuela. */
function catalogoEstados(): array
{
    return array_keys(territorio());
}

/** Municipios de un estado dado (ordenados). */
function municipiosDeEstado(?string $estado): array
{
    $t = territorio();
    if (!$estado || !isset($t[$estado])) return [];
    return array_keys($t[$estado]);
}

/** Parroquias de un municipio dado dentro de un estado (ordenadas). */
function parroquiasDeMunicipio(?string $estado, ?string $municipio): array
{
    $t = territorio();
    if (!$estado || !$municipio || !isset($t[$estado][$municipio])) return [];
    return $t[$estado][$municipio];
}

/**
 * Unidad geográfica "base" de un estado para el mapa/geojson.
 *
 * En Distrito Capital el sistema histórico dibuja PARROQUIAS (municipio
 * Libertador). En el resto del país la unidad base es el MUNICIPIO, que
 * cumple el mismo rol de "sección geográfica" que la parroquia en Caracas.
 *
 * Devuelve 'parroquia' o 'municipio'.
 */
function unidadBaseDelEstado(?string $estado): string
{
    return ($estado === 'Distrito Capital') ? 'parroquia' : 'municipio';
}

/** Convierte un nombre de estado en el "slug" del archivo geojson correspondiente. */
function slugEstado(string $estado): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $estado);
    $s = preg_replace('/[^a-zA-Z0-9]+/', '_', (string)$s);
    return strtolower(trim($s, '_'));
}

// ---------------------------------------------------------------------
// SCOPING POR ESTADO (master vs. estadal)
// ---------------------------------------------------------------------

/** ¿El usuario actual tiene acceso nacional (master)? */
function usuarioEsMaster(): bool
{
    return !empty($_SESSION['es_master']);
}

/** Estado al que se limita el usuario actual (null si es master o no tiene). */
function estadoDelUsuario(): ?string
{
    if (usuarioEsMaster()) return null;
    $e = $_SESSION['estado_asignado'] ?? null;
    return ($e !== null && $e !== '') ? $e : null;
}

/**
 * Devuelve [sqlFragment, params] para restringir por estado.
 *
 *   - Master           → ['', []]  (sin restricción)
 *   - Estadal con estado→ ['estado = :scope_estado', ['scope_estado' => 'Miranda']]
 *
 * $alias permite prefijar la columna (ej. 'i' → 'i.estado = ...').
 * Si el usuario estadal no tiene estado configurado, se fuerza un filtro
 * imposible (1=0) por seguridad: mejor no mostrar nada que mostrarlo todo.
 */
function scopeEstadoSql(string $alias = ''): array
{
    if (usuarioEsMaster()) {
        return ['', []];
    }
    $estado = estadoDelUsuario();
    $col = ($alias !== '') ? ($alias . '.estado') : 'estado';
    if ($estado === null) {
        // Usuario no-master SIN estado asignado (p. ej. cuentas creadas antes
        // del alcance nacional): no se le aplica restricción territorial, se
        // comporta como antes (ve todo). Así no se "vacían" listados por una
        // configuración incompleta. Para limitarlo a un estado, asígnele uno
        // desde Usuarios.
        return ['', []];
    }
    return [$col . ' = :scope_estado', ['scope_estado' => $estado]];
}

/**
 * Une el fragmento de scope a un arreglo de condiciones existentes.
 * Uso típico:
 *   $conds = [];  $params = [];
 *   aplicarScopeEstado($conds, $params, 'i');
 *   ... agrega más condiciones ...
 *   $where = $conds ? 'WHERE '.implode(' AND ', $conds) : '';
 */
function aplicarScopeEstado(array &$conds, array &$params, string $alias = ''): void
{
    [$frag, $p] = scopeEstadoSql($alias);
    if ($frag !== '') {
        $conds[] = $frag;
        $params = array_merge($params, $p);
    }
}

/**
 * Valida que un usuario estadal solo pueda tocar inspecciones de su estado.
 * Devuelve true si el usuario puede operar sobre la inspección dada.
 */
function puedeAccederEstadoDe(string $estadoInspeccion): bool
{
    if (usuarioEsMaster()) return true;
    return estadoDelUsuario() === $estadoInspeccion;
}

// =====================================================================
// SCOPING POR ENTE (aislamiento de datos entre entes)
//
// Reglas:
//   - Master                 -> ve todo (sin restricción de ente).
//   - Usuario de Gobernación -> ve todos los datos de SU ESTADO
//                               (todos los entes de ese estado).
//   - Usuario de otro ente   -> ve solo los datos de SU ENTE.
//   - Usuario sin ente        -> se comporta como antes (solo scope por estado
//                               si aplica); no se le restringe por ente.
// =====================================================================

/** Ente al que pertenece el usuario actual (null si no tiene o es master sin ente). */
function enteDelUsuario(): ?int
{
    $e = $_SESSION['ente_id'] ?? null;
    return $e ? (int)$e : null;
}

/** ¿El ente del usuario actual es una Gobernación? (ve todo su estado). */
function usuarioEsGobernacion(): bool
{
    return strcasecmp((string)($_SESSION['ente_tipo'] ?? ''), 'Gobernación') === 0
        || strcasecmp((string)($_SESSION['ente_tipo'] ?? ''), 'Gobernacion') === 0;
}

/**
 * Devuelve [sqlFragment, params] para restringir por ENTE, aplicando las
 * reglas de arriba. $enteCol es la columna de ente (ej. 'i.ente_id') y
 * $estadoCol la de estado (ej. 'i.estado'), usada para las gobernaciones.
 * Si el usuario no tiene ente asignado, no restringe por ente.
 */
function scopeEnteSql(string $enteCol = 'ente_id', string $estadoCol = 'estado'): array
{
    if (usuarioEsMaster()) {
        return ['', []];
    }
    $ente = enteDelUsuario();
    if ($ente === null) {
        // Usuario sin ente: no se filtra por ente (mantiene compatibilidad).
        return ['', []];
    }
    // Gobernación: ve todo su estado — es decir, los registros cuyo ENTE
    // pertenece a su mismo estado (no la ubicación geográfica del registro).
    if (usuarioEsGobernacion()) {
        $estado = $_SESSION['ente_estado'] ?? ($_SESSION['estado_asignado'] ?? null);
        if ($estado) {
            // El ente del registro debe estar en el mismo estado que la
            // gobernación. Se resuelve con una subconsulta sobre `entes`.
            $col = $enteCol; // p.ej. 'i.ente_id' o 'ente_id'
            return [
                $col . ' IN (SELECT id FROM entes WHERE estado = :scope_ente_estado)',
                ['scope_ente_estado' => $estado],
            ];
        }
        // Gobernación sin estado definido: cae a su propio ente por seguridad.
    }
    // Ente normal: solo su ente.
    return [$enteCol . ' = :scope_ente_id', ['scope_ente_id' => $ente]];
}

/** Agrega el filtro de ente a un arreglo de condiciones WHERE (igual que aplicarScopeEstado). */
function aplicarScopeEnte(array &$conds, array &$params, string $enteCol = 'ente_id', string $estadoCol = 'estado'): void
{
    [$frag, $p] = scopeEnteSql($enteCol, $estadoCol);
    if ($frag !== '') {
        $conds[] = $frag;
        $params = array_merge($params, $p);
    }
}
