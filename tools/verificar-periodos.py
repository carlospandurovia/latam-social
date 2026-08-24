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
import re
import subprocess
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent

# En el repo las clases estan en `app/`; en el arbol de trabajo desde el que se
# preparan las entregas, en `stage/app/`. Se busca en los dos en vez de suponer.
FUENTE = next((c for c in (RAIZ / 'app', RAIZ / 'stage' / 'app') if c.is_dir()), RAIZ / 'app')


def normalizar(texto):
    """Sin acentos de formato: lo que importa es la regla, no la sangria."""
    return re.sub(r'\s+', ' ', texto).strip().lower()


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
    print()

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
