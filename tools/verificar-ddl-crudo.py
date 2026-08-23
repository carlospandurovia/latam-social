#!/usr/bin/env python3
"""
EJECUTA el SQL crudo de las migraciones contra un motor de verdad.

Por que existe (H-08). `verificar-migraciones.py` contrasta lo que las
migraciones DECLARAN contra el esquema de referencia, pero el grabador
(`recolectar-esquema.php`) simula `DB::statement`: ninguna de esas sentencias
se ha ejecutado nunca. Asi llego hasta la maquina de desarrollo un

    ALTER TABLE creator_tax_profiles MODIFY created_by_user_id BIGINT UNSIGNED NOT NULL

que MariaDB acepta sin rechistar y MySQL 8 rechaza en seco:

    ERROR 1832: Cannot change column 'created_by_user_id':
    used in a foreign key constraint 'fk_ctp_creator_user'

La unica prueba de una migracion es ejecutarla. Esto hace, sobre una copia
limpia del esquema de referencia, un viaje de ida y vuelta:

    esquema final  --down()-->  estado anterior  --up()-->  esquema final

Solo se replican las sentencias que tienen inverso declarado en `down()`,
emparejadas por el NOMBRE del objeto que tocan. Una sentencia sin inverso no se
puede deshacer sobre el esquema final, asi que ejecutarla ahi solo daria un
"duplicate column" que no habla de la migracion sino de este metodo. Esas se
listan aparte, que ademas es informacion util: son las que una vuelta atras
dejaria puestas.

Uso:
    MYSQL_CMD=mysql8 python3 tools/verificar-ddl-crudo.py latam_m8

Lo que importa es correrlo contra MySQL 8, que es el motor de CI y el que se
parece a produccion. Contra MariaDB tambien vale, pero MariaDB es precisamente
el que perdona.
"""
import json
import os
import re
import shlex
import subprocess
import sys
from pathlib import Path

CLIENTE = shlex.split(os.environ.get('MYSQL_CMD', 'mysql -uroot'))
RAIZ = Path(__file__).resolve().parent.parent
REF = sys.argv[1] if len(sys.argv) > 1 else 'latam_fin'
COPIA = 'ddl_crudo_tmp'

VERDE, ROJO, GRIS, FIN = '\033[32m', '\033[31m', '\033[90m', '\033[0m'


def sql(base, sentencia):
    """Devuelve (ok, mensaje). Nunca lanza: aqui el error es el dato.

    El cuerpo de un trigger lleva `;` dentro del BEGIN...END y el cliente los
    trata como fin de sentencia: mandarlo con `-e` lo parte por la mitad y
    devuelve un 1064 que no tiene nada que ver con el SQL. Por eso todo va por
    la entrada estandar y, cuando hay bloque, con DELIMITER alrededor.

    Merece la nota porque ya engano una vez: la primera version de este script
    denuncio 16 triggers rotos que estaban perfectos. Una herramienta de
    verificacion con falsos positivos es peor que no tenerla, porque ensena a
    ignorar sus avisos.
    """
    if re.search(r'\bBEGIN\b', sentencia, re.IGNORECASE):
        guion = f'DELIMITER $$\n{sentencia.rstrip().rstrip(";")}$$\nDELIMITER ;\n'
    else:
        guion = sentencia.rstrip().rstrip(';') + ';\n'
    p = subprocess.run(CLIENTE + ([base] if base else []),
                       input=guion, capture_output=True, text=True)
    if p.returncode != 0 or 'ERROR' in p.stderr.upper():
        return False, (p.stderr or p.stdout).strip().split('\n')[0]
    return True, ''


def consultar(base, sentencia):
    return subprocess.run(CLIENTE + ['-N', '-B', base, '-e', sentencia],
                          capture_output=True, text=True).stdout


def copiar_esquema():
    """Clona la ESTRUCTURA de REF en COPIA. Sin filas: al motor no le hacen
    falta para validar un ALTER, y sin ellas la copia es instantanea."""
    sql(None, f'DROP DATABASE IF EXISTS {COPIA}')
    ok, err = sql(None, f'CREATE DATABASE {COPIA} CHARACTER SET utf8mb4')
    if not ok:
        print(f'No puedo crear {COPIA}: {err}')
        sys.exit(2)
    tablas = consultar(REF, "SELECT table_name FROM information_schema.tables "
                            f"WHERE table_schema='{REF}' AND table_type='BASE TABLE'").split()
    if not tablas:
        print(f'{REF} no tiene tablas. .Corrio tools/rehacer-referencia.sh?')
        sys.exit(2)
    # `CREATE TABLE ... LIKE` copia columnas, indices y CHECK, pero NO las
    # foraneas. Y las foraneas son justamente lo que hace saltar el 1832, asi
    # que hay que anadirlas aparte, cuando ya existen todas las tablas.
    for t in tablas:
        ok, err = sql(COPIA, f'CREATE TABLE {t} LIKE {REF}.{t}')
        if not ok:
            print(f'  no pude clonar {t}: {err}')
    crudo = consultar(REF,
                      "SELECT kcu.constraint_name, kcu.table_name, kcu.column_name, "
                      "kcu.referenced_table_name, kcu.referenced_column_name, rc.delete_rule "
                      "FROM information_schema.key_column_usage kcu "
                      "JOIN information_schema.referential_constraints rc "
                      "  ON rc.constraint_schema=kcu.constraint_schema "
                      " AND rc.constraint_name=kcu.constraint_name "
                      f"WHERE kcu.constraint_schema='{REF}' "
                      "AND kcu.referenced_table_name IS NOT NULL "
                      "ORDER BY kcu.constraint_name, kcu.ordinal_position")
    fks = {}
    for linea in crudo.strip().split('\n'):
        if not linea.strip():
            continue
        n, t, c, rt, rcol, regla = linea.split('\t')
        d = fks.setdefault(n, {'tabla': t, 'cols': [], 'rt': rt, 'rcols': [], 'regla': regla})
        d['cols'].append(c)
        d['rcols'].append(rcol)
    for n, d in fks.items():
        ok, err = sql(COPIA, f"ALTER TABLE {d['tabla']} ADD CONSTRAINT {n} FOREIGN KEY "
                             f"({','.join(d['cols'])}) REFERENCES {d['rt']}({','.join(d['rcols'])}) "
                             f"ON DELETE {d['regla']}")
        if not ok:
            print(f'  no pude recrear {n}: {err}')
    return len(tablas), len(fks)


def objeto(sentencia):
    """Que objeto crea o destruye una sentencia, para emparejar ida y vuelta.

    Devuelve (clase, nombre) o None. El emparejamiento es por NOMBRE, no por
    posicion: una migracion puede deshacer las cosas en otro orden y sigue
    siendo reversible.
    """
    s = ' '.join(sentencia.split())
    for patron, clase in (
        (r'CREATE\s+TRIGGER\s+`?(\w+)`?', 'trigger'),
        (r'DROP\s+TRIGGER\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?', 'trigger'),
        (r'ADD\s+COLUMN\s+`?(\w+)`?', 'columna'),
        (r'DROP\s+COLUMN\s+`?(\w+)`?', 'columna'),
        (r'ADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?(\w+)`?', 'indice'),
        (r'DROP\s+INDEX\s+`?(\w+)`?', 'indice'),
        (r'ADD\s+CONSTRAINT\s+`?(\w+)`?\s+FOREIGN\s+KEY', 'foranea'),
        (r'DROP\s+FOREIGN\s+KEY\s+`?(\w+)`?', 'foranea'),
        (r'MODIFY\s+(?:COLUMN\s+)?`?(\w+)`?', 'modify'),
    ):
        m = re.search(patron, s, re.IGNORECASE)
        if m:
            return (clase, m.group(1).lower())
    return None


datos = subprocess.run(['php', str(RAIZ / 'tools/recolectar-esquema.php'), '--crudo'],
                       capture_output=True, text=True)
if datos.returncode != 0:
    print(datos.stderr)
    sys.exit(2)
migraciones = json.loads(datos.stdout)

nt, nfk = copiar_esquema()
motor = subprocess.run(CLIENTE + ['-N', '-B', '-e', 'SELECT VERSION()'],
                       capture_output=True, text=True).stdout.strip()
print(f'motor {motor} | copia de {REF}: {nt} tablas y {nfk} foraneas')

fallos, ejecutadas = 0, 0
saltadas, sin_vuelta = [], []

for m in migraciones:
    if m['error']:
        print(f"{ROJO}!! {m['migracion']}: down() revienta al grabarse: {m['error']}{FIN}")
        fallos += 1
        continue

    inversos = {objeto(s) for s in m['down']} - {None}
    pares_up = [s for s in m['up'] if objeto(s) in inversos]
    huerfanas = [s for s in m['up'] if objeto(s) not in inversos]
    if huerfanas:
        sin_vuelta.append((m['migracion'], huerfanas, m['crea']))
    if not pares_up:
        saltadas.append((m['migracion'],
                         'crea ' + ', '.join(m['crea']) if m['crea'] else 'sin SQL crudo en down()'))
        continue

    print(f"\n--- {m['migracion']} ---")
    roto = False
    objetos_up = {objeto(s) for s in pares_up}
    pares_down = [s for s in m['down'] if objeto(s) in objetos_up]
    for etiqueta, sentencias in (('down', pares_down), ('up', pares_up)):
        for s in sentencias:
            ok, err = sql(COPIA, s)
            ejecutadas += 1
            marca = f'{VERDE}v{FIN}' if ok else f'{ROJO}x{FIN}'
            print(f'  {marca} {etiqueta:4} {" ".join(s.split())[:96]}')
            if not ok:
                print(f'      {ROJO}{err}{FIN}')
                fallos += 1
                roto = True
    if roto:
        # Una migracion rota a medias deja la copia en un estado que no es ni el
        # de antes ni el de despues. Seguir sobre eso da errores en cascada que
        # no son reales, asi que se empieza de nuevo limpio.
        print(f'  {GRIS}(rehaciendo la copia: quedo a medias){FIN}')
        copiar_esquema()

if saltadas:
    print(f'\n{GRIS}Sin ida y vuelta que probar ({len(saltadas)}):{FIN}')
    for nombre, motivo in saltadas:
        print(f'{GRIS}  - {nombre}  [{motivo}]{FIN}')

if sin_vuelta:
    total = sum(len(h) for _, h, _ in sin_vuelta)
    print(f'\nSentencias crudas sin inverso en down() ({total}). No se replican '
          'aqui, y una vuelta atras las dejaria puestas:')
    for nombre, huerfanas, crea in sin_vuelta:
        nota = '  (la tabla la crea la propia migracion: su down es un dropIfExists)' if crea else ''
        print(f'  {nombre}{nota}')
        for h in huerfanas:
            print(f'{GRIS}      {" ".join(h.split())[:100]}{FIN}')

sql(None, f'DROP DATABASE IF EXISTS {COPIA}')
print(f'\n{ejecutadas} sentencias ejecutadas de verdad contra {motor}: '
      + (f'{ROJO}{fallos} RECHAZADAS{FIN}' if fallos else f'{VERDE}ninguna rechazada{FIN}'))
sys.exit(1 if fallos else 0)
