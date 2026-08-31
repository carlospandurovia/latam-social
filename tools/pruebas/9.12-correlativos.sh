#!/bin/bash
# Pruebas de restriccion de la iteracion 9.12: series, tipos y el libro de numeros.
#
#   ck_dtype_code        el codigo del tipo es un identificador, no una frase
#   ck_dtype_largo       entre 1 y 12 digitos
#   ck_dtype_patron      una forma de serie sin etiqueta deja el formulario mudo
#   ck_ds_serie          la serie va en mayusculas y digitos
#   ck_ds_env            pruebas o produccion, no una tercera palabra
#   ck_ds_number         el correlativo empieza en 1
#   ck_ds_defecto        una serie apagada no puede ser la de por defecto
#   uq_ds_series         la misma serie no se declara dos veces
#   uq_ds_default        UNA sola serie por defecto por (sociedad, tipo, entorno)
#   tg_ds_forma_ins/upd  la forma la declara el TIPO, y el tipo es de un pais
#   ck_dn_status         estado de numero valido
#   ck_dn_usado          un numero usado dice en que documento
#   ck_dn_anulado        y uno anulado dice por que, con un motivo escrito
#   ck_dn_reservado      un reservado no puede estar ya usado
#   uq_dn_number         el mismo numero no sale dos veces de la misma serie
#   tg_dn_no_delete      informacion fiscal: no se borra
#   tg_dn_inmutable      ni se reescribe, y los estados van en una direccion
#
# Y la parte que no es una restriccion sino una CARRERA: dos conexiones de
# verdad reservando a la vez, con el patron de `4.11` (`T-27`). Sin ella,
# «bajo bloqueo, sin duplicados incluso bajo concurrencia» --que es lo que dice
# `BR-LE-007` con todas sus letras-- seria una afirmacion sin comprobar.
#
# Uso: bash tools/pruebas/9.12-correlativos.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.12 - Un numero sale una sola vez"
echo "==================================================================================="

# --------------------------------------------------------------------- premisas
#
# La suite construye lo suyo --serie `X912`-- y lo deja como estaba al terminar,
# salvo el libro de numeros: `tg_dn_no_delete` impide borrarlo, igual que 9.18
# no puede borrar sus politicas. Es correcto --un numero emitido es un hecho--
# y por eso se comprueba la premisa antes de empezar, en vez de descubrirlo con
# seis fallos que acusan a seis reglas distintas.
valor "no quedan numeros de una corrida anterior" \
  "SELECT CASE WHEN COUNT(*) = 0 THEN 'limpio' ELSE 'rehaga la base' END
     FROM document_numbers dn JOIN document_series ds ON ds.id = dn.document_series_id
    WHERE ds.series IN ('F912','F915');" "limpio"

SOC=$($CLIENTE $DB -sN -e "SELECT id FROM legal_entities WHERE code='CTS-PE';" 2>/dev/null | tr -d '\r')
SOC_CO=$($CLIENTE $DB -sN -e "SELECT id FROM legal_entities WHERE code='CTS-CO';" 2>/dev/null | tr -d '\r')
TIPO=$($CLIENTE $DB -sN -e "SELECT dt.id FROM document_types dt JOIN countries c ON c.id=dt.country_id
                             WHERE c.iso2='PE' AND dt.code='invoice';" 2>/dev/null | tr -d '\r')
TIPO_B=$($CLIENTE $DB -sN -e "SELECT dt.id FROM document_types dt JOIN countries c ON c.id=dt.country_id
                               WHERE c.iso2='PE' AND dt.code='boleta';" 2>/dev/null | tr -d '\r')
TIPO_CO=$($CLIENTE $DB -sN -e "SELECT dt.id FROM document_types dt JOIN countries c ON c.id=dt.country_id
                                WHERE c.iso2='CO' AND dt.code='invoice';" 2>/dev/null | tr -d '\r')

if [ -z "${SOC:-}" ] || [ -z "${TIPO:-}" ] || [ -z "${TIPO_CO:-}" ]; then
  echo "  La premisa no se cumple: falta la sociedad o los tipos de la semilla."
  echo "  Esta suite se corre despues de semilla.sql, como todas."
  exit 1
fi

echo ""
echo "-- El catalogo de tipos: lo que el pais declara --"

porque "un codigo con espacios" \
  "INSERT INTO document_types (country_id,code,name,number_length,created_at)
   SELECT id,'Factura Nueva','x',8,NOW(3) FROM countries WHERE iso2='PE';" \
  "ck_dtype_code|minusculas"

porque "un correlativo de 20 digitos" \
  "INSERT INTO document_types (country_id,code,name,number_length,created_at)
   SELECT id,'x912a','x',20,NOW(3) FROM countries WHERE iso2='PE';" \
  "ck_dtype_largo|1 y 12"

porque "una forma de serie sin decir como se pide" \
  "INSERT INTO document_types (country_id,code,name,series_pattern,number_length,created_at)
   SELECT id,'x912b','x','^Z[0-9]{3}\$',8,NOW(3) FROM countries WHERE iso2='PE';" \
  "ck_dtype_patron|forma"

probar "y con etiqueta, entra" \
  "INSERT INTO document_types (country_id,code,name,series_pattern,series_label,number_length,created_at)
   SELECT id,'x912b','Tipo de prueba 9.12','^X[0-9]{3}\$','Serie: X y tres digitos',6,NOW(3)
     FROM countries WHERE iso2='PE';" OK

TIPO_X=$($CLIENTE $DB -sN -e "SELECT id FROM document_types WHERE code='x912b';" 2>/dev/null | tr -d '\r')

echo ""
echo "-- La serie: su forma la declara el tipo, no el codigo --"

porque "una serie en minusculas" \
  "INSERT INTO document_series (legal_entity_id,document_type_id,series,environment,created_at)
   VALUES ($SOC,$TIPO,'f912','production',NOW(3));" \
  "ck_ds_serie|mayusculas"

porque "un entorno que no existe" \
  "INSERT INTO document_series (legal_entity_id,document_type_id,series,environment,created_at)
   VALUES ($SOC,$TIPO,'F912','preproduccion',NOW(3));" \
  "ck_ds_env|sandbox"

porque "un correlativo que empieza en cero" \
  "INSERT INTO document_series (legal_entity_id,document_type_id,series,next_number,environment,created_at)
   VALUES ($SOC,$TIPO,'F912',0,'production',NOW(3));" \
  "ck_ds_number|empieza en 1"

# La regla de forma: una factura peruana va con F. Es la que hace que el
# formulario pida «Serie: F y tres mas» sin una linea de codigo por pais.
porque "una serie que no tiene la forma de su tipo" \
  "INSERT INTO document_series (legal_entity_id,document_type_id,series,environment,created_at)
   VALUES ($SOC,$TIPO,'B912','production',NOW(3));" \
  "forma que exige"

# Y la que impide el cruce: una sociedad peruana no emite comprobantes
# colombianos. Si los emitiera, seria a traves de la sociedad colombiana.
porque "un tipo de otro pais en una sociedad peruana" \
  "INSERT INTO document_series (legal_entity_id,document_type_id,series,environment,created_at)
   VALUES ($SOC,$TIPO_CO,'F912','production',NOW(3));" \
  "de otro pais"

# `is_default = 0` a proposito: la semilla ya trae `F001` como la de por
# defecto de este tipo, y eso es justo lo que afirma la asercion siguiente.
probar "la serie con la forma correcta entra" \
  "INSERT INTO document_series (legal_entity_id,document_type_id,series,next_number,environment,is_active,is_default,created_at)
   VALUES ($SOC,$TIPO,'F912',1,'production',1,0,NOW(3));" OK

SERIE=$($CLIENTE $DB -sN -e "SELECT id FROM document_series WHERE series='F912';" 2>/dev/null | tr -d '\r')

porque "la misma serie declarada dos veces" \
  "INSERT INTO document_series (legal_entity_id,document_type_id,series,environment,created_at)
   VALUES ($SOC,$TIPO,'F912','production',NOW(3));" \
  "uq_ds_series"

# La columna puerta. Con dos por defecto, «emitir una factura» no tendria
# respuesta hasta que alguien eligiera, y el que elija sera el `first()` de turno.
porque "una segunda serie POR DEFECTO del mismo tipo que la sembrada" \
  "INSERT INTO document_series (legal_entity_id,document_type_id,series,environment,is_active,is_default,created_at)
   VALUES ($SOC,$TIPO,'F913','production',1,1,NOW(3));" \
  "uq_ds_default"

probar "pero una segunda serie normal, si" \
  "INSERT INTO document_series (legal_entity_id,document_type_id,series,environment,is_active,is_default,created_at)
   VALUES ($SOC,$TIPO,'F913','production',1,0,NOW(3));" OK

porque "una serie apagada marcada por defecto" \
  "INSERT INTO document_series (legal_entity_id,document_type_id,series,environment,is_active,is_default,created_at)
   VALUES ($SOC,$TIPO,'F914','production',0,1,NOW(3));" \
  "ck_ds_defecto|apagada"

# El tipo `x912b` tiene SEIS digitos: pasar de 999999 es quedarse sin serie, y
# el sitio donde se descubriria es el dia que no se pueda emitir.
probar "una serie del tipo corto, dentro de su longitud" \
  "INSERT INTO document_series (legal_entity_id,document_type_id,series,next_number,environment,created_at)
   VALUES ($SOC,$TIPO_X,'X912',999999,'production',NOW(3));" OK

porque "y una que ya no cabe en sus seis digitos" \
  "INSERT INTO document_series (legal_entity_id,document_type_id,series,next_number,environment,created_at)
   VALUES ($SOC,$TIPO_X,'X913',1000000,'production',NOW(3));" \
  "se agoto"

echo ""
echo "-- El libro: cada numero que sale queda escrito --"

probar "se reserva el primero" \
  "INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,created_at)
   VALUES ($SERIE,1,'F912-00000001','reserved',NOW(3),NOW(3));" OK

porque "el mismo numero, otra vez" \
  "INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,created_at)
   VALUES ($SERIE,1,'F912-00000001','reserved',NOW(3),NOW(3));" \
  "uq_dn_number"

porque "un estado que no existe" \
  "INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,created_at)
   VALUES ($SERIE,2,'F912-00000002','emitido',NOW(3),NOW(3));" \
  "ck_dn_status|Estado de numero"

porque "usado sin decir en que documento" \
  "INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,used_at,created_at)
   VALUES ($SERIE,2,'F912-00000002','used',NOW(3),NOW(3),NOW(3));" \
  "ck_dn_usado|que documento"

porque "anulado sin motivo" \
  "INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,voided_at,created_at)
   VALUES ($SERIE,2,'F912-00000002','voided',NOW(3),NOW(3),NOW(3));" \
  "ck_dn_anulado|por que"

porque "anulado con un motivo que no explica nada" \
  "INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,voided_at,void_reason,created_at)
   VALUES ($SERIE,2,'F912-00000002','voided',NOW(3),NOW(3),'error',NOW(3));" \
  "ck_dn_anulado|por que"

probar "y con un motivo escrito, si" \
  "INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,voided_at,void_reason,created_at)
   VALUES ($SERIE,2,'F912-00000002','voided',NOW(3),NOW(3),'La peticion fallo antes de emitir.',NOW(3));" OK

porque "un reservado que ya viene usado" \
  "INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,used_at,created_at)
   VALUES ($SERIE,3,'F912-00000003','reserved',NOW(3),NOW(3),NOW(3));" \
  "ck_dn_reservado"

echo ""
echo "-- Y lo que no se puede deshacer --"

porque "borrar un numero" \
  "DELETE FROM document_numbers WHERE document_series_id=$SERIE AND number=1;" \
  "no se borra"

porque "cambiarle el numero" \
  "UPDATE document_numbers SET number=99 WHERE document_series_id=$SERIE AND number=1;" \
  "no se reescribe"

porque "cambiarle la fecha de reserva" \
  "UPDATE document_numbers SET reserved_at='2020-01-01 00:00:00' WHERE document_series_id=$SERIE AND number=1;" \
  "no se reescribe"

probar "un reservado se marca usado" \
  "UPDATE document_numbers SET status='used',used_at=NOW(3),entity_type='client_invoice',entity_id=1
    WHERE document_series_id=$SERIE AND number=1;" OK

porque "y un usado ya no vuelve atras" \
  "UPDATE document_numbers SET status='reserved',used_at=NULL,entity_type=NULL,entity_id=NULL
    WHERE document_series_id=$SERIE AND number=1;" \
  "usado y anulado son finales"

porque "ni un anulado se reutiliza" \
  "UPDATE document_numbers SET status='reserved',voided_at=NULL,void_reason=NULL
    WHERE document_series_id=$SERIE AND number=2;" \
  "usado y anulado son finales"

# ============================================================================
#  La carrera: dos conexiones de verdad (patron de 4.11, `T-27`)
# ============================================================================
#
# Una prueba de PHPUnit corre en UNA conexion y dentro de una transaccion
# abierta: no puede ver a otra sesion, asi que no puede probar esto. Y sin
# probarlo, «se asigna bajo bloqueo, sin duplicados incluso bajo concurrencia»
# --`BR-LE-007`, en rojo-- es una frase.
#
# Sin un solo `sleep` de sincronizacion: la sesion B pone
# `innodb_lock_wait_timeout = 1` y se afirma el **1205**. Mientras A tenga el
# bloqueo, B da 1205 SIEMPRE, tarde lo que tarde la maquina; si el bloqueo no
# existe, B no espera. Los dos resultados son deterministas.
echo ""
echo "-- La carrera: dos conexiones reservando a la vez --"

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
  ( $CLIENTE --force "$DB" < "$TMP/$2" > "$TMP/$2.out" 2>&1 ) &
  eval "exec $1>$TMP/$2"
}

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
  local cerrojo="p9_12_$N"
  { echo "$3"; echo "SELECT GET_LOCK('$cerrojo', 5);"; } >&"$1"
  esperar_paso "$cerrojo"
}

marcar() { wc -c < "$TMP/$1.out" 2>/dev/null | tr -d ' ' || echo 0; }

errores_desde() {   # nombre, offset
  tail -c "+$(( ${2:-0} + 1 ))" "$TMP/$1.out" 2>/dev/null \
    | grep -oE 'ERROR [0-9]+' | grep -oE '[0-9]+' | sort -u | tr '\n' ' ' | sed 's/ $//'
}

# La serie de la carrera, aparte de la de arriba para que los numeros ya
# escritos no estorben.
$CLIENTE $DB -e "INSERT INTO document_series (legal_entity_id,document_type_id,series,next_number,environment,created_at)
                 VALUES ($SOC,$TIPO,'F915',1,'production',NOW(3));" 2>/dev/null
CARRERA=$($CLIENTE $DB -sN -e "SELECT id FROM document_series WHERE series='F915';" 2>/dev/null | tr -d '\r')

LEER_BLOQUEANDO="SELECT next_number FROM document_series WHERE id=$CARRERA FOR UPDATE;"
LEER_SUELTO="SELECT next_number FROM document_series WHERE id=$CARRERA;"

abrir 3 a
abrir 4 b

# 1. Con `FOR UPDATE` --lo que hace `Correlativos::reservar()`-- B espera.
paso 3 a "SET SESSION innodb_lock_wait_timeout=10; START TRANSACTION; $LEER_BLOQUEANDO"
off=$(marcar b)
paso 4 b "SET SESSION innodb_lock_wait_timeout=1;  START TRANSACTION; $LEER_BLOQUEANDO"

comprobar_igual() {   # nombre, esperado, obtenido
  if [ "$2" == "$3" ]; then _bien "$1" "$3"; else _mal "$1" "esperaba '$2', obtuvo '$3'"; fi
}

comprobar_igual "con FOR UPDATE, B espera a A (1205)" "1205" "$(errores_desde b "$off")"

paso 3 a "ROLLBACK;"
paso 4 b "ROLLBACK;"

# 1b. El contraejemplo, sin el cual lo anterior no significa nada: la misma
# lectura SIN bloquear no espera, y por eso las dos sesiones leerian el mismo
# contador. Es exactamente `MAX()+1`, y es exactamente lo que no se hace.
paso 3 a "START TRANSACTION; $LEER_BLOQUEANDO"
off=$(marcar b)
paso 4 b "START TRANSACTION; $LEER_SUELTO"

comprobar_igual "y sin FOR UPDATE, B no espera: leeria el mismo numero" "" "$(errores_desde b "$off")"

paso 3 a "ROLLBACK;"
paso 4 b "ROLLBACK;"

# 2. Y si los dos llegaran al INSERT con el mismo numero --porque alguien
# escribiera manana un camino sin bloqueo-- la unica lo rechaza. El bloqueo es
# la primera linea; `uq_dn_number` es la ultima.
paso 3 a "START TRANSACTION;
          INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,created_at)
          VALUES ($CARRERA,1,'F915-00000001','reserved',NOW(3),NOW(3));"
off=$(marcar b)
paso 4 b "SET SESSION innodb_lock_wait_timeout=1; START TRANSACTION;
          INSERT INTO document_numbers (document_series_id,number,full_number,status,reserved_at,created_at)
          VALUES ($CARRERA,1,'F915-00000001','reserved',NOW(3),NOW(3));"

# B espera la clave que A ya reservo y no confirmo: con el tope a 1 segundo eso
# es un 1205 determinista. Si A confirmara, seria un 1062; las dos cosas son
# «no entra», que es lo que se afirma.
comprobar_igual "dos INSERT del mismo numero: el segundo no entra" "1205" "$(errores_desde b "$off")"

paso 3 a "ROLLBACK;"
paso 4 b "ROLLBACK;"

exec 3>&- 2>/dev/null
exec 4>&- 2>/dev/null

# ------------------------------------------------------------------- limpieza
#
# Las series se pueden borrar --no son un hecho, son configuracion-- salvo las
# que ya dieron numeros: `fk_dn_serie` es RESTRICT, y eso tambien es correcto.
# Se borran las que se pueda y se dice cuantas quedan.
$CLIENTE $DB -e "DELETE FROM document_series WHERE series IN ('F913','X912','F915');" >/dev/null 2>&1
$CLIENTE $DB -e "DELETE FROM document_types WHERE code='x912b';" >/dev/null 2>&1

resumen
