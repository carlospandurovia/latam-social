#!/bin/bash
# Pruebas de restriccion de la iteracion 9.7: la conciliacion.
#
#   tg_payout_estado       el grafo de estados de un pago, y sus dos finales
#   ck_payout_conciliado   «confirmado» exige referencia, fecha valor, cuando y quien
#   ck_payout_devuelto     «devuelto» exige el motivo y quien lo registro
#   fk_payout_proof        el comprobante, si lo hay, es un archivo que existe
#
# `9.6` deja los pagos en `sent`, que quiere decir **«lo mandamos»**, no
# «llego». Entre las dos cosas esta el banco. Lo que faltaba no eran los estados
# --`payouts` los tiene desde la Fase 2-- sino el GRAFO: sin el, un pago saltaba
# de `pending` a `confirmed` sin haber salido nunca, y uno devuelto volvia a
# `sent` con un UPDATE, borrando que el banco lo habia rechazado.
#
# Uso: bash tools/pruebas/9.7-conciliacion.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.7 - La conciliacion"
echo "==================================================================================="

USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"
MON="(SELECT code FROM (SELECT code FROM currencies ORDER BY code LIMIT 1) m)"
SOC="(SELECT id FROM (SELECT id FROM legal_entities ORDER BY id LIMIT 1) s)"

# El medio de pago manda: `tg_payout_medio_valido` (3.8) exige que este
# verificado, que haya pasado el enfriamiento y que sea DEL creador al que se
# paga. Por eso el creador se saca del medio y no al reves.
MED="(SELECT id FROM (SELECT id FROM creator_payment_methods
        WHERE status='verified' AND eligible_from IS NOT NULL AND eligible_from <= NOW(3)
        ORDER BY id LIMIT 1) x)"
CRE="(SELECT creator_id FROM (SELECT creator_id FROM creator_payment_methods
        WHERE status='verified' AND eligible_from IS NOT NULL AND eligible_from <= NOW(3)
        ORDER BY id LIMIT 1) y)"

# La premisa se AFIRMA, no se cuenta: las suites anteriores dejan medios
# verificados suyos, asi que un numero exacto aqui seria un fallo que solo
# depende de quien corrio antes.
valor "hay un medio de pago verificado y elegible" \
  "SELECT CASE WHEN COUNT(*) > 0 THEN 'si' ELSE 'no' END FROM creator_payment_methods
    WHERE status='verified' AND eligible_from IS NOT NULL AND eligible_from <= NOW(3);" "si"

# Esta suite crea SU lote y SUS pagos, y no los limpia: `tg_payout_no_delete`
# prohibe borrar un pago que ya salio, que es justo lo que aqui se prueba. La
# base se rehace entera en cada pasada de `correr-todo.sh`, asi que el codigo
# fijo del lote no colisiona consigo mismo.
probar "un lote para conciliar" \
  "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,created_at)
   VALUES (UUID(),'LOTE-97',$SOC,$MON,'draft',$USR,NOW(3));" OK

LOTE="(SELECT id FROM (SELECT id FROM payout_batches WHERE code='LOTE-97') a)"

probar "y cuatro pagos dentro" \
  "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,
      account_masked_snapshot,amount,currency_code,status,created_at)
   VALUES (UUID(),$LOTE,$CRE,$MED,'Titular 97','****9701',101,$MON,'pending',NOW(3)),
          (UUID(),$LOTE,$CRE,$MED,'Titular 97','****9702',102,$MON,'pending',NOW(3)),
          (UUID(),$LOTE,$CRE,$MED,'Titular 97','****9703',103,$MON,'pending',NOW(3)),
          (UUID(),$LOTE,$CRE,$MED,'Titular 97','****9704',104,$MON,'pending',NOW(3));" OK

UNO="(SELECT id FROM (SELECT id FROM payouts WHERE account_masked_snapshot='****9701') b)"
DOS="(SELECT id FROM (SELECT id FROM payouts WHERE account_masked_snapshot='****9702') c)"
TRES="(SELECT id FROM (SELECT id FROM payouts WHERE account_masked_snapshot='****9703') d)"
CUATRO="(SELECT id FROM (SELECT id FROM payouts WHERE account_masked_snapshot='****9704') e)"

echo ""
echo "-- El grafo: no se confirma lo que no ha salido --"

# Se le dan TODOS los datos de la confirmacion a proposito. Si faltara alguno,
# el rechazo lo firmaria `ck_payout_conciliado` y esta asercion estaria verde
# sin haber preguntado nunca por el grafo.
porque "de pendiente a confirmado, con todos sus datos, no" \
  "UPDATE payouts SET status='confirmed', sent_at=NOW(3), bank_reference='REF-97-A',
      value_date=CURDATE(), confirmed_at=NOW(3), confirmed_by_user_id=$USR WHERE id=$UNO;" \
  "no existe en un pago"

porque "ni de pendiente a devuelto" \
  "UPDATE payouts SET status='returned', returned_at=NOW(3), return_reason='Cuenta cerrada.',
      returned_by_user_id=$USR WHERE id=$UNO;" "no existe en un pago"

probar "de pendiente a enviado, si" \
  "UPDATE payouts SET status='sent', sent_at=NOW(3) WHERE id=$UNO;" OK

echo ""
echo "-- Confirmar exige con que conciliar --"

porque "confirmar sin referencia ni fecha valor" \
  "UPDATE payouts SET status='confirmed', confirmed_at=NOW(3), confirmed_by_user_id=$USR
    WHERE id=$UNO;" "ck_payout_conciliado|exige referencia, fecha valor"

porque "confirmar con referencia pero sin fecha valor" \
  "UPDATE payouts SET status='confirmed', bank_reference='REF-97-B', confirmed_at=NOW(3),
      confirmed_by_user_id=$USR WHERE id=$UNO;" "ck_payout_conciliado|exige referencia, fecha valor"

porque "y confirmar sin decir quien lo concilio" \
  "UPDATE payouts SET status='confirmed', bank_reference='REF-97-B', value_date=CURDATE(),
      confirmed_at=NOW(3) WHERE id=$UNO;" "ck_payout_conciliado|exige referencia, fecha valor"

porque "el comprobante, si lo hay, es un archivo que existe" \
  "UPDATE payouts SET status='confirmed', bank_reference='REF-97-B', value_date=CURDATE(),
      confirmed_at=NOW(3), confirmed_by_user_id=$USR, proof_file_id=999999 WHERE id=$UNO;" \
  "fk_payout_proof|foreign key"

probar "con las cuatro cosas, se confirma" \
  "UPDATE payouts SET status='confirmed', bank_reference='REF-97-B', value_date=CURDATE(),
      confirmed_at=NOW(3), confirmed_by_user_id=$USR WHERE id=$UNO;" OK

echo ""
echo "-- Confirmado es final --"

porque "un pago confirmado no se devuelve" \
  "UPDATE payouts SET status='returned', returned_at=NOW(3), return_reason='Me equivoque.',
      returned_by_user_id=$USR WHERE id=$UNO;" "no existe en un pago"

porque "ni vuelve a enviado" \
  "UPDATE payouts SET status='sent' WHERE id=$UNO;" "no existe en un pago"

echo ""
echo "-- Devolver exige decir por que --"

probar "el segundo pago sale al banco" \
  "UPDATE payouts SET status='sent', sent_at=NOW(3) WHERE id=$DOS;" OK

porque "devolverlo sin motivo" \
  "UPDATE payouts SET status='returned', returned_at=NOW(3), returned_by_user_id=$USR
    WHERE id=$DOS;" "ck_payout_devuelto|exige el motivo y quien"

porque "y sin decir quien lo registro" \
  "UPDATE payouts SET status='returned', returned_at=NOW(3), return_reason='Cuenta cerrada.'
    WHERE id=$DOS;" "ck_payout_devuelto|exige el motivo y quien"

probar "con motivo y con firma, vuelve" \
  "UPDATE payouts SET status='returned', returned_at=NOW(3), return_reason='Cuenta cerrada.',
      returned_by_user_id=$USR WHERE id=$DOS;" OK

porque "y devuelto tambien es final: no se reintenta encima" \
  "UPDATE payouts SET status='sent', sent_at=NOW(3) WHERE id=$DOS;" "no existe en un pago"

echo ""
echo "-- Cancelar: solo antes de salir --"

probar "un pago pendiente se cancela" \
  "UPDATE payouts SET status='cancelled' WHERE id=$TRES;" OK

porque "pero uno cancelado no revive" \
  "UPDATE payouts SET status='sent', sent_at=NOW(3) WHERE id=$TRES;" "no existe en un pago"

probar "el cuarto pago sale al banco" \
  "UPDATE payouts SET status='sent', sent_at=NOW(3) WHERE id=$CUATRO;" OK

porque "y uno enviado ya no se cancela: o llego o volvio" \
  "UPDATE payouts SET status='cancelled' WHERE id=$CUATRO;" "no existe en un pago"

echo ""
echo "-- Lo que ya estaba, y sigue estando --"

porque "un pago ya enviado no se borra" \
  "DELETE FROM payouts WHERE id=$CUATRO;" "no se borra"

valor "el primero quedo confirmado, con su referencia" \
  "SELECT CONCAT(status,':',bank_reference) FROM payouts WHERE account_masked_snapshot='****9701';" \
  "confirmed:REF-97-B"

valor "el segundo quedo devuelto, con su motivo" \
  "SELECT CONCAT(status,':',return_reason) FROM payouts WHERE account_masked_snapshot='****9702';" \
  "returned:Cuenta cerrada."

resumen
