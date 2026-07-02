# Sistema de Inspección de Edificaciones Post-Sismo

Aplicación web en **PHP + MySQL** que digitaliza el instrumento de inspección
estructural post-sismo (antes en Excel/ODK) e incluye:

- **Dashboard** de indicadores pensado para presentación en TV/pantalla grande:
  KPIs, gráficos (Chart.js), mapa satelital con secciones geográficas por
  parroquia y clustering de marcadores (Leaflet), ficha técnica con registro
  fotográfico al hacer clic en un punto del mapa, y modo presentación de
  pantalla completa.
- **Formulario** de inspección tipo *wizard* (8 pasos) con selector de
  ubicación en mapa interactivo (estilo Google Maps) y registro fotográfico
  por cada elemento evaluado. 100% responsive para uso en campo desde celular.
- **Sistema de usuarios y permisos por módulo** (RBAC): roles configurables con
  permisos independientes de Ver / Crear / Editar / Eliminar para cada módulo
  (`dashboard`, `formulario`, `usuarios`).
- **Migración de datos**: se incluye el volcado real de las 260 inspecciones del
  Excel original ya transformadas a MySQL.

---

## 1. Requisitos

- PHP 8.1+ con extensiones `pdo_mysql`, `mbstring`, `json`, `gd` (todas estándar).
- MySQL 5.7+ / MariaDB 10.4+ (usa columnas `JSON`).
- Servidor web (Apache/Nginx) o `php -S` para desarrollo.
- Carpeta `uploads/` con permisos de escritura para el usuario del servidor web
  (`chmod -R 775 uploads` y asegurar que el propietario sea `www-data` o el
  usuario con el que corre PHP).

## 2. Instalación

### 2.1 Base de datos

**Instalación nueva:**
```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/datos_importados.sql   # opcional: carga las 260 inspecciones migradas del Excel
```

**Actualización de una instalación previa** (si ya tenía el sistema instalado
antes de esta versión, que agrega registro fotográfico):
```bash
mysql -u root -p < database/actualizacion_v2.sql
```

`schema.sql` crea la base `inspecciones_sismos`, las tablas, los 3 roles base
(Administrador, Inspector, Supervisor), los 3 módulos y 3 usuarios de
demostración:

| Usuario      | Contraseña        | Rol            |
|--------------|-------------------|----------------|
| `admin`      | `Admin#2026`      | Administrador  |
| `inspector`  | `Inspector#2026`  | Inspector      |
| `supervisor` | `Supervisor#2026` | Supervisor     |

> **Cambie estas contraseñas antes de usar el sistema en producción.**

### 2.2 Configuración de la aplicación

Edite `config/config.php` (o defina variables de entorno `DB_HOST`, `DB_NAME`,
`DB_USER`, `DB_PASS`) con los datos de su servidor MySQL. Se recomienda crear un
usuario de base de datos dedicado (no usar `root`):

```sql
CREATE USER 'app_inspecciones'@'localhost' IDENTIFIED BY 'una-clave-fuerte';
GRANT ALL PRIVILEGES ON inspecciones_sismos.* TO 'app_inspecciones'@'localhost';
FLUSH PRIVILEGES;
```

Si la aplicación se sirve desde una subcarpeta (ej. `https://dominio.com/inspecciones/`),
ajuste `APP_URL_BASE` en `config/config.php`.

### 2.3 Publicar los archivos

Copie toda la carpeta `inspecciones-sismos/` a la raíz pública de su servidor
(`public_html`, `htdocs`, `/var/www/html`, etc.) y apunte el *document root* ahí.

Asegúrese de dar permisos de escritura a la carpeta de fotos:
```bash
chmod -R 775 uploads
chown -R www-data:www-data uploads   # ajuste el usuario según su servidor
```

Acceda a `login.php` (o a la raíz del sitio, que redirige automáticamente).

---

## 3. Estructura del proyecto

```
inspecciones-sismos/
├── config/config.php          Configuración (BD, sesión, seguridad, subida de fotos)
├── includes/
│   ├── db.php                 Conexión PDO (prepared statements en todo el sistema)
│   ├── auth.php                Login, sesión, control de permisos por módulo (RBAC)
│   ├── functions.php           Helpers, catálogos y manejo de registro fotográfico
│   ├── header.php / footer.php Layout compartido (sidebar colapsable según permisos)
├── assets/css/style.css        Identidad visual propia ("Protocolo de campo")
├── login.php / logout.php / index.php
├── dashboard/
│   ├── index.php               KPIs, gráficos, mapa con secciones por parroquia y clusters
│   ├── api_kpis.php            Endpoint JSON (AJAX) — datos globales para el dashboard
│   └── api_ficha.php           Endpoint JSON — ficha técnica de una inspección + fotos
├── formulario/
│   ├── index.php                Listado + búsqueda + paginación
│   ├── create.php               Wizard de 8 pasos + mapa selector + registro fotográfico
│   ├── save.php                 Procesamiento seguro (INSERT/UPDATE + fotos)
│   ├── view.php                 Detalle de una inspección + galería de fotos
│   └── delete.php               Elimina inspección y limpia sus fotos en disco
├── admin/
│   ├── usuarios.php / guardar_usuario.php / eliminar_usuario.php
│   └── roles.php / guardar_rol.php   Matriz de permisos por módulo
├── uploads/inspecciones/       Registro fotográfico (protegido con .htaccess)
└── database/
    ├── schema.sql               Esquema completo + datos semilla (roles/usuarios)
    ├── actualizacion_v2.sql     Migración incremental (tabla de fotos) para instalaciones previas
    └── datos_importados.sql     260 inspecciones migradas desde el Excel original
```

## 4. Modelo de permisos

Cada **rol** tiene, por cada **módulo**, cuatro banderas independientes:
`ver`, `crear`, `editar`, `eliminar`. Esto se administra visualmente desde
**Administración → Roles y Permisos**, donde también se pueden crear roles
adicionales (ej. "Analista de Riesgo", "Solo Lectura", etc.) sin tocar código.

El menú lateral, los botones de acción y cada script de backend validan el
permiso correspondiente antes de mostrar contenido o ejecutar una acción
(defensa en profundidad: UI + servidor).

## 5. Dashboard para presentación en TV

- **Sin filtros manuales**: el dashboard siempre muestra el panorama completo,
  optimizado para pantallas grandes con tipografía ampliada (`tv-kpi-card`,
  `tv-hero`).
- **Secciones geográficas por parroquia**: el mapa dibuja los **límites
  reales de las 22 parroquias del Municipio Libertador**, incluidos en
  `assets/geo/parroquias_libertador.geojson` (filtrados y limpiados a
  partir del dataset oficial COD-AB de UN OCHA/HDX), coloreados según la
  decisión final predominante de cada una. Si ese archivo llegara a
  faltar, el sistema cae automáticamente a círculos aproximados calculados
  desde las coordenadas reales — ver `assets/geo/LEEME.md` para más
  detalle o para reemplazar el archivo con una versión propia.
- **Mapa compacto**: el mapa tiene una altura fija (380px) y no se estira
  para igualar la altura de la columna de gráficos junto a él.
- **Clustering de marcadores**: los puntos individuales se agrupan
  automáticamente cuando están muy cerca entre sí o se superponen (ver
  sección 7 sobre por qué esto era necesario).
- **Ficha técnica**: al hacer clic en un punto del mapa aparece un popup con
  foto de portada y botón "Ver ficha técnica", que abre un panel con todos
  los datos de la inspección (ubicación, características, riesgo, daños,
  personas afectadas, observaciones) y su registro fotográfico completo,
  agrupado por elemento evaluado.
- **Modo presentación**: botón flotante que oculta el menú lateral y la barra
  superior, maximiza el contenido y solicita pantalla completa del navegador
  — ideal para dejar el dashboard corriendo en un televisor. Se actualiza
  automáticamente cada 60 segundos sin recargar la página.
- **Sidebar colapsable**: en el resto del sistema, el menú lateral puede
  contraerse a solo íconos con el botón en el borde del sidebar; el estado
  se recuerda entre sesiones.

## 6. Formulario: mapa selector y registro fotográfico

- El paso "Identificación y ubicación" reemplaza los campos numéricos de
  latitud/longitud por un mapa interactivo (Leaflet + capa satelital Esri):
  toque o clic para colocar el marcador, arrástrelo para ajustar, o use el
  botón "Usar mi ubicación actual" (geolocalización del navegador/celular).
- Se agregaron campos de carga de fotos (`accept="image/*" capture="environment"`,
  lo que abre la cámara directamente en celulares) en: vista general de la
  edificación, cada uno de los 6 elementos estructurales evaluados, cada uno
  de los 4 elementos no estructurales evaluados, y la etiqueta/cartel de
  decisión final colocado. En modo edición se muestran las fotos ya
  guardadas con la opción de marcarlas para eliminar.
- Todo el formulario (grillas, wizard, mapa, inputs de archivo) es responsive
  y se ha probado en viewport móvil.
- Las imágenes se validan en el servidor (extensión, tamaño máximo 8 MB,
  verificación real de que el archivo es una imagen) antes de guardarse en
  `uploads/inspecciones/{id}/` y registrarse en la tabla `inspeccion_fotos`.

## 7. Diagnóstico: ¿por qué aparecían pocos puntos en el mapa?

Se investigó la causa exacta con los datos migrados: **192 de las 260
inspecciones del Excel original comparten exactamente la misma coordenada**
(`10.4885870, -66.9767820`), que corresponde al punto/zoom por defecto con el
que abre el mapa del formulario original (Survey123/ODK) — es decir, en la
mayoría de los registros el encuestador no movió el pin a la ubicación real
de la edificación antes de guardar. Solo existen **60 combinaciones de
coordenadas distintas** en todo el conjunto de datos.

Esto no era un error del dashboard: los ~192 marcadores estaban
literalmente apilados unos sobre otros en el mismo punto, por lo que
visualmente solo se apreciaba uno. Para solucionarlo:

1. Se agregó **clustering de marcadores**, así que ahora ese punto se ve como
   un círculo numerado (ej. "192") en vez de esconder los registros.
2. El **nuevo formulario obliga visualmente** a seleccionar la ubicación en
   el mapa (en vez de escribir números a mano), lo que en la práctica reduce
   mucho la probabilidad de que se quede en el valor por defecto — pero como
   no es un campo obligatorio con validación de "distinto al centro por
   defecto", igual es buena práctica capacitar al personal de campo para
   que siempre reposicione el marcador.
3. Si lo desea, puedo agregar una validación que impida guardar la
   inspección si la coordenada seleccionada coincide exactamente con el
   centro por defecto del mapa, forzando a que el usuario la mueva.

## 8. Validación realizada

Antes de la entrega se verificó en un entorno real (PHP 8.3 + MariaDB 10.11):

- Carga de `schema.sql`, `actualizacion_v2.sql` y `datos_importados.sql` sin errores.
- Los totales de personas/mascotas del dashboard migrados desde el Excel
  coinciden exactamente con los valores del dashboard de referencia.
- Flujo completo: login, creación de inspección (wizard de 8 pasos) con mapa
  selector y carga de fotos, edición con eliminación de fotos existentes,
  eliminación completa de una inspección (con limpieza de sus fotos en
  disco), búsqueda, ficha técnica desde el mapa del dashboard.
- Control de acceso: un usuario con rol `Supervisor` (solo permiso de
  `dashboard.ver`) recibe `403 Acceso denegado` al intentar entrar al
  formulario o a Usuarios, y `200` en el dashboard.
- Protección CSRF en creación, edición y eliminación de inspecciones, fotos y usuarios.

## 9. Solución de problemas

**Error "500 Internal Server Error" / "Unexpected end of JSON input" en el dashboard:**
Casi siempre significa que la base de datos no tiene la tabla `inspeccion_fotos`
(porque `schema.sql` es de antes de esa función, o `actualizacion_v2.sql` no
se ejecutó). Solución: `mysql -u root -p < database/actualizacion_v2.sql`.
Desde esta versión, además, los endpoints del dashboard (`api_kpis.php`,
`api_ficha.php`) y las funciones de fotos degradan de forma segura si la
tabla no existe (muestran el dashboard sin datos de fotos en vez de romperse),
y **siempre** devuelven JSON válido ante cualquier error inesperado del
servidor, así que un error 500 nunca debería dejar el body vacío — revise el
campo `detail` de la respuesta (visible con `APP_DEBUG=1`) para más contexto.

**Si vuelve a ejecutar `schema.sql` sobre una base de datos existente:**
esta versión corrige un bug donde faltaba el `DROP TABLE IF EXISTS
inspeccion_fotos` al inicio del script; si tenía una copia anterior del
archivo, re-ejecutarlo podía abortar a mitad de camino y dejar la base de
datos sin los usuarios y roles semilla (por eso el login fallaba con
"usuario o contraseña incorrectos" incluso con las credenciales correctas).
Con el `schema.sql` de este paquete eso ya no ocurre.

## 10. Notas de seguridad

- Contraseñas con `password_hash()` (bcrypt) y verificación con `password_verify()`.
- Todas las consultas usan sentencias preparadas (PDO) — sin concatenación de SQL.
- Protección CSRF en todos los formularios que modifican datos.
- Bloqueo temporal tras 5 intentos fallidos de login por usuario.
- Cabeceras de seguridad básicas (`X-Content-Type-Options`, `X-Frame-Options`, etc.).
- La carpeta `uploads/` tiene un `.htaccess` que impide la ejecución de
  scripts; además, cada archivo subido se valida como imagen real
  (`getimagesize`) antes de guardarse, con extensión y tamaño controlados.
- Bitácora de actividad (`log_actividad`) para acciones sensibles (login, CRUD de
  usuarios/roles, creación/edición/eliminación de inspecciones).
