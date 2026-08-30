#!/usr/bin/env python3
"""Que regla de la base no ha contestado NUNCA a nadie.

### Por que existe (9.14)

`verificar-equivalencia.py` comprueba que las dos bases imponen el mismo
conjunto de reglas. `verificar-triggers-generados.py` comprueba que las reglas
declaradas existen de verdad en el motor. Ninguno de los dos comprueba lo unico
que importa el dia que una regla falle: **que alguien se lo haya preguntado**.

En `9.10a` salio el caso puro. `campaign_costs` llevaba desde la Fase 2 con
cinco restricciones y `tg_cco_no_delete` puestos, verdes en los dos
verificadores... y **cero filas**: ninguna de las seis habia contestado jamas.
Al preguntarles por primera vez aparecieron dos huecos --el `UPDATE` estaba
abierto, y nada miraba `incurred_on`-- y una asercion de `2.13` que estaba
verde porque borraba de una tabla vacia.

Una regla que nunca se ha probado no es una regla: es una intencion escrita en
DDL. Esta herramienta las cuenta.

### Que cuenta como probada

Que su NOMBRE o un fragmento distintivo de su MENSAJE aparezca en alguna suite
de `tools/pruebas/` o en alguna prueba de PHPUnit. No comprueba que la asercion
sea buena --eso lo mide `verificar-suites.py` con el trinquete de los motivos--
sino que exista.

Uso:  python3 tools/verificar-cobertura-sql.py [--lista]
"""
from __future__ import annotations

import json
import pathlib
import re
import sys

RAIZ = pathlib.Path(__file__).resolve().parent.parent
DECLARACIONES = RAIZ / 'tools' / 'sql' / 'generado' / 'declaraciones.json'
SQL = RAIZ / 'tools' / 'sql'

# El area de entrega y el repositorio tienen disposiciones distintas.
PRUEBAS = [RAIZ / 'tools' / 'pruebas']
for candidato in (RAIZ / '.entrega' / 'tests', RAIZ / 'tests'):
    if candidato.is_dir():
        PRUEBAS.append(candidato)

VERDE, ROJO, AMBAR, GRIS, FIN = '\033[32m', '\033[31m', '\033[33m', '\033[90m', '\033[0m'


def texto_de_las_pruebas() -> str:
    trozos = []
    for carpeta in PRUEBAS:
        for archivo in carpeta.rglob('*'):
            if archivo.suffix in ('.sh', '.php') or archivo.name == 'SUITES':
                trozos.append(archivo.read_text(encoding='utf-8', errors='ignore'))
    return '\n'.join(trozos)


def fragmentos(mensaje: str) -> list[str]:
    """Trozos del mensaje suficientemente largos para no coincidir por azar."""
    limpio = re.sub(r'[^\w\s]', ' ', mensaje)
    palabras = [p for p in limpio.split() if len(p) > 3]
    # Ventanas de cuatro palabras: cortas coinciden con cualquier cosa y largas
    # se rompen con el primer salto de linea de una suite.
    return [' '.join(palabras[i:i + 4]) for i in range(0, max(len(palabras) - 3, 1))]


def triggers_a_mano() -> list[tuple[str, str]]:
    """Los disparadores escritos a mano en el esquema de referencia."""
    salida = []
    for archivo in sorted(SQL.glob('*.sql')):
        contenido = archivo.read_text(encoding='utf-8', errors='ignore')
        for bloque in re.finditer(
            r'CREATE TRIGGER\s+`?(\w+)`?(.*?)END//', contenido, re.S | re.I,
        ):
            nombre, cuerpo = bloque.group(1), bloque.group(2)
            mensajes = re.findall(r"MESSAGE_TEXT\s*=\s*'([^']+)'", cuerpo)
            salida.append((nombre, mensajes[0] if mensajes else ''))
    return salida


def main() -> int:
    if not DECLARACIONES.is_file():
        print(f'{ROJO}No existe {DECLARACIONES}.{FIN}')
        print('  Genere el esquema:  python3 tools/generar-triggers.py')
        return 2

    pruebas = texto_de_las_pruebas()
    if len(pruebas) < 10_000:
        # Contar que no hay problemas cuando lo que no hay es busqueda es el
        # modo de fallo mas caro de una comprobacion automatica (`T-28`).
        print(f'{ROJO}Solo se leyeron {len(pruebas)} caracteres de pruebas.{FIN}')
        return 2

    reglas: list[tuple[str, str, str]] = []
    for d in json.loads(DECLARACIONES.read_text(encoding='utf-8')):
        reglas.append((d['tabla'], d['nombre'], d.get('mensaje', '')))
    for nombre, mensaje in triggers_a_mano():
        reglas.append(('(disparador)', nombre, mensaje))

    mudas = []
    for tabla, nombre, mensaje in reglas:
        if nombre in pruebas:
            continue
        if mensaje and any(f in pruebas for f in fragmentos(mensaje)):
            continue
        mudas.append((tabla, nombre))

    total = len(reglas)
    print()
    print(f'  Reglas en el esquema: {total}    '
          f'nunca preguntadas: {AMBAR if mudas else VERDE}{len(mudas)}{FIN}')

    if mudas and '--lista' in sys.argv:
        print()
        por_tabla: dict[str, list[str]] = {}
        for tabla, nombre in mudas:
            por_tabla.setdefault(tabla, []).append(nombre)
        for tabla in sorted(por_tabla):
            print(f'  {tabla}')
            for nombre in sorted(por_tabla[tabla]):
                print(f'{GRIS}      {nombre}{FIN}')

    base = RAIZ / 'tools' / 'pruebas' / 'MUDAS-BASE'
    if base.is_file():
        techo = int(base.read_text().strip())
        if len(mudas) > techo:
            print()
            print(f'{ROJO}  El trinquete sube de {techo} a {len(mudas)}: '
                  f'hay reglas nuevas que nadie le pregunta a la base.{FIN}')
            print('  Escriba su asercion, o suba el numero a mano diciendo por que.')
            return 1
        if len(mudas) < techo:
            print(f'{GRIS}  El trinquete baja de {techo} a {len(mudas)}: '
                  f'actualice tools/pruebas/MUDAS-BASE{FIN}')

    print()
    print(f'{VERDE}  Ninguna regla nueva se quedo sin que nadie se la preguntara.{FIN}')
    print()
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
