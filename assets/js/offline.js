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

    const MAX_INTENTOS_SYNC = 8;

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
    function envioFueExitoso(resp) {
        return resp.ok && /\/formulario\/view\.php(\?|$)/.test(resp.url);
    }

    let sincronizando = false;
    async function sincronizarPendientes() {
        if (sincronizando || !navigator.onLine) return;
        sincronizando = true;
        try {
            const pendientes = await listarPendientes();
            for (const p of pendientes) {
                if ((p.intentos || 0) >= MAX_INTENTOS_SYNC) {
                    continue; // agotó reintentos: queda visible en el badge de error, no se reintenta solo
                }
                try {
                    const fd = reconstruirFormData(p.campos);
                    const resp = await fetch(p.url, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                    });

                    if (envioFueExitoso(resp)) {
                        await eliminarPendiente(p.id);
                        continue;
                    }

                    // El servidor respondió pero no fue un guardado exitoso
                    // (sesión/CSRF expirado, datos inválidos, sin permisos,
                    // etc.). No lo borramos: sumamos un intento y seguimos
                    // con el siguiente pendiente. Si ya se agotaron los
                    // reintentos, lo dejamos quieto y lo señalamos en el
                    // badge para que alguien lo revise manualmente — más
                    // vale una foto atascada visible que una perdida en
                    // silencio.
                    p.intentos = (p.intentos || 0) + 1;
                    p.ultimoError = resp.url;
                    await actualizarPendiente(p);
                } catch (err) {
                    // Esto sí es una falla de red real (fetch no llegó a
                    // completarse): paramos aquí y reintentamos todo en el
                    // próximo evento 'online', sin sumar intentos porque no
                    // sabemos si el servidor llegó a recibir algo.
                    break;
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

    /** Reinicia el contador de intentos de los pendientes atascados y reintenta ahora mismo. */
    async function reintentarFallidos() {
        const pendientes = await listarPendientes();
        for (const p of pendientes) {
            if ((p.intentos || 0) >= MAX_INTENTOS_SYNC) {
                p.intentos = 0;
                await actualizarPendiente(p);
            }
        }
        await sincronizarPendientes();
    }

    window.SismosOffline = {
        guardarPendiente,
        listarPendientes,
        sincronizarPendientes,
        actualizarBadge,
        reintentarFallidos,
    };

    window.addEventListener('online', function () {
        actualizarEstadoConexion();
        sincronizarPendientes();
    });
    window.addEventListener('offline', actualizarEstadoConexion);

    document.addEventListener('DOMContentLoaded', function () {
        actualizarEstadoConexion();
        actualizarBadge();
        sincronizarPendientes();
    });
})();
