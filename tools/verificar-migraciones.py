#!/usr/bin/env python3
"""
Contrasta lo que declaran las MIGRACIONES con el esquema SQL de referencia.

Durante toda la Fase 2 se probo el SQL de referencia, y por separado se
verifico que las migraciones declararan las mismas RESTRICCIONES. Nunca se
comprobo que declararan las mismas COLUMNAS.

Ese hueco no lo cubre ninguna prueba de restriccion: si una columna esta en el
SQL de referencia y falta en la migracion, las 125 aserciones siguen en verde
--porque corren contra el SQL-- mientras la aplicacion real trabaja sobre una
tabla que no tiene ese campo.

Uso: python3 tools/verificar-migraciones.py [base_de_referencia]
"""
import json, os, shlex, subprocess, sys, re
from pathlib import Path

# Mismo motivo que en los scripts de prueba: en CI el cliente y las
# credenciales son otros. MYSQL_CMD lo sobrescribe.
CLIENTE = shlex.split(os.environ.get('MYSQL_CMD', 'mysql -uroot'))

RAIZ = Path(__file__).resolve().parent.parent
DB = sys.argv[1] if len(sys.argv) > 1 else 'latam_fin'

# Tablas que no vienen de nuestras migraciones de dominio.
IGNORAR = {'migrations', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
           'jobs', 'job_batches', 'failed_jobs'}

# Registro del compilador de restricciones: existe en la aplicacion y no en el
# SQL de referencia, a proposito.
SOLO_MIGRACION = {'schema_constraints'}

# `users` la crea la migracion base de Laravel, que no vive en app/Modules.
# La nuestra solo la EXTIENDE (DEC: users se extiende, no se sustituye), asi
# que estas columnas no aparecen en el grabador y no son un hueco.
BASE_LARAVEL = {'users': {'id', 'name', 'email', 'email_verified_at', 'password',
                          'remember_token', 'created_at', 'updated_at'}}

r = subprocess.run(['php', str(RAIZ / 'tools/recolectar-esquema.php')],
                   capture_output=True, text=True)
if r.returncode != 0:
    print(r.stderr); sys.exit(2)
_grabado = json.loads(r.stdout)
mig = _grabado['tablas']

# H-15: una migracion que CONSULTA una columna antes de que exista.
#
# `verificar-ddl-crudo.py` ejecuta el SQL literal de las migraciones, pero no
# las llamadas al constructor de consultas. La migracion 000490 comprobaba el
# estado de los datos antes de endurecer la tabla y una de esas comprobaciones
# miraba `closed_at` -- una columna que anade ella misma doce lineas mas abajo.
# Sobre una base limpia eso es `ERROR 1054`, y como ocurria en `setUp()`
# fallaron las 18 pruebas de golpe sin que ninguna llegara a ejecutarse.
#
# El grabador ya sabe que columnas existen en cada punto de la secuencia. Solo
# habia que preguntarselo.
avisos = _grabado.get('avisos', [])

if avisos:
    print('\n  Columnas consultadas ANTES de existir (esto es ERROR 1054 en una base limpia):')
    for a in avisos:
        print(f"  !! {a['migracion']}")
        print(f"       {a['tabla']}.{a['columna']}  en ->{a['metodo']}()")
    print('\n  Si la consulta solo debe correr cuando la columna ya este, metala DENTRO')
    print('  del `if (Schema::hasColumn(...))`. Un cortocircuito en un `if` no protege')
    print('  a una consulta que ya se ha ejecutado.')

def q(sql):
    p = subprocess.run(CLIENTE + ['-N', DB, '-e', sql], capture_output=True, text=True)
    if p.returncode != 0:
        print(p.stderr); sys.exit(2)
    return [l.split('\t') for l in p.stdout.strip().split('\n') if l]

ref = {}
for t, c, tipo, nulo, extra in q(
        "SELECT table_name, column_name, column_type, is_nullable, extra "
        f"FROM information_schema.columns WHERE table_schema='{DB}' ORDER BY table_name, ordinal_position;"):
    ref.setdefault(t, {})[c] = {'tipo': tipo.upper(), 'nullable': nulo == 'YES',
                                'generada': 'GENERATED' in extra.upper()}

ref_idx = {}
for t, n, uniq in q("SELECT DISTINCT table_name, index_name, non_unique "
                    f"FROM information_schema.statistics WHERE table_schema='{DB}';"):
    ref_idx.setdefault(t, {})[n] = (uniq == '0')

ref_fk = {}
for t, n in q("SELECT table_name, constraint_name FROM information_schema.table_constraints "
              f"WHERE constraint_schema='{DB}' AND constraint_type='FOREIGN KEY';"):
    ref_fk.setdefault(t, set()).add(n)

def normalizar(tipo):
    """El tipo tal como lo reporta el motor vs. el que declara Blueprint."""
    t = tipo.upper().replace(' ', '')
    t = t.replace('BIGINT(20)UNSIGNED', 'BIGINTUNSIGNED')
    t = t.replace('SMALLINT(5)UNSIGNED', 'SMALLINTUNSIGNED')
    t = t.replace('TINYINT(3)UNSIGNED', 'TINYINTUNSIGNED')
    t = t.replace('INT(10)UNSIGNED', 'INTUNSIGNED')
    t = re.sub(r'^INT\(11\)$', 'INT', t)
    return t

tablas_mig = {t for t in mig if t not in IGNORAR | SOLO_MIGRACION}
tablas_ref = {t for t in ref if t not in IGNORAR}
problemas = 0

solo_mig, solo_ref = tablas_mig - tablas_ref, tablas_ref - tablas_mig
if solo_mig:
    print(f"  !! solo en las migraciones: {sorted(solo_mig)}"); problemas += len(solo_mig)
if solo_ref:
    print(f"  !! solo en el SQL de referencia: {sorted(solo_ref)}"); problemas += len(solo_ref)

for t in sorted(tablas_mig & tablas_ref):
    cm, cr = mig[t]['columnas'], ref[t]
    faltan = set(cr) - set(cm) - BASE_LARAVEL.get(t, set())
    sobran = set(cm) - set(cr)
    if faltan:
        print(f"  !! {t}: columnas del SQL que la migracion NO crea: {sorted(faltan)}"); problemas += len(faltan)
    if sobran:
        print(f"  !! {t}: columnas que la migracion crea de mas: {sorted(sobran)}"); problemas += len(sobran)
    for c in sorted(set(cm) & set(cr)):
        if cr[c].get('generada'):
            continue                      # las generadas se crean con SQL crudo identico
        a, b = normalizar(cr[c]['tipo']), normalizar(cm[c]['tipo'])
        if a != b:
            print(f"  !! {t}.{c}: tipo {cr[c]['tipo']} (SQL) vs {cm[c]['tipo']} (migracion)"); problemas += 1
        if cr[c]['nullable'] != cm[c]['nullable']:
            cual = 'NULL' if cr[c]['nullable'] else 'NOT NULL'
            print(f"  !! {t}.{c}: el SQL lo declara {cual} y la migracion al reves"); problemas += 1

    nombres_mig = set(mig[t]['indices']) | set(mig[t]['unicos']) | set(mig[t]['fk'])
    nombres_mig |= {'PRIMARY'}
    nombres_ref = set(ref_idx.get(t, {})) | ref_fk.get(t, set())
    # Laravel crea un indice implicito por cada FK; el SQL de referencia tambien
    # los declara a mano, asi que solo se compara lo que falta en la migracion.
    ausentes = {n for n in nombres_ref - nombres_mig
                if not n.startswith(('fk_', 'PRIMARY')) and n != 'users_email_unique'}
    if ausentes:
        print(f"  !! {t}: indices del SQL que la migracion no declara: {sorted(ausentes)}"); problemas += len(ausentes)
    fks_ausentes = ref_fk.get(t, set()) - set(mig[t]['fk'])
    if fks_ausentes:
        print(f"  !! {t}: claves foraneas ausentes en la migracion: {sorted(fks_ausentes)}"); problemas += len(fks_ausentes)

problemas += len(avisos)

cols = sum(len(v['columnas']) for k, v in mig.items() if k not in IGNORAR)
print(f"\n{len(tablas_mig)} tablas y {cols} columnas contrastadas contra {DB}: "
      + ("sin discrepancias." if problemas == 0 else f"{problemas} DISCREPANCIAS."))
sys.exit(1 if problemas else 0)
