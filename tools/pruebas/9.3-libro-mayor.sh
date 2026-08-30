#!/bin/bash
# Pruebas de restriccion de la iteracion 9.3: el libro mayor.
#
#   uq_ledger_devengo        UN devengo por participacion, y un anulado libera el sitio
#   tg_ledger_estado         solo las transiciones que existen; pagado y anulado no vuelven
#   ck_ledger_status_firma   mover un asiento exige decir por que
#   ck_ledger_estado_inicial un asiento nace devengado o pagado; a pagable se LLEGA
#   tg_ledger_no_update      lo demas del asiento sigue siendo inmutable (Fase 2)
#   tg_ledger_no_delete      y no se borra
#
# Toda esta suite escribe a MANO. `Ledger` no deja pasar nada de esto, y esa es
# la razon: lo que solo protege el servicio no protege al proximo que escriba --y
# `9.4` va a devengar desde un listener y `9.6` desde el lote de pago--.
#
# Uso: bash tools/pruebas/9.3-libro-mayor.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.3 - El libro mayor"
echo "==================================================================================="

CRE="(SELECT id FROM (SELECT id FROM creators ORDER BY id LIMIT 1) c)"
MON="(SELECT code FROM (SELECT code FROM currencies ORDER BY code LIMIT 1) m)"

# Se eligen participaciones SIN devengo, y se fijan en una variable de shell.
#
# `ledger_entries` no se puede limpiar --entro en la lista de 3.12 desde el
# principio-- y la suite de 2.13 corre antes y deja asientos suyos. Meterlo en la
# consulta en vez de en una variable tampoco vale: despues del primer INSERT esa
# participacion deja de estar libre, la subconsulta elegiria OTRA, y la asercion
# de «no se devenga dos veces» se pondria verde por el motivo equivocado.
LIBRES="SELECT cc.id FROM campaign_creators cc
          LEFT JOIN ledger_entries le
            ON le.campaign_creator_id = cc.id AND le.entry_type = 'earning' AND le.status <> 'void'
         WHERE le.id IS NULL ORDER BY cc.id"
PART=$($CLIENTE $DB -N -B -e "$LIBRES LIMIT 1" 2>/dev/null | tr -d '\r')

valor "hay una participacion sin devengar" \
  "SELECT CASE WHEN '${PART:-}' <> '' THEN 'si' ELSE 'no' END;" "si"

echo ""
echo "-- Un devengo por participacion --"

probar "el devengo entra" \
  "INSERT INTO ledger_entries
     (uuid,creator_id,entry_type,amount,currency_code,status,campaign_creator_id,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'earning',500,$MON,'accrued',$PART,'Devengo',NOW(3),NOW(3));" OK

porque "y un segundo devengo de la MISMA participacion no" \
  "INSERT INTO ledger_entries
     (uuid,creator_id,entry_type,amount,currency_code,status,campaign_creator_id,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'earning',500,$MON,'accrued',$PART,'Devengo otra vez',NOW(3),NOW(3));" "uq_ledger_devengo"

echo ""
echo "-- Anular libera el sitio --"

probar "se anula con motivo" \
  "UPDATE ledger_entries SET status='void', status_changed_at=NOW(3),
      status_reason='Se devengo sobre la participacion equivocada.'
    WHERE campaign_creator_id=$PART AND entry_type='earning';" OK

probar "y esa participacion vuelve a poder devengar" \
  "INSERT INTO ledger_entries
     (uuid,creator_id,entry_type,amount,currency_code,status,campaign_creator_id,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'earning',300,$MON,'accrued',$PART,'Devengo bis',NOW(3),NOW(3));" OK

echo ""
echo "-- Nacer y moverse --"

porque "un asiento NO nace pagable: a pagable se llega" \
  "INSERT INTO ledger_entries
     (uuid,creator_id,entry_type,amount,currency_code,status,campaign_creator_id,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'bonus',100,$MON,'payable',NULL,'Bono',NOW(3),NOW(3));" "ck_ledger_estado_inicial|nace devengado o pagado"

porque "mover un asiento sin decir por que, no" \
  "UPDATE ledger_entries SET status='payable', status_changed_at=NOW(3)
    WHERE campaign_creator_id=$PART AND status='accrued';" "exige decir cuando y por que"

probar "con motivo si se mueve a pagable" \
  "UPDATE ledger_entries SET status='payable', status_changed_at=NOW(3),
      status_reason='Se cumplieron las cinco condiciones.'
    WHERE campaign_creator_id=$PART AND status='accrued';" OK

# Y el motivo de la transicion ANTERIOR no explica la siguiente: sigue en la
# fila, y sin exigir que `status_changed_at` cambie, este UPDATE pasaria.
porque "el motivo del movimiento anterior no vale para el siguiente" \
  "UPDATE ledger_entries SET status='on_hold'
    WHERE campaign_creator_id=$PART AND status='payable';" "en ESE movimiento"

probar "y de pagable a pagado" \
  "UPDATE ledger_entries SET status='paid', status_changed_at=NOW(6),
      status_reason='Pagado en el lote LOTE-1.'
    WHERE campaign_creator_id=$PART AND status='payable';" OK

porque "pero un pagado NO vuelve" \
  "UPDATE ledger_entries SET status='accrued', status_changed_at=NOW(6),
      status_reason='Vuelta atras a mano.'
    WHERE campaign_creator_id=$PART AND status='paid';" "un pagado o un anulado no vuelven"

echo ""
echo "-- Lo que ya era inmutable, lo sigue siendo --"

porque "el importe de un asiento no se toca" \
  "UPDATE ledger_entries SET amount=999 WHERE campaign_creator_id=$PART;" "solo-insercion"

porque "ni la moneda" \
  "UPDATE ledger_entries SET currency_code='USD' WHERE campaign_creator_id=$PART;" "solo-insercion"

porque "ni se borra" \
  "DELETE FROM ledger_entries WHERE campaign_creator_id=$PART;" "no admite borrado"

resumen
