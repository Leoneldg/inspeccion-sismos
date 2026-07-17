"""
Asigna las fotos de Survey123/ArcGIS Online al sistema PHP (inspecciones-sismos):
- Copia cada foto a  uploads/inspecciones/<id_inspeccion>/  (donde tu app las espera)
- Inserta un registro por foto en la tabla `inspeccion_fotos`, con la categoría
  correcta (columna, viga, muro, fachada, "vista general", "decisión", etc.)

Este script vuelve a conectarse a ArcGIS (igual que el script de descarga que
ya usaste) porque necesita el dato "keywords" de cada foto, que es donde
Survey123 guarda a qué pregunta/categoría pertenece esa foto -- ese dato no
quedó guardado la primera vez que descargaste las fotos.

REQUISITOS
----------
1. Instala las dos dependencias que necesita (la de MySQL es nueva):
       py -m pip install requests pymysql

2. Completa la sección CONFIGURACIÓN más abajo:
   - Los mismos datos de ArcGIS que ya usaste en el script de descarga
     (FEATURE_SERVICE_URL, USUARIO, CLAVE).
   - Los datos de tu base de datos MySQL (si usas XAMPP/WAMP en tu misma PC,
     los valores por defecto casi seguro ya sirven: host "127.0.0.1",
     usuario "root", clave "").
   - RUTA_APP: la carpeta donde está instalado tu sistema PHP en esta PC
     (la carpeta que contiene "index.php", "dashboard/", "formulario/", etc.
     Ej: si usas XAMPP normalmente es "C:/xampp/htdocs/inspecciones-sismos").

3. Corre primero en modo de PRUEBA (no escribe nada, solo te muestra qué
   haría) para verificar que todo está bien conectado:
       py asignar_fotos_al_sistema.py --prueba

   Revisa especialmente:
   - Las inspecciones "[OMITIDO]" (nombre de edificio no coincide entre
     ArcGIS y MySQL) -- al final se guarda un archivo omitidos_revisar.csv
     con el detalle para que las revises con calma.
   - La lista de categorías (keywords) que no reconocí -- si ves nombres
     raros, dímelos y ajusto el diccionario MAPA_CATEGORIAS.

4. Cuando la prueba se vea bien, corre en serio (sin --prueba):
       py asignar_fotos_al_sistema.py
"""

import os
import re
import csv
import sys
import time
import difflib
import unicodedata
import requests
import pymysql

# =========================== CONFIGURACIÓN ===========================

# ---- ArcGIS Online / Survey123 (mismos datos que en descargar_fotos_survey123.py) ----
FEATURE_SERVICE_URL = "https://services3.arcgis.com/yaewiDuOIcucvCmt/arcgis/rest/services/survey123_02d3cabc0af545209288e0433e3e77e4_results/FeatureServer/0"
USUARIO = "Ecoinnova"
CLAVE   = "Innova2024*"
PORTAL_URL = "https://www.arcgis.com"   # cambia esto si usas ArcGIS Enterprise propio
# Nota: el "codigo" (INS-2026-XXXXXX) NO existe como campo en ArcGIS -- lo
# generamos nosotros al pasar los datos a MySQL. Por eso este script empareja
# por ORDEN (mismo orden en que se generaron los códigos) y lo verifica
# comparando el nombre del edificio antes de tocar nada. Ver más abajo.

# ---- Base de datos MySQL de tu sistema ----
DB_HOST     = "127.0.0.1"
DB_PORT     = 3306
DB_USUARIO  = "root"
DB_CLAVE    = "root"
DB_NOMBRE   = "inspecciones_sismos"

# ---- Carpeta donde está instalado tu sistema PHP en esta PC ----
RUTA_APP = r"/var/www/inspeccion"

# ---- Categorías válidas del sistema (ver catalogoCategoriasFoto() en
# includes/functions.php) y a qué texto de "keywords" de Survey123 deberían
# corresponder. Si tu formulario usa nombres distintos, agrega aquí más
# variantes (todo en minúsculas y sin tildes, el script normaliza solo). ----
MAPA_CATEGORIAS = {
    "general":              "general",
    "vista general":        "general",
    "fachada general":      "general",
    "columna":               "columna",
    "viga":                  "viga",
    "muro":                  "muro",
    "nodo":                  "nodo",
    "nodo conexion":         "nodo",
    "losa":                  "losa",
    "mamposteria":           "mamposteria",
    "paredes tabiqueria":    "paredes_tabiqueria",
    "paredes":               "paredes_tabiqueria",
    "escaleras":             "escaleras",
    "tanques balcones":      "tanques_balcones",
    "tanques":               "tanques_balcones",
    "balcones":              "tanques_balcones",
    "fachada":               "fachada",
    "cielo raso":            "fachada",
    "antenas":               "fachada",
    "decision":              "decision",
    "etiqueta":              "decision",
    "cartel":                "decision",
    "registro fotografico":  "general",   # "Registro Fotográfico" / campo interno "registro_fotogr_fico"
}
CATEGORIA_POR_DEFECTO = "general"  # si no se reconoce el keyword, cae aquí

# Qué tan parecidos deben ser dos nombres de edificio para darlos por buenos
# (1.0 = idénticos, 0.0 = nada que ver). 0.55 tolera tildes/mayúsculas raras,
# errores de tipeo comunes y pequeñas variaciones de redacción.
UMBRAL_SIMILITUD_NOMBRE = 0.55

# Nombres "comodín" que el sistema usó como relleno cuando Survey123 no
# traía un nombre de edificio real (ver reconstrucción de datos hecha antes).
# Si el nombre en MySQL es uno de estos, no exigimos que coincida con ArcGIS
# (probablemente en ArcGIS también viene vacío o casi vacío).
PATRONES_NOMBRE_RELLENO = ["edificacion sin nombre", "sin nombre", "no especificado"]

EXT_PERMITIDAS = {"jpg", "jpeg", "png", "webp"}

# =======================================================================


def normalizar(s):
    s = (s or "").strip().lower()
    s = unicodedata.normalize("NFD", s)
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")  # quita tildes
    s = re.sub(r"[^a-z0-9]+", " ", s).strip()
    return s


def categoria_desde_keyword(keyword, alias_por_campo=None):
    """Intenta resolver la categoría a partir del 'keywords' del adjunto.
    A veces ese valor es el texto de la pregunta (ej. 'Columna'), y a veces
    es el nombre interno del campo (ej. 'field_263') -- en ese segundo caso,
    se busca su alias real en la definición de la capa antes de comparar."""
    candidatos = [keyword]
    if alias_por_campo and keyword in alias_por_campo:
        candidatos.append(alias_por_campo[keyword])

    for texto in candidatos:
        kw = normalizar(texto)
        if not kw:
            continue
        if kw in MAPA_CATEGORIAS:
            return MAPA_CATEGORIAS[kw]
        for clave, categoria in MAPA_CATEGORIAS.items():
            if clave and clave in kw:
                return categoria
    return None  # no reconocido


def obtener_token(usuario, clave, portal_url):
    resp = requests.post(
        f"{portal_url}/sharing/rest/generateToken",
        data={"username": usuario, "password": clave, "referer": portal_url,
              "f": "json", "expiration": 60},
        timeout=30,
    )
    data = resp.json()
    if "token" not in data:
        raise RuntimeError(f"No se pudo obtener el token. Respuesta del servidor: {data}")
    return data["token"]


def obtener_definicion_capa(feature_service_url, token):
    resp = requests.get(feature_service_url, params={"f": "json", "token": token}, timeout=30)
    data = resp.json()
    if "error" in data:
        raise RuntimeError(f"Error consultando la definición de la capa: {data['error']}")
    return data


def obtener_campo_objectid(definicion):
    campo = definicion.get("objectIdField")
    if campo:
        return campo
    for f in definicion.get("fields", []):
        if f.get("type") == "esriFieldTypeOID":
            return f["name"]
    raise RuntimeError("No se pudo determinar el campo de ID único de la capa.")


def buscar_campo_por_alias(definicion, texto):
    """Busca en la lista de campos de la capa uno cuyo alias (la etiqueta que
    se ve en el formulario) contenga el texto dado, y devuelve su nombre
    interno real (que suele ser distinto, ej. 'nombre_de_edificio_o_estru')."""
    texto_norm = normalizar(texto)
    for f in definicion.get("fields", []):
        alias_norm = normalizar(f.get("alias", ""))
        if texto_norm in alias_norm or alias_norm in texto_norm:
            return f["name"]
    return None


def listar_features_ordenadas(feature_service_url, campo_oid, campos_extra, token):
    """Trae todas las features ordenadas por su ID único ascendente (con
    paginación), incluyendo los campos extra pedidos."""
    campos = campo_oid if not campos_extra else f"{campo_oid},{','.join(campos_extra)}"
    features, offset, page_size = [], 0, 1000
    while True:
        resp = requests.get(
            f"{feature_service_url}/query",
            params={"where": "1=1", "outFields": campos, "orderByFields": f"{campo_oid} ASC",
                    "resultOffset": offset, "resultRecordCount": page_size,
                    "f": "json", "token": token},
            timeout=60,
        )
        data = resp.json()
        if "error" in data:
            raise RuntimeError(data["error"])
        lote = data.get("features", [])
        features.extend(lote)
        if len(lote) < page_size:
            break
        offset += page_size
    return features


def es_nombre_de_relleno(nombre):
    n = normalizar(nombre)
    return any(patron in n for patron in PATRONES_NOMBRE_RELLENO)


def nombres_se_parecen(a, b):
    """Compara dos nombres de edificio de forma tolerante:
    - Si el de MySQL es un nombre "de relleno" (que nosotros mismos pusimos
      porque Survey123 no traía nombre), se acepta sin más.
    - Si están vacíos ambos, se acepta (no hay nada que comparar).
    - Si no, se usa una razón de similitud de texto (tolera tildes, mayúsculas
      y errores de tipeo) en vez de exigir coincidencia exacta.
    Devuelve (coincide: bool, similitud: float) para poder loguearlo."""
    if es_nombre_de_relleno(b):
        return True, 1.0
    an, bn = normalizar(a), normalizar(b)
    if not an and not bn:
        return True, 1.0
    if not an or not bn:
        return False, 0.0
    if an == bn or an in bn or bn in an:
        return True, 1.0
    ratio = difflib.SequenceMatcher(None, an, bn).ratio()
    return ratio >= UMBRAL_SIMILITUD_NOMBRE, ratio


def obtener_alias_todo_el_servicio(feature_service_url, token):
    """Survey123 a veces guarda las preguntas de foto de un grupo repetido
    ('repeat') en una TABLA relacionada distinta a la capa principal (capa 0).
    Los adjuntos igual quedan ligados a la feature principal, pero el nombre
    de campo que aparece en sus 'keywords' (ej. 'field_263') solo se puede
    traducir a su etiqueta real consultando esa tabla relacionada. Esta
    función junta los alias de TODAS las capas/tablas del servicio."""
    m = re.match(r"(.+)/(\d+)$", feature_service_url.rstrip("/"))
    if not m:
        return {}
    base_url = m.group(1)
    resp = requests.get(base_url, params={"f": "json", "token": token}, timeout=30)
    data = resp.json()
    if "error" in data:
        return {}
    alias_total = {}
    capas = (data.get("layers") or []) + (data.get("tables") or [])
    for capa in capas:
        cid = capa.get("id")
        if cid is None:
            continue
        try:
            r2 = requests.get(f"{base_url}/{cid}", params={"f": "json", "token": token}, timeout=30)
            d2 = r2.json()
            for f in d2.get("fields", []):
                if f["name"] not in alias_total:
                    alias_total[f["name"]] = f.get("alias", "")
        except Exception:
            continue
    return alias_total


def conectar_bd():
    return pymysql.connect(
        host=DB_HOST, port=DB_PORT, user=DB_USUARIO, password=DB_CLAVE,
        database=DB_NOMBRE, charset="utf8mb4", autocommit=False,
    )


def main():
    modo_prueba = "--prueba" in sys.argv

    print("Generando token de acceso a ArcGIS...")
    token = obtener_token(USUARIO, CLAVE, PORTAL_URL)

    definicion = obtener_definicion_capa(FEATURE_SERVICE_URL, token)
    campo_oid = obtener_campo_objectid(definicion)
    campo_edificio = buscar_campo_por_alias(definicion, "Nombre de edificio o estructura")
    if not campo_edificio:
        print("[ERROR] No pude encontrar en ArcGIS el campo del nombre del edificio "
              "para poder verificar el emparejamiento. Revisa los alias de campos de tu "
              "capa (f=json) y dime el nombre exacto para ajustarlo a mano.")
        return
    print(f"Campo de ID único detectado: {campo_oid}")
    print(f"Campo de nombre de edificio detectado: {campo_edificio}\n")

    # nombre interno de campo -> alias (etiqueta real de la pregunta), para
    # poder resolver keywords tipo "field_263" a su nombre real. Se combina
    # la capa principal con todas las tablas relacionadas (repeats).
    alias_por_campo = {f["name"]: f.get("alias", "") for f in definicion.get("fields", [])}
    print("Buscando etiquetas de preguntas en todas las tablas relacionadas (repeats)...")
    alias_por_campo.update({k: v for k, v in obtener_alias_todo_el_servicio(FEATURE_SERVICE_URL, token).items()
                             if k not in alias_por_campo or not alias_por_campo[k]})
    print(f"Total de campos con alias conocido: {len(alias_por_campo)}\n")

    print("Consultando inspecciones en ArcGIS (ordenadas por ID)...")
    features = listar_features_ordenadas(FEATURE_SERVICE_URL, campo_oid, [campo_edificio], token)
    print(f"Se encontraron {len(features)} registros en ArcGIS.\n")

    print("Conectando a la base de datos MySQL local...")
    conn = conectar_bd()
    cur = conn.cursor()
    print("Conexión a MySQL exitosa.\n")

    keywords_desconocidos = {}
    omitidos = []  # para el CSV de revisión manual
    total_fotos_ok = 0
    total_inspecciones_afectadas = 0
    total_sin_codigo_mysql = 0
    total_no_verificados = 0

    # El código (INS-2026-XXXXXX) se asignó en MySQL siguiendo el mismo orden
    # en que salen los registros de ArcGIS (por ID ascendente). Por eso aquí
    # se recorren en ese mismo orden y se calcula el código esperado según
    # la posición -- y se VERIFICA con el nombre del edificio antes de usarlo,
    # para no arriesgarse a mezclar fotos de una inspección con otra.
    for posicion, feat in enumerate(features, start=1):
        attrs = feat["attributes"]
        oid = attrs[campo_oid]
        nombre_arcgis = attrs.get(campo_edificio) or ""
        codigo_esperado = f"INS-2026-{posicion:06d}"

        cur.execute("SELECT id, nombre_edificio FROM inspecciones WHERE codigo = %s LIMIT 1",
                    (codigo_esperado,))
        row = cur.fetchone()
        if not row:
            total_sin_codigo_mysql += 1
            continue
        inspeccion_id, nombre_mysql = row

        coincide, similitud = nombres_se_parecen(nombre_arcgis, nombre_mysql)
        if not coincide:
            total_no_verificados += 1
            omitidos.append((codigo_esperado, nombre_arcgis, nombre_mysql, round(similitud, 2)))
            print(f"[OMITIDO] {codigo_esperado}: el nombre de edificio no coincide "
                  f"(ArcGIS='{nombre_arcgis}' vs MySQL='{nombre_mysql}', similitud={similitud:.2f}). "
                  f"No se tocó esta inspección -- revísala manualmente.")
            continue

        # 2. Listar adjuntos (con keywords) de esta feature
        resp = requests.get(f"{FEATURE_SERVICE_URL}/{oid}/attachments",
                             params={"f": "json", "token": token}, timeout=30)
        adjuntos = resp.json().get("attachmentInfos", [])
        if not adjuntos:
            continue

        carpeta_destino = os.path.join(RUTA_APP, "uploads", "inspecciones", str(inspeccion_id))
        if not modo_prueba:
            os.makedirs(carpeta_destino, exist_ok=True)

        fotos_de_esta_inspeccion = 0
        for adj in adjuntos:
            att_id = adj["id"]
            nombre_original = adj.get("name", f"foto_{att_id}")
            keyword_original = adj.get("keywords", "")
            categoria = categoria_desde_keyword(keyword_original, alias_por_campo)
            if categoria is None:
                alias_resuelto = alias_por_campo.get(keyword_original, "")
                clave_log = f"{keyword_original or '(vacio)'}" + (f" (alias: {alias_resuelto})" if alias_resuelto else "")
                keywords_desconocidos[clave_log] = keywords_desconocidos.get(clave_log, 0) + 1
                categoria = CATEGORIA_POR_DEFECTO

            ext = os.path.splitext(nombre_original)[1].lstrip(".").lower() or "jpg"
            if ext not in EXT_PERMITIDAS:
                ext = "jpg"
            nombre_archivo = f"{categoria}_{time.strftime('%Y%m%d%H%M%S')}_{att_id}.{ext}"
            ruta_relativa = f"uploads/inspecciones/{inspeccion_id}/{nombre_archivo}"
            ruta_absoluta = os.path.join(carpeta_destino, nombre_archivo)

            if modo_prueba:
                print(f"  [PRUEBA] {codigo_esperado} (id={inspeccion_id}): "
                      f"{nombre_original} -> categoria='{categoria}' (keyword='{keyword_original}')")
            else:
                # Revisar primero si ya existe (para no descargar de nuevo lo
                # que ya se bajó en una corrida anterior; si ya existe pero
                # con otra categoría, solo se corrige la categoría en la BD).
                cur.execute(
                    "SELECT id, categoria FROM inspeccion_fotos WHERE inspeccion_id=%s AND nombre_original=%s LIMIT 1",
                    (inspeccion_id, nombre_original),
                )
                existente = cur.fetchone()
                if existente:
                    foto_id, categoria_previa = existente
                    if categoria_previa != categoria:
                        cur.execute("UPDATE inspeccion_fotos SET categoria=%s WHERE id=%s",
                                    (categoria, foto_id))
                        print(f"  [ACTUALIZADO] {codigo_esperado}/{nombre_original}: "
                              f"categoria '{categoria_previa}' -> '{categoria}'")
                    total_fotos_ok += 1
                    fotos_de_esta_inspeccion += 1
                    continue

                for intento in range(3):
                    try:
                        r = requests.get(f"{FEATURE_SERVICE_URL}/{oid}/attachments/{att_id}",
                                          params={"token": token}, timeout=60)
                        r.raise_for_status()
                        with open(ruta_absoluta, "wb") as f:
                            f.write(r.content)
                        break
                    except Exception as e:
                        if intento == 2:
                            print(f"  [ERROR] No se pudo descargar {codigo_esperado}/{nombre_original}: {e}")
                            ruta_absoluta = None
                        else:
                            time.sleep(2)
                if not ruta_absoluta:
                    continue

                cur.execute(
                    "INSERT INTO inspeccion_fotos (inspeccion_id, categoria, ruta, nombre_original) "
                    "VALUES (%s, %s, %s, %s)",
                    (inspeccion_id, categoria, ruta_relativa, nombre_original),
                )

            total_fotos_ok += 1
            fotos_de_esta_inspeccion += 1

        if fotos_de_esta_inspeccion:
            total_inspecciones_afectadas += 1
            print(f"[{posicion}/{len(features)}] {codigo_esperado} ({nombre_mysql}): "
                  f"{fotos_de_esta_inspeccion} foto(s)")

    if not modo_prueba:
        conn.commit()
    conn.close()

    print("\n==============================================")
    print(f"{'[PRUEBA] ' if modo_prueba else ''}Fotos procesadas: {total_fotos_ok}")
    print(f"Inspecciones con al menos una foto: {total_inspecciones_afectadas}")
    if total_sin_codigo_mysql:
        print(f"[INFO] {total_sin_codigo_mysql} registros de ArcGIS no tienen todavía "
              f"un código correspondiente en tu MySQL (probablemente inspecciones nuevas "
              f"que aún no has importado).")
    if total_no_verificados:
        print(f"[AVISO] {total_no_verificados} inspecciones se OMITIERON porque el nombre "
              f"del edificio no coincidió entre ArcGIS y MySQL -- revísalas a mano (mira "
              f"los mensajes [OMITIDO] de arriba).")
        ruta_csv = os.path.abspath("omitidos_revisar.csv")
        with open(ruta_csv, "w", newline="", encoding="utf-8-sig") as f:
            w = csv.writer(f)
            w.writerow(["codigo", "nombre_arcgis", "nombre_mysql", "similitud"])
            w.writerows(omitidos)
        print(f"[INFO] Detalle guardado en: {ruta_csv}")
    if keywords_desconocidos:
        print("\n[AVISO] Se encontraron categorías (keywords) que no reconocí, se "
              f"clasificaron como '{CATEGORIA_POR_DEFECTO}'. Dime estos nombres para "
              "ajustar el MAPA_CATEGORIAS:")
        for kw, n in sorted(keywords_desconocidos.items(), key=lambda x: -x[1]):
            print(f"   - '{kw}': {n} foto(s)")
    print("==============================================")
    if modo_prueba:
        print("\nEsto fue solo una prueba, no se copió ni insertó nada todavía.")
        print("Si se ve bien, corre de nuevo sin --prueba para aplicar los cambios.")


if __name__ == "__main__":
    main()
