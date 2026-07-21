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
</body>
</html>
