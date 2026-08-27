#!/bin/bash
# Pruebas de restriccion de la iteracion 8.1: los entregables.
#
#   tg_del_participacion_aceptada  no hay entregable sin compromiso
#   tg_dv_participacion_viva       ni entrega sobre una participacion muerta
#   ck_del_due_futuro              un plazo no nace vencido
#   ck_del_submitted               enviado exige CUANDO
#   uq_del_sequence                un requisito de 3 son 3 filas numeradas
#   uq_dv_number                   y el historial de versiones es append-only
#   ck_dv_content                  una version trae archivo o enlace
#   ck_dv_url_https                y el enlace va por https
#
# ------------------------------------------------------------------------------
# POR QUE ESTA SUITE NO EMPIEZA VACIANDO LAS TABLAS
#
# La primera version de este archivo abria con
#     DELETE FROM deliverable_versions; DELETE FROM deliverables;
# y seguia con "y ningun entregable de una pasada anterior = 0". Salio en rojo
# de la peor manera posible: cinco fallos, y --lo grave-- varias aserciones que
# esperaban RECHAZO pasando POR EL MOTIVO EQUIVOCADO, chocando contra filas
# ajenas en vez de contra la regla que decian probar.
#
# El DELETE no fallaba por un `no_delete`. Fallaba por 1451: `content_reviews` y
# `publications` apuntan a estas filas con ON DELETE RESTRICT, y quien las
# creo fue la suite 2.12, que corre antes en la misma base. El `2>/dev/null`
# se comia el error y la suite seguia como si estuviera limpia.
#
# La premisa correcta no es "la tabla esta vacia" --nunca lo va a estar, y
# exigirlo es pedirle a una suite hermana que no haga su trabajo--. La premisa
# correcta es "no hay filas MIAS". Asi que esta suite:
#
#   1. trabaja sobre SU participacion, la de `luisvega`, elegida por nombre.
#      2.12 usa `ORDER BY id LIMIT 1`, que es la de `anatorres`. No se cruzan.
#   2. afirma su premisa contando solo lo suyo, y si no se cumple lo dice.
#   3. limpia al final solo lo suyo, y COMPRUEBA que la limpieza funciono en
#      vez de mandar el error a /dev/null.
#
# Uso: bash tools/pruebas/8.1-entregables.sh <base>
# ------------------------------------------------------------------------------
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
# Un RECHAZO solo prueba algo si rechaza por la regla que decimos. Sin esto,
# una fila ajena que choca con `uq_del_sequence` se lee igual que la regla
# funcionando, que es exactamente como esta suite salio verde en falso.
echo ""
echo "==================================================================================="
echo "  8.1 - Los entregables"
echo "==================================================================================="

# La participacion de esta suite. Por NOMBRE, no por posicion: el dia que la
# semilla crezca, `ORDER BY id LIMIT 1` apunta a otro sitio sin avisar.
# Envuelta en una tabla derivada a proposito. Sin eso, `DELETE FROM
# campaign_creators WHERE id=(SELECT ... FROM campaign_creators)` da 1093 --no
# se puede leer la tabla que se borra-- y 1093 es un ERROR, asi que la asercion
# "borrar la participacion se rechaza" saldria verde sin haber llegado nunca a
# la clave ajena que dice probar.
MIA="(SELECT id FROM (SELECT cc.id FROM campaign_creators cc
        JOIN creators c ON c.id=cc.creator_id
       WHERE c.display_name='luisvega' AND cc.accepted_at IS NOT NULL LIMIT 1) m)"
REQ="(SELECT id FROM (SELECT id FROM campaign_requirements ORDER BY id LIMIT 1) x)"

valor "las dos tablas existen" \
  "SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_name IN ('deliverables','deliverable_versions');" "2"
valor "la semilla trae mi participacion aceptada" \
  "SELECT COUNT(*) FROM campaign_creators cc JOIN creators c ON c.id=cc.creator_id
    WHERE c.display_name='luisvega' AND cc.accepted_at IS NOT NULL;" "1"
valor "y un requisito de brief" "SELECT COUNT(*) FROM campaign_requirements;" "1"
# La premisa de verdad: nada MIO de una pasada anterior. Que 2.12 haya dejado
# entregables de `anatorres` es correcto y no me estorba.
valor "y ningun entregable mio de una pasada anterior" \
  "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA;" "0"

# `$1` numero de secuencia, `$2` dias hasta la fecha limite, `$3` participacion.
entregable() {
  echo "INSERT INTO deliverables (uuid,campaign_creator_id,campaign_requirement_id,sequence_number,status,due_on,created_at,updated_at)
    VALUES (UUID(),${3:-$MIA},$REQ,$1,'pending',CURDATE() + INTERVAL ${2:-7} DAY,NOW(3),NOW(3));"
}

echo ""
echo "--- Un requisito de 3 son 3 filas numeradas ---"
probar "el primero" "$(entregable 1)" OK
probar "el segundo" "$(entregable 2)" OK
porque "repetir el numero uno" "$(entregable 1)" "uq_del_sequence"
valor "quedan dos mios" "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA;" "2"
probar "un numero de secuencia cero" "$(entregable 0)" RECHAZO

echo ""
echo "--- Un plazo no nace vencido ---"
# Es una regla de CREACION y se comprueba como tal: un entregable vence porque
# pasa el tiempo, no porque alguien le mueva la fecha hacia atras.
probar "con la fecha limite ayer" "$(entregable 3 -1)" RECHAZO
probar "con la fecha limite hoy" "$(entregable 3 0)" OK

echo ""
echo "--- No hay entregable sin compromiso ---"
# La version anterior de este bloque inventaba una participacion para `test1`.
# `test1` no existe en una base recien sembrada --lo crean OTRAS suites-- asi
# que el INSERT no insertaba nada y el rechazo venia de que el subselect
# devolvia NULL, no del disparador. Verde en falso, y lo cazo la asercion de
# premisa que ahora precede a la prueba. Aqui la premisa se construye con lo
# que la semilla si garantiza: mi propia participacion, desacreditada y
# devuelta a su sitio a continuacion.
$CLIENTE $DB -e "UPDATE campaign_creators SET status='shortlisted', accepted_at=NULL
  WHERE id=$MIA;" 2>&1 | grep -i error
valor "mi participacion queda sin aceptar para probarlo" \
  "SELECT COUNT(*) FROM campaign_creators cc JOIN creators c ON c.id=cc.creator_id
    WHERE c.display_name='luisvega' AND cc.accepted_at IS NULL;" "1"
SIN_ACEPTAR="(SELECT id FROM (SELECT cc.id FROM campaign_creators cc
                JOIN creators c ON c.id=cc.creator_id
               WHERE c.display_name='luisvega' AND cc.accepted_at IS NULL LIMIT 1) s)"
# Numero 9: si usara el 1 chocaria ademas con `uq_del_sequence` y el rechazo
# seria ambiguo. Y se exige el texto del disparador, no un ERROR cualquiera.
porque "para una participacion sin aceptar" "$(entregable 9 7 "$SIN_ACEPTAR")" "sin aceptar"
$CLIENTE $DB -e "UPDATE campaign_creators SET status='accepted', accepted_at=NOW(3)
  WHERE id=$SIN_ACEPTAR;" 2>&1 | grep -i error
valor "y vuelve a estar aceptada" \
  "SELECT COUNT(*) FROM campaign_creators cc JOIN creators c ON c.id=cc.creator_id
    WHERE c.display_name='luisvega' AND cc.accepted_at IS NOT NULL;" "1"

echo ""
echo "--- Enviado exige CUANDO ---"
DEL="(SELECT id FROM (SELECT id FROM deliverables WHERE campaign_creator_id=$MIA
                       ORDER BY id LIMIT 1) x)"
probar "marcar enviado sin fecha" \
  "UPDATE deliverables SET status='submitted' WHERE id=$DEL;" RECHAZO
probar "marcar enviado con fecha" \
  "UPDATE deliverables SET status='submitted', submitted_at=NOW(3) WHERE id=$DEL;" OK
probar "aprobado sin haber sido enviado" \
  "UPDATE deliverables SET status='approved', approved_at=NOW(3), submitted_at=NULL WHERE id=$DEL;" RECHAZO

echo ""
echo "--- Una version trae ALGO, y por https ---"
version() {
  echo "INSERT INTO deliverable_versions (uuid,deliverable_id,version_number,external_url,file_id,submitted_at,created_at)
    VALUES (UUID(),$DEL,$1,$2,NULL,NOW(3),NOW(3));"
}
probar "una version con enlace https" "$(version 1 "'https://drive.example/x'")" OK
porque "repetir el numero de version" "$(version 1 "'https://drive.example/y'")" "uq_dv_number"
probar "una version sin archivo ni enlace" "$(version 2 'NULL')" RECHAZO
probar "una version con enlace http" "$(version 2 "'http://drive.example/y'")" RECHAZO
probar "una con javascript:" "$(version 2 "'javascript:alert(1)'")" RECHAZO
probar "la version dos, bien" "$(version 2 "'https://drive.example/y'")" OK
valor "el historial es append-only: siguen las dos" \
  "SELECT COUNT(*) FROM deliverable_versions WHERE deliverable_id=$DEL;" "2"

echo ""
echo "--- Ni entrega sobre una participacion muerta ---"
# `tg_dv_participacion_viva` mira el estado de la participacion, no el del
# entregable. Se prueba cancelandola y devolviendola a su sitio.
$CLIENTE $DB -e "UPDATE campaign_creators SET status='cancelled' WHERE id=$MIA;" 2>&1 | grep -i error
probar "una version con la participacion cancelada" "$(version 3 "'https://drive.example/z'")" RECHAZO
$CLIENTE $DB -e "UPDATE campaign_creators SET status='accepted' WHERE id=$MIA;" 2>&1 | grep -i error
probar "y la misma version cuando vuelve a estar viva" "$(version 3 "'https://drive.example/z'")" OK

echo ""
echo "--- Nada de esto se borra mientras cuelgue de ello otra cosa ---"
porque "borrar la participacion que tiene entregables" \
  "DELETE FROM campaign_creators WHERE id=$MIA;" "fk_del_participation"
porque "borrar el entregable que tiene versiones" \
  "DELETE FROM deliverables WHERE id=$DEL;" "fk_dv_deliverable"
porque "borrar el requisito que los origino" \
  "DELETE FROM campaign_requirements WHERE id=$REQ;" "fk_del_requirement"

echo ""
echo "--- La limpieza, comprobada ---"
# Sin `2>/dev/null`. Si la limpieza deja de funcionar --porque manana estas
# tablas lleven `no_delete`, que es una pregunta abierta (Q-56)-- esta suite
# tiene que salir en rojo AQUI, no tres iteraciones despues y en otro sitio.
$CLIENTE $DB -e "DELETE FROM deliverable_versions
                  WHERE deliverable_id IN (SELECT id FROM (SELECT id FROM deliverables
                        WHERE campaign_creator_id=$MIA) z);" 2>&1 | grep -i error
$CLIENTE $DB -e "DELETE FROM deliverables WHERE campaign_creator_id=$MIA;" 2>&1 | grep -i error
valor "no quedan entregables mios" \
  "SELECT COUNT(*) FROM deliverables WHERE campaign_creator_id=$MIA;" "0"
valor "y mi participacion sigue aceptada, como la deje" \
  "SELECT cc.status FROM campaign_creators cc JOIN creators c ON c.id=cc.creator_id
    WHERE c.display_name='luisvega';" "accepted"
valor "y lo de 2.12 sigue donde estaba" \
  "SELECT COUNT(*) > 0 FROM deliverables;" "1"

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
