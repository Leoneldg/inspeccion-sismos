/**
 * Buzón offline — panel interactivo de inspecciones pendientes.
 * Muestra estado real, error específico por inspección, y permite
 * reintentar, editar o eliminar cada una individualmente.
 */
(function () {
    'use strict';

    function offline() { return window.SismosOffline; }

    function escapar(s) {
        return String(s || '').replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function fmtFecha(ts) {
        if (!ts) return '—';
        try {
            const d = new Date(ts);
            return d.toLocaleDateString('es-VE') + ' ' + d.toLocaleTimeString('es-VE', {hour:'2-digit',minute:'2-digit'});
        } catch(e) { return '—'; }
    }

    function contarFotos(campos) {
        return Array.isArray(campos) ? campos.filter(c => c && c.isFile).length : 0;
    }

    function overlay() { return document.getElementById('buzon-offline'); }

    // ── Render principal ──────────────────────────────────────────────────────
    async function render() {
        const cont   = document.getElementById('buzon-offline-lista');
        const resumen = document.getElementById('buzon-resumen');
        if (!cont) return;

        let pendientes = [];
        try {
            pendientes = await offline().listarPendientes();
        } catch(e) {
            cont.innerHTML = '<div class="buzon-vacio"><i class="bi bi-exclamation-circle"></i> El almacenamiento local no está disponible en este navegador.</div>';
            return;
        }

        const MAX = offline().MAX_INTENTOS_SYNC || 8;
        const conError = pendientes.filter(p => (p.intentos||0) >= MAX).length;

        // Actualizar resumen
        if (resumen) {
            if (!pendientes.length) {
                resumen.textContent = 'Todo sincronizado';
            } else {
                resumen.textContent = pendientes.length + ' pendiente' + (pendientes.length===1?'':'s')
                    + (conError ? ' · ' + conError + ' con error' : '')
                    + (navigator.onLine ? '' : ' · sin conexión');
            }
        }

        // Habilitar botón "reintentar todo"
        const btnTodo = document.getElementById('buzon-reintentar-todo');
        if (btnTodo) btnTodo.disabled = !pendientes.length || !navigator.onLine;

        if (!pendientes.length) {
            cont.innerHTML = '<div class="buzon-vacio"><i class="bi bi-check2-circle" style="color:#1a8a4a;font-size:32px;display:block;margin-bottom:8px;"></i>Todo sincronizado. No hay inspecciones pendientes.</div>';
            return;
        }

        // Ordenar: con error primero, luego por fecha descendente
        pendientes.sort((a,b) => {
            const aErr = (a.intentos||0) >= MAX;
            const bErr = (b.intentos||0) >= MAX;
            if (aErr !== bErr) return bErr ? 1 : -1;
            return (b.creado||0) - (a.creado||0);
        });

        cont.innerHTML = '';
        pendientes.forEach(p => {
            const meta     = p.meta || {};
            const intentos = p.intentos || 0;
            const agotado  = intentos >= MAX;
            const subiendo = !!p.subiendo;
            const fotos    = contarFotos(p.campos);
            const nombre   = meta.nombre_edificio || '(Sin nombre)';
            const ubic     = [meta.parroquia, meta.municipio, meta.estado].filter(Boolean).join(', ');

            const fila = document.createElement('div');
            fila.className = 'buzon-item' + (agotado ? ' buzon-item-error' : subiendo ? ' buzon-item-subiendo' : '');
            fila.dataset.id = p.id;

            // ── Cabecera de la fila ──
            let badgeHTML = '';
            if (subiendo) {
                badgeHTML = '<span class="badge badge-azul"><i class="bi bi-arrow-repeat girando"></i> Subiendo…</span>';
            } else if (agotado) {
                badgeHTML = '<span class="badge badge-rojo"><i class="bi bi-exclamation-circle"></i> Error — revisar</span>';
            } else if (intentos > 0) {
                badgeHTML = '<span class="badge badge-amarillo"><i class="bi bi-clock-history"></i> ' + intentos + '/' + MAX + ' intentos</span>';
            } else {
                badgeHTML = '<span class="badge badge-gris">Pendiente</span>';
            }

            // ── Error específico ──
            let errorHTML = '';
            if (p.ultimoError) {
                errorHTML = '<div class="buzon-error-msg"><i class="bi bi-exclamation-triangle-fill"></i> ' + escapar(p.ultimoError) + '</div>';
            }

            // ── Barra de progreso cuando está subiendo ──
            const barraHTML = subiendo
                ? '<div class="buzon-barra-wrap"><div class="buzon-barra-fill"></div></div>'
                : '';

            // ── Botones de acción ──
            const BASE = window._APP_URL_BASE || '/';
            let botonesHTML = '';
            if (!subiendo) {
                if (navigator.onLine) {
                    botonesHTML += '<button type="button" class="btn btn-primary btn-sm buzon-btn-reintentar" data-id="' + p.id + '">'
                        + '<i class="bi bi-arrow-repeat"></i> ' + (agotado ? 'Reintentar' : 'Subir ahora') + '</button>';
                }
                botonesHTML += '<a href="' + BASE + 'formulario/create.php?editar_offline=' + p.id + '" class="btn btn-outline btn-sm">'
                    + '<i class="bi bi-pencil"></i> Editar</a>';
                botonesHTML += '<button type="button" class="btn btn-danger btn-sm buzon-btn-eliminar" data-id="' + p.id + '">'
                    + '<i class="bi bi-trash"></i> Eliminar</button>';
            }

            fila.innerHTML =
                '<div class="buzon-item-header">'
                    + '<div class="buzon-item-info">'
                        + '<div class="buzon-item-nombre"><i class="bi bi-building"></i> ' + escapar(nombre) + '</div>'
                        + '<div class="buzon-item-meta">'
                            + (ubic ? '<span><i class="bi bi-geo-alt"></i> ' + escapar(ubic) + '</span>' : '')
                            + (meta.fecha_inspeccion ? '<span><i class="bi bi-calendar3"></i> ' + escapar(meta.fecha_inspeccion) + '</span>' : '')
                            + (fotos ? '<span><i class="bi bi-camera"></i> ' + fotos + ' foto' + (fotos===1?'':'s') + '</span>' : '')
                            + '<span><i class="bi bi-clock-history"></i> ' + fmtFecha(p.creado) + '</span>'
                        + '</div>'
                    + '</div>'
                    + '<div class="buzon-item-badge">' + badgeHTML + '</div>'
                + '</div>'
                + errorHTML
                + barraHTML
                + (botonesHTML ? '<div class="buzon-item-acciones">' + botonesHTML + '</div>' : '');

            cont.appendChild(fila);
        });

        // Eventos de botones
        cont.querySelectorAll('.buzon-btn-reintentar').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = +btn.dataset.id;
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-arrow-repeat girando"></i> Reintentando…';
                try {
                    await offline().reintentarUno(id);
                } catch(e) {
                    window.SismosToast?.('<i class="bi bi-exclamation-triangle-fill"></i> Error al reintentar: ' + e.message, 'error');
                }
                await render();
            });
        });

        cont.querySelectorAll('.buzon-btn-eliminar').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id  = +btn.dataset.id;
                const fil = btn.closest('.buzon-item');
                const nom = fil?.querySelector('.buzon-item-nombre')?.textContent?.trim() || 'esta inspección';
                if (!confirm('¿Eliminar ' + nom + ' del dispositivo?\n\nSi no se subió al servidor, los datos se perderán permanentemente.')) return;
                btn.disabled = true;
                try {
                    await offline().eliminarPendiente(id);
                    await offline().actualizarBadge();
                    await render();
                } catch(e) {
                    alert('No se pudo eliminar: ' + e.message);
                    btn.disabled = false;
                }
            });
        });
    }

    // ── Abrir/cerrar ─────────────────────────────────────────────────────────
    function abrir() {
        const ol = overlay();
        if (!ol) return;
        ol.classList.add('buzon-abierto');
        render();
    }

    function cerrar() {
        overlay()?.classList.remove('buzon-abierto');
    }

    // ── Reintentar todos ──────────────────────────────────────────────────────
    async function reintentarTodo() {
        const btn = document.getElementById('buzon-reintentar-todo');
        if (!navigator.onLine) {
            window.SismosToast?.('<i class="bi bi-wifi-off"></i> Sin conexión. Conéctese para reenviar.', 'error');
            return;
        }
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-arrow-repeat girando"></i> Reintentando…'; }
        try {
            await offline().reintentarFallidos();
        } catch(e) {}
        await render();
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Reintentar todo'; }
    }

    // ── Cerrar al hacer clic fuera ────────────────────────────────────────────
    document.addEventListener('click', e => {
        const ol = overlay();
        if (ol && ol.classList.contains('buzon-abierto') && e.target === ol) cerrar();
    });

    // ── Botones del DOM ───────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('buzon-reintentar-todo')?.addEventListener('click', reintentarTodo);
        document.querySelectorAll('[data-buzon-abrir]').forEach(el => el.addEventListener('click', abrir));
    });

    // ── API pública ───────────────────────────────────────────────────────────
    window.SismosBuzon = { abrir, cerrar, render, reintentarTodo };

})();
