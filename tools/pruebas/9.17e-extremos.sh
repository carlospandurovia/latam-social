#!/bin/bash
# Pruebas de restriccion de la iteracion 9.17e: la URL no se teclea.
#
#   ck_ipend_env         pruebas o produccion
#   ck_ipend_url         la direccion de un proveedor va por https
#   uq_ipend_entorno     una direccion por proveedor y entorno
#   tg_iconn_activa_*    activar exige saber a donde llamar --propia o heredada--
#                        y, si es un emisor electronico, una sociedad
#
# La que mas importa es la ultima pareja. Hasta 9.17e la regla era `ck_iconn_url`
# y solo miraba la propia fila, asi que obligaba a teclear a mano una direccion
# que es PUBLICA y FIJA --y un caracter de mas produce comprobantes que no
# llegan--. Ahora la pregunta es cruzada y por eso vive en un disparador.
#
# Uso: bash tools/pruebas/9.17e-extremos.sh <base>
set -u
DB=${1:-latam_social}
CLIENTE=${MYSQL_CMD:-mariadb}

source "$(dirname "$0")/comun.sh"
echo ""
echo "==================================================================================="
echo "  9.17e - La direccion de un proveedor no se teclea"
echo "==================================================================================="

LE=$($CLIENTE $DB -sN -e "SELECT id FROM legal_entities WHERE code='CTS-PE';" 2>/dev/null | tr -d '\r')

if [ -z "${LE:-}" ]; then
  echo "  La premisa no se cumple: falta la sociedad de la semilla."
  exit 1
fi

# Los proveedores los pone la suite, como hace `9.17d`: `semilla.sql` no los
# trae, y depender de `CimientosSeeder` --que es de Laravel-- ataria esta
# bateria de SQL puro a que alguien haya corrido el sembrador antes.
$CLIENTE $DB -e "
DELETE FROM integration_provider_endpoints WHERE base_url LIKE '%x917e%'
   OR integration_provider_id IN (SELECT id FROM integration_providers WHERE code LIKE 'x917e%');
DELETE FROM integration_connections WHERE uuid LIKE 'e17e%';
DELETE FROM integration_providers WHERE code LIKE 'x917e%';
INSERT INTO integration_providers (code,name,purpose,created_at) VALUES
 ('x917e_fe','Emisor de prueba','invoicing',NOW(3)),
 ('x917e_mail','Correo de prueba','email',NOW(3));" >/dev/null 2>&1

SUNAT=$($CLIENTE $DB -sN -e "SELECT id FROM integration_providers WHERE code='x917e_fe';" | tr -d '\r')
SMTP=$($CLIENTE $DB -sN -e "SELECT id FROM integration_providers WHERE code='x917e_mail';" | tr -d '\r')

if [ -z "${SUNAT:-}" ] || [ -z "${SMTP:-}" ]; then
  echo "  No se pudieron crear los proveedores de la prueba."
  exit 1
fi

# El emisor SI declara sus dos extremos --como los trae sembrados SUNAT--; el de
# correo NO declara ninguno, que es el otro caso que hay que poder afirmar.
$CLIENTE $DB -e "
INSERT INTO integration_provider_endpoints (integration_provider_id,environment,base_url,label,created_at)
VALUES ($SUNAT,'sandbox','https://beta.x917e.test/billService','Beta',NOW(3)),
       ($SUNAT,'production','https://prod.x917e.test/billService','Produccion',NOW(3));" >/dev/null 2>&1

valor "no quedan conexiones de una corrida anterior" \
  "SELECT CASE WHEN COUNT(*) = 0 THEN 'limpio' ELSE 'rehaga la base' END
     FROM integration_connections WHERE uuid LIKE 'e17e%';" "limpio"

echo ""
echo "-- La forma de un extremo --"

porque "una direccion por http" \
  "INSERT INTO integration_provider_endpoints (integration_provider_id,environment,base_url,created_at)
   VALUES ($SMTP,'sandbox','http://correo.example.test',NOW(3));" \
  "ck_ipend_url|por https"

porque "un entorno inventado" \
  "INSERT INTO integration_provider_endpoints (integration_provider_id,environment,base_url,created_at)
   VALUES ($SMTP,'casi','https://correo.example.test',NOW(3));" \
  "ck_ipend_env|pruebas o produccion"

porque "dos direcciones del mismo proveedor y entorno" \
  "INSERT INTO integration_provider_endpoints (integration_provider_id,environment,base_url,created_at)
   VALUES ($SUNAT,'sandbox','https://otra.x917e.test/billService',NOW(3));" \
  "uq_ipend_entorno|Duplicate"

echo ""
echo "-- Activar: saber a donde llamar --"

# SUNAT SI declara direccion para los dos entornos, asi que una conexion suya
# se puede activar SIN teclear ninguna URL. Es la asercion que da nombre a la
# iteracion: antes de 9.17e esto era imposible.
probar "activar una conexion de SUNAT sin teclear la URL" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,legal_entity_id,name,
     environment,status,created_at)
   VALUES ('e17e0000-0000-4000-8000-000000000001',$SUNAT,$LE,'SUNAT beta','sandbox','active',NOW(3));" "OK"

# El SMTP no declara ninguna, asi que la suya hay que escribirla.
porque "activar una conexion de un proveedor que no declara direccion" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,name,environment,status,created_at)
   VALUES (UUID(),$SMTP,'Correo','production','active',NOW(3));" \
  "tg_iconn_activa_ins|no sabe a donde llamar"

probar "la misma, escribiendo su propia direccion" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,name,base_url,
     environment,status,created_at)
   VALUES ('e17e0000-0000-4000-8000-000000000002',$SMTP,'Correo','https://correo.example.test',
     'production','active',NOW(3));" "OK"

# Y un borrador NO exige nada: es justamente donde faltan cosas (DEC-190).
probar "un borrador sin direccion se guarda igual" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,name,environment,status,created_at)
   VALUES ('e17e0000-0000-4000-8000-000000000003',$SMTP,'Correo a medias','sandbox','draft',NOW(3));" "OK"

porque "activar ese borrador sin darle direccion" \
  "UPDATE integration_connections SET status='active'
     WHERE uuid='e17e0000-0000-4000-8000-000000000003';" \
  "tg_iconn_activa_upd|no sabe a donde llamar"

echo ""
echo "-- Activar: un emisor electronico va con una sociedad --"

porque "activar un emisor electronico de toda la plataforma" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,name,environment,status,created_at)
   VALUES (UUID(),$SUNAT,'SUNAT sin sociedad','production','active',NOW(3));" \
  "tg_iconn_activa_ins|va con una sociedad"

# Y el correo SI puede ser de toda la plataforma: la regla es del proposito, no
# de todas las conexiones. Sin esta asercion, un disparador que exigiera
# sociedad siempre tambien pasaria la de arriba.
probar "el correo si puede ser de toda la plataforma" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,name,base_url,
     environment,status,created_at)
   VALUES ('e17e0000-0000-4000-8000-000000000004',$SMTP,'Correo plataforma',
     'https://correo.example.test','sandbox','active',NOW(3));" "OK"

echo ""
echo "-- Una activa por PROPOSITO, no por proveedor (9.17f) --"

# Un SEGUNDO proveedor del mismo proposito. El caso que trajo el negocio: «dos
# proveedores de FEL, se configuran pero se activa solo 1».
$CLIENTE $DB -e "
INSERT INTO integration_providers (code,name,purpose,created_at)
VALUES ('x917e_fe2','Otro emisor de prueba','invoicing',NOW(3));
INSERT INTO integration_provider_endpoints (integration_provider_id,environment,base_url,created_at)
SELECT id,'sandbox','https://beta2.x917e.test/billService',NOW(3)
  FROM integration_providers WHERE code='x917e_fe2';" 2>/dev/null
SUNAT2=$($CLIENTE $DB -sN -e "SELECT id FROM integration_providers WHERE code='x917e_fe2';" | tr -d '\r')

porque "un segundo emisor activo, de OTRO proveedor, misma sociedad y entorno" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,legal_entity_id,name,
     environment,status,created_at)
   VALUES (UUID(),$SUNAT2,$LE,'Otro emisor','sandbox','active',NOW(3));" \
  "uq_iconn_activa|Duplicate"

# Y dejarlo CONFIGURADO pero apagado si se puede: es justo lo que se pidio.
probar "pero dejarlo configurado y apagado si" \
  "INSERT INTO integration_connections (uuid,integration_provider_id,legal_entity_id,name,
     environment,status,created_at)
   VALUES ('e17e0000-0000-4000-8000-000000000005',$SUNAT2,$LE,'Otro emisor','sandbox','disabled',NOW(3));" "OK"

# El proposito se COPIA del proveedor: no se admite el que venga en la sentencia,
# porque seria un sitio donde alguien podria partir la puerta en dos.
valor "el proposito se copia del proveedor, no se teclea" \
  "SELECT purpose_snapshot FROM integration_connections
    WHERE uuid='e17e0000-0000-4000-8000-000000000005';" "invoicing"

resumen
