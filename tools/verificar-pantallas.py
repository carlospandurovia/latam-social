#!/usr/bin/env python3
"""Contrasta lo que la aplicacion NOMBRA contra lo que la aplicacion TIENE.

Por que existe
--------------
En este contenedor no hay `vendor/`: packagist esta bloqueado, asi que Composer
no puede instalar nada y **PHPUnit no se puede correr aqui**. Esa es la razon
estructural por la que varias iteraciones se entregaron en rojo.

Las suites SQL cubren el esquema y `verificar-fixturas.py` cubre los `INSERT` de
las pruebas. Lo que no cubria nadie es la capa de en medio, que es justo donde
se rompen las pruebas de caracteristica:

  * `route('contactos.edit', ...)` en una plantilla, y la ruta se llama
    `contactos.editar`  -> RouteNotFoundException en la prueba
  * `view('clientes.fiscal.form')` y el archivo esta en otra carpeta
    -> InvalidArgumentException
  * `permiso:client.tax.manage` en la ruta, y el seeder declara
    `client.tax.mange` -> 403 en TODAS las pruebas de esa pantalla
  * el controlador pasa `contactos` y la plantilla usa `$contacts`
    -> ErrorException: Undefined variable

Los cuatro son errores de UNA LETRA que dejan la suite entera en rojo, y los
cuatro se ven leyendo archivos. No hacen falta ni Laravel ni base de datos.

Que NO hace
-----------
No sustituye a PHPUnit. No comprueba logica, ni redirecciones, ni permisos
efectivos. Comprueba que los nombres que se cruzan entre capas existen.

Uso:  python3 tools/verificar-pantallas.py [-v]
"""

import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent

# DOS DISPOSICIONES, LA MISMA HERRAMIENTA.
#
# En el repositorio el codigo esta en `app/`, `routes/`, `tests/`. En el area
# de entrega esta en `stage/app/`, `stage/routes/`, `.entrega/tests/`. Esta
# herramienta viaja en `tools/` y tiene que funcionar en las dos.
#
# Estuvo apuntando solo a `stage/` y por eso reventaba en el CI con un
# `FileNotFoundError` sobre una ruta que en el repositorio no existe. No salia
# verde en falso --eso habria sido peor-- pero el paso estaba roto y el mensaje
# no decia nada util. Se resuelve mirando cual de las dos existe, no
# suponiendo.
CODIGO = RAIZ / 'stage' if (RAIZ / 'stage/app').is_dir() else RAIZ
PRUEBAS = RAIZ / '.entrega/tests' if (RAIZ / '.entrega/tests').is_dir() else RAIZ / 'tests'

RUTAS = CODIGO / 'routes/web.php'
VISTAS = CODIGO / 'resources/views'
APP = CODIGO / 'app'
SEEDER = CODIGO / 'database/seeders/CimientosSeeder.php'

# Roles que NO existen, y se usan a proposito.
#
# `ConFixturas::usuarioCon()` se queja por su cuenta cuando el rol no existe, en
# vez de dejar que el `insert` falle con un `1048` que acusa a la tabla. Probar
# esa queja exige escribir un rol inexistente, y esta puerta lo cazaba --con
# razon-- porque no puede saber que es deliberado.
#
# Estar en esta lista es una decision escrita, no un olvido: mismo criterio que
# `SIN_REGLA_A_PROPOSITO` en `verificar-periodos.py`. Si algun dia alguien
# declara un rol con este nombre, la exencion sobra y hay que quitarla.
ROLES_INEXISTENTES_A_PROPOSITO = {
    'rol_inventado': 'FixturasTest comprueba que `usuarioCon()` avisa cuando el rol no existe.',
}

VERBOSO = '-v' in sys.argv


def leer(p):
    return p.read_text(encoding='utf-8')


def phps(carpeta, patron='*.php'):
    return sorted(carpeta.rglob(patron)) if carpeta.is_dir() else []


# --------------------------------------------------------------------- rutas

def nombres_de_ruta():
    return set(re.findall(r"->name\('([^']+)'\)", leer(RUTAS)))


def es_comentario(linea):
    """Los docblocks nombran ejemplos que no existen: `permiso:a,b`.

    Acusar por lo que dice un comentario es exactamente el gate que grita en
    falso, y un gate que grita en falso deja de leerse.
    """
    t = linea.lstrip()
    return t.startswith(('*', '//', '#', '/*', '{{--'))


def usos_de_ruta():
    """Cada `route('x'` con el archivo y la linea donde aparece.

    `->route('uuid')` y `request()->route('catalogo')` NO son el ayudante:
    son el ACCESOR del parametro de ruta, y llevan el nombre del parametro, no
    el de la ruta. La primera version de este gate acuso a los cuatro sitios
    donde se usan.
    """
    usos = []
    for f in phps(VISTAS, '*.blade.php') + phps(APP) + phps(PRUEBAS):
        for n, linea in enumerate(leer(f).splitlines(), 1):
            if es_comentario(linea):
                continue
            for m in re.finditer(r"(->|::)?\broute\('([^']+)'", linea):
                if m.group(1):
                    continue
                usos.append((m.group(2), f, n))
    return usos


# -------------------------------------------------------------------- vistas

def archivo_de_vista(nombre):
    return VISTAS / (nombre.replace('.', '/') + '.blade.php')


def usos_de_vista():
    """`view('x'`, `@extends('x')` y `@include('x')`."""
    usos = []
    patron = re.compile(r"\bview\('([^']+)'|@extends\('([^']+)'\)|@include\('([^']+)'")
    for f in phps(APP) + phps(VISTAS, '*.blade.php'):
        for n, linea in enumerate(leer(f).splitlines(), 1):
            for m in patron.finditer(linea):
                nombre = m.group(1) or m.group(2) or m.group(3)
                # `view()` sin argumentos, o con una variable, no se comprueba.
                if nombre and not nombre.startswith('$'):
                    usos.append((nombre, f, n))
    return usos


# ---------------------------------------------------------------- permisos

def permisos_declarados():
    return set(re.findall(r"\['([a-z][a-z0-9_.]*)',\s*'[A-Z]", leer(SEEDER)))


def permisos_usados():
    usos = []
    fuentes = [RUTAS] + phps(APP)
    patron = re.compile(r"permiso:([a-z0-9_.]+)|Permisos::tiene\([^,]+,\s*'([^']+)'|@can\('([^']+)'")
    for f in fuentes + phps(VISTAS, '*.blade.php'):
        for n, linea in enumerate(leer(f).splitlines(), 1):
            if es_comentario(linea):
                continue
            for m in patron.finditer(linea):
                codigo = m.group(1) or m.group(2) or m.group(3)
                if codigo:
                    usos.append((codigo, f, n))
    return usos


def roles_declarados():
    # ['admin', 'Administrador', 'internal', true]
    # El tercer campo es el AMBITO, y no son solo `internal`/`external`:
    # `client_user` es `client` y `creator` es `creator`. Fijarse solo en dos
    # dejaba fuera dos roles que si existen.
    return set(re.findall(r"\['([a-z_]+)',\s*'[^']+',\s*'([a-z]+)',", leer(SEEDER)))


def roles_usados():
    """`usuarioCon('x')` en las pruebas: un rol inexistente da role_id NULL."""
    usos = []
    for f in phps(PRUEBAS):
        for n, linea in enumerate(leer(f).splitlines(), 1):
            for rol in re.findall(r"usuarioCon\('([^']+)'\)", linea):
                usos.append((rol, f, n))
    return usos


# ------------------------------------------------------ variables de plantilla

# Lo que Blade o Laravel ponen en el ambito sin que nadie las pase.
REGALADAS = {
    'errors', 'message', 'loop', 'slot', 'attributes', 'component',
    'app', 'request', 'user', '__env', 'this', 'cliente_actual',
}


def variables_que_pasa_el_controlador():
    """De `view('x', ['a' => ..., 'b' => ...])`, las claves.

    Solo se queda con las plantillas que se pintan desde UN sitio: si dos
    controladores pintan la misma, cada uno puede pasar cosas distintas y
    acusar seria adivinar.
    """
    porvista = {}
    texto_por_archivo = {f: leer(f) for f in phps(APP)}

    for f, texto in texto_por_archivo.items():
        for m in re.finditer(r"\bview\('([^']+)'\s*,", texto):
            nombre = m.group(1)
            # Se recorta desde la coma hasta el `);` que cierra la llamada,
            # contando parentesis para no partir en uno anidado.
            i = m.end()
            prof, fin = 1, None
            while i < len(texto):
                if texto[i] == '(':
                    prof += 1
                elif texto[i] == ')':
                    prof -= 1
                    if prof == 0:
                        fin = i
                        break
                i += 1
            if fin is None:
                continue
            cuerpo = texto[m.end():fin]
            claves = set(re.findall(r"'([A-Za-z_][A-Za-z0-9_]*)'\s*=>", cuerpo))
            # `+ $this->opciones()` y compania: si la llamada compone con algo
            # que no se ve aqui, no se puede saber que pasa. Se descarta.
            opaca = bool(re.search(r"\+\s*\$|\.\.\.|\$\w+\s*\+", cuerpo))
            porvista.setdefault(nombre, []).append((claves, f, opaca))

    return porvista


def variables_que_usa_la_plantilla(ruta):
    """`$x` en la plantilla, menos las que la propia plantilla define."""
    texto = leer(ruta)

    definidas = set(REGALADAS)
    # alias de bucles: @foreach ($xs as $x) / ($k => $v), @forelse igual
    # `re.S`: un `@foreach` sobre un array literal de varias lineas tiene su
    # `as $k => $v` muy lejos de la apertura. Sin esto, `$k` y `$v` parecian
    # variables que nadie pasa.
    for m in re.finditer(r"@(?:foreach|forelse)\s*\(.*?\bas\b\s*(.*?)\)", texto, re.S):
        for v in re.findall(r"\$([A-Za-z_]\w*)", m.group(1)):
            definidas.add(v)
    # @php $x = ... @endphp  y  @php($x = ...)
    #
    # Se miran DOS cosas dentro del bloque: las asignaciones y los `foreach` de
    # PHP puro. Lo segundo faltaba, y el sintoma fue una acusacion falsa en
    # `candidatos.blade.php` (7.4): un `foreach ($motivos as $clave => $texto)`
    # dentro de un `@php` hacia que `$texto` pareciera una variable que el
    # controlador no pasa. Una puerta que acusa en falso se acaba ignorando, y
    # eso es peor que no tenerla.
    for m in re.finditer(r"@php(.*?)@endphp|@php\((.*?)\)", texto, re.S):
        cuerpo = (m.group(1) or '') + (m.group(2) or '')
        for v in re.findall(r"\$([A-Za-z_]\w*)\s*=", cuerpo):
            definidas.add(v)
        for f in re.finditer(r"\bforeach\s*\(.*?\bas\b\s*(.*?)\)", cuerpo, re.S):
            for v in re.findall(r"\$([A-Za-z_]\w*)", f.group(1)):
                definidas.add(v)
    # funciones flecha y closures: fn ($x) => ... / function ($x)
    for m in re.finditer(r"\bfn\s*\((.*?)\)|\bfunction\s*\((.*?)\)", texto):
        for v in re.findall(r"\$([A-Za-z_]\w*)", (m.group(1) or '') + (m.group(2) or '')):
            definidas.add(v)
    # @foreach sobre pares: `as $k => $v` ya cubierto arriba

    usadas = {}
    for n, linea in enumerate(texto.splitlines(), 1):
        for v in re.findall(r"\$([A-Za-z_]\w*)", linea):
            usadas.setdefault(v, n)

    return {v: n for v, n in usadas.items() if v not in definidas}



# ------------------------------------------------- metodos y claves validadas

def clase_a_archivo():
    """`Espacio\\De\\Nombres\\Clase` -> archivo, para todo `stage/app`.

    Se indexa por nombre COMPLETO, no por el corto. Hay dos clases
    `GuardarPerfilFiscalRequest` —una en `Modules/Creator` y otra en
    `Modules/Client`— y un mapa por nombre corto dejaba solo una: la primera
    version de este gate acuso al controlador de cliente de leer ocho claves
    que si declara, porque estaba mirando las reglas del de creador.
    """
    mapa = {}
    for f in phps(APP):
        texto = leer(f)
        esp = re.search(r"^namespace\s+([\w\\]+);", texto, re.M)
        cls = re.search(r"^(?:final\s+)?(?:abstract\s+)?class\s+(\w+)", texto, re.M)
        if cls:
            mapa[(esp.group(1) + '\\' if esp else '') + cls.group(1)] = f
    return mapa


def resolver(corto, archivo, mapa):
    """De un nombre corto al archivo, usando los `use` de quien lo nombra.

    Si el nombre es ambiguo y no hay `use` que lo aclare, devuelve `None` y
    quien pregunta se calla. Adivinar entre dos clases homonimas es como se
    fabrica una acusacion falsa.
    """
    texto = leer(archivo)

    for fqcn in re.findall(r"^use\s+([\w\\]+);", texto, re.M):
        if fqcn.rsplit('\\', 1)[-1] == corto and fqcn in mapa:
            return mapa[fqcn]

    esp = re.search(r"^namespace\s+([\w\\]+);", texto, re.M)
    if esp:
        propio = esp.group(1) + '\\' + corto
        if propio in mapa:
            return mapa[propio]

    candidatos = [f for fqcn, f in mapa.items() if fqcn.rsplit('\\', 1)[-1] == corto]
    return candidatos[0] if len(candidatos) == 1 else None


def metodos_de(archivo):
    return set(re.findall(r"function\s+(\w+)\s*\(", leer(archivo)))


def acciones_de_ruta():
    """`[XController::class, 'metodo']` en web.php."""
    return re.findall(r"\[(\w+)::class,\s*'(\w+)'\]", leer(RUTAS))


def reglas_declaradas(archivo):
    """Claves de `rules()`, incluidas las que se anaden condicionalmente.

    Se coge el SUPERCONJUNTO a proposito: `GuardarPerfilFiscalRequest` anade
    `country_id` y `valid_from` solo cuando no es una correccion, y exigir que
    todas esten en el `return` daria falsos positivos justo en el sitio mas
    cuidado. Un superconjunto puede dejar pasar un fallo; un subconjunto acusa
    en falso, que es peor.
    """
    texto = leer(archivo)
    # `'clave' => [ ... ]` --lo normal-- y tambien `'clave' => $this->metodo()`
    # o `'clave' => $variable`: una regla puede COMPONERSE. En 9.17c
    # `tax_location_code` la compone `reglaDeLocalidad()`, porque el patron sale
    # del pais y no del codigo; con el patron viejo esa clave no se veia y el
    # verificador acusaba al controlador de leer algo que si esta declarado.
    # Sigue siendo un superconjunto, que es lo que este verificador quiere ser.
    claves = set(re.findall(r"'([A-Za-z_][\w.]*)'\s*=>\s*[\[$]", texto))
    claves |= set(re.findall(r"\$reglas\['([A-Za-z_][\w.]*)'\]", texto))
    # `campo.*` valida el contenido de `campo`; el controlador lee `campo`.
    return {c.split('.')[0] for c in claves}


def claves_validadas_que_lee_el_controlador():
    """`$datos = $request->validated()` y luego `$datos['x']`.

    Si `x` no es una regla, `validated()` no la trae y el controlador revienta
    con «Undefined array key», que en PHPUnit sale como un 500 sin pista.
    """
    hallazgos = []
    clases = clase_a_archivo()

    for f in phps(APP):
        texto = leer(f)
        # firma del metodo: (GuardarXRequest $request, ...)
        for m in re.finditer(r"function\s+\w+\s*\(\s*(\w*Request)\s+\$request[^)]*\)", texto):
            clase = m.group(1)
            archivo_regla = resolver(clase, f, clases)
            if archivo_regla is None:
                continue
            reglas = reglas_declaradas(archivo_regla)
            if not reglas:
                continue
            # el cuerpo, hasta la siguiente declaracion de metodo
            resto = texto[m.end():]
            sig = re.search(r"\n    (?:public|private|protected)\s", resto)
            cuerpo = resto[:sig.start()] if sig else resto

            var = re.search(r"\$(\w+)\s*=\s*\$request->validated\(\)", cuerpo)
            if not var:
                continue
            nombre_var = var.group(1)

            linea_base = texto[:m.end()].count('\n') + 1
            for lectura in re.finditer(r"\$" + nombre_var + r"\['([A-Za-z_]\w*)'\]", cuerpo):
                clave = lectura.group(1)
                if clave not in reglas:
                    n = linea_base + cuerpo[:lectura.start()].count('\n')
                    hallazgos.append((clave, clase, f, n))
    return hallazgos


# ------------------------------------------------------------------- informe

def main():
    problemas = []
    revisados = {'rutas': 0, 'vistas': 0, 'permisos': 0, 'roles': 0, 'plantillas': 0, 'acciones': 0}

    declaradas = nombres_de_ruta()
    for nombre, f, n in usos_de_ruta():
        revisados['rutas'] += 1
        if nombre not in declaradas:
            cerca = sorted(d for d in declaradas if d.split('.')[0] == nombre.split('.')[0])
            pista = ('  Con ese prefijo hay: ' + ', '.join(cerca)) if cerca else ''
            problemas.append((f'{f.relative_to(RAIZ)}:{n}',
                              f"la ruta `{nombre}` no la declara web.php.{pista}"))

    for nombre, f, n in usos_de_vista():
        revisados['vistas'] += 1
        if not archivo_de_vista(nombre).exists():
            problemas.append((f'{f.relative_to(RAIZ)}:{n}',
                              f"la plantilla `{nombre}` no existe "
                              f"({archivo_de_vista(nombre).relative_to(RAIZ)})"))

    permisos = permisos_declarados()
    for codigo, f, n in permisos_usados():
        revisados['permisos'] += 1
        if codigo not in permisos:
            problemas.append((f'{f.relative_to(RAIZ)}:{n}',
                              f"el permiso `{codigo}` no lo declara CimientosSeeder. "
                              f"Una pantalla con un permiso inexistente da 403 a TODO el mundo."))

    roles = {r for r, _ambito in roles_declarados()}
    for rol, f, n in roles_usados():
        revisados['roles'] += 1
        if rol not in roles and rol not in ROLES_INEXISTENTES_A_PROPOSITO:
            problemas.append((f'{f.relative_to(RAIZ)}:{n}',
                              f"el rol `{rol}` no lo declara CimientosSeeder: "
                              f"`role_id` saldria NULL y la prueba fallaria lejos de aqui."))

    clases = clase_a_archivo()
    for clase, metodo in acciones_de_ruta():
        revisados['acciones'] += 1
        archivo = resolver(clase, RUTAS, clases)
        if archivo is None:
            problemas.append((str(RUTAS.relative_to(RAIZ)),
                              f"`{clase}` no existe en {APP.relative_to(RAIZ)}, o su `use` falta."))
        elif metodo not in metodos_de(archivo):
            problemas.append((str(RUTAS.relative_to(RAIZ)),
                              f"`{clase}::{metodo}()` no existe. "
                              f"Tiene: {', '.join(sorted(metodos_de(archivo)))}"))

    for clave, clase, f, n in claves_validadas_que_lee_el_controlador():
        problemas.append((f'{f.relative_to(RAIZ)}:{n}',
                          f"lee `['{clave}']` de `validated()` y `{clase}` no declara "
                          f"esa regla: `validated()` no la trae y esto es un "
                          f"«Undefined array key» en tiempo de ejecucion."))

    # Variables de plantilla: solo donde la respuesta es inequivoca.
    for nombre, sitios in variables_que_pasa_el_controlador().items():
        ruta = archivo_de_vista(nombre)
        if not ruta.exists() or len(sitios) != 1:
            continue
        claves, origen, opaca = sitios[0]
        if opaca:
            continue
        revisados['plantillas'] += 1
        faltan = variables_que_usa_la_plantilla(ruta)
        # `@extends` hereda el ambito del padre; las que use el layout no son
        # asunto de esta plantilla.
        for v, n in sorted(faltan.items()):
            if v not in claves:
                problemas.append((f'{ruta.relative_to(RAIZ)}:{n}',
                                  f"usa `${v}` y `{origen.name}` no la pasa a `{nombre}`. "
                                  f"Pasa: {', '.join(sorted(claves)) or '(nada)'}"))

    print(f"  Nombres de ruta usados: {revisados['rutas']}   "
          f"plantillas nombradas: {revisados['vistas']}")
    print(f"  Permisos usados: {revisados['permisos']}   roles en pruebas: {revisados['roles']}   "
          f"plantillas con un solo origen: {revisados['plantillas']}")
    print(f"  Acciones de controlador en rutas: {revisados['acciones']}")
    print()

    if not problemas:
        print('  Todo lo que la aplicacion nombra, existe.')
        return 0

    for donde, que in problemas:
        print(f'  x {donde}')
        print(f'      {que}')
        print()
    print(f'  {len(problemas)} nombre(s) que no existen.')
    return 1


if __name__ == '__main__':
    sys.exit(main())
