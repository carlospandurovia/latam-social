#!/bin/bash
# Pruebas de restriccion de la iteracion 7.6: la invitacion.
#
#   uq_inv_viva                   una invitacion VIVA por participacion
#   uq_inv_token                  dos invitaciones no comparten huella
#   ck_inv_dates                  caduca DESPUES de mandarse
#   ck_inv_decline                rechazar exige motivo; aceptar no lo lleva
#   ck_inv_reason_valido          y el motivo es de lista cerrada
#   ck_inv_responded / _ip        contestada exige respuesta Y desde donde
#   ck_inv_revoked                anulada exige por que
#   ck_inv_terminal               contestada y anulada se excluyen
#   ck_camp_invitation_hours      el plazo va de 1 hora a 30 dias
#   tg_ccr_monto_con_invitacion   el importe no se mueve con una oferta encima
#   ck_iq_body / ck_iq_seen       T-38: una pregunta dice algo, y atenderla tiene dueno
#
# La ultima es la que de verdad importa: sin ella, al creador le llega «te
# pagamos 1.500», alguien lo baja a 900, y el creador acepta 900 sin haberlo
# visto nunca.
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
# Un RECHAZO solo prueba algo si rechaza por SU motivo. Un `SIGNAL` de mas de
# 128 caracteres se convierte en MySQL/Percona en `1648 Data too long for
# condition item`, que tambien es un error, y con `probar ... RECHAZO` sale
# verde igual. Cuatro mensajes llevaban rotos asi desde 7.4. Gate permanente:
# `tools/verificar-mensajes.py`; la leccion, aqui.
echo ""
echo "==================================================================================="
echo "  7.6 - La invitacion a una campana"
echo "==================================================================================="

$CLIENTE $DB -e "DELETE FROM invitation_questions; DELETE FROM invitations;" 2>/dev/null

valor "la tabla existe" \
  "SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_name='invitations';" "1"
valor "y ninguna invitacion de una pasada anterior" "SELECT COUNT(*) FROM invitations;" "0"
valor "la semilla trae dos participaciones" \
  "SELECT COUNT(*) FROM campaign_creators;" "2"

# `$1` sufijo de la huella, `$2` desplazamiento de la participacion,
# `$3` horas hasta caducar (puede ser negativo), `$4` importe.
inv() {
  echo "INSERT INTO invitations (uuid,campaign_creator_id,channel,token_hash,sent_at,expires_at,amount_snapshot,currency_snapshot,created_at)
    VALUES (UUID(),(SELECT id FROM (SELECT id FROM campaign_creators ORDER BY id LIMIT 1 OFFSET ${2:-0}) x),
      'email',LPAD('$1',64,'0'),NOW(3) - INTERVAL 1 HOUR,NOW(3) + INTERVAL ${3:-24} HOUR,${4:-1500},'PEN',NOW(3));"
}

echo ""
echo "--- Una invitacion VIVA por participacion ---"
probar "la primera" "$(inv 'a1' 0)" OK
probar "una segunda para la misma participacion" "$(inv 'a2' 0)" RECHAZO
probar "pero otra participacion si tiene la suya" "$(inv 'b1' 1)" OK
probar "anulando la primera, entra la siguiente" \
  "UPDATE invitations SET revoked_at=NOW(3), revoked_reason='sustituida'
     WHERE token_hash=LPAD('a1',64,'0');
   $(inv 'a2' 0)" OK
valor "y quedan las dos: anular no borra, es evidencia" \
  "SELECT COUNT(*) FROM invitations WHERE token_hash IN (LPAD('a1',64,'0'),LPAD('a2',64,'0'));" "2"

echo ""
echo "--- La huella es unica ---"
probar "dos invitaciones con la misma huella" "$(inv 'b1' 1)" RECHAZO

echo ""
echo "--- Caduca DESPUES de mandarse ---"
probar "una que caduca antes de enviarse" \
  "UPDATE invitations SET expires_at=sent_at - INTERVAL 1 HOUR WHERE token_hash=LPAD('a2',64,'0');" RECHAZO
probar "y una que caduca justo despues, si" \
  "UPDATE invitations SET expires_at=sent_at + INTERVAL 1 MINUTE WHERE token_hash=LPAD('a2',64,'0');" OK

echo ""
echo "--- Contestar exige respuesta, motivo cuando toca, y desde donde ---"
probar "contestada sin decir que se contesto" \
  "UPDATE invitations SET responded_at=NOW(3) WHERE token_hash=LPAD('a2',64,'0');" RECHAZO
probar "aceptada sin IP" \
  "UPDATE invitations SET responded_at=NOW(3), response='accepted' WHERE token_hash=LPAD('a2',64,'0');" RECHAZO
probar "aceptada CON motivo de rechazo (no significa nada)" \
  "UPDATE invitations SET responded_at=NOW(3), response='accepted', decline_reason='amount',
     responded_ip=INET6_ATON('203.0.113.9') WHERE token_hash=LPAD('a2',64,'0');" RECHAZO
probar "rechazada SIN motivo" \
  "UPDATE invitations SET responded_at=NOW(3), response='declined',
     responded_ip=INET6_ATON('203.0.113.9') WHERE token_hash=LPAD('a2',64,'0');" RECHAZO
probar "rechazada con un motivo inventado" \
  "UPDATE invitations SET responded_at=NOW(3), response='declined', decline_reason='me_cae_mal',
     responded_ip=INET6_ATON('203.0.113.9') WHERE token_hash=LPAD('a2',64,'0');" RECHAZO
probar "rechazada como debe ser" \
  "UPDATE invitations SET responded_at=NOW(3), response='declined', decline_reason='amount',
     responded_ip=INET6_ATON('203.0.113.9') WHERE token_hash=LPAD('a2',64,'0');" OK
probar "una respuesta inventada" \
  "UPDATE invitations SET response='quiza' WHERE token_hash=LPAD('a2',64,'0');" RECHAZO

echo ""
echo "--- Anular exige por que, y no convive con contestar ---"
probar "anular sin motivo" \
  "UPDATE invitations SET revoked_at=NOW(3) WHERE token_hash=LPAD('b1',64,'0');" RECHAZO
probar "anular una ya contestada" \
  "UPDATE invitations SET revoked_at=NOW(3), revoked_reason='sustituida'
     WHERE token_hash=LPAD('a2',64,'0');" RECHAZO
probar "contestar una ya anulada" \
  "UPDATE invitations SET responded_at=NOW(3), response='accepted', responded_ip=INET6_ATON('203.0.113.9')
     WHERE token_hash=LPAD('a1',64,'0');" RECHAZO

echo ""
echo "--- El plazo de la campana ---"
probar "cero horas: nace caducada" \
  "UPDATE campaigns SET invitation_hours=0 WHERE code='CMP-0001';" RECHAZO
probar "mas de treinta dias" \
  "UPDATE campaigns SET invitation_hours=721 WHERE code='CMP-0001';" RECHAZO
probar "setenta y dos horas" \
  "UPDATE campaigns SET invitation_hours=72 WHERE code='CMP-0001';" OK

echo ""
echo "--- El importe no se mueve con una invitacion viva encima ---"
#
# Las dos participaciones de la semilla estan ACEPTADAS, y sobre una aceptada
# manda `tg_ccr_compromiso` (7.5): el monto no se toca, haya invitacion o no. Asi
# que la segunda se pasa a `invited` para probar LA REGLA DE ESTA ITERACION y se
# devuelve al final.
#
# Sin esto la primera asercion salia verde por el motivo equivocado --el importe
# que ponia ya era el que tenia, y sin cambio no se dispara ningun trigger--,
# que es exactamente el falso verde que estas suites existen para no tener.
PART="(SELECT id FROM (SELECT id FROM campaign_creators ORDER BY id LIMIT 1 OFFSET 1) x)"
$CLIENTE $DB -e "UPDATE campaign_creators SET status='invited', accepted_at=NULL, invited_at=NOW(3)
  WHERE id=$PART;" 2>/dev/null

valor "la premisa: esa participacion ya no esta aceptada" \
  "SELECT status FROM campaign_creators WHERE id=$PART;" "invited"
valor "y la invitacion b1 sigue viva sobre ella" \
  "SELECT COUNT(*) FROM invitations WHERE token_hash=LPAD('b1',64,'0') AND viva_gate=1;" "1"

porque "bajar el monto de la participacion invitada" \
  "UPDATE campaign_creators SET agreed_amount=850 WHERE id=$PART;" "invitacion viva"
probar "anulada la invitacion, el monto se mueve" \
  "UPDATE invitations SET revoked_at=NOW(3), revoked_reason='renegociacion'
     WHERE token_hash=LPAD('b1',64,'0');
   UPDATE campaign_creators SET agreed_amount=850 WHERE id=$PART;" OK
probar "y sin invitacion viva, tampoco estorba una segunda vez" \
  "UPDATE campaign_creators SET agreed_amount=1000 WHERE id=$PART;" OK

echo ""
echo "--- T-38: las preguntas del creador ---"
# `a2` esta RECHAZADA y `b1` anulada, asi que las preguntas se cuelgan de la que
# haya: una pregunta es de la invitacion, no de su estado.
INV="(SELECT id FROM (SELECT id FROM invitations ORDER BY id LIMIT 1) x)"
pregunta() {
  echo "INSERT INTO invitation_questions (uuid,invitation_id,body,asked_at,created_at)
    VALUES (UUID(),$INV,'$1',NOW(3),NOW(3));"
}
probar "una pregunta normal" "$(pregunta 'Cuando llega el producto?')" OK
probar "una pregunta vacia" "$(pregunta '   ')" RECHAZO
probar "una de dos letras" "$(pregunta 'ok')" RECHAZO
probar "marcar vista sin decir quien" \
  "UPDATE invitation_questions SET seen_at=NOW(3) WHERE invitation_id=$INV;" RECHAZO
probar "marcar vista con quien" \
  "UPDATE invitation_questions SET seen_at=NOW(3),
     seen_by_user_id=(SELECT id FROM users ORDER BY id LIMIT 1) WHERE invitation_id=$INV;" OK
probar "y un dueno sin fecha tampoco" \
  "UPDATE invitation_questions SET seen_at=NULL WHERE invitation_id=$INV;" RECHAZO
probar "borrar la invitacion que tiene preguntas" \
  "DELETE FROM invitations WHERE id=$INV;" RECHAZO

echo ""
echo "--- La participacion no se borra teniendo invitaciones ---"
probar "borrar la participacion invitada" \
  "DELETE FROM campaign_creators
    WHERE id=(SELECT id FROM (SELECT id FROM campaign_creators ORDER BY id LIMIT 1 OFFSET 1) x);" RECHAZO

echo ""
echo "--- La puerta cuenta lo que tiene que contar ---"
valor "ninguna viva: todas contestadas o anuladas" \
  "SELECT COUNT(*) FROM invitations WHERE viva_gate=1;" "0"
# Tres y no cuatro: la cuarta insercion --la que repetia la huella `b1`-- la
# rechazo `uq_inv_token`, que es lo que se afirmo mas arriba.
valor "y las tres filas que si entraron siguen ahi" "SELECT COUNT(*) FROM invitations;" "3"

# Se devuelve la semilla a como estaba: aceptada y con su importe.
$CLIENTE $DB -e "DELETE FROM invitation_questions;
  DELETE FROM invitations;
  UPDATE campaign_creators SET agreed_amount=900, status='accepted', accepted_at=NOW(3)
   WHERE id=$PART;" 2>/dev/null

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
