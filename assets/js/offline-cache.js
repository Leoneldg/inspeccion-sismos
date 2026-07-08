/**
 * offline-cache.js
 * Guarda en IndexedDB los datos necesarios para trabajar sin conexión:
 *   - Lista de ingenieros/inspectores (para el selector del formulario)
 *   - Datos básicos del usuario logueado
 * Se actualiza automáticamente cuando hay internet.
 * NO guarda inspecciones del servidor (solo las pendientes de subir).
 */
(function () {
    'use strict';

    var DB_NAME    = 'sismos_cache';
    var DB_VERSION = 1;
    var STORES     = { ingenieros: 'ingenieros', usuario: 'usuario', meta: 'meta' };

    // ── Abrir BD ──────────────────────────────────────────────────────────────
    function abrirDB() {
        return new Promise(function (resolve, reject) {
            if (!window.indexedDB) { reject(new Error('IndexedDB no disponible')); return; }
            var req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function (e) {
                var db = e.target.result;
                if (!db.objectStoreNames.contains('ingenieros')) {
                    db.createObjectStore('ingenieros', { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains('usuario')) {
                    db.createObjectStore('usuario', { keyPath: 'clave' });
                }
                if (!db.objectStoreNames.contains('meta')) {
                    db.createObjectStore('meta', { keyPath: 'clave' });
                }
            };
            req.onsuccess = function (e) { resolve(e.target.result); };
            req.onerror   = function (e) { reject(e.target.error); };
        });
    }

    // ── Helpers de lectura/escritura ──────────────────────────────────────────
    function getAll(storeName) {
        return abrirDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx   = db.transaction(storeName, 'readonly');
                var req  = tx.objectStore(storeName).getAll();
                req.onsuccess = function () { resolve(req.result); };
                req.onerror   = function () { reject(req.error); };
            });
        });
    }

    function putAll(storeName, items) {
        return abrirDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx    = db.transaction(storeName, 'readwrite');
                var store = tx.objectStore(storeName);
                store.clear();
                items.forEach(function (item) { store.put(item); });
                tx.oncomplete = resolve;
                tx.onerror    = function () { reject(tx.error); };
            });
        });
    }

    function putOne(storeName, item) {
        return abrirDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx   = db.transaction(storeName, 'readwrite');
                var req  = tx.objectStore(storeName).put(item);
                req.onsuccess = resolve;
                req.onerror   = function () { reject(req.error); };
            });
        });
    }

    function getOne(storeName, key) {
        return abrirDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx  = db.transaction(storeName, 'readonly');
                var req = tx.objectStore(storeName).get(key);
                req.onsuccess = function () { resolve(req.result || null); };
                req.onerror   = function () { reject(req.error); };
            });
        });
    }

    // ── Actualizar caché desde el servidor ───────────────────────────────────
    function refrescarIngenieros() {
        return fetch(window._APP_URL_BASE + 'api/ingenieros_activos.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject('HTTP ' + r.status); })
            .then(function (data) {
                if (data.ok && Array.isArray(data.ingenieros)) {
                    return putAll('ingenieros', data.ingenieros)
                        .then(function () {
                            return putOne('meta', { clave: 'ingenieros_ts', valor: Date.now() });
                        });
                }
            });
    }

    function refrescarUsuario() {
        return fetch(window._APP_URL_BASE + 'api/usuario_actual.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject('HTTP ' + r.status); })
            .then(function (data) {
                if (data.ok) {
                    return putOne('usuario', { clave: 'yo', datos: data.usuario })
                        .then(function () {
                            return putOne('meta', { clave: 'usuario_ts', valor: Date.now() });
                        });
                }
            });
    }

    // Actualiza el caché si hay internet y han pasado más de 5 minutos
    // desde la última actualización (o si nunca se actualizó).
    function actualizarSiNecesario() {
        if (!navigator.onLine) return Promise.resolve();
        var STALE_MS = 5 * 60 * 1000; // 5 minutos
        return Promise.all([
            getOne('meta', 'ingenieros_ts').then(function (m) {
                if (!m || (Date.now() - m.valor) > STALE_MS) return refrescarIngenieros();
            }).catch(function () {}),
            getOne('meta', 'usuario_ts').then(function (m) {
                if (!m || (Date.now() - m.valor) > STALE_MS) return refrescarUsuario();
            }).catch(function () {}),
        ]);
    }

    // ── API pública ───────────────────────────────────────────────────────────
    window.SismosCache = {
        getIngenieros:  function () { return getAll('ingenieros'); },
        getUsuario:     function () { return getOne('usuario', 'yo').then(function(r) { return r ? r.datos : null; }); },
        actualizar:     actualizarSiNecesario,
        refrescarTodo:  function () { return Promise.all([refrescarIngenieros(), refrescarUsuario()]); },
    };

    // Al cargar la página con internet: actualizar el caché en segundo plano.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { actualizarSiNecesario().catch(function () {}); });
    } else {
        actualizarSiNecesario().catch(function () {});
    }
    // También cuando se recupera la conexión.
    window.addEventListener('online', function () { actualizarSiNecesario().catch(function () {}); });

})();
