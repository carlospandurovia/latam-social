#!/usr/bin/env python3
"""
Que ningun SIGNAL pase de 128 caracteres, y que dos tablas distintas no digan
lo mismo.

POR QUE
-------
`SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '...'` es como este proyecto le
habla al usuario desde la base: cada disparador explica en castellano por que
rechaza la fila, y varias pantallas ensenan ese texto tal cual.

`MESSAGE_TEXT` es un `VARCHAR(128)`. MySQL 8 y Percona 5.7 NO truncan: sueltan

    ERROR 1648 (HY000): Data too long for condition item 'MESSAGE_TEXT'

en lugar del 45000 que el disparador queria dar. MariaDB si lo deja pasar. O
sea: el motor de desarrollo perdona y el de PRODUCCION --Percona 5.7-- no.

Como se encontro
----------------
En 8.1, y de pura suerte. La suite de entregables empezo a exigir el TEXTO del
rechazo y no un ERROR cualquiera (`porque` en vez de `probar`), y en MySQL 8
salio 1648. Con la asercion antigua --«esto tiene que fallar»-- los cuatro
mensajes largos llevaban rotos desde 7.4 y las suites salian verdes, porque
1648 tambien es fallar. Un rechazo solo prueba algo si rechaza por su motivo.

Cuatro mensajes estaban por encima del limite: dos de `campaign_creators`
(7.4), uno de `campaign_markets` (7.x) y el de `deliverables` (8.1).

Que mira
--------
La base de verdad, no los archivos: los mensajes se arman con concatenacion de
PHP en las migraciones y con heredocs en el esquema de referencia, y una regex
sobre el fuente se deja la mitad. `information_schema.TRIGGERS` los tiene ya
montados, vengan de donde vengan.

El segundo control (9.17j)
--------------------------
Un mensaje repetido en DOS TABLAS distintas casi siempre es un copiar y pegar
que no se termino de adaptar: el rechazo habla de la tabla de al lado y quien lo
lee busca el problema donde no esta.

Se mira por TABLA y no por regla a proposito. Dos disparadores de la MISMA tabla
diciendo lo mismo es normal y correcto --`_ins` y `_upd` son dos puertas de una
sola regla, y «no se borra» y «no se altera» son la misma frase para el que la
lee--. Entre tablas distintas no hay ninguna razon legitima, asi que este
control no necesita lista de excepciones: la lista de excepciones que nadie mira
es como un verificador deja de servir.

Lo que NO puede ver, y hay que decirlo
--------------------------------------
El caso que costo una manana entera en 9.17h no lo habria cazado esto: eran dos
VERSIONES de la misma regla --la de `9.17e` en la base y la de `9.17g` en el
codigo-- con el mismo nombre y el mismo texto. Sobre un solo esquema son
indistinguibles, porque solo hay una instalada. Eso lo contesta
`App\Shared\Config\Esquema` avisando de que falta migrar, no un verificador de
mensajes.

Uso:  python3 tools/verificar-mensajes.py [base] [--cliente mysql8]
"""

import collections
import os
import re
import shlex
import subprocess
import sys

LIMITE = 128

BASE = 'latam_social_57'
CLIENTE = os.environ.get('MYSQL_CMD', 'mariadb')

_args = sys.argv[1:]
if '--cliente' in _args:
    _i = _args.index('--cliente')
    CLIENTE = _args[_i + 1]
    del _args[_i:_i + 2]
if _args and not _args[0].startswith('-'):
    BASE = _args[0]

ORDEN = shlex.split(CLIENTE)

# `MESSAGE_TEXT = '...'`, con `''` como comilla escapada dentro.
MENSAJE = re.compile(r"MESSAGE_TEXT\s*=\s*'((?:[^']|'')*)'", re.S)


def consultar(sql):
    p = subprocess.run(ORDEN + [BASE, '-N', '-B', '-e', sql],
                       capture_output=True, text=True)
    if p.returncode != 0:
        raise SystemExit('  La base no responde: '
                         + '\n'.join(l for l in p.stderr.splitlines()
                                     if not l.startswith('mysql: [Warning]')).strip())
    return [l for l in p.stdout.splitlines() if not l.startswith('mysql: [Warning]')]


def main():
    # `-B` (sin `--raw`) escapa saltos y tabuladores DE DENTRO de cada valor,
    # asi que cada disparador cabe en una linea y el tabulador que separa
    # columnas es el unico tabulador de verdad. Con `--raw` esto no funciona:
    # el cuerpo del disparador sale con sus saltos y cada uno parece una fila.
    filas = consultar(
        'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_STATEMENT '
        'FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')

    total = 0
    largos = []
    porMensaje = collections.defaultdict(set)
    for fila in filas:
        partes = fila.split('\t', 2)
        if len(partes) < 3:
            continue
        nombre, tabla, cuerpo = partes
        cuerpo = cuerpo.replace('\\n', '\n').replace('\\t', '\t')
        for m in MENSAJE.finditer(cuerpo):
            texto = ' '.join(m.group(1).replace("''", "'").split())
            total += 1
            if len(texto) > LIMITE:
                largos.append((len(texto), nombre, tabla, texto))
            porMensaje[texto].add((tabla, nombre))

    cruzados = {texto: donde for texto, donde in porMensaje.items()
                if len({tabla for tabla, _ in donde}) > 1}

    print(f'  Base: {BASE}    disparadores: {len(filas)}    mensajes SIGNAL: {total}')
    print(f'  Distintos: {len(porMensaje)}    '
          f'Limite de MESSAGE_TEXT: {LIMITE} caracteres (VARCHAR(128) en MySQL/Percona)')
    print()

    fallos = 0

    if largos:
        for n, nombre, tabla, texto in sorted(largos, reverse=True):
            print(f'  x {nombre} sobre `{tabla}`: {n} caracteres, {n - LIMITE} de mas')
            print(f'      {texto}')
            print()
        print(f'  {len(largos)} mensaje(s) que en MySQL/Percona dan 1648 en vez de 45000.')
        fallos += len(largos)
    else:
        print('  Todos los mensajes caben: en produccion diran lo que dicen aqui.')

    if cruzados:
        print()
        for texto, donde in sorted(cruzados.items()):
            print(f'  x el mismo texto en {len({t for t, _ in donde})} tablas distintas:')
            print(f'      {texto}')
            for tabla, nombre in sorted(donde):
                print(f'        {tabla}.{nombre}')
            print()
        print(f'  {len(cruzados)} mensaje(s) que mandan a buscar el problema a otra tabla.')
        fallos += len(cruzados)
    else:
        print('  Ningun mensaje se repite entre tablas distintas.')

    return 1 if fallos else 0


if __name__ == '__main__':
    sys.exit(main())
