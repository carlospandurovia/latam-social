#!/bin/bash
# Pruebas de restriccion de la iteracion 9.18: la politica de precios y el neto.
#
#   ck_pp_tasa              al 100 % de retencion el bruto seria infinito
#   ck_pp_umbral            y un umbral del 100 % sobre el ingreso, tambien
#   ck_pp_base              costo o ingreso, no hay una tercera cosa
#   ck_pp_fechas            no termina antes de empezar
#   uq_pp_current           UNA sola politica vigente (columna puerta)
#   tg_pp_sin_solape_ins    la tabla ENTERA es una serie
#   tg_pp_sin_solape_upd    y tambien al editar, que es donde se olvida
#   tg_pp_inmutable         una politica cerrada no se reescribe
#   tg_pp_no_delete         y no se borra: explica como se pacto cada compromiso
#   ck_ccr_base             se pacta el costo o el neto del creador
#   ck_ccr_neto             el neto nunca pasa del costo
#   ck_ccr_neto_completo    si se pacta el neto, consta con que tasa
#   ck_ccr_neto_cuadra      y el motor rehace la resta
#
# La que mas importa es la ultima. `neto = bruto x (100 - tasa) / 100`: si
# alguien toca el bruto y no el neto --o al reves-- la fila queda diciendo dos
# cosas distintas, y el sitio donde se descubre es la conversacion con el creador
# que cobro de menos.
#
# Uso: bash tools/pruebas/9.18-politica.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.18 - La politica de precios y el neto pactado"
echo "==================================================================================="

# Se aparta la politica sembrada --si la hay-- y se restituye al final, igual
# que 9.17 hace con la marca por defecto. Sin esto, `uq_pp_current` haria fallar
# el primer INSERT por un motivo que no es el que se esta probando.
ANTES=$($CLIENTE $DB -sN -e "SELECT COALESCE(MAX(id),0) FROM pricing_policies WHERE current_gate = 1;" 2>/dev/null)
$CLIENTE $DB -e "UPDATE pricing_policies SET valid_to = '2019-12-31' WHERE current_gate = 1;" >/dev/null 2>&1

# Esta suite NO se puede limpiar del todo: `tg_pp_no_delete` impide borrar una
# politica publicada, igual que 3.12 impide borrar una version de los terminos.
# Es correcto --una politica es una decision con fecha-- y tiene una consecuencia
# que conviene decir en vez de descubrir: la suite necesita una base recien
# rehecha, que es lo que `correr-todo.sh` hace en cada pasada.
#
# Se comprueba antes de empezar. Sin esto, una segunda corrida sobre la misma
# base daba SEIS fallos que acusaban a seis reglas distintas cuando lo unico que
# pasaba es que las politicas de 2030 seguian ahi de la vez anterior.
valor "no quedan politicas de 2030 de una corrida anterior" \
  "SELECT CASE WHEN COUNT(*) = 0 THEN 'limpio' ELSE 'rehaga la base' END
     FROM pricing_policies WHERE valid_from BETWEEN '2030-01-01' AND '2030-12-31';" "limpio"

echo ""
echo "-- Los limites que evitan una division por cero --"

porque "una retencion del 100 %" \
  "INSERT INTO pricing_policies (uuid,withholding_rate,min_margin_pct,margin_basis,valid_from,created_at)
   VALUES (UUID(),100,20,'cost','2030-01-01',NOW(3));" \
  "ck_pp_tasa|0 a 99"

porque "y un umbral del 100 %" \
  "INSERT INTO pricing_policies (uuid,withholding_rate,min_margin_pct,margin_basis,valid_from,created_at)
   VALUES (UUID(),29.5,100,'cost','2030-01-01',NOW(3));" \
  "ck_pp_umbral|0 a 99"

porque "una base que no es ni el costo ni el ingreso" \
  "INSERT INTO pricing_policies (uuid,withholding_rate,min_margin_pct,margin_basis,valid_from,created_at)
   VALUES (UUID(),29.5,20,'beneficio','2030-01-01',NOW(3));" \
  "ck_pp_base|costo o el ingreso"

porque "una politica que termina antes de empezar" \
  "INSERT INTO pricing_policies (uuid,withholding_rate,min_margin_pct,margin_basis,valid_from,valid_to,created_at)
   VALUES (UUID(),29.5,20,'cost','2030-06-01','2030-01-01',NOW(3));" \
  "ck_pp_fechas|antes de empezar"

echo ""
echo "-- UNA sola vigente, y sin solape --"

probar "la politica de 2030 entra" \
  "INSERT INTO pricing_policies (uuid,withholding_rate,min_margin_pct,margin_basis,valid_from,created_at)
   VALUES (UUID(),29.5,20,'cost','2030-01-01',NOW(3));" OK

porque "una segunda politica abierta" \
  "INSERT INTO pricing_policies (uuid,withholding_rate,min_margin_pct,margin_basis,valid_from,created_at)
   VALUES (UUID(),18,25,'revenue','2030-07-01',NOW(3));" \
  "uq_pp_current|Duplicate|politica de precios en esas fechas"

# Cerrada la anterior, la siguiente entra. Y si se solapara por un dia, no.
probar "se cierra la de 2030 el 30 de junio" \
  "UPDATE pricing_policies SET valid_to='2030-06-30' WHERE valid_from='2030-01-01';" OK

porque "una que empieza el mismo dia en que termina la anterior" \
  "INSERT INTO pricing_policies (uuid,withholding_rate,min_margin_pct,margin_basis,valid_from,created_at)
   VALUES (UUID(),18,25,'revenue','2030-06-30',NOW(3));" \
  "tg_pp_sin_solape_ins|cierre la anterior el dia antes"

probar "y al dia siguiente si" \
  "INSERT INTO pricing_policies (uuid,withholding_rate,min_margin_pct,margin_basis,valid_from,created_at)
   VALUES (UUID(),18,25,'revenue','2030-07-01',NOW(3));" OK

echo ""
echo "-- Una politica cerrada no se reescribe ni se borra --"

porque "cambiarle la tasa a la cerrada" \
  "UPDATE pricing_policies SET withholding_rate=8 WHERE valid_from='2030-01-01';" \
  "tg_pp_inmutable|no se reescribe"

porque "y borrarla" \
  "DELETE FROM pricing_policies WHERE valid_from='2030-01-01';" \
  "tg_pp_no_delete|no admite borrado"

# El no-solape tambien al EDITAR, no solo al insertar. `tg_pp_inmutable` deja
# mover el `valid_to` de una cerrada --cerrar antes o despues es una correccion
# legitima-- y sin esta regla se podria alargar hasta pisar a la siguiente.
#
# Se nombra el disparador entero y no su prefijo: `verificar-cobertura-sql.py`
# busca el nombre literal, y con `tg_pp_sin_solape` a secas uno de los dos
# contaba como preguntado y el otro como mudo (la leccion de 9.17c).
porque "alargar la cerrada hasta pisar a la siguiente" \
  "UPDATE pricing_policies SET valid_to='2030-08-01' WHERE valid_from='2030-01-01';" \
  "tg_pp_sin_solape_upd|cierre la anterior el dia antes"

echo ""
echo "-- El neto pactado: la aritmetica la rehace el motor --"

# ### Esta suite se construye SU participacion, y no reusa la de nadie
#
# Se intentaron las dos que trae la semilla y las dos estan ACEPTADAS:
# `tg_ccr_monto_congelado` (BR-CREATOR-008) impide cambiarles el importe, y con
# razon --lo aceptado no se toca--. Reusarlas habria medido esa regla en vez de
# las de aqui. Y un INSERT sobre las parejas (campana, creador) que ya existen
# choca antes con `uq_ccr_campaign_creator`.
#
# Asi que la suite crea su campana, copiando de la sembrada los campos que no le
# importan --cliente, marca, fechas, sociedad-- para no depender de que ninguno
# de ellos siga valiendo manana. Es lo mismo que hace 9.14 y por lo mismo.
$CLIENTE $DB -e "INSERT INTO campaigns
   (uuid,code,name,client_organization_id,client_brand_id,currency_code,starts_on,ends_on,
    status,revenue_amount,is_gratis,creator_budget_amount,billing_legal_entity_id,created_at,updated_at)
   SELECT UUID(),'CMP-918','Campana de la suite 9.18',client_organization_id,client_brand_id,
          currency_code,starts_on,ends_on,'draft',0,0,999999,
          billing_legal_entity_id,NOW(3),NOW(3)
     FROM campaigns WHERE code <> 'CMP-918' ORDER BY id LIMIT 1;" 2>&1 | grep -i error

$CLIENTE $DB -e "INSERT INTO campaign_creators
   (uuid,campaign_id,creator_id,status,agreed_amount,currency_code,payee_type,created_at,updated_at)
   SELECT UUID(),(SELECT id FROM campaigns WHERE code='CMP-918'),
          (SELECT id FROM creators ORDER BY id LIMIT 1),'shortlisted',
          100,(SELECT currency_code FROM campaigns WHERE code='CMP-918'),'creator',NOW(3),NOW(3);" 2>&1 | grep -i error

PART=$($CLIENTE $DB -sN -e "SELECT COALESCE(MAX(cc.id),0) FROM campaign_creators cc
   JOIN campaigns c ON c.id = cc.campaign_id WHERE c.code='CMP-918';" 2>/dev/null)

valor "la suite tiene su campana y su participacion, sin aceptar" \
  "SELECT CASE WHEN $PART > 0 THEN 'si' ELSE 'no' END;" "si"

porque "una base que no es ni bruto ni neto" \
  "UPDATE campaign_creators SET agreed_basis='mixto' WHERE id=$PART;" \
  "ck_ccr_base|costo o es el neto"

porque "un neto mayor que el costo" \
  "UPDATE campaign_creators SET agreed_amount=100, agreed_basis='net',
      agreed_net_amount=200, withholding_rate_snapshot=29.5 WHERE id=$PART;" \
  "ck_ccr_neto|no puede pasar de lo que cuesta"

porque "un neto pactado sin decir con que tasa" \
  "UPDATE campaign_creators SET agreed_amount=141.8440, agreed_basis='net',
      agreed_net_amount=100, withholding_rate_snapshot=NULL WHERE id=$PART;" \
  "ck_ccr_neto_completo|con que retencion"

# LA asercion de la iteracion. 141,8440 x (100 - 29,5) / 100 = 99,99998, que
# redondeado son los 100 que el creador recibe. Un neto de 120 con ese mismo
# bruto y esa misma tasa no cuadra por veinte soles, y eso es exactamente lo que
# nadie miraria hasta que el creador cobrase de menos.
porque "un neto que no cuadra con su bruto y su tasa" \
  "UPDATE campaign_creators SET agreed_amount=141.8440, agreed_basis='net',
      agreed_net_amount=120, withholding_rate_snapshot=29.5 WHERE id=$PART;" \
  "ck_ccr_neto_cuadra|no cuadra con el costo"

probar "y el que si cuadra entra: 100 netos cuestan 141,8440" \
  "UPDATE campaign_creators SET agreed_amount=141.8440, agreed_basis='net',
      agreed_net_amount=100, withholding_rate_snapshot=29.5,
      min_margin_pct_snapshot=20, margin_basis_snapshot='cost' WHERE id=$PART;" OK

valor "la fila guarda las dos cifras y la tasa" \
  "SELECT CONCAT(agreed_net_amount,'/',agreed_amount,'/',withholding_rate_snapshot)
     FROM campaign_creators WHERE id=$PART;" \
  "100.0000/141.8440/29.5000"

# El ingreso minimo con el umbral congelado en la propia fila: 141,8440 x 1,20.
valor "el ingreso minimo con su umbral congelado es 170,21" \
  "SELECT ROUND(agreed_amount * (1 + min_margin_pct_snapshot/100), 2)
     FROM campaign_creators WHERE id=$PART;" "170.21"

# Y el mismo costo con la OTRA base --margen sobre el ingreso-- pide mas. Es la
# diferencia que el negocio tiene que ver antes de elegir, y por eso se afirma
# aqui y no solo en la pantalla.
valor "y sobre el ingreso, el mismo 20 % pide 177,31" \
  "SELECT ROUND(agreed_amount / (1 - min_margin_pct_snapshot/100), 2)
     FROM campaign_creators WHERE id=$PART;" "177.31"

echo ""
echo "-- Se limpia lo suyo --"

probar "la participacion de la suite se borra" \
  "DELETE FROM campaign_creators WHERE id=$PART;" OK

probar "y su campana tambien" \
  "DELETE FROM campaigns WHERE code='CMP-918';" OK

# Las politicas de 2030 NO se pueden borrar --tg_pp_no_delete-- asi que se
# cierran y se devuelve el sitio a la que estaba. Es lo mismo que hace el
# sistema de verdad: aqui no se limpia, se releva.
$CLIENTE $DB -e "UPDATE pricing_policies SET valid_to='2030-12-31' WHERE valid_from='2030-07-01';
                 UPDATE pricing_policies SET valid_to=NULL WHERE id=$ANTES;" >/dev/null 2>&1

valor "la politica que estaba vigente vuelve a estarlo" \
  "SELECT CASE WHEN $ANTES = 0 THEN 'ok'
      WHEN EXISTS (SELECT 1 FROM pricing_policies WHERE id=$ANTES AND current_gate=1)
      THEN 'ok' ELSE 'no' END;" "ok"

resumen
