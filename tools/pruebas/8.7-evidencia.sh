#!/bin/bash
# Pruebas de restriccion de la iteracion 8.7: la prueba de que el post existio.
#
#   tg_pub_verificada_con_evidencia  no se verifica sin captura archivada
#   ck_pev_screenshot                una captura sin archivo no es una captura
#   ck_pev_http                      y una sonda dice con que estado respondio
#   ck_pub_rejected                  rechazada dice cuando Y por que
#   ck_pub_permanence                la permanencia solo existe si se verifico
#   uq_pub_fingerprint (viva_gate)   una rechazada NO reclama el enlace
#   tg_pev_no_delete                 la evidencia no se borra (3.12)
#
# Uso: bash tools/pruebas/8.7-evidencia.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  8.7 - La prueba de que el post existio"
echo "==================================================================================="

MIA="(SELECT id FROM (SELECT cc.id FROM campaign_creators cc
        JOIN creators c ON c.id=cc.creator_id
       WHERE c.display_name='luisvega' AND cc.accepted_at IS NOT NULL LIMIT 1) m)"
REQ="(SELECT id FROM (SELECT id FROM campaign_requirements ORDER BY id LIMIT 1) x)"
USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"
IG="(SELECT id FROM (SELECT id FROM platforms WHERE code='instagram') p)"
ARCH="(SELECT id FROM (SELECT id FROM files ORDER BY id LIMIT 1) f)"

valor "la columna puerta de la huella existe" \
  "SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='publications' AND column_name='viva_gate';" "1"
valor "y ningun entregable mio de una pasada anterior" \
  "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA;" "0"

# --- Un entregable aprobado y publicado --------------------------------------
$CLIENTE $DB -e "INSERT INTO deliverables
   (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,status,due_on,submitted_at,created_at,updated_at)
   VALUES (UUID(),$MIA,$REQ,1,'submitted',CURDATE() + INTERVAL 7 DAY,NOW(3),NOW(3),NOW(3));" 2>&1 | grep -i error
D="(SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA) d)"
$CLIENTE $DB -e "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
   VALUES (UUID(),$D,1,'https://drive.example/x',NOW(3),NOW(3));" 2>&1 | grep -i error
V="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$D) v)"
$CLIENTE $DB -e "UPDATE deliverables SET status='approved', approved_at=NOW(3),
   approved_by_user_id=$USR, approved_version_id=$V WHERE id=$D;" 2>&1 | grep -i error
$CLIENTE $DB -e "INSERT INTO publications
   (uuid,deliverable_id,deliverable_version_id,platform_id,url,url_fingerprint,published_at,
    reported_by_user_id,status,created_at,updated_at)
   VALUES (UUID(),$D,$V,$IG,'https://instagram.com/p/ZZZ',SHA2('https://instagram.com/p/ZZZ',256),
           NOW(3),$USR,'reported',NOW(3),NOW(3));" 2>&1 | grep -i error
# `ORDER BY id LIMIT 1` desde el principio: mas abajo esta suite registra una
# SEGUNDA publicacion --el enlace que se vuelve a reclamar tras el rechazo-- y
# sin el limite este subselect empieza a devolver dos filas a mitad de suite,
# con un 1242 que no tiene nada que ver con lo que se estaba probando.
P="(SELECT id FROM (SELECT id FROM publications WHERE deliverable_id=$D ORDER BY id LIMIT 1) pp)"
valor "tengo una publicacion reportada" \
  "SELECT status FROM publications WHERE deliverable_id=$D;" "reported"

echo ""
echo "--- No se verifica sin captura archivada ---"
porque "darla por verificada sin nada archivado" \
  "UPDATE publications SET status='verified', verified_at=NOW(3), verified_by_user_id=$USR
    WHERE id=$P;" "sin una captura archivada"
# Una sonda HTTP NO basta: es justo lo que la decision de 8.7 dice que no prueba.
$CLIENTE $DB -e "INSERT INTO publication_evidence
   (uuid,publication_id,evidence_type,http_status,captured_at,captured_by_user_id,created_at)
   VALUES (UUID(),$P,'http_check',200,NOW(3),$USR,NOW(3));" 2>&1 | grep -i error
porque "ni con una sonda HTTP de 200" \
  "UPDATE publications SET status='verified', verified_at=NOW(3), verified_by_user_id=$USR
    WHERE id=$P;" "sin una captura archivada"

echo ""
echo "--- Una captura sin archivo no es una captura ---"
porque "archivar un screenshot sin archivo" \
  "INSERT INTO publication_evidence (uuid,publication_id,evidence_type,http_status,captured_at,created_at)
    VALUES (UUID(),$P,'screenshot',200,NOW(3),NOW(3));" "ck_pev_screenshot"
porque "ni una sonda sin estado" \
  "INSERT INTO publication_evidence (uuid,publication_id,evidence_type,raw_payload,captured_at,created_at)
    VALUES (UUID(),$P,'http_check','{}',NOW(3),NOW(3));" "ck_pev_http"
probar "archivar la captura, con su archivo" \
  "INSERT INTO publication_evidence (uuid,publication_id,evidence_type,file_id,captured_at,captured_by_user_id,created_at)
    VALUES (UUID(),$P,'screenshot',$ARCH,NOW(3),$USR,NOW(3));" OK

echo ""
echo "--- La permanencia solo existe si se verifico ---"
porque "poner permanencia sobre una reportada" \
  "UPDATE publications SET permanence_until=CURDATE() + INTERVAL 30 DAY WHERE id=$P;" "ck_pub_permanence"
probar "verificarla, ya con la captura" \
  "UPDATE publications SET status='verified', verified_at=NOW(3), verified_by_user_id=$USR,
     permanence_until=DATE(published_at) + INTERVAL 30 DAY WHERE id=$P;" OK
valor "y queda con su fecha de permanencia" \
  "SELECT permanence_until IS NOT NULL FROM publications WHERE id=$P;" "1"

echo ""
echo "--- Rechazada dice cuando Y por que ---"
$CLIENTE $DB -e "UPDATE publications SET status='reported', verified_at=NULL,
   verified_by_user_id=NULL, permanence_until=NULL WHERE id=$P;" 2>&1 | grep -i error
porque "rechazarla sin motivo" \
  "UPDATE publications SET status='rejected', verified_at=NOW(3), verified_by_user_id=$USR
    WHERE id=$P;" "ck_pub_rejected"
porque "ni con un motivo de tres letras" \
  "UPDATE publications SET status='rejected', verified_at=NOW(3), verified_by_user_id=$USR,
     rejected_reason='no' WHERE id=$P;" "ck_pub_rejected"
probar "rechazarla con su motivo" \
  "UPDATE publications SET status='rejected', verified_at=NOW(3), verified_by_user_id=$USR,
     rejected_reason='El enlace no lleva a ningun post' WHERE id=$P;" OK

echo ""
echo "--- Una rechazada NO reclama el enlace ---"
# El agujero que aparecio al conectar el rechazo: se le pide al creador que
# arregle el post y vuelva a registrar el MISMO enlace, y la clave unica global
# se lo impedia con un 1062. La columna puerta lo resuelve.
probar "registrar otra vez el mismo enlace" \
  "INSERT INTO publications
    (uuid,deliverable_id,deliverable_version_id,platform_id,url,url_fingerprint,published_at,
     reported_by_user_id,status,created_at,updated_at)
    VALUES (UUID(),$D,$V,$IG,'https://instagram.com/p/ZZZ',SHA2('https://instagram.com/p/ZZZ',256),
            NOW(3),$USR,'reported',NOW(3),NOW(3));" OK
valor "y ahora hay dos filas, una rechazada y una viva" \
  "SELECT COUNT(*) FROM publications WHERE deliverable_id=$D;" "2"
P2="(SELECT id FROM (SELECT id FROM publications WHERE deliverable_id=$D AND status='reported') q)"
porque "pero dos VIVAS con el mismo enlace, no" \
  "UPDATE publications SET status='reported', verified_at=NULL, rejected_reason=NULL
    WHERE deliverable_id=$D AND status='rejected';" "uq_pub_fingerprint"

echo ""
echo "--- La evidencia no se borra (3.12) ---"
porque "borrar la captura archivada" \
  "DELETE FROM publication_evidence WHERE publication_id=$P;" "no admite borrado"

echo ""
echo "--- Esta suite NO limpia, y es el punto ---"
# Las demas suites borran lo suyo al terminar. Esta no puede, y no es un olvido:
# `publication_evidence` lleva `no_delete` desde 3.12 --es la prueba de que se
# publico y de ella depende que se pague-- y su clave ajena impide ademas borrar
# la publicacion, que impide borrar el entregable. La cadena entera se queda.
#
# Por eso `8.7-evidencia` va LA ULTIMA en `tools/pruebas/SUITES`. Quien anada una
# suite despues la encontrara con filas de `luisvega` puestas, y la asercion de
# premisa de esa suite se lo dira --que es exactamente para lo que existe--.
porque "borrar la publicacion que tiene evidencia" \
  "DELETE FROM publications WHERE id=$P;" "fk_pev_publication"
valor "la evidencia sigue ahi, que es el punto" \
  "SELECT COUNT(*) > 0 FROM publication_evidence WHERE publication_id=$P;" "1"

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
