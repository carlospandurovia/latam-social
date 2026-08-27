#!/bin/bash
# Pruebas de restriccion de la iteracion 8.4: el techo de rondas, en la base.
#
#   ck_cvw_round     solo la correccion del CLIENTE gasta ronda
#   tg_cvw_techo     agotadas y sin declarar -> rechazo; declarada de mas sin
#                    estarlo -> rechazo tambien
#   tg_del_rondas    el contador no baja (y si puede subir de golpe)
#
# 8.3 construyo el limite entero y lo dejo TODO en PHP. Un `if` de un servicio
# solo protege al que pasa por ese servicio, y 8.5 escribe revisiones del cliente
# desde un enlace firmado. Esta suite escribe a mano, saltandose el servicio.
#
# Uso: bash tools/pruebas/8.4-rondas.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  8.4 - El techo de rondas"
echo "==================================================================================="

MIA="(SELECT id FROM (SELECT cc.id FROM campaign_creators cc
        JOIN creators c ON c.id=cc.creator_id
       WHERE c.display_name='luisvega' AND cc.accepted_at IS NOT NULL LIMIT 1) m)"
REQ="(SELECT id FROM (SELECT id FROM campaign_requirements ORDER BY id LIMIT 1) x)"
USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"

valor "ningun entregable mio de una pasada anterior" \
  "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA;" "0"

# Dos rondas incluidas, puestas a mano: el valor sembrado puede cambiar y toda
# esta suite cuenta hasta el. Una aserción que depende de una semilla es una
# aserción que se cae el dia que alguien toque la semilla.
$CLIENTE $DB -e "UPDATE campaigns SET included_revision_rounds=2
   WHERE id=(SELECT campaign_id FROM (SELECT campaign_id FROM campaign_creators WHERE id=$MIA) z);" 2>&1 | grep -i error
$CLIENTE $DB -e "INSERT INTO deliverables
   (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,status,due_on,submitted_at,created_at,updated_at)
   VALUES (UUID(),$MIA,$REQ,1,'in_review',CURDATE() + INTERVAL 7 DAY,NOW(3),NOW(3),NOW(3));" 2>&1 | grep -i error
D="(SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA) d)"
$CLIENTE $DB -e "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,submitted_at,created_at)
   VALUES (UUID(),$D,1,'https://drive.example/rondas',NOW(3),NOW(3));" 2>&1 | grep -i error
V="(SELECT id FROM (SELECT id FROM deliverable_versions WHERE deliverable_id=$D) v)"
valor "tengo mi pieza en revision, con 2 rondas incluidas y 0 gastadas" \
  "SELECT CONCAT(c.included_revision_rounds,'/',d.revision_rounds_used)
     FROM deliverables d JOIN campaign_creators cc ON cc.id=d.campaign_creator_id
     JOIN campaigns c ON c.id=cc.campaign_id WHERE d.id=$D;" "2/0"

# Un ayudante, para que cada asercion diga solo lo suyo.
revision() {  # lado, consume, over, billing, autorizador
  echo "INSERT INTO content_reviews
    (uuid,deliverable_version_id,reviewer_user_id,reviewer_side,outcome,comments,
     consumes_round,over_included,billing_decision,authorized_by_user_id,reviewed_at,created_at)
    VALUES (UUID(),$V,$USR,'$1','changes_requested','Hay que retocar el encuadre.',
            $2,$3,$4,$5,NOW(3),NOW(3));"
}

echo ""
echo "--- Solo la correccion del CLIENTE gasta ronda ---"
# `DEC-133` vivia unicamente en `Revisiones::consumeRonda()`: `ck_cvw_round`
# decia «consume o es una correccion» y no decia DE QUIEN.
porque "una revision nuestra que gasta ronda del cliente" \
  "$(revision platform 1 0 NULL NULL)" "ck_cvw_round"
probar "la nuestra sin gastar ronda, si" \
  "$(revision platform 0 0 NULL NULL)" OK

echo ""
echo "--- No se cobra como extra lo que todavia entraba ---"
# Con billing y firma puestos A PROPOSITO: sin ellos la rechazaria `ck_cvw_over`
# --otra restriccion-- y la asercion pasaria por el motivo equivocado. Es la
# leccion de `T-48` y `T-51`.
porque "declarar de mas la primera ronda, con las dos incluidas libres" \
  "$(revision client 1 1 "'charge'" "$USR")" "no es una ronda de mas"

echo ""
echo "--- Las dos incluidas entran ---"
probar "primera ronda del cliente" \
  "$(revision client 1 0 NULL NULL)" OK
$CLIENTE $DB -e "UPDATE deliverables SET revision_rounds_used=1 WHERE id=$D;" 2>&1 | grep -i error
probar "segunda ronda del cliente" \
  "$(revision client 1 0 NULL NULL)" OK
$CLIENTE $DB -e "UPDATE deliverables SET revision_rounds_used=2 WHERE id=$D;" 2>&1 | grep -i error
valor "y el contador va por 2" \
  "SELECT revision_rounds_used FROM deliverables WHERE id=$D;" "2"

echo ""
echo "--- La tercera no se cuela sin declararse ---"
# Es la que cuesta dinero: `Revisiones::cargosPendientes()` cuenta
# `over_included` para facturar, asi que una fila que miente ahi es una ronda
# que se trabaja y no se cobra.
porque "tercera ronda del cliente sin declararla de mas" \
  "$(revision client 1 0 NULL NULL)" "ya gasto las rondas incluidas"
probar "y declarada, firmada y con su decision de facturacion, si" \
  "$(revision client 1 1 "'charge'" "$USR")" OK
valor "queda una sola marcada como ronda de mas" \
  "SELECT COUNT(*) FROM content_reviews WHERE deliverable_version_id=$V AND over_included=1;" "1"

echo ""
echo "--- El contador no baja ---"
# La mitad del dano que no tiene dueno: bajarlo no necesita la firma de nadie y
# le devuelve al cliente rondas que ya gasto.
porque "ponerlo a cero" \
  "UPDATE deliverables SET revision_rounds_used=0 WHERE id=$D;" "no baja"
porque "ni bajarlo una sola" \
  "UPDATE deliverables SET revision_rounds_used=1 WHERE id=$D;" "no baja"
probar "pero subirlo de golpe si, a proposito" \
  "UPDATE deliverables SET revision_rounds_used=5 WHERE id=$D;" OK
probar "y tocar el entregable sin tocar el contador, tambien" \
  "UPDATE deliverables SET updated_at=NOW(3) WHERE id=$D;" OK

# --- Limpieza -----------------------------------------------------------------
# Esta suite SI limpia: `content_reviews` no lleva `no_delete` --es `Q-56`, y se
# decide al facturar-- asi que la cadena entera se puede deshacer.
$CLIENTE $DB -e "DELETE FROM content_reviews WHERE deliverable_version_id=$V;" 2>&1 | grep -i error
$CLIENTE $DB -e "DELETE FROM deliverable_versions WHERE deliverable_id=$D;" 2>&1 | grep -i error
$CLIENTE $DB -e "DELETE FROM deliverables WHERE campaign_creator_id=$MIA;" 2>&1 | grep -i error
valor "y la suite deja la base como la encontro" \
  "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA;" "0"

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
