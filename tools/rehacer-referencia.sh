#!/bin/bash
# Rehace las dos bases de referencia desde cero:
#   latam_fin     -> los .sql tal cual, con CHECK nativos (motor moderno)
#   latam_fin_57  -> los -sin-check.sql mas triggers.sql (lo que veria Percona 5.7)
#
# Correr SIEMPRE despues de tocar cualquier tools/sql/*.sql y ANTES de
# verificar-migraciones.py, verificar-triggers-generados.py y
# verificar-equivalencia.py. Esos tres contrastan las migraciones contra estas
# bases; si vienen de una iteracion anterior denuncian diferencias que no
# existen, que es exactamente lo que paso al cerrar 3.7.
#
# El orden de carga no esta escrito a mano: se calcula leyendo los REFERENCES
# de cada esquema. Un esquema nuevo entra solo en su sitio.
set -e
cd "$(dirname "$0")/.."
CLIENTE=${MYSQL_CMD:-mariadb}
CON=${1:-latam_fin}
SIN=${2:-latam_fin_57}

ORDEN=$(python3 - <<'PY'
import re, glob, os
mods = {}
for f in glob.glob('tools/sql/*.sql'):
    s = os.path.basename(f)[:-4]
    if s in ('ejemplo-triggers-generados', 'sonda-produccion'):
        continue
    t = open(f, encoding='utf-8').read()
    mods[s] = {'crea': set(re.findall(r'CREATE TABLE (\w+)', t)),
               'ref':  set(re.findall(r'REFERENCES (\w+)', t))}
dueno = {t: m for m, d in mods.items() for t in d['crea']}
dep = {m: {dueno[r] for r in d['ref'] if r in dueno and dueno[r] != m} for m, d in mods.items()}
orden, pend = [], set(mods)
while pend:
    listo = [m for m in sorted(pend) if not (dep[m] - set(orden))]
    if not listo:
        raise SystemExit('ciclo entre esquemas: ' + repr({m: dep[m] - set(orden) for m in pend}))
    orden += listo
    pend -= set(listo)
print(' '.join(orden))
PY
)
echo "orden: $ORDEN"

$CLIENTE -e "DROP DATABASE IF EXISTS $CON; CREATE DATABASE $CON CHARACTER SET utf8mb4;"
$CLIENTE -e "DROP DATABASE IF EXISTS $SIN; CREATE DATABASE $SIN CHARACTER SET utf8mb4;"

for m in $ORDEN; do
  $CLIENTE "$CON" < "tools/sql/$m.sql"
  $CLIENTE "$SIN" < "tools/sql/generado/$m-sin-check.sql"
  printf "  %-20s cargado\n" "$m"
done
$CLIENTE "$SIN" < tools/sql/generado/triggers.sql
echo "  triggers.sql         cargado"

t1=$($CLIENTE -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$CON'")
t2=$($CLIENTE -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$SIN'")
tg=$($CLIENTE -N -e "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$SIN'")
echo "$CON: $t1 tablas | $SIN: $t2 tablas y $tg disparadores"
