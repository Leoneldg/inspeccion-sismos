# Corregir el cierre de sesión automático

## Qué estaba pasando

La sesión se cerraba sola por dos motivos:

1. **`session.gc_maxlifetime` estaba en `0`.** Alguien lo puso así creyendo
   que significaba "sin límite", pero en PHP el valor `0` hace lo contrario:
   permite que el recolector de basura borre la sesión **de inmediato**.
   Era peor que el valor por defecto.

2. **La cookie moría al cerrar el navegador** (`lifetime = 0`). En el celular,
   al cambiar de aplicación o bloquear la pantalla, el navegador puede
   descartarla y la persona aparece deslogueada.

Además, las sesiones se guardaban en la carpeta compartida del servidor,
donde el recolector de otros sitios puede borrarlas antes de tiempo.

## Qué hay que hacer

`config/config.php` **no está en Git**, así que hay que editarlo a mano
en el servidor.

```bash
sudo nano /var/www/inspeccion/config/config.php
```

Busque el bloque que empieza con `if (session_status() === PHP_SESSION_NONE) {`
y reemplácelo completo por el que está en el archivo
`config/config-sesion-ejemplo.txt` que viene en esta entrega.

## Crear la carpeta de sesiones

```bash
sudo mkdir -p /var/www/inspeccion/storage/sesiones
sudo chown www-data:www-data /var/www/inspeccion/storage/sesiones
sudo chmod 770 /var/www/inspeccion/storage/sesiones
```

El dueño debe ser el usuario con el que corre PHP-FPM. Para verificar cuál es:

```bash
ps aux | grep php-fpm | head -2
```

Normalmente es `www-data`.

## Verificar que funcionó

Cree un archivo temporal:

```bash
echo '<?php require "config/config.php"; echo "gc_maxlifetime: ".ini_get("session.gc_maxlifetime")."\n"; echo "save_path: ".ini_get("session.save_path")."\n";' | sudo tee /var/www/inspeccion/_sesion.php
```

Ábralo en el navegador. Debe mostrar:

```
gc_maxlifetime: 43200
save_path: /var/www/inspeccion/storage/sesiones
```

**Bórrelo apenas termine:**

```bash
sudo rm /var/www/inspeccion/_sesion.php
```

## Si aun así se cierra

Puede haber un límite en el propio PHP-FPM que sobreescribe lo anterior:

```bash
sudo nano /etc/php/8.3/fpm/php.ini
```

Busque y ajuste:

```ini
session.gc_maxlifetime = 43200
```

Y reinicie:

```bash
sudo systemctl restart php8.3-fpm
```

## Nota sobre el tiempo elegido

Quedó en **12 horas**, pensando en una jornada completa de trabajo.
Si prefiere otro valor, cambie la línea:

```php
define('SESION_DURACION', 12 * 60 * 60);
```

Tenga en cuenta que una sesión más larga es más cómoda pero menos segura:
si alguien deja el teléfono desbloqueado, otra persona podría usar el
sistema con su cuenta. Para equipos que comparten dispositivos, considere
bajarlo a 4 u 8 horas.
