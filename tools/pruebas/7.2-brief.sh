#!/bin/bash
# Pruebas de restriccion de la iteracion 7.2: el ingreso declarado.
#
#   ck_camp_revenue_declarado   una campana fuera de borrador dice si el cero es a proposito
#
# El mismo numero, dos significados. `revenue_amount` nace con DEFAULT 0, asi que
# hasta 7.2 una campana REGALADA --canje, cortesia, prueba-- y una campana a la
# que nadie le puso precio eran indistinguibles en la fila. Ante un margen
# descuadrado, la diferencia entre las dos es la diferencia entre «salio como se
# planeo» y «se nos escapo».
#
# La otra mitad de `BR-CAMPAIGN-004` --que el brief diga QUE hay que entregar--
# no esta aqui: vive en `BriefTest` porque exige contar filas de otra tabla, y un
# `CHECK` no puede mirar `campaign_requirements`. Queda anotado: la base protege
# el significado del cero, la aplicacion protege que haya algo que entregar.
#
# Se prueba PERMITIENDO ademas de rechazando. Sin las aserciones de OK, un
# `CHECK` mal escrito que rechazara TODO saldria verde entero.
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  7.2 - El ingreso declarado (BR-CAMPAIGN-004)"
echo "==================================================================================="

CLI="(SELECT id FROM (SELECT id FROM client_organizations ORDER BY id LIMIT 1) t)"
MAR="(SELECT id FROM (SELECT id FROM client_brands ORDER BY id LIMIT 1) t)"
MON="(SELECT code FROM (SELECT code FROM currencies ORDER BY code LIMIT 1) t)"
ENT="(SELECT id FROM (SELECT id FROM legal_entities ORDER BY id LIMIT 1) t)"

$CLIENTE $DB -e "DELETE FROM campaigns WHERE code LIKE 'C72-%';" 2>/dev/null

# Las premisas. Sin sociedad sembrada, TODA campana fuera de borrador se
# rechazaria por `ck_camp_billing_entity` y esta suite mediria la restriccion de
# 7.1 creyendo que mide la de 7.2. Es el fallo de 4.5, que costo tres aserciones
# verdes por el motivo equivocado.
valor "hay una sociedad para poder salir de borrador" \
  "SELECT COUNT(*)>0 FROM legal_entities;" "1"
valor "y ninguna campana de esta suite de una pasada anterior" \
  "SELECT COUNT(*) FROM campaigns WHERE code LIKE 'C72-%';" "0"
valor "la columna is_gratis existe y no admite NULL" \
  "SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='campaigns' AND COLUMN_NAME='is_gratis';" "NO"

alta() {  # codigo, estado, importe, gratis, entidad, confirmado
  echo "INSERT INTO campaigns (uuid,code,name,client_organization_id,client_brand_id,
    billing_legal_entity_id,currency_code,revenue_amount,is_gratis,starts_on,ends_on,
    status,confirmed_at,created_at)
    VALUES (UUID(),'$1','Campana $1',$CLI,$MAR,$5,$MON,$3,$4,'2026-09-01','2026-09-30',
            '$2',$6,NOW(3));"
}

echo ""
echo "--- Un borrador tiene derecho a estar a medias ---"
# Esto es lo que la PRIMERA version de la migracion se cargaba: `revenue_amount`
# y `is_gratis` nacen los dos a cero, asi que la regla sin recortar rechazaba
# cualquier campana recien creada. El formulario vacio violaba la regla antes de
# que nadie pudiera teclear el precio.
probar "borrador con 0 y sin declarar" "$(alta 'C72-A' 'draft' 0 0 'NULL' 'NULL')" OK
probar "en aprobacion con 0 y sin declarar: sigue escribiendose" \
  "$(alta 'C72-B' 'pending_approval' 0 0 'NULL' 'NULL')" OK
probar "cancelada con 0 y sin declarar: nunca llego a comprometerse" \
  "$(alta 'C72-C' 'cancelled' 0 0 'NULL' 'NULL')" OK

echo ""
echo "--- Fuera de borrador, el cero hay que explicarlo ---"
probar "aprobada con 0 SIN declarar" \
  "$(alta 'C72-D' 'approved' 0 0 "$ENT" 'NOW(3)')" RECHAZO
probar "en curso con 0 SIN declarar" \
  "$(alta 'C72-E' 'in_progress' 0 0 "$ENT" 'NOW(3)')" RECHAZO
probar "terminada con 0 SIN declarar" \
  "$(alta 'C72-F' 'completed' 0 0 "$ENT" 'NOW(3)')" RECHAZO

echo ""
echo "--- Y las dos formas de explicarlo se aceptan ---"
probar "aprobada con importe" \
  "$(alta 'C72-G' 'approved' 15000.00 0 "$ENT" 'NOW(3)')" OK
probar "aprobada con 0 DECLARADO gratuito (canje, cortesia)" \
  "$(alta 'C72-H' 'approved' 0 1 "$ENT" 'NOW(3)')" OK

echo ""
echo "--- Las dos a la vez es una contradiccion ---"
# Gratuita con importe no es «gratis con matices»: es que alguien marco la
# casilla sin borrar el numero, y de esa fila sale un margen que no cuadra con
# ninguna de las dos lecturas.
probar "gratuita CON importe" \
  "$(alta 'C72-I' 'approved' 5000.00 1 "$ENT" 'NOW(3)')" RECHAZO
probar "gratuita con importe negativo" \
  "$(alta 'C72-J' 'approved' -1 1 "$ENT" 'NOW(3)')" RECHAZO

echo ""
echo "--- Ni por UPDATE: salir de borrador es cuando se comprueba ---"
# La comprobacion no es «al insertar»: es «en cualquier fila que quede fuera de
# borrador». Un `UPDATE` que mueve el estado es la via normal de llegar ahi, y
# es tambien la que se saltaria una regla que solo viviera en la pantalla.
probar "mover un borrador de 0 sin declarar a aprobada" \
  "UPDATE campaigns SET status='approved', confirmed_at=NOW(3),
     billing_legal_entity_id=$ENT WHERE code='C72-A';" RECHAZO
valor "y sigue en borrador" \
  "SELECT status FROM campaigns WHERE code='C72-A';" "draft"
probar "declarandolo gratuito primero, si se mueve" \
  "UPDATE campaigns SET is_gratis=1, status='approved', confirmed_at=NOW(3),
     billing_legal_entity_id=$ENT WHERE code='C72-A';" OK
valor "ahora si esta aprobada" \
  "SELECT status FROM campaigns WHERE code='C72-A';" "approved"

echo ""
echo "--- Y una campana ya aprobada no se queda sin explicacion por detras ---"
probar "quitarle la marca de gratuita a una aprobada de 0" \
  "UPDATE campaigns SET is_gratis=0 WHERE code='C72-H';" RECHAZO
probar "ponerle 0 a una aprobada con importe" \
  "UPDATE campaigns SET revenue_amount=0 WHERE code='C72-G';" RECHAZO
probar "cambiarle el importe por otro importe si" \
  "UPDATE campaigns SET revenue_amount=20000.00 WHERE code='C72-G';" OK

echo ""
echo "--- ck_camp_revenue sigue en pie: no la sustituye, la completa ---"
probar "importe negativo en un borrador" \
  "$(alta 'C72-K' 'draft' -5 0 'NULL' 'NULL')" RECHAZO

$CLIENTE $DB -e "DELETE FROM campaigns WHERE code LIKE 'C72-%';" 2>/dev/null

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
