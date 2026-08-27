#!/bin/bash
# Pruebas de restriccion de la iteracion 8.3: la revision.
#
#   ck_cvw_comments          pedir cambios exige decir cuales
#   ck_cvw_firma             una revision NUESTRA la firma alguien
#   ck_cvw_over              una ronda de mas exige decidir y firmar
#   ck_cvw_billing           y sin exceso no hay decision que tomar
#   ck_cvw_over_es_ronda     solo una ronda del cliente puede pasarse
#   ck_cvw_billing_valor     cobrar o absorber, no otra cosa
#   ck_del_aprobador         aprobado dice tambien QUIEN
#   tg_cvw_ultima_version    no se revisa lo que el creador ya reemplazo
#   tg_cvw_entregable_abierto un veredicto no se edita: no hay mas veredictos
#
# Misma disciplina que 8.1 y por la misma razon: esta suite trabaja sobre SU
# participacion --la de `luisvega`, elegida por nombre-- y no exige la tabla
# vacia. 2.12 deja filas de `anatorres` a proposito y esta suite no las estorba.
#
# Uso: bash tools/pruebas/8.3-revision.sh <base>
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
# Un RECHAZO solo prueba algo si rechaza por SU motivo (`T-43`).
porque() {
  salida=$($CLIENTE $DB -e "$2" 2>&1)
  if echo "$salida" | grep -q "$3"; then printf "  \033[32m✓\033[0m %-70s %s\n" "$1" "$3"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-70s esperaba rechazo por '%s'\n" "$1" "$3"
       echo "      $(echo "$salida"|grep -i error|head -1)"; fail=$((fail+1)); fi
}

echo ""
echo "==================================================================================="
echo "  8.3 - La revision"
echo "==================================================================================="

MIA="(SELECT id FROM (SELECT cc.id FROM campaign_creators cc
        JOIN creators c ON c.id=cc.creator_id
       WHERE c.display_name='luisvega' AND cc.accepted_at IS NOT NULL LIMIT 1) m)"
REQ="(SELECT id FROM (SELECT id FROM campaign_requirements ORDER BY id LIMIT 1) x)"
USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"

valor "la tabla de revisiones existe" \
  "SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_name='content_reviews';" "1"
valor "y ninguna revision mia de una pasada anterior" \
  "SELECT COUNT(*) FROM content_reviews r
     JOIN deliverable_versions v ON v.id=r.deliverable_version_id
     JOIN deliverables d ON d.id=v.deliverable_id
    WHERE d.campaign_creator_id=$MIA;" "0"
valor "el contador de rondas vive en deliverables, no en campaign_creators" \
  "SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND column_name='revision_rounds_used';" "1"
valor "y esa unica columna esta en deliverables" \
  "SELECT table_name FROM information_schema.columns
    WHERE table_schema=DATABASE() AND column_name='revision_rounds_used';" "deliverables"

# --- El entregable y sus dos versiones -------------------------------------
$CLIENTE $DB -e "INSERT INTO deliverables
   (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,status,due_on,submitted_at,created_at,updated_at)
   VALUES (UUID(),$MIA,$REQ,1,'submitted',CURDATE() + INTERVAL 7 DAY,NOW(3),NOW(3),NOW(3));" 2>&1 | grep -i error
DEL="(SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA ORDER BY id LIMIT 1) x)"
$CLIENTE $DB -e "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
   VALUES (UUID(),$DEL,1,'https://drive.example/v1',NOW(3),NOW(3));" 2>&1 | grep -i error
V1="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$DEL AND version_number=1) a)"
valor "tengo un entregable con su version uno" \
  "SELECT COUNT(*) FROM deliverable_versions WHERE deliverable_id=$DEL;" "1"

# Argumentos, todos posicionales y todos obligatorios menos los dos ultimos:
#   $1 version   $2 veredicto   $3 comentario (SQL)   $4 lado
#   $5 firma (SQL)   $6 consume   $7 columnas extra   $8 valores extra
#
# La primera version los tenia en otro orden y con la version al final, opcional.
# Con `set -u` eso dio `$8: unbound variable` en cinco aserciones --que aun asi
# imprimieron un veredicto, porque el error iba a stderr y el `probar` de al lado
# leia otra cosa-- y ademas una llamada con `''` de relleno hacia que `${7:-$V1}`
# cayera al valor por omision: media suite revisaba la version equivocada
# creyendo que revisaba la otra. Posicionales fijos y sin huecos.
revision() {
  extra_col="${7:-}"; extra_val="${8:-}"
  echo "INSERT INTO content_reviews
    (uuid,deliverable_version_id,reviewer_user_id,reviewer_side,outcome,comments,consumes_round,reviewed_at,created_at${extra_col:+,$extra_col})
    VALUES (UUID(),$1,$5,'$4','$2',$3,$6,NOW(3),NOW(3)${extra_val:+,$extra_val});"
}

echo ""
echo "--- Pedir cambios exige decir cuales ---"
porque "una correccion sin comentario" \
  "$(revision "$V1" changes_requested NULL platform "$USR" 0)" "ck_cvw_comments"
porque "ni con un punto por comentario" \
  "$(revision "$V1" changes_requested "'.'" platform "$USR" 0)" "ck_cvw_comments"
probar "una correccion interna, con su motivo" \
  "$(revision "$V1" changes_requested "'El logo se ve cortado en el segundo 4.'" platform "$USR" 0)" OK
probar "y una aprobacion no necesita texto" \
  "$(revision "$V1" approved NULL platform "$USR" 0)" OK

echo ""
echo "--- Una revision NUESTRA va firmada ---"
porque "interna sin usuario" \
  "$(revision "$V1" approved NULL platform NULL 0)" "ck_cvw_firma"
# La del cliente puede no tenerlo: en 8.5 la escribe un enlace firmado.
probar "la del cliente puede no tenerlo" \
  "$(revision "$V1" changes_requested "'Prefieren el plano abierto.'" client NULL 1)" OK

echo ""
echo "--- Solo la correccion consume ronda ---"
porque "una aprobacion que pretende consumir ronda" \
  "$(revision "$V1" approved NULL platform "$USR" 1)" "ck_cvw_round"

echo ""
echo "--- Una ronda de mas exige decidir Y firmar ---"
# Con el contador TODAVIA a cero: quedan rondas incluidas, asi que declarar una
# decision de facturacion es lo que sobra, y de eso responde `ck_cvw_billing`.
porque "decidir sin que haya exceso" \
  "$(revision "$V1" changes_requested "'Segunda vuelta normal.'" client NULL 1 \
      "billing_decision,authorized_by_user_id" "'charge',$USR")" "ck_cvw_billing"

# Y a partir de aqui, la pieza con sus rondas GASTADAS, que es la premisa que
# estas aserciones daban por supuesta sin ponerla. Hasta 8.4 daba igual porque
# nada miraba el contador; ahora `tg_cvw_techo` lo mira, y una fila que dice
# «ronda de mas» con dos rondas libres es mentira. La premisa se escribe.
$CLIENTE $DB -e "UPDATE deliverables SET revision_rounds_used=2 WHERE id=$DEL;" 2>&1 | grep -i error
valor "la pieza ya gasto sus dos rondas incluidas" \
  "SELECT revision_rounds_used FROM deliverables WHERE id=$DEL;" "2"

porque "pasarse sin decir si se cobra" \
  "$(revision "$V1" changes_requested "'La tercera vuelta.'" client NULL 1 over_included 1)" "ck_cvw_over"
porque "una decision que no es ni cobrar ni absorber" \
  "$(revision "$V1" changes_requested "'La tercera vuelta.'" client NULL 1 \
      "over_included,billing_decision,authorized_by_user_id" "1,'regalar',$USR")" "ck_cvw_billing_valor"
porque "una ronda interna que pretende pasarse de las incluidas" \
  "$(revision "$V1" changes_requested "'Nuestra, no del cliente.'" platform "$USR" 0 \
      "over_included,billing_decision,authorized_by_user_id" "1,'charge',$USR")" "ck_cvw_over_es_ronda"
probar "la tercera vuelta, cobrada y firmada" \
  "$(revision "$V1" changes_requested "'La tercera vuelta, la pide el cliente.'" client NULL 1 \
      "over_included,billing_decision,authorized_by_user_id" "1,'charge',$USR")" OK
probar "o absorbida, tambien firmada" \
  "$(revision "$V1" changes_requested "'Esta la asumimos nosotros.'" client NULL 1 \
      "over_included,billing_decision,authorized_by_user_id" "1,'absorb',$USR")" OK

echo ""
echo "--- No se revisa lo que el creador ya reemplazo ---"
$CLIENTE $DB -e "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
   VALUES (UUID(),$DEL,2,'https://drive.example/v2',NOW(3),NOW(3));" 2>&1 | grep -i error
V2="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$DEL AND version_number=2) b)"
valor "ya hay una version dos" \
  "SELECT COUNT(*) FROM deliverable_versions WHERE deliverable_id=$DEL;" "2"
porque "revisar la version uno ahora" \
  "$(revision "$V1" approved NULL platform "$USR" 0)" "ya no es la ultima"
probar "revisar la version dos, si" \
  "$(revision "$V2" approved NULL platform "$USR" 0)" OK

echo ""
echo "--- Aprobado dice tambien QUIEN ---"
# CON el puntero, para que lo unico que falte sea la firma. Sin eso, MySQL
# rechazaba antes por `ck_del_approved_version` (8.2) y la asercion pasaba en
# MariaDB y fallaba en MySQL: el orden de evaluacion de los CHECK no es el mismo,
# y una asercion que depende de ese orden no afirma lo que dice.
porque "aprobar el entregable sin decir quien" \
  "UPDATE deliverables SET status='approved', approved_at=NOW(3), approved_version_id=$V2
    WHERE id=$DEL;" "ck_del_aprobador"
# Y con el puntero: desde 8.2, `ck_del_approved_version` exige que aprobado diga
# tambien QUE version se aprobo.
probar "aprobarlo con su firma" \
  "UPDATE deliverables SET status='approved', approved_at=NOW(3), approved_by_user_id=$USR,
     approved_version_id=$V2 WHERE id=$DEL;" OK

echo ""
echo "--- Un veredicto no se edita, y uno cerrado no admite mas ---"
# El mensaje cambio en 8.2: aprobado ya no es un callejon sin salida --se puede
# reabrir-- asi que el rechazo dice que hacer en vez de solo que no.
porque "revisar un entregable ya aprobado" \
  "$(revision "$V2" changes_requested "'Me lo he repensado.'" platform "$USR" 0)" "no admite mas veredictos"
porque "editar un veredicto ya emitido" \
  "UPDATE content_reviews SET outcome='approved'
    WHERE deliverable_version_id=$V1 AND outcome='changes_requested' LIMIT 1;" "no se edita"

echo ""
echo "--- La limpieza, comprobada ---"
# 8.2: primero se suelta el puntero. `fk_del_approved_version` impide borrar la
# version a la que apunta un entregable aprobado --que es justo lo que tiene que
# hacer-- y sin esto la limpieza fallaba en silencio y dejaba las filas puestas
# para la suite siguiente.
$CLIENTE $DB -e "UPDATE deliverables SET status='in_review', approved_at=NULL,
   approved_by_user_id=NULL, approved_version_id=NULL WHERE campaign_creator_id=$MIA;" 2>&1 | grep -i error
$CLIENTE $DB -e "DELETE FROM content_reviews WHERE deliverable_version_id IN
   (SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id IN
     (SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA) z)) y);" 2>&1 | grep -i error
$CLIENTE $DB -e "DELETE FROM deliverable_versions WHERE deliverable_id IN
   (SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA) z);" 2>&1 | grep -i error
$CLIENTE $DB -e "DELETE FROM deliverables WHERE campaign_creator_id=$MIA;" 2>&1 | grep -i error
valor "no quedan revisiones mias" \
  "SELECT COUNT(*) FROM content_reviews r
     JOIN deliverable_versions v ON v.id=r.deliverable_version_id
     JOIN deliverables d ON d.id=v.deliverable_id
    WHERE d.campaign_creator_id=$MIA;" "0"
valor "ni entregables mios" \
  "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA;" "0"
valor "y lo de 2.12 sigue donde estaba" \
  "SELECT COUNT(*) > 0 FROM content_reviews;" "1"

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
