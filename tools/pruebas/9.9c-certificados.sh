#!/bin/bash
# Pruebas de restriccion de la iteracion 9.9c: los certificados de firma.
#
#   ck_cert_dates       no caduca antes de empezar
#   ck_cert_pem         un cifrado vacio no es un certificado
#   ck_cert_huella      la huella son 64 caracteres
#   ck_cert_ruc         dice de que contribuyente es
#   ck_cert_source      entra como PKCS#12 o como PEM
#   ck_cert_env         pruebas o produccion, y no se mezclan
#   ck_cert_revocado    revocar exige decir por que
#   ck_cert_reemplazado y reemplazar, cuando dejo de usarse
#   uq_cert_activo      UNO solo en uso por sociedad y entorno
#   uq_cert_huella      el mismo certificado no se sube dos veces al mismo sitio
#   tg_cert_no_delete   explica la firma de lo ya emitido
#   tg_cert_inmutable   y lo que dice el certificado no lo cambia nadie
#
# La que mas importa es `uq_cert_activo`. Con dos certificados en uso, la mitad
# de los comprobantes saldria firmado con uno y la mitad con otro, y nadie
# sabria cual hasta que la administracion tributaria rechazara.
#
# Uso: bash tools/pruebas/9.9c-certificados.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.9c - El certificado con el que firma cada sociedad"
echo "==================================================================================="

LE=$($CLIENTE $DB -sN -e "SELECT id FROM legal_entities WHERE code='CTS-PE';" 2>/dev/null | tr -d '\r')
LE2=$($CLIENTE $DB -sN -e "SELECT id FROM legal_entities WHERE code='CTS-CO';" 2>/dev/null | tr -d '\r')
U1=$($CLIENTE $DB -sN -e "SELECT id FROM users ORDER BY id LIMIT 1;" 2>/dev/null | tr -d '\r')

if [ -z "${LE:-}" ] || [ -z "${LE2:-}" ] || [ -z "${U1:-}" ]; then
  echo "  La premisa no se cumple: faltan las dos sociedades o el usuario de la semilla."
  exit 1
fi

# Huellas de 64 caracteres, que es lo que exige `ck_cert_huella`.
H1=$(printf 'a%.0s' {1..64})
H2=$(printf 'b%.0s' {1..64})
H3=$(printf 'c%.0s' {1..64})

valor "no quedan certificados de una corrida anterior" \
  "SELECT CASE WHEN COUNT(*) = 0 THEN 'limpio' ELSE 'rehaga la base' END
     FROM signing_certificates;" "limpio"

# El de verdad, con el que trabajan casi todas las aserciones.
$CLIENTE $DB -e "
INSERT INTO signing_certificates (uuid,legal_entity_id,environment,subject_name,issuer_name,
  serial_number,tax_id_number,valid_from,valid_to,fingerprint_sha256,pem_cipher,source,status,
  uploaded_by_user_id,uploaded_at,created_at)
VALUES ('c99c0000-0000-4000-8000-000000000001',$LE,'production','CN=20603203896','CN=SUNAT',
  'AABB01','20603203896','2026-01-01 00:00:00.000','2027-01-01 00:00:00.000','$H1',
  'cifrado','pkcs12','active',$U1,NOW(3),NOW(3));" 2>/dev/null

CERT=$($CLIENTE $DB -sN -e "SELECT id FROM signing_certificates WHERE fingerprint_sha256='$H1';" | tr -d '\r')

echo ""
echo "-- La forma de un certificado --"

porque "uno que caduca antes de empezar" \
  "INSERT INTO signing_certificates (uuid,legal_entity_id,environment,subject_name,issuer_name,
     serial_number,tax_id_number,valid_from,valid_to,fingerprint_sha256,pem_cipher,source,status,
     uploaded_by_user_id,uploaded_at,created_at)
   VALUES (UUID(),$LE,'sandbox','CN=x','CN=y','01','20603203896',
     '2027-01-01 00:00:00.000','2026-01-01 00:00:00.000','$H2','cifrado','pkcs12','active',
     $U1,NOW(3),NOW(3));" \
  "ck_cert_dates|antes de empezar"

porque "uno sin material" \
  "INSERT INTO signing_certificates (uuid,legal_entity_id,environment,subject_name,issuer_name,
     serial_number,tax_id_number,valid_from,valid_to,fingerprint_sha256,pem_cipher,source,status,
     uploaded_by_user_id,uploaded_at,created_at)
   VALUES (UUID(),$LE,'sandbox','CN=x','CN=y','01','20603203896',
     '2026-01-01 00:00:00.000','2027-01-01 00:00:00.000','$H2','   ','pkcs12','active',
     $U1,NOW(3),NOW(3));" \
  "ck_cert_pem|sin material"

porque "una huella que no son 64 caracteres" \
  "INSERT INTO signing_certificates (uuid,legal_entity_id,environment,subject_name,issuer_name,
     serial_number,tax_id_number,valid_from,valid_to,fingerprint_sha256,pem_cipher,source,status,
     uploaded_by_user_id,uploaded_at,created_at)
   VALUES (UUID(),$LE,'sandbox','CN=x','CN=y','01','20603203896',
     '2026-01-01 00:00:00.000','2027-01-01 00:00:00.000','abc','cifrado','pkcs12','active',
     $U1,NOW(3),NOW(3));" \
  "ck_cert_huella|64 caracteres"

porque "uno que no dice de que contribuyente es" \
  "INSERT INTO signing_certificates (uuid,legal_entity_id,environment,subject_name,issuer_name,
     serial_number,tax_id_number,valid_from,valid_to,fingerprint_sha256,pem_cipher,source,status,
     uploaded_by_user_id,uploaded_at,created_at)
   VALUES (UUID(),$LE,'sandbox','CN=x','CN=y','01','  ',
     '2026-01-01 00:00:00.000','2027-01-01 00:00:00.000','$H2','cifrado','pkcs12','active',
     $U1,NOW(3),NOW(3));" \
  "ck_cert_ruc|contribuyente"

porque "un origen inventado" \
  "INSERT INTO signing_certificates (uuid,legal_entity_id,environment,subject_name,issuer_name,
     serial_number,tax_id_number,valid_from,valid_to,fingerprint_sha256,pem_cipher,source,status,
     uploaded_by_user_id,uploaded_at,created_at)
   VALUES (UUID(),$LE,'sandbox','CN=x','CN=y','01','20603203896',
     '2026-01-01 00:00:00.000','2027-01-01 00:00:00.000','$H2','cifrado','fotocopia','active',
     $U1,NOW(3),NOW(3));" \
  "ck_cert_source|PKCS#12 o como PEM"

porque "un estado inventado" \
  "INSERT INTO signing_certificates (uuid,legal_entity_id,environment,subject_name,issuer_name,
     serial_number,tax_id_number,valid_from,valid_to,fingerprint_sha256,pem_cipher,source,status,
     uploaded_by_user_id,uploaded_at,created_at)
   VALUES (UUID(),$LE,'sandbox','CN=x','CN=y','01','20603203896',
     '2026-01-01 00:00:00.000','2027-01-01 00:00:00.000','$H2','cifrado','pkcs12','caducado',
     $U1,NOW(3),NOW(3));" \
  "ck_cert_status|Estado de certificado"

porque "un entorno inventado" \
  "INSERT INTO signing_certificates (uuid,legal_entity_id,environment,subject_name,issuer_name,
     serial_number,tax_id_number,valid_from,valid_to,fingerprint_sha256,pem_cipher,source,status,
     uploaded_by_user_id,uploaded_at,created_at)
   VALUES (UUID(),$LE,'casi','CN=x','CN=y','01','20603203896',
     '2026-01-01 00:00:00.000','2027-01-01 00:00:00.000','$H2','cifrado','pkcs12','active',
     $U1,NOW(3),NOW(3));" \
  "ck_cert_env|pruebas o produccion"

echo ""
echo "-- Uno solo en uso, por sociedad y entorno --"

porque "un segundo certificado en uso para la misma sociedad y entorno" \
  "INSERT INTO signing_certificates (uuid,legal_entity_id,environment,subject_name,issuer_name,
     serial_number,tax_id_number,valid_from,valid_to,fingerprint_sha256,pem_cipher,source,status,
     uploaded_by_user_id,uploaded_at,created_at)
   VALUES (UUID(),$LE,'production','CN=otro','CN=SUNAT','02','20603203896',
     '2026-01-01 00:00:00.000','2027-01-01 00:00:00.000','$H2','cifrado','pkcs12','active',
     $U1,NOW(3),NOW(3));" \
  "uq_cert_activo|Duplicate"

# La otra mitad: el MISMO certificado en el entorno de pruebas tiene que dejar
# pasar. Sin esta asercion, una unica sobre la huella a secas tambien pasaria la
# de arriba --y prohibiria usar el mismo certificado en beta, que es lo normal--.
probar "el mismo certificado en el entorno de pruebas si se puede" \
  "INSERT INTO signing_certificates (uuid,legal_entity_id,environment,subject_name,issuer_name,
     serial_number,tax_id_number,valid_from,valid_to,fingerprint_sha256,pem_cipher,source,status,
     uploaded_by_user_id,uploaded_at,created_at)
   VALUES ('c99c0000-0000-4000-8000-000000000002',$LE,'sandbox','CN=20603203896','CN=SUNAT',
     'AABB01','20603203896','2026-01-01 00:00:00.000','2027-01-01 00:00:00.000','$H1',
     'cifrado','pkcs12','active',$U1,NOW(3),NOW(3));" "OK"

porque "el mismo certificado dos veces en el mismo entorno" \
  "INSERT INTO signing_certificates (uuid,legal_entity_id,environment,subject_name,issuer_name,
     serial_number,tax_id_number,valid_from,valid_to,fingerprint_sha256,pem_cipher,source,status,
     uploaded_by_user_id,uploaded_at,created_at)
   VALUES (UUID(),$LE2,'sandbox','CN=x','CN=SUNAT','03','9001234567',
     '2026-01-01 00:00:00.000','2027-01-01 00:00:00.000','$H1','cifrado','pkcs12','active',
     $U1,NOW(3),NOW(3));" \
  "uq_cert_huella|Duplicate"

# Y otra sociedad SI puede tener el suyo en produccion a la vez.
probar "otra sociedad con su propio certificado en produccion" \
  "INSERT INTO signing_certificates (uuid,legal_entity_id,environment,subject_name,issuer_name,
     serial_number,tax_id_number,valid_from,valid_to,fingerprint_sha256,pem_cipher,source,status,
     uploaded_by_user_id,uploaded_at,created_at)
   VALUES ('c99c0000-0000-4000-8000-000000000003',$LE2,'production','CN=9001234567','CN=DIAN',
     '04','9001234567','2026-01-01 00:00:00.000','2027-01-01 00:00:00.000','$H3',
     'cifrado','pkcs12','active',$U1,NOW(3),NOW(3));" "OK"

echo ""
echo "-- Lo que dice el certificado no lo cambia nadie --"

porque "cambiarle el material" \
  "UPDATE signing_certificates SET pem_cipher='otro' WHERE id=$CERT;" \
  "no se reescribe"

porque "estirarle la fecha de caducidad" \
  "UPDATE signing_certificates SET valid_to='2030-01-01 00:00:00.000' WHERE id=$CERT;" \
  "no se reescribe"

porque "cambiarle la sociedad" \
  "UPDATE signing_certificates SET legal_entity_id=$LE2 WHERE id=$CERT;" \
  "no se reescribe"

# Lo que SI puede moverse: el estado. Sin esta asercion, un disparador que
# congelara la fila entera tambien pasaria las tres de arriba --y dejaria el
# sistema sin poder reemplazar ni revocar un certificado--.
probar "marcarlo como reemplazado si se puede" \
  "UPDATE signing_certificates SET status='replaced', replaced_at=NOW(3) WHERE id=$CERT;" "OK"

porque "reemplazarlo sin decir cuando" \
  "UPDATE signing_certificates SET status='replaced', replaced_at=NULL
     WHERE uuid='c99c0000-0000-4000-8000-000000000003';" \
  "ck_cert_reemplazado|cuando dejo de usarse"

echo ""
echo "-- Revocar exige decir por que --"

porque "revocar sin motivo" \
  "UPDATE signing_certificates SET status='revoked', revoked_at=NOW(3)
     WHERE uuid='c99c0000-0000-4000-8000-000000000003';" \
  "ck_cert_revocado|decir por que"

porque "revocar con un motivo de tres letras" \
  "UPDATE signing_certificates SET status='revoked', revoked_at=NOW(3), revoked_reason='ups'
     WHERE uuid='c99c0000-0000-4000-8000-000000000003';" \
  "ck_cert_revocado|decir por que"

probar "revocar con el motivo escrito" \
  "UPDATE signing_certificates SET status='revoked', revoked_at=NOW(3),
     revoked_reason='La clave privada quedo expuesta en un correo.'
     WHERE uuid='c99c0000-0000-4000-8000-000000000003';" "OK"

echo ""
echo "-- Y no se borra --"

porque "borrar un certificado" \
  "DELETE FROM signing_certificates WHERE id=$CERT;" \
  "no se borra"

resumen
