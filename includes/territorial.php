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

/**
 * Igual que scopeEstadoSql pero contra una COLUMNA arbitraria (no solo
 * "estado"). Útil para tablas administrativas cuyo campo territorial tiene
 * otro nombre, p. ej. usuarios.estado_asignado o ingenieros.estado.
 *
 *   scopeEstadoColSql('estado_asignado', 'u')
 *     master        → ['', []]
 *     estadal        → ['u.estado_asignado = :scope_estado', [...]]
 */
function scopeEstadoColSql(string $columna, string $alias = ''): array
{
    if (usuarioEsMaster()) {
        return ['', []];
    }
    $estado = estadoDelUsuario();
    if ($estado === null) {
        return ['', []];
    }
    $col = ($alias !== '') ? ($alias . '.' . $columna) : $columna;
    return [$col . ' = :scope_estado', ['scope_estado' => $estado]];
}

/** Aplica scopeEstadoColSql a un arreglo de condiciones. */
function aplicarScopeEstadoCol(array &$conds, array &$params, string $columna, string $alias = ''): void
{
    [$frag, $p] = scopeEstadoColSql($columna, $alias);
    if ($frag !== '') {
        $conds[] = $frag;
        $params = array_merge($params, $p);
    }
}

/** Ente al que pertenece el usuario actual (null si no tiene o es master). */
function enteDelUsuario(): ?int
{
    $e = $_SESSION['ente_id'] ?? null;
    return ($e !== null && $e !== '') ? (int)$e : null;
}
