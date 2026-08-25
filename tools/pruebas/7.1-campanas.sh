#!/bin/bash
# Pruebas de restriccion de la iteracion 7.1: quien factura una campana.
#
# Dos reglas, y las dos protegen el mismo dato:
#
#   ck_camp_billing_entity   una campana fuera de borrador DICE quien la factura
#   tg_camp_entidad_congelada   y una vez confirmada, ya no lo cambia
#
# Lo que hay debajo es `BR-LE-001`: *nunca se deduce de la configuracion vigente
# en el momento de la consulta*. Sin la columna, «quien facturo esta campana de
# 2026» se respondia mirando la cobertura de HOY, que para entonces puede ser
# otra sociedad: una respuesta plausible y falsa.
#
# Las dos se prueban PERMITIENDO ademas de rechazando. La asercion de que algo
# se permite es la que descubre que las de rechazo mentian --leccion repetida
# siete veces en este proyecto--.
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

ok=0; fail=0
probar() {
  salida=$($CLIENTE $DB -e "$2" 2>&1)
  if [ -z "$salida" ] || ! echo "$salida" | grep -qi "ERROR"; then real="OK"; else real="RECHAZO"; fi
  if [ "$real" == "$3" ]; then printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$real"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-70s esperaba %s, obtuvo %s\n" "$1" "$3" "$real"; echo "      $(echo "$salida"|grep -i error|head -1)"; fail=$((fail+1)); fi
}
valor() {
  real=$($CLIENTE $DB -N -B -e "$2" 2>&1 | grep -v '^mysql: \[Warning\]' | tr -d '\r')
  if [ "$real" == "$3" ]; then printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$real"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-70s esperaba '%s', obtuvo '%s'\n" "$1" "$3" "$real"; fail=$((fail+1)); fi
}

echo ""
echo "==================================================================================="
echo "  7.1 - Quien factura una campana (BR-LE-001, BR-LE-002)"
echo "==================================================================================="

# ------------------------------------------------------------------- premisas
CLI="(SELECT id FROM (SELECT id FROM client_organizations ORDER BY id LIMIT 1) t)"
MAR="(SELECT id FROM (SELECT id FROM client_brands ORDER BY id LIMIT 1) t)"
MON="(SELECT code FROM (SELECT code FROM currencies ORDER BY code LIMIT 1) t)"
E1="(SELECT id FROM (SELECT id FROM legal_entities ORDER BY id LIMIT 1) t)"
E2="(SELECT id FROM (SELECT id FROM legal_entities WHERE code='C71-SOC') t)"

$CLIENTE $DB -e "DELETE FROM campaigns WHERE code LIKE 'C71-%';" 2>/dev/null

# La suite se crea SU segunda sociedad, copiando la primera.
#
# Es la leccion de 4.5: aquella suite uso paises que la semilla no tiene, las
# columnas salieron NULL, y TRES aserciones de rechazo se pusieron verdes por el
# motivo equivocado. Depender de cuantas sociedades haya sembradas es el mismo
# error con otro nombre.
$CLIENTE $DB -e "INSERT INTO legal_entities
  (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,tax_id_number,
   address_line1,city,default_currency_code,timezone,status,created_at)
  SELECT UUID(),platform_brand_id,'C71-SOC','Sociedad de la suite 7.1',country_id,
         tax_id_type,'20719990001',address_line1,city,default_currency_code,timezone,
         'active',NOW(3)
    FROM legal_entities ORDER BY id LIMIT 1;" 2>/dev/null

valor "hay al menos DOS sociedades para poder probar el cambio" \
  "SELECT COUNT(*)>=2 FROM legal_entities;" "1"
valor "y ninguna campana de esta suite de una pasada anterior" \
  "SELECT COUNT(*) FROM campaigns WHERE code LIKE 'C71-%';" "0"

alta() {  # codigo, estado, entidad, confirmado
  echo "INSERT INTO campaigns (uuid,code,name,client_organization_id,client_brand_id,
    billing_legal_entity_id,currency_code,starts_on,ends_on,status,confirmed_at,created_at)
    VALUES (UUID(),'$1','Campana $1',$CLI,$MAR,$3,$MON,'2026-09-01','2026-09-30','$2',$4,NOW(3));"
}

echo ""
echo "--- Un borrador puede no saber todavia quien lo factura ---"
# Es el margen que permite empezar a teclear una campana de un pais sin
# cobertura. Lo que no puede es salir de ahi.
probar "borrador sin sociedad" "$(alta 'C71-A' 'draft' 'NULL' 'NULL')" OK
probar "en aprobacion sin sociedad tambien: sigue escribiendose" \
  "$(alta 'C71-B' 'pending_approval' 'NULL' 'NULL')" OK
probar "cancelada sin sociedad: nunca llego a comprometerse" \
  "$(alta 'C71-C' 'cancelled' 'NULL' 'NULL')" OK

echo ""
echo "--- Pero en cuanto se compromete, tiene que decirlo ---"
probar "aprobada SIN sociedad" "$(alta 'C71-D' 'approved' 'NULL' 'NOW(3)')" RECHAZO
probar "en curso SIN sociedad" "$(alta 'C71-E' 'in_progress' 'NULL' 'NOW(3)')" RECHAZO
probar "terminada SIN sociedad" "$(alta 'C71-F' 'completed' 'NULL' 'NOW(3)')" RECHAZO
probar "aprobada CON sociedad" "$(alta 'C71-G' 'approved' "$E1" 'NOW(3)')" OK

echo ""
echo "--- Y una vez confirmada, esa sociedad no se cambia (BR-LE-002) ---"
probar "cambiar la sociedad de una campana confirmada" \
  "UPDATE campaigns SET billing_legal_entity_id=$E2 WHERE code='C71-G';" RECHAZO
probar "borrarla tambien esta prohibido: <=> y no <>" \
  "UPDATE campaigns SET billing_legal_entity_id=NULL WHERE code='C71-G';" RECHAZO
probar "lo demas de una campana confirmada si se puede tocar" \
  "UPDATE campaigns SET name='Campana C71-G renombrada' WHERE code='C71-G';" OK
valor "y la sociedad sigue siendo la primera" \
  "SELECT billing_legal_entity_id=$E1 FROM campaigns WHERE code='C71-G';" "1"

echo ""
echo "--- Mientras es borrador, si se corrige ---"
# El margen para un dedazo. Sin esto, un error de captura obligaria a cancelar
# la campana y rehacerla, y el historico se llenaria de campanas `cancelled`
# que no se cancelaron por negocio.
probar "poner sociedad a un borrador" \
  "UPDATE campaigns SET billing_legal_entity_id=$E1 WHERE code='C71-A';" OK
probar "y cambiarsela por otra" \
  "UPDATE campaigns SET billing_legal_entity_id=$E2 WHERE code='C71-A';" OK
probar "y quitarsela" \
  "UPDATE campaigns SET billing_legal_entity_id=NULL WHERE code='C71-A';" OK

echo ""
echo "--- La foranea: la sociedad tiene que existir ---"
probar "una sociedad inventada" \
  "UPDATE campaigns SET billing_legal_entity_id=999999 WHERE code='C71-A';" RECHAZO

$CLIENTE $DB -e "DELETE FROM campaigns WHERE code LIKE 'C71-%';
  DELETE FROM legal_entities WHERE code='C71-SOC';" 2>/dev/null

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
