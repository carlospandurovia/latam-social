#!/bin/bash
# Pruebas de restriccion de la iteracion 9.9a: las tasas de impuesto.
#
#   ck_tax_rate           entre 0 y 100: una tasa del 100 % no es una tasa
#   ck_tax_code           mayusculas y sin espacios, como sale impreso
#   ck_tax_dates          no termina antes de empezar
#   ck_tax_nombre         el impuesto necesita un nombre legible
#   tg_tax_sin_solape_ins UNA sola respuesta a «.cuanto era el IGV?» por fecha
#   tg_tax_sin_solape_upd y tambien al editar, que es donde se olvida
#   tg_tax_no_delete      una tasa cerrada explica el impuesto de lo ya emitido
#
# La que mas importa es el solape. `invoices` guarda el importe del impuesto,
# pero quien quiera reconstruir POR QUE fue ese importe tiene que poder preguntar
# «cuanto era el IGV el 3 de marzo» y recibir UNA respuesta. Con dos periodos
# pisados hay dos, y entonces la factura no se puede explicar.
#
# Uso: bash tools/pruebas/9.9a-impuestos.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.9a - El impuesto es un dato"
echo "==================================================================================="

PE=$($CLIENTE $DB -sN -e "SELECT id FROM countries WHERE iso2='PE';" 2>/dev/null | tr -d '\r')
CO=$($CLIENTE $DB -sN -e "SELECT id FROM countries WHERE iso2='CO';" 2>/dev/null | tr -d '\r')

if [ -z "${PE:-}" ] || [ -z "${CO:-}" ]; then
  echo "  La premisa no se cumple: faltan los paises de la semilla."
  exit 1
fi

# Esta suite NO se puede limpiar: `tg_tax_no_delete` impide borrar, que es justo
# lo que afirma la ultima asercion. Necesita base recien rehecha.
valor "no quedan tasas de una corrida anterior" \
  "SELECT CASE WHEN COUNT(*) = 0 THEN 'limpio' ELSE 'rehaga la base' END
     FROM tax_rates WHERE code LIKE 'X99A%';" "limpio"

echo ""
echo "-- La forma de una tasa --"

porque "una tasa del 100 %" \
  "INSERT INTO tax_rates (country_id,code,name,rate,valid_from,created_at)
   VALUES ($PE,'X99A','Impuesto de prueba',100,'2030-01-01',NOW(3));" \
  "ck_tax_rate|0 y 100"

porque "una tasa negativa" \
  "INSERT INTO tax_rates (country_id,code,name,rate,valid_from,created_at)
   VALUES ($PE,'X99A','Impuesto de prueba',-1,'2030-01-01',NOW(3));" \
  "ck_tax_rate|0 y 100"

porque "un codigo en minusculas" \
  "INSERT INTO tax_rates (country_id,code,name,rate,valid_from,created_at)
   VALUES ($PE,'x99a','Impuesto de prueba',18,'2030-01-01',NOW(3));" \
  "ck_tax_code|mayusculas"

porque "un nombre de dos letras" \
  "INSERT INTO tax_rates (country_id,code,name,rate,valid_from,created_at)
   VALUES ($PE,'X99A','xx',18,'2030-01-01',NOW(3));" \
  "ck_tax_nombre|leer"

porque "una vigencia que termina antes de empezar" \
  "INSERT INTO tax_rates (country_id,code,name,rate,valid_from,valid_to,created_at)
   VALUES ($PE,'X99A','Impuesto de prueba',18,'2030-06-01','2030-01-01',NOW(3));" \
  "ck_tax_dates|antes de empezar"

probar "y una tasa bien formada entra" \
  "INSERT INTO tax_rates (country_id,code,name,rate,valid_from,valid_to,created_at)
   VALUES ($PE,'X99A','Impuesto de prueba',18,'2030-01-01','2030-06-30',NOW(3));" OK

echo ""
echo "-- Una sola respuesta por fecha --"

porque "otra tasa del mismo impuesto que pisa esas fechas" \
  "INSERT INTO tax_rates (country_id,code,name,rate,valid_from,created_at)
   VALUES ($PE,'X99A','Impuesto de prueba',19,'2030-06-30',NOW(3));" \
  "tg_tax_sin_solape_ins|cierre la anterior"

probar "pero el dia siguiente al cierre, si" \
  "INSERT INTO tax_rates (country_id,code,name,rate,valid_from,created_at)
   VALUES ($PE,'X99A','Impuesto de prueba',19,'2030-07-01',NOW(3));" OK

# Y el mismo impuesto en OTRO pais no se pisa: la serie es (pais, codigo).
probar "el mismo codigo en otro pais no estorba" \
  "INSERT INTO tax_rates (country_id,code,name,rate,valid_from,created_at)
   VALUES ($CO,'X99A','Impuesto de prueba',19,'2030-01-01',NOW(3));" OK

# El lado que se olvida: mover una vigencia por UPDATE tambien puede pisar.
porque "y estirar una vigencia por encima de la siguiente" \
  "UPDATE tax_rates SET valid_to='2030-12-31'
    WHERE country_id=$PE AND code='X99A' AND valid_from='2030-01-01';" \
  "tg_tax_sin_solape_upd|cierre la anterior"

echo ""
echo "-- Y lo que no se puede deshacer --"

porque "borrar una tasa" \
  "DELETE FROM tax_rates WHERE code='X99A';" \
  "no se borra"

resumen
