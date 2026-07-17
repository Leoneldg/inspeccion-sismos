// Menú lateral: comportamiento de panel deslizante en móvil + colapso en escritorio
document.addEventListener('DOMContentLoaded', function () {
    var sidebar  = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebar-backdrop');
    var btnMenu  = document.getElementById('btn-menu');
    var toggle   = document.getElementById('sidebar-toggle');

    // Avisa a cualquier mapa Leaflet de la página que el tamaño de su
    // contenedor pudo haber cambiado (colapsar sidebar, abrir/cerrar en
    // móvil, redimensionar ventana). Se dispara tras la transición CSS.
    function anunciarCambioLayout() {
        setTimeout(function () {
            window.dispatchEvent(new Event('sismos:layout-change'));
        }, 220);
    }

    function openMobileMenu() {
        sidebar?.classList.add('open');
        backdrop?.classList.add('show');
        anunciarCambioLayout();
    }
    function closeMobileMenu() {
        sidebar?.classList.remove('open');
        backdrop?.classList.remove('show');
        anunciarCambioLayout();
    }

    // Hamburguesa: abre/cierra el panel en móvil
    btnMenu?.addEventListener('click', function () {
        sidebar?.classList.contains('open') ? closeMobileMenu() : openMobileMenu();
    });

    // Tocar el fondo oscuro cierra el menú
    backdrop?.addEventListener('click', closeMobileMenu);

    // Tecla Escape cierra el menú
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMobileMenu();
    });

    // Si la ventana crece a escritorio, aseguramos que no quede "abierto" a medias
    var resizeTimer;
    window.addEventListener('resize', function () {
        if (window.innerWidth > 960) closeMobileMenu();
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            window.dispatchEvent(new Event('sismos:layout-change'));
        }, 150);
    });

    // Colapsar / expandir el sidebar en escritorio (persistente vía localStorage)
    if (toggle) {
        toggle.addEventListener('click', function () {
            var collapsed = document.body.classList.toggle('sidebar-collapsed');
            localStorage.setItem('sidebar_collapsed', collapsed ? '1' : '0');
            anunciarCambioLayout();
        });
    }
});

// Cierra el menú lateral en móvil al navegar a otra sección
document.addEventListener('click', function (e) {
    var link = e.target.closest('.sidebar a.nav-item');
    if (link) {
        document.getElementById('sidebar')?.classList.remove('open');
        document.getElementById('sidebar-backdrop')?.classList.remove('show');
    }
});

// Autocierre de alertas tras unos segundos
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .4s ease';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 400);
        }, 6000);
    });
});
