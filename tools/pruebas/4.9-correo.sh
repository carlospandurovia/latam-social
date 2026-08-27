#!/bin/bash
# Pruebas de restriccion de la iteracion 4.9: el correo.
#
#   uq_et_vigente     una sola version ABIERTA por (codigo, idioma)
#   tg_et_sin_solape  y ninguna que se solape con otra en el tiempo
#   ck_el_sent        enviado exige la fecha
#   ck_el_failed      fallido exige CUANDO y POR QUE
#
# Lo que se le envio a alguien tiene que poder demostrarse anos despues, y por
# eso una version publicada no se edita: se publica la siguiente y la anterior
# se cierra EL DIA ANTES. El error de un dia --once apariciones en este
# proyecto-- entra por ahi, y esta suite es donde se comprueba que no.
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

# Los cuatro ayudantes viven en UN sitio desde 8.11: estaban copiados en las
# treinta suites y habian derivado en seis variantes, y nueve de ellas se
# habrian puesto verdes con el motor apagado. Ver `tools/pruebas/comun.sh`.
source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  4.9 - El correo: plantillas versionadas y registro de envios"
echo "==================================================================================="

$CLIENTE $DB -e "DELETE FROM email_log WHERE template_code LIKE 'p49.%';
  DELETE FROM email_templates WHERE code LIKE 'p49.%';" 2>/dev/null

valor "las dos tablas existen" \
  "SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_name IN ('email_templates','email_log');" "2"
valor "y ninguna plantilla de esta suite de una pasada anterior" \
  "SELECT COUNT(*) FROM email_templates WHERE code LIKE 'p49.%';" "0"

plantilla() {  # codigo, idioma, version, desde, hasta
  echo "INSERT INTO email_templates (uuid,code,locale,version,subject,body,content_sha256,effective_from,effective_to,created_at)
    VALUES (UUID(),'$1','$2','$3','Asunto $3','Cuerpo $3',SHA2('$1$2$3',256),'$4',$5,NOW(3));"
}

echo ""
echo "--- Una version por codigo, idioma y etiqueta ---"
probar "la primera version" "$(plantilla 'p49.aviso' 'es' '1.0' '2026-01-01' 'NULL')" OK
probar "la misma etiqueta otra vez" "$(plantilla 'p49.aviso' 'es' '1.0' '2027-01-01' 'NULL')" RECHAZO
probar "la misma etiqueta en OTRO idioma si" "$(plantilla 'p49.aviso' 'pt' '1.0' '2026-01-01' 'NULL')" OK

echo ""
echo "--- Una sola version ABIERTA por codigo e idioma ---"
probar "una segunda abierta en el mismo idioma" \
  "$(plantilla 'p49.aviso' 'es' '2.0' '2026-06-01' 'NULL')" RECHAZO
probar "cerrando la primera EL DIA ANTES, la segunda entra" \
  "UPDATE email_templates SET effective_to='2026-05-31' WHERE code='p49.aviso' AND locale='es' AND version='1.0';
   $(plantilla 'p49.aviso' 'es' '2.0' '2026-06-01' 'NULL')" OK
valor "y ahora hay dos versiones en castellano" \
  "SELECT COUNT(*) FROM email_templates WHERE code='p49.aviso' AND locale='es';" "2"

echo ""
echo "--- El error de un dia: cerrar el MISMO dia deja dos vigentes ---"
# `effective_to` es INCLUSIVO. Cerrar la 1.0 el 2026-06-01 --el dia en que
# empieza la 2.0-- deja las dos cubriendo ese dia. Es la regla de periodo, y la
# compila `Periodo::sinSolape`, no una comprobacion escrita a mano.
probar "reabrir la primera hasta el dia en que empieza la segunda" \
  "UPDATE email_templates SET effective_to='2026-06-01' WHERE code='p49.aviso' AND locale='es' AND version='1.0';" RECHAZO
valor "sigue cerrada el dia antes" \
  "SELECT effective_to FROM email_templates WHERE code='p49.aviso' AND locale='es' AND version='1.0';" "2026-05-31"
probar "una version que termina antes de empezar" \
  "$(plantilla 'p49.otro' 'es' '1.0' '2026-06-01' "'2026-01-01'")" RECHAZO

echo ""
echo "--- El registro de envios ---"
TPL="(SELECT id FROM (SELECT id FROM email_templates WHERE code='p49.aviso' AND locale='es' AND version='2.0') t)"
envio() {  # uuid-sufijo, estado, enviado, fallado, error
  echo "INSERT INTO email_log (uuid,email_template_id,template_code,template_version,template_locale,
      locale_requested,to_email,subject,body_sha256,status,attempts,last_error,queued_at,sent_at,failed_at,created_at)
    VALUES (UUID(),$TPL,'p49.aviso','2.0','es','$1','prueba@ejemplo.test','Asunto',SHA2('cuerpo',256),
      '$2',1,$5,NOW(3),$3,$4,NOW(3));"
}
probar "un envio en cola" "$(envio 'es' 'queued' 'NULL' 'NULL' 'NULL')" OK
probar "uno enviado, con su fecha" "$(envio 'es' 'sent' 'NOW(3)' 'NULL' 'NULL')" OK
probar "uno enviado SIN fecha de envio" "$(envio 'es' 'sent' 'NULL' 'NULL' 'NULL')" RECHAZO
probar "uno fallido con cuando y por que" \
  "$(envio 'es' 'failed' 'NULL' 'NOW(3)' "'El buzon esta lleno.'")" OK
probar "uno fallido SIN motivo" "$(envio 'es' 'failed' 'NULL' 'NOW(3)' 'NULL')" RECHAZO
probar "uno fallido SIN fecha" "$(envio 'es' 'failed' 'NULL' 'NULL' "'Algo paso.'")" RECHAZO
probar "un estado inventado" "$(envio 'es' 'pensandolo' 'NULL' 'NULL' 'NULL')" RECHAZO

echo ""
echo "--- La caida de idioma queda anotada ---"
probar "un envio en el que se pidio pt-BR y salio en es" "$(envio 'pt-BR' 'sent' 'NOW(3)' 'NULL' 'NULL')" OK
valor "y se puede consultar cuantos hubo" \
  "SELECT COUNT(*) FROM email_log WHERE template_code='p49.aviso' AND locale_requested <> template_locale;" "1"

echo ""
echo "--- La plantilla no se borra si tiene envios: es evidencia ---"
probar "borrar la plantilla usada" \
  "DELETE FROM email_templates WHERE code='p49.aviso' AND locale='es' AND version='2.0';" RECHAZO

$CLIENTE $DB -e "DELETE FROM email_log WHERE template_code LIKE 'p49.%';
  DELETE FROM email_templates WHERE code LIKE 'p49.%';" 2>/dev/null

echo ""
echo "==================================================================================="
printf "  \033[32m%d correctas\033[0m, \033[31m%d fallidas\033[0m\n" $ok $fail
echo "==================================================================================="
[ $fail -eq 0 ]
