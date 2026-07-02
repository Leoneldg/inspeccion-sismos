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
        document.querySelectorAll('[data-pendientes-offline]').forEach(function (el) {
            el.textContent = pendientes.length;
        });
        document.querySelectorAll('[data-pendientes-offline-wrap]').forEach(function (el) {
            el.classList.toggle('oculto-offline', pendientes.length === 0);
        });
    }

    let sincronizando = false;
    async function sincronizarPendientes() {
        if (sincronizando || !navigator.onLine) return;
        sincronizando = true;
        try {
            const pendientes = await listarPendientes();
            for (const p of pendientes) {
                try {
                    const fd = reconstruirFormData(p.campos);
                    const resp = await fetch(p.url, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                    });
                    // Si el servidor respondió algo (incluso un error de validación propio
                    // de esa fila), la damos por procesada: no queremos reintentar para
                    // siempre un registro que el servidor ya rechazó por datos inválidos.
                    // Solo la dejamos en cola si la RED falló (catch de abajo).
                    if (resp.status > 0) {
                        await eliminarPendiente(p.id);
                    }
                } catch (err) {
                    // Se cayó la red a mitad de la sincronización: paramos aquí y
                    // reintentamos todo en el próximo evento 'online'.
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

    window.SismosOffline = {
        guardarPendiente,
        listarPendientes,
        sincronizarPendientes,
        actualizarBadge,
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
