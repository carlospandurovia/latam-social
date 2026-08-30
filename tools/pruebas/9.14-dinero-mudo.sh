#!/bin/bash
# QA de la Fase 9: las reglas del dinero que NADIE habia preguntado nunca.
#
# `verificar-cobertura-sql.py` --que nace en esta iteracion-- midio que de las
# 317 reglas del esquema hay 167 a las que ninguna prueba les ha preguntado
# jamas. Entre ellas, VEINTE de las cinco tablas por las que pasa el dinero:
# `ledger_entries`, `payouts`, `payout_batches`, `payout_earnings` y `payments`.
#
# Estaban puestas desde la Fase 2 y verdes en los dos verificadores que existen
# --el de equivalencia y el de disparadores generados--, porque los dos
# comprueban que la regla EXISTA. Ninguno comprueba lo unico que importa el dia
# que falle: que alguien se lo haya preguntado. Es exactamente lo que paso con
# `campaign_costs` en `9.10a`, y alli aparecieron dos huecos al primer intento.
#
#   ck_ledger_type              un tipo de asiento inventado
#   ck_ledger_sign              el signo tiene que corresponder al tipo
#   ck_ledger_amount            un asiento de cero no es un asiento
#   ck_ledger_earning_link      un devengo sin participacion
#   ck_ledger_payout_link       un pago sin payout, y un no-pago con payout
#   ck_ledger_reversal          una reversion que no dice que corrige
#   ck_ledger_reverses_type     y solo dos tipos pueden corregir a otro
#   ck_ledger_fx                una tasa congelada a medias
#   ck_ledger_withholding       una retencion sin su tasa y su norma
#   ck_ledger_withholding_rate  y una tasa fuera de (0, 100]
#   ck_payout_status            estados de un pago
#   ck_payout_amount            un pago de cero
#   ck_payout_sent              enviado sin fecha de envio
#   ck_payout_returned          devuelto sin fecha de devolucion
#   ck_pbatch_status            estados de un lote
#   ck_pbatch_approved          aprobado sin firma ni fecha
#   ck_pbatch_executed          ejecutado sin fecha
#   ck_pbatch_approval_order    ejecutado antes que aprobado
#   ck_pe_amount                liquidar cero no liquida nada
#   ck_pe_void                  anular una liquidacion sin decir por que
#
# `ck_payment_amount` y `ck_payment_method` NO estan: `payments` cuelga de una
# factura y la semilla no tiene ninguna. Ver el final del archivo.
#
# Uso: bash tools/pruebas/9.14-dinero-mudo.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.14 - Las reglas del dinero que nadie habia preguntado"
echo "==================================================================================="

USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"
OTRO="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1 OFFSET 1) u2)"
MON="(SELECT code FROM (SELECT code FROM currencies ORDER BY code LIMIT 1) m)"
SOC="(SELECT id FROM (SELECT id FROM legal_entities ORDER BY id LIMIT 1) s)"
CRE="(SELECT id FROM (SELECT id FROM creators ORDER BY id LIMIT 1) c)"
PAR="(SELECT id FROM (SELECT id FROM campaign_creators ORDER BY id LIMIT 1) p)"

valor "hay un creador en la semilla" \
  "SELECT CASE WHEN COUNT(*) > 0 THEN 'si' ELSE 'no' END FROM creators;" "si"
valor "y una participacion" \
  "SELECT CASE WHEN COUNT(*) > 0 THEN 'si' ELSE 'no' END FROM campaign_creators;" "si"
valor "y dos usuarios distintos" \
  "SELECT CASE WHEN COUNT(*) > 1 THEN 'si' ELSE 'no' END FROM users;" "si"

# Todas las aserciones de rechazo de abajo no dejan filas: no hay nada que
# limpiar en unas tablas que no se pueden limpiar (3.12).
#
# ### Esta suite se construye SU caso, y no reusa el de nadie
#
# Dos de las reglas de aqui --`ck_ledger_reverses_type` y `ck_pe_amount`-- solo
# contestan si existe un asiento: la primera necesita uno al que apuntar, y la
# segunda esta DETRAS de `tg_pe_sociedad`, que mira tres tablas y contesta antes,
# asi que hace falta el caso BUENO entero para poder torcer solo el importe.
#
# La primera version reusaba lo que dejaban las suites anteriores, como hace
# `9.6`. Corriendo sola se ponia amarilla, y corriendo la bateria entera TAMBIEN:
# para cuando llega aqui, el unico devengo pagable ya esta liquidado por `9.6` y
# las dos participaciones de la semilla estan ocupadas. Dos motivos distintos, el
# mismo sintoma, y ninguno de los dos tiene que ver con lo que se quiere probar.
#
# Asi que se crea todo: campana propia --copiando los datos de la de la semilla,
# que ya son validos-- participacion propia con el creador que tiene medio de
# pago verificado (`tg_payout_medio_valido`, 3.8), y su devengo. Una suite de QA
# que depende del orden mide el orden, no la regla.
$CLIENTE $DB -e "INSERT INTO campaigns
   (uuid,code,name,client_organization_id,client_brand_id,currency_code,starts_on,ends_on,
    status,revenue_amount,is_gratis,creator_budget_amount,billing_legal_entity_id,created_at,updated_at)
   SELECT UUID(),'CMP-914','Campana de la suite 9.14',client_organization_id,client_brand_id,
          currency_code,starts_on,ends_on,'draft',0,0,creator_budget_amount,
          billing_legal_entity_id,NOW(3),NOW(3)
     FROM campaigns WHERE code <> 'CMP-914' ORDER BY id LIMIT 1;" 2>&1 | grep -i error

$CLIENTE $DB -e "INSERT INTO campaign_creators
   (uuid,campaign_id,creator_id,status,agreed_amount,currency_code,payee_type,created_at,updated_at)
   SELECT UUID(),(SELECT id FROM campaigns WHERE code='CMP-914'),cpm.creator_id,'shortlisted',
          100,(SELECT currency_code FROM campaigns WHERE code='CMP-914'),'creator',NOW(3),NOW(3)
     FROM creator_payment_methods cpm
    WHERE cpm.status='verified' AND cpm.eligible_from IS NOT NULL AND cpm.eligible_from <= NOW(3)
    ORDER BY cpm.id LIMIT 1;" 2>&1 | grep -i error

$CLIENTE $DB -e "INSERT INTO ledger_entries
   (uuid,creator_id,entry_type,amount,currency_code,status,campaign_creator_id,
    description,occurred_at,created_at)
   SELECT UUID(),cc.creator_id,'earning',cc.agreed_amount,cc.currency_code,'accrued',cc.id,
          'Devengo de la suite 9.14.',NOW(3),NOW(3)
     FROM campaign_creators cc
     JOIN campaigns c ON c.id = cc.campaign_id
    WHERE c.code='CMP-914';" 2>&1 | grep -i error

# Nace `accrued` y se mueve: `ck_ledger_estado_inicial` (9.3) no admite nacer
# pagable --a pagable se LLEGA-- y `ck_ledger_status_firma` exige el motivo.
$CLIENTE $DB -e "UPDATE ledger_entries SET status='payable', status_changed_at=NOW(6),
   status_reason='Preparado para la suite 9.14.'
   WHERE description='Devengo de la suite 9.14.';" 2>&1 | grep -i error

LIQ=$($CLIENTE $DB -N -B -e "SELECT id FROM ledger_entries
    WHERE description='Devengo de la suite 9.14.' ORDER BY id DESC LIMIT 1" 2>/dev/null | tr -d '\r')

valor "la suite tiene su campana, su participacion y su devengo" \
  "SELECT CASE WHEN '${LIQ:-}' <> '' THEN 'si' ELSE 'no' END;" "si"

echo ""
echo "-- El libro mayor: que puede ser un asiento --"

# La alternancia es necesaria y **no es una concesion**: `ck_ledger_sign` enumera
# los tipos en sus dos ramas, asi que un tipo inventado incumple LAS DOS reglas a
# la vez y cual se nombra depende del motor --MariaDB dice `ck_ledger_type`,
# MySQL 8 dice `ck_ledger_sign`--. `ck_ledger_type` no puede ser nunca el unico
# motivo: no hay ninguna fila que lo incumpla y respete el signo.
porque "un tipo de asiento inventado" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'propina',100,$MON,'accrued','9.14',NOW(3),NOW(3));" \
  "ck_ledger_type|ck_ledger_sign"

porque "un asiento de cero no es un asiento" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,campaign_creator_id,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'earning',0,$MON,'accrued',$PAR,'9.14',NOW(3),NOW(3));" \
  "ck_ledger_amount"

echo ""
echo "-- El signo tiene que corresponder al tipo --"

# Un devengo NEGATIVO es dinero que el creador nos debe disfrazado de dinero que
# le debemos. `ck_ledger_sign` existe desde la Fase 2 para eso y nunca contesto.
porque "un devengo negativo" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,campaign_creator_id,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'earning',-100,$MON,'accrued',$PAR,'9.14',NOW(3),NOW(3));" \
  "ck_ledger_sign"

porque "un pago positivo" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'payment',100,$MON,'paid','9.14',NOW(3),NOW(3));" \
  "ck_ledger_sign|ck_ledger_payout_link"

porque "una penalizacion positiva" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'penalty',100,$MON,'accrued','9.14',NOW(3),NOW(3));" \
  "ck_ledger_sign"

echo ""
echo "-- Cada asiento con lo que le da sentido --"

porque "un devengo sin participacion" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'earning',100,$MON,'accrued','9.14',NOW(3),NOW(3));" \
  "ck_ledger_earning_link"

porque "un pago sin payout" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'payment',-100,$MON,'paid','9.14',NOW(3),NOW(3));" \
  "ck_ledger_payout_link"

porque "una reversion que no dice que corrige" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'payment_reversal',100,$MON,'accrued','9.14',NOW(3),NOW(3));" \
  "ck_ledger_reversal"

# Solo `payment_reversal` y `adjustment` corrigen a otro asiento. Un devengo que
# apunta a otro devengo es una cadena que nadie sabe leer.
#
# Hace falta un asiento al que apuntar --`fk_ledger_reverses` lo exige-- y la
# semilla no trae ninguno. Corriendo la bateria entera si lo hay, porque `2.13` y
# `9.3` van antes; **corriendo esta suite sola, no**. La primera version de esta
# asercion no lo vio: sin asiento, el subselect da NULL, la fila es LEGAL y el
# `INSERT` entraba --con lo que la asercion decia «no se rechazo» en vez de
# «faltaba la premisa», que es otra cosa--. Se afirma la premisa.
if [ -n "${LIQ:-}" ]; then
  DIANA="$LIQ"
  porque "y un devengo no corrige a nadie" \
    "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,campaign_creator_id,reverses_entry_id,description,occurred_at,created_at)
     VALUES (UUID(),$CRE,'earning',100,$MON,'accrued',$PAR,$DIANA,'9.14',NOW(3),NOW(3));" \
    "ck_ledger_reverses_type"
fi

echo ""
echo "-- La tasa congelada, entera o nada (BR-FIN-009) --"

porque "una tasa sin su fecha ni su fuente" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,campaign_creator_id,exchange_rate_snapshot,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'earning',100,$MON,'accrued',$PAR,3.75,'9.14',NOW(3),NOW(3));" \
  "ck_ledger_fx"

echo ""
echo "-- La retencion, que todavia no se usa y ya esta defendida (Q-40) --"

porque "una retencion sin su tasa ni su norma" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'withholding',-100,$MON,'accrued','9.14',NOW(3),NOW(3));" \
  "ck_ledger_withholding"

porque "y un devengo con tasa de retencion pegada" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,campaign_creator_id,withholding_rate_snapshot,withholding_basis_snapshot,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'earning',100,$MON,'accrued',$PAR,8,'Art. 74 LIR','9.14',NOW(3),NOW(3));" \
  "ck_ledger_withholding"

porque "una tasa de retencion del 120%" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,withholding_rate_snapshot,withholding_basis_snapshot,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'withholding',-100,$MON,'accrued',120,'Art. 74 LIR','9.14',NOW(3),NOW(3));" \
  "ck_ledger_withholding_rate"

porque "y una del cero por ciento" \
  "INSERT INTO ledger_entries (uuid,creator_id,entry_type,amount,currency_code,status,withholding_rate_snapshot,withholding_basis_snapshot,description,occurred_at,created_at)
   VALUES (UUID(),$CRE,'withholding',-100,$MON,'accrued',0,'Art. 74 LIR','9.14',NOW(3),NOW(3));" \
  "ck_ledger_withholding_rate"

echo ""
echo "-- El lote --"

porque "un estado de lote inventado" \
  "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,created_at)
   VALUES (UUID(),'L914-A',$SOC,$MON,'firmado',$USR,NOW(3));" \
  "ck_pbatch_status"

porque "aprobado sin decir quien ni cuando" \
  "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,created_at)
   VALUES (UUID(),'L914-B',$SOC,$MON,'approved',$USR,NOW(3));" \
  "ck_pbatch_approved"

porque "ejecutado sin fecha de ejecucion" \
  "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,approved_by_user_id,approved_at,created_at)
   VALUES (UUID(),'L914-C',$SOC,$MON,'executed',$USR,$OTRO,NOW(3),NOW(3));" \
  "ck_pbatch_executed"

# Ejecutar antes de aprobar es la firma llegando despues del dinero.
porque "ejecutado ANTES de aprobarse" \
  "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,approved_by_user_id,approved_at,executed_at,created_at)
   VALUES (UUID(),'L914-D',$SOC,$MON,'executed',$USR,$OTRO,NOW(3),DATE_SUB(NOW(3), INTERVAL 1 DAY),NOW(3));" \
  "ck_pbatch_approval_order"

echo ""
echo "-- El pago --"

MED="(SELECT id FROM (SELECT id FROM creator_payment_methods WHERE status='verified'
        AND eligible_from IS NOT NULL AND eligible_from <= NOW(3) ORDER BY id LIMIT 1) x)"
DUE="(SELECT creator_id FROM (SELECT creator_id FROM creator_payment_methods WHERE status='verified'
        AND eligible_from IS NOT NULL AND eligible_from <= NOW(3) ORDER BY id LIMIT 1) y)"

probar "un lote para colgar los pagos" \
  "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,created_at)
   VALUES (UUID(),'L914-OK',$SOC,$MON,'draft',$USR,NOW(3));" OK

L="(SELECT id FROM (SELECT id FROM payout_batches WHERE code='L914-OK') a)"

porque "un estado de pago inventado" \
  "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,
      account_masked_snapshot,amount,currency_code,status,created_at)
   VALUES (UUID(),$L,$DUE,$MED,'Titular','****9140',100,$MON,'rebotado',NOW(3));" \
  "ck_payout_status"

porque "un pago de cero" \
  "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,
      account_masked_snapshot,amount,currency_code,status,created_at)
   VALUES (UUID(),$L,$DUE,$MED,'Titular','****9141',0,$MON,'pending',NOW(3));" \
  "ck_payout_amount"

porque "enviado sin fecha de envio" \
  "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,
      account_masked_snapshot,amount,currency_code,status,created_at)
   VALUES (UUID(),$L,$DUE,$MED,'Titular','****9142',100,$MON,'sent',NOW(3));" \
  "ck_payout_sent"

porque "devuelto sin fecha de devolucion" \
  "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,
      account_masked_snapshot,amount,currency_code,status,sent_at,return_reason,returned_by_user_id,created_at)
   VALUES (UUID(),$L,$DUE,$MED,'Titular','****9143',100,$MON,'returned',NOW(3),'Cuenta cerrada.',$USR,NOW(3));" \
  "ck_payout_returned"

echo ""
echo "-- La liquidacion --"

# `ck_pe_amount` esta DETRAS de `tg_pe_sociedad` (9.6), que mira tres tablas y
# contesta primero. La primera version de esta asercion la daba por probada
# ensenando un rechazo del disparador: verde, y sin haber preguntado nunca por el
# importe. Para que conteste hay que construir el caso BUENO entero --un devengo
# pagable, sin liquidacion viva, y un lote de la sociedad de SU campana-- y
# torcer solo el importe.
if [ -n "${LIQ:-}" ]; then
  SOCLIQ="(SELECT billing_legal_entity_id FROM (SELECT c.billing_legal_entity_id
            FROM ledger_entries le JOIN campaign_creators cc ON cc.id=le.campaign_creator_id
            JOIN campaigns c ON c.id=cc.campaign_id WHERE le.id=$LIQ) s)"
  CRELIQ="(SELECT creator_id FROM (SELECT creator_id FROM ledger_entries WHERE id=$LIQ) r)"
  MEDLIQ="(SELECT id FROM (SELECT id FROM creator_payment_methods
            WHERE creator_id=$CRELIQ AND status='verified' ORDER BY id LIMIT 1) m2)"

  probar "un lote de la sociedad correcta" \
    "INSERT INTO payout_batches (uuid,code,legal_entity_id,currency_code,status,created_by_user_id,created_at)
     VALUES (UUID(),'L914-LIQ',$SOCLIQ,$MON,'draft',$USR,NOW(3));" OK

  probar "y un pago dentro" \
    "INSERT INTO payouts (uuid,payout_batch_id,creator_id,payment_method_id,beneficiary_name_snapshot,
        account_masked_snapshot,amount,currency_code,status,created_at)
     VALUES (UUID(),(SELECT id FROM (SELECT id FROM payout_batches WHERE code='L914-LIQ') q),
        $CRELIQ,$MEDLIQ,'Titular','****9144',100,$MON,'pending',NOW(3));" OK

  PLIQ="(SELECT id FROM (SELECT id FROM payouts WHERE account_masked_snapshot='****9144') b)"

  porque "liquidar cero no liquida nada" \
    "INSERT INTO payout_earnings (payout_id,ledger_entry_id,amount,created_at)
     VALUES ($PLIQ,$LIQ,0,NOW(3));" \
    "ck_pe_amount"

  porque "y anular una liquidacion exige decir por que" \
    "INSERT INTO payout_earnings (payout_id,ledger_entry_id,amount,voided_at,created_at)
     VALUES ($PLIQ,$LIQ,50,NOW(3),NOW(3));" \
    "ck_pe_void"
fi

# `ck_payment_amount` y `ck_payment_method` se quedan SIN preguntar, y se dice.
#
# `payments` cuelga de `invoices` con una foranea NOT NULL, y la semilla no trae
# ninguna factura: cualquier intento se estrella antes en la foranea, y una
# asercion que admita ese rechazo como bueno seria verde sin haber preguntado
# nada --que es justo el defecto que esta suite existe para cazar--. Sembrar una
# factura a mano significaria inventar hoy la forma de una factura que construye
# `9.9`, y eso espera al contador (`Q-44`). Quedan contadas en `MUDAS-BASE`.

resumen
