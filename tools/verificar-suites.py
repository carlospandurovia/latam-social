#!/usr/bin/env python3
"""Las suites de restriccion se comprueban a si mismas (8.11).

La septima puerta. Sale del QA de la Fase 8, y de un defecto que se pudo
demostrar en vez de sospechar.

### Lo que paso

Los cuatro ayudantes de las suites --`probar`, `valor`, `porque`-- estaban
COPIADOS en las treinta. El QA los midio y salieron **seis variantes** de lo
mismo, y la diferencia no era cosmetica: **nueve suites no tenian la guarda de
conexion**.

Sin esa guarda, un motor caido pone en verde cada `probar ... RECHAZO`, porque
no poder conectar tambien produce la palabra ERROR. Medido: la suite de `7.6`
contra una base que no existe daba **39 aserciones correctas y cero fallidas**.

Y no era una leccion nueva. La suite de `2.13` lleva escrito desde la Fase 2
«25 aserciones en verde contra un socket muerto». Se arreglo alli, en un
archivo, y habia treinta.

### Lo que comprueba

1. Que toda suite listada en `SUITES` existe.
2. Que ninguna define sus propios `probar`, `valor` o `porque`.
3. Que todas cargan `comun.sh`.
4. Cuantas aserciones negativas afirman **por que** se rechaza (`porque`) y
   cuantas solo afirman que algo fallo. Este numero **solo puede bajar**: es el
   trinquete, y esta en `tools/pruebas/MOTIVOS-BASE`.

El punto 4 no es estetica. `T-48`, `T-50`, `T-51` y `T-54` fueron aserciones
verdes por el motivo equivocado, y las cuatro eran `probar ... RECHAZO` a secas.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
PRUEBAS = RAIZ / 'tools' / 'pruebas'
LISTA = PRUEBAS / 'SUITES'
COMUN = PRUEBAS / 'comun.sh'
BASE = PRUEBAS / 'MOTIVOS-BASE'

VERDE, ROJO, AMBAR, GRIS, FIN = '\033[32m', '\033[31m', '\033[33m', '\033[90m', '\033[0m'

PROPIO = re.compile(r'^(probar|valor|porque)\(\)\s*\{', re.M)
CARGA = 'source "$(dirname "$0")/comun.sh"'
NEGATIVA = re.compile(r'"\s*RECHAZO\s*$', re.M)
CON_MOTIVO = re.compile(r'^\s*porque\s', re.M)


def main() -> int:
    if not LISTA.is_file() or not COMUN.is_file():
        print(f'{ROJO}  Falta tools/pruebas/SUITES o comun.sh.{FIN}')
        return 2

    suites = [l.strip() for l in LISTA.read_text().splitlines()
              if l.strip() and not l.strip().startswith('#')]

    fallos: list[str] = []
    sin_motivo = con_motivo = 0

    for nombre in suites:
        archivo = PRUEBAS / f'{nombre}.sh'

        if not archivo.is_file():
            fallos.append(f'{nombre}: esta en SUITES y el archivo no existe')
            continue

        texto = archivo.read_text()

        if PROPIO.search(texto):
            cuales = ', '.join(sorted({m.group(1) for m in PROPIO.finditer(texto)}))
            fallos.append(f'{nombre}: define sus propios ayudantes ({cuales}). '
                          'Van en comun.sh: una copia es una variante esperando a divergir')

        if CARGA not in texto:
            fallos.append(f'{nombre}: no carga comun.sh')

        sin_motivo += len(NEGATIVA.findall(texto))
        con_motivo += len(CON_MOTIVO.findall(texto))

    print(f'\n  Suites: {len(suites)}    aserciones negativas: {sin_motivo + con_motivo}')
    print(f'  Afirman POR QUE se rechaza: {con_motivo}    '
          f'solo que algo fallo: {AMBAR}{sin_motivo}{FIN}')

    tope = int(BASE.read_text().strip()) if BASE.is_file() else sin_motivo

    if sin_motivo > tope:
        fallos.append(f'las aserciones sin motivo suben de {tope} a {sin_motivo}. '
                      'Una asercion negativa nueva se escribe con `porque`, que afirma '
                      'POR QUE se rechaza: un rechazo solo prueba algo si rechaza por SU motivo')
    elif sin_motivo < tope:
        print(f'{GRIS}  El trinquete baja de {tope} a {sin_motivo}: '
              f'actualice tools/pruebas/MOTIVOS-BASE{FIN}')

    if fallos:
        print()
        for f in fallos:
            print(f'{ROJO}  x {FIN}{f}')
        print(f'\n{ROJO}  {len(fallos)} problema(s) en las suites.{FIN}\n')
        return 1

    print(f'\n{VERDE}  Las suites comparten ayudantes y ninguna se pondria verde '
          f'con el motor apagado.{FIN}\n')
    return 0


if __name__ == '__main__':
    sys.exit(main())
