#!/bin/bash
# Pruebas de restriccion de la iteracion 8.2: que version es la buena.
#
#   fk_del_approved_version    el puntero apunta a una version DE ESE entregable
#   ck_del_approved_version    aprobado y puntero van juntos o no van
#   tg_dv_entregable_abierto   no se entrega sobre un entregable cerrado
#   tg_cvw_entregable_abierto  ni se opina, salvo para reabrirlo
#   ck_cvw_outcome             `reopened` es un veredicto
#   ck_cvw_comments            y reabrir tambien exige decir por que
#
# Misma disciplina que 8.1 y 8.3: trabaja sobre SU participacion --la de
# `luisvega`, elegida por nombre-- y no exige la tabla vacia.
#
# Uso: bash tools/pruebas/8.2-version-vigente.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  8.2 - Que version es la buena"
echo "==================================================================================="

MIA="(SELECT id FROM (SELECT cc.id FROM campaign_creators cc
        JOIN creators c ON c.id=cc.creator_id
       WHERE c.display_name='luisvega' AND cc.accepted_at IS NOT NULL LIMIT 1) m)"
REQ="(SELECT id FROM (SELECT id FROM campaign_requirements ORDER BY id LIMIT 1) x)"
USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"

valor "la columna del puntero existe" \
  "SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='deliverables'
      AND column_name='approved_version_id';" "1"
valor "y ningun entregable mio de una pasada anterior" \
  "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA;" "0"

# --- Dos entregables mios, cada uno con su version ---------------------------
$CLIENTE $DB -e "INSERT INTO deliverables
   (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,status,due_on,submitted_at,created_at,updated_at)
   VALUES (UUID(),$MIA,$REQ,1,'submitted',CURDATE() + INTERVAL 7 DAY,NOW(3),NOW(3),NOW(3)),
          (UUID(),$MIA,$REQ,2,'submitted',CURDATE() + INTERVAL 7 DAY,NOW(3),NOW(3),NOW(3));" 2>&1 | grep -i error
A="(SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA AND sequence_number=1) a)"
B="(SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA AND sequence_number=2) b)"
$CLIENTE $DB -e "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
   VALUES (UUID(),$A,1,'https://drive.example/a1',NOW(3),NOW(3)),
          (UUID(),$B,1,'https://drive.example/b1',NOW(3),NOW(3));" 2>&1 | grep -i error
VA="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$A AND version_number=1) va)"
VB="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$B AND version_number=1) vb)"
valor "tengo dos entregables con una version cada uno" \
  "SELECT COUNT(*) FROM deliverable_versions v JOIN deliverables d ON d.id=v.deliverable_id
    WHERE d.campaign_creator_id=$MIA;" "2"

echo ""
echo "--- Aprobado y puntero van juntos o no van ---"
porque "aprobar sin decir que version" \
  "UPDATE deliverables SET status='approved', approved_at=NOW(3), approved_by_user_id=$USR
    WHERE id=$A;" "ck_del_approved_version"
porque "apuntar a una version sin estar aprobado" \
  "UPDATE deliverables SET approved_version_id=$VA WHERE id=$A;" "ck_del_approved_version"

echo ""
echo "--- Y el puntero tiene que ser de ESE entregable ---"
# Aqui esta casi todo el valor de la clave ajena COMPUESTA: la version B existe,
# asi que una clave simple la aceptaria sin pestanear.
porque "apuntar a la version aprobada de OTRO entregable" \
  "UPDATE deliverables SET status='approved', approved_at=NOW(3), approved_by_user_id=$USR,
     approved_version_id=$VB WHERE id=$A;" "fk_del_approved_version"
probar "apuntar a la suya, bien" \
  "UPDATE deliverables SET status='approved', approved_at=NOW(3), approved_by_user_id=$USR,
     approved_version_id=$VA WHERE id=$A;" OK
valor "y queda apuntada" \
  "SELECT approved_version_id=$VA FROM deliverables WHERE id=$A;" "1"

echo ""
echo "--- Sobre un entregable cerrado no se entrega ---"
porque "una version nueva sobre lo ya aprobado" \
  "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
    VALUES (UUID(),$A,2,'https://drive.example/a2',NOW(3),NOW(3));" "entregable cerrado"

echo ""
echo "--- Ni se opina, salvo para reabrirlo ---"
revision() {
  echo "INSERT INTO content_reviews
    (uuid,deliverable_version_id,reviewer_user_id,reviewer_side,outcome,comments,consumes_round,reviewed_at,created_at)
    VALUES (UUID(),$1,$USR,'platform','$2',$3,0,NOW(3),NOW(3));"
}
porque "otro veredicto sobre lo ya aprobado" \
  "$(revision "$VA" approved NULL)" "no admite mas veredictos"
porque "reabrirlo sin decir por que" \
  "$(revision "$VA" reopened NULL)" "ck_cvw_comments"
porque "ni con un punto por motivo" \
  "$(revision "$VA" reopened "'.'")" "ck_cvw_comments"
probar "reabrirlo con su motivo" \
  "$(revision "$VA" reopened "'El cliente cambio el claim de la campana.'")" OK

echo ""
echo "--- Reabierto, vuelve a admitir trabajo ---"
probar "quitar la aprobacion y el puntero a la vez" \
  "UPDATE deliverables SET status='in_review', approved_at=NULL, approved_by_user_id=NULL,
     approved_version_id=NULL WHERE id=$A;" OK
probar "y ahora si entra la version dos" \
  "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
    VALUES (UUID(),$A,2,'https://drive.example/a2',NOW(3),NOW(3));" OK
valor "la reapertura queda en el historial" \
  "SELECT COUNT(*) FROM content_reviews WHERE deliverable_version_id=$VA AND outcome='reopened';" "1"

echo ""
echo "--- La version aprobada no se borra por debajo ---"
probar "aprobar la version dos" \
  "UPDATE deliverables SET status='approved', approved_at=NOW(3), approved_by_user_id=$USR,
     approved_version_id=(SELECT id FROM (SELECT id FROM deliverable_versions
        WHERE deliverable_id=$A AND version_number=2) v2) WHERE id=$A;" OK
porque "borrar la version que esta apuntada" \
  "DELETE FROM deliverable_versions WHERE deliverable_id=$A AND version_number=2;" "fk_del_approved_version"

echo ""
echo "--- La limpieza, comprobada ---"
$CLIENTE $DB -e "UPDATE deliverables SET status='in_review', approved_at=NULL,
   approved_by_user_id=NULL, approved_version_id=NULL WHERE campaign_creator_id=$MIA;" 2>&1 | grep -i error
$CLIENTE $DB -e "DELETE FROM content_reviews WHERE deliverable_version_id IN
   (SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id IN
     (SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA) z)) y);" 2>&1 | grep -i error
$CLIENTE $DB -e "DELETE FROM deliverable_versions WHERE deliverable_id IN
   (SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA) z);" 2>&1 | grep -i error
$CLIENTE $DB -e "DELETE FROM deliverables WHERE campaign_creator_id=$MIA;" 2>&1 | grep -i error
valor "no quedan entregables mios" \
  "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA;" "0"
valor "y lo de 2.12 sigue donde estaba" \
  "SELECT COUNT(*) > 0 FROM deliverables;" "1"

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
