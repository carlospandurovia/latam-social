#!/bin/bash
# Pruebas de restriccion de la iteracion 9.17g: la cuenta de correo.
#
#   ck_mail_host       el servidor necesita una direccion
#   ck_mail_port       entre 1 y 65535
#   ck_mail_cifrado    tls, ssl o ninguno
#   ck_mail_remitente  con forma de correo
#   ck_mail_nombre     un nombre que se pueda leer
#   ck_mail_espera     entre 1 y 120 segundos
#   uq_mail_conexion   una conexion, una configuracion
#
# `ck_mail_espera` es la que no parece importante y lo es: un servidor que no
# contesta puede colgar una peticion web durante minutos, y quien esta esperando
# cree que el sistema murio.
#
# Uso: bash tools/pruebas/9.17g-correo.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.17g - La cuenta con la que sale el correo"
echo "==================================================================================="

$CLIENTE $DB -e "
DELETE FROM mail_settings WHERE host LIKE '%x917g%';
DELETE FROM integration_connections WHERE uuid LIKE 'c917%';
DELETE FROM integration_providers WHERE code='x917g_mail';
INSERT INTO integration_providers (code,name,purpose,created_at)
VALUES ('x917g_mail','Correo de prueba','email',NOW(3));" >/dev/null 2>&1

PROV=$($CLIENTE $DB -sN -e "SELECT id FROM integration_providers WHERE code='x917g_mail';" | tr -d '\r')

if [ -z "${PROV:-}" ]; then
  echo "  No se pudo crear el proveedor de la prueba."
  exit 1
fi

$CLIENTE $DB -e "
INSERT INTO integration_connections (uuid,integration_provider_id,name,base_url,environment,status,created_at)
VALUES ('c9170000-0000-4000-8000-000000000001',$PROV,'Correo x917g','smtp://x917g.test:587','production','draft',NOW(3));" >/dev/null 2>&1
CONN=$($CLIENTE $DB -sN -e "SELECT id FROM integration_connections WHERE uuid='c9170000-0000-4000-8000-000000000001';" | tr -d '\r')

valor "la conexion de la prueba existe" \
  "SELECT CASE WHEN COUNT(*) = 1 THEN 'si' ELSE 'no' END
     FROM integration_connections WHERE id=$CONN;" "si"

echo ""
echo "-- La forma de una cuenta --"

porque "un servidor en blanco" \
  "INSERT INTO mail_settings (integration_connection_id,host,port,encryption,from_address,from_name,created_at)
   VALUES ($CONN,'   ',587,'tls','hola@x917g.test','LATAM',NOW(3));" \
  "ck_mail_host|necesita una direccion"

porque "un puerto fuera de rango" \
  "INSERT INTO mail_settings (integration_connection_id,host,port,encryption,from_address,from_name,created_at)
   VALUES ($CONN,'smtp.x917g.test',70000,'tls','hola@x917g.test','LATAM',NOW(3));" \
  "ck_mail_port|Out of range|entre 1 y 65535"

porque "un cifrado inventado" \
  "INSERT INTO mail_settings (integration_connection_id,host,port,encryption,from_address,from_name,created_at)
   VALUES ($CONN,'smtp.x917g.test',587,'rot13','hola@x917g.test','LATAM',NOW(3));" \
  "ck_mail_cifrado|tls, ssl o ninguno"

porque "un remitente que no es un correo" \
  "INSERT INTO mail_settings (integration_connection_id,host,port,encryption,from_address,from_name,created_at)
   VALUES ($CONN,'smtp.x917g.test',587,'tls','hola-arroba-nada','LATAM',NOW(3));" \
  "ck_mail_remitente|forma de correo"

porque "un remitente sin nombre legible" \
  "INSERT INTO mail_settings (integration_connection_id,host,port,encryption,from_address,from_name,created_at)
   VALUES ($CONN,'smtp.x917g.test',587,'tls','hola@x917g.test','L',NOW(3));" \
  "ck_mail_nombre|nombre que se pueda leer"

porque "una espera de cinco minutos" \
  "INSERT INTO mail_settings (integration_connection_id,host,port,encryption,from_address,from_name,timeout_seconds,created_at)
   VALUES ($CONN,'smtp.x917g.test',587,'tls','hola@x917g.test','LATAM',300,NOW(3));" \
  "ck_mail_espera|entre 1 y 120"

# Sin cifrar SI se admite: un servidor de pruebas local no lo lleva, y se avisa
# en rojo en vez de impedirlo (`DEC-190`). Sin esta asercion, un CHECK que
# exigiera cifrado siempre tambien pasaria la del cifrado inventado.
probar "sin cifrar si se admite: se avisa, no se impide" \
  "INSERT INTO mail_settings (integration_connection_id,host,port,encryption,from_address,from_name,created_at)
   VALUES ($CONN,'x917g.local',1025,NULL,'hola@x917g.test','LATAM Social',NOW(3));" "OK"

echo ""
echo "-- Una conexion, una configuracion --"

porque "una segunda configuracion para la misma conexion" \
  "INSERT INTO mail_settings (integration_connection_id,host,port,encryption,from_address,from_name,created_at)
   VALUES ($CONN,'otro.x917g.test',587,'tls','hola@x917g.test','LATAM',NOW(3));" \
  "uq_mail_conexion|Duplicate"

resumen
