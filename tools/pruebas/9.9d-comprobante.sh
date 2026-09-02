#!/bin/bash
# Pruebas de restriccion de la iteracion 9.9d: el comprobante electronico.
#
#   uq_edoc_vigente    UNO vigente por factura y clase (columna puerta 35)
#   ck_edoc_huella     64 caracteres hexadecimales, no «una cadena cualquiera»
#   ck_edoc_kind       xml_signed o cdr, y nada mas
#   ck_edoc_vacio      un documento de cero bytes no es un documento
#   tg_edoc_no_delete  lo firmado no se borra
#   tg_edoc_inmutable  ni se cambia; solo se marca como reemplazado
#
# La que importa es `uq_edoc_vigente`. Sin ella, regenerar dejaria DOS vigentes
# y nadie sabria cual se mando --que es exactamente el problema que 9.17f
# encontro con las conexiones activas--.
#
# Uso: bash tools/pruebas/9.9d-comprobante.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.9d - El comprobante electronico"
echo "==================================================================================="

USR="(SELECT id FROM (SELECT id FROM users ORDER BY id LIMIT 1) u)"
FAC="(SELECT id FROM (SELECT id FROM invoices ORDER BY id LIMIT 1) i)"
H1="'$(printf 'a%.0s' {1..64})'"
H2="'$(printf 'b%.0s' {1..64})'"

valor "ningun documento de una pasada anterior" \
  "SELECT COUNT(*) FROM electronic_documents;" "0"
# «Al menos una», no «exactamente una»: 9.9b deja las suyas y esta suite corre
# despues. Afirmar el numero exacto seria afirmar el orden de las suites, que es
# una dependencia que nadie escribio y que se rompe sola.
valor "hay al menos una factura sobre la que trabajar" \
  "SELECT LEAST(COUNT(*), 1) FROM invoices;" "1"

echo ""
echo "-- Lo que entra --"

probar "un xml firmado entra" \
  "INSERT INTO electronic_documents
     (uuid,invoice_id,kind,name,xml_content,sha256,size_bytes,generated_at,generated_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,'xml_signed','20603203896-01-F001-1.xml','<Invoice/>',$H1,10,NOW(3),$USR,NOW(3),NOW(3));" OK

porque "pero NO un segundo vigente de la misma clase" \
  "INSERT INTO electronic_documents
     (uuid,invoice_id,kind,name,xml_content,sha256,size_bytes,generated_at,generated_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,'xml_signed','20603203896-01-F001-1.xml','<Invoice/>',$H2,10,NOW(3),$USR,NOW(3),NOW(3));" \
  "uq_edoc_vigente"

# El CDR de 9.9e es otra clase, asi que convive con el XML sin pelearse por la
# puerta: la garantia es «uno de CADA clase», no «uno en total».
probar "el CDR de la misma factura si convive con el XML" \
  "INSERT INTO electronic_documents
     (uuid,invoice_id,kind,name,xml_content,sha256,size_bytes,generated_at,generated_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,'cdr','R-20603203896-01-F001-1.xml','<ApplicationResponse/>',$H2,20,NOW(3),$USR,NOW(3),NOW(3));" OK

porque "una clase que no existe, no" \
  "INSERT INTO electronic_documents
     (uuid,invoice_id,kind,name,xml_content,sha256,size_bytes,generated_at,generated_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,'pdf','x-01-F001-1.xml','<x/>',$H2,10,NOW(3),$USR,NOW(3),NOW(3));" \
  "ck_edoc_kind|Clase de documento"

porque "ni una huella que no es una huella" \
  "INSERT INTO electronic_documents
     (uuid,invoice_id,kind,name,xml_content,sha256,size_bytes,generated_at,generated_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,'xml_signed','y-01-F001-1.xml','<x/>','no-es-una-huella',10,NOW(3),$USR,NOW(3),NOW(3));" \
  "ck_edoc_huella|64 caracteres"

# El nombre es la IDENTIDAD del documento ante la administracion, no una
# etiqueta: SUNAT lo usa para reconocerlo dentro del ZIP.
porque "ni uno sin nombre con el que reconocerlo" \
  "INSERT INTO electronic_documents
     (uuid,invoice_id,kind,name,xml_content,sha256,size_bytes,generated_at,generated_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,'xml_signed','  ','<x/>',$H2,10,NOW(3),$USR,NOW(3),NOW(3));" \
  "ck_edoc_nombre|nombre que exige"

porque "ni un documento de cero bytes" \
  "INSERT INTO electronic_documents
     (uuid,invoice_id,kind,name,xml_content,sha256,size_bytes,generated_at,generated_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,'xml_signed','z-01-F001-1.xml','<x/>',$H2,0,NOW(3),$USR,NOW(3),NOW(3));" \
  "ck_edoc_vacio|vacio no es un documento"

echo ""
echo "-- Lo firmado no se toca --"

porque "el xml guardado no se cambia" \
  "UPDATE electronic_documents SET xml_content='<Invoice>otro</Invoice>' WHERE kind='xml_signed';" \
  "tg_edoc_inmutable|no se cambia"

porque "ni su huella" \
  "UPDATE electronic_documents SET sha256=$H2 WHERE kind='xml_signed';" \
  "tg_edoc_inmutable|no se cambia"

porque "ni el nombre con el que lo reconoce la administracion" \
  "UPDATE electronic_documents SET name='otro-nombre.xml' WHERE kind='xml_signed';" \
  "tg_edoc_inmutable|no se cambia"

porque "y no se borra" \
  "DELETE FROM electronic_documents WHERE kind='xml_signed';" \
  "tg_edoc_no_delete|no se borra"

echo ""
echo "-- Reemplazar si se puede, y no tiene vuelta --"

probar "marcarlo como reemplazado" \
  "UPDATE electronic_documents SET superseded_at=NOW(3) WHERE kind='xml_signed';" OK

probar "y entonces si entra el nuevo vigente" \
  "INSERT INTO electronic_documents
     (uuid,invoice_id,kind,name,xml_content,sha256,size_bytes,generated_at,generated_by_user_id,created_at,updated_at)
   VALUES (UUID(),$FAC,'xml_signed','20603203896-01-F001-1.xml','<Invoice>v2</Invoice>',$H2,14,NOW(3),$USR,NOW(3),NOW(3));" OK

# Volver atras convertiria en vigente algo que ya se declaro sustituido, y
# entonces habria dos: la puerta lo pararia, pero el mensaje no diria por que.
porque "un reemplazado no vuelve a ser el vigente" \
  "UPDATE electronic_documents SET superseded_at=NULL WHERE sha256=$H1;" \
  "tg_edoc_inmutable|no vuelve a ser"

echo ""
echo "-- Esta suite limpia lo suyo, y no puede: es el punto --"

# `tg_edoc_no_delete` impide borrarlos, asi que la suite NO deja la base como la
# encontro y no puede hacerlo sin quitar la regla que acaba de afirmar. Se
# quita el disparador para limpiar, que es lo mismo que hara quien restaure.
probar "se quita el disparador para poder limpiar" \
  "DROP TRIGGER IF EXISTS tg_edoc_no_delete;" OK
probar "se limpian los documentos de la suite" \
  "DELETE FROM electronic_documents;" OK

resumen
