#!/usr/bin/env python3
"""
Que el esquema de referencia y las migraciones digan lo MISMO sobre periodos.

POR QUE
-------
La regla «dos periodos de la misma serie no se solapan» vive en dos sitios a la
vez, y tiene que vivir en los dos:

  - `tools/sql/*.sql`  es lo que cargan las bases de referencia y las suites.
  - la migracion       es lo que se ejecuta en produccion.

Los dos salen hoy de la misma clase (`App\\Shared\\Database\\Periodo`), asi que
hoy coinciden. Nada impide que manana no: cambiar la `serie` en la migracion y
no en el `.sql` deja las pruebas verdes y produccion con otra regla. Seria
`DEC-042` reapareciendo por la puerta de atras --una restriccion que existe en
desarrollo y no en produccion-- pero al reves y en silencio.

Este script lee las declaraciones de las migraciones, genera el SQL con la clase
DE VERDAD (no con una reimplementacion: si el generador tiene un fallo, esta
prueba tiene que verlo tambien) y comprueba que ese texto esta en el esquema.

Uso:  python3 tools/verificar-periodos.py
"""

import json
import os
import re
import shlex
import subprocess
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent

# La comprobacion por FORMA necesita mirar el esquema de verdad, asi que este
# script --que hasta ahora solo leia archivos-- ahora tambien habla con la base.
BASE = 'latam_fin_57'
CLIENTE = os.environ.get('MYSQL_CMD', 'mariadb')

_args = sys.argv[1:]
if '--cliente' in _args:
    _i = _args.index('--cliente')
    CLIENTE = _args[_i + 1]
    del _args[_i:_i + 2]
if _args and not _args[0].startswith('-'):
    BASE = _args[0]

ORDEN = shlex.split(CLIENTE)


def consultar(sql):
    p = subprocess.run(ORDEN + [BASE, '-N', '-B', '-e', sql], capture_output=True, text=True)
    if p.returncode != 0:
        raise SystemExit('  La base no responde: '
                         + '\n'.join(l for l in p.stderr.splitlines()
                                     if not l.startswith('mysql: [Warning]')).strip())
    lineas = [l for l in p.stdout.splitlines() if not l.startswith('mysql: [Warning]')]

    return [l.split('\t') for l in lineas if l]

# En el repo las clases estan en `app/`; en el arbol de trabajo desde el que se
# preparan las entregas, en `stage/app/`. Se busca en los dos en vez de suponer.
FUENTE = next((c for c in (RAIZ / 'app', RAIZ / 'stage' / 'app') if c.is_dir()), RAIZ / 'app')


def normalizar(texto):
    """Sin acentos de formato: lo que importa es la regla, no la sangria."""
    return re.sub(r'\s+', ' ', texto).strip().lower()


# Tablas con forma de periodo que NO llevan regla, y por que. Cada una tuvo que
# decidirse; estar en esta lista es una decision escrita, no un olvido.
SIN_REGLA_A_PROPOSITO = {
    'creator_addresses':
        'DEC-072: `uq_creator_addresses_default` ya decidio que puede haber varias '
        'direcciones del mismo tipo con una marcada por defecto. Prohibir el solape '
        'seria contradecir el diseno, no endurecerlo.',
    'creator_guardians':
        '`uq_creator_guardians_active` ya garantiza un solo tutor activo por creador, '
        'asi que dos periodos activos no pueden existir. La regla seria redundante.',
}


def tablas_con_forma_de_periodo():
    """Cualquier par `X_from` / `X_to` de tipo fecha, se llame como se llame.

    POR QUE POR FORMA Y NO POR NOMBRE
    ---------------------------------
    El barrido de 3.10 busco tablas con columnas `valid_from`, encontro siete y
    las trato todas. `terms_versions` no aparecio: sus columnas se llaman
    `effective_from` / `effective_to`.

    Ahi estuvo tres iteraciones el mismo defecto --dos versiones de los terminos
    vigentes el dia de cada publicacion-- en la tabla que guarda el texto legal
    que el creador acepto. Un defecto de clase escondido detras de un nombre.

    Buscar por forma lo habria encontrado a la primera, asi que eso es lo que se
    hace ahora.
    """
    filas = consultar("""
        SELECT a.TABLE_NAME,
               SUBSTRING(a.COLUMN_NAME, 1, CHAR_LENGTH(a.COLUMN_NAME) - 5),
               IF(EXISTS(SELECT 1 FROM information_schema.TRIGGERS t
                          WHERE t.TRIGGER_SCHEMA = a.TABLE_SCHEMA
                            AND t.EVENT_OBJECT_TABLE = a.TABLE_NAME
                            AND t.ACTION_STATEMENT LIKE '%IFNULL%9999-12-31%'), 1, 0)
          FROM information_schema.COLUMNS a
          JOIN information_schema.COLUMNS b
            ON b.TABLE_SCHEMA = a.TABLE_SCHEMA AND b.TABLE_NAME = a.TABLE_NAME
           AND b.COLUMN_NAME = CONCAT(SUBSTRING(a.COLUMN_NAME, 1, CHAR_LENGTH(a.COLUMN_NAME) - 5), '_to')
         WHERE a.TABLE_SCHEMA = '""" + BASE + """'
           AND a.COLUMN_NAME LIKE '%\\_from'
           AND a.DATA_TYPE IN ('date', 'datetime')
         GROUP BY 1, 2, 3 ORDER BY 1
    """)

    return [(f[0], f[1], f[2] == '1') for f in filas]


def revisar_forma():
    """Devuelve (cuantas, huerfanas)."""
    tablas = tablas_con_forma_de_periodo()
    huerfanas = [(t, par) for t, par, tiene in tablas
                 if not tiene and t not in SIN_REGLA_A_PROPOSITO]

    return len(tablas), huerfanas


def main():
    recolector = subprocess.run(
        ['php', str(RAIZ / 'tools' / 'recolectar-esquema.php')],
        capture_output=True, text=True, cwd=RAIZ)

    if recolector.returncode != 0:
        print('  No pude leer las migraciones:')
        print(recolector.stderr.strip()[:2000])
        return 2

    periodos = json.loads(recolector.stdout).get('periodos', [])

    if not periodos:
        print('  Ninguna migracion declara reglas de periodo. Nada que contrastar.')
        return 0

    # El SQL, generado por la clase real.
    shim = RAIZ / 'tools' / '.periodo-render.php'
    shim.write_text(f'''<?php
namespace App\\Shared\\Database;
require {json.dumps(str(FUENTE / "Shared" / "Database" / "Restriccion.php"))};
require {json.dumps(str(FUENTE / "Shared" / "Database" / "Periodo.php"))};
$decls = json_decode(file_get_contents('php://stdin'), true);
$fuera = [];
foreach ($decls as $d) {{
    foreach (['INSERT', 'UPDATE'] as $ev) {{
        $fuera[] = Periodo::sql($d['tabla'], $d['nombre'], $d['serie'], $d['mensaje'],
            $d['donde'], $d['columnasDonde'], $d['desde'], $d['hasta'], $d['clavePrimaria'], $ev);
    }}
}}
echo json_encode($fuera);
''', encoding='utf-8')

    try:
        render = subprocess.run(['php', str(shim)], input=json.dumps(periodos),
                                capture_output=True, text=True)
    finally:
        shim.unlink(missing_ok=True)

    if render.returncode != 0:
        print('  La clase Periodo no pudo generar el SQL:')
        print(render.stderr.strip()[:2000])
        return 2

    generados = json.loads(render.stdout)

    esquema = normalizar('\n'.join(
        p.read_text(encoding='utf-8') for p in sorted((RAIZ / 'tools' / 'sql').glob('*.sql'))))

    faltan = [g for g in generados if normalizar(g) not in esquema]

    print(f'  Reglas de periodo declaradas: {len(periodos)}    disparadores esperados: {len(generados)}')

    cuantas, huerfanas = revisar_forma()
    excluidas = len(SIN_REGLA_A_PROPOSITO)
    print(f'  Tablas con forma de periodo: {cuantas}    sin regla a proposito: {excluidas}')
    print()

    if huerfanas:
        for tabla, par in huerfanas:
            print(f'  x `{tabla}` tiene `{par}_from` / `{par}_to` y ninguna regla de solape.')
            print('      O se le pone, o se anade a SIN_REGLA_A_PROPOSITO con el motivo escrito.')
            print()
        print(f'  {len(huerfanas)} tabla(s) con forma de periodo y sin decidir.')
        return 1

    if not faltan:
        print('  El esquema de referencia dice lo mismo que las migraciones.')
        return 0

    for g in faltan:
        nombre = re.search(r'CREATE TRIGGER `(\w+)`', g)
        print(f'  x falta en tools/sql/: {nombre.group(1) if nombre else "?"}')
        print('      ' + '\n      '.join(g.splitlines()[:6]))
        print()

    print(f'  {len(faltan)} disparador(es) que la migracion crea y el esquema de referencia no.')
    print('  Regenerelos desde la clase; no los copie a mano.')
    return 1


if __name__ == '__main__':
    sys.exit(main())
