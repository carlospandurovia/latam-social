#!/bin/bash
# Corre todas las suites de restriccion contra los DOS motores logicos:
#   latam_social      -> CHECK nativos
#   latam_social_57   -> los mismos esquemas sin CHECK, con los triggers generados
#
# Cada suite exige base limpia (comprueba que su creador no tenga filas), asi
# que la base se rehace entera en cada pasada. Es barato y evita el falso
# "recree la base y cargue la semilla".
set -e
cd "$(dirname "$0")/../.."
SUITES="2.12-contenido 2.13-finanzas 3.5-activacion 3.6-fiscal 3.7-redes 3.8-pagos 3.9-tarifas 3.10-periodos 3.11-anulacion 3.12-no-borrar"

# Los nombres son argumentos para poder correr la misma bateria en otro motor:
#   MYSQL_CMD=mysql8 bash tools/pruebas/correr-todo.sh latam_m8 latam_m8_57
CON=${1:-latam_social}
SIN=${2:-latam_social_57}

bash tools/rehacer-referencia.sh "$CON" "$SIN" >/dev/null
CLIENTE=${MYSQL_CMD:-mariadb}
$CLIENTE "$CON" < tools/pruebas/semilla.sql
$CLIENTE "$SIN" < tools/pruebas/semilla.sql

tot_ok=0; tot_fail=0
for base in "$CON" "$SIN"; do
  echo ""; echo "###################### $base ######################"
  for s in $SUITES; do
    echo ""; echo "===== $s ====="
    salida=$(bash "tools/pruebas/$s.sh" "$base" 2>&1) || true
    echo "$salida"
    # El resumen viene con colores ANSI incrustados; hay que quitarlos antes de
    # leer los numeros o el total sale siempre en cero y todo parece verde.
    limpio=$(echo "$salida" | sed -E 's/\x1b\[[0-9;]*m//g')
    linea=$(echo "$limpio" | grep -oE '[0-9]+ correctas, [0-9]+ fallidas' | tail -1)
    ok=$(echo "$linea" | awk '{print $1}'); fa=$(echo "$linea" | awk '{print $3}')
    tot_ok=$((tot_ok+${ok:-0})); tot_fail=$((tot_fail+${fa:-0}))
  done
done
echo ""; echo "TOTAL: $tot_ok correctas, $tot_fail fallidas"

# Los fixtures de PHPUnit, contra el esquema de verdad.
#
# Va aqui y no en PHPUnit porque no necesita `vendor/`: se puede correr desde
# donde se escriben las pruebas, que es justo donde no hay Laravel instalado.
# Tres iteraciones seguidas se entregaron en rojo por un fixture que
# contradecia al esquema, y las tres se habrian visto aqui en dos segundos.
echo ""; echo "===== fixturas de PHPUnit ====="
python3 tools/verificar-fixturas.py "$SIN" --cliente "$CLIENTE" || tot_fail=$((tot_fail+1))

# Que el esquema de referencia y las migraciones digan lo mismo sobre periodos.
# Los dos salen de la misma clase, asi que hoy coinciden; esto es lo que hace
# que sigan coincidiendo manana.
echo ""; echo "===== periodos: esquema contra migraciones ====="
python3 tools/verificar-periodos.py || tot_fail=$((tot_fail+1))

[ "$tot_fail" -eq 0 ]
