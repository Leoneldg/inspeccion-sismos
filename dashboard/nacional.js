/**
 * =====================================================================
 * Controlador de navegación NACIONAL del dashboard.
 * =====================================================================
 * Convive con el dashboard existente (dashboard/index.php) sin reescribirlo.
 * Se encarga de:
 *   - Mantener el nivel de navegación (nacional → estado → municipio).
 *   - Cargar el geojson correcto según el nivel (estados del país, o los
 *     municipios/parroquias del estado seleccionado), con carga perezosa por
 *     archivo (portabilidad: nunca carga los 24 estados de golpe).
 *   - Exponer helpers que el index.php usa para dibujar el mapa y para
 *     resolver el nombre de la unidad geográfica de cada polígono.
 *
 * El backend (api_kpis.php) ya devuelve: nivel, estado_filtro,
 * municipio_filtro, unidad_base, es_master, por_estado, secciones_geo.
 */
(function () {
    'use strict';

    const BASE = window.APP_URL_BASE || '/';

    // Estado de navegación nacional (independiente del filtro de parroquia/decisión).
    const NAV = {
        estado: '',      // '' = vista nacional (todos los estados)
        municipio: '',   // '' = nivel estado; con valor = drill-down a parroquias
        esMaster: true,  // lo confirma la primera respuesta de la API
    };

    // Cache de geojson ya cargados (por ruta) para no re-descargar.
    const cacheGeo = {};
    // Índices estado→archivo (se cargan una vez).
    let idxParroquias = null, idxMunicipios = null;

    function slugEstado(nombre) {
        return (nombre || '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-zA-Z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .toLowerCase();
    }

    async function fetchJson(ruta) {
        if (cacheGeo[ruta] !== undefined) return cacheGeo[ruta];
        try {
            const res = await fetch(BASE + 'assets/geo/' + ruta, { cache: 'force-cache' });
            cacheGeo[ruta] = res.ok ? await res.json() : null;
        } catch (e) {
            cacheGeo[ruta] = null;
        }
        return cacheGeo[ruta];
    }

    /**
     * Devuelve el geojson de límites que corresponde al nivel actual:
     *   - nacional            → estados_venezuela.geojson
     *   - estado (no DC)      → municipios/<slug>.geojson
     *   - estado = DC, o dentro de un municipio → parroquias/<slug>.geojson
     */
    async function limitesActuales() {
        if (!NAV.estado) {
            return await fetchJson('estados_venezuela.geojson');
        }
        const slug = slugEstado(NAV.estado);
        const esDC = NAV.estado === 'Distrito Capital';
        if (esDC || NAV.municipio) {
            return await fetchJson('parroquias/' + slug + '.geojson');
        }
        return await fetchJson('municipios/' + slug + '.geojson');
    }

    // Nombre de la unidad (estado/municipio/parroquia) de un feature, según nivel.
    const CLAVES = {
        estado:    ['estado', 'ESTADO', 'NAME_1', 'name'],
        municipio: ['municipio', 'MUNICIPIO', 'NAME_2', 'name'],
        parroquia: ['parroquia', 'PARROQUIA', 'adm3_name', 'NAME_3', 'nombre', 'name'],
    };
    function nombreUnidad(feature, unidadBase) {
        const props = feature.properties || {};
        const claves = CLAVES[unidadBase] || CLAVES.parroquia;
        for (const k of claves) if (props[k]) return props[k];
        // fallback: primera propiedad string
        for (const k in props) if (typeof props[k] === 'string') return props[k];
        return null;
    }

    // Vista inicial del mapa según nivel (centro/zoom).
    const VISTA_PAIS = { center: [8.0, -66.0], zoom: 6 };
    function vistaInicial() {
        return NAV.estado ? null /* se ajusta a bounds del estado */ : VISTA_PAIS;
    }

    window.DashboardNacional = {
        NAV,
        slugEstado,
        limitesActuales,
        nombreUnidad,
        vistaInicial,
        // Sincroniza el estado de navegación con lo que respondió la API.
        sincronizar(data) {
            if (typeof data.es_master !== 'undefined') NAV.esMaster = !!data.es_master;
            // Si el backend forzó un estado (usuario estadal), reflejarlo.
            if (data.estado_filtro && !NAV.esMaster) NAV.estado = data.estado_filtro;
        },
        entrarEstado(estado) { NAV.estado = estado || ''; NAV.municipio = ''; },
        entrarMunicipio(muni) { NAV.municipio = muni || ''; },
        volverNacional() { NAV.estado = ''; NAV.municipio = ''; },
        volverEstado() { NAV.municipio = ''; },
        // Parámetros de territorio para el fetch de la API.
        paramsTerritorio(params) {
            if (NAV.estado) params.set('estado', NAV.estado);
            if (NAV.municipio) params.set('municipio', NAV.municipio);
            return params;
        },
    };
})();
