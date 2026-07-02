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
define('APP_URL_BASE', '/inspecciones-sismos/'); // cambiar si se despliega en subcarpeta, ej: '/inspecciones-sismos/'
define('APP_TIMEZONE', 'America/Caracas');

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

date_default_timezone_set(APP_TIMEZONE);

// ---------------------------------------------------------------------
// Sesión segura
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    if (!empty($_SERVER['HTTPS'])) {
        ini_set('session.cookie_secure', 1);
    }
    session_name('inspsismo_sess');
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
