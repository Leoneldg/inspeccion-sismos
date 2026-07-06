// Modal de código QR — compartido entre la lista de inspecciones, la ficha
// (formulario/view.php) y el aviso automático al terminar de crear una
// inspección nueva. Depende de la librería "qrcode" (cargada antes que este
// archivo en includes/footer.php) y del markup de #modal-qr en ese mismo
// footer.

let qrDataActual = null; // { dataUrl, codigo, url } del QR mostrado actualmente

function abrirModalQR(url, codigo) {
    const modal = document.getElementById('modal-qr');
    const canvas = document.getElementById('qr-canvas');
    if (!modal || !canvas || typeof QRCode === 'undefined') return;

    document.getElementById('qr-codigo-texto').textContent = codigo || '';
    document.getElementById('qr-url-texto').textContent = url;
    qrDataActual = null;

    QRCode.toCanvas(canvas, url, { width: 260, margin: 1, color: { dark: '#0b1330', light: '#ffffff' } }, function (err) {
        if (err) {
            console.error('No se pudo generar el código QR:', err);
            return;
        }
        qrDataActual = { dataUrl: canvas.toDataURL('image/png'), codigo: codigo || '', url };
    });

    modal.style.display = 'flex';
}

function cerrarModalQR() {
    const modal = document.getElementById('modal-qr');
    if (modal) modal.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('modal-qr')?.addEventListener('click', function (e) {
        if (e.target === this) cerrarModalQR();
    });
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') cerrarModalQR();
});

function descargarQR() {
    if (!qrDataActual) return;
    const a = document.createElement('a');
    a.href = qrDataActual.dataUrl;
    a.download = 'QR-' + (qrDataActual.codigo || 'inspeccion') + '.png';
    document.body.appendChild(a);
    a.click();
    a.remove();
}

function imprimirQR() {
    if (!qrDataActual) return;
    const ventana = window.open('', '_blank', 'width=420,height=560');
    if (!ventana) {
        alert('El navegador bloqueó la ventana de impresión. Habilita las ventanas emergentes para este sitio e intenta de nuevo.');
        return;
    }
    const html = '<!DOCTYPE html><html><head><meta charset="utf-8">' +
        '<title>QR - ' + qrDataActual.codigo + '</title>' +
        '<style>' +
        'body{font-family:Arial,Helvetica,sans-serif;text-align:center;padding:40px 20px;}' +
        'img{width:260px;height:260px;}' +
        'h2{margin:6px 0 2px;font-size:18px;}' +
        'p{color:#555;font-size:11px;word-break:break-all;max-width:320px;margin:10px auto 0;}' +
        '</style></head><body>' +
        '<h2>' + qrDataActual.codigo + '</h2>' +
        '<img src="' + qrDataActual.dataUrl + '" alt="Código QR">' +
        '<p>' + qrDataActual.url + '</p>' +
        '<script>window.onload = function () { window.print(); };<' + '/script>' +
        '</body></html>';
    ventana.document.write(html);
    ventana.document.close();
}
