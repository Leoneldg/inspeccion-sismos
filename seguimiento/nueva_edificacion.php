<?php
/**
 * NUEVA EDIFICACIÓN — para las que aparecen en campo y no estaban
 * en el listado original.
 *
 * Solo lo esencial: ubicación, nombre, etiqueta y decisión.
 * El levantamiento técnico completo se hace después, desde la ficha.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/territorial.php';
require_once __DIR__ . '/../includes/seguimiento.php';

requierePermiso('seguimiento', 'editar');

// Parroquias que puede usar: las suyas, o todas si no está limitado.
$misParroquias = parroquiasDelUsuario();
$estadoUsr = estadoDelUsuario() ?: 'Distrito Capital';

$parroquiasDisp = $misParroquias;
if (!$parroquiasDisp) {
    // Sin restricción: se ofrecen las del mapa.
    $geo = @file_get_contents(__DIR__ . '/../assets/geo/parroquias/distrito_capital.geojson');
    if ($geo && ($j = json_decode($geo, true))) {
        foreach ($j['features'] ?? [] as $f) {
            $p = $f['properties']['parroquia'] ?? '';
            if ($p !== '') $parroquiasDisp[] = $p;
        }
        sort($parroquiasDisp);
    }
}

$decisiones = catalogoDecisionFinal();

$pageTitle    = 'Agregar edificación';
$pageSubtitle = 'Para las que no estaban en el listado';
$activeModule = 'seguimiento';
include __DIR__ . '/../includes/header.php';
?>
<style>
.ne-card { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(20,30,60,.07);
           padding:20px 22px; margin-bottom:16px; }
.ne-tit { font-weight:700; color:#22366F; font-size:15px; display:flex; align-items:center;
          gap:8px; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid #eef0f5; }
.ne-fila { display:flex; gap:12px; flex-wrap:wrap; }
.ne-fila .field { flex:1; min-width:190px; }
.ne-dec { display:flex; gap:9px; flex-wrap:wrap; }
.ne-dec label { flex:1; min-width:150px; border:2px solid #e5e8f0; border-radius:10px;
                padding:12px 14px; cursor:pointer; display:flex; align-items:center; gap:10px;
                font-size:13.5px; font-weight:600; transition:all .15s; }
.ne-dec label:hover { background:#f7f9fd; }
.ne-dec input { width:20px; height:20px; flex-shrink:0; }
.ne-dec .letra { display:inline-flex; align-items:center; justify-content:center;
                 width:26px; height:26px; border:2px solid; border-radius:6px;
                 font-weight:800; font-size:13px; flex-shrink:0; }
.ne-mapa { height:280px; border-radius:10px; border:1px solid #dbe0ec; }
@media (max-width: 640px) {
    .ne-card { padding:16px 15px; }
    .ne-fila .field { flex:1 1 100%; }
    .ne-dec label { flex:1 1 100%; }
}
</style>

<form id="form-nueva" onsubmit="return guardarNueva(event)">

  <!-- UBICACIÓN -->
  <div class="ne-card">
    <div class="ne-tit"><i class="bi bi-geo-alt-fill"></i> Ubicación</div>

    <div class="ne-fila">
      <div class="field">
        <label class="text-sm">Parroquia *</label>
        <select id="ne-parroquia" class="form-control" required>
          <option value="">— Seleccione —</option>
          <?php foreach ($parroquiasDisp as $p): ?>
          <option value="<?= e($p) ?>"><?= e(mb_strtoupper($p, 'UTF-8')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="text-sm">Municipio</label>
        <input type="text" id="ne-municipio" class="form-control" value="Libertador">
      </div>
    </div>

    <div class="field">
      <label class="text-sm">Dirección o punto de referencia *</label>
      <input type="text" id="ne-direccion" class="form-control" required
             placeholder="Av. Principal, sector, casa o edificio cercano…">
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px;">
      <button type="button" class="btn btn-primary" onclick="tomarUbicacion()">
        <i class="bi bi-crosshair"></i> <span id="ne-gps-txt">Usar mi ubicación</span>
      </button>
      <span id="ne-coords" class="text-sm text-muted"></span>
    </div>

    <div id="ne-mapa" class="ne-mapa"></div>
    <p class="text-sm text-muted" style="margin:6px 0 0;">
      Toque el mapa para ajustar la posición exacta de la edificación.
    </p>
    <input type="hidden" id="ne-lat"><input type="hidden" id="ne-lng">
  </div>

  <!-- EDIFICACIÓN -->
  <div class="ne-card">
    <div class="ne-tit"><i class="bi bi-building"></i> Edificación</div>
    <div class="ne-fila">
      <div class="field" style="flex:2;">
        <label class="text-sm">Nombre de la edificación *</label>
        <input type="text" id="ne-nombre" class="form-control" required
               placeholder="Residencias, quinta, casa…">
      </div>
      <div class="field">
        <label class="text-sm">Uso</label>
        <select id="ne-uso" class="form-control">
          <?php foreach (catalogoUsoEdificacion() as $u): ?>
          <option value="<?= e($u) ?>" <?= $u === 'Vivienda Multifamiliar' ? 'selected' : '' ?>><?= e($u) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="ne-fila">
      <div class="field">
        <label class="text-sm">Pisos</label>
        <input type="number" id="ne-pisos" class="form-control" min="1" max="200" value="1">
      </div>
      <div class="field">
        <label class="text-sm">Familias</label>
        <input type="number" id="ne-familias" class="form-control" min="0" value="0">
      </div>
      <div class="field">
        <label class="text-sm">Personas</label>
        <input type="number" id="ne-personas" class="form-control" min="0" value="0">
      </div>
    </div>
  </div>

  <!-- ETIQUETA -->
  <div class="ne-card">
    <div class="ne-tit"><i class="bi bi-tag-fill"></i> Etiqueta</div>
    <p class="text-sm text-muted" style="margin:-6px 0 10px;">
      Tome la foto de la etiqueta pegada en la fachada, si la tiene.
    </p>

    <div id="ne-bloque-etiqueta">
      <button type="button" class="btn btn-outline" onclick="fotoEtiquetaNueva()">
        <i class="bi bi-camera"></i> Foto de la etiqueta
      </button>
      <div id="ne-etiqueta-fotos" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;"></div>
    </div>

    <label class="check-row" style="display:flex;align-items:flex-start;gap:9px;background:#f7f9fd;
           border-radius:9px;padding:11px 13px;margin-top:10px;cursor:pointer;">
      <input type="checkbox" id="ne-sin-etiqueta" style="margin-top:2px;" onchange="onNeSinEtiqueta(this)">
      <span>
        <span style="font-weight:600;color:#2a3140;font-size:14px;">Esta edificación no tiene etiqueta</span>
        <span style="display:block;font-size:12.5px;color:#5b6478;margin-top:2px;">
          Marque si no encontró la etiqueta en la fachada.
        </span>
      </span>
    </label>

    <div id="ne-motivo" style="display:none;margin-top:10px;">
      <select id="ne-etiqueta-motivo" class="form-control">
        <option value="">— Motivo (opcional) —</option>
        <option value="No fue colocada">Nunca fue colocada</option>
        <option value="Se desprendió">Se desprendió o se perdió</option>
        <option value="Ilegible">Está pero ilegible</option>
        <option value="Fachada inaccesible">No se pudo acceder a la fachada</option>
        <option value="Edificación derrumbada">La edificación está derrumbada</option>
        <option value="Otro">Otro motivo</option>
      </select>
    </div>
  </div>

  <!-- DECISIÓN -->
  <div class="ne-card">
    <div class="ne-tit"><i class="bi bi-clipboard-check-fill"></i> Decisión</div>
    <p class="text-sm text-muted" style="margin:-6px 0 12px;">
      Clasificación de la edificación según lo observado.
    </p>
    <div class="ne-dec">
      <?php
      $orden = [
          'Acceso Restringido - Precaución al Entrar',
          'Edificación Insegura - Acceso No Permitido',
          'Edificación Inspeccionada - Acceso Permitido',
          'Derrumbado',
      ];
      foreach ($orden as $dec):
          if ($dec !== 'Derrumbado' && !isset($decisiones[$dec])) continue;
          $sim = recSimboloDecision($dec);
          $corto = $decisiones[$dec]['corto'] ?? 'Derrumbado';
      ?>
      <label id="lbl-<?= $sim['letra'] ?>">
        <input type="radio" name="ne_decision" value="<?= e($dec) ?>" required
               onchange="marcarDecision('<?= $sim['letra'] ?>', '<?= $sim['color'] ?>')">
        <span class="letra" style="color:<?= $sim['color'] ?>;border-color:<?= $sim['color'] ?>;">
          <?= $sim['letra'] ?>
        </span>
        <span><?= e($corto) ?></span>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="field" style="margin-top:14px;">
      <label class="text-sm">Observaciones</label>
      <textarea id="ne-obs" class="form-control" rows="2"
                placeholder="Daños observados, situación de las familias…"></textarea>
    </div>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px;">
    <button type="submit" class="btn btn-primary" style="flex:1;min-width:200px;justify-content:center;">
      <i class="bi bi-check2-circle"></i> Registrar edificación
    </button>
    <a href="<?= APP_URL_BASE ?>seguimiento/index.php" class="btn btn-outline">Cancelar</a>
  </div>
</form>

<input type="file" id="ne-file-camara" accept="image/*" capture="environment" style="display:none;" onchange="_neFoto(this)">
<input type="file" id="ne-file-galeria" accept="image/*" style="display:none;" onchange="_neFoto(this)">

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const URL_BASE = '<?= APP_URL_BASE ?>seguimiento/';
const ESTADO_USR = <?= json_encode($estadoUsr, JSON_UNESCAPED_UNICODE) ?>;

let _mapa = null, _marcador = null, _fotoEtiqueta = null;

// --- Mapa ---
(function initMapa() {
    _mapa = L.map('ne-mapa').setView([10.5061, -66.9146], 13);
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        { maxZoom: 19, attribution: 'Esri' }).addTo(_mapa);
    _mapa.on('click', e => ponerMarcador(e.latlng.lat, e.latlng.lng));
})();

function ponerMarcador(lat, lng) {
    if (_marcador) _mapa.removeLayer(_marcador);
    _marcador = L.marker([lat, lng]).addTo(_mapa);
    document.getElementById('ne-lat').value = lat.toFixed(6);
    document.getElementById('ne-lng').value = lng.toFixed(6);
    document.getElementById('ne-coords').innerHTML =
        '<i class="bi bi-check-circle-fill" style="color:#2E7D32;"></i> '
        + lat.toFixed(5) + ', ' + lng.toFixed(5);
}

function tomarUbicacion() {
    const txt = document.getElementById('ne-gps-txt');
    if (!navigator.geolocation) { alert('Este dispositivo no permite ubicación.'); return; }
    txt.textContent = 'Buscando…';
    navigator.geolocation.getCurrentPosition(
        pos => {
            const { latitude: la, longitude: lo } = pos.coords;
            ponerMarcador(la, lo);
            _mapa.setView([la, lo], 18);
            txt.textContent = 'Usar mi ubicación';
        },
        err => {
            txt.textContent = 'Usar mi ubicación';
            alert('No se pudo obtener la ubicación.\n\nActive el GPS o toque el mapa para marcar el punto.');
        },
        { enableHighAccuracy: true, timeout: 15000 }
    );
}

// --- Decisión ---
function marcarDecision(letra, color) {
    document.querySelectorAll('.ne-dec label').forEach(l => {
        l.style.borderColor = '#e5e8f0';
        l.style.background = '#fff';
    });
    const lbl = document.getElementById('lbl-' + letra);
    if (lbl) { lbl.style.borderColor = color; lbl.style.background = color + '10'; }
}

// --- Etiqueta ---
function onNeSinEtiqueta(chk) {
    document.getElementById('ne-bloque-etiqueta').style.display = chk.checked ? 'none' : '';
    document.getElementById('ne-motivo').style.display = chk.checked ? 'block' : 'none';
}

function fotoEtiquetaNueva() {
    const capa = document.createElement('div');
    capa.id = 'ne-origen';
    capa.style.cssText = 'position:fixed;inset:0;background:rgba(20,25,40,.6);z-index:2300;'
        + 'display:flex;align-items:flex-end;justify-content:center;';
    capa.innerHTML = '<div style="background:#fff;border-radius:14px 14px 0 0;width:100%;max-width:440px;padding:18px;">'
        + '<div style="font-weight:700;color:#22366F;font-size:16px;margin-bottom:14px;text-align:center;">'
        + '¿Cómo quiere agregar la foto?</div>'
        + '<button type="button" onclick="_neCamara()" style="width:100%;display:flex;align-items:center;gap:12px;'
        + 'background:#22366F;color:#fff;border:0;border-radius:10px;padding:14px 16px;font-size:15px;'
        + 'font-weight:600;cursor:pointer;margin-bottom:10px;"><i class="bi bi-camera-fill" style="font-size:22px;"></i>'
        + '<span style="flex:1;text-align:left;">Tomar foto ahora</span></button>'
        + '<button type="button" onclick="_neGaleria()" style="width:100%;display:flex;align-items:center;gap:12px;'
        + 'background:#fff;color:#22366F;border:2px solid #dbe0ec;border-radius:10px;padding:14px 16px;'
        + 'font-size:15px;font-weight:600;cursor:pointer;margin-bottom:10px;"><i class="bi bi-images" style="font-size:22px;"></i>'
        + '<span style="flex:1;text-align:left;">Elegir de la galería</span></button>'
        + '<button type="button" onclick="document.getElementById(\'ne-origen\').remove()" '
        + 'style="width:100%;background:transparent;border:0;color:#5b6478;padding:10px;font-size:14px;'
        + 'cursor:pointer;">Cancelar</button></div>';
    document.body.appendChild(capa);
}
function _neCamara()  { document.getElementById('ne-origen').remove(); document.getElementById('ne-file-camara').click(); }
function _neGaleria() { document.getElementById('ne-origen').remove(); document.getElementById('ne-file-galeria').click(); }

function _neFoto(input) {
    if (!input.files || !input.files[0]) return;
    _fotoEtiqueta = input.files[0];
    input.value = '';
    const cont = document.getElementById('ne-etiqueta-fotos');
    const url = URL.createObjectURL(_fotoEtiqueta);
    cont.innerHTML = '<div style="text-align:center;">'
        + '<img src="' + url + '" style="width:72px;height:72px;object-fit:cover;border-radius:8px;'
        + 'border:1px solid #d8dce6;">'
        + '<div style="font-size:11px;color:#2E7D32;font-weight:600;margin-top:3px;">'
        + '<i class="bi bi-check-circle-fill"></i> Lista</div></div>';
}

// --- Guardar ---
async function guardarNueva(ev) {
    ev.preventDefault();

    const lat = document.getElementById('ne-lat').value;
    const lng = document.getElementById('ne-lng').value;
    if (!lat || !lng) {
        alert('Marque la ubicación de la edificación.\n\nUse "Usar mi ubicación" o toque el mapa.');
        return false;
    }

    const dec = document.querySelector('input[name="ne_decision"]:checked');
    if (!dec) { alert('Indique la decisión sobre la edificación.'); return false; }

    const sinEtq = document.getElementById('ne-sin-etiqueta').checked;
    if (!sinEtq && !_fotoEtiqueta) {
        if (!confirm('No ha tomado la foto de la etiqueta.\n\n¿Continuar de todos modos?')) return false;
    }

    const datos = {
        parroquia:  document.getElementById('ne-parroquia').value,
        municipio:  document.getElementById('ne-municipio').value,
        estado:     ESTADO_USR,
        direccion:  document.getElementById('ne-direccion').value,
        latitud:    lat,
        longitud:   lng,
        nombre_edificio: document.getElementById('ne-nombre').value,
        uso_edificacion: document.getElementById('ne-uso').value,
        num_pisos:  document.getElementById('ne-pisos').value,
        numero_familias: document.getElementById('ne-familias').value,
        numero_personas: document.getElementById('ne-personas').value,
        decision_final: dec.value,
        observaciones: document.getElementById('ne-obs').value,
        sin_etiqueta: sinEtq ? 1 : 0,
        etiqueta_motivo: document.getElementById('ne-etiqueta-motivo').value,
    };

    const btn = ev.target.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Registrando…';

    // Sin señal: queda en cola con su foto.
    if (window.ObrasOffline && !navigator.onLine) {
        await ObrasOffline.encolar('avance', URL_BASE + 'guardar_nueva_edificacion.php', datos,
            'Nueva edificación · ' + datos.nombre_edificio);
        if (_fotoEtiqueta && window.ObrasFotos) {
            await ObrasFotos.respaldar(_fotoEtiqueta, {
                nivel: 'etiqueta_pendiente', parte: 'etiqueta', origen: 'camara',
                descripcion: 'Etiqueta · ' + datos.nombre_edificio,
            });
        }
        alert('Sin señal: la edificación quedó guardada en el teléfono.\n\n'
            + 'Se registrará al recuperar la conexión.');
        location.href = URL_BASE + 'index.php';
        return false;
    }

    try {
        const res = await fetch(URL_BASE + 'guardar_nueva_edificacion.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify(datos), credentials: 'same-origin'
        });
        const texto = await res.text();
        let d;
        try { d = JSON.parse(texto); }
        catch (e) {
            alert('El servidor respondió algo inesperado. Intente de nuevo.');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2-circle"></i> Registrar edificación';
            return false;
        }

        if (!d.ok) {
            alert(d.mensaje || 'No se pudo registrar.');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2-circle"></i> Registrar edificación';
            return false;
        }

        // Subir la foto de la etiqueta, si la hay.
        if (_fotoEtiqueta && d.edificio_id) {
            const fd = new FormData();
            fd.append('nivel', 'edificio');
            fd.append('ref_id', d.edificio_id);
            fd.append('parte', 'etiqueta');
            fd.append('foto', _fotoEtiqueta);
            try { await fetch(URL_BASE + 'subir_foto_rec.php', { method:'POST', body: fd, credentials:'same-origin' }); }
            catch (e) { /* la edificación ya quedó registrada */ }
        }

        alert('Edificación registrada con el código ' + d.codigo + '.');
        location.href = URL_BASE + 'remodelacion.php?inspeccion=' + d.inspeccion_id;

    } catch (e) {
        // Se cayó la señal: no perder lo llenado.
        if (window.ObrasOffline) {
            await ObrasOffline.encolar('avance', URL_BASE + 'guardar_nueva_edificacion.php', datos,
                'Nueva edificación · ' + datos.nombre_edificio);
            alert('Se perdió la conexión.\n\nLa edificación quedó guardada y se registrará después.');
            location.href = URL_BASE + 'index.php';
        } else {
            alert('Se perdió la conexión. Intente de nuevo.');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2-circle"></i> Registrar edificación';
        }
    }
    return false;
}

// Ubicación automática al abrir, para ahorrarle un paso al técnico.
setTimeout(tomarUbicacion, 600);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
