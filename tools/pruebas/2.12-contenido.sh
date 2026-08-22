#!/bin/bash
# Pruebas de restriccion de la iteracion 2.12 (contenido, publicacion, evidencia).
# Uso: bash tools/pruebas/2.12-contenido.sh <base>
DB=${1:-latam_c12}
# El cliente y sus credenciales salen del entorno: en local es `mariadb` sin
# nada, y en CI es `mysql -h127.0.0.1 -uroot -proot`. Estaba fijo a `mariadb`,
# lo que habria hecho fallar el CI entero en el primer INSERT.
CLIENTE=${MYSQL_CMD:-mariadb}

ok=0; fail=0
# Un fallo de conexion NO es un rechazo. Sin esta distincion, una base caida
# hace que todas las pruebas de rechazo "pasen" y el informe salga verde con el
# motor apagado. Paso de verdad: 25 aserciones en verde contra un socket muerto.
probar() {
  salida=$($CLIENTE $DB -e "$2" 2>&1)
  if echo "$salida" | grep -qiE "ERROR (2002|2003|2005|1045|1049)|Can't connect|Unknown database|Access denied"; then
    printf "  \033[31m!\033[0m %-64s LA BASE NO RESPONDE\n" "$1"
    echo "      $(echo "$salida" | grep -i error | head -1)"
    fail=$((fail+1)); return
  fi
  if [ -z "$salida" ] || ! echo "$salida" | grep -qi "ERROR"; then real="OK"; else real="RECHAZO"; fi
  if [ "$real" == "$3" ]; then printf "  \033[32m✓\033[0m %-62s %s\n" "$1" "$real"; ok=$((ok+1))
  else printf "  \033[31m✗\033[0m %-62s esperaba %s, obtuvo %s\n" "$1" "$3" "$real"; echo "      $(echo "$salida"|grep -i error|head -1)"; fail=$((fail+1)); fi
}
P="(SELECT id FROM campaign_creators ORDER BY id LIMIT 1)"
R="(SELECT id FROM campaign_requirements ORDER BY id LIMIT 1)"
D="(SELECT id FROM deliverables ORDER BY id LIMIT 1)"
D2="(SELECT id FROM deliverables ORDER BY id LIMIT 1 OFFSET 1)"
V="(SELECT id FROM deliverable_versions ORDER BY id LIMIT 1)"
IG="(SELECT id FROM platforms WHERE code='instagram')"
PUB="(SELECT id FROM publications ORDER BY id LIMIT 1)"
U="(SELECT id FROM users LIMIT 1)"
F="(SELECT id FROM files ORDER BY id LIMIT 1)"

echo ""
echo "--- Entregables ---"
probar "deliverable: primero de un requisito de 2 reels" \
 "INSERT INTO deliverables (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,due_on) VALUES (UUID(),$P,$R,1,'2026-09-10');" OK
probar "deliverable: segundo del mismo requisito" \
 "INSERT INTO deliverables (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,due_on) VALUES (UUID(),$P,$R,2,'2026-09-12');" OK
probar "deliverable: el mismo numero repetido" \
 "INSERT INTO deliverables (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,due_on) VALUES (UUID(),$P,$R,1,'2026-09-15');" RECHAZO
probar "deliverable: aprobado sin haberse entregado" \
 "INSERT INTO deliverables (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,due_on,approved_at) VALUES (UUID(),$P,$R,3,'2026-09-15',NOW(3));" RECHAZO
probar "deliverable: estado inventado" \
 "INSERT INTO deliverables (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,due_on,status) VALUES (UUID(),$P,$R,4,'2026-09-15','listo');" RECHAZO

echo ""
echo "--- Versiones (solo insercion) ---"
probar "version: primera entrega con archivo" \
 "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,file_id,submitted_at) VALUES (UUID(),$D,1,$F,NOW(3));" OK
probar "version: segunda entrega tras correccion" \
 "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at) VALUES (UUID(),$D,2,'https://drive.google.com/x',NOW(3));" OK
probar "version: el mismo numero de version repetido" \
 "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,file_id,submitted_at) VALUES (UUID(),$D,1,$F,NOW(3));" RECHAZO
probar "version: sin archivo NI enlace (vacia)" \
 "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,submitted_at) VALUES (UUID(),$D,3,NOW(3));" RECHAZO

echo ""
echo "--- Revisiones y rondas incluidas ---"
probar "review: aprobacion (no consume ronda)" \
 "INSERT INTO content_reviews (uuid,deliverable_version_id,reviewer_user_id,outcome,reviewed_at) VALUES (UUID(),$V,$U,'approved',NOW(3));" OK
probar "review: correccion que consume ronda" \
 "INSERT INTO content_reviews (uuid,deliverable_version_id,reviewer_user_id,outcome,consumes_round,reviewed_at) VALUES (UUID(),$V,$U,'changes_requested',1,NOW(3));" OK
probar "review: aprobacion que pretende consumir ronda" \
 "INSERT INTO content_reviews (uuid,deliverable_version_id,reviewer_user_id,outcome,consumes_round,reviewed_at) VALUES (UUID(),$V,$U,'approved',1,NOW(3));" RECHAZO
probar "review: revision del cliente" \
 "INSERT INTO content_reviews (uuid,deliverable_version_id,reviewer_side,outcome,consumes_round,reviewed_at) VALUES (UUID(),$V,'client','changes_requested',1,NOW(3));" OK
probar "review: veredicto inventado" \
 "INSERT INTO content_reviews (uuid,deliverable_version_id,outcome,reviewed_at) VALUES (UUID(),$V,'mas_o_menos',NOW(3));" RECHAZO

echo ""
echo "--- Publicaciones ---"
probar "publication: el creador reporta su enlace" \
 "INSERT INTO publications (uuid,deliverable_id,platform_id,url,url_fingerprint,published_at) VALUES (UUID(),$D,$IG,'https://instagram.com/p/ABC123',REPEAT('a',64),NOW(3));" OK
probar "publication: OTRO entregable reclama el MISMO post" \
 "INSERT INTO publications (uuid,deliverable_id,platform_id,url,url_fingerprint,published_at) VALUES (UUID(),$D2,$IG,'https://instagram.com/p/ABC123?utm=x',REPEAT('a',64),NOW(3));" RECHAZO
probar "publication: verificada sin verificador" \
 "INSERT INTO publications (uuid,deliverable_id,platform_id,url,url_fingerprint,published_at,status,verified_at) VALUES (UUID(),$D,$IG,'https://instagram.com/p/D',REPEAT('d',64),NOW(3),'verified',NOW(3));" RECHAZO
probar "publication: retirada sin fecha de retiro" \
 "INSERT INTO publications (uuid,deliverable_id,platform_id,url,url_fingerprint,published_at,status) VALUES (UUID(),$D,$IG,'https://instagram.com/p/E',REPEAT('e',64),NOW(3),'removed');" RECHAZO
probar "publication: huella de longitud incorrecta" \
 "INSERT INTO publications (uuid,deliverable_id,platform_id,url,url_fingerprint,published_at) VALUES (UUID(),$D,$IG,'https://instagram.com/p/F','corta',NOW(3));" RECHAZO

echo ""
echo "--- Evidencia y permanencia ---"
probar "evidence: captura de pantalla" \
 "INSERT INTO publication_evidence (uuid,publication_id,evidence_type,file_id,captured_at) VALUES (UUID(),$PUB,'screenshot',$F,NOW(3));" OK
probar "evidence: comprobacion HTTP sin archivo" \
 "INSERT INTO publication_evidence (uuid,publication_id,evidence_type,http_status,captured_at) VALUES (UUID(),$PUB,'http_check',200,NOW(3));" OK
probar "evidence: sin archivo, sin payload y sin estado HTTP" \
 "INSERT INTO publication_evidence (uuid,publication_id,evidence_type,captured_at) VALUES (UUID(),$PUB,'manual',NOW(3));" RECHAZO
probar "evidence: payload que no es JSON" \
 "INSERT INTO publication_evidence (uuid,publication_id,evidence_type,raw_payload,captured_at) VALUES (UUID(),$PUB,'api_snapshot','{roto',NOW(3));" RECHAZO
probar "evidence: tipo inventado" \
 "INSERT INTO publication_evidence (uuid,publication_id,evidence_type,http_status,captured_at) VALUES (UUID(),$PUB,'foto_del_movil',200,NOW(3));" RECHAZO
probar "permanence: el post sigue vivo" \
 "INSERT INTO permanence_checks (publication_id,checked_at,is_live,http_status) VALUES ($PUB,NOW(3),1,200);" OK
probar "permanence: el post desaparecio" \
 "INSERT INTO permanence_checks (publication_id,checked_at,is_live,http_status,notes) VALUES ($PUB,NOW(3),0,404,'Eliminado por el creador');" OK

echo ""
echo -n "  Tablas de solo insercion con updated_at (debe ser 0): "
$CLIENTE $DB -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND COLUMN_NAME='updated_at' AND TABLE_NAME IN ('deliverable_versions','content_reviews','permanence_checks');" -B -N 2>/dev/null
printf "\n  \033[1mResultado: %d correctas, %d fallidas\033[0m\n" $ok $fail
[ $fail -eq 0 ] || exit 1
