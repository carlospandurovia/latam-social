#!/bin/bash
# Pruebas de restriccion de la iteracion 2.12 (contenido, publicacion, evidencia).
# Uso: bash tools/pruebas/2.12-contenido.sh <base>
DB=${1:-latam_c12}
# El cliente y sus credenciales salen del entorno: en local es `mariadb` sin
# nada, y en CI es `mysql -h127.0.0.1 -uroot -proot`. Estaba fijo a `mariadb`,
# lo que habria hecho fallar el CI entero en el primer INSERT.
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
# Un fallo de conexion NO es un rechazo. Sin esta distincion, una base caida
# hace que todas las pruebas de rechazo "pasen" y el informe salga verde con el
# motor apagado. Paso de verdad: 25 aserciones en verde contra un socket muerto.
P="(SELECT id FROM campaign_creators ORDER BY id LIMIT 1)"
R="(SELECT id FROM campaign_requirements ORDER BY id LIMIT 1)"
# Envueltos en una tabla derivada: desde 8.6 estos entregables hay que APROBARLOS
# antes de publicar, y `UPDATE deliverables ... WHERE id=(SELECT ... FROM
# deliverables)` da 1093 en MySQL --no se puede leer la tabla que se escribe--.
# MariaDB lo tolera, asi que sin esto la suite pasaba en un motor y no en el
# otro. Misma leccion que en la suite de 8.1.
D="(SELECT id FROM (SELECT id FROM deliverables ORDER BY id LIMIT 1) d1)"
D2="(SELECT id FROM (SELECT id FROM deliverables ORDER BY id LIMIT 1 OFFSET 1) d2)"
# 8.3 exige revisar la ULTIMA version de un entregable --un veredicto sobre
# contenido que el creador ya reemplazo no lo lee nadie--, asi que esto apunta
# a la mas alta y no a la primera.
V="(SELECT id FROM (SELECT id FROM deliverable_versions ORDER BY version_number DESC LIMIT 1) x)"
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
# Desde 8.3, pedir cambios exige DECIR CUALES (`ck_cvw_comments`): una
# correccion sin texto le llega al creador como «hazlo otra vez».
# Desde 8.4 la que gasta ronda es la del CLIENTE: `reviewer_side` va explicito.
# El valor por defecto de la columna es 'platform', y una correccion nuestra no
# cuenta contra el precio (`DEC-133`).
probar "review: correccion del cliente que consume ronda" \
 "INSERT INTO content_reviews (uuid,deliverable_version_id,reviewer_user_id,reviewer_side,outcome,comments,consumes_round,reviewed_at) VALUES (UUID(),$V,$U,'client','changes_requested','El logo se ve cortado en el segundo 4.',1,NOW(3));" OK
probar "review: correccion nuestra que pretende consumir ronda" \
 "INSERT INTO content_reviews (uuid,deliverable_version_id,reviewer_user_id,reviewer_side,outcome,comments,consumes_round,reviewed_at) VALUES (UUID(),$V,$U,'platform','changes_requested','Esta es nuestra.',1,NOW(3));" RECHAZO
probar "review: correccion sin decir cual" \
 "INSERT INTO content_reviews (uuid,deliverable_version_id,reviewer_user_id,reviewer_side,outcome,consumes_round,reviewed_at) VALUES (UUID(),$V,$U,'client','changes_requested',1,NOW(3));" RECHAZO
probar "review: aprobacion que pretende consumir ronda" \
 "INSERT INTO content_reviews (uuid,deliverable_version_id,reviewer_user_id,outcome,consumes_round,reviewed_at) VALUES (UUID(),$V,$U,'approved',1,NOW(3));" RECHAZO
# La del cliente puede no tener usuario: en 8.5 la escribe un enlace firmado,
# sin cuenta detras. La NUESTRA si tiene que ir firmada (`ck_cvw_firma`).
probar "review: revision del cliente" \
 "INSERT INTO content_reviews (uuid,deliverable_version_id,reviewer_side,outcome,comments,consumes_round,reviewed_at) VALUES (UUID(),$V,'client','changes_requested','Prefieren el plano abierto del primer envio.',1,NOW(3));" OK
probar "review: interna sin firmar" \
 "INSERT INTO content_reviews (uuid,deliverable_version_id,reviewer_side,outcome,reviewed_at) VALUES (UUID(),$V,'platform','approved',NOW(3));" RECHAZO
probar "review: veredicto inventado" \
 "INSERT INTO content_reviews (uuid,deliverable_version_id,reviewer_side,outcome,reviewed_at) VALUES (UUID(),$V,'client','mas_o_menos',NOW(3));" RECHAZO

echo ""
echo "--- Publicaciones ---"
# 8.6: solo se publica lo APROBADO, y la version aprobada. Asi que primero se
# aprueba, y el `deliverable_version_id` es obligatorio y tiene que ser esa.
# `$D2` no tenia ninguna version --nadie se la habia pedido hasta ahora--.
$CLIENTE $DB -e "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at)
   VALUES (UUID(),$D2,1,'https://drive.google.com/d2',NOW(3));" 2>&1 | grep -i error
V2="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$D2 ORDER BY version_number DESC LIMIT 1) w)"
# `submitted_at` tambien: `ck_del_approved` (2.12) exige que aprobado implique
# entregado, y estos entregables se insertaron sin fecha de entrega porque hasta
# 8.1 nadie se la pedia.
$CLIENTE $DB -e "UPDATE deliverables SET status='approved', submitted_at=NOW(3), approved_at=NOW(3),
   approved_by_user_id=$U, approved_version_id=$V WHERE id=$D;
  UPDATE deliverables SET status='approved', submitted_at=NOW(3), approved_at=NOW(3),
   approved_by_user_id=$U, approved_version_id=$V2 WHERE id=$D2;" 2>&1 | grep -i error
probar "publication: el creador reporta su enlace" \
 "INSERT INTO publications (uuid,deliverable_id,deliverable_version_id,platform_id,url,url_fingerprint,published_at,created_at) VALUES (UUID(),$D,$V,$IG,'https://instagram.com/p/ABC123',REPEAT('a',64),NOW(3),NOW(3));" OK
probar "publication: OTRO entregable reclama el MISMO post" \
 "INSERT INTO publications (uuid,deliverable_id,deliverable_version_id,platform_id,url,url_fingerprint,published_at,created_at) VALUES (UUID(),$D2,$V2,$IG,'https://instagram.com/p/ABC123?utm=x',REPEAT('a',64),NOW(3),NOW(3));" RECHAZO
probar "publication: verificada sin verificador" \
 "INSERT INTO publications (uuid,deliverable_id,deliverable_version_id,platform_id,url,url_fingerprint,published_at,created_at,status,verified_at) VALUES (UUID(),$D,$V,$IG,'https://instagram.com/p/D',REPEAT('d',64),NOW(3),NOW(3),'verified',NOW(3));" RECHAZO
probar "publication: retirada sin fecha de retiro" \
 "INSERT INTO publications (uuid,deliverable_id,deliverable_version_id,platform_id,url,url_fingerprint,published_at,created_at,status) VALUES (UUID(),$D,$V,$IG,'https://instagram.com/p/E',REPEAT('e',64),NOW(3),NOW(3),'removed');" RECHAZO
probar "publication: huella de longitud incorrecta" \
 "INSERT INTO publications (uuid,deliverable_id,deliverable_version_id,platform_id,url,url_fingerprint,published_at,created_at) VALUES (UUID(),$D,$V,$IG,'https://instagram.com/p/F','corta',NOW(3),NOW(3));" RECHAZO

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
# 8.8 endurecio esta tabla y estas dos aserciones cambiaron de signo: eran OK
# porque nada impedia comprobar la permanencia de una publicacion que nadie
# habia verificado --y `permanence_until` sale justo de verificar (8.7), asi que
# una comprobacion asi no mide nada--. Se dejan aqui, afirmando el rechazo, para
# que quede escrito que era un hueco y no una decision. El camino completo lo
# prueba `8.8-permanencia`.
probar "permanence: comprobar lo que nadie verifico" \
 "INSERT INTO permanence_checks (uuid,publication_id,source,checked_at,is_live,http_status) VALUES (UUID(),$PUB,'probe',NOW(3),1,200);" RECHAZO
probar "permanence: y una manual sin firma tampoco" \
 "INSERT INTO permanence_checks (uuid,publication_id,checked_at,is_live,http_status,notes) VALUES (UUID(),$PUB,NOW(3),0,404,'Eliminado por el creador');" RECHAZO

echo ""
echo -n "  Tablas de solo insercion con updated_at (debe ser 0): "
$CLIENTE $DB -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND COLUMN_NAME='updated_at' AND TABLE_NAME IN ('deliverable_versions','content_reviews','permanence_checks');" -B -N 2>/dev/null
printf "\n  \033[1mResultado: %d correctas, %d fallidas\033[0m\n" $ok $fail
[ $fail -eq 0 ] || exit 1
