# Límites reales de las parroquias

Esta carpeta ya incluye `parroquias_libertador.geojson` con los **22
polígonos reales de las parroquias del Municipio Libertador (Caracas)**,
filtrados y limpiados a partir del dataset oficial COD-AB de UN OCHA/HDX
(nivel administrativo 3 - parroquia). El dashboard los detecta y usa
automáticamente; no necesita hacer nada más.

Cada parroquia tiene su propiedad `parroquia` normalizada exactamente igual
a los valores usados en la base de datos (`La Candelaria`, `San Bernardino`,
`Antímano`, etc. — ver `includes/functions.php` -> `catalogoParroquias()`),
así que el nombre se muestra correctamente al pasar el cursor sobre cada
parroquia en el mapa.

## Si necesita regenerar o reemplazar este archivo

El dataset original de UN OCHA/HDX (`ven_admbnda_adm3_20180502`, o su
versión más reciente en https://data.humdata.org/dataset/cod-ab-ven) trae
**las 1134 parroquias de todo Venezuela**, no solo las de Caracas — y
además el municipio se llama "Libertador" en varios estados del país (Mérida,
Táchira, Carabobo, etc.), así que **no basta con filtrar por nombre de
municipio**: hay que filtrar también por `adm1_name == "Distrito Capital"`
para quedarse solo con las 22 parroquias correctas de Caracas.

Si descarga una versión nueva del dataset y quiere volver a generar el
archivo:

1. Abra el GeoJSON/shapefile descargado.
2. Filtre las features donde `adm2_name == "Libertador"` **y**
   `adm1_name == "Distrito Capital"` (debe darle exactamente 22).
3. Renombre la propiedad con el nombre de la parroquia a `parroquia`, y
   corrija dos particularidades conocidas del dataset oficial:
   - `"Antimano"` → `"Antímano"` (falta el acento en el dato origen)
   - `"Candelaria"` → `"La Candelaria"` (falta el artículo)
   - `"San Bernandino"` → `"San Bernardino"` (typo en el dato origen)
4. (Opcional pero recomendado) Simplifique la geometría para que cargue
   rápido en el navegador, con **https://mapshaper.org** (arrastrar el
   archivo, "Simplify" 10-15% conservando topología, exportar GeoJSON).
5. Guarde como `assets/geo/parroquias_libertador.geojson`, reemplazando
   este archivo.

## Formato esperado por el dashboard

- `FeatureCollection` de polígonos, proyección WGS84 (lat/lng).
- Cada `Feature.properties` debe tener el nombre de la parroquia en alguna
  de estas claves (se detecta automáticamente en este orden): `parroquia`,
  `PARROQUIA`, `nombre`, `NOMBRE`, `name`, `NAME`, `NAME_3`, `ADM3_ES`,
  `shapeName`, `adm3_name`, `adm3_ref_n`.
- El nombre no necesita coincidir de forma exacta: el dashboard normaliza
  (sin acentos, minúsculas, sin artículo inicial "La/El/Los/Las") antes de
  comparar contra los datos de la base de datos.
