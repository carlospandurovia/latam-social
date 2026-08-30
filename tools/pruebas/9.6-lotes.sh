#!/bin/bash
# Pruebas de restriccion de la iteracion 9.6: los lotes de pago.
#
#   tg_pe_sociedad          la sociedad que paga es la de la CAMPANA (BR-LE-009 / DEC-157)
#   tg_pe_sociedad          y solo se liquida un devengo pagable
#   uq_pe_viva              un devengo, una liquidacion viva
#   tg_pe_inmutable         una liquidacion no se reescribe: se anula, con quien y por que
#   tg_pe_no_delete         y no se borra
#   ck_pbatch_segregation   quien arma un lote no lo firma (BR-FIN-005)
#
# La primera es la deuda que `DEC-157` mando pagar aqui: el roadmap la tenia en
# `9.11`, la undecima de catorce, o sea despues de que diez iteraciones se
# hubieran construido dandola por buena.
#
# Uso: bash tools/pruebas/9.6-lotes.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.6 - Los lotes de pago"
echo "==================================================================================="

USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"
OTRO="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1 OFFSET 1) u2)"
MON="(SELECT code FROM (SELECT code FROM currencies ORDER BY code LIMIT 1) m)"

# La suite REUSA un devengo que ya existe en vez de crear el suyo.
#
# Crearlo choca con `uq_ledger_devengo` --un devengo por participacion-- porque
# `2.13` y `9.3` corren antes y ya ocuparon las participaciones de la semilla.
# Buscar uno movible y moverlo prueba lo mismo y no depende de cuantas
# participaciones tenga la semilla ni de que hicieron las suites anteriores.
DEVID=$($CLIENTE $DB -N -B -e "SELECT le.id FROM ledger_entries le
    JOIN campaign_creators cc ON cc.id = le.campaign_creator_id
    JOIN campaigns c ON c.id = cc.campaign_id
   WHERE le.entry_type='earning' AND le.status IN ('accrued','on_hold','payable')
     AND c.billing_legal_entity_id IS NOT NULL
   ORDER BY le.id LIMIT 1" 2>/dev/null | tr -d '\r')

valor "hay un devengo movible de una campana con sociedad" \
  "SELECT CASE WHEN '${DEVID:-}' <> '' THEN 'si' ELSE 'no' END;" "si"

$CLIENTE $DB -e "UPDATE ledger_entries SET status='payable', status_changed_at=NOW(6),
   status_reason='Preparado para la suite 9.6.'
   WHERE id=${DEVID:-0} AND status <> 'payable';" 2>&1 | grep -i error

DEV="${DEVID:-0}"
SOC="(SELECT billing_legal_entity_id FROM (SELECT c.billing_legal_entity_id
        FROM ledger_entries le JOIN campaign_creators cc ON cc.id=le.campaign_creator_id
        JOIN campaigns c ON c.id=cc.campaign_id WHERE le.id=$DEV) s)"
CRE="(SELECT creator_id FROM (SELECT creator_id FROM ledger_entries WHERE id=$DEV) r)"
MED="(SELECT id FROM (SELECT id FROM creator_payment_methods ORDER BY id LIMIT 1) x)"
OTRA_SOC="(SELECT id FROM (SELECT id FROM legal_entities WHERE code='CTS-CO') y)"

valor "el devengo quedo pagable" \
  "SELECT status FROM ledger_entries WHERE id=${DEVID:-0};" "payable"
valor "y existe la segunda sociedad" \
  "SELECT COUNT(*) FROM legal_entities WHERE code='CTS-CO';" "1"

echo ""
echo "-- BR-LE-009: paga la sociedad de la campana --"

probar "un lote de la sociedad CORRECTA" \
  "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,created_at)
   VALUES (UUID(),'LOTE-96-OK',$SOC,$MON,'draft',$USR,NOW(3));" OK

probar "y otro de la sociedad EQUIVOCADA" \
  "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,created_at)
   VALUES (UUID(),'LOTE-96-MAL',$OTRA_SOC,$MON,'draft',$USR,NOW(3));" OK

BUENO="(SELECT id FROM (SELECT id FROM payout_batches WHERE code='LOTE-96-OK') a)"
MALO="(SELECT id FROM (SELECT id FROM payout_batches WHERE code='LOTE-96-MAL') b)"

probar "un pago en cada uno" \
  "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,
      account_masked_snapshot,amount,currency_code,status,created_at)
   VALUES (UUID(),$BUENO,$CRE,$MED,'Titular','****1234',100,$MON,'pending',NOW(3)),
          (UUID(),$MALO,$CRE,$MED,'Titular','****1234',100,$MON,'pending',NOW(3));" OK

P_BUENO="(SELECT id FROM (SELECT id FROM payouts WHERE payout_batch_id=$BUENO ORDER BY id LIMIT 1) c)"
P_MALO="(SELECT id FROM (SELECT id FROM payouts WHERE payout_batch_id=$MALO ORDER BY id LIMIT 1) d)"

porque "el lote de la OTRA sociedad no puede liquidar este devengo" \
  "INSERT INTO payout_earnings (payout_id,ledger_entry_id,amount,created_at)
   VALUES ($P_MALO,$DEV,100,NOW(3));" "sociedad que paga tiene que ser la de la campana"

probar "el de la sociedad correcta si" \
  "INSERT INTO payout_earnings (payout_id,ledger_entry_id,amount,created_at)
   VALUES ($P_BUENO,$DEV,100,NOW(3));" OK

porque "y ese devengo ya no entra en otra liquidacion viva" \
  "INSERT INTO payout_earnings (payout_id,ledger_entry_id,amount,created_at)
   VALUES ($P_BUENO,$DEV,100,NOW(3));" "uq_pe_viva"

echo ""
echo "-- La liquidacion no se reescribe --"

porque "cambiarle el importe, no" \
  "UPDATE payout_earnings SET amount=999 WHERE ledger_entry_id=$DEV;" "no se reescribe"

porque "anularla sin decir quien y por que, tampoco" \
  "UPDATE payout_earnings SET voided_at=NOW(3) WHERE ledger_entry_id=$DEV;" "exige quien lo saco y por que"

probar "con las dos cosas si se anula" \
  "UPDATE payout_earnings SET voided_at=NOW(3), voided_by_user_id=$USR,
      voided_reason='Se cayo el post y se retuvo el devengo.' WHERE ledger_entry_id=$DEV;" OK

probar "y el devengo vuelve a poder liquidarse" \
  "INSERT INTO payout_earnings (payout_id,ledger_entry_id,amount,created_at)
   VALUES ($P_BUENO,$DEV,100,NOW(3));" OK

porque "borrar una liquidacion, nunca" \
  "DELETE FROM payout_earnings WHERE ledger_entry_id=$DEV;" "no admite borrado"

echo ""
echo "-- BR-FIN-005: quien arma no firma --"

porque "el autor del lote no lo puede aprobar" \
  "UPDATE payout_batches SET status='approved', approved_by_user_id=$USR, approved_at=NOW(3)
    WHERE code='LOTE-96-OK';" "ck_pbatch_segregation|no puede aprobarlo"

probar "otra persona si" \
  "UPDATE payout_batches SET status='approved', approved_by_user_id=$OTRO, approved_at=NOW(3)
    WHERE code='LOTE-96-OK';" OK

resumen
