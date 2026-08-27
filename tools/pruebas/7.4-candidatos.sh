#!/bin/bash
# Pruebas de restriccion de la iteracion 7.4: la lista corta.
#
#   tg_ccr_campana_cerrada_ins / _upd   nadie entra en una campana cerrada
#   uq_ccr_campaign_creator             el mismo creador una sola vez por campana
#   fk_ccr_market_campaign              el mercado es DE la campana (compuesta, 7.3)
#
# `campaign_creators` existe desde la Fase 2 y hasta 7.4 nadie habia escrito una
# fila. En cuanto se escribe la primera aparece el hueco: nada impedia meter un
# creador en una campana `completed`. No es cosmetico --esa fila devenga en el
# ledger (9.3), sale en el reporte del cliente (10.4) y cuenta en el Creator
# Score (14.3)-- y las tres son consecuencias caras de una fila barata.
#
# Se prueba PERMITIENDO ademas de rechazando: sin las aserciones de OK, un
# disparador que rechazara todo saldria verde entero.
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

# Un RECHAZO solo prueba algo si rechaza por SU motivo. Un `SIGNAL` de mas de
# 128 caracteres se convierte en MySQL/Percona en `1648 Data too long for
# condition item`, que tambien es un error, y con `probar ... RECHAZO` sale
# verde igual. Cuatro mensajes llevaban rotos asi desde 7.4. Gate permanente:
# `tools/verificar-mensajes.py`; la leccion, aqui.
porque() {
  salida=$($CLIENTE $DB -e "$2" 2>&1)
  if echo "$salida" | grep -q "$3"; then printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$3"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-70s esperaba rechazo por '''%s'''\n" "$1" "$3"
       echo "      $(echo "$salida"|grep -i error|head -1)"; fail=$((fail+1)); fi
}

echo ""
echo "==================================================================================="
echo "  7.4 - La lista corta de la campana (BR-CREATOR-008)"
echo "==================================================================================="

CLI="(SELECT id FROM (SELECT id FROM client_organizations ORDER BY id LIMIT 1) t)"
MAR="(SELECT id FROM (SELECT id FROM client_brands ORDER BY id LIMIT 1) t)"
MON="(SELECT code FROM (SELECT code FROM currencies ORDER BY code LIMIT 1) t)"
ENT="(SELECT id FROM (SELECT id FROM legal_entities ORDER BY id LIMIT 1) t)"
CR1="(SELECT id FROM (SELECT id FROM creators ORDER BY id LIMIT 1) t)"
CR2="(SELECT id FROM (SELECT id FROM creators ORDER BY id LIMIT 1 OFFSET 1) t)"
P1="(SELECT id FROM (SELECT id FROM countries ORDER BY id LIMIT 1) t)"
P2="(SELECT id FROM (SELECT id FROM countries ORDER BY id LIMIT 1 OFFSET 1) t)"

$CLIENTE $DB -e "DELETE FROM campaign_creators WHERE campaign_id IN
   (SELECT id FROM campaigns WHERE code LIKE 'C74-%');
  DELETE FROM campaign_markets WHERE campaign_id IN
   (SELECT id FROM campaigns WHERE code LIKE 'C74-%');
  DELETE FROM campaigns WHERE code LIKE 'C74-%';" 2>/dev/null

# Premisas. DOS creadores sembrados: media suite necesita distinguir «el mismo
# otra vez» de «otro distinto», y con uno solo esas aserciones saldrian verdes
# sin distinguir nada.
valor "hay al menos DOS creadores sembrados" "SELECT COUNT(*)>=2 FROM creators;" "1"
valor "y al menos una sociedad para poder cerrar una campana" "SELECT COUNT(*)>0 FROM legal_entities;" "1"
valor "y ninguna campana de esta suite de una pasada anterior" \
  "SELECT COUNT(*) FROM campaigns WHERE code LIKE 'C74-%';" "0"

alta() {  # codigo, estado, confirmado, cerrado
  echo "INSERT INTO campaigns (uuid,code,name,client_organization_id,client_brand_id,
    billing_legal_entity_id,currency_code,revenue_amount,is_gratis,starts_on,ends_on,
    status,confirmed_at,closed_at,created_at)
    VALUES (UUID(),'$1','Campana $1',$CLI,$MAR,$ENT,$MON,5000.00,0,'2026-09-01','2026-09-30',
            '$2',$3,$4,NOW(3));"
}
CV="(SELECT id FROM campaigns WHERE code='C74-VIVA')"
CC="(SELECT id FROM campaigns WHERE code='C74-CERR')"
MV="(SELECT id FROM (SELECT id FROM campaign_markets WHERE campaign_id=$CV ORDER BY id LIMIT 1) t)"
MC="(SELECT id FROM (SELECT id FROM campaign_markets WHERE campaign_id=$CC ORDER BY id LIMIT 1) t)"

$CLIENTE $DB -e "$(alta 'C74-VIVA' 'in_progress' 'NOW(3)' 'NULL')" 2>/dev/null
$CLIENTE $DB -e "$(alta 'C74-CERR' 'completed' 'NOW(3)' 'NOW(3)')" 2>/dev/null
$CLIENTE $DB -e "INSERT INTO campaign_markets (campaign_id,country_id,created_at) VALUES ($CV,$P1,NOW(3));" 2>/dev/null
$CLIENTE $DB -e "INSERT INTO campaign_markets (campaign_id,country_id,created_at) VALUES ($CC,$P2,NOW(3));" 2>/dev/null
valor "las dos campanas de la suite existen" \
  "SELECT COUNT(*) FROM campaigns WHERE code LIKE 'C74-%';" "2"

meter() {  # campana, creador, mercado, estado
  echo "INSERT INTO campaign_creators
    (uuid,campaign_id,creator_id,campaign_market_id,status,agreed_amount,currency_code,created_at)
    VALUES (UUID(),$1,$2,$3,'$4',0,$MON,NOW(3));"
}

echo ""
echo "--- En una campana viva se entra ---"
probar "un candidato, con su mercado" "$(meter "$CV" "$CR1" "$MV" 'shortlisted')" OK
probar "otro creador distinto en la misma campana" "$(meter "$CV" "$CR2" "$MV" 'shortlisted')" OK

echo ""
echo "--- El mismo creador una sola vez por campana ---"
probar "repetir el creador" "$(meter "$CV" "$CR1" "$MV" 'shortlisted')" RECHAZO
valor "siguen siendo dos" \
  "SELECT COUNT(*) FROM campaign_creators WHERE campaign_id=$CV;" "2"

echo ""
echo "--- El mercado tiene que ser de ESA campana (foranea compuesta de 7.3) ---"
probar "colgarlo del mercado de la otra campana" "$(meter "$CV" "$CR1" "$MC" 'shortlisted')" RECHAZO

# Y sin mercado si: en 7.4 el mercado se DERIVA del pais, pero la foranea es
# compuesta y en MySQL una foranea compuesta con un componente NULL no se
# comprueba --el mismo NULL con significado de `N-03`--. Hay que quitar antes la
# fila que ya existe: `uq_ccr_campaign_creator` es un creador por campana, y sin
# esto la asercion salia RECHAZO por el motivo equivocado.
$CLIENTE $DB -e "DELETE FROM campaign_creators WHERE campaign_id=$CV AND creator_id=$CR1;" 2>/dev/null
probar "sin mercado (NULL) si entra" "$(meter "$CV" "$CR1" 'NULL' 'shortlisted')" OK
probar "y se le puede poner el mercado despues" \
  "UPDATE campaign_creators SET campaign_market_id=$MV WHERE campaign_id=$CV AND creator_id=$CR1;" OK

echo ""
echo "--- En una campana CERRADA no entra nadie ---"
porque "meter un candidato en la campana cerrada" "$(meter "$CC" "$CR1" "$MC" 'shortlisted')" "campana cerrada"
valor "y la campana cerrada sigue vacia" \
  "SELECT COUNT(*) FROM campaign_creators WHERE campaign_id=$CC;" "0"

echo ""
echo "--- Ni se avanza una participacion que ya estaba, si la campana se cierra ---"
# Se mete con la campana VIVA, se cierra la campana, y despues se intenta mover.
# Sin el BEFORE UPDATE, un INSERT legitimo de ayer se podria mover hoy a
# `accepted` en una campana que se cerro en medio.
probar "cerrar la campana viva" \
  "UPDATE campaigns SET status='completed', closed_at=NOW(3) WHERE code='C74-VIVA';" OK
probar "avanzar a invited una participacion de la campana ya cerrada" \
  "UPDATE campaign_creators SET status='invited', invited_at=NOW(3)
     WHERE campaign_id=$CV AND creator_id=$CR1;" RECHAZO
probar "pero CANCELARLA si se puede: hay que poder resolverlas" \
  "UPDATE campaign_creators SET status='cancelled'
     WHERE campaign_id=$CV AND creator_id=$CR1;" OK
valor "y quedo cancelada" \
  "SELECT status FROM campaign_creators WHERE campaign_id=$CV AND creator_id=$CR1;" "cancelled"
# Tocar otra columna sin mover el estado tampoco se bloquea: el disparador mira
# el CAMBIO de estado, no cualquier UPDATE.
probar "corregir el mercado de una participacion sin mover su estado" \
  "UPDATE campaign_creators SET campaign_market_id=$MV
     WHERE campaign_id=$CV AND creator_id=$CR2;" OK

echo ""
echo "--- ck_ccr_amount y ck_ccr_status siguen en pie (Fase 2) ---"
probar "un importe negativo" \
  "UPDATE campaign_creators SET agreed_amount=-1 WHERE campaign_id=$CV LIMIT 1;" RECHAZO
probar "un estado inventado" \
  "UPDATE campaign_creators SET status='pensandolo' WHERE campaign_id=$CV LIMIT 1;" RECHAZO

$CLIENTE $DB -e "DELETE FROM campaign_creators WHERE campaign_id IN
   (SELECT id FROM (SELECT id FROM campaigns WHERE code LIKE 'C74-%') t);
  DELETE FROM campaign_markets WHERE campaign_id IN
   (SELECT id FROM (SELECT id FROM campaigns WHERE code LIKE 'C74-%') t);
  UPDATE campaigns SET confirmed_at=NULL, closed_at=NULL, status='draft' WHERE code LIKE 'C74-%';
  DELETE FROM campaigns WHERE code LIKE 'C74-%';" 2>/dev/null

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
