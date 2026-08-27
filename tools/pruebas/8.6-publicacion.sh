#!/bin/bash
# Pruebas de restriccion de la iteracion 8.6: el post publicado.
#
#   tg_pub_version_aprobada       solo se publica lo aprobado, y esa version
#   fk_pub_version                y la version tiene que ser DEL entregable
#   uq_pub_fingerprint            el mismo post no se reclama dos veces
#   ck_pub_published_no_futuro    un post no se publica manana
#   ck_pub_fingerprint            la huella mide 64
#   ck_pub_rejected               rechazada dice cuando se reviso (y por que, desde 8.7)
#
# Misma disciplina que 8.1, 8.2 y 8.3: trabaja sobre SU participacion.
#
# Uso: bash tools/pruebas/8.6-publicacion.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

ok=0; fail=0
probar() {
  salida=$($CLIENTE $DB -e "$2" 2>&1)
  if echo "$salida" | grep -qiE "ERROR (2002|2003|2005|1045|1049)|Can't connect|Unknown database|Access denied"; then
    printf "  \033[31m!\033[0m %-70s LA BASE NO RESPONDE\n" "$1"
    echo "      $(echo "$salida" | grep -i error | head -1)"
    fail=$((fail+1)); return
  fi
  if [ -z "$salida" ] || ! echo "$salida" | grep -qi "ERROR"; then real="OK"; else real="RECHAZO"; fi
  if [ "$real" == "$3" ]; then printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$real"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-70s esperaba %s, obtuvo %s\n" "$1" "$3" "$real"; echo "      $(echo "$salida"|grep -i error|head -1)"; fail=$((fail+1)); fi
}
valor() {
  real=$($CLIENTE $DB -N -B -e "$2" 2>&1 | grep -v '^mysql: \[Warning\]' | tr -d '\r')
  if [ "$real" == "$3" ]; then printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$real"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-70s esperaba '%s', obtuvo '%s'\n" "$1" "$3" "$real"; fail=$((fail+1)); fi
}
porque() {
  salida=$($CLIENTE $DB -e "$2" 2>&1)
  if echo "$salida" | grep -q "$3"; then printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$3"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-70s esperaba rechazo por '%s'\n" "$1" "$3"
       echo "      $(echo "$salida"|grep -i error|head -1)"; fail=$((fail+1)); fi
}

echo ""
echo "==================================================================================="
echo "  8.6 - El post publicado"
echo "==================================================================================="

MIA="(SELECT id FROM (SELECT cc.id FROM campaign_creators cc
        JOIN creators c ON c.id=cc.creator_id
       WHERE c.display_name='luisvega' AND cc.accepted_at IS NOT NULL LIMIT 1) m)"
REQ="(SELECT id FROM (SELECT id FROM campaign_requirements ORDER BY id LIMIT 1) x)"
USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"
IG="(SELECT id FROM (SELECT id FROM platforms WHERE code='instagram') p)"

valor "la columna de version publicada existe" \
  "SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='publications'
      AND column_name='deliverable_version_id';" "1"
valor "y ningun entregable mio de una pasada anterior" \
  "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA;" "0"

# --- Dos entregables mios, con una version cada uno; solo el primero aprobado --
$CLIENTE $DB -e "INSERT INTO deliverables
   (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,status,due_on,submitted_at,created_at,updated_at)
   VALUES (UUID(),$MIA,$REQ,1,'submitted',CURDATE() + INTERVAL 7 DAY,NOW(3),NOW(3),NOW(3)),
          (UUID(),$MIA,$REQ,2,'submitted',CURDATE() + INTERVAL 7 DAY,NOW(3),NOW(3),NOW(3));" 2>&1 | grep -i error
A="(SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA AND sequence_number=1) a)"
B="(SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA AND sequence_number=2) b)"
$CLIENTE $DB -e "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
   VALUES (UUID(),$A,1,'https://drive.example/a1',NOW(3),NOW(3)),
          (UUID(),$A,2,'https://drive.example/a2',NOW(3),NOW(3)),
          (UUID(),$B,1,'https://drive.example/b1',NOW(3),NOW(3));" 2>&1 | grep -i error
VA1="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$A AND version_number=1) x)"
VA2="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$A AND version_number=2) y)"
VB="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$B AND version_number=1) z)"

# `$1` entregable, `$2` version, `$3` url, `$4` huella, `$5` cuando
publicar() {
  echo "INSERT INTO publications
    (uuid,deliverable_id,deliverable_version_id,platform_id,url,url_fingerprint,published_at,
     reported_by_user_id,status,created_at,updated_at)
    VALUES (UUID(),$1,$2,$IG,'$3',$4,$5,$USR,'reported',NOW(3),NOW(3));"
}
HUELLA_A="SHA2('https://instagram.com/p/AAA',256)"
HUELLA_B="SHA2('https://instagram.com/p/BBB',256)"

echo ""
echo "--- Solo se publica lo aprobado ---"
porque "un post sobre un entregable sin aprobar" \
  "$(publicar "$A" "$VA2" 'https://instagram.com/p/AAA' "$HUELLA_A" 'NOW(3)')" "Solo se publica lo aprobado"

$CLIENTE $DB -e "UPDATE deliverables SET status='approved', approved_at=NOW(3),
   approved_by_user_id=$USR, approved_version_id=$VA2 WHERE id=$A;" 2>&1 | grep -i error
valor "aprobado el primero, en su version dos" \
  "SELECT approved_version_id=$VA2 FROM deliverables WHERE id=$A;" "1"

echo ""
echo "--- Y esa version, no otra ---"
porque "publicar la version UNO cuando la aprobada es la dos" \
  "$(publicar "$A" "$VA1" 'https://instagram.com/p/AAA' "$HUELLA_A" 'NOW(3)')" "la version aprobada"
# Al INSERTAR gana el disparador: la version de otro entregable nunca puede ser
# la aprobada de este --`fk_del_approved_version` (8.2) tambien es compuesta--
# asi que el rechazo llega antes por ahi. Se afirma lo que PASA y no lo que uno
# quisiera que pasara.
porque "publicar la version de OTRO entregable" \
  "$(publicar "$A" "$VB" 'https://instagram.com/p/AAA' "$HUELLA_A" 'NOW(3)')" "la version aprobada"
probar "publicar la aprobada, bien" \
  "$(publicar "$A" "$VA2" 'https://instagram.com/p/AAA' "$HUELLA_A" 'NOW(3)')" OK
# Y aqui SI se ve la clave ajena compuesta sola: el disparador es BEFORE INSERT y
# no mira los UPDATE, asi que mover el puntero de una publicacion ya escrita a la
# version de otro entregable solo lo impide la clave. Sin la segunda columna, esa
# fila diria que se publico algo que no era de ese entregable.
porque "mover una publicacion a la version de otro entregable" \
  "UPDATE publications SET deliverable_version_id=$VB WHERE deliverable_id=$A;" "fk_pub_version" 

echo ""
echo "--- El mismo post no se reclama dos veces ---"
$CLIENTE $DB -e "UPDATE deliverables SET status='approved', approved_at=NOW(3),
   approved_by_user_id=$USR, approved_version_id=$VB WHERE id=$B;" 2>&1 | grep -i error
porque "otro entregable reclamando el mismo post" \
  "$(publicar "$B" "$VB" 'https://instagram.com/p/AAA?utm_source=ig' "$HUELLA_A" 'NOW(3)')" "uq_pub_fingerprint"
probar "otro entregable con SU post" \
  "$(publicar "$B" "$VB" 'https://instagram.com/p/BBB' "$HUELLA_B" 'NOW(3)')" OK

echo ""
echo "--- Un post no se publica manana ---"
$CLIENTE $DB -e "DELETE FROM publications WHERE deliverable_id=$B;" 2>&1 | grep -i error
porque "con la fecha de publicacion en el futuro" \
  "$(publicar "$B" "$VB" 'https://instagram.com/p/CCC' "SHA2('c',256)" 'NOW(3) + INTERVAL 2 DAY')" "ck_pub_published_no_futuro"
porque "con una huella que no mide 64" \
  "$(publicar "$B" "$VB" 'https://instagram.com/p/CCC' "'corta'" 'NOW(3)')" "ck_pub_fingerprint"

echo ""
echo "--- Rechazada dice cuando se reviso ---"
probar "registrar el post de B" \
  "$(publicar "$B" "$VB" 'https://instagram.com/p/BBB' "$HUELLA_B" 'NOW(3)')" OK
porque "marcarla rechazada sin fecha de revision" \
  "UPDATE publications SET status='rejected' WHERE deliverable_id=$B;" "ck_pub_rejected"
# Y con el motivo: desde 8.7 `ck_pub_rejected` exige tambien el POR QUE, que es
# lo que el creador necesita para arreglarlo.
probar "y con ella y con su motivo" \
  "UPDATE publications SET status='rejected', verified_at=NOW(3),
     rejected_reason='El enlace no lleva a ningun post' WHERE deliverable_id=$B;" OK

echo ""
echo "--- Lo publicado no se toca por debajo ---"
porque "borrar la version publicada" \
  "DELETE FROM deliverable_versions WHERE id=$VA2;" "fk_"
porque "meter una version nueva sobre lo aprobado" \
  "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
    VALUES (UUID(),$A,9,'https://drive.example/a9',NOW(3),NOW(3));" "entregable cerrado"

echo ""
echo "--- La limpieza, comprobada ---"
$CLIENTE $DB -e "DELETE FROM publications WHERE deliverable_id IN
   (SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA) z);" 2>&1 | grep -i error
$CLIENTE $DB -e "UPDATE deliverables SET status='in_review', approved_at=NULL,
   approved_by_user_id=NULL, approved_version_id=NULL WHERE campaign_creator_id=$MIA;" 2>&1 | grep -i error
$CLIENTE $DB -e "DELETE FROM deliverable_versions WHERE deliverable_id IN
   (SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA) z);" 2>&1 | grep -i error
$CLIENTE $DB -e "DELETE FROM deliverables WHERE campaign_creator_id=$MIA;" 2>&1 | grep -i error
valor "no quedan entregables mios" \
  "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA;" "0"
valor "ni publicaciones mias" \
  "SELECT COUNT(*) FROM publications p JOIN deliverables d ON d.id=p.deliverable_id
    WHERE d.campaign_creator_id=$MIA;" "0"

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
