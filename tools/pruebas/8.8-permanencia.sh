#!/bin/bash
# Pruebas de restriccion de la iteracion 8.8: la permanencia minima del post.
#
#   tg_pc_publicacion_verificada  no se comprueba lo que nadie verifico
#   ck_pc_source / ck_pc_is_live  origen de lista cerrada, y 0 o 1
#   ck_pc_manual                  una comprobacion manual la firma alguien
#   ck_pc_caida_motivada          «no estaba» sin nada detras no vale
#   ck_pc_no_futuro               no se mira en el futuro
#   uq_pc_sonda_dia               una sonda por publicacion y dia (16a puerta)
#   tg_pub_permanencia            la caida exige comprobacion Y captura POSTERIOR
#   ck_pub_removed                y dice cuando, quien y por que
#   ck_pub_removed_no_antes       no se cae antes de publicarse
#   ck_pub_fulfilled              cumplida dice hasta cuando y cuando se cerro
#   ck_pub_status                 `expired` ya no existe: es `fulfilled`
#   ck_del_status                 el entregable caido tiene su propio estado
#   tg_cvw/tg_dv_entregable_abierto  y `removed` cuenta como cerrado
#   tg_pc_inmutable / tg_pc_no_delete  append-only y no se borra (3.12)
#
# Uso: bash tools/pruebas/8.8-permanencia.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  8.8 - La permanencia minima del post"
echo "==================================================================================="

# Esta suite trabaja sobre SU entregable, el numero 2 de la participacion de
# luisvega. `8.7` corre antes y deja el numero 1 puesto --no puede limpiarlo, y
# eso es su punto--, asi que aqui se elige por `sequence_number` y no por
# participacion: dos suites que comparten fila se pisan, y la que falla es la
# segunda, por un motivo que no tiene nada que ver con lo que probaba.
MIA="(SELECT id FROM (SELECT cc.id FROM campaign_creators cc
        JOIN creators c ON c.id=cc.creator_id
       WHERE c.display_name='luisvega' AND cc.accepted_at IS NOT NULL LIMIT 1) m)"
REQ="(SELECT id FROM (SELECT id FROM campaign_requirements ORDER BY id LIMIT 1) x)"
USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"
IG="(SELECT id FROM (SELECT id FROM platforms WHERE code='instagram') p)"
ARCH="(SELECT id FROM (SELECT id FROM files ORDER BY id LIMIT 1) f)"

valor "la columna puerta de la sonda existe" \
  "SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='permanence_checks' AND column_name='sonda_dia';" "1"
valor "y ningun entregable mio de una pasada anterior" \
  "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA AND sequence_number=2;" "0"

# --- Mi cadena: entregable 2, aprobado, publicado hace 40 dias ---------------
#
# Publicado hace 40 dias y con 30 de permanencia: la ventana cerro hace 10. Se
# elige asi para que `fulfilled` sea comprobable sin tocar el reloj, que es lo
# que dejo viva la mutacion «la permanencia se cuenta desde hoy» en 8.7.
$CLIENTE $DB -e "INSERT INTO deliverables
   (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,status,due_on,submitted_at,created_at,updated_at)
   VALUES (UUID(),$MIA,$REQ,2,'submitted',CURDATE() + INTERVAL 7 DAY,NOW(3),NOW(3),NOW(3));" 2>&1 | grep -i error
D="(SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA AND sequence_number=2) d)"
$CLIENTE $DB -e "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
   VALUES (UUID(),$D,1,'https://drive.example/perm',NOW(3),NOW(3));" 2>&1 | grep -i error
V="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$D) v)"
$CLIENTE $DB -e "UPDATE deliverables SET status='approved', approved_at=NOW(3),
   approved_by_user_id=$USR, approved_version_id=$V WHERE id=$D;" 2>&1 | grep -i error
$CLIENTE $DB -e "INSERT INTO publications
   (uuid,deliverable_id,deliverable_version_id,platform_id,url,url_fingerprint,published_at,
    reported_by_user_id,status,created_at,updated_at)
   VALUES (UUID(),$D,$V,$IG,'https://instagram.com/p/PERM8',SHA2('https://instagram.com/p/PERM8',256),
           NOW(3) - INTERVAL 40 DAY,$USR,'reported',NOW(3),NOW(3));" 2>&1 | grep -i error
P="(SELECT id FROM (SELECT id FROM publications WHERE deliverable_id=$D ORDER BY id LIMIT 1) pp)"
valor "tengo mi publicacion reportada" \
  "SELECT status FROM publications WHERE deliverable_id=$D;" "reported"

echo ""
echo "--- No se comprueba la permanencia de lo que nadie verifico ---"
porque "anotar una comprobacion sobre una reportada" \
  "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,checked_by_user_id,created_at)
    VALUES (UUID(),$P,'manual',NOW(3),1,$USR,NOW(3));" "que nadie verifico"

# Verificarla. `verified_at` explicitamente en el pasado y no `NOW(3)`: mas
# abajo hace falta una captura POSTERIOR a la verificacion, y con los dos en el
# mismo milisegundo la comparacion depende de la suerte. Es `T-39`.
$CLIENTE $DB -e "INSERT INTO publication_evidence (uuid,publication_id,evidence_type,file_id,captured_at,captured_by_user_id,created_at)
   VALUES (UUID(),$P,'screenshot',$ARCH,NOW(3) - INTERVAL 39 DAY,$USR,NOW(3));" 2>&1 | grep -i error
$CLIENTE $DB -e "UPDATE publications SET status='verified', verified_at=NOW(3) - INTERVAL 39 DAY,
   verified_by_user_id=$USR, permanence_until=DATE(published_at) + INTERVAL 30 DAY WHERE id=$P;" 2>&1 | grep -i error
valor "verificada, con su ventana ya vencida" \
  "SELECT status = 'verified' AND permanence_until < CURDATE() FROM publications WHERE id=$P;" "1"

echo ""
echo "--- Una comprobacion dice de donde sale, que vio y quien miro ---"
porque "un origen inventado" \
  "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,checked_by_user_id,created_at)
    VALUES (UUID(),$P,'sonda',NOW(3),1,$USR,NOW(3));" "ck_pc_source"
# Con `notes` puesto a proposito: sin ellas un 7 viola TAMBIEN
# `ck_pc_caida_motivada` --no es 1, y no trae nada detras-- y cual de los dos
# CHECK responde depende del motor. MariaDB contestaba `ck_pc_is_live` y MySQL 8
# `ck_pc_caida_motivada`: la asercion pasaba en un motor y fallaba en el otro
# sin que el esquema tuviera nada malo. Es `T-48` otra vez, y ahora `T-51`.
porque "un is_live que no es ni 0 ni 1" \
  "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,checked_by_user_id,notes,created_at)
    VALUES (UUID(),$P,'manual',NOW(3),7,$USR,'Lo mire y no se que poner',NOW(3));" "ck_pc_is_live"
porque "una manual sin firma" \
  "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,created_at)
    VALUES (UUID(),$P,'manual',NOW(3),1,NOW(3));" "ck_pc_manual"
porque "«no estaba» sin decir que se vio" \
  "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,checked_by_user_id,created_at)
    VALUES (UUID(),$P,'manual',NOW(3),0,$USR,NOW(3));" "ck_pc_caida_motivada"
porque "ni con una nota de tres letras" \
  "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,checked_by_user_id,notes,created_at)
    VALUES (UUID(),$P,'manual',NOW(3),0,$USR,'no',NOW(3));" "ck_pc_caida_motivada"
porque "ni una comprobacion hecha manana" \
  "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,checked_by_user_id,created_at)
    VALUES (UUID(),$P,'manual',NOW(3) + INTERVAL 1 DAY,1,$USR,NOW(3));" "ck_pc_no_futuro"

echo ""
echo "--- Una sonda por publicacion y dia: la decimosexta columna puerta ---"
probar "la sonda anota que el post sigue ahi" \
  "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,created_at)
    VALUES (UUID(),$P,'probe',NOW(3),1,NOW(3));" OK
# El cron duplicado --dos servidores, o alguien que lo ejecuta a mano para ver
# si funciona-- meteria la misma comprobacion dos veces y mandaria dos correos.
porque "y la misma sonda otra vez el mismo dia, no" \
  "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,created_at)
    VALUES (UUID(),$P,'probe',NOW(3),1,NOW(3));" "uq_pc_sonda_dia"
probar "pero una persona puede mirar las veces que quiera" \
  "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,checked_by_user_id,created_at)
    VALUES (UUID(),$P,'manual',NOW(3),1,$USR,NOW(3));" OK
probar "y otra vez" \
  "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,checked_by_user_id,created_at)
    VALUES (UUID(),$P,'manual',NOW(3),1,$USR,NOW(3));" OK

echo ""
echo "--- Una caida exige una comprobacion que la respalde ---"
porque "darla por caida sin ninguna comprobacion fallida" \
  "UPDATE publications SET status='removed', removed_at=NOW(3), removed_by_user_id=$USR,
     removed_reason='El creador borro el post' WHERE id=$P;" "una comprobacion que diga que no esta"
$CLIENTE $DB -e "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,checked_by_user_id,notes,created_at)
   VALUES (UUID(),$P,'manual',NOW(3),0,$USR,'El enlace devuelve una pagina de no encontrado',NOW(3));" 2>&1 | grep -i error
# Y la captura vieja NO sirve: probo que el post existia, no que ya no este.
porque "ni con la captura que probo que existia" \
  "UPDATE publications SET status='removed', removed_at=NOW(3), removed_by_user_id=$USR,
     removed_reason='El creador borro el post' WHERE id=$P;" "captura tomada DESPUES"
$CLIENTE $DB -e "INSERT INTO publication_evidence (uuid,publication_id,evidence_type,file_id,captured_at,captured_by_user_id,created_at)
   VALUES (UUID(),$P,'screenshot',$ARCH,NOW(3),$USR,NOW(3));" 2>&1 | grep -i error

echo ""
echo "--- Y dice cuando, quien la firma y por que ---"
porque "caida sin motivo ni firma" \
  "UPDATE publications SET status='removed', removed_at=NOW(3) WHERE id=$P;" "ck_pub_removed"
porque "ni con un motivo de tres letras" \
  "UPDATE publications SET status='removed', removed_at=NOW(3), removed_by_user_id=$USR,
     removed_reason='no' WHERE id=$P;" "ck_pub_removed"
porque "ni retirada antes de publicarse" \
  "UPDATE publications SET status='removed', removed_at=NOW(3) - INTERVAL 90 DAY,
     removed_by_user_id=$USR, removed_reason='El creador borro el post' WHERE id=$P;" "ck_pub_removed_no_antes"
probar "la caida, con todo lo que hace falta" \
  "UPDATE publications SET status='removed', removed_at=NOW(3), removed_by_user_id=$USR,
     removed_reason='El creador borro el post' WHERE id=$P;" OK

echo ""
echo "--- El entregable caido tiene su propio estado, y cuenta como cerrado ---"
probar "el entregable pasa a removed" \
  "UPDATE deliverables SET status='removed' WHERE id=$D;" OK
porque "y ya no admite una version nueva encima" \
  "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
    VALUES (UUID(),$D,2,'https://drive.example/otra',NOW(3),NOW(3));" "entregable cerrado"
porque "ni un veredicto sobre el" \
  "INSERT INTO content_reviews
    (uuid,deliverable_version_id,reviewer_user_id,reviewer_side,outcome,comments,consumes_round,reviewed_at,created_at)
    VALUES (UUID(),$V,$USR,'platform','approved',NULL,0,NOW(3),NOW(3));" "no admite mas veredictos"

echo ""
echo "--- Reponer exige una captura POSTERIOR a la caida ---"
porque "devolverla a vigilada con lo que ya habia" \
  "UPDATE publications SET status='verified', removed_at=NULL, removed_reason=NULL,
     removed_by_user_id=NULL WHERE id=$P;" "captura posterior a la caida"
$CLIENTE $DB -e "INSERT INTO publication_evidence (uuid,publication_id,evidence_type,file_id,captured_at,captured_by_user_id,created_at)
   VALUES (UUID(),$P,'screenshot',$ARCH,NOW(3) + INTERVAL 1 MINUTE,$USR,NOW(3));" 2>&1 | grep -i error
probar "con la captura de ahora, si" \
  "UPDATE publications SET status='verified', removed_at=NULL, removed_reason=NULL,
     removed_by_user_id=NULL WHERE id=$P;" OK
$CLIENTE $DB -e "UPDATE deliverables SET status='verified' WHERE id=$D;" 2>&1 | grep -i error

echo ""
echo "--- La ventana no se cierra antes de su fecha ---"
$CLIENTE $DB -e "UPDATE publications SET permanence_until=CURDATE() + INTERVAL 5 DAY WHERE id=$P;" 2>&1 | grep -i error
porque "darla por cumplida faltando cinco dias" \
  "UPDATE publications SET status='fulfilled', fulfilled_at=NOW(3) WHERE id=$P;" "antes de su fecha"
$CLIENTE $DB -e "UPDATE publications SET permanence_until=DATE(published_at) + INTERVAL 30 DAY WHERE id=$P;" 2>&1 | grep -i error
porque "ni sin decir cuando se cerro" \
  "UPDATE publications SET status='fulfilled' WHERE id=$P;" "ck_pub_fulfilled"
probar "cumplida, con la ventana ya vencida" \
  "UPDATE publications SET status='fulfilled', fulfilled_at=NOW(3) WHERE id=$P;" OK
valor "y el estado es fulfilled, no expired" \
  "SELECT status FROM publications WHERE id=$P;" "fulfilled"
# `permanence_until` a NULL en el mismo UPDATE, por lo mismo: dejandola puesta,
# `expired` viola tambien `ck_pub_permanence` --ya no esta en su lista-- y gana
# uno u otro segun el motor.
porque "«expired» ya no es un estado de publicacion" \
  "UPDATE publications SET status='expired', permanence_until=NULL, fulfilled_at=NULL WHERE id=$P;" "ck_pub_status"

echo ""
echo "--- Una comprobacion no se edita ni se borra (3.12) ---"
porque "corregir una comprobacion" \
  "UPDATE permanence_checks SET is_live=1 WHERE publication_id=$P AND is_live=0;" "no se edita"
porque "borrarla" \
  "DELETE FROM permanence_checks WHERE publication_id=$P;" "no admite borrado"

echo ""
echo "--- Esta suite tampoco limpia, y por el mismo motivo que 8.7 ---"
# `permanence_checks` acaba de entrar en la lista de 3.12: de estas filas depende
# que un pago se pare, y de eso se discute despues. Su clave ajena impide ademas
# borrar la publicacion, que impide borrar el entregable. La cadena se queda, y
# por eso `8.8-permanencia` va LA ULTIMA en `tools/pruebas/SUITES`.
valor "las comprobaciones siguen ahi, que es el punto" \
  "SELECT COUNT(*) > 0 FROM permanence_checks WHERE publication_id=$P;" "1"

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
