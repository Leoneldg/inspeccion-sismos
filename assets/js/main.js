// Cierra el menú lateral en móvil al navegar
document.addEventListener('click', function (e) {
    var link = e.target.closest('.sidebar a.nav-item');
    if (link) {
        document.getElementById('sidebar')?.classList.remove('open');
    }
});

// Colapsar / expandir el sidebar (persistente vía localStorage)
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('sidebar-toggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            var collapsed = document.body.classList.toggle('sidebar-collapsed');
            localStorage.setItem('sidebar_collapsed', collapsed ? '1' : '0');
        });
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
