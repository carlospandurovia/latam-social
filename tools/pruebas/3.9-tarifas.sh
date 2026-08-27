#!/bin/bash
# Pruebas de restriccion de la iteracion 3.9: tarifas, disponibilidad y agenda.
#
# Cubre los tres hallazgos reproducidos ANTES de arreglarlos:
#   H-16  el historial admitia periodos solapados: dos precios el mismo dia
#   H-17  `source` afirmaba «lo declaro el creador» cuando nadie lo dijo
#   H-18  nadie firmaba el precio
# y la decision DEC-068: cero es un precio valido, pero hay que declararlo.
#
# Uso: bash tools/pruebas/3.9-tarifas.sh <base>
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
# El `grep -v` del aviso: en CI el cliente lleva la clave en la linea de
# comandos y MySQL avisa por stderr. Sin filtrarlo, el aviso acaba dentro del
# valor comparado. Ver el comentario largo en 3.8-pagos.sh.
CR="(SELECT id FROM (SELECT id FROM creators WHERE display_name='anatorres') t)"
U1="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) t)"
F1="(SELECT id FROM (SELECT id FROM content_formats ORDER BY id LIMIT 1) t)"
F2="(SELECT id FROM (SELECT id FROM content_formats ORDER BY id LIMIT 1 OFFSET 1) t)"

usados=$($CLIENTE $DB -N -e "SELECT COUNT(*) FROM creator_rates" 2>/dev/null)
if [ -z "$usados" ]; then
  echo "  No puedo leer $DB. .Esta levantado el motor y creada la base?"; exit 2
fi
if [ "$usados" != "0" ]; then
  echo "  Ya hay $usados tarifas: recree la base y cargue la semilla."; exit 2
fi

COLS="creator_id,content_format_id,currency_code,amount,source,created_by_user_id,valid_from"

echo ""
echo "--- H-16: el historial tiene UNA respuesta por fecha ---"
probar "primera tarifa, cerrada en junio" \
 "INSERT INTO creator_rates ($COLS,valid_to) VALUES ($CR,$F1,'PEN',1000,'self_declared',$U1,'2026-01-01','2026-06-30');" OK
probar "otra que empieza en marzo: se pisa con la anterior" \
 "INSERT INTO creator_rates ($COLS,valid_to) VALUES ($CR,$F1,'PEN',2500,'negotiated',$U1,'2026-03-01','2026-09-30');" RECHAZO
probar "el mismo formato en OTRA moneda y las mismas fechas (no es solape)" \
 "INSERT INTO creator_rates ($COLS,valid_to) VALUES ($CR,$F1,'USD',300,'negotiated',$U1,'2026-03-01','2026-09-30');" OK
probar "cerrar la primera el dia ANTES y abrir la nueva" \
 "UPDATE creator_rates SET valid_to='2026-02-28' WHERE currency_code='PEN' AND amount=1000;
  INSERT INTO creator_rates ($COLS) VALUES ($CR,$F1,'PEN',2500,'negotiated',$U1,'2026-03-01');" OK
valor "  y ahora el 2026-05-01 la tarifa en PEN es una sola" \
 "SELECT GROUP_CONCAT(amount) FROM creator_rates WHERE currency_code='PEN' AND '2026-05-01' BETWEEN valid_from AND IFNULL(valid_to,'9999-12-31');" "2500.0000"
probar "mover la vigente hacia atras hasta pisar a la cerrada" \
 "UPDATE creator_rates SET valid_from='2026-01-15' WHERE currency_code='PEN' AND amount=2500;" RECHAZO
probar "cerrar la vigente y abrir una tercera" \
 "UPDATE creator_rates SET valid_to='2026-12-31' WHERE currency_code='PEN' AND amount=2500 AND valid_to IS NULL;
  INSERT INTO creator_rates ($COLS) VALUES ($CR,$F1,'PEN',3000,'negotiated',$U1,'2027-01-01');" OK

echo ""
echo "--- H-17: de donde sale el precio lo dice alguien, no el DEFAULT ---"
probar "sin decir el origen" \
 "INSERT INTO creator_rates (creator_id,content_format_id,currency_code,amount,created_by_user_id,valid_from) VALUES ($CR,$F2,'PEN',800,$U1,'2026-01-01');" RECHAZO
probar "origen inventado" \
 "INSERT INTO creator_rates ($COLS) VALUES ($CR,$F2,'PEN',800,'a_ojo',$U1,'2026-01-01');" RECHAZO
probar "origen declarado: estimada por nosotros" \
 "INSERT INTO creator_rates ($COLS) VALUES ($CR,$F2,'PEN',800,'estimated',$U1,'2026-01-01');" OK

echo ""
echo "--- H-18: alguien firma el precio ---"
probar "tarifa sin decir quien la puso" \
 "INSERT INTO creator_rates (creator_id,content_format_id,currency_code,amount,source,valid_from) VALUES ($CR,$F2,'USD',250,'negotiated','2026-01-01');" RECHAZO

echo ""
echo "--- DEC-068: cero es un precio, pero hay que declararlo ---"
probar "cero a secas" \
 "INSERT INTO creator_rates ($COLS) VALUES ($CR,$F2,'COP',0,'negotiated',$U1,'2026-01-01');" RECHAZO
probar "cero declarado gratuito" \
 "INSERT INTO creator_rates ($COLS,is_gratis) VALUES ($CR,$F2,'COP',0,'negotiated',$U1,'2026-01-01',1);" OK
probar "gratuito CON importe" \
 "INSERT INTO creator_rates ($COLS,is_gratis) VALUES ($CR,$F1,'COP',500,'negotiated',$U1,'2026-01-01',1);" RECHAZO
probar "importe negativo" \
 "INSERT INTO creator_rates ($COLS) VALUES ($CR,$F1,'COP',-100,'negotiated',$U1,'2026-01-01');" RECHAZO

echo ""
echo "--- La disponibilidad tenia el mismo hueco ---"
probar "primera disponibilidad, cerrada en junio" \
 "INSERT INTO creator_availability (creator_id,valid_from,valid_to) VALUES ($CR,'2026-01-01','2026-06-30');" OK
probar "otra que se pisa con la anterior" \
 "INSERT INTO creator_availability (creator_id,valid_from,valid_to) VALUES ($CR,'2026-05-01','2026-12-31');" RECHAZO
probar "cerrar la anterior el dia antes y abrir la nueva" \
 "UPDATE creator_availability SET valid_to='2026-04-30' WHERE valid_to='2026-06-30';
  INSERT INTO creator_availability (creator_id,valid_from) VALUES ($CR,'2026-05-01');" OK
# Cerrar la vigente ANTES de las tres siguientes, y esto no es cosmetica.
#
# Sin este cierre, la fila abierta (valid_to NULL) se solapa con cualquier fecha
# futura, asi que las dos aserciones de RECHAZO de abajo pasaban... por el
# disparador de solape, no por lo que dicen comprobar. Dos pruebas en verde que
# no probaban nada, y solo se vio porque la TERCERA --la que espera OK-- fallo.
# Es el mismo patron de siempre: la asercion de lo permitido es la que descubre
# que el resto mentia.
$CLIENTE $DB -e "UPDATE creator_availability SET valid_to='2027-12-31' WHERE valid_to IS NULL;" 2>/dev/null

probar "dice que viaja y no dice hasta donde" \
 "INSERT INTO creator_availability (creator_id,accepts_travel,valid_from) VALUES ($CR,1,'2028-01-01');" RECHAZO
probar "alcance de viaje inventado" \
 "INSERT INTO creator_availability (creator_id,accepts_travel,travel_scope,valid_from) VALUES ($CR,1,'galactico','2028-01-01');" RECHAZO
probar "viaja, y dice hasta donde" \
 "INSERT INTO creator_availability (creator_id,accepts_travel,travel_scope,valid_from,valid_to) VALUES ($CR,1,'national','2028-01-01','2028-12-31');" OK

echo ""
echo "--- Bloqueos de agenda ---"
probar "un bloqueo normal" \
 "INSERT INTO creator_blackouts (creator_id,starts_on,ends_on,reason) VALUES ($CR,'2026-07-01','2026-07-15','Vacaciones');" OK
probar "un bloqueo que termina antes de empezar" \
 "INSERT INTO creator_blackouts (creator_id,starts_on,ends_on) VALUES ($CR,'2026-08-15','2026-08-01');" RECHAZO

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
