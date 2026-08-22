#!/usr/bin/env python3
"""
Detecta nombres de restriccion e indice repetidos entre los .sql del proyecto.

Existe porque InnoDB exige que los nombres de clave foranea sean unicos en TODA
la base, no solo en la tabla. Una colision no da un error legible: da
"errno: 121 Duplicate key on write or update" al crear la tabla, que no dice cual.

Paso real: `fk_cc_creator` se uso en creator_categories y en campaign_creators,
porque abrevie las dos tablas como "cc".

Uso:  python3 tools/verificar-nombres-sql.py
"""
import re, sys, glob, collections, pathlib

RAIZ = pathlib.Path(__file__).resolve().parent.parent
IGNORAR = ('sonda-', 'ejemplo-')

def main() -> int:
    nombres = collections.defaultdict(list)
    for ruta in sorted((RAIZ / 'tools' / 'sql').glob('*.sql')):
        if ruta.name.startswith(IGNORAR):
            continue
        texto = ruta.read_text(encoding='utf-8')
        for m in re.finditer(r'CONSTRAINT\s+(\w+)\s+(?:FOREIGN KEY|CHECK)', texto):
            nombres[m.group(1)].append(ruta.name)
        for m in re.finditer(r'(?:UNIQUE\s+)?KEY\s+((?:uq|ix)_\w+)\s*\(', texto):
            nombres[m.group(1)].append(ruta.name)

    duplicados = {n: v for n, v in nombres.items() if len(v) > 1}
    largos = [n for n in nombres if len(n) > 64]

    print(f"Nombres declarados: {len(nombres)}")
    if duplicados:
        print(f"\nCOLISIONES ({len(duplicados)}):")
        for n, v in sorted(duplicados.items()):
            print(f"  {n:34} en {', '.join(sorted(set(v)))}")
    if largos:
        print(f"\nDEMASIADO LARGOS, el limite de MySQL son 64 ({len(largos)}):")
        for n in largos:
            print(f"  {n} ({len(n)})")

    if duplicados or largos:
        return 1
    print("Sin colisiones ni nombres demasiado largos.")
    return 0

if __name__ == '__main__':
    sys.exit(main())
