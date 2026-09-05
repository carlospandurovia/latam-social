#!/usr/bin/env python3
"""Quien puede hablar con el mundo real, y si mira antes desde que maquina lo hace.

### Por que existe (9.22b)

`9.22a` y `9.22b` pusieron la barrera de `DEC-029` en los dos sitios que hoy
sacan algo de la maquina: el comprobante a la administracion y el correo. El
riesgo no es ese codigo --ese ya la mira-- sino **el consumidor que se anada
manana**: un cobro por pasarela, un pago a creadores ejecutado por proveedor, un
webhook de salida. Ninguno de ellos va a acordarse solo.

La comprobacion es simple y por eso aguanta: **todo archivo que pida la
direccion o la clave de una conexion tiene que consultar `Instalacion`**
--por si mismo, o pasando por `Integraciones::conexionParaUsar()`, que la
consulta--. El que no lo haga, tiene que estar escrito en
`tools/pruebas/SALIDAS-AL-MUNDO` con el motivo al lado.

Mismo criterio que `verificar-muro.py` con las rutas sin permiso: **no se prohibe
la excepcion, se exige que este escrita**. Una excepcion con motivo es una
decision; una excepcion silenciosa es un olvido, y son indistinguibles el dia que
alguien lee el codigo.

Uso:  python3 tools/verificar-salidas.py
"""
from __future__ import annotations

import pathlib
import re
import sys

RAIZ = pathlib.Path(__file__).resolve().parent.parent
LISTA = RAIZ / 'tools' / 'pruebas' / 'SALIDAS-AL-MUNDO'

# El area de entrega y el repositorio tienen disposiciones distintas.
APP = next((p for p in (RAIZ / 'stage' / 'app', RAIZ / 'app') if p.is_dir()), None)

# La puerta misma: es quien hace la comprobacion, no quien la debe.
PUERTA = 'Modules/Core/Services/Integraciones.php'

# L-5: la SEGUNDA clase de salida, y la descubrio esta iteracion.
#
# El criterio de arriba reconoce al consumidor por pedir la direccion o la clave
# de una conexion. La medicion de visitas no pide ninguna de las dos --su
# identificador es publico-- y sin embargo carga un `<script>` de un tercero en
# todas las paginas de la calle y le manda la IP de cada visitante. El
# verificador no la habria visto nunca.
#
# Asi que se mira tambien EN LAS PLANTILLAS: toda vista que nombre un dominio
# ajeno dentro de un `src`, un `<link href>` o un `<script>` tiene que estar
# escrita en la lista con su motivo. Es el mismo trato --no se prohibe, se exige
# que este escrita-- y de paso deja a la vista algo que nadie habia mirado: que
# la tipografia tambien viaja a un tercero.
VISTAS = next((p for p in (RAIZ / 'stage' / 'resources' / 'views',
                           RAIZ / 'resources' / 'views') if p.is_dir()), None)

COMENTARIO = re.compile(r'\{\{--.*?--\}\}', re.S)

# Un `<a href>` NO cuenta, y la primera version de esto se equivocaba justo ahi:
# denunciaba cinco pantallas del panel que enlazan a SUNAT o a la ayuda de
# Google. Un enlace no manda nada a nadie hasta que una persona decide pulsarlo;
# lo que se busca aqui es lo que se carga SOLO, sin que nadie lo pida.
CARGA = re.compile(
    r'(?:\bsrc\s*=\s*["\']|<link\b[^>]*?\bhref\s*=\s*["\'])https?://([a-z0-9.-]+)', re.I)

# Y lo que se carga desde dentro de un `<script>`: los fragmentos de Meta y de
# Tag Manager arman la direccion en JavaScript, asi que no hay ningun `src=` que
# mirar hasta que el navegador ya la ha pedido.
GUION = re.compile(r'<script\b[^>]*>(.*?)</script>', re.I | re.S)
EN_GUION = re.compile(r'https?://([a-z0-9.-]+)', re.I)

# Los nuestros no cuentan: salen de `route()` o del propio dominio.
PROPIOS = ('localhost', '127.0.0.1')

VERDE, ROJO, AMBAR, GRIS, FIN = '\033[32m', '\033[31m', '\033[33m', '\033[90m', '\033[0m'

# «Estar a punto de hablar con alguien de fuera» se reconoce por PEDIR LOS
# MEDIOS de la llamada: la direccion a la que se llama, o la clave con la que uno
# se identifica. Nadie pide un secreto para pintarlo en una pantalla --`9.17d`
# separo `estado()` de `secreto()` justo para eso-- asi que esto no confunde a
# quien solo lista conexiones con quien va a llamar.
#
# Se descarto reconocerlo por `status = 'active'`: los tipos de cambio eligen su
# conexion por la fuente y no por el estado, asi que ese criterio los dejaba
# fuera sin que nadie lo notara --y el consumidor que no se detecta es
# exactamente el que este verificador existe para detectar--.
PIDE = re.compile(r'Integraciones::(?:secreto|urlDe)\(')
MIRA = re.compile(r'Instalacion::|conexionParaUsar\(')


def declaradas() -> dict[str, str]:
    fuera = {}
    for linea in LISTA.read_text(encoding='utf-8').splitlines():
        if not linea.strip() or linea.lstrip().startswith('#'):
            continue
        ruta, _, motivo = linea.partition('#')
        fuera[ruta.strip()] = motivo.strip()
    return fuera


def main() -> int:
    if APP is None:
        print(f'{ROJO}  No encuentro la carpeta de la aplicacion.{FIN}')
        return 1

    lista = declaradas()
    consumidores: dict[str, bool] = {}

    for archivo in sorted(APP.rglob('*.php')):
        rel = archivo.relative_to(APP).as_posix()

        if 'Database/Migrations/' in rel or rel == PUERTA:
            continue

        fuente = archivo.read_text(encoding='utf-8')

        if not PIDE.search(fuente):
            continue

        consumidores['app/' + rel] = bool(MIRA.search(fuente))

    # Y las plantillas que cargan algo de un tercero.
    if VISTAS is not None:
        for vista in sorted(VISTAS.rglob('*.blade.php')):
            rel = vista.relative_to(VISTAS).as_posix()
            fuente = COMENTARIO.sub('', vista.read_text(encoding='utf-8'))
            hosts = {h.lower() for h in CARGA.findall(fuente)}

            for cuerpo in GUION.findall(fuente):
                hosts |= {h.lower() for h in EN_GUION.findall(cuerpo)}

            hosts = {h for h in hosts if not h.startswith(PROPIOS)}

            if not hosts:
                continue

            # `emite` es la clave que devuelve `Sitio::medicion()`, o sea: esta
            # plantilla pregunta por la maquina antes de cargar nada.
            consumidores['vistas/' + rel] = 'emite' in fuente

    print()
    print('===== salidas al mundo: quien mira la barrera de entorno =====')
    print()

    miran = [r for r, ok in consumidores.items() if ok]
    no_miran = [r for r, ok in consumidores.items() if not ok]

    print(f'  Codigo que va a llamar a un proveedor: {len(consumidores)}   '
          f'miran la barrera: {VERDE}{len(miran)}{FIN}   escritos aparte: {len(lista)}')
    print()

    fallos = 0

    huerfanos = [r for r in no_miran if r not in lista]
    if huerfanos:
        fallos += 1
        print(f'{ROJO}  Van a llamar a un proveedor, no miran la barrera y no estan escritos:{FIN}')
        for ruta in sorted(huerfanos):
            print(f'      {ruta}')
        print(f'{GRIS}      O consultan `Instalacion`, o se escribe en SALIDAS-AL-MUNDO por que no.{FIN}')

    viejas = [r for r in lista if r not in consumidores]
    if viejas:
        fallos += 1
        print()
        print(f'{ROJO}  Escritos en SALIDAS-AL-MUNDO pero ya no llaman a ningun proveedor:{FIN}')
        for ruta in sorted(viejas):
            print(f'      {ruta}')

    resueltas = [r for r in lista if consumidores.get(r) is True]
    if resueltas:
        fallos += 1
        print()
        print(f'{ROJO}  Escritos como excepcion, pero YA miran la barrera --sobra la linea:{FIN}')
        for ruta in sorted(resueltas):
            print(f'      {ruta}')

    if fallos:
        print()
        return 1

    for ruta in sorted(lista):
        print(f'{AMBAR}  Sin barrera, a proposito:{FIN} {ruta}')
        print(f'{GRIS}      {lista[ruta]}{FIN}')
    if lista:
        print()

    print(f'{VERDE}  Todo el que puede hablar con el mundo real mira antes desde que maquina.{FIN}')
    print()
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
