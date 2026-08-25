#!/bin/bash
# Dos conexiones de verdad contra el mismo motor (`T-27`).
#
# POR QUE HACIA FALTA ESTA SUITE
# ------------------------------
# La iteracion 4.6 cerro las dos carreras de `T-17`, y cada mitad quedo fijada
# por una prueba que se pone roja si se quita el arreglo. Pero eso no es lo
# mismo que probar la concurrencia: una prueba de PHPUnit corre en UNA conexion
# y, con `RefreshDatabase`, dentro de una transaccion abierta, asi que una
# segunda conexion no veria nada de lo que la prueba escribio.
#
# Esta suite si abre dos clientes. Lo que demuestra, en el motor:
#
#   1. Un `UPDATE` que no encuentra ninguna fila NO TOMA NINGUN BLOQUEO.
#      Ese es el corazon de `T-17` y es lo que nadie habia comprobado.
#   2. Por eso dos peticiones simultaneas llegan las dos al `INSERT`, y la
#      segunda se estrella.
#   3. Con el bloqueo de la fila del CLIENTE --lo que hace `bajarPrincipal()`
#      desde 4.6-- se ponen en fila solas.
#
# SIN UN SOLO `sleep` DE SINCRONIZACION
# -------------------------------------
# Una prueba de concurrencia que se sincroniza durmiendo es inestable por
# definicion: en un runner cargado el `sleep` se queda corto y la suite falla
# sin que nada este mal. Una suite inestable es peor que ninguna, porque enseña
# a ignorar el rojo.
#
# Aqui la sincronizacion la hace el propio motor: la sesion B pone
# `innodb_lock_wait_timeout = 1` y **se afirma el error 1205**. Mientras A tenga
# el bloqueo, B da 1205 SIEMPRE, tarde lo que tarde la maquina. Y si el bloqueo
# no existe, B no espera y pasa: los dos resultados son deterministas.
#
# El unico `sleep` que hay es para que el cliente arranque y lea del FIFO, y va
# con reintentos.
set -u

DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

ok=0; fail=0

comprobar() {   # nombre, esperado, obtenido
  if [ "$2" == "$3" ]; then
    printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$3"; ok=$((ok+1))
  else
    printf "  \033[31m✗\033[0m %-70s esperaba %s, obtuvo %s\n" "$1" "$2" "$3"; fail=$((fail+1))
  fi
}

# ---------------------------------------------------------------- sesiones
#
# Una sesion es un cliente `mysql` leyendo de un FIFO. Vive hasta que se le
# cierra el descriptor, asi que su transaccion sigue abierta entre ordenes:
# eso es justo lo que una sola conexion no puede simular.
TMP=$(mktemp -d)
limpiar() {
  exec 3>&- 2>/dev/null
  exec 4>&- 2>/dev/null
  wait 2>/dev/null
  rm -rf "$TMP"
}
trap limpiar EXIT

abrir() {   # descriptor, nombre
  mkfifo "$TMP/$2"
  # `--force`: sin el, el cliente aborta en el primer error y la sesion muere
  # justo cuando lo interesante es lo que pasa DESPUES del error.
  ( $CLIENTE --force "$DB" < "$TMP/$2" > "$TMP/$2.out" 2>&1 ) &
  eval "exec $1>$TMP/$2"
}

# COMO SE SABE QUE UNA SESION YA EJECUTO
# -------------------------------------
# La primera version escribia una marca con `SELECT 'm123'` y la buscaba en la
# salida. No funciona: el cliente `mysql` escribiendo a un archivo bufferiza, y
# la marca aparece tarde o no aparece.
#
# Se usan **cerrojos con nombre** (`GET_LOCK`). Son la unica senal de este motor
# que cumple las dos condiciones que hacen falta:
#
#   - NO son transaccionales: se ven desde otra conexion aunque quien los tomo
#     siga con su transaccion abierta, que es justo el caso.
#   - Se preguntan con SQL desde fuera (`IS_USED_LOCK`), sin depender de que la
#     salida del cliente haya llegado al disco.
#
# Desde MySQL 5.7 y MariaDB 10.0 una sesion puede sostener varios a la vez, asi
# que se van acumulando sin estorbarse.
esperar_paso() {   # cerrojo
  for _ in $(seq 1 200); do
    v=$($CLIENTE "$DB" -N -B -e "SELECT IS_USED_LOCK('$1') IS NOT NULL;" 2>/dev/null | grep -v Warning | tr -d '\r')
    [ "${v:-0}" == "1" ] && return 0
    sleep 0.05
  done
  echo "  (aviso) la sesion no llego al cerrojo $1"
  return 1
}

N=0
paso() {   # descriptor, nombre, sql
  N=$((N+1))
  local cerrojo="p4_11_$N"
  { echo "$3"; echo "SELECT GET_LOCK('$cerrojo', 5);"; } >&"$1"
  esperar_paso "$cerrojo"
}

# LOS ERRORES DE ESTA FASE, NO LOS DE TODAS
# -----------------------------------------
# Grepear el archivo entero acumulaba: la fase 3 veia `120512051205` porque
# arrastraba los 1205 de las fases anteriores. Se guarda el tamano al empezar
# cada fase y se lee solo lo que vino despues.
marcar() {   # nombre -> tamano actual de su salida
  wc -c < "$TMP/$1.out" 2>/dev/null | tr -d ' ' || echo 0
}

# Los codigos DISTINTOS, no uno por linea. En la fase 2 salen dos 1205 --uno
# del `UPDATE` que se topa con la fila sin confirmar de A, otro del `INSERT` que
# espera la clave-- y contar apariciones seria contar detalles del motor. Lo que
# se afirma es QUE clase de error le paso a B, y que no le paso ninguna otra.
errores_desde() {   # nombre, offset
  tail -c "+$(( ${2:-0} + 1 ))" "$TMP/$1.out" 2>/dev/null \
    | grep -oE 'ERROR [0-9]+' | grep -oE '[0-9]+' | sort -u | tr '\n' ' ' | sed 's/ $//'
}

echo ""
echo "=========================================================================="
echo "  4.11 - Concurrencia de verdad: dos conexiones (T-27)"
echo "=========================================================================="

# ---------------------------------------------------------------- premisas
$CLIENTE "$DB" -e "
  INSERT INTO client_organizations (uuid,commercial_name,client_code,country_id,status,created_at)
  SELECT UUID(),'Cliente 4.11','CLI-411',country_id,'active',NOW(3)
    FROM client_organizations WHERE client_code='CLI-0001' LIMIT 1;" 2>/dev/null

CID=$($CLIENTE "$DB" -N -B -e "SELECT id FROM client_organizations WHERE client_code='CLI-411';" 2>/dev/null | grep -v Warning | tr -d '\r')

if [ -z "${CID:-}" ]; then
  echo "  La premisa no se cumple: no existe CLI-0001 del que copiar el pais."
  echo "  Esta suite se corre despues de semilla.sql, como todas."
  exit 1
fi

comprobar "el cliente de la prueba existe y no tiene contactos" "0" \
  "$($CLIENTE "$DB" -N -B -e "SELECT COUNT(*) FROM contacts WHERE client_organization_id=$CID;" 2>/dev/null | grep -v Warning | tr -d '\r')"

INSERTAR="INSERT INTO contacts (uuid,client_organization_id,full_name,contact_email,contact_type,is_primary,status,created_at)"
BAJAR="UPDATE contacts SET is_primary=0 WHERE client_organization_id=$CID AND contact_type='commercial' AND is_primary=1 AND status='active';"

echo ""
echo "--- 1. El UPDATE que no encuentra nada no toma ningun bloqueo ---"
# Es el corazon de T-17, y hasta ahora nadie lo habia comprobado contra el
# motor: estaba deducido, no medido.

abrir 3 a
abrir 4 b
paso 3 a "SET SESSION innodb_lock_wait_timeout=10; START TRANSACTION; $BAJAR"
off=$(marcar b)
paso 4 b "SET SESSION innodb_lock_wait_timeout=1;  START TRANSACTION; $BAJAR"

comprobar "con el puesto LIBRE, B no espera a A (ningun 1205)" "" "$(errores_desde b "$off")"

paso 3 a "ROLLBACK;"
paso 4 b "ROLLBACK;"

echo ""
echo "--- 1b. Y con el puesto OCUPADO si espera, que es lo que da sentido a 1 ---"
# La leccion que esta sesion ha repetido seis veces: la asercion de que algo NO
# pasa solo significa algo si se demuestra que en el caso contrario SI pasa.
#
# Sin esto, la prueba 1 saldria verde igual si el `innodb_lock_wait_timeout` no
# se estuviera aplicando, si las dos sesiones fueran en realidad la misma, o si
# el `UPDATE` no se estuviera ejecutando. Aqui se ve que el aparato funciona:
# mismo `UPDATE`, mismas dos sesiones, y ahora B espera.
$CLIENTE "$DB" -e "$INSERTAR VALUES (UUID(),$CID,'Ya esta','ya@x.test','commercial',1,'active',NOW(3));" 2>/dev/null

paso 3 a "START TRANSACTION; $BAJAR"
off=$(marcar b)
paso 4 b "START TRANSACTION; $BAJAR"

comprobar "con el puesto OCUPADO, B espera a A (1205)" "1205" "$(errores_desde b "$off")"

paso 3 a "ROLLBACK;"
paso 4 b "ROLLBACK;"
$CLIENTE "$DB" -e "DELETE FROM contacts WHERE client_organization_id=$CID;" 2>/dev/null

echo ""
echo "--- 2. Y por eso los dos llegan al INSERT, y el segundo choca ---"
paso 3 a "START TRANSACTION; $BAJAR $INSERTAR VALUES (UUID(),$CID,'Ana A','a@x.test','commercial',1,'active',NOW(3));"
off=$(marcar b)
paso 4 b "START TRANSACTION; $BAJAR $INSERTAR VALUES (UUID(),$CID,'Ana B','b@x.test','commercial',1,'active',NOW(3));"

# B espera a que A confirme, porque la unica ya reservo la clave. Con el tope a
# 1 segundo eso es un 1205 determinista.
# Dos esperas, no una: el `UPDATE` de B se topa con la fila que A inserto y aun
# no confirmo --ahi el puesto YA esta ocupado, y entonces el UPDATE si bloquea--
# y despues el `INSERT` espera la clave de la unica. Las dos son 1205 y ninguna
# otra cosa le pasa a B: no escribe nada.
comprobar "B se para en seco: solo esperas de bloqueo, nada escrito" "1205" "$(errores_desde b "$off")"

paso 3 a "ROLLBACK;"
paso 4 b "ROLLBACK;"

echo ""
echo "--- 3. Con el bloqueo del CLIENTE, se ponen en fila ANTES de tocar nada ---"
# Lo que hace `Contactos::bajarPrincipal()` desde 4.6. La diferencia no es que
# la base proteja el dato --ya lo protegia-- sino DONDE espera B: aqui espera
# antes de escribir, asi que cuando le toca ve lo que A dejo y releva bien.
BLOQUEO="SELECT id FROM client_organizations WHERE id=$CID FOR UPDATE;"

paso 3 a "START TRANSACTION; $BLOQUEO"
off=$(marcar b)
paso 4 b "START TRANSACTION; $BLOQUEO"

comprobar "B espera en la fila del cliente (1205), sin haber escrito nada" "1205" \
  "$(errores_desde b "$off")"

comprobar "y no hay ningun contacto escrito" "0" \
  "$($CLIENTE "$DB" -N -B -e "SELECT COUNT(*) FROM contacts WHERE client_organization_id=$CID;" 2>/dev/null | grep -v Warning | tr -d '\r')"

paso 3 a "ROLLBACK;"
paso 4 b "ROLLBACK;"

echo ""
echo "--- 4. Y cuando A suelta, B ve lo que A dejo ---"
paso 3 a "START TRANSACTION; $BLOQUEO $BAJAR $INSERTAR VALUES (UUID(),$CID,'Ana A','a@x.test','commercial',1,'active',NOW(3)); COMMIT;"
paso 4 b "SET SESSION innodb_lock_wait_timeout=10; START TRANSACTION; $BLOQUEO $BAJAR $INSERTAR VALUES (UUID(),$CID,'Ana B','b@x.test','commercial',1,'active',NOW(3)); COMMIT;"

comprobar "los dos contactos existen" "2" \
  "$($CLIENTE "$DB" -N -B -e "SELECT COUNT(*) FROM contacts WHERE client_organization_id=$CID;" 2>/dev/null | grep -v Warning | tr -d '\r')"
comprobar "y solo UNO es el principal: B relevo a A en vez de chocar" "1" \
  "$($CLIENTE "$DB" -N -B -e "SELECT COUNT(*) FROM contacts WHERE client_organization_id=$CID AND is_primary=1 AND status='active';" 2>/dev/null | grep -v Warning | tr -d '\r')"
comprobar "el principal es B, que llego el ultimo" "Ana B" \
  "$($CLIENTE "$DB" -N -B -e "SELECT full_name FROM contacts WHERE client_organization_id=$CID AND is_primary=1 AND status='active';" 2>/dev/null | grep -v Warning | tr -d '\r')"

# Esta suite recoge lo suyo.
#
# Las demas dejan sus filas puestas porque la bateria rehace las bases en cada
# pasada. Esta ademas abre dos sesiones con transacciones y cerrojos con nombre,
# asi que dejarlo todo cerrado es parte de lo que prueba: si algo quedara
# colgado, la siguiente pasada se quedaria esperando y el sintoma seria un CI
# que no termina, que es la peor forma de fallar de todas.
paso 3 a "ROLLBACK;"
paso 4 b "ROLLBACK;"
exec 3>&-
exec 4>&-
$CLIENTE "$DB" -e "DELETE FROM contacts WHERE client_organization_id=$CID;
  DELETE FROM client_organizations WHERE id=$CID;" 2>/dev/null

echo ""
echo "=========================================================================="
if [ $fail -eq 0 ]; then
  printf "  \033[32m%d correctas\033[0m, %d fallidas\n" "$ok" "$fail"
else
  printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" "$ok" "$fail"
fi
echo "=========================================================================="

[ $fail -eq 0 ]
