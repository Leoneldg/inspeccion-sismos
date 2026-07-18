/**
 * MANTENER LA SESIÓN VIVA.
 *
 * El personal en campo llena formularios largos sin recargar la página.
 * Para el servidor eso parece inactividad y cerraba la sesión sola.
 *
 * Este módulo avisa al servidor cada pocos minutos de que la persona
 * sigue trabajando, y si aun así la sesión cae, lo dice claramente en
 * lugar de dejar que se pierdan los datos en silencio.
 */
(function () {
    'use strict';

    const CADA_MS = 5 * 60 * 1000;      // avisar cada 5 minutos
    let _sesionCaida = false;
    let _timer = null;

    function base() { return window._APP_URL_BASE || '/'; }

    /** Le dice al servidor que seguimos aquí. */
    async function latido() {
        if (!navigator.onLine) return;          // sin señal no hay nada que hacer
        try {
            const res = await fetch(base() + 'api/ping.php?t=' + Date.now(), {
                method: 'GET', cache: 'no-store', credentials: 'same-origin',
            });
            if (!res.ok) return;
            const d = await res.json();
            if (d && d.sesion === false) {
                avisarSesionCaida();
            } else {
                _sesionCaida = false;
                quitarAviso();
            }
        } catch (e) {
            // Sin conexión: no se puede saber. No se molesta al usuario.
        }
    }

    /**
     * Aviso visible y persistente. Antes la sesión caía en silencio y el
     * usuario seguía llenando datos que ya no se iban a guardar.
     */
    function avisarSesionCaida() {
        if (_sesionCaida) return;
        _sesionCaida = true;

        let barra = document.getElementById('aviso-sesion');
        if (barra) return;
        barra = document.createElement('div');
        barra.id = 'aviso-sesion';
        barra.style.cssText = 'position:fixed;left:0;right:0;top:0;z-index:2500;'
            + 'background:#A61C1C;color:#fff;padding:11px 14px;font-size:13.5px;'
            + 'font-weight:600;text-align:center;box-shadow:0 2px 12px rgba(20,25,40,.25);';
        barra.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> '
            + 'Su sesión se cerró. Lo que escriba ahora no se guardará. '
            + '<button onclick="MantenerSesion.reconectar()" '
            + 'style="margin-left:8px;background:#fff;color:#A61C1C;border:0;border-radius:6px;'
            + 'padding:5px 14px;font-weight:700;cursor:pointer;">Iniciar sesión</button>';
        document.body.appendChild(barra);
    }

    function quitarAviso() {
        const b = document.getElementById('aviso-sesion');
        if (b) b.remove();
    }

    /**
     * Abre el login en otra pestaña: así la persona no pierde lo que
     * tiene escrito en esta pantalla.
     */
    function reconectar() {
        window.open(base() + 'login.php', '_blank');
        const b = document.getElementById('aviso-sesion');
        if (b) {
            b.innerHTML = '<i class="bi bi-info-circle-fill"></i> '
                + 'Inicie sesión en la otra pestaña y luego vuelva aquí. '
                + '<button onclick="MantenerSesion.comprobar()" '
                + 'style="margin-left:8px;background:#fff;color:#A61C1C;border:0;border-radius:6px;'
                + 'padding:5px 14px;font-weight:700;cursor:pointer;">Ya inicié sesión</button>';
        }
    }

    /** Comprobación manual tras volver a iniciar sesión. */
    async function comprobar() {
        _sesionCaida = false;
        await latido();
        if (!document.getElementById('aviso-sesion')) {
            alert('Listo, la sesión está activa otra vez.\n\nPuede seguir trabajando y guardar lo que tenía en pantalla.');
        } else {
            alert('Todavía no detecto la sesión. Verifique que inició sesión en la otra pestaña.');
        }
    }

    function arrancar() {
        if (_timer) return;
        _timer = setInterval(latido, CADA_MS);
        // Al volver a la app tras cambiar de pantalla, comprobar enseguida.
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) latido();
        });
        window.addEventListener('online', latido);
    }

    window.MantenerSesion = { latido, reconectar, comprobar };

    document.addEventListener('DOMContentLoaded', arrancar);
})();
