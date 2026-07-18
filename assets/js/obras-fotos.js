/**
 * RESPALDO LOCAL DE FOTOS.
 *
 * Cuando se toma una foto con la cámara desde el navegador, esa imagen NO
 * queda en la galería del teléfono: vive solo en memoria. Si falla la
 * subida, se pierde para siempre y hay que volver al sitio.
 *
 * Este módulo guarda una copia de cada foto en el teléfono (IndexedDB)
 * apenas se toma, antes de intentar subirla. Además permite descargarlas
 * a la galería para tenerlas como respaldo físico.
 */
(function () {
    'use strict';

    const DB_NAME = 'obras_fotos';
    const DB_VERSION = 1;
    const STORE = 'fotos';
    const LIMITE_MB = 300;          // tope del respaldo, para no llenar el teléfono

    function abrirDB() {
        return new Promise((resolve, reject) => {
            if (!window.indexedDB) { reject(new Error('Sin almacenamiento local')); return; }
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = () => {
                const db = req.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    const st = db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
                    st.createIndex('inspeccion', 'inspeccion_id', { unique: false });
                    st.createIndex('subida', 'subida', { unique: false });
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    function tx(modo, fn) {
        return abrirDB().then(db => new Promise((resolve, reject) => {
            const t = db.transaction(STORE, modo);
            const r = fn(t.objectStore(STORE));
            t.oncomplete = () => resolve(r && r.result !== undefined ? r.result : r);
            t.onerror = () => reject(t.error);
        }));
    }

    /**
     * Guarda la foto en el teléfono apenas se toma.
     * Devuelve el id local, que sirve para marcarla como subida después.
     */
    async function respaldar(archivo, meta) {
        if (!archivo) return null;
        try {
            const registro = {
                inspeccion_id: (meta && meta.inspeccion_id) || 0,
                nivel: (meta && meta.nivel) || '',
                ref_id: (meta && meta.ref_id) || 0,
                parte: (meta && meta.parte) || 'durante',
                origen: (meta && meta.origen) || 'camara',
                descripcion: (meta && meta.descripcion) || '',
                nombre: archivo.name || ('foto_' + Date.now() + '.jpg'),
                tipo: archivo.type || 'image/jpeg',
                peso: archivo.size || 0,
                blob: archivo,
                tomada_en: new Date().toISOString(),
                usuario: window._USER_ID || 0,
                subida: 0,
            };
            const id = await tx('readwrite', s => s.add(registro));
            limpiarSiHaceFalta();      // en segundo plano
            return id;
        } catch (e) {
            return null;   // si no se puede respaldar, el flujo continúa igual
        }
    }

    /** Marca una foto como ya subida al servidor. */
    async function marcarSubida(id) {
        if (!id) return;
        try {
            const f = await tx('readonly', s => s.get(id));
            if (!f) return;
            f.subida = 1;
            f.subida_en = new Date().toISOString();
            await tx('readwrite', s => s.put(f));
        } catch (e) { /* nada */ }
    }

    async function listar(soloPendientes) {
        try {
            const todas = await tx('readonly', s => s.getAll());
            const uid = window._USER_ID || 0;
            let r = (todas || []).filter(f => !f.usuario || f.usuario === uid);
            if (soloPendientes) r = r.filter(f => !f.subida);
            return r.sort((a, b) => (b.id || 0) - (a.id || 0));
        } catch (e) { return []; }
    }

    async function borrar(id) {
        try { await tx('readwrite', s => s.delete(id)); } catch (e) {}
    }

    /** Cuánto espacio ocupa el respaldo. */
    async function espacioUsado() {
        const todas = await listar(false);
        const bytes = todas.reduce((s, f) => s + (f.peso || 0), 0);
        return { fotos: todas.length, mb: +(bytes / 1048576).toFixed(1) };
    }

    /**
     * Si el respaldo crece demasiado, borra las MÁS VIEJAS que ya se
     * subieron. Nunca borra una foto pendiente de subir.
     */
    async function limpiarSiHaceFalta() {
        try {
            const uso = await espacioUsado();
            if (uso.mb < LIMITE_MB) return;
            const todas = await listar(false);
            const subidas = todas.filter(f => f.subida).sort((a, b) => (a.id || 0) - (b.id || 0));
            let liberado = 0;
            const objetivo = (uso.mb - LIMITE_MB * 0.7) * 1048576;
            for (const f of subidas) {
                if (liberado >= objetivo) break;
                liberado += f.peso || 0;
                await borrar(f.id);
            }
        } catch (e) { /* nada */ }
    }

    /** Descarga una foto a la galería/descargas del teléfono. */
    async function descargar(id) {
        try {
            const f = await tx('readonly', s => s.get(id));
            if (!f || !f.blob) { alert('Esa foto ya no está guardada.'); return; }
            const url = URL.createObjectURL(f.blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = f.nombre || 'foto.jpg';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 4000);
        } catch (e) {
            alert('No se pudo descargar la foto.');
        }
    }

    /** Descarga todas las que aún no se han subido. */
    async function descargarPendientes() {
        const pend = await listar(true);
        if (!pend.length) { alert('No hay fotos pendientes de subir.'); return; }
        if (!confirm('Se descargarán ' + pend.length + ' foto(s) a su teléfono.\n\n¿Continuar?')) return;
        for (const f of pend) {
            await descargar(f.id);
            await new Promise(r => setTimeout(r, 350));   // el navegador necesita respiro
        }
    }

    /** Panel para revisar las fotos guardadas en el teléfono. */
    async function verGaleria() {
        const fotos = await listar(false);
        const uso = await espacioUsado();
        let capa = document.getElementById('fotos-panel');
        if (capa) capa.remove();

        capa = document.createElement('div');
        capa.id = 'fotos-panel';
        capa.style.cssText = 'position:fixed;inset:0;background:rgba(20,25,40,.6);z-index:2200;'
            + 'display:flex;align-items:center;justify-content:center;padding:14px;';

        let cuerpo;
        if (!fotos.length) {
            cuerpo = '<p style="color:#5b6478;margin:0;">Todavía no hay fotos guardadas en este teléfono.</p>';
        } else {
            cuerpo = fotos.map(f => {
                const estado = f.subida
                    ? '<span style="color:#2E7D32;font-size:11.5px;font-weight:600;">Subida</span>'
                    : '<span style="color:#a8871f;font-size:11.5px;font-weight:700;">Pendiente de subir</span>';
                const cuando = f.tomada_en ? new Date(f.tomada_en).toLocaleString('es-VE') : '';
                const kb = Math.round((f.peso || 0) / 1024);
                const origen = f.origen === 'galeria'
                    ? '<span style="font-size:10.5px;color:#5b6478;"><i class="bi bi-images"></i> galería</span>'
                    : '<span style="font-size:10.5px;color:#2d4488;"><i class="bi bi-camera-fill"></i> cámara</span>';
                return '<div style="display:flex;gap:10px;align-items:center;padding:9px 4px;border-bottom:1px solid #f0f2f7;">'
                    + '<i class="bi bi-image" style="color:#2d4488;font-size:18px;"></i>'
                    + '<div style="flex:1;min-width:0;">'
                    + '<div style="font-size:12.5px;font-weight:600;color:#2a3140;">'
                    + (f.descripcion || (f.nivel + ' #' + f.ref_id)) + '</div>'
                    + '<div style="font-size:11px;color:#767c94;">' + cuando + ' · ' + kb + ' KB · ' + origen + '</div>'
                    + estado + '</div>'
                    + '<button onclick="ObrasFotos.descargar(' + f.id + ')" '
                    + 'style="background:#fff;border:1px solid #dbe0ec;border-radius:6px;padding:4px 10px;'
                    + 'font-size:11.5px;cursor:pointer;color:#22366F;">Guardar</button></div>';
            }).join('');
        }

        const pend = fotos.filter(f => !f.subida).length;
        capa.innerHTML = '<div style="background:#fff;border-radius:12px;max-width:520px;width:100%;max-height:88vh;overflow-y:auto;">'
            + '<div style="background:#22366F;color:#fff;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;">'
            + '<b>Fotos guardadas en el teléfono</b>'
            + '<button onclick="document.getElementById(\'fotos-panel\').remove()" '
            + 'style="background:transparent;border:0;color:#fff;font-size:20px;cursor:pointer;line-height:1;">&times;</button></div>'
            + '<div style="padding:12px 18px 6px;font-size:12.5px;color:#5b6478;">'
            + uso.fotos + ' foto(s) · ' + uso.mb + ' MB'
            + (pend ? ' · <strong style="color:#a8871f;">' + pend + ' sin subir</strong>' : '')
            + '</div>'
            + '<div style="padding:0 18px;">' + cuerpo + '</div>'
            + (pend ? '<div style="padding:14px 18px;"><button onclick="ObrasFotos.descargarPendientes()" '
              + 'style="width:100%;background:#22366F;color:#fff;border:0;border-radius:8px;padding:11px;'
              + 'font-weight:700;font-size:14px;cursor:pointer;">Guardar las ' + pend + ' pendientes en el teléfono</button></div>' : '')
            + '</div>';
        document.body.appendChild(capa);
    }

    window.ObrasFotos = {
        respaldar, marcarSubida, listar, borrar, descargar,
        descargarPendientes, verGaleria, espacioUsado,
    };
})();
