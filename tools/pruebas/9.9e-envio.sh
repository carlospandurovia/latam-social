#!/bin/bash
# Pruebas de restriccion de la iteracion 9.9e: el envio a la administracion.
#
#   uq_dsub_intento    un numero de intento por factura
#   ck_dsub_outcome    cinco finales, y solo esos
#   ck_dsub_contesto   si contesto, se guarda lo que dijo
#   ck_dsub_notas      solo lo que ENTRO puede traer observaciones
#   ck_invoice_external el estado de la factura habla el mismo vocabulario
#   tg_dsub_no_delete  un intento no se borra
#   tg_dsub_no_update  ni se corrige: se reintenta, y eso anade una fila
#
# La que importa es `ck_dsub_contesto`. Sin ella, un «aceptado» sin codigo
# entraria, y la pregunta «.donde esta el CDR de esta factura?» se quedaria sin
# respuesta justo cuando alguien la hace: en una fiscalizacion.
#
# Uso: bash tools/pruebas/9.9e-envio.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.9e - El envio a la administracion"
echo "==================================================================================="

USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"
FAC="(SELECT id FROM (SELECT id FROM invoices ORDER BY id LIMIT 1) i)"
H1="'$(printf 'c%.0s' {1..64})'"

valor "ningun envio de una pasada anterior" \
  "SELECT COUNT(*) FROM document_submissions;" "0"

# Hace falta un documento electronico al que apuntar: un intento sin documento
# no dice QUE se mando, y esa es la mitad de para lo que existe la tabla.
probar "se arma un comprobante sobre el que trabajar" \
  "INSERT INTO electronic_documents
     (uuid,invoice_id,kind,name,xml_content,sha256,size_bytes,generated_at,generated_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,'xml_signed','20603203896-01-F001-1.xml','<Invoice/>',$H1,10,NOW(3),$USR,NOW(3),NOW(3));" OK

EDOC="(SELECT id FROM (SELECT id FROM electronic_documents WHERE sha256=$H1) e)"

echo ""
echo "-- Lo que entra --"

probar "un envio aceptado, con lo que contesto" \
  "INSERT INTO document_submissions
     (uuid,invoice_id,electronic_document_id,attempt_number,outcome,response_code,
      response_message,notes_count,connection_snapshot,environment,sent_at,sent_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,$EDOC,1,'aceptado','0','La Factura F001-1 ha sido aceptada',0,
      'SUNAT produccion','production',NOW(3),$USR,NOW(3),NOW(3));" OK

porque "pero no dos con el mismo numero de intento" \
  "INSERT INTO document_submissions
     (uuid,invoice_id,electronic_document_id,attempt_number,outcome,response_code,
      environment,sent_at,sent_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,$EDOC,1,'rechazado','2335','production',NOW(3),$USR,NOW(3),NOW(3));" \
  "uq_dsub_intento"

probar "el segundo intento si, con su numero" \
  "INSERT INTO document_submissions
     (uuid,invoice_id,electronic_document_id,attempt_number,outcome,response_code,
      environment,sent_at,sent_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,$EDOC,2,'rechazado','2335','production',NOW(3),$USR,NOW(3),NOW(3));" OK

# Un error de red es el UNICO final que puede no traer codigo: no se llego a
# saber nada. Aceptado o rechazado SIN codigo serian una afirmacion sin prueba.
probar "un error de red sin codigo entra: no se llego a saber" \
  "INSERT INTO document_submissions
     (uuid,invoice_id,electronic_document_id,attempt_number,outcome,response_message,
      environment,sent_at,sent_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,$EDOC,3,'error_red','Se agoto la espera','production',NOW(3),$USR,NOW(3),NOW(3));" OK

porque "pero un aceptado SIN codigo, no" \
  "INSERT INTO document_submissions
     (uuid,invoice_id,electronic_document_id,attempt_number,outcome,
      environment,sent_at,sent_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,$EDOC,4,'aceptado','production',NOW(3),$USR,NOW(3),NOW(3));" \
  "ck_dsub_contesto|se guarda lo que dijo"

porque "ni un final que nadie sabe como arreglar" \
  "INSERT INTO document_submissions
     (uuid,invoice_id,electronic_document_id,attempt_number,outcome,response_code,
      environment,sent_at,sent_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,$EDOC,5,'raro','0','production',NOW(3),$USR,NOW(3),NOW(3));" \
  "ck_dsub_outcome|Resultado de envio"

porque "ni un intento numero cero" \
  "INSERT INTO document_submissions
     (uuid,invoice_id,electronic_document_id,attempt_number,outcome,response_code,
      environment,sent_at,sent_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,$EDOC,0,'aceptado','0','production',NOW(3),$USR,NOW(3),NOW(3));" \
  "ck_dsub_intento|primer intento es el numero uno"

# Observaciones sobre algo que NO entro seria decir que la administracion puso
# reparos a un documento que nunca acepto.
porque "ni observaciones sobre algo que no entro" \
  "INSERT INTO document_submissions
     (uuid,invoice_id,electronic_document_id,attempt_number,outcome,response_code,notes_count,
      environment,sent_at,sent_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,$EDOC,6,'rechazado','2335',2,'production',NOW(3),$USR,NOW(3),NOW(3));" \
  "ck_dsub_notas|entro puede traer observaciones"

echo ""
echo "-- El estado de la factura habla el mismo vocabulario --"

probar "la factura queda aceptada" \
  "UPDATE invoices SET external_status='aceptado' WHERE id=$FAC;" OK

porque "y no puede quedar en un estado que nadie sabe leer" \
  "UPDATE invoices SET external_status='enviadoquizas' WHERE id=$FAC;" \
  "ck_invoice_external|Estado ante la administracion"

echo ""
echo "-- Un intento es un hecho --"

porque "no se corrige" \
  "UPDATE document_submissions SET outcome='aceptado' WHERE attempt_number=2;" \
  "tg_dsub_no_update|se reintenta"

porque "y no se borra" \
  "DELETE FROM document_submissions WHERE attempt_number=2;" \
  "tg_dsub_no_delete|no se borra"

echo ""
echo "-- Esta suite tiene que quitar sus propias reglas para limpiar --"

probar "se quitan los disparadores" \
  "DROP TRIGGER IF EXISTS tg_dsub_no_delete; DROP TRIGGER IF EXISTS tg_edoc_no_delete;" OK
probar "se limpian los envios" \
  "DELETE FROM document_submissions;" OK
probar "y el comprobante de la suite" \
  "UPDATE invoices SET external_status=NULL WHERE id=$FAC;
   DELETE FROM electronic_documents WHERE sha256=$H1;" OK

resumen
