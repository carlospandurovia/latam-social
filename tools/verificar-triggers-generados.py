#!/usr/bin/env python3
"""
Contrasta lo que el generador CREE que son las columnas de cada restriccion
contra lo que el motor DICE que son.

Existe porque el generador parsea SQL con expresiones regulares, y un parser de
SQL a base de regex se equivoca en silencio. Ya paso dos veces:

  - `PRIMARY` casaba con la columna `primary_color` -> restriccion perdida.
  - La linea de continuacion de un CHECK multilinea parecia una columna
    llamada `OR` -> trigger con NEW.`OR`, que no se crea.

Las dos veces el sintoma habria sido el mismo: la regla se aplica en desarrollo
(CHECK nativo) y no en produccion (el trigger no llego a crearse). Que es
exactamente el fallo que DEC-042 pretendia cerrar.

Uso: python3 tools/verificar-triggers-generados.py <base>
"""
import json, os, re, shlex, subprocess, sys
from pathlib import Path

# Mismo motivo que en los scripts de prueba: en CI el cliente y las
# credenciales son otros. MYSQL_CMD lo sobrescribe.
CLIENTE = shlex.split(os.environ.get('MYSQL_CMD', 'mysql -uroot'))

RAIZ = Path(__file__).resolve().parent.parent
DB = sys.argv[1] if len(sys.argv) > 1 else 'latam_fin'
decl = json.load(open(RAIZ / 'tools/sql/generado/declaraciones.json'))

out = subprocess.run(CLIENTE + ['-N', DB, '-e',
    f"SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='{DB}';"],
    capture_output=True, text=True)
if out.returncode != 0:
    print(out.stderr); sys.exit(2)

reales = {}
for l in out.stdout.strip().split('\n'):
    t, c = l.split('\t')
    reales.setdefault(t, set()).add(c)

def fuera_de_literales(expr):
    o, lit, buf, i = [], False, [], 0
    while i < len(expr):
        c = expr[i]
        if lit:
            if c == "'":
                if i + 1 < len(expr) and expr[i + 1] == "'": i += 1
                else: lit = False
        elif c == "'":
            lit = True; o.append(''.join(buf)); buf = []
        else:
            buf.append(c)
        i += 1
    o.append(''.join(buf))
    return ' '.join(o)

problemas = 0
for d in decl:
    cols = reales.get(d['tabla'], set())
    if not cols:
        print(f"  !! {d['nombre']}: la tabla {d['tabla']} no existe en {DB}")
        problemas += 1
        continue
    vis = fuera_de_literales(d['expresion'])
    deberian = {c for c in cols
                if re.search(r'(?<![`\w.])' + re.escape(c) + r'(?![`\w])', vis)}
    declaradas = set(d['columnas'])
    if deberian != declaradas:
        problemas += 1
        print(f"  !! {d['nombre']} ({d['tabla']})")
        if deberian - declaradas: print(f"     no declaradas: {sorted(deberian - declaradas)}")
        if declaradas - deberian: print(f"     inventadas:    {sorted(declaradas - deberian)}")

print(f"{len(decl)} restricciones contrastadas contra information_schema de {DB}: "
      + ("sin discrepancias." if problemas == 0 else f"{problemas} DISCREPANCIAS."))
sys.exit(1 if problemas else 0)
