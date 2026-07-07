/**
 * Buzón de envíos pendientes (modo offline).
 *
 * Muestra, en un panel, las inspecciones guardadas en este dispositivo que
 * todavía no se han subido al servidor. Cada fila trae su información
 * identificativa y un botón "Reintentar" que vuelve a ejecutar el envío de
 * ese registro puntual. Cuando un envío se sube con éxito, desaparece del
 * listado automáticamente.
 *
 * Depende de window.SismosOffline (assets/js/offline.js).
 */
(function () {
    'use strict';

    function offline() { return window.SismosOffline; }

    function fmtFecha(ts) {
        if (!ts) return '';
        try {
            const d = new Date(ts);
            return d.toLocaleDateString('es-VE') + ' ' + d.toLocaleTimeString('es-VE', { hour: '2-digit', minute: '2-digit' });
        } catch (e) { return ''; }
    }

    function contarFotos(campos) {
        if (!Array.isArray(campos)) return 0;
        return campos.filter((c) => c && c.isFile).length;
    }

    // Descripción territorial breve a partir de la metadata guardada.
    function ubicacionTexto(meta) {
        const partes = [meta.parroquia, meta.municipio, meta.estado].filter(Boolean);
        return partes.join(', ');
    }

    function overlay() { return document.getElementById('buzon-offline'); }

    async function render() {
        const cont = document.getElementById('buzon-offline-lista');
        const resumen = document.getElementById('buzon-resumen');
        if (!cont) return;

        let pendientes = [];
        try {
            pendientes = await offline().listarPendientes();
        } catch (e) {
            cont.innerHTML = '<div class="buzon-vacio">El almacenamiento local no está disponible en este navegador.</div>';
            if (resumen) resumen.textContent = '';
            return;
        }

        const MAX = offline().MAX_INTENTOS_SYNC || 8;

        if (!pendientes.length) {
            cont.innerHTML = '<div class="buzon-vacio"><i class="bi bi-check2-circle"></i> No hay envíos pendientes. Todo está sincronizado.</div>';
            if (resumen) resumen.textContent = '0 pendientes';
            const btnTodo = document.getElementById('buzon-reintentar-todo');
            if (btnTodo) btnTodo.disabled = true;
            return;
        }

        const btnTodo = document.getElementById('buzon-reintentar-todo');
        if (btnTodo) btnTodo.disabled = !navigator.onLine;

        if (resumen) {
            const conError = pendientes.filter((p) => (p.intentos || 0) >= MAX).length;
            resumen.textContent = pendientes.length + ' pendiente' + (pendientes.length === 1 ? '' : 's')
                + (conError ? ' · ' + conError + ' con error' : '')
                + (navigator.onLine ? '' : ' · sin conexión');
        }

        // Orden: más recientes primero.
        pendientes.sort((a, b) => (b.creado || 0) - (a.creado || 0));

        cont.innerHTML = '';
        pendientes.forEach((p) => {
            const meta = p.meta || {};
            const intentos = p.intentos || 0;
            const agotado = intentos >= MAX;
            const fotos = contarFotos(p.campos);
            const ubic = ubicacionTexto(meta);

            const fila = document.createElement('div');
            fila.className = 'buzon-item' + (agotado ? ' buzon-item-error' : '');
            fila.dataset.id = p.id;

            const info = document.createElement('div');
            info.className = 'buzon-item-info';
            info.innerHTML =
                '<div class="buzon-item-nombre">' +
                    '<i class="bi bi-building"></i> ' +
                    (meta.nombre_edificio ? escapar(meta.nombre_edificio) : '(Sin nombre de edificio)') +
                '</div>' +
                '<div class="buzon-item-meta">' +
                    (ubic ? '<span><i class="bi bi-geo-alt"></i> ' + escapar(ubic) + '</span>' : '') +
                    (meta.fecha_inspeccion ? '<span><i class="bi bi-calendar3"></i> ' + escapar(meta.fecha_inspeccion) + '</span>' : '') +
                    (fotos ? '<span><i class="bi bi-camera"></i> ' + fotos + ' foto' + (fotos === 1 ? '' : 's') + '</span>' : '') +
                    '<span><i class="bi bi-clock-history"></i> ' + escapar(fmtFecha(p.creado)) + '</span>' +
                    (intentos ? '<span class="buzon-intentos"><i class="bi bi-arrow-repeat"></i> ' + intentos + ' intento' + (intentos === 1 ? '' : 's') + (agotado ? ' (sin éxito)' : '') + '</span>' : '') +
                '</div>';

            const acciones = document.createElement('div');
            acciones.className = 'buzon-item-acciones';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-primary btn-sm buzon-btn-reintentar';
            btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Reintentar';
            btn.addEventListener('click', () => reintentar(p.id, btn));
            acciones.appendChild(btn);

            fila.appendChild(info);
            fila.appendChild(acciones);
            cont.appendChild(fila);
        });
    }

    function escapar(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    async function reintentar(id, btn) {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Enviando…';
        }
        let res;
        try {
            res = await offline().reintentarUno(id);
        } catch (e) {
            res = { ok: false, motivo: 'error' };
        }

        if (res && res.ok) {
            // Se subió: quitar la fila con una pequeña animación y re-renderizar.
            const fila = document.querySelector('.buzon-item[data-id="' + id + '"]');
            if (fila) {
                fila.classList.add('buzon-item-enviado');
                setTimeout(render, 350);
            } else {
                render();
            }
            return;
        }

        // No se pudo: mostrar el motivo y rehabilitar el botón.
        const motivos = {
            'sin-conexion': 'Sin conexión. Conéctate e intenta de nuevo.',
            'rechazado': 'El servidor rechazó el envío (sesión expirada o datos inválidos).',
            'red': 'No se pudo conectar con el servidor.',
            'no-existe': 'El registro ya no está en el buzón.',
            'sin-db': 'Almacenamiento local no disponible.',
        };
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Reintentar';
        }
        const fila = document.querySelector('.buzon-item[data-id="' + id + '"]');
        if (fila) {
            let aviso = fila.querySelector('.buzon-item-aviso');
            if (!aviso) {
                aviso = document.createElement('div');
                aviso.className = 'buzon-item-aviso';
                fila.querySelector('.buzon-item-info').appendChild(aviso);
            }
            aviso.textContent = motivos[res && res.motivo] || 'No se pudo enviar. Intenta más tarde.';
        }
        // Si desapareció (no-existe), re-render para reflejar el estado real.
        if (res && res.motivo === 'no-existe') render();
    }

    async function reintentarTodo() {
        const btn = document.getElementById('buzon-reintentar-todo');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Enviando…'; }
        try {
            // Reinicia atascados y sincroniza toda la cola.
            await offline().reintentarFallidos();
        } catch (e) { /* ignore */ }
        if (btn) { btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Reintentar todo'; }
        render();
    }

    function abrir() {
        const ov = overlay();
        if (!ov) return;
        ov.hidden = false;
        document.body.classList.add('buzon-abierto');
        render();
    }

    function cerrar() {
        const ov = overlay();
        if (!ov) return;
        ov.hidden = true;
        document.body.classList.remove('buzon-abierto');
    }

    // Cerrar al hacer clic fuera del panel o con Escape.
    document.addEventListener('click', function (e) {
        const ov = overlay();
        if (ov && !ov.hidden && e.target === ov) cerrar();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') cerrar();
    });

    window.SismosBuzon = { abrir, cerrar, render, reintentarTodo };

    // Si el buzón está abierto cuando la sincronización automática vacía la
    // cola, refrescar la lista para que los enviados desaparezcan solos.
    window.addEventListener('online', function () { const ov = overlay(); if (ov && !ov.hidden) setTimeout(render, 1500); });
})();
