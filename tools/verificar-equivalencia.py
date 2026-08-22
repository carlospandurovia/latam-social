#!/usr/bin/env python3
"""
La pregunta de DEC-042, respondida con datos.

Compara la base de desarrollo (MariaDB, CHECK nativo) con la copia que simula
produccion (Percona 5.7, sin CHECK, solo triggers generados) y comprueba que
imponen EXACTAMENTE el mismo conjunto de reglas.

Una sola restriccion presente en una y ausente en la otra significa que las
pruebas pasan en desarrollo y la regla no existe donde estan los datos reales.

Uso: python3 tools/verificar-equivalencia.py [base_check] [base_trigger]
"""
import json, os, shlex, subprocess, sys
from pathlib import Path

# Mismo motivo que en los scripts de prueba: en CI el cliente y las
# credenciales son otros. MYSQL_CMD lo sobrescribe.
CLIENTE = shlex.split(os.environ.get('MYSQL_CMD', 'mysql -uroot'))

RAIZ = Path(__file__).resolve().parent.parent
A = sys.argv[1] if len(sys.argv) > 1 else 'latam_fin'
B = sys.argv[2] if len(sys.argv) > 2 else 'latam_fin_57'

def q(db, sql):
    r = subprocess.run(CLIENTE + ['-N', db, '-e', sql], capture_output=True, text=True)
    if r.returncode != 0:
        print(r.stderr); sys.exit(2)
    return [l for l in r.stdout.strip().split('\n') if l]

checks = set(q(A, "SELECT constraint_name FROM information_schema.table_constraints "
                  f"WHERE constraint_schema='{A}' AND constraint_type='CHECK';"))
cubiertos = {}
for t in q(B, "SELECT trigger_name FROM information_schema.triggers "
              f"WHERE trigger_schema='{B}' AND trigger_name LIKE 'tg_ck_%';"):
    cubiertos.setdefault(t[3:-4], set()).add(t[-3:])

decl = {d['nombre'] for d in json.load(open(RAIZ / 'tools/sql/generado/declaraciones.json'))}

print(f"CHECK nativos en {A}: {len(checks)}")
print(f"restricciones declaradas: {len(decl)}")
print(f"restricciones con trigger en {B}: {len(cubiertos)}")

fallos = 0
for etiqueta, conj in [
    (f"CHECK sin trigger equivalente (se perderian en {B})", checks - set(cubiertos)),
    ("trigger sin CHECK correspondiente", set(cubiertos) - checks),
    ("con trigger de INSERT pero no de UPDATE (o al reves)",
     {k for k, v in cubiertos.items() if v != {'ins', 'upd'}}),
    ("CHECK que el generador nunca vio", checks - decl),
]:
    if conj:
        fallos += len(conj)
        print(f"\n  !! {etiqueta}: {len(conj)}")
        for x in sorted(conj): print(f"     - {x}")

print("\n" + ("EQUIVALENTES: las dos bases imponen el mismo conjunto de reglas."
              if not fallos else f"NO EQUIVALENTES: {fallos} discrepancias."))
sys.exit(1 if fallos else 0)
