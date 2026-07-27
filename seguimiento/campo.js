/* ===================================================================
 * MODO CAMPO · lógica de captura rápida.
 *
 * Reutiliza el árbol de arbol_avance.php y los endpoints de foto y
 * avance existentes. Aquí solo vive la NAVEGACIÓN: elegir piso, listar
 * lo pendiente, mostrar un elemento a la vez y saltar al siguiente.
 *
 * Un "elemento" del recorrido es un apartamento/local (con sus
 * ambientes) o un área común. Se normalizan a una lista plana por piso
 * para poder recorrerlos de corrido.
 * =================================================================== */

let CM_ARBOL = null;      // árbol crudo del servidor
let CM_PISOS = [];        // [{ id, etiqueta, items: [...] }]
let CM_PISO_ACTUAL = null;
let CM_ITEM_ACTUAL = null;
let CM_FOTO_DEST = null;  // destino de la foto que se está subiendo

/* ---- Utilidades de color / texto ---- */
function cmColor(pct) {
    if (pct >= 100) return '#1D9E75';
    if (pct > 0)    return '#EF9F27';
    return '#E24B4A';
}
function cmToast(msg) {
    const t = document.getElementById('cm-toast');
    t.textContent = msg;
    t.classList.add('ver');
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('ver'), 2200);
}

/* ---- Normalizar el árbol a una lista de items por piso ----
 *
 * Cada item queda con la misma "forma", venga de un apartamento o de un
 * área común, para que el recorrido no tenga que distinguir casos:
 *   { tipo:'apartamento'|'area', id, titulo, sub,
 *     nivel:'ambiente'|'area_comun', refId, avance,
 *     tieneFotoDurante, fotosAntes, fotosDurante, fotosDespues, subitems }
 *
 * Para apartamentos, cada ambiente que necesita reparación es un
 * subitem con su propio avance y foto. Para áreas comunes, el área es
 * el item completo.
 */
function cmNormalizar(arbol) {
    const pisos = [];

    (arbol.pisos || []).forEach(p => {
        const items = [];

        (p.apartamentos || []).forEach(ap => {
            const esLocal = !!ap.es_local;
            // Ambientes que requieren reparación = lo que se trabaja.
            const ambs = (ap.ambientes || []).filter(a => a.necesita_reparacion);
            const subitems = ambs.map(a => ({
                nivel: 'ambiente',
                refId: a.id,
                titulo: a.etiqueta,
                avance: a.avance || 0,
                tieneFotoDurante: !!a.tiene_foto_durante,
                fotosAntes: a.fotos_antes || 0,
                fotosDurante: a.fotos_durante || 0,
            }));
            // Si el apartamento no tiene ambientes detallados, se trabaja
            // como una sola unidad (avance del apartamento completo).
            if (!subitems.length) {
                subitems.push({
                    nivel: 'apartamento',
                    refId: ap.id,
                    titulo: 'Avance general',
                    avance: ap.avance || 0,
                    tieneFotoDurante: !!ap.tiene_foto_durante,
                    fotosAntes: 0,
                    fotosDurante: 0,
                });
            }
            items.push({
                tipo: esLocal ? 'local' : 'apartamento',
                id: 'ap' + ap.id,
                titulo: (esLocal ? 'Local ' : '') + (ap.identificador || ''),
                sub: subitems.length + (subitems.length === 1 ? ' trabajo' : ' trabajos'),
                subitems: subitems,
            });
        });

        pisos.push({
            id: p.piso_id,
            etiqueta: p.etiqueta || ('Piso ' + p.numero_piso),
            avance: p.avance || 0,
            items: items,
        });
    });

    // Áreas comunes: no cuelgan de un piso. Se agrupan en un "piso"
    // virtual al final para que también entren en el recorrido.
    const areas = (arbol.areas_comunes || []);
    if (areas.length) {
        const items = areas.map(a => ({
            tipo: 'area',
            id: 'ac' + a.id,
            titulo: a.nombre,
            sub: (a.m2 ? a.m2 + ' m²' : 'Área común'),
            subitems: [{
                nivel: 'area_comun',
                refId: a.id,
                titulo: a.nombre,
                avance: a.avance || 0,
                tieneFotoDurante: !!a.tiene_foto_durante,
                fotosAntes: (a.fotos_antes || []).length,
                fotosDurante: (a.fotos_durante || []).length,
                fotosDespues: (a.fotos_despues || []).length,
            }],
        }));
        pisos.push({ id: 'areas', etiqueta: 'Áreas comunes', avance: 0, items: items, esAreas: true });
    }

    return pisos;
}

/* ---- % de un item = promedio de sus subitems ---- */
function cmAvanceItem(item) {
    if (!item.subitems.length) return 0;
    const s = item.subitems.reduce((a, x) => a + (x.avance || 0), 0);
    return Math.round(s / item.subitems.length);
}
/* Un item está pendiente si no llegó al 100%. */
function cmPendiente(item) { return cmAvanceItem(item) < 100; }

/* ---- Pintar la fila de pisos ---- */
function cmPintarPisos() {
    const cont = document.getElementById('cm-pisos');
    cont.innerHTML = CM_PISOS.map(p => {
        const pend = p.items.filter(cmPendiente).length;
        const act = (CM_PISO_ACTUAL && CM_PISO_ACTUAL.id === p.id) ? ' activo' : '';
        const nota = pend > 0 ? (pend + ' por trabajar') : 'listo';
        return '<button class="cm-piso-btn' + act + '" onclick="cmElegirPiso(\'' + p.id + '\')">'
            + p.etiqueta
            + '<span class="cm-mini">' + nota + '</span></button>';
    }).join('');
}

/* ---- Elegir piso: muestra el primer pendiente de ese piso ---- */
function cmElegirPiso(pisoId) {
    CM_PISO_ACTUAL = CM_PISOS.find(p => String(p.id) === String(pisoId)) || null;
    cmPintarPisos();
    if (!CM_PISO_ACTUAL) return;
    const prox = CM_PISO_ACTUAL.items.find(cmPendiente) || CM_PISO_ACTUAL.items[0];
    cmMostrarItem(prox);
}

/* ---- Buscar el siguiente pendiente DENTRO del piso actual ---- */
function cmSiguientePendiente(desdeItem) {
    if (!CM_PISO_ACTUAL) return null;
    const items = CM_PISO_ACTUAL.items;
    const i = items.indexOf(desdeItem);
    for (let k = 1; k <= items.length; k++) {
        const cand = items[(i + k) % items.length];
        if (cmPendiente(cand)) return cand;
    }
    return null;   // no queda nada pendiente en este piso
}

/* ---- Chips de los elementos del piso ---- */
function cmChips() {
    if (!CM_PISO_ACTUAL) return '';
    return '<div class="cm-chips">' + CM_PISO_ACTUAL.items.map(it => {
        const pct = cmAvanceItem(it);
        let cls = 'cm-chip';
        if (CM_ITEM_ACTUAL && CM_ITEM_ACTUAL.id === it.id) cls += ' actual';
        else if (pct >= 100) cls += ' ok';
        else if (pct > 0) cls += ' proceso';
        const marca = pct >= 100 ? ' ✓' : (pct > 0 ? ' ' + pct + '%' : '');
        return '<span class="' + cls + '" onclick="cmMostrarItemId(\'' + it.id + '\')">'
            + it.titulo + marca + '</span>';
    }).join('') + '</div>';
}

function cmMostrarItemId(id) {
    const it = CM_PISO_ACTUAL.items.find(x => x.id === id);
    if (it) cmMostrarItem(it);
}

/* ---- Pintar el panel de UN item ---- */
function cmMostrarItem(item) {
    CM_ITEM_ACTUAL = item;
    const panel = document.getElementById('cm-panel');
    panel.classList.remove('cm-hidden');
    document.getElementById('cm-cargando').classList.add('cm-hidden');

    if (!item) { panel.innerHTML = ''; return; }

    // Por simplicidad de campo se trabaja el PRIMER subitem pendiente
    // (o el primero si todos están listos). El resto se ve en los chips.
    const sub = item.subitems.find(s => (s.avance || 0) < 100) || item.subitems[0];

    const fase = f => {
        if (f === 'antes')   return sub.fotosAntes > 0 ? 'ok' : '';
        if (f === 'durante') return sub.tieneFotoDurante ? 'ok' : 'activa';
        if (f === 'despues') return (sub.avance >= 100) ? 'activa' : '';
        return '';
    };

    let control;
    if (!CM_PUEDE) {
        control = '<div class="cm-barra-piso"><div style="width:' + (sub.avance||0) + '%;background:'
            + cmColor(sub.avance||0) + ';"></div></div>';
    } else if (sub.tieneFotoDurante) {
        control =
            '<div class="cm-slider-wrap" id="cm-slider">'
            + '<div class="cm-slider-row"><span>Avance de este trabajo</span>'
            + '<b id="cm-pct">' + (sub.avance||0) + '%</b></div>'
            + '<input type="range" class="cm-range" min="0" max="100" step="5" value="' + (sub.avance||0) + '" '
            + 'oninput="cmMoverSlider(this.value)" onchange="cmGuardar(this.value)">'
            + ((sub.avance >= 100 && sub.nivel === 'area_comun')
                ? '<button class="cm-btn-sec" style="width:100%;margin-top:10px;" onclick="cmFoto(\'despues\')">'
                  + '<i class="bi bi-check-circle"></i> Subir foto del después</button>'
                : '')
            + '</div>';
    } else {
        control =
            '<button class="cm-btn-cam" onclick="cmFoto(\'durante\')">'
            + '<i class="bi bi-camera"></i> Tomar foto del durante</button>';
    }

    panel.innerHTML =
        '<div class="cm-card">'
        + cmChips()
        + '<div style="padding:14px;">'
        + '<div style="font-size:13px;color:#5b6478;">' + item.titulo + '</div>'
        + '<div style="font-size:18px;font-weight:700;color:#2a3140;margin-bottom:4px;">' + sub.titulo + '</div>'

        + '<div class="cm-fases">'
        + '<div class="cm-fase ' + fase('antes') + '"><i class="bi bi-image"></i>'
        + '<div class="cm-fase-txt">Antes' + (sub.fotosAntes>0?' ✓':'') + '</div></div>'
        + '<div class="cm-fase ' + fase('durante') + '"><i class="bi bi-camera"></i>'
        + '<div class="cm-fase-txt">Durante' + (sub.tieneFotoDurante?' ✓':'') + '</div></div>'
        + '<div class="cm-fase ' + fase('despues') + '"><i class="bi bi-check-circle"></i>'
        + '<div class="cm-fase-txt">Después</div></div>'
        + '</div>'

        + (sub.fotosAntes > 0
            ? '<button class="cm-btn-sec" style="width:100%;margin-bottom:12px;" onclick="cmVerAntes()">'
              + '<i class="bi bi-image"></i> Ver foto(s) del antes (' + sub.fotosAntes + ')</button>'
            : '<div style="font-size:12px;color:#9aa1b4;margin-bottom:12px;"><i class="bi bi-image"></i> '
              + 'Sin foto del antes en el levantamiento</div>')

        + control

        + '<div class="cm-nav">'
        + '<button class="cm-btn-sec" onclick="cmSaltar()">Saltar</button>'
        + '<button class="cm-btn-prim" onclick="cmSiguiente()">Siguiente pendiente <i class="bi bi-arrow-right"></i></button>'
        + '</div>'

        + '</div></div>';

    CM_ITEM_ACTUAL._sub = sub;   // recordar el subitem en foco
}

/* ---- Slider: pintar en vivo ---- */
function cmMoverSlider(v) {
    const el = document.getElementById('cm-pct');
    if (el) { el.textContent = v + '%'; el.style.color = cmColor(+v); }
}

/* ---- Guardar avance (reusa los endpoints existentes) ---- */
async function cmGuardar(v) {
    const sub = CM_ITEM_ACTUAL._sub;
    const pct = parseInt(v);
    sub.avance = pct;

    const esArea = sub.nivel === 'area_comun';
    const url = esArea ? CM_URL + 'guardar_avance_area.php'
        : (sub.nivel === 'ambiente' ? CM_URL + 'guardar_avance_ambiente.php'
                                    : CM_URL + 'guardar_avance.php');
    const payload = esArea
        ? { area_comun_id: sub.refId, porcentaje: pct, edificio_id: CM_EDIF }
        : (sub.nivel === 'ambiente'
            ? { ambiente_id: sub.refId, porcentaje: pct, edificio_id: CM_EDIF }
            : { apartamento_id: sub.refId, porcentaje: pct, edificio_id: CM_EDIF });

    // Sin señal: se encola y sube al volver.
    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('avance', url, payload, 'Avance ' + sub.titulo + ' → ' + pct + '%');
        cmToast('Guardado en el teléfono');
        cmActualizarPisoEnMemoria();
        return;
    }
    try {
        const res = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload) });
        const d = await res.json();
        if (!d.ok) { cmToast(d.mensaje || 'No se pudo guardar'); return; }
        if (typeof d.avance_edificio === 'number') {
            document.getElementById('cm-avance-gen').textContent = d.avance_edificio + '%';
        }
        cmToast('✓ Guardado');
        cmActualizarPisoEnMemoria();
        // Si llegó a 100 y es área, repintar para ofrecer el "después".
        if (pct >= 100 && esArea) cmMostrarItem(CM_ITEM_ACTUAL);
    } catch (e) {
        if (window.ObrasOffline) {
            await ObrasOffline.encolar('avance', url, payload, 'Avance ' + sub.titulo);
            cmToast('Guardado en el teléfono');
        } else { cmToast('Sin conexión'); }
    }
}

/* Refresca chips y barra del piso sin recargar del servidor. */
function cmActualizarPisoEnMemoria() {
    const panel = document.getElementById('cm-panel');
    const chips = panel.querySelector('.cm-chips');
    if (chips) chips.outerHTML = cmChips();
    cmPintarPisos();
}

/* ---- Fotos: elegir cámara o galería ---- */
function cmFoto(parte) {
    const sub = CM_ITEM_ACTUAL._sub;
    CM_FOTO_DEST = { nivel: sub.nivel, refId: sub.refId, parte: parte };
    // En campo casi siempre es cámara: se dispara directo.
    document.getElementById('cm-file-cam').click();
}
function cmVerAntes() {
    const sub = CM_ITEM_ACTUAL._sub;
    if (sub.nivel === 'ambiente') {
        window.open(CM_URL + 'listar_fotos_ambiente.php?ambiente=' + sub.refId, '_blank');
    } else {
        cmToast('Las fotos del antes se ven en la ficha completa');
    }
}

/* ---- Subir la foto elegida (comprime + respalda + sube) ---- */
async function cmOnFoto(input) {
    if (!input.files || !input.files[0] || !CM_FOTO_DEST) { input.value=''; return; }
    let archivo = input.files[0];
    const d = CM_FOTO_DEST;
    input.value = '';

    try { archivo = await cmComprimir(archivo); } catch (e) { /* sube sin comprimir */ }

    // Respaldo inmediato en el teléfono.
    let idLocal = null;
    if (window.ObrasFotos) {
        idLocal = await ObrasFotos.respaldar(archivo, {
            inspeccion_id: CM_INSP, nivel: d.nivel, ref_id: d.refId,
            parte: d.parte, descripcion: d.parte + ' · ' + d.nivel + ' #' + d.refId,
        });
    }

    const fd = new FormData();
    fd.append('nivel', d.nivel);
    fd.append('ref_id', d.refId);
    fd.append('parte', d.parte);
    fd.append('foto', archivo);

    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('foto', CM_URL + 'subir_foto_rec.php',
            { nivel: d.nivel, ref_id: d.refId, parte: d.parte,
              foto: archivo, nombre_archivo: archivo.name || 'foto.jpg' },
            'Foto ' + d.parte);
        cmMarcarFoto(d);
        cmToast('Foto guardada en el teléfono');
        return;
    }

    cmToast('Subiendo foto…');
    try {
        const res = await fetch(CM_URL + 'subir_foto_rec.php', { method:'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { cmToast(data.mensaje || 'No se pudo subir'); return; }
        if (idLocal && window.ObrasFotos) ObrasFotos.marcarSubida(idLocal);
        cmMarcarFoto(d);
        cmToast('✓ Foto lista');
    } catch (e) {
        if (window.ObrasOffline) {
            await ObrasOffline.encolar('foto', CM_URL + 'subir_foto_rec.php',
                { nivel: d.nivel, ref_id: d.refId, parte: d.parte,
                  foto: archivo, nombre_archivo: archivo.name || 'foto.jpg' }, 'Foto ' + d.parte);
            cmMarcarFoto(d);
            cmToast('Foto guardada en el teléfono');
        } else { cmToast('Sin conexión'); }
    }
}

/* Marca en memoria que ya hay foto y repinta el item. */
function cmMarcarFoto(d) {
    const sub = CM_ITEM_ACTUAL._sub;
    if (d.parte === 'durante') { sub.tieneFotoDurante = true; sub.fotosDurante = (sub.fotosDurante||0)+1; }
    if (d.parte === 'despues') { sub.fotosDespues = (sub.fotosDespues||0)+1; }
    cmMostrarItem(CM_ITEM_ACTUAL);
}

/* ---- Navegación ---- */
function cmSiguiente() {
    const prox = cmSiguientePendiente(CM_ITEM_ACTUAL);
    if (prox) { cmMostrarItem(prox); window.scrollTo({top:0,behavior:'smooth'}); }
    else cmPisoListo();
}
function cmSaltar() {
    const prox = cmSiguientePendiente(CM_ITEM_ACTUAL);
    if (prox && prox.id !== CM_ITEM_ACTUAL.id) cmMostrarItem(prox);
    else cmToast('No hay más pendientes en este piso');
}
function cmPisoListo() {
    const panel = document.getElementById('cm-panel');
    panel.innerHTML = '<div class="cm-card"><div class="cm-vacio">'
        + '<i class="bi bi-check-circle"></i>'
        + '<div style="font-size:17px;font-weight:700;color:#2a3140;margin-top:8px;">¡Piso completo!</div>'
        + '<div style="margin-top:4px;">Ya no queda nada pendiente en ' + CM_PISO_ACTUAL.etiqueta + '.</div>'
        + '<div style="margin-top:14px;">Elija otro piso arriba para seguir.</div>'
        + '</div></div>';
    cmPintarPisos();
}

/* ---- Compresión de foto (espejo de comprimirFoto de levantamiento) ---- */
async function cmComprimir(archivo) {
    if (!archivo || !archivo.type || archivo.type.indexOf('image/') !== 0) return archivo;
    const MAX = 1600, CALIDAD = 0.72;
    const dataUrl = await new Promise((res, rej) => {
        const r = new FileReader(); r.onload = () => res(r.result); r.onerror = rej;
        r.readAsDataURL(archivo);
    });
    const img = await new Promise((res, rej) => {
        const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = dataUrl;
    });
    let { width, height } = img;
    if (width > MAX || height > MAX) {
        if (width > height) { height = Math.round(height * MAX / width); width = MAX; }
        else { width = Math.round(width * MAX / height); height = MAX; }
    }
    const canvas = document.createElement('canvas');
    canvas.width = width; canvas.height = height;
    canvas.getContext('2d').drawImage(img, 0, 0, width, height);
    const blob = await new Promise(res => {
        try { canvas.toBlob(res, 'image/jpeg', CALIDAD); } catch (e) { res(null); }
    });
    if (!blob) return archivo;
    return new File([blob], (archivo.name || 'foto').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
}

/* ---- Arranque ---- */
async function cmCargar() {
    try {
        const res = await fetch(CM_URL + 'arbol_avance.php?inspeccion=' + CM_INSP);
        const d = await res.json();
        if (!d.ok) { document.getElementById('cm-cargando').innerHTML =
            '<div style="color:#A61C1C;">' + (d.mensaje || 'No se pudo cargar.') + '</div>'; return; }
        CM_ARBOL = d;
        CM_PISOS = cmNormalizar(d);
        document.getElementById('cm-avance-gen').textContent = (d.avance_edificio || 0) + '%';
        document.getElementById('cm-cargando').classList.add('cm-hidden');
        cmPintarPisos();
        // Abrir el primer piso que tenga pendientes.
        const primero = CM_PISOS.find(p => p.items.some(cmPendiente)) || CM_PISOS[0];
        if (primero) cmElegirPiso(primero.id);
        else document.getElementById('cm-panel').innerHTML =
            '<div class="cm-card"><div class="cm-vacio"><i class="bi bi-check-circle"></i>'
            + '<div style="font-size:17px;font-weight:700;margin-top:8px;">Todo al día</div>'
            + '<div style="margin-top:4px;">No hay trabajos pendientes en este edificio.</div></div></div>';
    } catch (e) {
        document.getElementById('cm-cargando').innerHTML =
            '<div style="color:#A61C1C;">Error al cargar. Revise su conexión.</div>';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('cm-file-cam').addEventListener('change', function () { cmOnFoto(this); });
    document.getElementById('cm-file-gal').addEventListener('change', function () { cmOnFoto(this); });
    cmCargar();
});
