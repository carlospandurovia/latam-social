#!/bin/bash
# Pruebas de restriccion de la iteracion 7.5: el compromiso economico.
#
#   ck_camp_creator_budget    el presupuesto de creadores no es negativo
#   ck_camp_budget_override   autorizar exige quien, cuando Y por que
#   tg_ccr_compromiso         congelado al aceptar, y declarado al invitar
#
# `BR-CAMPAIGN-005` es roja y nombra «el presupuesto de creadores de la
# campana». `campaigns` tenia `revenue_amount` --lo que se le cobra al cliente--
# y nada mas: el dato que la regla nombra NO ESTABA EN EL MODELO. Cuarto caso
# del patron de 7.1 a 7.4 y el peor de los cinco.
#
# Y `BR-CREATOR-008`: la tarifa declarada es referencia, el compromiso es el
# monto congelado en la participacion. De ese numero sale lo que se le paga a
# una persona, asi que vive en la base y no en el controlador.
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  7.5 - El compromiso economico (BR-CAMPAIGN-005, BR-CREATOR-008)"
echo "==================================================================================="

CLI="(SELECT id FROM (SELECT id FROM client_organizations ORDER BY id LIMIT 1) t)"
MAR="(SELECT id FROM (SELECT id FROM client_brands ORDER BY id LIMIT 1) t)"
MON="(SELECT code FROM (SELECT code FROM currencies ORDER BY code LIMIT 1) t)"
ENT="(SELECT id FROM (SELECT id FROM legal_entities ORDER BY id LIMIT 1) t)"
USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) t)"
CR1="(SELECT id FROM (SELECT id FROM creators ORDER BY id LIMIT 1) t)"
CR2="(SELECT id FROM (SELECT id FROM creators ORDER BY id LIMIT 1 OFFSET 1) t)"

$CLIENTE $DB -e "DELETE FROM campaign_creators WHERE campaign_id IN
   (SELECT id FROM (SELECT id FROM campaigns WHERE code LIKE 'C75-%') t);
  DELETE FROM campaigns WHERE code LIKE 'C75-%';" 2>/dev/null

valor "hay al menos DOS creadores sembrados" "SELECT COUNT(*)>=2 FROM creators;" "1"
valor "y un usuario para poder firmar la autorizacion" "SELECT COUNT(*)>0 FROM users;" "1"
valor "y ninguna campana de esta suite de una pasada anterior" \
  "SELECT COUNT(*) FROM campaigns WHERE code LIKE 'C75-%';" "0"

alta() {  # codigo, gratis, presupuesto
  echo "INSERT INTO campaigns (uuid,code,name,client_organization_id,client_brand_id,
    billing_legal_entity_id,currency_code,revenue_amount,is_gratis,creator_budget_amount,
    starts_on,ends_on,status,confirmed_at,created_at)
    VALUES (UUID(),'$1','Campana $1',$CLI,$MAR,$ENT,$MON,$( [ "$2" == "1" ] && echo 0 || echo 15000.00),$2,$3,
            '2026-09-01','2026-09-30','in_progress',NOW(3),NOW(3));"
}
CP="(SELECT id FROM (SELECT id FROM campaigns WHERE code='C75-PAGA') t)"
CG="(SELECT id FROM (SELECT id FROM campaigns WHERE code='C75-CANJE') t)"

$CLIENTE $DB -e "$(alta 'C75-PAGA' 0 1000.00)" 2>/dev/null
$CLIENTE $DB -e "$(alta 'C75-CANJE' 1 0)" 2>/dev/null
valor "las dos campanas de la suite existen" \
  "SELECT COUNT(*) FROM campaigns WHERE code LIKE 'C75-%';" "2"

echo ""
echo "--- El presupuesto de creadores ---"
probar "un presupuesto de cero es valido: una campana puede no gastar nada" \
  "UPDATE campaigns SET creator_budget_amount=0 WHERE code='C75-PAGA';" OK
probar "uno negativo no" \
  "UPDATE campaigns SET creator_budget_amount=-1 WHERE code='C75-PAGA';" RECHAZO
probar "y uno normal si" \
  "UPDATE campaigns SET creator_budget_amount=1000.00 WHERE code='C75-PAGA';" OK

echo ""
echo "--- La autorizacion del sobrecosto: las TRES columnas o ninguna ---"
# Una firma sin explicacion no sirve dentro de un ano. Es la misma forma que
# `ck_inv_responded`: dos datos que responden a la misma pregunta van juntos.
probar "quien y cuando, SIN motivo" \
  "UPDATE campaigns SET budget_override_by_user_id=$USR, budget_override_at=NOW(3)
     WHERE code='C75-PAGA';" RECHAZO
probar "motivo SIN quien lo firmo" \
  "UPDATE campaigns SET budget_override_reason='porque si' WHERE code='C75-PAGA';" RECHAZO
probar "las tres juntas si" \
  "UPDATE campaigns SET budget_override_by_user_id=$USR, budget_override_at=NOW(3),
     budget_override_reason='El cliente amplio el alcance a dos ciudades mas.'
     WHERE code='C75-PAGA';" OK
probar "y quitarlas las tres, tambien" \
  "UPDATE campaigns SET budget_override_by_user_id=NULL, budget_override_at=NULL,
     budget_override_reason=NULL WHERE code='C75-PAGA';" OK
probar "pero quitar solo el motivo, no" \
  "UPDATE campaigns SET budget_override_by_user_id=$USR, budget_override_at=NOW(3),
     budget_override_reason='Motivo suficiente para la auditoria.' WHERE code='C75-PAGA';" OK
probar "  ...y ahora borrar el motivo dejando la firma" \
  "UPDATE campaigns SET budget_override_reason=NULL WHERE code='C75-PAGA';" RECHAZO

meter() {  # campana, creador, importe, estado
  echo "INSERT INTO campaign_creators
    (uuid,campaign_id,creator_id,status,agreed_amount,currency_code,created_at)
    VALUES (UUID(),$1,$2,'$4',$3,$MON,NOW(3));"
}

echo ""
echo "--- No se invita a nadie sin decirle cuanto se le paga ---"
probar "un candidato con importe cero: todavia se esta armando la lista" \
  "$(meter "$CP" "$CR1" 0 'shortlisted')" OK
probar "pasarlo a invited con cero" \
  "UPDATE campaign_creators SET status='invited', invited_at=NOW(3)
     WHERE campaign_id=$CP AND creator_id=$CR1;" RECHAZO
probar "ponerle importe primero" \
  "UPDATE campaign_creators SET agreed_amount=400.00 WHERE campaign_id=$CP AND creator_id=$CR1;" OK
probar "y ahora si se invita" \
  "UPDATE campaign_creators SET status='invited', invited_at=NOW(3)
     WHERE campaign_id=$CP AND creator_id=$CR1;" OK

echo ""
echo "--- Salvo que la campana sea un canje (7.2) ---"
# Es la mitad que no se puede escribir con un CHECK: la condicion esta en OTRA
# tabla. Y exigirle importe a un creador de una campana declarada gratuita seria
# contradecir la decision de 7.2.
probar "en la campana gratuita, invitar con cero" \
  "$(meter "$CG" "$CR2" 0 'shortlisted')" OK
probar "  ...y moverlo a invited" \
  "UPDATE campaign_creators SET status='invited', invited_at=NOW(3)
     WHERE campaign_id=$CG AND creator_id=$CR2;" OK

echo ""
echo "--- Y al aceptar, el monto queda congelado (BR-CREATOR-008) ---"
probar "el creador acepta" \
  "UPDATE campaign_creators SET status='accepted', accepted_at=NOW(3)
     WHERE campaign_id=$CP AND creator_id=$CR1;" OK
probar "subirle el monto despues de aceptar" \
  "UPDATE campaign_creators SET agreed_amount=900.00 WHERE campaign_id=$CP AND creator_id=$CR1;" RECHAZO
probar "bajarselo tampoco" \
  "UPDATE campaign_creators SET agreed_amount=100.00 WHERE campaign_id=$CP AND creator_id=$CR1;" RECHAZO
valor "y sigue siendo el acordado" \
  "SELECT agreed_amount=400.0000 FROM campaign_creators WHERE campaign_id=$CP AND creator_id=$CR1;" "1"
# Era `revision_rounds_used`, que en 8.3 se fue de esta tabla --las rondas son
# por ENTREGABLE, no por creador--. Sirve igual cualquier columna que no sea el
# monto: lo que se afirma es que el congelado es del importe y no de la fila.
probar "lo demas de una participacion aceptada si se toca" \
  "UPDATE campaign_creators SET completed_at=NOW(3) WHERE campaign_id=$CP AND creator_id=$CR1;" OK

$CLIENTE $DB -e "DELETE FROM campaign_creators WHERE campaign_id IN
   (SELECT id FROM (SELECT id FROM campaigns WHERE code LIKE 'C75-%') t);
  UPDATE campaigns SET confirmed_at=NULL, status='draft', budget_override_by_user_id=NULL,
     budget_override_at=NULL, budget_override_reason=NULL WHERE code LIKE 'C75-%';
  DELETE FROM campaigns WHERE code LIKE 'C75-%';" 2>/dev/null

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
