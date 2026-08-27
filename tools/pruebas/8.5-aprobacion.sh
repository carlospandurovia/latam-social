#!/bin/bash
# Pruebas de restriccion de la iteracion 8.5: el visto bueno del cliente.
#
#   tg_apl_version_aprobada     al cliente solo se le manda lo aprobado, Y esa version
#   uq_apl_viva                 un enlace vivo por pieza (17a columna puerta)
#   ck_apl_respondida           contestada implica respuesta, y al reves
#   ck_apl_cambios              pedir cambios exige decir cuales
#   ck_apl_plazo                un enlace no caduca antes de mandarse
#   ck_apl_una_salida           o lo contesta el cliente, o se anula. Las dos no
#   ck_apl_transcrita           no se transcribe lo que nadie contesto
#   tg_apl_respuesta_inmutable  la conformidad del cliente no se reescribe
#   tg_apl_no_delete            y no se borra (3.12)
#
# Uso: bash tools/pruebas/8.5-aprobacion.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  8.5 - El visto bueno del cliente"
echo "==================================================================================="

# Su propia pieza: la numero 3 de luisvega. 8.7 dejo puesta la 1 y 8.8 la 2, y
# ninguna de las dos puede limpiar. Dos suites que comparten fila se pisan, y la
# que falla es la segunda, por un motivo que no tiene que ver con lo que probaba.
MIA="(SELECT id FROM (SELECT cc.id FROM campaign_creators cc
        JOIN creators c ON c.id=cc.creator_id
       WHERE c.display_name='luisvega' AND cc.accepted_at IS NOT NULL LIMIT 1) m)"
REQ="(SELECT id FROM (SELECT id FROM campaign_requirements ORDER BY id LIMIT 1) x)"
USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"
H1="SHA2('uno',256)"
H2="SHA2('dos',256)"

valor "la columna puerta del enlace vivo existe" \
  "SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='approval_links' AND column_name='viva_gate';" "1"
valor "y ninguna pieza mia de una pasada anterior" \
  "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA AND sequence_number=3;" "0"

$CLIENTE $DB -e "INSERT INTO deliverables
   (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,status,due_on,submitted_at,created_at,updated_at)
   VALUES (UUID(),$MIA,$REQ,3,'in_review',CURDATE() + INTERVAL 7 DAY,NOW(3),NOW(3),NOW(3));" 2>&1 | grep -i error
D="(SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA AND sequence_number=3) d)"
$CLIENTE $DB -e "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
   VALUES (UUID(),$D,1,'https://drive.example/aprob1',NOW(3),NOW(3));" 2>&1 | grep -i error
$CLIENTE $DB -e "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
   VALUES (UUID(),$D,2,'https://drive.example/aprob2',NOW(3),NOW(3));" 2>&1 | grep -i error
V1="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$D AND version_number=1) v)"
V2="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$D AND version_number=2) v)"

enlace() {  # version, hash, extra_col, extra_val
  echo "INSERT INTO approval_links
    (uuid,deliverable_id,deliverable_version_id,token_hash,sent_to,sent_by_user_id,
     sent_at,expires_at,created_at,updated_at${3:+,$3})
    VALUES (UUID(),$D,$1,$2,'marketing@acme.example',$USR,
            NOW(3),NOW(3) + INTERVAL 5 DAY,NOW(3),NOW(3)${4:+,$4});"
}

echo ""
echo "--- Al cliente solo se le manda lo aprobado ---"
porque "mandarle una pieza en revision" \
  "$(enlace "$V1" "$H1")" "solo se le manda lo aprobado"
$CLIENTE $DB -e "UPDATE deliverables SET status='approved', approved_at=NOW(3),
   approved_by_user_id=$USR, approved_version_id=$V1 WHERE id=$D;" 2>&1 | grep -i error
# Y la version APROBADA, no otra cualquiera de la pieza: es la otra mitad de
# `BR-CONTENT-002`, y el puntero de 8.2 es lo que la hace comprobable.
porque "ni la version 2 cuando la aprobada es la 1" \
  "$(enlace "$V2" "$H1")" "la version aprobada"
probar "la version aprobada, si" \
  "$(enlace "$V1" "$H1")" OK

echo ""
echo "--- Un enlace vivo por pieza: la decimoseptima columna puerta ---"
# Dos enlaces vivos son dos respuestas posibles y contradictorias del mismo
# cliente, y ninguna forma de saber cual vale.
porque "un segundo enlace mientras el primero sigue vivo" \
  "$(enlace "$V1" "$H2")" "uq_apl_viva"
probar "anulando el primero, el segundo entra" \
  "UPDATE approval_links SET revoked_at=NOW(3), revoked_reason='reemplazado'
    WHERE deliverable_id=$D AND revoked_at IS NULL AND responded_at IS NULL;" OK
probar "y ahora si" \
  "$(enlace "$V1" "$H2")" OK
A2="(SELECT id FROM (SELECT id FROM approval_links WHERE token_hash=$H2) a)"

echo ""
echo "--- Anular exige decir por que ---"
porque "anular sin motivo" \
  "UPDATE approval_links SET revoked_at=NOW(3) WHERE id=$A2;" "ck_apl_revocada"

echo ""
echo "--- Contestada implica respuesta, y al reves ---"
porque "la fecha sin la respuesta" \
  "UPDATE approval_links SET responded_at=NOW(3) WHERE id=$A2;" "ck_apl_respondida"
porque "la respuesta sin la fecha" \
  "UPDATE approval_links SET response='approved' WHERE id=$A2;" "ck_apl_respondida"
porque "una respuesta inventada" \
  "UPDATE approval_links SET responded_at=NOW(3), response='quiza' WHERE id=$A2;" "ck_apl_response"

echo ""
echo "--- Pedir cambios exige decir cuales ---"
porque "cambios sin comentario" \
  "UPDATE approval_links SET responded_at=NOW(3), response='changes_requested' WHERE id=$A2;" "ck_apl_cambios"
porque "ni con un «no» por comentario" \
  "UPDATE approval_links SET responded_at=NOW(3), response='changes_requested', comments='no' WHERE id=$A2;" "ck_apl_cambios"

echo ""
echo "--- No se transcribe lo que nadie contesto ---"
porque "atar una revision a un enlace sin respuesta" \
  "UPDATE approval_links SET content_review_id=(SELECT id FROM (SELECT id FROM content_reviews ORDER BY id LIMIT 1) r)
    WHERE id=$A2;" "ck_apl_transcrita"

echo ""
echo "--- El cliente contesta ---"
probar "aprueba" \
  "UPDATE approval_links SET responded_at=NOW(3), response='approved', comments='Perfecto' WHERE id=$A2;" OK
valor "y la pieza NO se movio: la respuesta se registra, no decide" \
  "SELECT status FROM deliverables WHERE id=$D;" "approved"
porque "y ya no se puede reescribir" \
  "UPDATE approval_links SET response='changes_requested', comments='Ahora digo otra cosa' WHERE id=$A2;" "no se reescribe"
porque "ni anular lo que ya contesto" \
  "UPDATE approval_links SET revoked_at=NOW(3), revoked_reason='tarde' WHERE id=$A2;" "ck_apl_una_salida"

echo ""
echo "--- Un enlace que caduca antes de mandarse no sirve ---"
porque "el plazo al reves" \
  "UPDATE approval_links SET expires_at=sent_at - INTERVAL 1 DAY WHERE id=$A2;" "ck_apl_plazo"

echo ""
echo "--- La conformidad del cliente no se borra (3.12) ---"
porque "borrar el enlace contestado" \
  "DELETE FROM approval_links WHERE id=$A2;" "no admite borrado"
valor "y sigue ahi, que es el punto" \
  "SELECT COUNT(*) FROM approval_links WHERE deliverable_id=$D;" "2"

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
