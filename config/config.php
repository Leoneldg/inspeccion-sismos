<?php
/**
 * Configuración global del sistema.
 * Ajuste los datos de conexión a su servidor MySQL antes de desplegar.
 */

// ---------------------------------------------------------------------
// Base de datos
// ---------------------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'inspecciones_sismos');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// ---------------------------------------------------------------------
// Aplicación
// ---------------------------------------------------------------------
define('APP_NAME', 'Inspección de Edificaciones Post-Sismo');
// Ruta base donde se sirve la aplicación. Se puede fijar con la variable de
// entorno APP_URL_BASE (recomendado en producción, para no tener que tocar
// código si el despliegue cambia de subcarpeta o pasa a la raíz del
// dominio); si no está definida, usa '/inspecciones-sismos/' por defecto.
// IMPORTANTE: debe terminar en '/', y si se sirve desde la raíz del
// dominio debe ser exactamente '/'.
define('APP_URL_BASE', getenv('APP_URL_BASE') ?: '/inspeccion-sismos/');
define('APP_TIMEZONE', 'America/Caracas');

// Clave para firmar el enlace público del PDF que se codifica en el QR de
// cada inspección (así se puede abrir el PDF sin iniciar sesión al
// escanearlo en campo, pero solo con el token exacto de ese registro —
// no cualquiera puede adivinar la URL de otra inspección).
// IMPORTANTE: en producción, defina APP_QR_SECRET como variable de entorno
// con un valor propio y secreto; si la cambia, los QR ya impresos dejan de
// funcionar (habría que reimprimirlos).
define('APP_QR_SECRET', getenv('APP_QR_SECRET') ?: 'cambia-esta-clave-en-produccion-inspecciones-sismos');

// ---------------------------------------------------------------------
// Versión de assets (cache-busting automático). Cambia solo cuando el
// archivo CSS/JS se modifica, para que el navegador nunca sirva una
// versión vieja en caché tras una actualización del sistema.
// ---------------------------------------------------------------------
$cssPath = __DIR__ . '/../assets/css/style.css';
$jsPath  = __DIR__ . '/../assets/js/main.js';
define('ASSET_VERSION', (string)max(
    file_exists($cssPath) ? filemtime($cssPath) : 0,
    file_exists($jsPath) ? filemtime($jsPath) : 0,
    1
));

// ---------------------------------------------------------------------
// Registro fotográfico
// ---------------------------------------------------------------------
define('UPLOAD_DIR', __DIR__ . '/../uploads/inspecciones/');
define('UPLOAD_URL', APP_URL_BASE . 'uploads/inspecciones/');

// Registro fotográfico del módulo de Seguimiento y Control (obras).
define('SEG_UPLOAD_DIR', __DIR__ . '/../uploads/seguimiento/');
define('SEG_UPLOAD_URL', APP_URL_BASE . 'uploads/seguimiento/');

date_default_timezone_set(APP_TIMEZONE);

// ---------------------------------------------------------------------
// Sesión segura
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    // Este sistema se usa en campo con el modo offline (assets/js/offline.js):
    // un inspector puede llenar el formulario sin señal y que el navegador
    // recién lo envíe horas después, al volver la conexión. Si la sesión
    // expira antes de eso (el default de PHP son ~24 minutos de
    // inactividad), ese envío se rechaza por sesión/CSRF inválido y el modo
    // offline lo reintenta sin éxito. Le damos 12 horas de margen.
    $vidaSesionSegundos = 12 * 60 * 60;
    ini_set('session.gc_maxlifetime', (string)$vidaSesionSegundos);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    // Detecta HTTPS también cuando el sitio corre detrás de un proxy inverso
    // (Cloudflare, Nginx como proxy, balanceador de carga, etc.), donde
    // $_SERVER['HTTPS'] a veces no llega seteado aunque el visitante sí esté
    // en https -- si se fuerza cookie_secure sin que el navegador vea la
    // conexión como segura, la sesión (y por lo tanto el login) no persiste.
    $esHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    if ($esHttps) {
        ini_set('session.cookie_secure', 1);
    }
    session_name('inspsismo_sess');
    session_set_cookie_params($vidaSesionSegundos);
    session_start();
}

// Cabeceras de seguridad básicas
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Mostrar errores solo en entorno de desarrollo
define('APP_DEBUG', getenv('APP_DEBUG') === '1');
if (APP_DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
