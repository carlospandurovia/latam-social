#!/bin/bash
# Pruebas de restriccion de la iteracion 9.21c: el contacto de las marcas.
#
#   ck_clead_status      estado de contacto valido
#   ck_clead_descartado  descartar exige decir POR QUE, y con NULL tambien
#   ck_clead_convertido  convertido dice en que cliente
#   ck_clead_revisado    salir de «nuevo» deja quien lo movio y cuando
#   ck_clead_correo      el correo tiene forma de correo
#   ck_clead_web         la web va con http o https
#   uq_clead_abierto     un solo contacto ABIERTO por correo (columna puerta)
#   tg_clead_no_delete   un contacto no se borra: se descarta con su motivo
#
# La que mas importa es `ck_clead_descartado` con el motivo NULO. En 9.12 se
# aprendio que `CHAR_LENGTH(NULL)` es NULL, la conjuncion entera es NULL y un
# CHECK solo rechaza cuando es FALSO: sin la mitad `note IS NOT NULL`, descartar
# SIN NINGUN motivo pasaria --justo el caso que la regla existe para impedir--.
#
# Uso: bash tools/pruebas/9.21c-prospectos.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.21c - El contacto de las marcas"
echo "==================================================================================="

PAIS=$($CLIENTE $DB -sN -e "SELECT id FROM countries WHERE iso2='PE';" 2>/dev/null | tr -d '\r')
USUARIO=$($CLIENTE $DB -sN -e "SELECT id FROM users ORDER BY id LIMIT 1;" 2>/dev/null | tr -d '\r')
CLIENTE_ID=$($CLIENTE $DB -sN -e "SELECT id FROM client_organizations ORDER BY id LIMIT 1;" 2>/dev/null | tr -d '\r')

if [ -z "${PAIS:-}" ] || [ -z "${USUARIO:-}" ]; then
  echo "  La premisa no se cumple: falta el pais o el usuario de la semilla."
  exit 1
fi

# Esta suite NO se puede limpiar: `tg_clead_no_delete` impide borrar, que es
# justo lo que afirma la ultima asercion. Necesita base recien rehecha, que es
# lo que `correr-todo.sh` hace en cada pasada.
valor "no quedan contactos de una corrida anterior" \
  "SELECT CASE WHEN COUNT(*) = 0 THEN 'limpio' ELSE 'rehaga la base' END
     FROM client_leads WHERE email LIKE 'x921c%';" "limpio"

BASE="INSERT INTO client_leads (uuid,company_name,contact_name,email,country_id,status,submitted_at,created_at)"

echo ""
echo "-- La forma de lo que llega --"

porque "un correo que no es un correo" \
  "$BASE VALUES (UUID(),'X921C','Luis','x921c-sin-arroba',$PAIS,'new',NOW(3),NOW(3));" \
  "ck_clead_correo|forma de correo"

porque "una web por ftp" \
  "INSERT INTO client_leads (uuid,company_name,contact_name,email,country_id,website,status,submitted_at,created_at)
   VALUES (UUID(),'X921C','Luis','x921c-a@ejemplo.pe',$PAIS,'ftp://x.pe','new',NOW(3),NOW(3));" \
  "ck_clead_web|http"

# La fila lleva revisor y fecha A PROPOSITO. Sin ellos rompe DOS reglas
# --`ck_clead_status` y `ck_clead_revisado`, porque cualquier estado que no sea
# `new` exige decir quien lo movio-- y cada motor nombra la que evalua primero:
# MariaDB contestaba `ck_clead_status` y MySQL 8 `ck_clead_revisado`. La
# asercion pasaba en un motor y fallaba en el otro por el mismo dato, que es la
# senal de que estaba afirmando una casualidad. Rompiendo UNA sola regla, los
# dos motores dicen lo mismo.
porque "un estado que no existe" \
  "INSERT INTO client_leads (uuid,company_name,contact_name,email,country_id,status,
      reviewed_by_user_id,reviewed_at,submitted_at,created_at)
   VALUES (UUID(),'X921C','Luis','x921c-a@ejemplo.pe',$PAIS,'pensandolo',$USUARIO,NOW(3),NOW(3),NOW(3));" \
  "ck_clead_status"

probar "y un contacto normal entra" \
  "$BASE VALUES (UUID(),'X921C Marca','Luis','x921c-a@ejemplo.pe',$PAIS,'new',NOW(3),NOW(3));" OK

echo ""
echo "-- Un solo contacto ABIERTO por correo --"

porque "el mismo correo, otra vez, con el primero abierto" \
  "$BASE VALUES (UUID(),'X921C Marca','Luis','X921C-A@ejemplo.pe',$PAIS,'new',NOW(3),NOW(3));" \
  "uq_clead_abierto"

# Y cerrado deja el hueco libre: el ano que viene es un contacto nuevo de verdad.
probar "se descarta el primero, con su motivo" \
  "UPDATE client_leads SET status='discarded', note='No encaja: no tienen presupuesto este ano.',
      reviewed_by_user_id=$USUARIO, reviewed_at=NOW(3)
    WHERE email='x921c-a@ejemplo.pe';" OK

probar "y entonces el mismo correo puede volver a escribir" \
  "$BASE VALUES (UUID(),'X921C Marca','Luis','x921c-a@ejemplo.pe',$PAIS,'new',NOW(3),NOW(3));" OK

echo ""
echo "-- Lo que hay que decir para mover un contacto --"

porque "descartar SIN NINGUN motivo (el caso del NULL)" \
  "INSERT INTO client_leads (uuid,company_name,contact_name,email,country_id,status,
      reviewed_by_user_id,reviewed_at,submitted_at,created_at)
   VALUES (UUID(),'X921C','Luis','x921c-b@ejemplo.pe',$PAIS,'discarded',$USUARIO,NOW(3),NOW(3),NOW(3));" \
  "ck_clead_descartado|por que"

porque "descartar con un motivo que no explica nada" \
  "INSERT INTO client_leads (uuid,company_name,contact_name,email,country_id,status,note,
      reviewed_by_user_id,reviewed_at,submitted_at,created_at)
   VALUES (UUID(),'X921C','Luis','x921c-b@ejemplo.pe',$PAIS,'discarded','no',$USUARIO,NOW(3),NOW(3),NOW(3));" \
  "ck_clead_descartado|por que"

porque "moverlo sin decir quien lo movio" \
  "INSERT INTO client_leads (uuid,company_name,contact_name,email,country_id,status,submitted_at,created_at)
   VALUES (UUID(),'X921C','Luis','x921c-c@ejemplo.pe',$PAIS,'contacted',NOW(3),NOW(3));" \
  "ck_clead_revisado|quien lo movio"

porque "darlo por convertido sin decir en que cliente" \
  "INSERT INTO client_leads (uuid,company_name,contact_name,email,country_id,status,
      reviewed_by_user_id,reviewed_at,submitted_at,created_at)
   VALUES (UUID(),'X921C','Luis','x921c-d@ejemplo.pe',$PAIS,'converted',$USUARIO,NOW(3),NOW(3),NOW(3));" \
  "ck_clead_convertido|en que cliente"

echo ""
echo "-- Y lo que no se puede deshacer --"

porque "borrar un contacto" \
  "DELETE FROM client_leads WHERE email LIKE 'x921c%';" \
  "no se borra"

resumen
