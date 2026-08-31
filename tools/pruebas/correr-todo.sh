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
# La lista NO se escribe aqui: vive en tools/pruebas/SUITES, que es tambien la
# que lee el CI. Tenerla en dos sitios es como se perdieron 3.10, 3.11 y 3.12
# en dos de los tres motores del CI sin que nadie lo notara.
SUITES=$(grep -vE '^[[:space:]]*(#|$)' tools/pruebas/SUITES | tr '\n' ' ')

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
python3 tools/verificar-periodos.py "$SIN" --cliente "$CLIENTE" || tot_fail=$((tot_fail+1))

# Lo que la aplicacion NOMBRA contra lo que la aplicacion TIENE.
#
# Aqui no hay `vendor/` —packagist esta bloqueado— asi que PHPUnit no se puede
# correr, y esa es la razon estructural por la que varias iteraciones se
# entregaron en rojo. Este gate cubre la clase de fallo que mas veces las
# rompio: un nombre de ruta, de plantilla, de permiso, de rol, de metodo o de
# clave validada que no existe. Errores de una letra que tumban la suite entera
# y que se ven leyendo archivos, sin Laravel y sin base de datos.
# Que lo que la base le dice al usuario le quepa en la boca.
#
# `MESSAGE_TEXT` es VARCHAR(128) y MySQL/Percona NO truncan: dan 1648 en vez
# del 45000 del disparador. MariaDB si lo deja pasar, asi que el motor de
# desarrollo perdona y el de produccion no. Cuatro mensajes llevaban rotos
# desde 7.4 sin que ninguna suite lo viera, porque todas comprobaban «esto
# falla» y 1648 tambien es fallar.
echo ""; echo "===== mensajes de la base: caben en 128 ====="
python3 tools/verificar-mensajes.py "$SIN" --cliente "$CLIENTE" || tot_fail=$((tot_fail+1))
python3 tools/verificar-mensajes.py "$CON" --cliente "$CLIENTE" || tot_fail=$((tot_fail+1))

# 9.14: que regla de la base no ha contestado NUNCA a nadie.
#
# Los otros verificadores comprueban que la regla EXISTA y que sea la misma en
# los dos motores. Ninguno comprobaba lo unico que importa el dia que falle: que
# alguien se lo haya preguntado. `campaign_costs` llevaba dos semanas con cinco
# restricciones verdes en todos los verificadores y CERO filas.
echo ""; echo "===== reglas que nadie ha preguntado ====="
python3 tools/verificar-cobertura-sql.py || tot_fail=$((tot_fail+1))

# 9.14: que ruta no exige permiso, y si esta escrito que es a proposito.
echo ""; echo "===== el muro: rutas sin permiso ====="
python3 tools/verificar-muro.py || tot_fail=$((tot_fail+1))

echo ""; echo "===== nombres entre capas ====="
python3 tools/verificar-pantallas.py || tot_fail=$((tot_fail+1))

# 9.17d: el cierre lo tiene que decir una LINEA, no el codigo de salida.
#
# Hasta hoy esto terminaba en silencio: la ultima cifra que se leia era el
# «TOTAL: N correctas, 0 fallidas» de las suites SQL, que NO cuenta los
# verificadores de despues. `verificar-periodos.py` llevaba rojo desde 9.16
# --pedia al esquema el texto viejo de `tver_sin_solape`-- y la pasada entera
# parecia verde porque nadie miraba `$?`. Un aviso que no se ve no es un aviso.
echo ""
if [ "$tot_fail" -eq 0 ]; then
    printf '\033[32mLa pasada entera en verde: suites y verificadores.\033[0m\n'
else
    printf '\033[31m%d comprobacion(es) en rojo en esta pasada.\033[0m\n' "$tot_fail"
fi

[ "$tot_fail" -eq 0 ]
