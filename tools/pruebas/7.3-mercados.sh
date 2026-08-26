#!/bin/bash
# Pruebas de restriccion de la iteracion 7.3: los mercados de la campana.
#
#   uq_cm_id_campaign + fk_creq_market_campaign   el mercado es DE la campana
#   ck_cm_target                                  cero creadores no es un objetivo
#   tg_cm_no_quitar_confirmada                    de una confirmada se anade, no se quita
#   ck_creq_deadline / ck_creq_permanence         los plazos del brief, acotados (T-33)
#
# La foranea COMPUESTA es lo que justifica la iteracion. Una foranea a
# `campaign_markets(id)` a secas solo comprueba que el mercado exista: nada
# impedia un requisito de la campana A colgado del mercado de la campana B, y
# con el un brief que se resolvia contra el pais equivocado.
#
# Y hay que probar las DOS caras: que el mercado ajeno se rechaza, y que
# `campaign_market_id IS NULL` --«todos los mercados», la excepcion consciente
# de 2.3 §9-- sigue entrando. Sin la segunda, la primera saldria verde aunque la
# foranea compuesta hubiera roto el caso mas comun de todos.
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
echo "  7.3 - Los mercados de la campana (N-03, BR-CAMPAIGN-003, T-33)"
echo "==================================================================================="

CLI="(SELECT id FROM (SELECT id FROM client_organizations ORDER BY id LIMIT 1) t)"
MAR="(SELECT id FROM (SELECT id FROM client_brands ORDER BY id LIMIT 1) t)"
MON="(SELECT code FROM (SELECT code FROM currencies ORDER BY code LIMIT 1) t)"
ENT="(SELECT id FROM (SELECT id FROM legal_entities ORDER BY id LIMIT 1) t)"
FMT="(SELECT id FROM (SELECT id FROM content_formats ORDER BY id LIMIT 1) t)"
P1="(SELECT id FROM (SELECT id FROM countries ORDER BY id LIMIT 1) t)"
P2="(SELECT id FROM (SELECT id FROM countries ORDER BY id LIMIT 1 OFFSET 1) t)"

$CLIENTE $DB -e "DELETE FROM campaign_requirements WHERE campaign_id IN
   (SELECT id FROM campaigns WHERE code LIKE 'C73-%');
  DELETE FROM campaign_markets WHERE campaign_id IN
   (SELECT id FROM campaigns WHERE code LIKE 'C73-%');
  DELETE FROM campaigns WHERE code LIKE 'C73-%';" 2>/dev/null

# Premisas. DOS paises y un formato: media suite compara un pais contra otro, y
# con uno solo en el catalogo esas aserciones saldrian verdes sin comparar nada.
# Es el fallo de 4.5, que costo tres aserciones verdes por el motivo equivocado.
valor "hay al menos DOS paises en el catalogo" "SELECT COUNT(*)>=2 FROM countries;" "1"
valor "y al menos un formato de contenido" "SELECT COUNT(*)>0 FROM content_formats;" "1"
valor "y ninguna campana de esta suite de una pasada anterior" \
  "SELECT COUNT(*) FROM campaigns WHERE code LIKE 'C73-%';" "0"

alta() {  # codigo, estado, confirmado
  echo "INSERT INTO campaigns (uuid,code,name,client_organization_id,client_brand_id,
    billing_legal_entity_id,currency_code,revenue_amount,is_gratis,starts_on,ends_on,
    status,confirmed_at,created_at)
    VALUES (UUID(),'$1','Campana $1',$CLI,$MAR,$ENT,$MON,1000.00,0,'2026-09-01','2026-09-30',
            '$2',$3,NOW(3));"
}
CA="(SELECT id FROM campaigns WHERE code='C73-A')"
CB="(SELECT id FROM campaigns WHERE code='C73-B')"
# Los dos van envueltos en una tabla derivada `(SELECT ... FROM (...) t)`.
#
# MySQL 8 rechaza con un **1093** --«you can't specify target table for update in
# FROM clause»-- cualquier DELETE o UPDATE cuyo subconsulta lea la MISMA tabla;
# MariaDB lo permite. Sin envolverlos, dos aserciones de esta suite salian
# RECHAZO en MySQL 8 y una de ellas ERA la que esperaba rechazo: verde por el
# motivo equivocado, otra vez, y en el motor que menos se mira.
MA="(SELECT id FROM (SELECT id FROM campaign_markets WHERE campaign_id=$CA ORDER BY id LIMIT 1) t)"
MB="(SELECT id FROM (SELECT id FROM campaign_markets WHERE campaign_id=$CB ORDER BY id LIMIT 1) t)"

$CLIENTE $DB -e "$(alta 'C73-A' 'draft' 'NULL')" 2>/dev/null
$CLIENTE $DB -e "$(alta 'C73-B' 'approved' 'NOW(3)')" 2>/dev/null
valor "las dos campanas de la suite existen" \
  "SELECT COUNT(*) FROM campaigns WHERE code LIKE 'C73-%';" "2"

echo ""
echo "--- El cupo de creadores: sin fijar si, cero no ---"
probar "mercado sin cupo (NULL = sin fijar)" \
  "INSERT INTO campaign_markets (campaign_id,country_id,target_creators,created_at)
     VALUES ($CA,$P1,NULL,NOW(3));" OK
probar "mercado con cupo de 5" \
  "INSERT INTO campaign_markets (campaign_id,country_id,target_creators,created_at)
     VALUES ($CA,$P2,5,NOW(3));" OK
probar "mercado con cupo CERO" \
  "INSERT INTO campaign_markets (campaign_id,country_id,target_creators,created_at)
     SELECT $CA,id,0,NOW(3) FROM countries ORDER BY id LIMIT 1 OFFSET 2;" RECHAZO
probar "y bajar a cero uno que ya existe" \
  "UPDATE campaign_markets SET target_creators=0 WHERE campaign_id=$CA AND country_id=$P2;" RECHAZO
probar "subirlo a otro numero si" \
  "UPDATE campaign_markets SET target_creators=12 WHERE campaign_id=$CA AND country_id=$P2;" OK

echo ""
echo "--- El mismo pais dos veces en la misma campana ---"
probar "repetir el pais" \
  "INSERT INTO campaign_markets (campaign_id,country_id,created_at) VALUES ($CA,$P1,NOW(3));" RECHAZO
probar "el mismo pais en OTRA campana si: son campanas distintas" \
  "INSERT INTO campaign_markets (campaign_id,country_id,created_at) VALUES ($CB,$P1,NOW(3));" OK

echo ""
echo "--- La foranea COMPUESTA: el mercado tiene que ser de ESTA campana ---"
req() {  # campana, mercado, formato
  echo "INSERT INTO campaign_requirements
    (campaign_id,campaign_market_id,content_format_id,quantity,deadline_offset_days,permanence_days,created_at)
    VALUES ($1,$2,$3,1,7,30,NOW(3));"
}
probar "requisito general (campaign_market_id NULL = todos los mercados)" \
  "$(req "$CA" 'NULL' "$FMT")" OK
probar "requisito del mercado propio" \
  "$(req "$CA" "$MA" "$FMT")" OK
probar "requisito colgado del mercado de OTRA campana" \
  "$(req "$CA" "$MB" "$FMT")" RECHAZO
probar "y mover un mercado de campana tampoco" \
  "UPDATE campaign_markets SET campaign_id=$CB WHERE id=$MA;" RECHAZO
valor "el mercado sigue siendo de su campana" \
  "SELECT campaign_id=$CA FROM campaign_markets WHERE id=$MA;" "1"

echo ""
echo "--- T-33: los plazos del brief, acotados en la BASE ---"
probar "permanencia de 100.000 dias (273 anos)" \
  "UPDATE campaign_requirements SET permanence_days=100000 WHERE campaign_id=$CA LIMIT 1;" RECHAZO
probar "plazo de entrega de 400 dias" \
  "UPDATE campaign_requirements SET deadline_offset_days=400 WHERE campaign_id=$CA LIMIT 1;" RECHAZO
probar "los topes exactos si entran: 365 y 3650" \
  "UPDATE campaign_requirements SET deadline_offset_days=365, permanence_days=3650 WHERE campaign_id=$CA LIMIT 1;" OK
probar "cantidad cero sigue rechazada (ck_creq_quantity, Fase 2)" \
  "UPDATE campaign_requirements SET quantity=0 WHERE campaign_id=$CA LIMIT 1;" RECHAZO

echo ""
echo "--- De una campana confirmada se ANADE un mercado, no se quita ---"
probar "anadir un mercado a la campana confirmada" \
  "INSERT INTO campaign_markets (campaign_id,country_id,created_at) VALUES ($CB,$P2,NOW(3));" OK
probar "quitarle uno" \
  "DELETE FROM campaign_markets WHERE id=$MB;" RECHAZO
valor "y sigue ahi" \
  "SELECT COUNT(*) FROM campaign_markets WHERE campaign_id=$CB;" "2"

echo ""
echo "--- Mientras es borrador, un mercado VACIO si se quita ---"
# El margen para un dedazo. Con requisitos dentro no, y eso lo impide la
# foranea RESTRICT: la pantalla lo dice antes para no traducir un 1451.
probar "quitar un mercado del borrador que tiene requisitos" \
  "DELETE FROM campaign_markets WHERE id=$MA;" RECHAZO
probar "vaciarlo primero" \
  "DELETE FROM campaign_requirements WHERE campaign_market_id=$MA;" OK
probar "y ahora si" \
  "DELETE FROM campaign_markets WHERE id=$MA;" OK

$CLIENTE $DB -e "DELETE FROM campaign_requirements WHERE campaign_id IN
   (SELECT id FROM campaigns WHERE code LIKE 'C73-%');
  DELETE FROM campaign_markets WHERE campaign_id IN
   (SELECT id FROM campaigns WHERE code LIKE 'C73-%');
  UPDATE campaigns SET confirmed_at=NULL, status='draft' WHERE code LIKE 'C73-%';
  DELETE FROM campaigns WHERE code LIKE 'C73-%';" 2>/dev/null

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
