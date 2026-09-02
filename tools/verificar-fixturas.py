#!/usr/bin/env python3
"""
Ejecuta los INSERT de las pruebas contra el esquema de referencia.

POR QUE EXISTE
--------------
Tres iteraciones seguidas se entregaron en rojo por la misma razon, y las tres
veces el sintoma fue el mismo: 14 o 15 pruebas fallando de golpe en `setUp()`
con un codigo de error de la base.

    3.6  ->  1054  columna que aun no existia
    3.8  ->  1364  `created_by_user_id` sin valor por defecto
    3.9  ->  4025  `ck_creators_activation` y `ck_camp_confirmed`

Y las tres veces la causa de fondo fue la misma frase escrita en un fixture:

    'status' => 'active'        'status' => 'in_progress'

Una palabra de estado tecleada como si fuera una palabra, cuando en este
esquema un estado es una AFIRMACION CON EVIDENCIA DETRAS. `active` no es una
cadena: son tres restricciones --fecha de activacion, identidad verificada, y
quien la verifico con que documento--. `in_progress` exige `confirmed_at`.

El fixture no miente a proposito; miente porque nadie lo confronta con la base
hasta que corre PHPUnit. Y PHPUnit no se puede correr desde el contenedor donde
se escriben estos archivos --no hay `vendor/`--, asi que el fallo solo aparecia
en la maquina de quien recibe la entrega. Este script cierra ese hueco: no
necesita Laravel, solo el esquema de referencia y una base con la semilla.

QUE HACE Y QUE NO
-----------------
Extrae los literales de `DB::table('x')->insert([...])` y los EJECUTA. Lo que
no puede evaluar --`now()`, `Str::uuid()`, `$this->creadorId`, una subconsulta--
lo sustituye por un valor sintetico del tipo correcto, resolviendo las claves
ajenas contra filas que ya existen.

Por tanto: los valores LITERALES son los de verdad y son los que se comprueban.
Lo sintetico solo esta para que la fila llegue entera a la base. Un fixture que
pase aqui puede seguir fallando por logica de negocio; uno que falle aqui va a
fallar en PHPUnit seguro.

Uso:  python3 tools/verificar-fixturas.py [base] [--cliente mysql8]
"""

import json
import re
import shlex
import subprocess
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
PRUEBAS = RAIZ / '.entrega' / 'tests'
if not PRUEBAS.is_dir():
    PRUEBAS = RAIZ / 'tests'

BASE = 'latam_m8_57'
CLIENTE = 'mysql8'

argumentos = sys.argv[1:]
if '--cliente' in argumentos:
    i = argumentos.index('--cliente')
    CLIENTE = argumentos[i + 1]
    del argumentos[i:i + 2]
if argumentos:
    BASE = argumentos[0]

# El cliente puede venir como una orden entera --en CI es
# `mysql -h127.0.0.1 -P3307 --protocol=TCP -uroot -proot`--, no solo como el
# nombre de un ejecutable. Se parte en argumentos; si no, `subprocess` busca un
# programa llamado "mysql -h127.0.0.1 ..." y no lo encuentra.
ORDEN = shlex.split(CLIENTE)


def consultar(sql, base=BASE):
    p = subprocess.run(ORDEN + [base, '-N', '-B', '-e', sql],
                       capture_output=True, text=True)
    if p.returncode != 0:
        raise SystemExit(f'  La base no responde: {p.stderr.strip()}')
    lineas = [l for l in p.stdout.splitlines() if not l.startswith('mysql: [Warning]')]
    return [l.split('\t') for l in lineas if l]


# --------------------------------------------------------------- el esquema

def cargar_esquema():
    cols = {}
    for tabla, col, nulo, defecto, tipo, tipodato, extra in consultar(
        # Centinela explicito, no `\\0`: el cliente escribe la BARRA y el CERO
        # como dos caracteres, no un byte nulo, asi que comparar con "\\0" de
        # Python no casaba nunca. Efecto: toda columna parecia tener valor por
        # defecto y el gate no rellenaba ninguna obligatoria. Silencioso y
        # total --el gate decia verde porque no llegaba a preguntar--.
        "SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, IFNULL(COLUMN_DEFAULT,'<<NADA>>'), "
        "COLUMN_TYPE, DATA_TYPE, EXTRA FROM information_schema.COLUMNS "
        f"WHERE TABLE_SCHEMA='{BASE}' ORDER BY TABLE_NAME, ORDINAL_POSITION"
    ):
        cols.setdefault(tabla, {})[col] = {
            'nulo': nulo == 'YES',
            'defecto': None if defecto == '<<NADA>>' else defecto,
            'tipo': tipo,
            'dato': tipodato,
            'extra': extra,
        }
    fks = {}
    for tabla, col, rt, rc in consultar(
        "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME "
        "FROM information_schema.KEY_COLUMN_USAGE "
        f"WHERE TABLE_SCHEMA='{BASE}' AND REFERENCED_TABLE_NAME IS NOT NULL"
    ):
        fks.setdefault(tabla, {})[col] = (rt, rc)
    return cols, fks


# ------------------------------------------------------------- el PHP

LLAMADA = re.compile(r"DB::table\(\s*'(\w+)'\s*\)\s*->\s*insert(?:GetId)?\(\s*\[")


def bloques(texto):
    """Devuelve (tabla, cuerpo, linea) por cada insert, casando corchetes."""
    for m in LLAMADA.finditer(texto):
        tabla = m.group(1)
        i = m.end() - 1           # sobre el '['
        prof, j, en_cadena, comilla = 0, i, False, ''
        while j < len(texto):
            c = texto[j]
            if en_cadena:
                if c == '\\':
                    j += 2
                    continue
                if c == comilla:
                    en_cadena = False
            elif c in "'\"":
                en_cadena, comilla = True, c
            elif c == '[':
                prof += 1
            elif c == ']':
                prof -= 1
                if prof == 0:
                    break
            j += 1
        yield tabla, texto[i + 1:j], texto[:m.start()].count('\n') + 1


PAR = re.compile(r"'(\w+)'\s*=>\s*")


def pares(cuerpo):
    """('col', texto crudo del valor) respetando corchetes, parentesis y cadenas."""
    salida = []
    for m in PAR.finditer(cuerpo):
        j, prof, en_cadena, comilla = m.end(), 0, False, ''
        while j < len(cuerpo):
            c = cuerpo[j]
            if en_cadena:
                if c == '\\':
                    j += 2
                    continue
                if c == comilla:
                    en_cadena = False
            elif c in "'\"":
                en_cadena, comilla = True, c
            elif c in '([{':
                prof += 1
            elif c in ')]}':
                if prof == 0:
                    break
                prof -= 1
            elif c == ',' and prof == 0:
                break
            j += 1
        salida.append((m.group(1), cuerpo[m.end():j].strip()))
    return salida


LITERAL_CADENA = re.compile(r"^'((?:[^'\\]|\\.)*)'$")
LITERAL_ENTERO = re.compile(r'^-?\d+$')


def literal(bruto):
    """(es_literal, valor). Solo cadenas simples, enteros, null y booleanos."""
    b = bruto.strip()
    m = LITERAL_CADENA.match(b)
    if m:
        return True, m.group(1).replace("\\'", "'").replace('\\\\', '\\')
    if LITERAL_ENTERO.match(b):
        return True, int(b)
    if b == 'null':
        return True, None
    if b in ('true', 'false'):
        return True, 1 if b == 'true' else 0
    return False, None


# --------------------------------------------------- valores sinteticos

def escapar(v):
    if v is None:
        return 'NULL'
    if isinstance(v, int):
        return str(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"


contador = [0]


cols_global = [None]


# 9.17j -- Las tablas que son de Laravel y no de este proyecto.
#
# `migrations` no esta en `tools/sql/` y no debe estarlo: es la contabilidad del
# framework, no parte del modelo. Pero `EsquemaTest` escribe en ella a proposito
# --quita una fila para comprobar que el sistema se entera de que le falta
# migrar-- y sin esta lista el verificador la denuncia por no existir.
#
# Se nombran una por una y no se acepta cualquier tabla desconocida: «no esta en
# el esquema» es exactamente el hallazgo que este verificador existe para dar.
DEL_FRAMEWORK = {'migrations', 'sessions', 'cache', 'cache_locks',
                 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'}


def fabricar_fila(tabla, columna, cols, fks, cache, enumerados, visitadas):
    """Inserta una fila minima en `tabla` y devuelve su clave.

    Recursiva: si `tabla` tiene una clave ajena a otra tabla vacia, sube
    primero. `visitadas` corta los ciclos (una tabla que se referencia a si
    misma acabaria aqui para siempre).
    """
    if tabla in visitadas or tabla not in cols:
        return None
    visitadas = visitadas | {tabla}

    nombres, valores = [], []
    for col, info in cols[tabla].items():
        if 'auto_increment' in info['extra'] or 'GENERATED' in info['extra'].upper():
            continue
        if info['nulo'] or info['defecto'] is not None:
            continue
        ref = fks.get(tabla, {}).get(col)
        if ref:
            if ref not in cache:
                filas = consultar(f'SELECT `{ref[1]}` FROM `{ref[0]}` ORDER BY `{ref[1]}` LIMIT 1')
                cache[ref] = (filas[0][0] if filas
                              else fabricar_fila(ref[0], ref[1], cols, fks, cache,
                                                 enumerados, visitadas))
            val = cache[ref]
            if val is None:
                return None
        else:
            val = sintetico(tabla, col, info, fks, cache, enumerados)
        nombres.append(f'`{col}`')
        valores.append(escapar(val))

    sql = f'INSERT INTO `{tabla}` (' + ', '.join(nombres) + ') VALUES (' + ', '.join(valores) + ');'
    p = subprocess.run(ORDEN + [BASE, '-e', sql], capture_output=True, text=True)
    if p.returncode != 0:
        if '-v' in sys.argv:
            motivo = next((l for l in p.stderr.splitlines()
                           if not l.startswith('mysql: [Warning]')), '?')
            print(f'      (no pude fabricar fila en `{tabla}`: {motivo.strip()})')
        return None
    filas = consultar(f'SELECT `{columna}` FROM `{tabla}` ORDER BY `{columna}` DESC LIMIT 1')
    return filas[0][0] if filas else None


ENUMERADO = re.compile(r"^(\w+)\s+IN\s*\((.+)\)$", re.IGNORECASE | re.DOTALL)


def cargar_enumerados():
    """col -> primer valor permitido, para los CHECK de la forma `col IN (...)`.

    Sin esto la sintesis inventaba `status = 'fx17'` y la base lo rechazaba con
    toda la razon. El gate acusaba entonces al fixture de un fallo que era mio.
    """
    ruta = RAIZ / 'tools' / 'sql' / 'generado' / 'declaraciones.json'
    fuera = {}
    if not ruta.is_file():
        return fuera
    for d in json.loads(ruta.read_text(encoding='utf-8')):
        m = ENUMERADO.match(d['expresion'].strip())
        if m and len(d['columnas']) == 1:
            valores = re.findall(r"'((?:[^'\\]|\\.)*)'", m.group(2))
            if valores:
                fuera[(d['tabla'], m.group(1))] = valores[0]
    return fuera


def sintetico(tabla, col, info, fks, cache, enumerados):
    """Un valor que la base acepte, para lo que no se puede evaluar desde PHP."""
    ref = fks.get(tabla, {}).get(col)
    if ref:
        clave = ref
        if clave not in cache:
            filas = consultar(f'SELECT `{ref[1]}` FROM `{ref[0]}` ORDER BY `{ref[1]}` LIMIT 1')
            if filas:
                cache[clave] = filas[0][0]
            else:
                # La tabla destino esta vacia. Antes me rendia aqui y 14 inserts
                # se quedaban sin veredicto por culpa de `roles`, `payout_batches`
                # y `social_accounts`. Ahora fabrico la fila que falta, tirando
                # del hilo hacia arriba tantas veces como haga falta.
                cache[clave] = fabricar_fila(ref[0], ref[1], cols_global[0], fks, cache,
                                             enumerados, set())
        return cache[clave]

    if (tabla, col) in enumerados:
        return enumerados[(tabla, col)]

    if info['dato'] == 'enum':
        m = re.findall(r"'((?:[^'\\]|\\.)*)'", info['tipo'])
        if m:
            return m[0]

    d = info['dato']
    contador[0] += 1
    n = contador[0]
    if d in ('int', 'bigint', 'smallint', 'tinyint', 'mediumint'):
        return 1
    if d in ('decimal', 'float', 'double'):
        return 1
    if d in ('datetime', 'timestamp'):
        return '2026-01-01 00:00:00'
    if d == 'date':
        return '2026-01-01'
    if d == 'time':
        return '00:00:00'
    if d == 'json':
        return '{}'
    if d == 'char' and '(36)' in info['tipo']:
        return f'{n:08x}-0000-4000-8000-{n:012x}'
    m = re.search(r'\((\d+)\)', info['tipo'])
    largo = int(m.group(1)) if m else 40
    base = f'fx{n}'
    return base[:largo] if largo >= len(base) else base[:largo]


# ------------------------------------------------------------------ correr

COLUMNAS_DE = {}
MENSAJES_DE = {}
# Tablas que no se dejaron vaciar, y reglas que miran OTRAS FILAS de la tabla.
TOZUDAS = set()
MIRAN_OTRAS_FILAS = set()


def cargar_columnas_de_restriccion():
    ruta = RAIZ / 'tools' / 'sql' / 'generado' / 'declaraciones.json'
    if not ruta.is_file():
        return {}
    return {d['nombre']: set(d['columnas'])
            for d in json.loads(ruta.read_text(encoding='utf-8'))}


def cargar_reglas_que_miran_otras_filas():
    """Disparadores cuyo cuerpo consulta la propia tabla.

    Hacen falta para no acusar en falso. Una regla que solo mira la fila que
    entra --un CHECK de columna-- se puede juzgar con la tabla llena o vacia. Una
    que mira OTRAS FILAS, no: si la tabla no se pudo vaciar, el rechazo puede
    venir de una fila de la semilla y no del fixture.

    Paso de verdad con `terms_versions`: desde que es evidencia (`T-16`) no se
    deja vaciar, asi que la version que trae la semilla se queda ahi y choca con
    la que inserta la prueba. El fixture no tenia ningun defecto.
    """
    fuera = set()
    for fila in consultar(
        "SELECT TRIGGER_NAME, REPLACE(ACTION_STATEMENT, '\n', ' ') "
        f"FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='{BASE}'"
    ):
        nombre, cuerpo = fila[0], fila[1]
        if re.search(r'SELECT\s+1\s+FROM', cuerpo, re.IGNORECASE):
            fuera.add(nombre)
            for mensaje in re.findall(r"MESSAGE_TEXT\s*=\s*'((?:[^']|'')*)'", cuerpo):
                fuera.add(mensaje.replace("''", "'").strip())
    return fuera


def cargar_mensajes_de_disparador():
    """mensaje de SIGNAL -> columnas que ese disparador mira.

    Los disparadores escritos a mano no pasan por el compilador de
    restricciones, asi que no estan en `declaraciones.json` y su mensaje no
    lleva el nombre de nada: «El medio de pago no es del creador al que se le
    paga.» no se puede atribuir a ciegas.

    Aqui se leen del propio motor: se saca el texto de cada `SIGNAL` y las
    columnas `NEW.x` que el cuerpo consulta. Con eso, un disparador hecho a
    mano se puede atribuir igual que un CHECK generado.
    """
    fuera = {}
    filas = consultar("SELECT REPLACE(ACTION_STATEMENT, '\\n', ' ') "
                      f"FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='{BASE}'")
    for fila in filas:
        cuerpo = fila[0]
        for mensaje in re.findall(r"MESSAGE_TEXT\s*=\s*'((?:[^']|'')*)'", cuerpo):
            columnas = set(re.findall(r'NEW\.(\w+)', cuerpo))
            if columnas:
                fuera.setdefault(mensaje.replace("''", "'").strip(), set()).update(columnas)
    return fuera


NOMBRE_RESTRICCION = re.compile(
    r'Restriccion (\w+) incumplida|CONSTRAINT `(\w+)` failed', re.IGNORECASE)


def culpa_del_fixture(error, literales, tabla):
    """
    ?Este rechazo lo provoco el fixture, o lo provoque yo al rellenar huecos?

    Es la pregunta central del script y la razon de que sirva para algo. La
    primera version acusaba al fixture de TODO lo que la base rechazaba, y de
    17 avisos 12 eran mios: valores sinteticos que incumplian un CHECK. Un gate
    que grita sin razon se acaba ignorando, y entonces no es un gate.

    La regla: un CHECK solo acusa al fixture si el fixture puso un LITERAL en
    alguna de las columnas que ese CHECK mira. `ck_creators_activation` mira
    `status` y `activated_at`, y el fixture escribio `status => 'active'`:
    suyo. `ck_cc_status` mira `status`, y el fixture puso ahi una variable:
    mio, y me callo.

    Devuelve (acusar, motivo_si_no).
    """
    if 'Duplicate entry' in error:
        # Choque con la semilla, no contradiccion con el esquema. En una base
        # de pruebas recien creada la fila entraria.
        return False, 'choca con la semilla, no con el esquema'

    if tabla in TOZUDAS:
        # La tabla no se pudo vaciar --lleva `no_delete`--, asi que puede haber
        # filas de la semilla estorbando. Solo importa si la regla que rechazo
        # mira otras filas; un CHECK de columna se juzga igual de bien lleno.
        m = NOMBRE_RESTRICCION.search(error)
        etiqueta = (m.group(1) or m.group(2)) if m else None
        mira_otras = (etiqueta is not None
                      and any(etiqueta in r or r in etiqueta for r in MIRAN_OTRAS_FILAS))
        if not mira_otras:
            mira_otras = any(r and r in error for r in MIRAN_OTRAS_FILAS)
        if mira_otras:
            return False, ('la tabla no se deja vaciar (`no_delete`) y la regla mira otras '
                           'filas: el rechazo puede venir de la semilla')

    if 'Unknown column' in error:
        return True, None                      # 1054: siempre del fixture

    m = re.search(r"Column '(\w+)' cannot be null", error)
    if m:
        if m.group(1) in literales:
            return True, None
        return False, f'no pude sintetizar `{m.group(1)}` (su tabla destino esta vacia)'

    if "doesn't have a default value" in error:
        return True, None                      # 1364: columna obligatoria ausente

    m = NOMBRE_RESTRICCION.search(error)
    nombre, miradas = None, None
    if m:
        nombre = m.group(1) or m.group(2)
        miradas = COLUMNAS_DE.get(nombre)
    else:
        # Disparador escrito a mano: no dice su nombre, solo su queja. Se busca
        # por el texto del mensaje.
        for mensaje, columnas in MENSAJES_DE.items():
            if mensaje and mensaje in error:
                nombre, miradas = f'"{mensaje}"', columnas
                break

    if miradas is None:
        return True, None                      # no la conozco: mejor avisar
    if miradas & set(literales):
        return True, None
    return False, f'{nombre} mira {sorted(miradas)}, y de esas el fixture no fija ninguna'


def main():
    global COLUMNAS_DE, MENSAJES_DE, TOZUDAS, MIRAN_OTRAS_FILAS
    cols, fks = cargar_esquema()
    cols_global[0] = cols
    COLUMNAS_DE = cargar_columnas_de_restriccion()
    MENSAJES_DE = cargar_mensajes_de_disparador()
    MIRAN_OTRAS_FILAS = cargar_reglas_que_miran_otras_filas()
    enumerados = cargar_enumerados()
    cache = {}
    problemas = []
    inconcluyentes = []
    revisados = 0

    archivos = sorted(PRUEBAS.rglob('*Test.php'))
    if not archivos:
        raise SystemExit(f'  No encontre pruebas en {PRUEBAS}')

    # Las tablas donde los fixtures escriben se vacian ANTES de empezar.
    #
    # La semilla trae su propia `anatorres`, con el mismo documento que casi
    # todos los fixtures. El resultado era que 10 de los 40 inserts morian con
    # `Duplicate entry` --un choque con la semilla, no una contradiccion con el
    # esquema-- y se quedaban sin veredicto. Justo `creators`, que es la tabla
    # que mas se toca y donde ya se escondieron dos de estos fallos.
    #
    # Se vacian solo las que los fixtures usan; los catalogos (paises, monedas,
    # formatos, usuarios) se quedan, que son de donde salen las claves ajenas.
    destinos = {t for a in archivos
                for t, _, _ in bloques(a.read_text(encoding='utf-8'))
                if t in cols}
    #
    # Una por una, y tolerando el fallo. En un solo lote no funciona: varias de
    # estas tablas llevan un disparador `no_delete` --lo financiero no se borra
    # nunca, es regla del proyecto-- y el primer rechazo aborta el resto del
    # lote. Con `creator_payment_methods` reventando en medio, `creators` se
    # quedaba sin vaciar y volvia a chocar con la semilla. Sintoma raro, causa
    # tonta: el orden alfabetico.
    #
    # Las que se niegan a vaciarse conservan sus filas de la semilla, y sus
    # fixtures se quedaran sin veredicto. Es correcto que sea asi: la regla del
    # esquema manda sobre la comodidad del gate.
    tozudas = []
    for t in sorted(destinos):
        r = subprocess.run(
            ORDEN + [BASE, '-e', f'SET FOREIGN_KEY_CHECKS=0; DELETE FROM `{t}`;'],
            capture_output=True, text=True)
        if r.returncode != 0:
            tozudas.append(t)
    TOZUDAS = set(tozudas)
    if tozudas and '-v' in sys.argv:
        print('  No se dejan vaciar (disparador `no_delete`): ' + ', '.join(tozudas))

    a_proposito = []
    del_framework = []

    for archivo in archivos:
        texto = archivo.read_text(encoding='utf-8')
        lineas = texto.split('\n')
        for tabla, cuerpo, linea in bloques(texto):
            donde = f'{archivo.relative_to(RAIZ)}:{linea}  {tabla}'

            # Un insert que la prueba espera que la base RECHACE.
            #
            # Hasta ahora esta herramienta no sabia distinguir «un fixture que
            # miente» de «un fixture que demuestra que la base no se deja»: los
            # segundos salian en rojo con toda la razon tecnica y ninguna
            # practica. Las pruebas que afirman un rechazo de UNICIDAD se
            # escapaban por casualidad --un insert aislado no choca con nada-- y
            # las que afirman un CHECK de una sola fila, no.
            #
            # El marcador va en la linea de ANTES y hay que escribirlo a mano,
            # que es lo que impide ponerlo por costumbre. Se cuentan y se
            # imprimen: una lista de excepciones que nadie mira crece sola.
            # Se miran las TRES lineas de antes, no solo la inmediata: el
            # marcador suele ir al principio de un comentario que explica por que,
            # y exigir que sea la ultima linea del comentario obligaria a
            # escribirlo al reves de como se lee.
            previas = '\n'.join(lineas[max(0, linea - 4):linea - 1])

            if 'fixture-invalido-a-proposito' in previas:
                a_proposito.append(donde)
                continue

            if tabla in DEL_FRAMEWORK:
                del_framework.append(donde)
                continue

            if tabla not in cols:
                problemas.append((donde, f'la tabla `{tabla}` no existe en el esquema'))
                continue

            revisados += 1
            asignados = pares(cuerpo)
            nombres = [c for c, _ in asignados]

            desconocidas = [c for c in nombres if c not in cols[tabla]]
            if desconocidas:
                problemas.append((donde, 'columnas que no existen: ' + ', '.join(desconocidas)))
                continue

            columnas, valores, hubo_literal = [], [], []
            literales_puestos = set()
            for col, bruto in asignados:
                info = cols[tabla][col]
                es_lit, val = literal(bruto)
                if es_lit:
                    hubo_literal.append(f'{col}={val!r}')
                    literales_puestos.add(col)
                else:
                    val = sintetico(tabla, col, info, fks, cache, enumerados)
                columnas.append(f'`{col}`')
                valores.append(escapar(val))

            # Las que la base exige y el fixture no menciona.
            #
            # Se rellenan para que el fallo que salga despues sea de RESTRICCION
            # y no un 1364 que tape lo demas... pero rellenarlas y callarse era
            # un agujero: una columna obligatoria que el fixture se deja SIEMPRE
            # revienta en PHPUnit con `1364`, y este gate la tapaba.
            #
            # Paso de verdad: un fixture de `terms_versions` se dejo `title`,
            # el gate lo relleno, dijo verde, y PHPUnit fallo. Asi que ahora se
            # rellenan Y se denuncian.
            faltan_obligatorias = []
            for col, info in cols[tabla].items():
                if col in nombres or info['nulo'] or info['defecto'] is not None:
                    continue
                if 'auto_increment' in info['extra'] or 'GENERATED' in info['extra'].upper():
                    continue
                faltan_obligatorias.append(col)
                columnas.append(f'`{col}`')
                valores.append(escapar(sintetico(tabla, col, info, fks, cache, enumerados)))

            sql = (f'INSERT INTO `{tabla}` (' + ', '.join(columnas) + ') VALUES ('
                   + ', '.join(valores) + ');')

            if faltan_obligatorias:
                problemas.append((donde,
                    'el fixture no da columnas OBLIGATORIAS sin valor por defecto: '
                    + ', '.join(faltan_obligatorias)
                    + '\n      En PHPUnit esto es un `1364 Field ... doesn\'t have a default value`.'))

            p = subprocess.run(ORDEN + [BASE, '-e', 'START TRANSACTION;\n' + sql + '\nROLLBACK;'],
                               capture_output=True, text=True)
            err = '\n'.join(l for l in p.stderr.splitlines()
                            if not l.startswith('mysql: [Warning]')).strip()
            if err:
                # La linea que importa es la que empieza por `ERROR`, no la
                # primera. Ante un fallo, el cliente de MariaDB escribe antes el
                # eco de la sentencia enmarcado en guiones:
                #
                #     --------------
                #     INSERT INTO `terms_versions` (...) VALUES (...)
                #     --------------
                #
                #     ERROR 1364 (HY000) at line 2: Field 'uuid' doesn't ...
                #
                # Coger `err.split('\n')[0]` daba `--------------`, que no casa
                # con ningun patron conocido, y `culpa_del_fixture()` caia hasta
                # el final acusando al fixture. O sea: el gate culpaba al fixture
                # justo cuando no habia entendido el error. Seis fixturas sanas
                # salieron seniadas asi, con `--------------` por motivo.
                #
                # Si no hay linea `ERROR`, no se acusa a nadie: sin veredicto.
                detalle = next((l.strip() for l in err.splitlines()
                                if l.lstrip().startswith('ERROR')), '')
                if detalle == '':
                    inconcluyentes.append((donde, 'la base fallo pero no dijo ERROR: ' + err.splitlines()[0]))
                    continue
                acusar, motivo = culpa_del_fixture(detalle, literales_puestos, tabla)
                if not acusar:
                    inconcluyentes.append((donde, motivo))
                    continue
                if faltan_obligatorias:
                    detalle += '   (rellene yo: ' + ', '.join(faltan_obligatorias) + ')'
                if hubo_literal:
                    detalle += '\n      literales del fixture: ' + ', '.join(hubo_literal)
                problemas.append((donde, detalle))

    print(f'  Base: {BASE}    inserts revisados: {revisados}')
    if del_framework:
        print()
        print(f'  De tablas del framework, no del modelo: {len(del_framework)}')

    if a_proposito:
        # Se imprimen SIEMPRE, no solo con -v: una lista de excepciones que
        # nadie mira crece sola. Es la misma politica que `SIN_PERMISO` en
        # `RutasProtegidasTest`.
        print(f'  Invalidos a proposito (la prueba afirma el rechazo): {len(a_proposito)}')
        for donde in a_proposito:
            print(f'      - {donde}')
    if inconcluyentes:
        print(f'  Sin veredicto: {len(inconcluyentes)} (rechazos que provoco el relleno, no el fixture)')
        if '-v' in sys.argv:
            for donde, motivo in inconcluyentes:
                print(f'      - {donde}: {motivo}')
    print()
    if not problemas:
        print('  Ningun fixture contradice al esquema.')
        return 0
    for donde, que in problemas:
        print(f'  x {donde}')
        print(f'      {que}')
        print()
    print(f'  {len(problemas)} fixture(s) que la base rechaza.')
    return 1


if __name__ == '__main__':
    sys.exit(main())
