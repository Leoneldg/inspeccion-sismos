/**
 * CATÁLOGO PARA TRABAJO SIN SEÑAL
 *
 * Guarda en el teléfono todas las edificaciones, para poder buscarlas
 * y abrir su levantamiento sin conexión.
 *
 * El técnico lo descarga con el botón "Preparar para campo" antes de
 * salir. Después trabaja todo el día offline y al volver la señal la
 * cola de envío sube lo registrado.
 */
'use strict';

window.ObrasCatalogo = (function () {

    const BD      = 'obras-catalogo';
    const VERSION = 1;
    const TIENDA  = 'edificios';
    const META    = 'meta';

    let _db = null;

    /** Abre la base local, creando las tiendas si hace falta. */
    function abrir() {
        if (_db) return Promise.resolve(_db);
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(BD, VERSION);

            req.onupgradeneeded = (e) => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains(TIENDA)) {
                    const t = db.createObjectStore(TIENDA, { keyPath: 'id' });
                    // Índice por parroquia: es como se busca en campo.
                    t.createIndex('parr', 'parr', { unique: false });
                }
                if (!db.objectStoreNames.contains(META)) {
                    db.createObjectStore(META, { keyPath: 'clave' });
                }
            };

            req.onsuccess = () => { _db = req.result; resolve(_db); };
            req.onerror = () => reject(req.error);
        });
    }

    /** Guarda un valor suelto: versión, fecha de descarga, recetas. */
    async function guardarMeta(clave, valor) {
        const db = await abrir();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(META, 'readwrite');
            tx.objectStore(META).put({ clave, valor });
            tx.oncomplete = resolve;
            tx.onerror = () => reject(tx.error);
        });
    }

    async function leerMeta(clave) {
        const db = await abrir();
        return new Promise((resolve) => {
            const tx = db.transaction(META, 'readonly');
            const req = tx.objectStore(META).get(clave);
            req.onsuccess = () => resolve(req.result ? req.result.valor : null);
            req.onerror = () => resolve(null);
        });
    }

    /**
     * Descarga el catálogo completo y lo guarda.
     * onProgreso recibe un texto para mostrar al usuario.
     */
    async function descargar(onProgreso) {
        const aviso = (t) => { if (onProgreso) onProgreso(t); };

        if (!navigator.onLine) {
            throw new Error('Hace falta conexión para preparar el equipo.');
        }

        aviso('Descargando edificaciones…');
        const res = await fetch(URL_CATALOGO, { credentials: 'same-origin' });

        if (res.status === 401) throw new Error('Su sesión expiró. Entre de nuevo.');
        if (!res.ok) throw new Error('No se pudo descargar el catálogo.');

        const d = await res.json();
        if (!d.ok) throw new Error(d.mensaje || 'No se pudo descargar.');

        aviso('Guardando ' + d.total.toLocaleString('es-VE') + ' edificaciones…');

        const db = await abrir();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(TIENDA, 'readwrite');
            const t = tx.objectStore(TIENDA);
            t.clear();
            d.edificios.forEach(e => t.put(e));
            tx.oncomplete = resolve;
            tx.onerror = () => reject(tx.error);
        });

        await guardarMeta('version', d.version);
        await guardarMeta('descargado', new Date().toISOString());
        await guardarMeta('parroquias', d.parroquias);
        await guardarMeta('trabajos', d.trabajos);
        await guardarMeta('recetas', d.recetas);
        await guardarMeta('total', d.total);

        aviso('Listo: ' + d.total.toLocaleString('es-VE') + ' edificaciones guardadas.');
        return d.total;
    }

    /**
     * Busca en el catálogo guardado.
     * Filtra por texto y por parroquia, igual que el buscador en línea.
     */
    async function buscar(texto, parroquia, limite) {
        const db = await abrir();
        const t = (texto || '').toLowerCase().trim();
        const lim = limite || 60;

        return new Promise((resolve) => {
            const tx = db.transaction(TIENDA, 'readonly');
            const store = tx.objectStore(TIENDA);
            const out = [];

            // Si hay parroquia, se usa el índice: mucho más rápido.
            const cursor = parroquia
                ? store.index('parr').openCursor(IDBKeyRange.only(parroquia))
                : store.openCursor();

            cursor.onsuccess = (e) => {
                const c = e.target.result;
                if (!c || out.length >= lim) { resolve(out); return; }

                const ed = c.value;
                if (!t
                    || (ed.nom  || '').toLowerCase().includes(t)
                    || (ed.cod  || '').toLowerCase().includes(t)
                    || (ed.dir  || '').toLowerCase().includes(t)) {
                    out.push(ed);
                }
                c.continue();
            };
            cursor.onerror = () => resolve(out);
        });
    }

    /** Una edificación por su id. */
    async function porId(id) {
        const db = await abrir();
        return new Promise((resolve) => {
            const tx = db.transaction(TIENDA, 'readonly');
            const req = tx.objectStore(TIENDA).get(Number(id));
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => resolve(null);
        });
    }

    /** Estado de lo guardado, para mostrarlo en pantalla. */
    async function estado() {
        try {
            const total = await leerMeta('total');
            const cuando = await leerMeta('descargado');
            return {
                listo:  !!total,
                total:  total || 0,
                cuando: cuando ? new Date(cuando) : null,
                parroquias: (await leerMeta('parroquias')) || [],
            };
        } catch (e) {
            return { listo: false, total: 0, cuando: null, parroquias: [] };
        }
    }

    /** Borra todo lo guardado. */
    async function borrar() {
        const db = await abrir();
        return new Promise((resolve) => {
            const tx = db.transaction([TIENDA, META], 'readwrite');
            tx.objectStore(TIENDA).clear();
            tx.objectStore(META).clear();
            tx.oncomplete = resolve;
            tx.onerror = resolve;
        });
    }

    // La URL la define la página que carga este script.
    const URL_CATALOGO = (window.APP_URL_BASE || '') + 'seguimiento/catalogo_offline.php';

    return { descargar, buscar, porId, estado, borrar, leerMeta };
})();
