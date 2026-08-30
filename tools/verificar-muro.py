#!/usr/bin/env python3
"""Que ruta no exige permiso, y si esta escrito que es a proposito.

### Por que existe (9.14)

De 145 rutas con nombre, 23 no exigen ningun permiso. Casi todas estan bien
--entrar, recuperar la contrasena, los enlaces firmados de invitacion y de
aprobacion-- pero hasta hoy **nada distinguia «es publica a proposito» de «se le
olvido el middleware a alguien»**, y el fallo que de verdad paso en este proyecto
es ese: en `5.9` la portada enseñaba los totales internos a cualquier
autenticado.

La lista vive en `tools/pruebas/RUTAS-ABIERTAS`, con el motivo escrito al lado de
cada una. Esta herramienta comprueba que **tres sitios digan lo mismo**: la
lista, `routes/web.php` y la constante `ABIERTAS` de `MuroTest`. Tres copias de
lo mismo es como se pierde una --paso con `SUITES` en 3.12-- y aqui no se pueden
fundir, porque una la lee Python sin Laravel y otra la lee PHPUnit.

Uso:  python3 tools/verificar-muro.py
"""
from __future__ import annotations

import pathlib
import re
import sys

RAIZ = pathlib.Path(__file__).resolve().parent.parent
LISTA = RAIZ / 'tools' / 'pruebas' / 'RUTAS-ABIERTAS'

# El area de entrega y el repositorio tienen disposiciones distintas.
RUTAS = next((p for p in (RAIZ / 'stage' / 'routes' / 'web.php',
                          RAIZ / 'routes' / 'web.php') if p.is_file()), None)
PRUEBA = next((p for p in (RAIZ / '.entrega' / 'tests' / 'Feature' / 'MuroTest.php',
                           RAIZ / 'tests' / 'Feature' / 'MuroTest.php') if p.is_file()), None)

VERDE, ROJO, GRIS, FIN = '\033[32m', '\033[31m', '\033[90m', '\033[0m'


def declaradas() -> set[str]:
    nombres = set()
    for linea in LISTA.read_text(encoding='utf-8').splitlines():
        limpia = linea.split('#')[0].strip()
        if limpia:
            nombres.add(limpia)
    return nombres


def del_codigo() -> tuple[set[str], int]:
    """Las rutas sin `permiso:`, y cuantas hay en total."""
    fuente = RUTAS.read_text(encoding='utf-8')
    sin, total = set(), 0

    for bloque in re.finditer(
        r"Route::(?:get|post|put|patch|delete)\((.*?)->name\('([^']+)'\)", fuente, re.S,
    ):
        total += 1
        if 'permiso:' not in bloque.group(0):
            sin.add(bloque.group(2))

    return sin, total


def de_la_prueba() -> set[str]:
    fuente = PRUEBA.read_text(encoding='utf-8')
    bloque = re.search(r'const ABIERTAS = \[(.*?)\];', fuente, re.S)
    if bloque is None:
        return set()
    return set(re.findall(r"'([^']+)'", bloque.group(1)))


def main() -> int:
    if RUTAS is None or PRUEBA is None or not LISTA.is_file():
        print(f'{ROJO}Falta alguno de los tres archivos que hay que contrastar.{FIN}')
        return 2

    lista = declaradas()
    codigo, total = del_codigo()
    prueba = de_la_prueba()

    if total < 50:
        # Contar que no hay problemas cuando lo que no hay es busqueda (`T-28`).
        print(f'{ROJO}Solo se encontraron {total} rutas: el analisis esta roto.{FIN}')
        return 2

    fallos = 0
    print()
    print(f'  Rutas con nombre: {total}    sin permiso: {len(codigo)}')

    nuevas = codigo - lista
    if nuevas:
        fallos += 1
        print()
        print(f'{ROJO}  Rutas sin `permiso:` que NO estan en RUTAS-ABIERTAS:{FIN}')
        for nombre in sorted(nuevas):
            print(f'      {nombre}')
        print(f'{GRIS}      O les falta el middleware, o hay que escribir alli por que no.{FIN}')

    viejas = lista - codigo
    if viejas:
        fallos += 1
        print()
        print(f'{ROJO}  En RUTAS-ABIERTAS pero ya no existen o ya exigen permiso:{FIN}')
        for nombre in sorted(viejas):
            print(f'      {nombre}')

    if prueba != lista:
        fallos += 1
        print()
        print(f'{ROJO}  RUTAS-ABIERTAS y la constante de MuroTest no dicen lo mismo:{FIN}')
        for nombre in sorted(lista ^ prueba):
            donde = 'solo en la lista' if nombre in lista else 'solo en la prueba'
            print(f'      {nombre}  {GRIS}({donde}){FIN}')

    if fallos:
        print()
        return 1

    print()
    print(f'{VERDE}  Toda ruta sin permiso esta escrita, y con su motivo.{FIN}')
    print()
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
