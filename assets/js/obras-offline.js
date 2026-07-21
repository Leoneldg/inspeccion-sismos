/**
 * MODO OFFLINE del módulo de Seguimiento.
 *
 * Permite al sistematizador trabajar sin señal:
 *   · Descarga la ficha completa de una edificación (pisos, apartamentos,
 *     ambientes y fotos del "antes") y la guarda en el teléfono.
 *   · Si registra avance o toma fotos sin conexión, todo queda en cola.
 *   · Al volver la señal, sube lo pendiente solo, en orden.
 *
 * Guarda en IndexedDB (base propia, separada del formulario).
 */
(function () {
    'use strict';

    const DB_NAME = 'obras_offline';
    const DB_VERSION = 1;
    const ST_FICHAS = 'fichas';      // fichas descargadas para trabajar
    const ST_COLA   = 'cola';        // cambios pendientes de subir

    // ---------------------------------------------------------------
    // Base de datos local
    // ---------------------------------------------------------------
    function abrirDB() {
        return new Promise((resolve, reject) => {
            if (!window.indexedDB) { reject(new Error('Este navegador no permite trabajar sin señal.')); return; }
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = () => {
                const db = req.result;
                if (!db.objectStoreNames.contains(ST_FICHAS)) {
                    db.createObjectStore(ST_FICHAS, { keyPath: 'inspeccion_id' });
                }
                if (!db.objectStoreNames.contains(ST_COLA)) {
                    db.createObjectStore(ST_COLA, { keyPath: 'id', autoIncrement: true });
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    function tx(store, modo, fn) {
        return abrirDB().then(db => new Promise((resolve, reject) => {
            const t = db.transaction(store, modo);
            const s = t.objectStore(store);
            const r = fn(s);
            t.oncomplete = () => resolve(r && r.result !== undefined ? r.result : r);
            t.onerror = () => reject(t.error);
        }));
    }

    // ---------------------------------------------------------------
    // Fichas descargadas
    // ---------------------------------------------------------------
    async function guardarFicha(paquete) {
        paquete.guardado_en = new Date().toISOString();
        return tx(ST_FICHAS, 'readwrite', s => s.put(paquete));
    }

    async function obtenerFicha(inspeccionId) {
        return tx(ST_FICHAS, 'readonly', s => s.get(Number(inspeccionId)));
    }

    async function listarFichas() {
        return tx(ST_FICHAS, 'readonly', s => s.getAll());
    }

    async function borrarFicha(inspeccionId) {
        return tx(ST_FICHAS, 'readwrite', s => s.delete(Number(inspeccionId)));
    }

    /**
     * Descarga una edificación completa para trabajarla sin señal.
     * También pide al navegador que guarde las fotos del "antes".
     */
    async function descargarFicha(inspeccionId, onProgreso) {
        const base = window._APP_URL_BASE || '/';
        if (onProgreso) onProgreso('Descargando la ficha…');

        const res = await fetch(base + 'seguimiento/paquete_offline.php?inspeccion=' + inspeccionId);
        const d = await res.json();
        if (!d.ok) throw new Error(d.mensaje || 'No se pudo descargar.');

        await guardarFicha(d);

        // Guardar las fotos en el caché del navegador, para verlas sin señal.
        const fotos = d.fotos || [];
        if (window.caches && fotos.length) {
            try {
                const cache = await caches.open('obras-fotos');
                let n = 0;
                for (const url of fotos) {
                    try { await cache.add(url); } catch (e) { /* una foto que falle no detiene el resto */ }
                    n++;
                    if (onProgreso) onProgreso('Guardando fotos… ' + n + ' de ' + fotos.length);
                }
            } catch (e) { /* sin caché de fotos igual se puede trabajar */ }
        }
        return d;
    }

    // ---------------------------------------------------------------
    // Cola de cambios pendientes
    // ---------------------------------------------------------------
    async function encolar(tipo, url, datos, descripcion) {
        const item = {
            usuario: window._USER_ID || 0,   // cada quien sube lo suyo
            tipo: tipo,                 // 'avance' | 'foto'
            url: url,
            datos: datos,
            descripcion: descripcion || '',
            creado_en: new Date().toISOString(),
            intentos: 0,
            ultimoError: null,
        };
        await tx(ST_COLA, 'readwrite', s => s.add(item));
        await actualizarAviso();
        return item;
    }

    async function listarCola(todos) {
        const cola = await tx(ST_COLA, 'readonly', s => s.getAll());
        if (todos) return cola;
        // Solo lo del usuario actual: si comparten el teléfono, cada quien
        // sube lo suyo y no se mezclan los registros.
        const uid = window._USER_ID || 0;
        return (cola || []).filter(it => !it.usuario || it.usuario === uid);
    }

    /** Cuántos pendientes hay de OTROS usuarios en este teléfono. */
    async function pendientesDeOtros() {
        const todos = await listarCola(true);
        const uid = window._USER_ID || 0;
        return (todos || []).filter(it => it.usuario && it.usuario !== uid).length;
    }

    async function borrarDeCola(id) {
        await tx(ST_COLA, 'readwrite', s => s.delete(id));
        await actualizarAviso();
    }

    async function actualizarEnCola(item) {
        return tx(ST_COLA, 'readwrite', s => s.put(item));
    }

    // ---------------------------------------------------------------
    // Sincronización
    // ---------------------------------------------------------------
    let _sincronizando = false;
    let _sincronizandoDesde = 0;

    /**
     * fetch con límite de tiempo. Sin esto, con señal débil la petición
     * queda colgada para siempre y la subida "nunca termina".
     */
    async function fetchConTiempo(url, opciones, ms) {
        const ctrl = new AbortController();
        const t = setTimeout(() => ctrl.abort(), ms || 45000);
        try {
            return await fetch(url, Object.assign({}, opciones, { signal: ctrl.signal }));
        } finally {
            clearTimeout(t);
        }
    }

    /**
     * ¿Hay internet DE VERDAD? navigator.onLine solo indica que hay wifi o
     * datos conectados: con una barra de señal dice "sí" y no sale nada.
     * Se comprueba pidiendo un archivo pequeño al propio servidor.
     */
    async function hayInternetReal() {
        if (!navigator.onLine) return false;
        const base = window._APP_URL_BASE || '/';
        try {
            const res = await fetchConTiempo(base + 'api/ping.php?t=' + Date.now(),
                { method: 'GET', cache: 'no-store', credentials: 'same-origin' }, 8000);
            return res.ok;
        } catch (e) {
            return false;
        }
    }

    /**
     * Lee la respuesta con cuidado: el servidor puede devolver HTML
     * (sesión expirada, error 500, portal de wifi público) y eso reventaba
     * la sincronización dejando todo trabado.
     */
    async function leerRespuesta(res) {
        const texto = await res.text();
        try {
            return { json: JSON.parse(texto), texto: texto };
        } catch (e) {
            return { json: null, texto: texto };
        }
    }

    /** Clasifica el fallo para saber si tiene sentido reintentar. */
    function clasificarFallo(res, r) {
        if (res.status === 401 || (r.json && r.json.sesion_expirada)) {
            return { motivo: 'Su sesión expiró. Inicie sesión de nuevo.', reintentar: true, pausar: true };
        }
        if (res.status === 403) {
            return { motivo: 'Sin permiso para esta acción.', reintentar: false };
        }
        if (res.status === 413) {
            return { motivo: 'La foto es muy pesada para el servidor.', reintentar: false };
        }
        if (!r.json) {
            // Respondió algo que no es JSON: casi siempre es la pantalla de
            // login o un portal de wifi. No sirve reintentar en bucle.
            const pareceLogin = /login|iniciar sesi/i.test(r.texto || '');
            return {
                motivo: pareceLogin ? 'Debe iniciar sesión otra vez.' : 'El servidor respondió algo inesperado.',
                reintentar: true, pausar: pareceLogin,
            };
        }
        return { motivo: (r.json.mensaje || 'El servidor rechazó el envío.'), reintentar: false };
    }

    async function sincronizar(onProgreso) {
        // Si quedó trabado más de 2 minutos, se libera solo.
        if (_sincronizando && (Date.now() - _sincronizandoDesde) < 120000) {
            return { subidos: 0, fallidos: 0, yaCorriendo: true };
        }
        if (!navigator.onLine) return { subidos: 0, fallidos: 0, sinSenal: true };

        // Comprobar que la conexión sirve de verdad antes de intentar subir.
        if (onProgreso) onProgreso('Verificando la conexión…');
        if (!(await hayInternetReal())) {
            return { subidos: 0, fallidos: 0, sinSenal: true, senalDebil: true };
        }

        _sincronizando = true;
        _sincronizandoDesde = Date.now();
        let subidos = 0, fallidos = 0, descartados = 0, pausado = false;

        try {
            const cola = await listarCola();
            // Orden importante: primero los datos (que crean los ambientes
            // en el servidor) y al final las fotos que dependen de ellos.
            const prioridad = t => (t === 'foto_ambiente_pendiente' ? 2 : (t === 'foto' ? 1 : 0));
            cola.sort((a, b) => {
                const d = prioridad(a.tipo) - prioridad(b.tipo);
                return d !== 0 ? d : (a.id || 0) - (b.id || 0);
            });
            const total = cola.length;
            let i = 0;

            for (const item of cola) {
                i++;
                if (onProgreso) onProgreso('Subiendo ' + i + ' de ' + total + '…');
                try {
                    let res;
                    if (item.tipo === 'foto_ambiente_pendiente') {
                        // Foto de un ambiente creado sin conexión: va a un
                        // endpoint que lo busca por apartamento y etiqueta.
                        const fd = new FormData();
                        fd.append('apartamento_id', item.datos.apartamento_id);
                        fd.append('etiqueta', item.datos.etiqueta);
                        fd.append('parte', item.datos.parte || 'antes');
                        if (item.datos.foto) {
                            fd.append('foto', item.datos.foto, item.datos.nombre_archivo || 'foto.jpg');
                        }
                        res = await fetchConTiempo(
                            (window._APP_URL_BASE || '/') + 'seguimiento/subir_foto_ambiente_offline.php',
                            { method: 'POST', body: fd, credentials: 'same-origin' }, 90000);
                    } else if (item.tipo === 'foto') {
                        const fd = new FormData();
                        Object.keys(item.datos).forEach(k => {
                            if (k !== 'foto') fd.append(k, item.datos[k]);
                        });
                        if (item.datos.foto) {
                            fd.append('foto', item.datos.foto, item.datos.nombre_archivo || 'foto.jpg');
                        }
                        // Las fotos pesan: se les da más tiempo.
                        res = await fetchConTiempo(item.url,
                            { method: 'POST', body: fd, credentials: 'same-origin' }, 90000);
                    } else {
                        res = await fetchConTiempo(item.url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(item.datos),
                            credentials: 'same-origin',
                        }, 30000);
                    }

                    const r = await leerRespuesta(res);

                    if (res.ok && r.json && r.json.ok) {
                        await borrarDeCola(item.id);
                        subidos++;
                        continue;
                    }

                    // Caso especial: la foto llegó antes que su ambiente.
                    // Se deja en cola sin marcarla como error definitivo.
                    if (r.json && r.json.reintentar) {
                        item.intentos = (item.intentos || 0) + 1;
                        item.ultimoError = 'Esperando que se cree el ambiente';
                        item.rechazado = false;
                        await actualizarEnCola(item);
                        fallidos++;
                        continue;
                    }

                    const fallo = clasificarFallo(res, r);
                    item.intentos = (item.intentos || 0) + 1;
                    item.ultimoError = fallo.motivo;
                    item.rechazado = !fallo.reintentar;   // el servidor lo rechazó de plano
                    await actualizarEnCola(item);
                    fallidos++;

                    if (fallo.pausar) {
                        // Sesión caída: no tiene sentido seguir intentando ahora.
                        pausado = true;
                        break;
                    }
                } catch (e) {
                    // Se cortó la señal o tardó demasiado: se detiene y
                    // lo demás queda para el próximo intento.
                    item.intentos = (item.intentos || 0) + 1;
                    item.ultimoError = (e && e.name === 'AbortError')
                        ? 'La señal es muy lenta, no se completó el envío'
                        : 'Sin conexión';
                    await actualizarEnCola(item);
                    fallidos++;
                    break;
                }
            }
        } finally {
            _sincronizando = false;
            await actualizarAviso();
        }
        return { subidos, fallidos, descartados, pausado };
    }

    // ---------------------------------------------------------------
    // Aviso visible del estado
    // ---------------------------------------------------------------
    async function actualizarAviso() {
        let cola = [];
        try { cola = await listarCola(); } catch (e) { return; }
        const n = cola.length;
        let barra = document.getElementById('offline-aviso');

        if (!n && navigator.onLine) { if (barra) barra.remove(); return; }

        if (!barra) {
            barra = document.createElement('div');
            barra.id = 'offline-aviso';
            barra.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:1500;'
                + 'padding:10px 14px;font-size:13.5px;font-weight:600;text-align:center;'
                + 'box-shadow:0 -2px 12px rgba(20,25,40,.18);';
            document.body.appendChild(barra);
        }

        if (!navigator.onLine) {
            barra.style.background = '#C9A227';
            barra.style.color = '#2a2416';
            barra.innerHTML = '<i class="bi bi-wifi-off"></i> Sin señal · Trabajando en el teléfono'
                + (n ? ' · <strong>' + n + '</strong> por subir'
                     + ' <button onclick="ObrasOffline.verPendientes()" style="margin-left:8px;background:#2a2416;'
                     + 'color:#fff;border:0;border-radius:6px;padding:3px 10px;font-size:12px;cursor:pointer;">Ver</button>' : '');
        } else if (n) {
            barra.style.background = '#22366F';
            barra.style.color = '#fff';
            barra.innerHTML = '<i class="bi bi-cloud-arrow-up-fill"></i> <strong>' + n + '</strong> cambio(s) por subir'
                + ' <button onclick="ObrasOffline.sincronizarAhora()" style="margin-left:8px;background:#fff;'
                + 'color:#22366F;border:0;border-radius:6px;padding:4px 12px;font-weight:700;cursor:pointer;">'
                + 'Subir ahora</button>'
                + ' <button onclick="ObrasOffline.verPendientes()" style="margin-left:6px;background:transparent;'
                + 'color:#fff;border:1px solid #ffffff66;border-radius:6px;padding:4px 10px;cursor:pointer;">Ver detalle</button>';
        }
    }

    /**
     * Muestra la lista de cambios pendientes con su estado.
     * Antes los fallos quedaban invisibles y parecía que "no se subió nada".
     */
    async function verPendientes() {
        const cola = await listarCola();
        let capa = document.getElementById('offline-panel');
        if (capa) capa.remove();

        capa = document.createElement('div');
        capa.id = 'offline-panel';
        capa.style.cssText = 'position:fixed;inset:0;background:rgba(20,25,40,.55);z-index:1600;'
            + 'display:flex;align-items:center;justify-content:center;padding:14px;';

        const otros = await pendientesDeOtros();
        let filas = '';
        if (otros) {
            filas += '<div style="background:#fffbf0;border:1px solid #C9A22755;border-radius:8px;'
                + 'padding:10px 12px;margin-bottom:12px;font-size:12.5px;color:#8a6d1a;">'
                + '<i class="bi bi-people-fill"></i> Hay <strong>' + otros + '</strong> cambio(s) de otro usuario '
                + 'en este teléfono. Esa persona debe iniciar sesión para subirlos.</div>';
        }
        if (!cola.length) {
            filas += '<p style="color:#5b6478;margin:0;">No hay nada pendiente suyo. Todo está subido.</p>';
        } else {
            filas = cola.map(it => {
                const rechazado = it.rechazado;
                const color = rechazado ? '#A61C1C' : (it.ultimoError ? '#a8871f' : '#5b6478');
                const estado = rechazado
                    ? '<strong style="color:#A61C1C;">No se pudo subir</strong>'
                    : (it.ultimoError ? '<span style="color:#a8871f;">Reintentando</span>'
                                      : '<span style="color:#5b6478;">En espera</span>');
                const icono = it.tipo === 'foto' ? 'bi-camera-fill' : 'bi-percent';
                const detalle = it.ultimoError
                    ? '<div style="font-size:11.5px;color:' + color + ';margin-top:2px;">' + it.ultimoError + '</div>' : '';
                const btn = rechazado
                    ? '<button onclick="ObrasOffline.descartar(' + it.id + ')" style="background:#fff;border:1px solid #A61C1C55;'
                      + 'color:#A61C1C;border-radius:6px;padding:3px 9px;font-size:11.5px;cursor:pointer;">Descartar</button>'
                    : '';
                return '<div style="display:flex;gap:10px;align-items:flex-start;padding:9px 4px;border-bottom:1px solid #f0f2f7;">'
                    + '<i class="bi ' + icono + '" style="color:#2d4488;margin-top:2px;"></i>'
                    + '<div style="flex:1;min-width:0;">'
                    + '<div style="font-size:13px;font-weight:600;color:#2a3140;">' + (it.descripcion || 'Cambio') + '</div>'
                    + '<div style="font-size:11.5px;color:#767c94;">' + estado
                    + ' · intento ' + (it.intentos || 0) + '</div>' + detalle + '</div>' + btn + '</div>';
            }).join('');
        }

        capa.innerHTML = '<div style="background:#fff;border-radius:12px;max-width:520px;width:100%;max-height:86vh;overflow-y:auto;">'
            + '<div style="background:#22366F;color:#fff;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;">'
            + '<b>Cambios pendientes de subir</b>'
            + '<button onclick="document.getElementById(\'offline-panel\').remove()" '
            + 'style="background:transparent;border:0;color:#fff;font-size:20px;cursor:pointer;line-height:1;">&times;</button></div>'
            + '<div style="padding:14px 18px;">' + filas + '</div>'
            + (cola.length ? '<div style="padding:0 18px 16px;"><button onclick="ObrasOffline.sincronizarAhora()" '
              + 'style="width:100%;background:#22366F;color:#fff;border:0;border-radius:8px;padding:11px;'
              + 'font-weight:700;font-size:14px;cursor:pointer;">Intentar subir todo ahora</button></div>' : '')
            + '</div>';
        document.body.appendChild(capa);
    }

    /** Descarta un pendiente que el servidor rechazó y no tiene arreglo. */
    async function descartar(id) {
        if (!confirm('Este cambio no se pudo subir y se perderá.\n\n¿Descartarlo?')) return;
        await borrarDeCola(id);
        await verPendientes();
    }

    async function sincronizarAhora() {
        const barra = document.getElementById('offline-aviso');
        const r = await sincronizar(txt => {
            if (barra) barra.innerHTML = '<i class="bi bi-arrow-repeat"></i> ' + txt;
        });
        if (r.senalDebil) {
            alert('Hay señal pero no llega al servidor.\n\nBúsquese un lugar con mejor cobertura e intente otra vez. Nada se ha perdido.');
        } else if (r.sinSenal) {
            alert('Todavía no hay señal.\n\nLos cambios siguen guardados en el teléfono y subirán solos al recuperar la conexión.');
        } else if (r.pausado) {
            alert('Su sesión expiró.\n\nInicie sesión de nuevo y vuelva a intentar. Nada se perdió.');
        } else if (r.fallidos > 0 && r.subidos > 0) {
            alert('Se subieron ' + r.subidos + ' cambio(s).\n\nQuedaron ' + r.fallidos
                + ' pendientes. Toque "Ver detalle" para saber por qué.');
            verPendientes();
        } else if (r.fallidos > 0) {
            alert('No se pudo subir nada.\n\nToque "Ver detalle" para saber el motivo.');
            verPendientes();
        } else if (r.subidos > 0) {
            alert('Listo: se subieron ' + r.subidos + ' cambio(s).');
            if (typeof cargarFicha === 'function') cargarFicha();
        }
        await actualizarAviso();
    }

    // ---------------------------------------------------------------
    // API pública
    // ---------------------------------------------------------------
    window.ObrasOffline = {
        descargarFicha, obtenerFicha, listarFichas, borrarFicha,
        encolar, listarCola, borrarDeCola,
        sincronizar, sincronizarAhora, actualizarAviso,
        verPendientes, descartar, pendientesDeOtros,
        hayConexion: () => navigator.onLine,
        hayInternetReal,
    };

    // Al volver la señal, subir lo pendiente automáticamente.
    window.addEventListener('online', async () => {
        await actualizarAviso();
        const cola = await listarCola();
        if (cola.length) sincronizar();
    });
    window.addEventListener('offline', actualizarAviso);

    /**
     * Al abrir la página se revisa la cola: si quedaron datos sin
     * enviar de una sesión anterior, se suben ahora.
     *
     * Antes solo se sincronizaba al cambiar la conexión, así que un
     * técnico que cerraba la app sin señal y la reabría con señal
     * seguía teniendo todo pendiente.
     */
    async function arrancar() {
        try {
            await actualizarAviso();
            if (navigator.onLine) {
                const cola = await listarCola();
                if (cola.length) sincronizar();
            }
        } catch (e) { /* no interrumpir la carga */ }
    }

    // Se llama directo: si el script va al final, DOMContentLoaded
    // ya pasó y el evento nunca se dispararía.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', arrancar);
    } else {
        arrancar();
    }

    // Reintento periódico: si la señal es intermitente, el evento
    // 'online' puede no llegar nunca.
    setInterval(async () => {
        if (!navigator.onLine) return;
        try {
            const cola = await listarCola();
            if (cola.length) sincronizar();
        } catch (e) {}
    }, 60000);   // cada minuto
})();
