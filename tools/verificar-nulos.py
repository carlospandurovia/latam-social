#!/usr/bin/env python3
"""Un CHECK que se apoya en una columna NULL no rechaza nada (9.9c).

### El defecto, cuatro veces

Un `CHECK` en MySQL/MariaDB rechaza una fila **solo cuando la expresion es
FALSA**. Cuando es NULL, la deja pasar. Y `CHAR_LENGTH(TRIM(NULL))` es NULL, asi
que esta regla:

    status <> 'voided' OR CHAR_LENGTH(TRIM(void_reason)) >= 10

NO impide anular sin motivo: con `void_reason` a NULL la expresion entera vale
NULL y la fila entra. La regla parece estar y no esta.

Este proyecto lo ha cometido cuatro veces --`9.12` con `ck_dn_anulado`, `9.21c`
con `ck_clead_descartado`, `9.9b` con `ck_invoice_void_reason` y `9.9c` con
`ck_cert_revocado`-- y las cuatro lo descubrio una suite, por casualidad de que
alguien escribiera esa asercion. La quinta no hace falta descubrirla.

### Que comprueba

Toda funcion que devuelve NULL ante NULL --`CHAR_LENGTH`, `TRIM`, `LENGTH`,
`UPPER`, `LOWER`, `SUBSTRING`, `REGEXP`...-- aplicada a una columna que la tabla
declara NULLABLE, dentro de una expresion que **no comprueba antes que esa
columna no sea NULL**.

Se lee del esquema de referencia --`information_schema`-- y no del texto de las
migraciones: lo que importa es lo que la base impone de verdad.

Corre contra la base CON CHECK nativos: en la que los imita con disparadores no
hay nada que leer en `information_schema`.

Uso: python3 tools/verificar-nulos.py [base] [--cliente mysql8]
"""
import re
import subprocess
import sys

ARGS = sys.argv[1:]
CLIENTE = ['mariadb']

if '--cliente' in ARGS:
    i = ARGS.index('--cliente')
    CLIENTE = ARGS[i + 1].split()
    del ARGS[i:i + 2]

BASE = ARGS[0] if ARGS else 'latam_fin'

# Funciones que devuelven NULL cuando reciben NULL. La lista no pretende ser
# exhaustiva: son las que este esquema usa dentro de un CHECK.
PROPAGAN = ('CHAR_LENGTH', 'LENGTH', 'TRIM', 'UPPER', 'LOWER', 'SUBSTRING', 'CONCAT')


def consulta(sql):
    salida = subprocess.run(
        CLIENTE + [BASE, '-sN', '-e', sql],
        capture_output=True, text=True,
    )
    if salida.returncode != 0:
        print('  No se pudo leer', BASE, ':', salida.stderr.strip()[:200])
        sys.exit(2)
    return [l.split('\t') for l in salida.stdout.splitlines() if l.strip()]


def nullables():
    """{tabla: {columna que admite NULL}}"""
    filas = consulta(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS "
        "WHERE TABLE_SCHEMA = DATABASE() AND IS_NULLABLE = 'YES'"
    )
    mapa = {}
    for tabla, columna in filas:
        mapa.setdefault(tabla, set()).add(columna)
    return mapa


def checks():
    """[(tabla, nombre, expresion)]"""
    return consulta(
        "SELECT tc.TABLE_NAME, cc.CONSTRAINT_NAME, cc.CHECK_CLAUSE "
        "FROM information_schema.CHECK_CONSTRAINTS cc "
        "JOIN information_schema.TABLE_CONSTRAINTS tc "
        "  ON tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME "
        " AND tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA "
        "WHERE cc.CONSTRAINT_SCHEMA = DATABASE()"
    )


def se_protege(expresion, columna):
    """.Dice la expresion, en alguna parte, que penso en el NULL de esa columna?

    Valen las DOS formas, porque las dos son pensarlo:

      * `col IS NOT NULL AND f(col)`  -- se exige que haya valor.
      * `col IS NULL OR f(col)`       -- se admite que no lo haya, a proposito.

    Lo que se busca es la ausencia de las dos: una expresion que mete la columna
    en una funcion y NUNCA la nombra junto a NULL es una en la que nadie penso
    en ese caso, y ahi es donde la regla se cae en silencio.
    """
    patron = r'`?\b%s\b`?\s+IS\s+(NOT\s+)?NULL' % re.escape(columna)
    return re.search(patron, expresion, re.I) is not None


def main():
    admite_null = nullables()
    sospechosas = []

    for tabla, nombre, expresion in checks():
        columnas = admite_null.get(tabla, set())
        if not columnas:
            continue

        for columna in columnas:
            # .Aparece dentro de una funcion que propaga NULL?
            dentro = any(
                re.search(r'\b%s\s*\(([^()]|\([^()]*\))*`?\b%s\b`?' % (fn, re.escape(columna)),
                          expresion, re.I)
                for fn in PROPAGAN
            )
            if dentro and not se_protege(expresion, columna):
                sospechosas.append((tabla, nombre, columna, expresion.strip()))

    print()
    print('  CHECKs que se apoyan en una columna que admite NULL')
    print('  ' + '-' * 68)
    print()
    print('  Base: %s    reglas revisadas: %d' % (BASE, len(checks())))
    print()

    if not sospechosas:
        print('\033[32m  Ninguna regla se cae en silencio ante un NULL.\033[0m')
        return 0

    for tabla, nombre, columna, expresion in sospechosas:
        print('\033[31m  ✗ %s.%s\033[0m' % (tabla, nombre))
        print('      `%s` admite NULL y la expresion no lo comprueba antes.' % columna)
        print('      \033[90m%s\033[0m' % expresion[:160])
    print()
    print('\033[31m  %d regla(s) que NO rechazan lo que dicen rechazar.\033[0m' % len(sospechosas))
    print('  Anada `%s IS NOT NULL AND ...` delante: un CHECK solo rechaza cuando es FALSO.'
          % sospechosas[0][2])
    return 1


if __name__ == '__main__':
    sys.exit(main())
