/**
 * Modo offline para el formulario de inspección.
 *
 * Cuando no hay conexión (o el envío falla por red), el formulario completo
 * -incluyendo fotos- se guarda en IndexedDB en vez de perderse. Apenas el
 * navegador detecta que volvió la señal, se reintenta subir todo lo
 * pendiente automáticamente, en orden, uno por uno.
 */
(function () {
    const DB_NAME = 'sismos_offline';
    const DB_VERSION = 1;
    const STORE = 'pendientes';

    function abrirDB() {
        return new Promise((resolve, reject) => {
            if (!window.indexedDB) {
                reject(new Error('IndexedDB no disponible en este navegador'));
                return;
            }
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = () => {
                const db = req.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    /** Convierte un FormData (con archivos incluidos) en algo serializable en IndexedDB. */
    async function guardarPendiente(url, formData, meta) {
        const campos = [];
        for (const [key, value] of formData.entries()) {
            if (value instanceof File) {
                if (value.size > 0) {
                    campos.push({ key, isFile: true, name: value.name, type: value.type, blob: value });
                }
            } else {
                campos.push({ key, isFile: false, value });
            }
        }
        const db = await abrirDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).add({
                url,
                campos,
                meta: meta || {},
                creado: Date.now(),
            });
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    }

    async function listarPendientes() {
        const db = await abrirDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readonly');
            const req = tx.objectStore(STORE).getAll();
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    async function eliminarPendiente(id) {
        const db = await abrirDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).delete(id);
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    }

    /** Actualiza un registro pendiente existente (p. ej. para sumarle un intento fallido). */
    async function actualizarPendiente(registro) {
        const db = await abrirDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).put(registro);
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    }

    /**
     * Aviso flotante (toast) en pantalla, para que la persona SEPA que algo
     * pasó -- antes, subir o fallar en segundo plano solo cambiaba un
     * numerito en la esquina que nadie mira. Se apila si hay varios.
     */
    function mostrarToast(mensaje, tipo) {
        let cont = document.getElementById('sismos-toast-cont');
        if (!cont) {
            cont = document.createElement('div');
            cont.id = 'sismos-toast-cont';
            document.body.appendChild(cont);
        }
        const el = document.createElement('div');
        el.className = 'sismos-toast sismos-toast-' + (tipo || 'info');
        el.innerHTML = mensaje;
        cont.appendChild(el);
        requestAnimationFrame(() => el.classList.add('mostrar'));
        setTimeout(() => {
            el.classList.remove('mostrar');
            setTimeout(() => el.remove(), 400);
        }, tipo === 'error' ? 9000 : 5500);
    }
    window.SismosToast = mostrarToast; // reutilizable desde otras páginas si hace falta

    const MAX_INTENTOS_SYNC = 3; // tras 3 fallos queda en error manual — más manejable

    function reconstruirFormData(campos) {
        const fd = new FormData();
        for (const c of campos) {
            if (c.isFile) {
                fd.append(c.key, new File([c.blob], c.name, { type: c.type }));
            } else {
                fd.append(c.key, c.value);
            }
        }
        return fd;
    }

    async function actualizarBadge() {
        let pendientes = [];
        try {
            pendientes = await listarPendientes();
        } catch (e) {
            return; // IndexedDB no disponible: no mostramos el badge, no rompemos nada
        }
        const fallidos = pendientes.filter((p) => (p.intentos || 0) >= MAX_INTENTOS_SYNC);
        document.querySelectorAll('[data-pendientes-offline]').forEach(function (el) {
            el.textContent = pendientes.length;
        });
        document.querySelectorAll('[data-pendientes-offline-wrap]').forEach(function (el) {
            el.classList.toggle('oculto-offline', pendientes.length === 0);
            el.classList.toggle('pendientes-offline-con-error', fallidos.length > 0);
        });
        document.querySelectorAll('[data-pendientes-offline-error]').forEach(function (el) {
            el.classList.toggle('oculto-offline', fallidos.length === 0);
        });
        document.querySelectorAll('[data-pendientes-offline-error-count]').forEach(function (el) {
            el.textContent = fallidos.length;
        });
    }

    /**
     * Determina si la respuesta del servidor significa que el envío se
     * guardó de verdad. save.php redirige a formulario/view.php?id=... solo
     * cuando todo salió bien; ante cualquier error (validación, CSRF/sesión
     * expirada, permisos) redirige a create.php o a login.php con un
     * mensaje. Antes, cualquier respuesta (incluida esa redirección de
     * error) se tomaba como "ya se procesó" y se borraba de la cola sin
     * avisar a nadie — así se perdían inspecciones/fotos en silencio.
     */
    // ── Detección de éxito: acepta tanto redirección a view.php (legacy)
    //    como respuesta JSON { ok: true } (nuevo, para sync offline)
    function envioFueExitoso(resp, jsonData) {
        if (jsonData && typeof jsonData === 'object') {
            return jsonData.ok === true;
        }
        return resp.ok && /\/formulario\/view\.php(\?|$)/.test(resp.url);
    }

    let sincronizando = false;
    let sincronizandoDesde = 0;

    async function sincronizarPendientes() {
        // Prevenir que quede atascado: si lleva más de 90s en "sincronizando", resetear.
        if (sincronizando && (Date.now() - sincronizandoDesde) < 90000) return;
        if (!navigator.onLine) return;
        sincronizando = true;
        sincronizandoDesde = Date.now();
        try {
            const pendientes = await listarPendientes();
            const porSubir = pendientes.filter((p) => (p.intentos || 0) < MAX_INTENTOS_SYNC);
            if (porSubir.length > 0) {
                mostrarToast(
                    '<i class="bi bi-cloud-arrow-up"></i> Subiendo ' + porSubir.length
                    + ' inspección' + (porSubir.length === 1 ? '' : 'es') + ' pendiente…',
                    'info'
                );
            }
            for (const p of pendientes) {
                if ((p.intentos || 0) >= MAX_INTENTOS_SYNC) continue;
                const nom = (p.meta && p.meta.nombre_edificio) ? ('"'  + p.meta.nombre_edificio + '"') : 'sin nombre';
                try {
                    p.subiendo = true;
                    await actualizarPendiente(p);
                    await actualizarBadge();

                    // ── Obtener CSRF token fresco del servidor ────────────────
                    // El token guardado en IndexedDB puede ser de horas o días
                    // atrás, o de otra sesión. Pedimos uno nuevo antes de enviar.
                    let csrfFresco = null;
                    try {
                        const csrfResp = await fetch(
                            (window._APP_URL_BASE || '/') + 'api/csrf_token.php',
                            { credentials: 'same-origin', cache: 'no-store' }
                        );
                        if (csrfResp.status === 401) {
                            p.subiendo = false;
                            p.intentos = MAX_INTENTOS_SYNC; // no reintentar automáticamente
                            p.ultimoError = 'Sesión cerrada. Inicie sesión y reintente manualmente.';
                            await actualizarPendiente(p);
                            mostrarToast('<i class="bi bi-exclamation-triangle-fill"></i> Sesión cerrada. Inicie sesión para reenviar las inspecciones pendientes.', 'error');
                            break; // parar toda la sincronización
                        }
                        const csrfData = await csrfResp.json();
                        if (csrfData.ok) csrfFresco = csrfData.token;
                    } catch (e) {
                        // Sin acceso al endpoint de CSRF = sin red real
                        p.subiendo = false;
                        break;
                    }

                    // Reconstruir FormData con el CSRF fresco
                    const fd = reconstruirFormData(p.campos);
                    if (csrfFresco) {
                        fd.set('csrf', csrfFresco); // sobreescribir el token viejo
                    }
                    // Añadir marcador para que save.php responda JSON
                    fd.set('_offline_sync', '1');

                    const ctrl = new AbortController();
                    const tid  = setTimeout(() => ctrl.abort(), 60000); // 60s máximo
                    let resp, jsonData = null;
                    try {
                        resp = await fetch(p.url, {
                            method: 'POST',
                            body: fd,
                            credentials: 'same-origin',
                            signal: ctrl.signal,
                            headers: { 'X-Offline-Sync': '1', 'X-Requested-With': 'fetch' },
                        });
                        // Intentar leer JSON (nuevo) o dejar jsonData en null (legacy)
                        // save.php ahora siempre responde JSON — leerlo y parsearlo.
                        const ct = resp.headers.get('content-type') || '';
                        if (ct.includes('application/json')) {
                            try { jsonData = await resp.json(); } catch(e) {}
                        }
                        // Fallback por si el Content-Type no llega bien
                        if (!jsonData) {
                            try { const t = await resp.clone().text(); if (t.trim().startsWith('{')) jsonData = JSON.parse(t); } catch(e) {}
                        }
                    } finally { clearTimeout(tid); }

                    p.subiendo = false;

                    if (envioFueExitoso(resp, jsonData)) {
                        await eliminarPendiente(p.id);
                        mostrarToast('<i class="bi bi-check-circle-fill"></i> Inspección ' + nom + ' subida correctamente.', 'success');
                        continue;
                    }

                    // No fue exitoso — guardar error legible
                    p.intentos = (p.intentos || 0) + 1;
                    // save.php devuelve { ok, url, error } — compatibilidad con campo 'mensaje' anterior
                    if (jsonData && (jsonData.error || jsonData.mensaje)) {
                        p.ultimoError = jsonData.error || jsonData.mensaje;
                    } else if (resp && /\/login\.php(\?|$)/.test(resp.url)) {
                        p.ultimoError = 'Sesión cerrada. Inicie sesión y reintente.';
                    } else if (resp && resp.status === 403) {
                        p.ultimoError = 'Error de seguridad (token vencido). Abra el formulario y reintente.';
                    } else if (resp && !resp.ok) {
                        p.ultimoError = 'Error ' + resp.status + ' del servidor.';
                    } else {
                        p.ultimoError = 'Datos incompletos o rechazados por el servidor.';
                    }
                    await actualizarPendiente(p);
                    if (p.intentos >= MAX_INTENTOS_SYNC) {
                        mostrarToast('<i class="bi bi-exclamation-triangle-fill"></i> ' + nom + ': ' + p.ultimoError, 'error');
                    }
                } catch (err) {
                    p.subiendo = false;
                    if (err.name === 'AbortError') {
                        p.intentos = (p.intentos || 0) + 1;
                        p.ultimoError = 'Tiempo agotado (60 s). Verifique la señal y reintente.';
                        await actualizarPendiente(p);
                        mostrarToast('<i class="bi bi-exclamation-triangle-fill"></i> ' + nom + ': tiempo agotado.', 'error');
                    }
                    break; // falla de red — esperar próxima reconexión
                }
            }
        } finally {
            sincronizando = false;
            actualizarBadge();
        }
    }

    function actualizarEstadoConexion() {
        document.body.classList.toggle('sin-conexion', !navigator.onLine);
    }

    /** Reinicia el contador de TODOS los pendientes atascados (cualquier cantidad de intentos) y reintenta. */
    async function reintentarFallidos() {
        const pendientes = await listarPendientes();
        for (const p of pendientes) {
            // Resetear cualquiera que tenga intentos > 0, no solo los que llegaron al límite
            if ((p.intentos || 0) > 0) {
                p.intentos   = 0;
                p.ultimoError = null;
                p.subiendo    = false;
                await actualizarPendiente(p);
            }
        }
        await sincronizarPendientes();
    }

    window.SismosOffline = {
        guardarPendiente,
        listarPendientes,
        eliminarPendiente,
        actualizarPendiente,
        sincronizarPendientes,
        actualizarBadge,
        reintentarFallidos,
        MAX_INTENTOS_SYNC,
        /** Reinicia intentos de UN pendiente y lo reenvía ahora. */
        reintentarUno: async function (id) {
            const pendientes = await listarPendientes();
            const p = pendientes.find((x) => x.id === id);
            if (!p) throw new Error('No encontrado');
            p.intentos   = 0;
            p.ultimoError = null;
            p.subiendo    = false;
            await actualizarPendiente(p);
            await sincronizarPendientes();
            await actualizarBadge();
        },
    };

    window.addEventListener('online', function () {
        actualizarEstadoConexion();
        sincronizarPendientes();
    });
    window.addEventListener('offline', actualizarEstadoConexion);

    // Respaldo: el evento 'online' del navegador no siempre dispara de
    // forma confiable en celulares (sobre todo con señal intermitente,
    // 2G/3G entrando y saliendo). Cada 45s, si el navegador dice que hay
    // señal, se intenta sincronizar de nuevo -- sincronizarPendientes() ya
    // se sale de inmediato si no hay nada pendiente, así que no hace daño
    // dejarlo corriendo de fondo.
    setInterval(function () {
        if (navigator.onLine) sincronizarPendientes();
    }, 45000);

    document.addEventListener('DOMContentLoaded', function () {
        actualizarEstadoConexion();
        actualizarBadge();
        sincronizarPendientes();
    });
})();
