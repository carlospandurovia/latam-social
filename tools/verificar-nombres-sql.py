#!/usr/bin/env python3
"""
Detecta nombres de restriccion e indice repetidos entre los .sql del proyecto.

Existe porque InnoDB exige que los nombres de clave foranea sean unicos en TODA
la base, no solo en la tabla. Una colision no da un error legible: da
"errno: 121 Duplicate key on write or update" al crear la tabla, que no dice cual.

Paso real: `fk_cc_creator` se uso en creator_categories y en campaign_creators,
porque abrevie las dos tablas como "cc".

Y desde 8.11 cuenta tambien las COLUMNAS PUERTA, por un motivo parecido.

El QA de la Fase 8 las conto y salieron **24**. Los documentos y los comentarios
del esquema venian numerandolas a mano --«la decimoquinta», «la decimosexta»--
y el contador iba por 17. Se habian escapado cuatro que no se llaman `_gate`
(`open_email_key`, `active_creator_key`, `email_active_key`, `sonda_dia`) y
nadie habia contado nunca: el numero se heredaba de un documento al siguiente.

Un numero que aparece en ocho documentos y que no comprueba nadie es un numero
que va a estar mal. Ahora sale de contar el esquema.

Uso:  python3 tools/verificar-nombres-sql.py
"""
import re, sys, glob, collections, pathlib

RAIZ = pathlib.Path(__file__).resolve().parent.parent
IGNORAR = ('sonda-', 'ejemplo-')

def _por_comas(cuerpo: str):
    """Parte la definicion de una tabla por las comas de NIVEL CERO.

    Partir por `,` a secas rompe `DECIMAL(18,4)` y `CASE WHEN ... THEN 1 ELSE
    NULL END` por la mitad, que es como la primera version encontro once
    columnas puerta de veinticuatro.
    """
    pieza, hondo = [], 0
    for c in cuerpo:
        if c == '(':
            hondo += 1
        elif c == ')':
            hondo -= 1
        if c == ',' and hondo == 0:
            yield ''.join(pieza)
            pieza = []
        else:
            pieza.append(c)
    if pieza:
        yield ''.join(pieza)


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

    # Las columnas puerta: una columna GENERADA que solo tiene valor cuando la
    # fila cuenta, mas un UNIQUE que la incluye. Es como este esquema expresa
    # «unico entre los vigentes» sin indices parciales, que MySQL no tiene.
    puertas = set()
    for ruta in sorted((RAIZ / 'tools' / 'sql').glob('*.sql')):
        if ruta.name.startswith(IGNORAR):
            continue
        texto = ruta.read_text(encoding='utf-8')
        for bloque in re.finditer(r'CREATE TABLE\s+`?(\w+)`?\s*\((.*?)\n\)\s*ENGINE',
                                  texto, re.S):
            tabla = bloque.group(1)
            # Sin comentarios y con los saltos aplanados: casi todas se declaran
            # en dos lineas --el tipo arriba y `GENERATED ALWAYS AS` debajo-- y
            # mirar linea a linea encontraba tres de veinticuatro.
            cuerpo = re.sub(r'--[^\n]*', ' ', bloque.group(2))
            cuerpo = re.sub(r'\s+', ' ', cuerpo)
            for pieza in _por_comas(cuerpo):
                if 'GENERATED ALWAYS AS' not in pieza:
                    continue
                m = re.match(r'\s*`?(\w+)`?', pieza)
                if m:
                    puertas.add(f'{tabla}.{m.group(1)}')

        # Y las que se anaden con ALTER --`users.email_active_key` es la unica
        # hoy, y era justo la que faltaba: 23 de 24. Contar solo los CREATE
        # TABLE dejaba fuera una y el numero seguia siendo casi correcto, que
        # es la peor clase de numero.
        plano = re.sub(r'\s+', ' ', re.sub(r'--[^\n]*', ' ', texto))
        for m in re.finditer(r'ALTER TABLE `?(\w+)`?(.*?);', plano):
            for pieza in _por_comas(m.group(2)):
                if 'GENERATED ALWAYS AS' not in pieza:
                    continue
                c = re.search(r'ADD COLUMN `?(\w+)`?', pieza)
                if c:
                    puertas.add(f'{m.group(1)}.{c.group(1)}')

    duplicados = {n: v for n, v in nombres.items() if len(v) > 1}
    largos = [n for n in nombres if len(n) > 64]

    print(f"Nombres declarados: {len(nombres)}    columnas puerta: {len(puertas)}")
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
