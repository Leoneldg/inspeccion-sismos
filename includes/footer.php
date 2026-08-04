        </div>
    </div>
</div>

<!-- Modal de código QR (compartido: lista de inspecciones, ficha, y al terminar de crear una inspección) -->
<div id="modal-qr" class="modal-overlay" style="display:none;">
    <div class="modal-qr-box">
        <div class="modal-head">
            <div>
                <h3>Código QR de la inspección</h3>
                <span class="codigo" id="qr-codigo-texto"></span>
            </div>
            <button class="modal-close" onclick="cerrarModalQR()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" style="text-align:center;">
            <div id="qr-canvas-wrap"><canvas id="qr-canvas"></canvas></div>
            <p class="text-sm text-muted" style="margin-top:12px;">Al escanearlo se abre el PDF de esta inspección, sin necesidad de iniciar sesión.</p>
            <p class="text-sm text-muted" id="qr-url-texto"></p>
            <div class="flex gap-8" style="justify-content:center;margin-top:14px;flex-wrap:wrap;">
                <button type="button" class="btn btn-primary btn-sm" onclick="imprimirQR()"><i class="bi bi-printer-fill"></i> Imprimir</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="descargarQR()"><i class="bi bi-download"></i> Descargar como imagen</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>window._APP_URL_BASE = '<?= APP_URL_BASE ?>';
window._USER_ID = <?= (int)($_SESSION['user_id'] ?? 0) ?>;</script>
<script src="<?= APP_URL_BASE ?>assets/js/offline.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= APP_URL_BASE ?>assets/js/offline-cache.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= APP_URL_BASE ?>assets/js/mantener-sesion.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= APP_URL_BASE ?>assets/js/obras-fotos.js?v=<?= ASSET_VERSION ?>"></script>
<script>window.APP_URL_BASE = '<?= APP_URL_BASE ?>';</script>
<script src="<?= APP_URL_BASE ?>assets/js/obras-offline.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= APP_URL_BASE ?>assets/js/obras-catalogo.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= APP_URL_BASE ?>assets/js/main.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= APP_URL_BASE ?>assets/js/qr.js?v=<?= ASSET_VERSION ?>"></script>
<?php if (isset($_GET['embed']) && $_GET['embed'] == '1'): ?>
<script>
/* Modo embebido (dentro de una pestaña de fase): la página se carga sin
 * sidebar. Para que al navegar a otra ficha NO reaparezca la app completa
 * "en miniatura", propagamos ?embed=1 a todos los enlaces internos. */
(function () {
  function esInterno(url) {
    try {
      var u = new URL(url, window.location.href);
      return u.origin === window.location.origin;
    } catch (e) { return false; }
  }
  function agregarEmbed(url) {
    try {
      var u = new URL(url, window.location.href);
      if (!u.searchParams.has('embed')) u.searchParams.set('embed', '1');
      return u.pathname + u.search + u.hash;
    } catch (e) { return url; }
  }
  function procesar(a) {
    var href = a.getAttribute('href');
    if (!href) return;
    if (href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
    if (a.target && a.target !== '_self') return;      // PDFs que abren en pestaña nueva: se dejan
    if (a.hasAttribute('download')) return;
    if (!esInterno(href)) return;
    a.setAttribute('href', agregarEmbed(href));
  }
  function todos() { document.querySelectorAll('a[href]').forEach(procesar); }
  document.addEventListener('DOMContentLoaded', todos);
  // También al vuelo, por si el contenido se pinta con JS después.
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest && ev.target.closest('a[href]');
    if (a) procesar(a);
  }, true);
})();
</script>
<?php endif; ?>
</body>
</html>
