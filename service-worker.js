/**
 * Service Worker — Inspección de Edificaciones Post-Sismo (PWA)
 *
 * Objetivo: que la app abra y funcione aunque no haya internet.
 *
 * Estrategia:
 *   - Recursos estáticos (CSS, JS, íconos, fuentes): "cache first" — se sirven
 *     desde la caché al instante y se actualizan en segundo plano.
 *   - Páginas del formulario (HTML): "network first" con respaldo a la caché,
 *     para que, con señal, siempre traiga la versión más reciente, y sin señal,
 *     use la última que se guardó.
 *   - Envíos del formulario (POST a save.php) y llamadas de datos NUNCA se
 *     cachean: el guardado offline lo maneja IndexedDB (assets/js/offline.js).
 *
 * Al cambiar de versión, se borran las cachés viejas.
 */
'use strict';

const VERSION = 'obras-pwa-v6';
const CACHE_ESTATICO = VERSION + '-estatico';
const CACHE_PAGINAS  = VERSION + '-paginas';

// Recursos base que se guardan al instalar (el "cascarón" de la app).
// Las rutas son relativas al scope donde se registra el service worker.
const PRECACHE = [
  'seguimiento/index.php?pwa=1',
  'assets/js/obras-offline.js',
  'assets/js/obras-catalogo.js',
  'assets/js/obras-fotos.js',
  'assets/js/mantener-sesion.js',
  'assets/css/style.css',
  'assets/js/main.js',
  'assets/js/offline.js',
  'assets/js/buzon.js',
  'assets/js/qr.js',
  'assets/pwa/icon-192.png',
  'assets/pwa/icon-512.png',
  'offline-fallback.html'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_ESTATICO).then((cache) =>
      // addAll falla si un recurso no responde 200; se toleran fallos
      // individuales para no bloquear la instalación completa.
      Promise.allSettled(PRECACHE.map((url) => cache.add(url)))
    ).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((claves) =>
      Promise.all(
        claves
          .filter((c) => !c.startsWith(VERSION))
          .map((c) => caches.delete(c))
      )
    ).then(() => self.clients.claim())
  );
});

// ¿Es una petición que NO debe cachearse nunca? (guardar, APIs, login POST)
function noCachear(request, url) {
  if (request.method !== 'GET') return true; // POST/PUT/DELETE nunca
  // Sesion: nunca deben servirse desde cache (rompen el login/logout).
  if (url.pathname.endsWith('logout.php')) return true;
  if (url.pathname.endsWith('ping.php')) return true;
  if (url.pathname.endsWith('login.php')) return true;
  if (url.pathname.endsWith('save.php')) return true;
  // Endpoints del modulo de seguimiento (siempre datos frescos).
  if (/\/(guardar_|listar_|buscar_|subir_|calcular_|asignar_|arbol_|puntos_|ficha_|pdf_|paquete_)/.test(url.pathname)) return true;
  if (url.pathname.includes('/api_') || url.pathname.endsWith('_json.php')) return true;
  if (url.pathname.endsWith('guardar_ingeniero.php')) return true;
  return false;
}

// ¿Es un recurso estático? (cache-first)
function esEstatico(url) {
  return /\.(css|js|png|jpg|jpeg|gif|svg|webp|woff2?|ttf|ico)$/i.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Solo interceptamos peticiones del mismo origen (nuestro dominio).
  if (url.origin !== self.location.origin) return;

  if (noCachear(request, url)) {
    // Dejar pasar a la red tal cual (el guardado offline lo maneja IndexedDB).
    return;
  }

  if (esEstatico(url)) {
    // Cache-first: rápido y disponible sin conexión.
    event.respondWith(
      caches.match(request).then((cacheado) => {
        if (cacheado) {
          // Actualiza en segundo plano.
          fetch(request).then((resp) => {
            if (resp && resp.ok) caches.open(CACHE_ESTATICO).then((c) => c.put(request, resp.clone()));
          }).catch(() => {});
          return cacheado;
        }
        return fetch(request).then((resp) => {
          if (resp && resp.ok) {
            const copia = resp.clone();
            caches.open(CACHE_ESTATICO).then((c) => c.put(request, copia));
          }
          return resp;
        });
      })
    );
    return;
  }

  // Las pantallas de administración van siempre a la red: no se usan
  // en campo y cachearlas causaba que algunos vieran versiones viejas.
  if (url.pathname.includes('/admin/') || url.pathname.includes('/dashboard/')) {
    event.respondWith(fetch(request).catch(() =>
      caches.match('offline-fallback.html')
    ));
    return;
  }

  // Las de seguimiento SÍ se guardan: el técnico tiene que poder abrir
  // el levantamiento sin señal. Se pide primero a la red para que
  // siempre tenga la versión actual, y se guarda una copia por si
  // después se queda sin datos.
  //
  // Otras páginas: red primero, caché como respaldo.
  event.respondWith(
    fetch(request)
      .then((resp) => {
        if (resp && resp.ok) {
          const copia = resp.clone();
          caches.open(CACHE_PAGINAS).then((c) => c.put(request, copia));
        }
        return resp;
      })
      .catch(() =>
        caches.match(request).then((cacheado) =>
          cacheado || caches.match('offline-fallback.html')
        )
      )
  );
});
