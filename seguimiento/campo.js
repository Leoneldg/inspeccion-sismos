/* ===================================================================
 * MODO CAMPO · fase de intervención.
 *
 * Dos vistas sobre el mismo árbol:
 *   RESULTADOS · consulta. Indicadores y la tira antes/durante/después.
 *   REPORTAR   · captura. Piso → apartamento → ambiente → partidas.
 *
 * El porcentaje NUNCA se escribe: se calcula desde las partidas
 * reportadas, ponderando por metros cuadrados. ivRecalcular() es el
 * espejo exacto del cálculo de includes/intervencion.php, para que la
 * pantalla siga siendo correcta cuando se trabaja sin señal.
 * =================================================================== */

const IV = {
    arbol: null,
    vista: 'resultados',
    piso: 0,          // índice del piso elegido en Reportar
    cont: null,       // índice del contenedor (apartamento/grupo)
    esp: null,        // índice del espacio (ambiente/área/elemento)
    destino: null,    // partida + fase de la foto que se está subiendo
    abiertos: {},     // pisos desplegados en Resultados
};

/* ---------- Utilidades ---------- */
function ivColor(pct) {
    if (pct >= 100) return '#1D9E75';
    if (pct > 0)    return '#EF9F27';
    return '#C8CDDB';
}
function esc(t) {
    return String(t == null ? '' : t)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function ivToast(msg) {
    const t = document.getElementById('iv-toast');
    t.textContent = msg;
    t.classList.add('ver');
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('ver'), 2600);
}
function ivLupa(src) {
    document.getElementById('iv-lupa-img').src = src;
    document.getElementById('iv-lupa').style.display = 'flex';
}
function ivHoy() {
    const d = new Date();
    const p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
}
function ivFechaCorta(iso) {
    const p = String(iso || '').split('-');
    return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : iso;
}

/* ---------- Cálculo (espejo del servidor) ----------
 * Estado de una partida: 100 si tiene reporte "después", 50 si tiene
 * "durante", 0 si no tiene nada. El peso es sus m² (1 si no se midieron).
 * Los niveles superiores son promedios ponderados por ese peso.
 */
function ivRecalcular() {
    if (!IV.arbol) return;
    let pesoEd = 0, acumEd = 0, m2Tot = 0, m2Hecho = 0;
    const cuenta = { sin_iniciar: 0, en_proceso: 0, terminada: 0 };

    IV.arbol.pisos.forEach(pi => {
        let pesoP = 0, acumP = 0;
        pi.contenedores.forEach(co => {
            let pesoC = 0, acumC = 0;
            co.espacios.forEach(es => {
                let pesoE = 0, acumE = 0;
                es.partidas.forEach(pa => {
                    const hay = f => (pa.bitacora || []).some(b => b.fase === f);
                    pa.estado = hay('despues') ? 'terminada' : (hay('durante') ? 'en_proceso' : 'sin_iniciar');
                    pa.pct = pa.estado === 'terminada' ? 100 : (pa.estado === 'en_proceso' ? 50 : 0);
                    const peso = pa.m2 > 0 ? pa.m2 : 1;
                    pesoE += peso; acumE += peso * pa.pct;
                    m2Tot += pa.m2; m2Hecho += pa.m2 * pa.pct / 100;
                });
                es.avance = pesoE > 0 ? Math.round(acumE / pesoE) : 0;
                es.estado = es.avance >= 100 ? 'terminada' : (es.avance > 0 ? 'en_proceso' : 'sin_iniciar');
                cuenta[es.estado]++;
                pesoC += pesoE; acumC += acumE;
            });
            co.avance = pesoC > 0 ? Math.round(acumC / pesoC) : 0;
            pesoP += pesoC; acumP += acumC;
        });
        pi.avance = pesoP > 0 ? Math.round(acumP / pesoP) : 0;
        pesoEd += pesoP; acumEd += acumP;
    });

    IV.arbol.avance = pesoEd > 0 ? Math.round(acumEd / pesoEd) : 0;
    IV.arbol.espacios = {
        total: cuenta.sin_iniciar + cuenta.en_proceso + cuenta.terminada,
        sin_iniciar: cuenta.sin_iniciar,
        en_proceso: cuenta.en_proceso,
        terminados: cuenta.terminada,
    };
    IV.arbol.m2 = { total: Math.round(m2Tot * 100) / 100, intervenido: Math.round(m2Hecho * 100) / 100 };
    document.getElementById('iv-avance-gen').textContent = IV.arbol.avance + '%';
}

/* ---------- Cambio de vista ---------- */
function ivVista(v) {
    IV.vista = v;
    const tabR = document.getElementById('iv-tab-res');
    const tabP = document.getElementById('iv-tab-rep');
    if (tabR) tabR.classList.toggle('activa', v === 'resultados');
    if (tabP) tabP.classList.toggle('activa', v === 'reportar');
    document.getElementById('iv-resultados').classList.toggle('iv-oculto', v !== 'resultados');
    document.getElementById('iv-reportar').classList.toggle('iv-oculto', v !== 'reportar');
    if (v === 'resultados') ivPintarResultados(); else ivPintarReportar();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ===================================================================
 * VISTA 1 · RESULTADOS
 * =================================================================== */
function ivPintarResultados() {
    const cont = document.getElementById('iv-resultados');
    const a = IV.arbol;
    if (!a) return;

    if (a.sin_plan) {
        cont.innerHTML = '<div class="iv-card"><div class="iv-vacio">'
            + '<i class="bi bi-clipboard-x" style="color:#C9A227;"></i>'
            + '<div style="font-size:16px;font-weight:700;color:#2a3140;margin-top:10px;">Todavía no hay plan de trabajo</div>'
            + '<div style="margin-top:6px;font-size:13px;">El levantamiento técnico no dejó ningún ambiente '
            + 'marcado para reparar. Sin plan no hay nada que intervenir.</div></div></div>';
        return;
    }

    const e = a.espacios, m = a.m2;
    let h = '';

    // --- Avance general ---
    h += '<div class="iv-card" style="padding:16px 18px;">'
       + '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;">'
       + '<span style="font-size:13px;color:#5b6478;">Avance de la intervención</span>'
       + '<span style="font-size:26px;font-weight:800;color:' + ivColor(a.avance) + ';">' + a.avance + '%</span>'
       + '</div>'
       + '<div class="iv-barra iv-barra-alta"><div style="width:' + Math.max(a.avance, 3) + '%;background:'
       + ivColor(a.avance) + ';">' + (a.avance >= 12 ? a.avance + '%' : '') + '</div></div>'
       + '<div class="iv-nota"><i class="bi bi-calculator"></i> Calculado sobre '
       + m.total.toLocaleString('es-VE') + ' m² del plan. No se edita a mano: sube cuando se reportan partidas.</div>'
       + '</div>';

    // --- Indicadores ---
    h += '<div class="iv-card" style="padding:14px 15px;">'
       + '<div class="iv-kpis">'
       + '<div class="iv-kpi"><div class="n" style="color:#6b7285;">' + e.sin_iniciar + '</div><div class="t">Sin iniciar</div></div>'
       + '<div class="iv-kpi" style="background:#FDF3E7;"><div class="n" style="color:#A66A00;">' + e.en_proceso + '</div><div class="t">En proceso</div></div>'
       + '<div class="iv-kpi" style="background:#E7F4EC;"><div class="n" style="color:#2E7D32;">' + e.terminados + '</div><div class="t">Terminados</div></div>'
       + '</div>'
       + '<div class="iv-nota"><strong>' + e.terminados + ' de ' + e.total + '</strong> espacios a reparar terminados · '
       + m.intervenido.toLocaleString('es-VE') + ' de ' + m.total.toLocaleString('es-VE') + ' m² intervenidos</div>'
       + '</div>';

    // --- Detalle por piso ---
    a.pisos.forEach((pi, i) => {
        const abierto = !!IV.abiertos[i];
        h += '<div class="iv-card">'
           + '<div onclick="ivTogglePiso(' + i + ')" style="padding:13px 15px;cursor:pointer;'
           + 'display:flex;align-items:center;gap:11px;">'
           + '<i class="bi bi-chevron-' + (abierto ? 'down' : 'right') + '" style="color:#22366F;"></i>'
           + '<div style="flex:1;min-width:0;">'
           + '<div style="font-size:14.5px;font-weight:700;color:#22366F;">' + esc(pi.etiqueta) + '</div>'
           + '<div class="iv-barra" style="margin-top:6px;"><div style="width:' + pi.avance + '%;background:'
           + ivColor(pi.avance) + ';"></div></div>'
           + '</div>'
           + '<div class="iv-pct" style="color:' + ivColor(pi.avance) + ';">' + pi.avance + '%</div>'
           + '</div>';

        if (abierto) {
            h += '<div style="border-top:1px solid #eef0f5;">';
            pi.contenedores.forEach(co => {
                h += '<div style="padding:9px 15px 4px;font-size:11.5px;font-weight:700;color:#767c94;'
                   + 'text-transform:uppercase;letter-spacing:.4px;background:#fafbfe;">'
                   + esc(co.titulo) + ' · ' + co.avance + '%</div>';
                co.espacios.forEach(es => { h += ivBloqueEspacio(es); });
            });
            h += '</div>';
        }
        h += '</div>';
    });

    cont.innerHTML = h;
}

function ivTogglePiso(i) {
    IV.abiertos[i] = !IV.abiertos[i];
    ivPintarResultados();
}

/* Bloque de un espacio en Resultados: título, % y la tira de tres fases
 * con todas las fotos que existan de cada una. */
function ivBloqueEspacio(es) {
    const antes = [], durante = [], despues = [];
    (es.fotos_generales || []).forEach(f => antes.push(f));
    (es.partidas || []).forEach(pa => {
        (pa.fotos_antes || []).forEach(f => antes.push(f));
        (pa.bitacora || []).forEach(b => {
            (b.fotos || []).forEach(f => (b.fase === 'despues' ? despues : durante).push(f));
        });
    });

    const tira = (titulo, cls, fotos) =>
        '<div class="iv-fase ' + cls + '">'
        + '<div class="iv-fase-cab">' + titulo + '</div>'
        + '<div class="iv-fase-cuerpo">'
        + (fotos.length
            ? fotos.slice(0, 6).map(f => '<img src="' + esc(f.ruta) + '" alt="' + titulo
                + '" onclick="ivLupa(\'' + esc(f.ruta) + '\')">').join('')
            : '<div class="iv-fase-vacia">Sin fotos</div>')
        + '</div></div>';

    return '<div style="padding:12px 15px;border-bottom:1px solid #eef0f5;">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">'
        + '<div style="font-size:14px;font-weight:600;color:#2a3140;">' + esc(es.titulo) + '</div>'
        + '<div style="font-size:14px;font-weight:800;color:' + ivColor(es.avance) + ';">' + es.avance + '%</div>'
        + '</div>'
        + '<div class="iv-barra" style="margin-top:6px;"><div style="width:' + es.avance + '%;background:'
        + ivColor(es.avance) + ';"></div></div>'
        + '<div class="iv-fases">'
        + tira('Antes', 'antes', antes)
        + tira('Durante', 'durante', durante)
        + tira('Después', 'despues', despues)
        + '</div></div>';
}

/* ===================================================================
 * VISTA 2 · REPORTAR
 * =================================================================== */
function ivPintarReportar() {
    const cont = document.getElementById('iv-reportar');
    const a = IV.arbol;
    if (!a) return;

    if (!IV.PUEDE_REPORTAR) {
        cont.innerHTML = '<div class="iv-card"><div class="iv-vacio">'
            + '<i class="bi bi-lock" style="color:#C9A227;"></i>'
            + '<div style="font-size:15px;margin-top:10px;">Solo el sistematizador reporta la intervención.</div>'
            + '</div></div>';
        return;
    }
    if (a.sin_plan) {
        cont.innerHTML = '<div class="iv-card"><div class="iv-vacio">'
            + '<i class="bi bi-clipboard-x" style="color:#C9A227;"></i>'
            + '<div style="font-size:15px;margin-top:10px;">No hay partidas que reportar. '
            + 'Primero hay que completar el levantamiento técnico.</div></div></div>';
        return;
    }

    // Barra de pisos, siempre visible.
    let h = '<div class="iv-pisos">' + a.pisos.map((pi, i) => {
        const pend = pi.contenedores.reduce((n, co) =>
            n + co.espacios.filter(es => es.avance < 100).length, 0);
        return '<button type="button" class="iv-piso-btn' + (i === IV.piso ? ' activo' : '')
            + '" onclick="ivElegirPiso(' + i + ')">' + esc(pi.etiqueta)
            + '<span class="mini">' + (pend > 0 ? pend + ' por hacer' : 'completo') + '</span></button>';
    }).join('') + '</div>';

    const piso = a.pisos[IV.piso];
    if (!piso) { cont.innerHTML = h; return; }

    if (IV.cont === null)      h += ivListaContenedores(piso);
    else if (IV.esp === null)  h += ivListaEspacios(piso);
    else                       h += ivPanelPartidas(piso);

    cont.innerHTML = h;
}

function ivElegirPiso(i) { IV.piso = i; IV.cont = null; IV.esp = null; ivPintarReportar(); }
function ivAbrirCont(i)  { IV.cont = i; IV.esp = null; ivPintarReportar(); window.scrollTo({top:0,behavior:'smooth'}); }
function ivAbrirEsp(i)   { IV.esp = i; ivPintarReportar(); window.scrollTo({top:0,behavior:'smooth'}); }
function ivVolverCont()  { IV.cont = null; IV.esp = null; ivPintarReportar(); }
function ivVolverEsp()   { IV.esp = null; ivPintarReportar(); }

/* Nivel 1: apartamentos / locales / grupos del piso. */
function ivListaContenedores(piso) {
    return '<div class="iv-card">'
        + '<div class="iv-migas"><b>' + esc(piso.etiqueta) + '</b> · elija dónde trabajó</div>'
        + piso.contenedores.map((co, i) => {
            const listos = co.espacios.filter(es => es.avance >= 100).length;
            return '<div class="iv-fila" onclick="ivAbrirCont(' + i + ')">'
                + '<div class="iv-fila-info">'
                + '<div class="iv-fila-tit">' + esc(co.titulo) + '</div>'
                + '<div class="iv-fila-sub">' + listos + ' de ' + co.espacios.length + ' espacios terminados</div>'
                + '<div class="iv-barra" style="margin-top:6px;"><div style="width:' + co.avance
                + '%;background:' + ivColor(co.avance) + ';"></div></div>'
                + '</div>'
                + '<div class="iv-pct" style="color:' + ivColor(co.avance) + ';">' + co.avance + '%</div>'
                + '<i class="bi bi-chevron-right" style="color:#a3a9ba;"></i></div>';
        }).join('') + '</div>';
}

/* Nivel 2: ambientes / áreas / elementos del contenedor. */
function ivListaEspacios(piso) {
    const co = piso.contenedores[IV.cont];
    return '<div class="iv-card">'
        + '<div class="iv-migas">'
        + '<span onclick="ivVolverCont()" style="cursor:pointer;color:#22366F;"><i class="bi bi-arrow-left"></i></span>'
        + esc(piso.etiqueta) + ' · <b>' + esc(co.titulo) + '</b>'
        + '</div>'
        + co.espacios.map((es, i) => {
            const n = es.partidas.length;
            const fin = es.partidas.filter(p => p.estado === 'terminada').length;
            return '<div class="iv-fila" onclick="ivAbrirEsp(' + i + ')">'
                + '<div class="iv-fila-info">'
                + '<div class="iv-fila-tit">' + esc(es.titulo) + '</div>'
                + '<div class="iv-fila-sub">' + fin + ' de ' + n + ' partidas terminadas</div>'
                + '<div class="iv-barra" style="margin-top:6px;"><div style="width:' + es.avance
                + '%;background:' + ivColor(es.avance) + ';"></div></div>'
                + '</div>'
                + '<div class="iv-pct" style="color:' + ivColor(es.avance) + ';">' + es.avance + '%</div>'
                + '<i class="bi bi-chevron-right" style="color:#a3a9ba;"></i></div>';
        }).join('') + '</div>';
}

/* Nivel 3: las partidas del espacio. Aquí se reporta. */
function ivPanelPartidas(piso) {
    const co = piso.contenedores[IV.cont];
    const es = co.espacios[IV.esp];

    let h = '<div class="iv-card">'
        + '<div class="iv-migas">'
        + '<span onclick="ivVolverEsp()" style="cursor:pointer;color:#22366F;"><i class="bi bi-arrow-left"></i></span>'
        + esc(piso.etiqueta) + ' · ' + esc(co.titulo) + ' · <b>' + esc(es.titulo) + '</b>'
        + '</div>'
        + '<div style="padding:13px 15px;border-bottom:1px solid #eef0f5;">'
        + '<div style="display:flex;justify-content:space-between;align-items:baseline;">'
        + '<span style="font-size:12.5px;color:#5b6478;">Avance de este espacio</span>'
        + '<span style="font-size:20px;font-weight:800;color:' + ivColor(es.avance) + ';">' + es.avance + '%</span>'
        + '</div>'
        + '<div class="iv-barra" style="margin-top:7px;"><div style="width:' + es.avance + '%;background:'
        + ivColor(es.avance) + ';"></div></div>'
        + '<div class="iv-nota"><i class="bi bi-lock"></i> Lo calcula el sistema según las partidas que usted '
        + 'reporte y los metros de cada una.</div>'
        + '</div>';

    es.partidas.forEach((pa, i) => { h += ivBloquePartida(es, pa, i); });
    return h + '</div>';
}

/* Una partida: qué hay que hacer, en qué estado está, sus fotos y los
 * botones para reportar. */
function ivBloquePartida(es, pa, idx) {
    const badge = pa.estado === 'terminada'
        ? '<span class="iv-badge fin"><i class="bi bi-check-circle"></i> Terminada</span>'
        : (pa.estado === 'en_proceso'
            ? '<span class="iv-badge proc">En proceso</span>'
            : '<span class="iv-badge sin">Sin iniciar</span>');

    const durante = [], despues = [];
    (pa.bitacora || []).forEach(b => (b.fotos || []).forEach(f => {
        (b.fase === 'despues' ? despues : durante).push(f);
    }));

    const tira = (titulo, cls, fotos, borrable) =>
        '<div class="iv-fase ' + cls + '">'
        + '<div class="iv-fase-cab">' + titulo + '</div>'
        + '<div class="iv-fase-cuerpo">'
        + (fotos.length
            ? fotos.slice(0, 6).map(f => '<img src="' + esc(f.ruta) + '" alt="' + titulo + '"'
                + ' onclick="' + (borrable && f.id
                    ? 'ivBorrarFoto(' + f.id + ')'
                    : 'ivLupa(\'' + esc(f.ruta) + '\')') + '">').join('')
            : '<div class="iv-fase-vacia">Sin fotos</div>')
        + '</div></div>';

    const yaDespues = pa.estado === 'terminada';

    let h = '<div class="iv-partida">'
        + '<div class="iv-partida-top">'
        + '<div style="flex:1;min-width:0;">'
        + '<div class="iv-partida-nom">' + esc(pa.trabajo_txt) + '</div>'
        + '<div class="iv-partida-sub">' + esc(pa.superficie_txt)
        + (pa.m2 > 0 ? ' · ' + pa.m2.toLocaleString('es-VE') + ' m²' : ' · sin metros registrados')
        + '</div></div>' + badge + '</div>'

        + '<div class="iv-fases">'
        + tira('Antes', 'antes', pa.fotos_antes || [], false)
        + tira('Durante', 'durante', durante, true)
        + tira('Después', 'despues', despues, true)
        + '</div>'

        + '<div class="iv-acciones">'
        + '<button type="button" class="iv-btn iv-btn-dur" onclick="ivFoto(' + idx + ',\'durante\')">'
        + '<i class="bi bi-camera"></i> Durante</button>'
        + '<button type="button" class="iv-btn iv-btn-des" onclick="ivFoto(' + idx + ',\'despues\')">'
        + '<i class="bi bi-check2-circle"></i> Después</button>'
        + '</div>';

    if (yaDespues) {
        h += '<button type="button" class="iv-btn-txt" onclick="ivDeshacer(' + idx + ')">'
           + 'Se cerró por error: reabrir esta partida</button>';
    }

    // Bitácora de la partida.
    if ((pa.bitacora || []).length) {
        h += '<div class="iv-bit">';
        pa.bitacora.forEach(b => {
            h += '<div class="iv-bit-item">'
               + '<span class="iv-bit-fecha">' + esc(b.fecha_txt || ivFechaCorta(b.fecha)) + '</span>'
               + '<span>' + (b.fase === 'despues' ? 'Después' : 'Durante')
               + ' · ' + (b.fotos || []).length + ' foto' + ((b.fotos || []).length === 1 ? '' : 's')
               + (b.obs ? ' · ' + esc(b.obs) : '')
               + (b.autor ? ' · ' + esc(b.autor) : '')
               + (b.pendiente ? ' · <i class="bi bi-cloud-arrow-up"></i> por subir' : '')
               + '</span></div>';
        });
        h += '</div>';
    }

    return h + '</div>';
}

/* ---------- Partida abierta en el panel, por posición ----------
 * Se usa el índice y no la clave: la clave lleva el tipo de trabajo, que
 * es texto libre en algunas instalaciones, y meterlo dentro de un
 * onclick obliga a escaparlo dos veces (HTML y JavaScript). El índice
 * siempre es un número. */
function ivPartidaActual(idx) {
    const piso = IV.arbol.pisos[IV.piso];
    if (!piso || IV.cont === null || IV.esp === null) return null;
    const co = piso.contenedores[IV.cont];
    if (!co) return null;
    const es = co.espacios[IV.esp];
    if (!es || !es.partidas[idx]) return null;
    return { es: es, pa: es.partidas[idx] };
}

/* ===================================================================
 * CAPTURA DE FOTO
 * =================================================================== */
function ivFoto(idx, fase) {
    const r = ivPartidaActual(idx);
    if (!r) { ivToast('No se encontró la partida.'); return; }
    IV.destino = { fase: fase, pa: r.pa };
    document.getElementById('iv-file').click();
}

async function ivOnFoto(input) {
    if (!input.files || !input.files[0] || !IV.destino) { input.value = ''; return; }
    let archivo = input.files[0];
    const d = IV.destino;
    input.value = '';

    try { archivo = await ivComprimir(archivo); } catch (e) { /* se sube tal cual */ }

    // Respaldo en el teléfono antes de intentar nada: una foto de cámara
    // no queda en la galería y se perdería si falla la subida.
    let idLocal = null;
    if (window.ObrasFotos) {
        try {
            idLocal = await ObrasFotos.respaldar(archivo, {
                inspeccion_id: IV_INSP, nivel: d.pa.nivel, ref_id: d.pa.ref_id,
                parte: d.fase,
                descripcion: d.fase + ' · ' + d.pa.trabajo_txt + ' · ' + d.pa.superficie_txt,
            });
        } catch (e) { /* seguir sin respaldo */ }
    }

    // La fecha se fija AQUÍ, no al sincronizar: si se trabaja el lunes
    // sin señal y sube el miércoles, la bitácora debe decir lunes.
    const datos = {
        inspeccion: IV_INSP,
        nivel: d.pa.nivel,
        ref_id: d.pa.ref_id,
        superficie: d.pa.superficie || '',
        trabajo: d.pa.trabajo || '',
        fase: d.fase,
        fecha: ivHoy(),
    };

    const url = IV_URL + 'intervencion_foto.php';
    const rotulo = (d.fase === 'despues' ? 'Después' : 'Durante') + ' · ' + d.pa.trabajo_txt;

    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('foto', url,
            Object.assign({}, datos, { foto: archivo, nombre_archivo: archivo.name || 'foto.jpg' }),
            rotulo);
        ivAplicarLocal(d, archivo, true);
        ivToast('Sin señal: guardado en el teléfono');
        return;
    }

    const fd = new FormData();
    Object.keys(datos).forEach(k => fd.append(k, datos[k]));
    fd.append('foto', archivo);

    ivToast('Subiendo foto…');
    try {
        const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) { ivToast(data.mensaje || 'No se pudo guardar'); return; }
        if (idLocal && window.ObrasFotos) ObrasFotos.marcarSubida(idLocal);
        ivAplicarLocal(d, archivo, false, data.foto);
        ivToast('Reporte guardado');
    } catch (e) {
        if (window.ObrasOffline) {
            await ObrasOffline.encolar('foto', url,
                Object.assign({}, datos, { foto: archivo, nombre_archivo: archivo.name || 'foto.jpg' }),
                rotulo);
            ivAplicarLocal(d, archivo, true);
            ivToast('Sin señal: guardado en el teléfono');
        } else {
            ivToast('No hay conexión y este navegador no puede guardar la foto.');
        }
    }
}

/* Refleja el reporte en pantalla sin esperar al servidor. Mantiene la
 * regla del día: si ya hay asiento de esa fase hoy, la foto se le suma. */
function ivAplicarLocal(destino, archivo, pendiente, fotoServidor) {
    const pa = destino.pa;
    const hoy = ivHoy();
    pa.bitacora = pa.bitacora || [];

    let asiento = pa.bitacora.find(b => b.fase === destino.fase && b.fecha === hoy);
    if (!asiento) {
        asiento = { fase: destino.fase, fecha: hoy, fecha_txt: ivFechaCorta(hoy),
                    obs: '', autor: '', fotos: [], pendiente: !!pendiente };
        pa.bitacora.unshift(asiento);
    }
    if (pendiente) asiento.pendiente = true;

    asiento.fotos.push(fotoServidor && fotoServidor.ruta
        ? { id: fotoServidor.id, ruta: fotoServidor.ruta, hora: fotoServidor.hora }
        : { id: null, ruta: URL.createObjectURL(archivo), hora: '' });

    ivRecalcular();
    if (IV.vista === 'reportar') ivPintarReportar(); else ivPintarResultados();
}

/* ---------- Deshacer un "después" cargado por error ---------- */
async function ivDeshacer(idx) {
    const r = ivPartidaActual(idx);
    if (!r) return;
    if (!confirm('Se borrarán las fotos del "después" de esta partida y volverá a quedar en proceso. ¿Continuar?')) return;

    const pa = r.pa;
    const fd = new FormData();
    fd.append('accion', 'deshacer');
    fd.append('nivel', pa.nivel);
    fd.append('ref_id', pa.ref_id);
    fd.append('superficie', pa.superficie || '');
    fd.append('trabajo', pa.trabajo || '');
    fd.append('fase', 'despues');

    try {
        const res = await fetch(IV_URL + 'intervencion_foto.php',
            { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await res.json();
        if (!d.ok) { ivToast(d.mensaje || 'No se pudo deshacer'); return; }
        pa.bitacora = (pa.bitacora || []).filter(b => b.fase !== 'despues');
        ivRecalcular();
        ivPintarReportar();
        ivToast('Partida reabierta');
    } catch (e) {
        ivToast('Necesita conexión para deshacer un reporte.');
    }
}

/* ---------- Borrar una foto suelta ---------- */
async function ivBorrarFoto(fotoId) {
    if (!fotoId) { ivToast('Esa foto todavía no ha subido.'); return; }
    if (!confirm('¿Eliminar esta foto del reporte?')) return;
    const fd = new FormData();
    fd.append('accion', 'eliminar_foto');
    fd.append('foto_id', fotoId);
    try {
        const res = await fetch(IV_URL + 'intervencion_foto.php',
            { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await res.json();
        if (!d.ok) { ivToast(d.mensaje || 'No se pudo eliminar'); return; }
        IV.arbol.pisos.forEach(pi => pi.contenedores.forEach(co => co.espacios.forEach(es =>
            es.partidas.forEach(pa => (pa.bitacora || []).forEach(b => {
                b.fotos = (b.fotos || []).filter(f => f.id !== fotoId);
            })))));
        ivRecalcular();
        ivPintarReportar();
        ivToast('Foto eliminada');
    } catch (e) {
        ivToast('Necesita conexión para eliminar una foto.');
    }
}

/* ---------- Compresión en el teléfono ---------- */
async function ivComprimir(archivo) {
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

/* ---------- Arranque ---------- */
async function ivCargar() {
    const cargando = document.getElementById('iv-cargando');
    try {
        const res = await fetch(IV_URL + 'intervencion_arbol.php?inspeccion=' + IV_INSP,
            { credentials: 'same-origin' });
        const d = await res.json();
        if (!d.ok) {
            cargando.innerHTML = '<div style="color:#A61C1C;">' + esc(d.mensaje || 'No se pudo cargar.') + '</div>';
            return;
        }
        IV.arbol = d;
        IV.PUEDE_REPORTAR = d.puede_reportar && IV_PUEDE;

        // Abrir por defecto el primer piso con trabajo pendiente.
        const i = d.pisos.findIndex(pi =>
            pi.contenedores.some(co => co.espacios.some(es => es.avance < 100)));
        IV.piso = i >= 0 ? i : 0;
        IV.abiertos[IV.piso] = true;

        ivRecalcular();
        cargando.classList.add('iv-oculto');
        ivVista('resultados');
    } catch (e) {
        cargando.innerHTML = '<div style="color:#A61C1C;">Error al cargar. Revise su conexión.</div>';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('iv-file').addEventListener('change', function () { ivOnFoto(this); });
    ivCargar();
});
