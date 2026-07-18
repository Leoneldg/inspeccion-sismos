# Sistema offline — Guía de instalación

Todo lo trabajado en las últimas tandas, reunido y verificado.

---

## 1. Archivos nuevos

Estos no existen en el servidor, hay que crearlos:

```
assets/js/obras-offline.js                  Motor offline (cola y sincronización)
assets/js/obras-fotos.js                    Respaldo de fotos en el teléfono
assets/js/mantener-sesion.js                Evita que la sesión caiga sola
api/ping.php                                Verifica conexión y sesión reales
seguimiento/paquete_offline.php             Descarga una ficha para trabajar sin señal
seguimiento/comprobante_levantamiento.php   PDF de constancia al cerrar
```

## 2. Archivos que se reemplazan

```
service-worker.js                Caché de la aplicación (versión v4)
includes/footer.php              Carga los scripts nuevos
seguimiento/levantamiento.php    Autoguardado, fotos, selector cámara/galería
seguimiento/remodelacion.php     Trabajo sin señal, respaldo de fotos
seguimiento/index.php            Corrección de San Bernardino en el mapa
```

## 3. Configuración de sesión (a mano)

`config/config.php` **no está en Git**. Hay que editarlo en el servidor
siguiendo `INSTRUCCIONES_SESION.md`.

Punto clave: **reemplazar** el bloque de sesión existente, no agregar otro
al final. Si hay dos bloques, manda el primero y el cambio no surte efecto.

Verificar que quede uno solo:

```bash
grep -c "session_status() === PHP_SESSION_NONE" /var/www/inspeccion/config/config.php
```

Debe dar `1`.

Crear la carpeta de sesiones:

```bash
sudo mkdir -p /var/www/inspeccion/storage/sesiones
sudo chown www-data:www-data /var/www/inspeccion/storage/sesiones
sudo chmod 770 /var/www/inspeccion/storage/sesiones
```

## 4. Límite de subida (pendiente desde hace varias tandas)

Sin esto, las fotos de celular seguirán fallando: pesan entre 3 y 8 MB y
el servidor corta en 2 MB.

```bash
sudo nano /etc/php/8.3/fpm/php.ini
```

```ini
upload_max_filesize = 12M
post_max_size = 16M
memory_limit = 256M
session.gc_maxlifetime = 43200
```

En nginx (`/etc/nginx/nginx.conf`, dentro del bloque `http`):

```nginx
client_max_body_size 16M;
```

Reiniciar:

```bash
sudo systemctl restart php8.3-fpm nginx apache2
```

---

## 5. Después de subir: borrar el Service Worker viejo

**Este paso es obligatorio en cada dispositivo.** El navegador conserva la
versión anterior y el sistema no se actualiza sin esto.

**En computadora:** F12 → Application → Service Workers → Unregister →
Clear site data → Ctrl+Shift+R.

**En celular:** Ajustes del navegador → Configuración del sitio →
Datos almacenados → borrar los del sitio → recargar.

---

## 6. Pruebas antes de soltarlo al equipo

Hágalas en un celular real, no en el escritorio.

**Sesión**
1. Inicie sesión y deje la pestaña abierta 30 minutos sin tocarla.
2. Vuelva y guarde algo. Antes fallaba; ahora debe funcionar.

**Fotos**
1. Toque un botón de foto: debe aparecer el menú con "Tomar foto" y
   "Elegir de la galería".
2. Tome una foto y confirme que aparece en "Mis fotos".

**Sin señal**
1. Con conexión, abra una ficha y toque "Llevar sin señal". Espere a que
   termine de guardar las fotos.
2. **Active modo avión** y recargue la página: debe cargar igual, con un
   aviso amarillo.
3. Registre un avance y tome una foto. Deben quedar como pendientes.
4. Desactive el modo avión: todo debe subir solo.

**Señal débil** (el caso que más falla)
1. F12 → Network → cambie "No throttling" por **"Slow 3G"**.
2. Intente guardar un avance. Debe avisar, no quedarse colgado.

**Comprobante**
1. Cierre un levantamiento de prueba.
2. Descargue el PDF y verifique que los números del resumen coincidan con
   lo que cargó. Si dice "3 jefes de familia" y usted registró 4, hay un
   dato que no se guardó.

---

## 7. Qué decirle al equipo

- **Antes de salir a campo**, con señal, abrir cada edificación del día y
  tocar "Llevar sin señal".
- **Esperar el "Guardado" verde** antes de pasar al siguiente ambiente.
- Si aparece la barra amarilla de "sin señal", **es normal**: siga
  trabajando, todo se guarda en el teléfono.
- Al recuperar señal, revisar que la barra desaparezca. Si queda algo
  pendiente, tocar "Ver detalle" para saber por qué.
- **Al terminar la jornada**, si hay fotos pendientes, usar
  "Guardar las pendientes en el teléfono" desde "Mis fotos".

---

## 8. Límites conocidos

**El espacio del teléfono.** Cada edificación con fotos pesa varios MB.
Descargar veinte edificios puede llenar el almacenamiento. Recomiende
bajar solo los del día.

**El comprobante necesita señal.** Se genera en el servidor. Si el cierre
ocurre sin conexión, hay que descargarlo después.

**Sesión de 12 horas.** Cómoda para la jornada, pero si comparten
teléfonos considere bajarla: alguien podría trabajar con la cuenta de otro
y la trazabilidad quedaría mal atribuida.
